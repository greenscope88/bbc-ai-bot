<?php
declare(strict_types=1);

/**
 * Phase 2-E Step 2-E-1 — GroundedOutput layout_profile Value Object.
 *
 * SSOT: docs/BATS_AI_GROUNDED_RESPONSE_COMPOSER.md §9.5.
 */
final class LayoutProfile
{
    public const PRODUCT_RICH = 'product_rich_v1';
    public const KNOWLEDGE_STANDARD = 'knowledge_standard_v1';
    public const MINIMAL = 'minimal_v1';

    /** @var list<string> */
    private const VALID = [
        self::PRODUCT_RICH,
        self::KNOWLEDGE_STANDARD,
        self::MINIMAL,
    ];

    public static function isValid(string $profile): bool
    {
        return in_array($profile, self::VALID, true);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::VALID;
    }

    public static function assertValid(string $profile): void
    {
        if (!self::isValid($profile)) {
            throw new \InvalidArgumentException('invalid layout profile: ' . $profile);
        }
    }
}
