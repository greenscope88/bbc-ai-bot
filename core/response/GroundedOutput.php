<?php
declare(strict_types=1);

/**
 * Phase 9-C-2C-1 — Grounded Response Composer output contract (presentation layer).
 *
 * SSOT: docs/PHASE_9C2C_GROUNDED_RESPONSE_COMPOSER.md
 *
 * The final, ready-to-send reply text plus grounded metadata. The text is
 * produced strictly from the GroundedInput; the composer never adds facts that
 * the Runtime did not provide.
 */
final class GroundedOutput
{
    private string $text;

    private bool $grounded;

    private int $usedFactsCount;

    private string $sourceType;

    private bool $humanServiceRequired;

    /** @var list<string> */
    private array $safetyNotes;

    /**
     * @param list<string> $safetyNotes
     */
    public function __construct(
        string $text,
        bool $grounded,
        int $usedFactsCount,
        string $sourceType,
        bool $humanServiceRequired,
        array $safetyNotes = []
    ) {
        $this->text = $text;
        $this->grounded = $grounded;
        $this->usedFactsCount = max(0, $usedFactsCount);
        $this->sourceType = $sourceType;
        $this->humanServiceRequired = $humanServiceRequired;
        $this->safetyNotes = $safetyNotes;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function isGrounded(): bool
    {
        return $this->grounded;
    }

    public function getUsedFactsCount(): int
    {
        return $this->usedFactsCount;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function isHumanServiceRequired(): bool
    {
        return $this->humanServiceRequired;
    }

    /**
     * @return list<string>
     */
    public function getSafetyNotes(): array
    {
        return $this->safetyNotes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'grounded' => $this->grounded,
            'used_facts_count' => $this->usedFactsCount,
            'source_type' => $this->sourceType,
            'human_service_required' => $this->humanServiceRequired,
            'safety_notes' => $this->safetyNotes,
        ];
    }
}
