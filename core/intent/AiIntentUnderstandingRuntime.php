<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntimeInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentContextLoader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'DispatchPlan.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ExecutionHint.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'conversation' . DIRECTORY_SEPARATOR . 'ConversationOwner.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'KnowledgeIntentDetector.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'BatsSearchIntentBuilder.php';

/**
 * Phase 2-D Step 2-D-2 — AI Intent Understanding Runtime（核心理解流程）.
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md §3 / §4 / §5 / §6 / §8 / F-1～F-5.
 *
 * 唯一職責（L1 Decision Layer）：編排 §4 理解流程，**唯讀**聚合 snapshot 並整合
 * 既有 v1 元件（Wrapper 重用，不重寫），輸出單一 `AiIntentUnderstandingResult`。
 *
 * 整合之既有 v1 元件（Wrapper 重用）：
 *   - KnowledgeIntentDetector  → intent 類別（product_search / knowledge_query / ambiguous）
 *   - BatsSearchIntentBuilder  → Product 路徑之 entity + clarification（內含 ClarificationPolicy）
 *   - AiIntentContextLoader    → context / owner / stage / resume snapshot（唯讀）
 *
 * 嚴格邊界（IU-002 / Owner First / CEP）：
 *   - **唯讀**：不修改 Owner / Memory / State；不路由、不執行業務、不組句、不送訊息。
 *   - `dispatch_plan` 為唯一權威路由；`execution_hint` 為非綁定提示（封閉 enum）。
 *   - Owner = HUMAN → `dispatch_plan = human`、`execution_hint = human_blocked`
 *     （仍完成理解，但不改變 Owner；Ownership Rule，L2 §7 / CA-005）。
 *   - Ambiguous → 一律 `clarification.required = true` 且 `dispatch_plan = clarification`
 *     （CA-009：不得直接進入其他 Execution Runtime）。
 */
final class AiIntentUnderstandingRuntime implements AiIntentUnderstandingRuntimeInterface
{
    public const PHASE = '2-D-2';

    /** @var KnowledgeIntentDetector */
    private $knowledgeIntentDetector;

    /** @var BatsSearchIntentBuilder */
    private $searchIntentBuilder;

    /** @var AiIntentContextLoader */
    private $contextLoader;

    public function __construct(
        ?KnowledgeIntentDetector $knowledgeIntentDetector = null,
        ?BatsSearchIntentBuilder $searchIntentBuilder = null,
        ?AiIntentContextLoader $contextLoader = null
    ) {
        $this->knowledgeIntentDetector = $knowledgeIntentDetector ?? new KnowledgeIntentDetector();
        $this->searchIntentBuilder = $searchIntentBuilder ?? new BatsSearchIntentBuilder();
        $this->contextLoader = $contextLoader ?? new AiIntentContextLoader();
    }

