<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'StructuredSearchResumeState.php';

/**
 * Typed mutation result for Structured Search Resume Store (store-only semantics).
 * Does not represent webhook / LINE / Gemini / Search completion.
 */
final class StructuredSearchResumeMutationResult
{
    public const CREATED = 'CREATED';
    public const REPLACED = 'REPLACED';
    public const CLEARED = 'CLEARED';
    public const ALREADY_ABSENT = 'ALREADY_ABSENT';
    public const IDEMPOTENT_REPLAY = 'IDEMPOTENT_REPLAY';
    public const VERSION_CONFLICT = 'VERSION_CONFLICT';
    public const IDENTITY_MISMATCH = 'IDENTITY_MISMATCH';
    public const INVALID_STATE = 'INVALID_STATE';
    public const IO_ERROR = 'IO_ERROR';

    private string $status;
    private ?StructuredSearchResumeState $state;
    private ?int $version;
    private string $failureReason;
    private string $operation;

    private function __construct(
        string $status,
        string $operation = '',
        ?StructuredSearchResumeState $state = null,
        ?int $version = null,
        string $failureReason = ''
    ) {
        $this->status = $status;
        $this->operation = $operation;
        $this->state = $state;
        $this->version = $version;
        $this->failureReason = $failureReason;
    }

    public static function created(StructuredSearchResumeState $state): self
    {
        return new self(self::CREATED, 'create', $state, $state->getStateVersion());
    }

    public static function replaced(StructuredSearchResumeState $state): self
    {
        return new self(self::REPLACED, 'replace', $state, $state->getStateVersion());
    }

    public static function cleared(int $clearedVersion): self
    {
        return new self(self::CLEARED, 'clear', null, $clearedVersion);
    }

    public static function alreadyAbsent(): self
    {
        return new self(self::ALREADY_ABSENT, 'clear');
    }

    public static function idempotentReplay(
        string $operation,
        ?StructuredSearchResumeState $state = null
    ): self {
        $version = $state !== null ? $state->getStateVersion() : null;

        return new self(self::IDEMPOTENT_REPLAY, $operation, $state, $version);
    }

    public static function versionConflict(string $operation, string $reason = ''): self
    {
        return new self(self::VERSION_CONFLICT, $operation, null, null, $reason);
    }

    public static function identityMismatch(string $operation, string $reason = ''): self
    {
        return new self(self::IDENTITY_MISMATCH, $operation, null, null, $reason);
    }

    public static function invalidState(string $operation, string $reason = ''): self
    {
        return new self(self::INVALID_STATE, $operation, null, null, $reason);
    }

    public static function ioError(string $operation, string $reason = ''): self
    {
        return new self(self::IO_ERROR, $operation, null, null, $reason);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getState(): ?StructuredSearchResumeState
    {
        return $this->state;
    }

    public function getVersion(): ?int
    {
        return $this->version;
    }

    public function getFailureReason(): string
    {
        return $this->failureReason;
    }

    public function isSuccess(): bool
    {
        return in_array(
            $this->status,
            [
                self::CREATED,
                self::REPLACED,
                self::CLEARED,
                self::ALREADY_ABSENT,
                self::IDEMPOTENT_REPLAY,
            ],
            true
        );
    }
}
