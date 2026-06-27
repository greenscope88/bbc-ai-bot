<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationOwner.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationStatus.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationState.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationStateRepositoryInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'InMemoryConversationStateRepository.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'JsonFileConversationStateRepository.php';

/**
 * Phase 2-B Step 2-A — Conversation State Runtime.
 *
 * SSOT:
 *   - docs/BATS_AI_CONVERSATION_ARCHITECTURE.md §5～§11
 *     (CA-002 Owner+Status / CA-003 Ownership / CA-004 Sliding Timeout /
 *      CA-005 Human Takeover / CA-006 AI Resume / CA-010 唯一 State 管理者)
 *
 * 唯一職責：管理 Conversation Owner / Conversation Status / Human Takeover /
 * Sliding Timeout / AI Resume 判定。為 Pipeline 第四層的唯一 State 管理者。
 *
 * 明確不負責 / 本回合明確不做（Additive Implementation）：
 *   - 不接入 saas_router / webhook / FinalReplyGate（Production Working Path）。
 *   - 不修改、不映射既有 ConversationStatusResolver（無 Compat / Alias / Bridge）。
 *   - 不組句、不執行業務、不管理 Conversation Memory。
 *   - 不發送 Gentle Reminder（Maintenance Message 留待後續 Step）。
 *
 * 本元件為純邏輯 + 持久化；所有時間決策皆可注入 $now 以利測試。
 */
final class ConversationStateRuntime
{
    /** CA-004：Sliding Timeout 固定 3 分鐘。 */
    public const DEFAULT_TIMEOUT_MINUTES = 3;

    private ConversationStateRepositoryInterface $repository;

    private int $timeoutMinutes;

    private \DateTimeZone $timezone;

    public function __construct(
        ?ConversationStateRepositoryInterface $repository = null,
        int $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES,
        ?\DateTimeZone $timezone = null
    ) {
        $this->repository = $repository ?? new JsonFileConversationStateRepository();
        $this->timeoutMinutes = $timeoutMinutes > 0 ? $timeoutMinutes : self::DEFAULT_TIMEOUT_MINUTES;
        $this->timezone = $timezone ?? new \DateTimeZone('Asia/Taipei');
    }

    public static function createForTesting(
        ?ConversationStateRepositoryInterface $repository = null,
        int $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES
    ): self {
        return new self(
            $repository ?? new InMemoryConversationStateRepository(),
            $timeoutMinutes
        );
    }

    public function getTimeoutMinutes(): int
    {
        return $this->timeoutMinutes;
    }

    /**
     * 取出既有狀態；不存在時回傳 null（純讀取，不建立）。
     */
    public function get(string $conversationId): ?ConversationState
    {
        return $this->repository->find($conversationId);
    }

    /**
     * 取出既有狀態；不存在時建立全新狀態（Owner = AI、Status = ACTIVE），
     * 但尚未持久化。
     */
    public function loadOrCreate(string $conversationId): ConversationState
    {
        $state = $this->repository->find($conversationId);
        if ($state === null) {
            $state = ConversationState::create($conversationId);
        }

        return $state;
    }

    public function forget(string $conversationId): void
    {
        $this->repository->delete($conversationId);
    }

    /**
     * Human Takeover（CA-005）：真人客服送出訊息。
     * → Owner = HUMAN；更新 last_human_message_at（Sliding Window, CA-004）。
     * → Status = WAITING_CUSTOMER（真人已回覆，等待客戶）。
     *
     * 多次呼叫即為滑動視窗延長：每次都重置 last_human_message_at。
     */
    public function recordHumanAgentMessage(
        string $conversationId,
        ?\DateTimeImmutable $now = null
    ): ConversationState {
        $now = $this->resolveNow($now);
        $state = $this->loadOrCreate($conversationId);

        $state->setOwner(ConversationOwner::HUMAN);
        $state->setLastHumanMessageAt($now->format(\DateTimeInterface::ATOM));
        if (!ConversationStatus::isTerminal($state->getStatus())) {
            $state->setStatus(ConversationStatus::WAITING_CUSTOMER);
        }

        return $this->persist($state, $now);
    }

    /**
     * 記錄客戶訊息時間（AI Resume「Customer New Message」條件用）。
     * 本方法不改變 Owner —— Owner 轉移由 evaluateAiResume + resumeToAi 決定。
     */
    public function recordCustomerMessage(
        string $conversationId,
        ?\DateTimeImmutable $now = null
    ): ConversationState {
        $now = $this->resolveNow($now);
        $state = $this->loadOrCreate($conversationId);

        $state->setLastCustomerMessageAt($now->format(\DateTimeInterface::ATOM));

        return $this->persist($state, $now);
    }

