<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'CustomerMemoryCard.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationMemoryRepositoryInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'InMemoryConversationMemoryRepository.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'JsonFileConversationMemoryRepository.php';

/**
 * Phase 2-B Step 1 — Conversation Memory Runtime.
 *
 * SSOT:
 *   - docs/BATS_AI_CONVERSATION_MEMORY.md（Conversation Memory Runtime / Customer Memory Card）
 *   - docs/BATS_AI_CONVERSATION_ARCHITECTURE.md §4（Pipeline 第三層）
 *
 * 唯一職責：管理「本次對話上下文」（Customer Memory Card）—— 記得什麼。
 *
 * 明確不負責（依 SSOT / CA-010）：
 *   - 不擁有 Conversation Owner / Conversation Status 狀態管理權
 *     （由 Conversation State Runtime 管理；本 Runtime 僅同步鏡像 Owner）。
 *   - 不管理 CRM / 會員 / 永久 Profile。
 *   - 不自行推測、不生成回覆內容（Grounded：只記得被明確告知的事實）。
 *
 * 本元件為 Additive Implementation：不修改任何既有成功流程，僅新增 Runtime Layer。
 */
final class ConversationMemoryRuntime
{
    private ConversationMemoryRepositoryInterface $repository;

    private \DateTimeZone $timezone;

    public function __construct(
        ?ConversationMemoryRepositoryInterface $repository = null,
        ?\DateTimeZone $timezone = null
    ) {
        $this->repository = $repository ?? new JsonFileConversationMemoryRepository();
        $this->timezone = $timezone ?? new \DateTimeZone('Asia/Taipei');
    }

    public static function createForTesting(
        ?ConversationMemoryRepositoryInterface $repository = null
    ): self {
        return new self($repository ?? new InMemoryConversationMemoryRepository());
    }

    /**
     * 取出既有記憶卡；不存在時回傳 null（純讀取，不建立）。
     */
    public function get(string $conversationId): ?CustomerMemoryCard
    {
        return $this->repository->find($conversationId);
    }

    /**
     * 取出既有記憶卡；不存在時建立一張全新（Stage = New、Owner = AI）卡片，
     * 但尚未持久化（直到 remember()/save 才寫入）。
     */
    public function loadOrCreate(string $conversationId): CustomerMemoryCard
    {
        $card = $this->repository->find($conversationId);
        if ($card === null) {
            $card = CustomerMemoryCard::create($conversationId);
        }

        return $card;
    }

    /**
     * 套用本輪 grounded 變更並持久化。只更新被明確提供的欄位，不重新理解整段對話。
     *
     * 支援的 change keys：
     *   - current_requirement            (?string)
     *   - conversation_stage             (string；須為合法 Stage)
     *   - completed_items                (string|list<string>)
     *   - outstanding_issues             (string|list<string>)
     *   - resolve_outstanding            (string|list<string>：標記待辦完成)
     *   - human_handoff_status           (string：AI|HUMAN)
     *   - ai_summary                     (?string)
     *   - recently_recommended_products  (string|list<string>)
     *
     * @param array<string, mixed> $changes
     */
    public function remember(
        string $conversationId,
        array $changes,
        ?\DateTimeImmutable $now = null
    ): CustomerMemoryCard {
        $card = $this->loadOrCreate($conversationId);
        $this->applyChanges($card, $changes);

        $now = $now ?? new \DateTimeImmutable('now', $this->timezone);
        $card->touch($now->format(\DateTimeInterface::ATOM));

        $this->repository->save($card);

        return $card;
    }

    /**
     * 同步 Conversation State Runtime 提供之 Conversation Owner（唯讀鏡像）。
     * 本 Runtime 不擁有狀態管理權，僅將 Owner 反映至 Human Handoff Status。
     */
    public function syncConversationOwner(
        string $conversationId,
        string $owner,
        ?\DateTimeImmutable $now = null
    ): CustomerMemoryCard {
        return $this->remember($conversationId, ['human_handoff_status' => $owner], $now);
    }

    public function forget(string $conversationId): void
    {
        $this->repository->delete($conversationId);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function applyChanges(CustomerMemoryCard $card, array $changes): void
    {
        if (array_key_exists('current_requirement', $changes)) {
            $value = $changes['current_requirement'];
            $card->setCurrentRequirement($value !== null ? (string) $value : null);
        }

        if (isset($changes['conversation_stage']) && trim((string) $changes['conversation_stage']) !== '') {
            $card->setConversationStage((string) $changes['conversation_stage']);
        }

        foreach (self::toList($changes['completed_items'] ?? null) as $item) {
            $card->addCompletedItem($item);
        }

        foreach (self::toList($changes['outstanding_issues'] ?? null) as $issue) {
            $card->addOutstandingIssue($issue);
        }

        foreach (self::toList($changes['resolve_outstanding'] ?? null) as $issue) {
            $card->resolveOutstandingIssue($issue);
        }

        if (isset($changes['human_handoff_status']) && trim((string) $changes['human_handoff_status']) !== '') {
            $card->setHumanHandoffStatus((string) $changes['human_handoff_status']);
        }

        if (array_key_exists('ai_summary', $changes)) {
            $value = $changes['ai_summary'];
            $card->setAiSummary($value !== null ? (string) $value : null);
        }

        foreach (self::toList($changes['recently_recommended_products'] ?? null) as $product) {
            $card->addRecommendedProduct($product);
        }
    }

    /**
     * 將單一字串或字串陣列正規化為 list<string>。
     *
     * @param mixed $value
     * @return list<string>
     */
    private static function toList($value): array
    {
        if ($value === null) {
            return [];
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                if (is_array($item) || is_object($item)) {
                    continue;
                }
                $out[] = (string) $item;
            }

            return $out;
        }

        return [(string) $value];
    }
}
