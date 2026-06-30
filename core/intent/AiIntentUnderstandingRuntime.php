<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingRuntimeInterface.php';

/**
 * Phase 2-D Step 2-D-1 — AI Intent Understanding Runtime（Skeleton）.
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md §3 / §4 / §6 / F-1 / F-2.
 *
 * 本回合（2-D-1 Contract Foundation）**僅建立 Runtime Skeleton**，作為後續 Runtime
 * 的統一入口骨架與 ABI 穩定點。
 *
 * 嚴格邊界（本步 Scope）：**不含任何實際 Runtime Logic** —— 不接 Knowledge /
 * Search / Grounded / Execution / Reply、不讀寫 Owner / Memory / State、不路由、
 * 不分類、不組句。`understand()` 於本骨架階段明確拋出「未實作」訊號；實際理解與
 * dispatch planning 之邏輯於 Phase 2-D-2+ 落地。
 */
final class AiIntentUnderstandingRuntime implements AiIntentUnderstandingRuntimeInterface
{
    /** 標示本類別目前為 Contract Foundation 骨架（無 runtime logic）。 */
    public const PHASE = '2-D-1';

    /**
     * {@inheritDoc}
     *
     * 骨架階段：不執行任何理解 / 聚合 / 路由邏輯。
     *
     * @param array<string, mixed> $context
     */
    public function understand(string $customerMessage, array $context = []): AiIntentUnderstandingResult
    {
        throw new \BadMethodCallException(
            'AI Intent Understanding Runtime skeleton (Phase 2-D-1 Contract Foundation): '
            . 'understand() carries no runtime logic yet; implementation lands in Phase 2-D-2+.'
        );
    }
}
