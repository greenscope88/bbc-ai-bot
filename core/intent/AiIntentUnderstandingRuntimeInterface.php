<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiIntentUnderstandingResult.php';

/**
 * Phase 2-D Step 2-D-1 — AI Intent Understanding Runtime Interface（ABI）.
 *
 * SSOT: docs/BATS_AI_INTENT_UNDERSTANDING_V2.md §3 / §4 / §6 / F-1 / F-2.
 *
 * AIU Runtime（L1 Decision Layer）之統一入口契約：接收 Customer Message（+ context：
 * tenant 識別 / trace_id 等），產出單一 `AiIntentUnderstandingResult`（§5）。
 *
 * 邊界（IU-002 / Owner First / CEP）：實作為**唯讀聚合器 + 決策層**，不變更
 * Owner / Status / Memory、不路由、不執行業務、不組句、不送任何 Channel 訊息。
 */
interface AiIntentUnderstandingRuntimeInterface
{
    /**
     * @param array<string, mixed> $context  ['tenant_sno' => ?string, 'trace_id' => ?string, ...]
     */
    public function understand(string $customerMessage, array $context = []): AiIntentUnderstandingResult;
}
