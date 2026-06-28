<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationChannelParserInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationEventType.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationIdentityBuilder.php';

/**
 * Phase 2-B Step 4-D-2-A — LINE Conversation Channel Parser（Foundation）.
 *
 * SSOT: Step 4-D-2 Event Source Integration Review。
 *
 * 唯一職責：將 LINE webhook envelope（{ destination, events: [...] }）轉成
 * Canonical Descriptor 清單。本回合為 fixture-level Foundation，不接 webhook、
 * 不接 SaaSRouter、不接 Runtime。
 *
 * 保守原則（CEP / Owner First）：
 *   - 僅 type=message 且 message.type=text 之事件 → customer_message descriptor。
 *   - **mode=standby**：LINE 在真人接手期間仍會把「客戶訊息」以一般 message
 *     event 投遞；真人客服訊息**不會**以標準 webhook message event 投遞。因此
 *     standby 下的 message 仍保守歸類為 customer_message（payload 附 line_mode
 *     供未來判斷），**絕不**臆測為 human_agent_message。
 *   - 缺 userId（無法建立身分）、非文字、非 message 事件 → 保守略過（不產出）。
 *   - 任一筆解析失敗 → 略過該筆，不拋 production-breaking error。
 */
final class LineConversationChannelParser implements ConversationChannelParserInterface
{
    public const MODE_ACTIVE = 'active';
    public const MODE_STANDBY = 'standby';

    public function channel(): string
    {
        return ConversationIdentityBuilder::CHANNEL_LINE;
    }

    /**
     * @param array<string, mixed> $rawEnvelope LINE webhook body
     * @param array<string, mixed> $context     ['tenant_sno' => string, 'trace_id' => ?string]
     * @return list<array<string, mixed>>
     */
    public function parse(array $rawEnvelope, array $context): array
    {
        $tenantSno = isset($context['tenant_sno']) ? trim((string) $context['tenant_sno']) : '';
        $traceId = isset($context['trace_id']) ? (string) $context['trace_id'] : '';
        $destination = isset($rawEnvelope['destination']) ? trim((string) $rawEnvelope['destination']) : '';

        if ($tenantSno === '') {
            return [];
        }

        $events = isset($rawEnvelope['events']) && is_array($rawEnvelope['events'])
            ? $rawEnvelope['events']
            : [];

        $descriptors = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $descriptor = $this->parseSingleEvent($event, $tenantSno, $destination, $traceId);
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
    private function parseSingleEvent(
        array $event,
        string $tenantSno,
        string $destination,
        string $traceId
    ): ?array {
        $eventType = isset($event['type']) ? trim((string) $event['type']) : '';
        if ($eventType !== 'message') {
            return null; // 保守：follow/join/postback/... 本回合不產出
        }

        $message = (isset($event['message']) && is_array($event['message'])) ? $event['message'] : [];
        $messageType = isset($message['type']) ? trim((string) $message['type']) : '';
        if ($messageType !== 'text') {
            return null; // 保守：image/sticker/... 本回合不產出
        }

        $source = (isset($event['source']) && is_array($event['source'])) ? $event['source'] : [];
        $lineUserId = isset($source['userId']) ? trim((string) $source['userId']) : '';
        if ($lineUserId === '') {
            return null; // 無法建立 per-user 身分 → 略過
        }

        $text = isset($message['text']) ? trim((string) $message['text']) : '';

        $mode = isset($event['mode']) ? trim((string) $event['mode']) : self::MODE_ACTIVE;
        $occurredAt = $this->resolveOccurredAt($event['timestamp'] ?? null);
        $conversationId = ConversationIdentityBuilder::forLine($tenantSno, $lineUserId);

        $channelEventId = isset($event['webhookEventId']) ? trim((string) $event['webhookEventId']) : '';
        $messageId = isset($message['id']) ? trim((string) $message['id']) : '';
        $idempotencyKey = $this->buildIdempotencyKey($destination, $channelEventId, $messageId, $lineUserId, $occurredAt);

        // 保守歸類：standby 仍為 customer_message（LINE 不投遞真人客服訊息事件）。
        return [
            'type' => ConversationEventType::CUSTOMER_MESSAGE,
            'conversation_id' => $conversationId,
            'occurred_at' => $occurredAt,
            'payload' => [
                'text' => $text,
                'line_mode' => $mode,
                'line_user_id' => $lineUserId,
            ],
            'idempotency_key' => $idempotencyKey,
            'channel' => ConversationIdentityBuilder::CHANNEL_LINE,
            'channel_event_id' => $channelEventId !== '' ? $channelEventId : $messageId,
            'trace_id' => $traceId,
        ];
    }

    /**
     * @param mixed $timestampMs LINE event.timestamp（毫秒）
     */
    private function resolveOccurredAt($timestampMs): string
    {
        $tz = new \DateTimeZone('Asia/Taipei');
        if (is_int($timestampMs) || (is_string($timestampMs) && ctype_digit((string) $timestampMs))) {
            $seconds = (int) ((int) $timestampMs / 1000);
            if ($seconds > 0) {
                return (new \DateTimeImmutable('@' . $seconds))->setTimezone($tz)
                    ->format(\DateTimeInterface::ATOM);
            }
        }

        return (new \DateTimeImmutable('now', $tz))->format(\DateTimeInterface::ATOM);
    }

    private function buildIdempotencyKey(
        string $destination,
        string $channelEventId,
        string $messageId,
        string $lineUserId,
        string $occurredAt
    ): string {
        $suffix = $channelEventId !== ''
            ? $channelEventId
            : ($messageId !== '' ? $messageId : ($lineUserId . '@' . $occurredAt));

        return 'line:' . ($destination !== '' ? $destination : 'unknown') . ':' . $suffix;
    }
}
