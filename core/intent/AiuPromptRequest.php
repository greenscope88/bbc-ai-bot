<?php
declare(strict_types=1);

/**
 * Phase 2-D Step 2-D-4 — AIU Prompt Request（§16.3 邏輯輸入形狀）.
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md §16.3
 */
final class AiuPromptRequest
{
    private string $tenantId;
    private string $channel;
    private string $customerUtterance;
    /** @var array<string, mixed> */
    private array $contextSnapshot;
    private string $ownerSnapshot;
    private string $conversationStage;
    /** @var array<string, mixed>|null */
    private ?array $resumeContext;
    private ?string $requestId;
    /** Reference datetime for Gemini date resolution (timezone embedded). */
    private \DateTimeImmutable $referenceDateTime;
    /**
     * Typed Structured Search pending state for Prompt injection (null when absent).
     *
     * @var array<string, mixed>|null
     */
    private ?array $structuredSearchResumeState;

    /**
     * @param array<string, mixed>      $contextSnapshot
     * @param array<string, mixed>|null $resumeContext
     * @param array<string, mixed>|null $structuredSearchResumeState
     */
    public function __construct(
        string $tenantId,
        string $channel,
        string $customerUtterance,
        array $contextSnapshot,
        string $ownerSnapshot,
        string $conversationStage,
        ?array $resumeContext,
        \DateTimeImmutable $referenceDateTime,
        ?string $requestId = null,
        ?array $structuredSearchResumeState = null
    ) {
        $this->tenantId = trim($tenantId);
        $this->channel = trim($channel) !== '' ? trim($channel) : 'line';
        $this->customerUtterance = trim($customerUtterance);
        $this->contextSnapshot = $contextSnapshot;
        $this->ownerSnapshot = trim($ownerSnapshot);
        $this->conversationStage = trim($conversationStage);
        $this->resumeContext = $resumeContext;
        $this->referenceDateTime = $referenceDateTime;
        $this->requestId = $requestId !== null && trim($requestId) !== '' ? trim($requestId) : null;
        $this->structuredSearchResumeState = $structuredSearchResumeState;
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getCustomerUtterance(): string
    {
        return $this->customerUtterance;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContextSnapshot(): array
    {
        return $this->contextSnapshot;
    }

    public function getOwnerSnapshot(): string
    {
        return $this->ownerSnapshot;
    }

    public function getConversationStage(): string
    {
        return $this->conversationStage;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResumeContext(): ?array
    {
        return $this->resumeContext;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStructuredSearchResumeState(): ?array
    {
        return $this->structuredSearchResumeState;
    }

    public function hasStructuredSearchResumeState(): bool
    {
        return $this->structuredSearchResumeState !== null;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getReferenceDateTime(): \DateTimeImmutable
    {
        return $this->referenceDateTime;
    }

    /**
     * Calendar date (YYYY-MM-DD) in the reference timezone.
     */
    public function getReferenceCalendarDate(): string
    {
        return $this->referenceDateTime->format('Y-m-d');
    }

    /**
     * Timezone name of the reference datetime (e.g. Asia/Taipei).
     */
    public function getReferenceTimezone(): string
    {
        return $this->referenceDateTime->getTimezone()->getName();
    }
}
