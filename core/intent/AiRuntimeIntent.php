<?php
declare(strict_types=1);

/**
 * Current Runtime Intent Contract — canonical `intent_type` values for BBC AI Runtime.
 *
 * SSOT: docs/BATS_AI_RUNTIME_INTEGRATION.md §11 / §12.
 */
final class AiRuntimeIntent
{
    public const PRODUCT_SEARCH = 'product_search';
    public const KNOWLEDGE_QUERY = 'knowledge_query';
    public const AMBIGUOUS = 'ambiguous';
    public const HUMAN_SERVICE_REQUEST = 'human_service_request';

    /** @var list<string> */
    private const VALID = [
        self::PRODUCT_SEARCH,
        self::KNOWLEDGE_QUERY,
        self::AMBIGUOUS,
        self::HUMAN_SERVICE_REQUEST,
    ];

    public static function isValid(string $intent): bool
    {
        return in_array($intent, self::VALID, true);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::VALID;
    }

    public static function assertValid(string $intent): void
    {
        if (!self::isValid($intent)) {
            throw new \InvalidArgumentException('invalid ai runtime intent: ' . $intent);
        }
    }
}
