<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'conversation' . DIRECTORY_SEPARATOR . 'ConversationRuntimeFacade.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'conversation' . DIRECTORY_SEPARATOR . 'ConversationOwner.php';

/**
 * Phase 2-D Step 2-D-2 — AI Intent Context Loader（唯讀 snapshot 載入器）.
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md §3.2 / §4 / §6 / §8（Integration）.
 *
 * 唯一職責：為 AIU Runtime **唯讀**載入下列 snapshot —
 *   - context_snapshot     ← Conversation Memory Runtime（Customer Memory Card）
 *   - owner_snapshot       ← Conversation State Runtime（resolveEffectiveOwner）
 *   - conversation_stage   ← Customer Memory Card 之 Conversation Stage
 *   - resume_context       ← State Runtime（CA-006 evaluateAiResume 為 true 時）
 *
 * 嚴格邊界（IU-002 / Owner First / CEP）：
 *   - **絕不**修改 Memory / State / Owner；僅呼叫唯讀 get / resolve / evaluate。
 *   - 無 conversation_id（或對話不存在）→ 回傳 SSOT 預設（owner = AI、空 context、
 *     stage 空字串、resume null），不建立任何狀態。
 *   - 全程防禦化（never-throw）：載入失敗一律退回預設，不影響理解流程。
 */
final class AiIntentContextLoader
{
    /** @var ConversationRuntimeFacade */
    private $facade;

    public function __construct(?ConversationRuntimeFacade $facade = null)
    {
        $this->facade = $facade ?? new ConversationRuntimeFacade();
    }

    public static function createForTesting(?ConversationRuntimeFacade $facade = null): self
    {
        return new self($facade ?? ConversationRuntimeFacade::createForTesting());
    }

    /**
     * 唯讀載入 snapshot 集合。
     *
     * @return array{
     *   context_snapshot: array<string, mixed>,
     *   owner_snapshot: string,
     *   conversation_stage: string,
     *   resume_context: array<string, mixed>|null
     * }
     */
    public function load(string $conversationId, ?\DateTimeImmutable $now = null): array
    {
        $default = [
            'context_snapshot' => [],
            'owner_snapshot' => ConversationOwner::AI,
            'conversation_stage' => '',
            'resume_context' => null,
        ];

        $conversationId = trim($conversationId);
        if ($conversationId === '') {
            return $default;
        }

        try {
            // --- Memory（唯讀）---
            $card = $this->facade->memory()->get($conversationId);
            if ($card !== null) {
                $default['context_snapshot'] = $card->toArray();
                $default['conversation_stage'] = $card->getConversationStage();
            }

            // --- State（唯讀）---
            $default['owner_snapshot'] = $this->facade->state()->resolveEffectiveOwner($conversationId, $now);

            // --- Resume Context（CA-006；唯讀判定，不套用轉移）---
            if ($this->facade->state()->evaluateAiResume($conversationId, $now)) {
                $state = $this->facade->state()->get($conversationId);
                if ($state !== null) {
                    $default['resume_context'] = [
                        'resumed_from_owner' => ConversationOwner::HUMAN,
                        'last_human_message_at' => $state->getLastHumanMessageAt(),
                        'last_customer_message_at' => $state->getLastCustomerMessageAt(),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // never-throw：任何讀取失敗退回已累積之預設（不影響理解流程）。
        }

        return $default;
    }
}
