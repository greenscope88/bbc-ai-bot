<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationEventType.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationEventInterface.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'CustomerMessageEvent.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'HumanAgentMessageEvent.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SystemEvent.php';

/**
 * Phase 2-B Step 4-D-1 — Conversation Event Normalizer.
 *
 * SSOT: Step 4-D Architecture Review（Adapter Before Integration / ABI）。
 *
 * 唯一職責：將「已正規化之 Channel 無關描述符（canonical descriptor）」
 * 轉成 Conversation Domain Event 物件。
 *
 * 嚴格邊界（本回合 Scope）：
 *   - **不解析** LINE / CRM / Webhook / 任何外部格式。
 *   - 外部 Channel Parser 為未來 Step（4-D-2+）之責任；本 Normalizer 只接受
 *     已符合契約的 canonical descriptor。
 *   - 不呼叫任何 Runtime、不修改 Owner / Memory / State。
 *
 * Canonical descriptor 契約：
 *   [
 *     'type'            => string   // ConversationEventType::*
 *     'conversation_id' => string
 *     'occurred_at'     => ?string  // ISO-8601；省略則使用 now
 *     'payload'         => array    // Channel 無關事件內容
 *   ]
 */
final class ConversationEventNormalizer
{
    /**
     * @param array<string, mixed> $descriptor
     */
    public function normalize(array $descriptor): ConversationEventInterface
    {
        $type = isset($descriptor['type']) ? trim((string) $descriptor['type']) : '';
        ConversationEventType::assertValid($type);

        $conversationId = isset($descriptor['conversation_id'])
            ? trim((string) $descriptor['conversation_id'])
            : '';
        if ($conversationId === '') {
            throw new \InvalidArgumentException('conversation event descriptor requires conversation_id');
        }

        $occurredAt = $this->resolveOccurredAt($descriptor['occurred_at'] ?? null);
        $payload = isset($descriptor['payload']) && is_array($descriptor['payload'])
            ? $descriptor['payload']
            : [];

        return $this->buildEvent($type, $conversationId, $occurredAt, $payload);
    }

    /**
     * @param list<array<string, mixed>> $descriptors
     * @return list<ConversationEventInterface>
     */
    public function normalizeMany(array $descriptors): array
    {
        $events = [];
        foreach ($descriptors as $descriptor) {
            if (!is_array($descriptor)) {
                throw new \InvalidArgumentException('each descriptor must be an array');
            }
            $events[] = $this->normalize($descriptor);
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildEvent(
        string $type,
        string $conversationId,
        \DateTimeImmutable $occurredAt,
        array $payload
    ): ConversationEventInterface {
        switch ($type) {
            case ConversationEventType::CUSTOMER_MESSAGE:
                return new CustomerMessageEvent($conversationId, $occurredAt, $payload);
            case ConversationEventType::HUMAN_AGENT_MESSAGE:
                return new HumanAgentMessageEvent($conversationId, $occurredAt, $payload);
            case ConversationEventType::SYSTEM:
                return new SystemEvent($conversationId, $occurredAt, $payload);
            default:
                throw new \InvalidArgumentException('unsupported conversation event type: ' . $type);
        }
    }

    /**
     * @param mixed $raw
     */
    private function resolveOccurredAt($raw): \DateTimeImmutable
    {
        if ($raw instanceof \DateTimeImmutable) {
            return $raw;
        }

        if (is_string($raw) && trim($raw) !== '') {
            try {
                return new \DateTimeImmutable(trim($raw));
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('invalid occurred_at: ' . $raw);
            }
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('Asia/Taipei'));
    }
}