    public static function createForTesting(
        ?KnowledgeIntentDetector $knowledgeIntentDetector = null,
        ?BatsSearchIntentBuilder $searchIntentBuilder = null,
        ?AiIntentContextLoader $contextLoader = null
    ): self {
        return new self(
            $knowledgeIntentDetector ?? new KnowledgeIntentDetector(),
            $searchIntentBuilder ?? new BatsSearchIntentBuilder(),
            $contextLoader ?? AiIntentContextLoader::createForTesting()
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $context  ['conversation_id'=>?string, 'now'=>?DateTimeImmutable,
     *                                         'reference_date'=>?DateTimeImmutable, 'tenant_sno'=>?string, ...]
     */
    public function understand(string $customerMessage, array $context = []): AiIntentUnderstandingResult
    {
        $message = trim($customerMessage);
        $now = ($context['now'] ?? null) instanceof \DateTimeImmutable ? $context['now'] : null;
        $conversationId = isset($context['conversation_id']) ? (string) $context['conversation_id'] : '';

        // ①～③ 唯讀 snapshot（owner / context / stage / resume）。
        $snapshot = $this->contextLoader->load($conversationId, $now);

        // ④ Intent 類別（Wrapper：KnowledgeIntentDetector）。
        $detected = $this->classify($message);

        // ⑤⑥ entity + clarification（Product 走 BatsSearchIntentBuilder；Ambiguous 強制澄清）。
        $entity = [];
        $clarificationRequired = false;
        $clarificationReason = '';

        if ($detected === AiIntentCategory::PRODUCT_SEARCH) {
            $searchIntent = $this->searchIntentBuilder->parse($message, $this->builderContext($context));
            $entity = $searchIntent->toArray();
            $clarificationRequired = $searchIntent->isClarificationRequired();
            $clarificationReason = (string) ($searchIntent->getClarificationReason() ?? '');
        } elseif ($detected === AiIntentCategory::AMBIGUOUS) {
            $clarificationRequired = true;
            $clarificationReason = 'intent_ambiguous';
        }

        // ⑧ Dispatch Planning（Owner First）。
        $owner = $snapshot['owner_snapshot'];
        [$dispatchPlan, $executionHint] = $this->planDispatch($detected, $owner, $clarificationRequired);

        // 組裝 AiIntentUnderstandingResult（§5）。
        $result = new AiIntentUnderstandingResult($detected, $dispatchPlan);
        $result
            ->setEntity($entity)
            ->setContextSnapshot($snapshot['context_snapshot'])
            ->setOwnerSnapshot($owner)
            ->setConversationStage($snapshot['conversation_stage'])
            ->setResumeContext($snapshot['resume_context'])
            ->setClarification($clarificationRequired, $clarificationReason)
            ->setExecutionHint($executionHint);

        return $result;
    }

    /**
     * Wrapper：v1 KnowledgeIntentDetector → v2 AiIntentCategory。
     */
    private function classify(string $message): string
    {
        $intentType = (string) ($this->knowledgeIntentDetector->detect($message)['intent_type'] ?? '');

        switch ($intentType) {
            case KnowledgeIntentDetector::INTENT_PRODUCT_SEARCH:
                return AiIntentCategory::PRODUCT_SEARCH;
            case KnowledgeIntentDetector::INTENT_KNOWLEDGE_QUERY:
                return AiIntentCategory::KNOWLEDGE;
            case KnowledgeIntentDetector::INTENT_AMBIGUOUS:
            default:
                return AiIntentCategory::AMBIGUOUS;
        }
    }

    /**
     * Dispatch Planning（Owner First / CA-009）。
     *
     * @return array{0: string, 1: string|null} [dispatch_plan, execution_hint]
     */
    private function planDispatch(string $category, string $owner, bool $clarificationRequired): array
    {
        // Owner First：真人持有對話 → AI 不得執行（Ownership Rule）。
        if ($owner === ConversationOwner::HUMAN) {
            return [DispatchPlan::HUMAN, ExecutionHint::HUMAN_BLOCKED];
        }

        if ($category === AiIntentCategory::AMBIGUOUS) {
            // CA-009：Ambiguous 必須 Clarification，不得直入其他 Execution。
            return [DispatchPlan::CLARIFICATION, null];
        }

        if ($category === AiIntentCategory::PRODUCT_SEARCH) {
            if ($clarificationRequired) {
                return [DispatchPlan::CLARIFICATION, ExecutionHint::PRODUCT_CLARIFICATION];
            }

            return [DispatchPlan::PRODUCT, ExecutionHint::PRODUCT_SEARCH];
        }

        // Knowledge：交由 Knowledge Runtime 解析（不編碼 tenant→industry→global 解析鏈）。
        return [DispatchPlan::KNOWLEDGE, ExecutionHint::KNOWLEDGE_RESOLVE];
    }

    /**
     * 僅透傳 BatsSearchIntentBuilder 支援之 context（如 reference_date），不擴大 scope。
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function builderContext(array $context): array
    {
        $builderContext = [];
        if (($context['reference_date'] ?? null) instanceof \DateTimeImmutable) {
            $builderContext['reference_date'] = $context['reference_date'];
        }

        return $builderContext;
    }
}
