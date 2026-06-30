<?php
declare(strict_types=1);

/**
 * Phase 2-D Step 2-D-1 — Execution Hint（非綁定執行提示）Value Object.
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md §5.2 / F-4 / IU-004.
 *
 * `AiIntentUnderstandingResult.execution_hint` 之封閉取值（可空）。
 *
 * 守則（IU-004 / CEP）：
 *   - **非綁定（advisory）**：dispatch_plan 為唯一權威；hint 僅為提示，Execution
 *     Runtime 可忽略。
 *   - **扁平字串、封閉 enum、可空**：確保契約長期穩定。
 *   - **僅投影 AIU 已知事實**（clarification / owner snapshot），零額外執行決策權。
 *   - **不得編碼 Execution Runtime 內部解析鏈**（如 Knowledge 之
 *     Tenant→Industry→Global 順序屬 Knowledge / Industry Shared Runtime）。
 */
final class ExecutionHint
{
    // Product（由 clarification.required 推導）
    public const PRODUCT_SEARCH = 'product_search';
    public const PRODUCT_CLARIFICATION = 'product_clarification';
    public const PRODUCT_NO_SEARCH = 'product_no_search';

    // Knowledge（僅標示交由 Knowledge Runtime 解析；不含解析鏈）
    public const KNOWLEDGE_RESOLVE = 'knowledge_resolve';

    // Human（由 owner_snapshot = HUMAN 推導）
    public const HUMAN_BLOCKED = 'human_blocked';

    /** @var list<string> */
    private const VALID = [
        self::PRODUCT_SEARCH,
        self::PRODUCT_CLARIFICATION,
        self::PRODUCT_NO_SEARCH,
        self::KNOWLEDGE_RESOLVE,
        self::HUMAN_BLOCKED,
    ];

    /**
     * null 代表「無適用提示」（合法）。
     */
    public static function isValid(?string $hint): bool
    {
        if ($hint === null) {
            return true;
        }

        return in_array($hint, self::VALID, true);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::VALID;
    }

    public static function assertValid(?string $hint): void
    {
        if (!self::isValid($hint)) {
            throw new \InvalidArgumentException('invalid execution hint: ' . (string) $hint);
        }
    }
}
