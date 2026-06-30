<?php
declare(strict_types=1);

/**
 * Phase 2-D Step 2-D-1 — Dispatch Plan（權威路由目標）Value Object.
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md §5 / F-3.
 *
 * `AiIntentUnderstandingResult.dispatch_plan` 之封閉取值。為 AIU Runtime 之
 * **唯一權威路由依據**（Runtime Dispatch 依此路由）。本 VO 僅定義合法值與驗證，
 * **不**執行任何路由（路由由 Runtime Dispatch 負責，CEP）。
 */
final class DispatchPlan
{
    public const PRODUCT = 'product';
    public const KNOWLEDGE = 'knowledge';
    public const CLARIFICATION = 'clarification';
    public const HUMAN = 'human';

    /** @var list<string> */
    private const VALID = [
        self::PRODUCT,
        self::KNOWLEDGE,
        self::CLARIFICATION,
        self::HUMAN,
    ];

    public static function isValid(string $plan): bool
    {
        return in_array($plan, self::VALID, true);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::VALID;
    }

    public static function assertValid(string $plan): void
    {
        if (!self::isValid($plan)) {
            throw new \InvalidArgumentException('invalid dispatch plan: ' . $plan);
        }
    }
}
