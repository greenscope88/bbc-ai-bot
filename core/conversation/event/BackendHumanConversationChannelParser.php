<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationChannelParserInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationEventType.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationIdentityBuilder.php';

/**
 * Phase 2-C Step 2-C-1 — Backend / CRM Human Conversation Channel Parser（Foundation）.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md CA-005（Automatic Human Takeover）、
 *       CA-003（Ownership / Owner First）；Phase 2-C Human Service Runtime Plan。
 *
 * 唯一職責：將「後台 / CRM 真人客服已成功送出訊息」之事件，轉成 Channel 無關之
 * `human_agent_message` Canonical Descriptor（CEP）。本回合為 fixture-level
 * Foundation —— 不接 webhook、不接 SaaSRouter、不接 Runtime、不修改 Owner。
 *
 * Owner First Principle：本 Parser 僅產出事件描述符，**絕不**改變 Conversation
 * Owner；Owner 轉移（→ HUMAN）只能由 ConversationStateRuntime 於後續整合步驟
 * （Step 2-C-2）依此事件執行。
 *
 * 正式 Event Contract（Phase 2-C 裁示，不得自行擴充）：
 *   - tenant_sno       （可由 context 提供；context 優先，envelope 後備）
 *   - line_user_id     （必填；用於 conversation identity）
 *   - agent_id         （真人客服識別；缺漏時容忍為空字串）
 *   - text             （必填；真人客服訊息內容）
 *   - sent_at          （ISO-8601；缺漏 / 不合法時以 now 取代）
 *   - idempotency_key  （去重鍵；缺漏時以穩定欄位推導）
 *
 * Conversation Identity（CA：與 customer event 同一套，不建立第二套）：
 *   ConversationIdentityBuilder::forLine(tenant_sno, line_user_id)
 *     → {sno}:line:{lineUserId}
 *
 * 保守原則（never-break）：
 *   - 缺 tenant_sno / line_user_id / text → 保守略過（回傳空陣列），不拋
 *     production-breaking error。
 *   - 解析失敗 → 略過，不影響任何主流程。
 */
final class BackendHumanConversationChannelParser implements ConversationChannelParserInterface
{
    /** 本 Parser 之事件來源識別（與 conversation identity 的 'line' channel 不同；identity 仍走 LINE）。 */
    public const CHANNEL = 'backend_human';

    public function channel(): string
    {
        return self::CHANNEL;
    }

    /**
     * 將後台 / CRM 真人客服事件 envelope 轉為 Canonical Descriptor 清單。
     *
     * 接受兩種形狀：
     *   - 單一事件物件（扁平契約欄位）
     *   - { events: [ {事件}, ... ] } 批次包裝
     *
     * @param array<string, mixed> $rawEnvelope 後台 / CRM 原始事件
     * @param array<string, mixed> $context     ['tenant_sno' => ?string, 'trace_id' => ?string]
     * @return list<array<string, mixed>>
     */
    public function parse(array $rawEnvelope, array $context): array
    {
        $contextTenantSno = isset($context['tenant_sno']) ? trim((string) $context['tenant_sno']) : '';
        $traceId = isset($context['trace_id']) ? (string) $context['trace_id'] : '';

        $events = (isset($rawEnvelope['events']) && is_array($rawEnvelope['events']))
            ? $rawEnvelope['events']
            : [$rawEnvelope];

        $descriptors = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $descriptor = $this->parseSingleEvent($event, $contextTenantSno, $traceId);
            if ($descriptor !== null) {
                $descriptors[] = $descriptor;
            }
        }

        return $descriptors;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    private function parseSingleEvent(array $event, string $contextTenantSno, string $traceId): ?array
    {
        // tenant_sno：context 優先（由 ingress 解析 / 驗證），envelope 後備。
        $tenantSno = $contextTenantSno !== ''
            ? $contextTenantSno
            : (isset($event['tenant_sno']) ? trim((string) $event['tenant_sno']) : '');
        if ($tenantSno === '') {
            return null;
        }

        $lineUserId = isset($event['line_user_id']) ? trim((string) $event['line_user_id']) : '';
        if ($lineUserId === '') {
            return null; // 無法建立 conversation identity → 略過
        }

        $text = isset($event['text']) ? trim((string) $event['text']) : '';
        if ($text === '') {
            return null; // 無內容之真人訊息 → 保守略過
        }

        $agentId = isset($event['agent_id']) ? trim((string) $event['agent_id']) : '';
        $occurredAt = $this->resolveOccurredAt($event['sent_at'] ?? null);
        $conversationId = ConversationIdentityBuilder::forLine($tenantSno, $lineUserId);

        $idempotencyKey = isset($event['idempotency_key']) ? trim((string) $event['idempotency_key']) : '';
        if ($idempotencyKey === '') {
            $idempotencyKey = $this->deriveIdempotencyKey($tenantSno, $lineUserId, $occurredAt, $text);
        }

        return [
            'type' => ConversationEventType::HUMAN_AGENT_MESSAGE,
            'conversation_id' => $conversationId,
            'occurred_at' => $occurredAt,
            'payload' => [
                'text' => $text,
                'agent_id' => $agentId,
            ],
            'idempotency_key' => $idempotencyKey,
            'channel' => self::CHANNEL,
            'channel_event_id' => $idempotencyKey,
            'trace_id' => $traceId,
        ];
    }

    /**
     * @param mixed $sentAt ISO-8601 字串（如 2026-06-28T15:00:00+08:00）
     */
    private function resolveOccurredAt($sentAt): string
    {
        $tz = new \DateTimeZone('Asia/Taipei');
        if (is_string($sentAt) && trim($sentAt) !== '') {
            try {
                return (new \DateTimeImmutable(trim($sentAt)))->format(\DateTimeInterface::ATOM);
            } catch (\Exception $e) {
                // fall through to now
            }
        }

        return (new \DateTimeImmutable('now', $tz))->format(\DateTimeInterface::ATOM);
    }

    private function deriveIdempotencyKey(
        string $tenantSno,
        string $lineUserId,
        string $occurredAt,
        string $text
    ): string {
        $digest = substr(sha1($occurredAt . '|' . $text), 0, 16);

        return 'backend_human:' . $tenantSno . ':' . $lineUserId . ':' . $digest;
    }
}
