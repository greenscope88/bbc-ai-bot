<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeState.php';

/**
 * Typed load result for Structured Search Resume Store (store-only semantics).
 */
final class StructuredSearchResumeLoadResult
{
    public const FOUND = 'FOUND';
    public const NOT_FOUND = 'NOT_FOUND';
    public const EXPIRED = 'EXPIRED';
    public const CORRUPT = 'CORRUPT';
    public const IDENTITY_MISMATCH = 'IDENTITY_MISMATCH';
    public const IO_ERROR = 'IO_ERROR';

    private string $status;
    private ?StructuredSearchResumeState $state;
    private string $failureReason;

    private function __construct(
        string $status,
        ?StructuredSearchResumeState $state = null,
        string $failureReason = ''
    ) {
        $this->status = $status;
        $this->state = $state;
        $this->failureReason = $failureReason;
    }

    public static function found(StructuredSearchResumeState $state): self
    {
        return new self(self::FOUND, $state);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    public static function expired(?StructuredSearchResumeState $state = null): self
    {
        return new self(self::EXPIRED, $state);
    }

    public static function corrupt(string $reason = ''): self
    {
        return new self(self::CORRUPT, null, $reason);
    }

    public static function identityMismatch(string $reason = ''): self
    {
        return new self(self::IDENTITY_MISMATCH, null, $reason);
    }

    public static function ioError(string $reason = ''): self
    {
        return new self(self::IO_ERROR, null, $reason);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getState(): ?StructuredSearchResumeState
    {
        return $this->state;
    }

    public function getFailureReason(): string
    {
        return $this->failureReason;
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }
}
