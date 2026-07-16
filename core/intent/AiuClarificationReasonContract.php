<?php
declare(strict_types=1);

/**
 * Closed Product Search AIU clarification reason vocabulary.
 *
 * Owns AIU enum + runtime mapping. Validates structured Gemini output only.
 * Never parses utterance; never rewrites or invents reason.
 */
final class AiuClarificationReasonContract
{
    public const AIU_MISSING_DESTINATION = 'missing_destination';

    public const AIU_MISSING_TRAVEL_DATES = 'missing_travel_dates';

    public const RUNTIME_DESTINATION_UNKNOWN = 'destination_unknown';

    public const RUNTIME_DATE_REQUIRED = 'date_required';

    public const FAILURE_UNSUPPORTED = 'unsupported_aiu_clarification_reason';

    public const FAILURE_MISSING = 'clarification_reason_missing';

    public const FAILURE_ENTITY_INCONSISTENCY = 'clarification_reason_entity_inconsistency';

    /** @var list<string> */
    private const ALLOWED_AIU_REASONS = [
        self::AIU_MISSING_DESTINATION,
        self::AIU_MISSING_TRAVEL_DATES,
    ];

    /** Runtime aliases and other forbidden AIU inputs (Product Search). */
    /** @var list<string> */
    private const FORBIDDEN_AIU_REASONS = [
        'critical_slots_missing',
        self::RUNTIME_DATE_REQUIRED,
        self::RUNTIME_DESTINATION_UNKNOWN,
    ];

    /** @var array<string, string> */
    private const AIU_TO_RUNTIME_MAP = [
        self::AIU_MISSING_DESTINATION => self::RUNTIME_DESTINATION_UNKNOWN,
        self::AIU_MISSING_TRAVEL_DATES => self::RUNTIME_DATE_REQUIRED,
    ];

    /**
     * @return list<string>
     */
    public static function allowedAiuReasons(): array
    {
        return self::ALLOWED_AIU_REASONS;
    }

    /**
     * @return array<string, string>
     */
    public static function aiuToRuntimeMap(): array
    {
        return self::AIU_TO_RUNTIME_MAP;
    }

    public static function isAllowedAiuReason(string $reason): bool
    {
        return in_array(trim($reason), self::ALLOWED_AIU_REASONS, true);
    }

    /**
     * Map a validated AIU reason to Runtime clarification reason.
     * Does not infer from entities.
     *
     * @throws \InvalidArgumentException
     */
    public static function mapToRuntimeReason(string $aiuReason): string
    {
        $reason = trim($aiuReason);
        if ($reason === '') {
            throw new \InvalidArgumentException(self::FAILURE_MISSING);
        }
        if (!isset(self::AIU_TO_RUNTIME_MAP[$reason])) {
            throw new \InvalidArgumentException(self::FAILURE_UNSUPPORTED);
        }

        return self::AIU_TO_RUNTIME_MAP[$reason];
    }

    /**
     * Derive missing_entity for Resume State from a validated Closed AIU reason only.
     *
     * @throws \InvalidArgumentException
     */
    public static function missingEntityForAiuReason(string $aiuReason): string
    {
        $reason = trim($aiuReason);
        if ($reason === self::AIU_MISSING_DESTINATION) {
            return 'destination';
        }
        if ($reason === self::AIU_MISSING_TRAVEL_DATES) {
            return 'date';
        }

        throw new \InvalidArgumentException(self::FAILURE_UNSUPPORTED);
    }

    /**
     * Validate Product Search clarification reason against structured entities.
     * When clarification is not required, reason is left unchecked (null/empty OK).
     *
     * @param array<string, mixed> $entities
     * @throws \InvalidArgumentException with FAILURE_* message
     */
    public static function assertValidProductSearchClarification(
        bool $clarificationRequired,
        string $clarificationReason,
        array $entities
    ): void {
        if (!$clarificationRequired) {
            return;
        }

        $reason = trim($clarificationReason);
        if ($reason === '') {
            throw new \InvalidArgumentException(self::FAILURE_MISSING);
        }

        if (in_array($reason, self::FORBIDDEN_AIU_REASONS, true)) {
            throw new \InvalidArgumentException(self::FAILURE_UNSUPPORTED);
        }

        if (!self::isAllowedAiuReason($reason)) {
            throw new \InvalidArgumentException(self::FAILURE_UNSUPPORTED);
        }

        $hasDestination = self::hasDestination($entities);
        $hasCompleteDates = self::hasCompleteTravelDates($entities);

        if ($reason === self::AIU_MISSING_DESTINATION) {
            // Destination First: valid when destination absent, regardless of dates.
            if ($hasDestination) {
                throw new \InvalidArgumentException(self::FAILURE_ENTITY_INCONSISTENCY);
            }

            return;
        }

        // missing_travel_dates: destination present + usable dates missing/incomplete.
        if (!$hasDestination) {
            throw new \InvalidArgumentException(self::FAILURE_ENTITY_INCONSISTENCY);
        }
        if ($hasCompleteDates) {
            throw new \InvalidArgumentException(self::FAILURE_ENTITY_INCONSISTENCY);
        }
    }

    /**
     * Classify invalid reason without mutating inputs (tests / diagnostics).
     *
     * @param array<string, mixed> $entities
     * @return string|null FAILURE_* code, or null when valid / not required
     */
    public static function classifyInvalidProductSearchClarification(
        bool $clarificationRequired,
        string $clarificationReason,
        array $entities
    ): ?string {
        try {
            self::assertValidProductSearchClarification(
                $clarificationRequired,
                $clarificationReason,
                $entities
            );

            return null;
        } catch (\InvalidArgumentException $e) {
            $code = $e->getMessage();
            if (
                $code === self::FAILURE_UNSUPPORTED
                || $code === self::FAILURE_MISSING
                || $code === self::FAILURE_ENTITY_INCONSISTENCY
            ) {
                return $code;
            }

            return self::FAILURE_UNSUPPORTED;
        }
    }

    /**
     * @param array<string, mixed> $entities
     */
    public static function hasDestination(array $entities): bool
    {
        $destination = $entities['destination'] ?? null;
        if ($destination === null || $destination === '') {
            return false;
        }
        if (is_string($destination) || is_numeric($destination)) {
            return trim((string) $destination) !== '';
        }
        if (!is_array($destination)) {
            return false;
        }
        foreach ($destination as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            if (trim((string) $item) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Usable complete travel dates = both date_from and date_to present.
     *
     * @param array<string, mixed> $entities
     */
    public static function hasCompleteTravelDates(array $entities): bool
    {
        $from = isset($entities['date_from']) ? trim((string) $entities['date_from']) : '';
        $to = isset($entities['date_to']) ? trim((string) $entities['date_to']) : '';

        return $from !== '' && $to !== '';
    }
}
