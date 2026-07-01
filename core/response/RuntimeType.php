<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-1 — GroundedInput runtime_type Value Object.
 *
 * SSOT: docs/BATS_AI_GROUNDED_RESPONSE_COMPOSER.md §8.5.
 */
final class RuntimeType
{
    public const PRODUCT_SEARCH = 'product_search';
    public const KNOWLEDGE_PRIVATE = 'knowledge_private';
    public const KNOWLEDGE_SHARED = 'knowledge_shared';
    public const HUMAN_SERVICE = 'human_service';
    public const CLARIFICATION = 'clarification';
    public const WAITING_ACK = 'waiting_ack';

    /** @var list<string> */
    private const VALID = [
        self::PRODUCT_SEARCH,
        self::KNOWLEDGE_PRIVATE,
        self::KNOWLEDGE_SHARED,
        self::HUMAN_SERVICE,
        self::CLARIFICATION,
        self::WAITING_ACK,
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
            throw new \InvalidArgumentException('invalid runtime type: ' . $type);
        }
    }
}
