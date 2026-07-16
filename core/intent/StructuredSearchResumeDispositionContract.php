<?php
declare(strict_types=1);

/**
 * Gemini Resume Disposition — closed enum + state-presence consistency.
 * Validates only; never classifies utterance or merges entities.
 */
final class StructuredSearchResumeDispositionContract
{
    public const CONTINUE_PENDING = 'continue_pending';
    public const MODIFY_PENDING = 'modify_pending';
    public const NEW_REQUEST = 'new_request';

    public const FAILURE_MISSING = 'resume_disposition_missing';
    public const FAILURE_UNSUPPORTED = 'resume_disposition_unsupported';
    public const FAILURE_INCONSISTENT = 'resume_disposition_state_inconsistency';

    /** @var list<string> */
    private const ALLOWED = [
        self::CONTINUE_PENDING,
        self::MODIFY_PENDING,
        self::NEW_REQUEST,
    ];

    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        return self::ALLOWED;
    }

    public static function isAllowed(string $disposition): bool
    {
        return in_array(trim($disposition), self::ALLOWED, true);
    }

    /**
     * @param string|null $disposition Normalized disposition (empty string = absent)
     * @throws \InvalidArgumentException
     */
    public static function assertValidForPriorState(?string $disposition, bool $priorStateInjected): void
    {
        $value = $disposition !== null ? trim($disposition) : '';

        if ($priorStateInjected) {
            if ($value === '') {
                throw new \InvalidArgumentException(self::FAILURE_MISSING);
            }
            if (!self::isAllowed($value)) {
                throw new \InvalidArgumentException(self::FAILURE_UNSUPPORTED);
            }

            return;
        }

        // No prior pending injected: absent or new_request only.
        if ($value === '' || $value === self::NEW_REQUEST) {
            return;
        }

        if (self::isAllowed($value)) {
            throw new \InvalidArgumentException(self::FAILURE_INCONSISTENT);
        }

        throw new \InvalidArgumentException(self::FAILURE_UNSUPPORTED);
    }
}
