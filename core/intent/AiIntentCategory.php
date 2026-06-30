<?php
declare(strict_types=1);

/**
 * Phase 2-D Step 2-D-1 — AI Intent Category（意圖類別）Value Object.
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md §5（intent 欄位）；
 *       canonical 定義見 docs/BATS_AI_CONVERSATION_ARCHITECTURE.md §3 / CA-009.
 *
 * 本 VO 僅承載 `AiIntentUnderstandingResult.intent` 之封閉取值（CA-009 三類），
 * 為 Contract Foundation 之 ABI 穩定點。**不**重新定義意圖語意（語意本體仍以
 * L2 §3 / CA-009 為準），亦不含任何分類邏輯。
 */
final class AiIntentCategory
{
    public const PRODUCT_SEARCH = 'Product Search';
    public const KNOWLEDGE = 'Knowledge';
    public const AMBIGUOUS = 'Ambiguous';

    /** @var list<string> */
    private const VALID = [
        self::PRODUCT_SEARCH,
        self::KNOWLEDGE,
        self::AMBIGUOUS,
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
            throw new \InvalidArgumentException('invalid ai intent category: ' . $intent);
        }
    }
}