    /**
     * Human Hold（CA-004）：目前是否處於「真人接手且尚在 3 分鐘暫停窗內」。
     * Hold 為 true 時，AI 不得正式回覆。
     */
    public function isHumanHoldActive(
        string $conversationId,
        ?\DateTimeImmutable $now = null
    ): bool {
        $state = $this->repository->find($conversationId);
        if ($state === null) {
            return false;
        }

        return $this->isHumanHoldActiveForState($state, $this->resolveNow($now));
    }

    /**
     * 解析「目前由誰擁有對話」（CA-003）。
     *
     * - 若 Human Hold 仍有效 → Owner = HUMAN。
     * - 若 Owner = HUMAN 但已超過 3 分鐘暫停窗 → 視為 AI 可接手（回 AI）。
     *   （實際 Owner 轉移與狀態落地由 resumeToAi 執行；本方法只回報「有效 Owner」。）
     * - 其餘情況回傳目前儲存的 Owner。
     */
    public function resolveEffectiveOwner(
        string $conversationId,
        ?\DateTimeImmutable $now = null
    ): string {
        $state = $this->repository->find($conversationId);
        if ($state === null) {
            return ConversationOwner::AI;
        }

        $now = $this->resolveNow($now);
        if ($state->getOwner() === ConversationOwner::HUMAN) {
            return $this->isHumanHoldActiveForState($state, $now)
                ? ConversationOwner::HUMAN
                : ConversationOwner::AI;
        }

        return $state->getOwner();
    }

    /**
     * AI Resume 三條件評估（CA-006）：
     *   1) Conversation Owner = HUMAN
     *   2) Customer New Message（last_customer_message_at 晚於 last_human_message_at）
     *   3) now − last_human_message_at > 3 minutes
     *
     * 全部成立才回傳 true。本方法為純判定，不改變狀態。
     */
    public function evaluateAiResume(
        string $conversationId,
        ?\DateTimeImmutable $now = null
    ): bool {
        $state = $this->repository->find($conversationId);
        if ($state === null) {
            return false;
        }

        $now = $this->resolveNow($now);

        // 條件 1：Owner = HUMAN
        if ($state->getOwner() !== ConversationOwner::HUMAN) {
            return false;
        }

        $lastHuman = $state->getLastHumanMessageAtDate();
        if ($lastHuman === null) {
            return false;
        }

        // 條件 2：Customer New Message（客戶於真人最後訊息之後又發話）
        $lastCustomer = $state->getLastCustomerMessageAtDate();
        if ($lastCustomer === null || $lastCustomer <= $lastHuman) {
            return false;
        }

        // 條件 3：now − last_human_message_at > 3 minutes
        $resumeThreshold = $lastHuman->modify('+' . $this->timeoutMinutes . ' minutes');

        return $now > $resumeThreshold;
    }

    /**
     * 套用 AI Resume：Owner 由 HUMAN 轉回 AI、Status = ACTIVE，並持久化。
     * 僅在 evaluateAiResume() 為 true 時呼叫；否則回傳 false 不變更。
     */
    public function resumeToAi(
        string $conversationId,
        ?\DateTimeImmutable $now = null
    ): bool {
        $now = $this->resolveNow($now);
        if (!$this->evaluateAiResume($conversationId, $now)) {
            return false;
        }

        $state = $this->loadOrCreate($conversationId);
        $state->setOwner(ConversationOwner::AI);
        if (!ConversationStatus::isTerminal($state->getStatus())) {
            $state->setStatus(ConversationStatus::ACTIVE);
        }
        $this->persist($state, $now);

        return true;
    }

    /**
     * 顯式設定 Conversation Status（受合法值約束）。
     */
    public function setStatus(
        string $conversationId,
        string $status,
        ?\DateTimeImmutable $now = null
    ): ConversationState {
        $now = $this->resolveNow($now);
        $state = $this->loadOrCreate($conversationId);
        $state->setStatus($status);

        return $this->persist($state, $now);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isHumanHoldActiveForState(ConversationState $state, \DateTimeImmutable $now): bool
    {
        if ($state->getOwner() !== ConversationOwner::HUMAN) {
            return false;
        }

        $lastHuman = $state->getLastHumanMessageAtDate();
        if ($lastHuman === null) {
            return false;
        }

        $expiresAt = $lastHuman->modify('+' . $this->timeoutMinutes . ' minutes');

        return $now <= $expiresAt;
    }

    private function persist(ConversationState $state, \DateTimeImmutable $now): ConversationState
    {
        $state->touch($now->format(\DateTimeInterface::ATOM));
        $this->repository->save($state);

        return $state;
    }

    private function resolveNow(?\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now ?? new \DateTimeImmutable('now', $this->timezone);
    }
}
