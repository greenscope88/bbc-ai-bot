<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-1 — GroundedOutput reply_type Value Object.
 *
 * SSOT: docs/BATS_AI_GROUNDED_RESPONSE_COMPOSER.md §9.5.
 */
final class ReplyType
{
    public const NORMAL = 'normal_reply';
    public const CLARIFICATION = 'clarification';
    public const WAITING_ACK = 'waiting_ack';
    public const HUMAN_FALLBACK = 'human_agent_fallback';
    public const NO_RESULTS = 'no_results';
    public const SUPPRESSED_HUMAN_TAKEOVER = 'suppressed_human_takeover';

    /** @var list<string> */
    private const VALID = [
        self::NORMAL,
        self::CLARIFICATION,
        self::WAITING_ACK,
        self::HUMAN_FALLBACK,
        self::NO_RESULTS,
        self::SUPPRESSED_HUMAN_TAKEOVER,
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::VALID, true);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::VALID;
    }

    public static function assertValid(string $type): void
    {
        if (!self::isValid($type)) {
            throw new \InvalidArgumentException('invalid reply type: ' . $type);
        }
    }
}
