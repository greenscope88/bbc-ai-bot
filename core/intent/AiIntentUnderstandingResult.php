<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'DispatchPlan.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ExecutionHint.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'conversation' . DIRECTORY_SEPARATOR . 'ConversationOwner.php';

/**
 * Phase 2-D Step 2-D-1 — AiIntentUnderstandingResult（AIU Runtime 唯一輸出 DTO）.
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md §5 / F-3（9 欄位正式 Contract）.
 *
 * 9 個正式欄位（凍結）：
 *   intent / entity / context_snapshot / owner_snapshot / conversation_stage /
 *   resume_context / clarification / dispatch_plan / execution_hint
 *
 * 邊界（IU-002 / Owner First）：本 DTO 為 AIU Runtime 之**唯讀聚合輸出**之承載物，
 * 僅持有資料與序列化，**不**含任何 Runtime 行為、不讀寫 Memory / State、不路由、
 * 不執行業務、不組句。`dispatch_plan` 為唯一權威路由；`execution_hint` 為非綁定提示。
 */
final class AiIntentUnderstandingResult
{
    /** intent — 意圖類別（CA-009；AiIntentCategory）。 */
    private string $intent;

    /**
     * entity — 萃取之實體集合（語意欄位引用 BATS_AI_SEMANTIC_SEARCH.md）。
     *
     * @var array<string, mixed>
     */
    private array $entity = [];

    /**
     * context_snapshot — 讀自 Conversation Memory 之唯讀快照。
     *
     * @var array<string, mixed>
     */
    private array $contextSnapshot = [];

    /** owner_snapshot — 讀自 Conversation State 之有效 Owner（AI | HUMAN）。 */
    private string $ownerSnapshot = ConversationOwner::AI;

    /** conversation_stage — 對話階段（唯讀；本體由 Memory / Lifecycle 擁有）。 */
    private string $conversationStage = '';

    /**
     * resume_context — AI Resume 延續上下文；非 Resume 情境為 null。
     *
     * @var array<string, mixed>|null
     */
    private ?array $resumeContext = null;

    /** clarification.required。 */
    private bool $clarificationRequired = false;

    /** clarification.reason。 */
    private string $clarificationReason = '';

    /** dispatch_plan — 唯一權威路由目標（DispatchPlan）。 */
    private string $dispatchPlan;

    /** execution_hint — 非綁定提示（ExecutionHint；可空）。 */
    private ?string $executionHint = null;

    public function __construct(string $intent, string $dispatchPlan)
    {
        $this->setIntent($intent);
        $this->setDispatchPlan($dispatchPlan);
    }

    public static function create(string $intent, string $dispatchPlan): self
    {
        return new self($intent, $dispatchPlan);
    }

    public function getIntent(): string
    {
        return $this->intent;
    }

    public function setIntent(string $intent): self
    {
        $intent = trim($intent);
        AiIntentCategory::assertValid($intent);
        $this->intent = $intent;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getEntity(): array
    {
        return $this->entity;
    }

    /**
     * @param array<string, mixed> $entity
     */
    public function setEntity(array $entity): self
    {
        $this->entity = $entity;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContextSnapshot(): array
    {
        return $this->contextSnapshot;
    }

    /**
     * @param array<string, mixed> $contextSnapshot
     */
    public function setContextSnapshot(array $contextSnapshot): self
    {
        $this->contextSnapshot = $contextSnapshot;

        return $this;
    }

    public function getOwnerSnapshot(): string
    {
        return $this->ownerSnapshot;
    }

    public function setOwnerSnapshot(string $ownerSnapshot): self
    {
        $ownerSnapshot = trim($ownerSnapshot);
        ConversationOwner::assertValid($ownerSnapshot);
        $this->ownerSnapshot = $ownerSnapshot;

        return $this;
    }

    public function getConversationStage(): string
    {
        return $this->conversationStage;
    }

    public function setConversationStage(string $conversationStage): self
    {
        $this->conversationStage = trim($conversationStage);

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResumeContext(): ?array
    {
        return $this->resumeContext;
    }

    /**
     * @param array<string, mixed>|null $resumeContext
     */
    public function setResumeContext(?array $resumeContext): self
    {
        $this->resumeContext = $resumeContext;

        return $this;
    }

    public function isClarificationRequired(): bool
    {
        return $this->clarificationRequired;
    }

    public function getClarificationReason(): string
    {
        return $this->clarificationReason;
    }

    public function setClarification(bool $required, string $reason = ''): self
    {
        $this->clarificationRequired = $required;
        $this->clarificationReason = trim($reason);

        return $this;
    }

    public function getDispatchPlan(): string
    {
        return $this->dispatchPlan;
    }

    public function setDispatchPlan(string $dispatchPlan): self
    {
        $dispatchPlan = trim($dispatchPlan);
        DispatchPlan::assertValid($dispatchPlan);
        $this->dispatchPlan = $dispatchPlan;

        return $this;
    }

    public function getExecutionHint(): ?string
    {
        return $this->executionHint;
    }

    public function setExecutionHint(?string $executionHint): self
    {
        if ($executionHint !== null) {
            $executionHint = trim($executionHint);
            if ($executionHint === '') {
                $executionHint = null;
            }
        }
        ExecutionHint::assertValid($executionHint);
        $this->executionHint = $executionHint;

        return $this;
    }

    /**
     * 序列化為 9 欄位正式 Contract（鍵名凍結，順序對齊 SSOT §5）。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'entity' => $this->entity,
            'context_snapshot' => $this->contextSnapshot,
            'owner_snapshot' => $this->ownerSnapshot,
            'conversation_stage' => $this->conversationStage,
            'resume_context' => $this->resumeContext,
            'clarification' => [
                'required' => $this->clarificationRequired,
                'reason' => $this->clarificationReason,
            ],
            'dispatch_plan' => $this->dispatchPlan,
            'execution_hint' => $this->executionHint,
        ];
    }

    /**
     * 自 9 欄位 Contract 還原（容忍缺漏：套用 SSOT 預設值）。
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $intent = isset($data['intent']) ? (string) $data['intent'] : '';
        $dispatchPlan = isset($data['dispatch_plan']) ? (string) $data['dispatch_plan'] : '';

        $result = new self($intent, $dispatchPlan);

        if (isset($data['entity']) && is_array($data['entity'])) {
            $result->setEntity($data['entity']);
        }
        if (isset($data['context_snapshot']) && is_array($data['context_snapshot'])) {
            $result->setContextSnapshot($data['context_snapshot']);
        }
        if (isset($data['owner_snapshot']) && trim((string) $data['owner_snapshot']) !== '') {
            $result->setOwnerSnapshot((string) $data['owner_snapshot']);
        }
        if (array_key_exists('conversation_stage', $data)) {
            $result->setConversationStage((string) $data['conversation_stage']);
        }
        if (array_key_exists('resume_context', $data)) {
            $resume = $data['resume_context'];
            $result->setResumeContext(is_array($resume) ? $resume : null);
        }
        if (isset($data['clarification']) && is_array($data['clarification'])) {
            $result->setClarification(
                (bool) ($data['clarification']['required'] ?? false),
                (string) ($data['clarification']['reason'] ?? '')
            );
        }
        if (array_key_exists('execution_hint', $data)) {
            $hint = $data['execution_hint'];
            $result->setExecutionHint($hint !== null ? (string) $hint : null);
        }

        return $result;
    }
}
