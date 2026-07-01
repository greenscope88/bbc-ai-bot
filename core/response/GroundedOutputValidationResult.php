<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-2d — internal validation result (not a frozen contract field).
 *
 * SSOT: docs/BATS_AI_GROUNDED_RESPONSE_COMPOSER.md §10.
 */
final class GroundedOutputValidationResult
{
    /** @var list<string> */
    private array $notes;

    /** @var list<string> */
    private array $failedRuleIds;

    private bool $passed;

    /**
     * @param list<string> $notes
     * @param list<string> $failedRuleIds
     */
    private function __construct(bool $passed, array $failedRuleIds, array $notes)
    {
        $this->passed = $passed;
        $this->failedRuleIds = $failedRuleIds;
        $this->notes = $notes;
    }

    public static function pass(): self
    {
        return new self(true, [], []);
    }

    /**
     * @param list<string> $failedRuleIds
     * @param list<string> $notes
     */
    public static function fail(array $failedRuleIds, array $notes): self
    {
        return new self(false, $failedRuleIds, $notes);
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    /**
     * @return list<string>
     */
    public function getFailedRuleIds(): array
    {
        return $this->failedRuleIds;
    }

    /**
     * @return list<string>
     */
    public function getNotes(): array
    {
        return $this->notes;
    }
}
