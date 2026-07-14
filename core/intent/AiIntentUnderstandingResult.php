<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentCategory.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'conversation' . DIRECTORY_SEPARATOR . 'ConversationOwner.php';

/**
 * AIU Understanding Result — semantic contract after Normalize.
 *
 * B0-3: Contract output has entities only; no dispatch_plan / execution_hint.
 */
final class AiIntentUnderstandingResult
{
    private string $intent;

    /** @var array<string, mixed> */
    private array $entities = [];

    /** @var array<string, mixed> */
    private array $contextSnapshot = [];

    private string $ownerSnapshot = ConversationOwner::AI;

    private string $conversationStage = '';

    /** @var array<string, mixed>|null */
    private ?array $resumeContext = null;

    private bool $clarificationRequired = false;

    private string $clarificationReason = '';

    private float $confidence = 0.0;

    /**
     * Observability-only: raw Gemini date-field presence booleans.
     * Not part of the semantic contract; never consumed by routing / search / compose.
     *
     * @var array{
     *   raw_has_date_range: bool,
     *   raw_has_date_from: bool,
     *   raw_has_date_to: bool,
     *   raw_has_date_expression: bool
     * }|null
     */
    private ?array $datePipelineRawPresence = null;

    public function __construct(string $intent)
    {
        $this->setIntent($intent);
    }

    public static function create(string $intent): self
    {
        return new self($intent);
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
    public function getEntities(): array
    {
        return $this->entities;
    }

    /**
     * @param array<string, mixed> $entities
     */
    public function setEntities(array $entities): self
    {
        $this->entities = $entities;

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

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    public function setConfidence(float $confidence): self
    {
        $this->confidence = max(0.0, min(1.0, $confidence));

        return $this;
    }

    /**
     * Attach raw Gemini date-field presence flags once (immutable thereafter).
     *
     * @param array{
     *   raw_has_date_range?: bool,
     *   raw_has_date_from?: bool,
     *   raw_has_date_to?: bool,
     *   raw_has_date_expression?: bool
     * } $presence
     */
    public function attachDatePipelineRawPresence(array $presence): self
    {
        if ($this->datePipelineRawPresence !== null) {
            return $this;
        }

        $this->datePipelineRawPresence = [
            'raw_has_date_range' => (bool) ($presence['raw_has_date_range'] ?? false),
            'raw_has_date_from' => (bool) ($presence['raw_has_date_from'] ?? false),
            'raw_has_date_to' => (bool) ($presence['raw_has_date_to'] ?? false),
            'raw_has_date_expression' => (bool) ($presence['raw_has_date_expression'] ?? false),
        ];

        return $this;
    }

    /**
     * @return array{
     *   raw_has_date_range: bool,
     *   raw_has_date_from: bool,
     *   raw_has_date_to: bool,
     *   raw_has_date_expression: bool
     * }|null
     */
    public function getDatePipelineRawPresence(): ?array
    {
        return $this->datePipelineRawPresence;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'entities' => $this->entities,
            'context_snapshot' => $this->contextSnapshot,
            'owner_snapshot' => $this->ownerSnapshot,
            'conversation_stage' => $this->conversationStage,
            'resume_context' => $this->resumeContext,
            'clarification' => [
                'required' => $this->clarificationRequired,
                'reason' => $this->clarificationReason,
            ],
            'confidence' => $this->confidence,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $intent = isset($data['intent']) ? (string) $data['intent'] : '';
        $result = new self($intent);

        if (isset($data['entities']) && is_array($data['entities'])) {
            $result->setEntities($data['entities']);
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
        if (isset($data['confidence'])) {
            $result->setConfidence((float) $data['confidence']);
        }

        return $result;
    }
}
