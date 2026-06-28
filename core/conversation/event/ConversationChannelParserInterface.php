<?php
declare(strict_types=1);

/**
 * Phase 2-B Step 4-D-2-A — Conversation Channel Parser（介面）.
 *
 * SSOT: Step 4-D-2 Event Source Integration Review（ABI / CEP）。
 *
 * 唯一職責：將某一外部通路（LINE / CRM / Web Chat / …）之原始事件，轉成
 * Channel 無關之 Canonical Descriptor 清單（CEP）。
 *
 * Pipeline 定位：
 *   External Channel
 *     → ConversationChannelParser（本介面）   ← 唯一知道外部格式之層
 *     → ConversationEventNormalizer            （descriptor → Domain Event）
 *     → ConversationEventAdapter
 *     → ConversationRuntimeFacade
 *
 * 嚴格契約：
 *   - Parser **只產出** Canonical Descriptor，不呼叫任何 Runtime、不寫 State /
 *     Memory、不修改 Owner、不送 LINE。
 *   - 對於無法辨識 / 不支援之事件：必須**保守略過**（回傳空陣列或排除該筆），
 *     不得拋出 production-breaking error，亦不得臆測為 human_agent_message。
 *
 * Canonical Descriptor 契約（與 ConversationEventNormalizer 相容）：
 *   [
 *     'type'             => string  // ConversationEventType::*
 *     'conversation_id'  => string
 *     'occurred_at'      => string  // ISO-8601
 *     'payload'          => array   // Channel 無關
 *     // --- Adapter 層 metadata（Normalizer 忽略）---
 *     'idempotency_key'  => string
 *     'channel'          => string
 *     'channel_event_id' => string
 *     'trace_id'         => string
 *   ]
 */
interface ConversationChannelParserInterface
{
    /** @return string 通路識別（如 'line' / 'crm' / 'web_chat'）。 */
    public function channel(): string;

    /**
     * 將原始事件 envelope 轉為 Canonical Descriptor 清單。
     *
     * @param array<string, mixed> $rawEnvelope 外部原始事件（如 LINE webhook body）
     * @param array<string, mixed> $context     tenant_sno / channel_id / trace_id 等
     * @return list<array<string, mixed>>        Canonical Descriptor 清單（可為空）
     */
    public function parse(array $rawEnvelope, array $context): array;
}
