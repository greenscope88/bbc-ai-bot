<?php
declare(strict_types=1);

/**
 * Outcome of a persisted rate-limit evaluation (no side effects in dry_run/disabled).
 */
final class RateLimitPersistenceResult
{
    /** @var bool */
    private $wouldAllow;

    /** @var bool */
    private $wouldReject;

    /** @var bool */
    private $skippedDueToDisabled;

    /** @var string */
    private $mode;

    /** @var array<string, mixed> */
    private $sanitizedContext;

    /**
     * @param array<string, mixed> $sanitizedContext
     */
    private function __construct(bool $wouldAllow, bool $wouldReject, bool $skippedDueToDisabled, string $mode, array $sanitizedContext)
    {
        $this->wouldAllow = $wouldAllow;
        $this->wouldReject = $wouldReject;
        $this->skippedDueToDisabled = $skippedDueToDisabled;
        $this->mode = $mode;
        $this->sanitizedContext = $sanitizedContext;
    }

    /**
     * @param array<string, mixed> $sanitizedContext
     */
    public static function disabledPassThrough(array $sanitizedContext): self
    {
        return new self(true, false, true, 'disabled', $sanitizedContext);
    }

    /**
     * @param array<string, mixed> $sanitizedContext
     */
    public static function dryRun(bool $wouldReject, array $sanitizedContext): self
    {
        return new self(!$wouldReject, $wouldReject, false, 'dry_run', $sanitizedContext);
    }

    public function wouldAllow(): bool
    {
        return $this->wouldAllow;
    }

    public function wouldReject(): bool
    {
        return $this->wouldReject;
    }

    public function skippedDueToDisabled(): bool
    {
        return $this->skippedDueToDisabled;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSanitizedContext(): array
    {
        return $this->sanitizedContext;
    }
}
