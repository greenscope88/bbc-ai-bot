<?php
declare(strict_types=1);

/**
 * Phase 2-B Step 4-D-2-A — Conversation Identity Builder.
 *
 * SSOT: Step 4-D-2 Event Source Integration Review（conversation_id 策略凍結）；
 *       Conversation Runtime Foundation。
 *
 * 唯一職責：集中產生 conversation_id，避免各 Channel Parser 自行散落組合。
 *
 * 正式格式：
 *   tenantSno + ':' + channelType + ':' + conversationIdentifier
 *
 * 範例：
 *   LINE     → {sno}:line:{lineUserId}
 *   Web Chat → {sno}:web_chat:{sessionId}
 *   CRM      → {sno}:crm:{ticketId}
 *
 * 嚴格邊界（本回合 Scope）：
 *   - 純字串建構，不接 Runtime、不寫 State / Memory。
 *   - 與既有 pilot key（{sno}:{channelId}）不同：本 Builder 為 per end-user
 *     identity（CA Human Takeover 正確性所需），屬未來整合採用之新規則，
 *     本回合僅建立 Foundation，不接入 Production。
 */
final class ConversationIdentityBuilder
{
    public const CHANNEL_LINE = 'line';
    public const CHANNEL_WEB_CHAT = 'web_chat';
    public const CHANNEL_CRM = 'crm';

    /** @var list<string> */
    private const KNOWN_CHANNELS = [
        self::CHANNEL_LINE,
        self::CHANNEL_WEB_CHAT,
        self::CHANNEL_CRM,
    ];

    /**
     * 通用建構：tenantSno:channelType:conversationIdentifier。
     */
    public static function build(string $tenantSno, string $channelType, string $conversationIdentifier): string
    {
        $tenantSno = trim($tenantSno);
        $channelType = trim($channelType);
        $conversationIdentifier = trim($conversationIdentifier);

        if ($tenantSno === '') {
            throw new \InvalidArgumentException('conversation identity requires non-empty tenant_sno');
        }
        if ($channelType === '') {
            throw new \InvalidArgumentException('conversation identity requires non-empty channel_type');
        }
        if ($conversationIdentifier === '') {
            throw new \InvalidArgumentException('conversation identity requires non-empty conversation_identifier');
        }

        return $tenantSno . ':' . $channelType . ':' . $conversationIdentifier;
    }

    /**
     * LINE：{sno}:line:{lineUserId}。
     */
    public static function forLine(string $tenantSno, string $lineUserId): string
    {
        return self::build($tenantSno, self::CHANNEL_LINE, $lineUserId);
    }

    /**
     * Web Chat：{sno}:web_chat:{sessionId}。
     */
    public static function forWebChat(string $tenantSno, string $sessionId): string
    {
        return self::build($tenantSno, self::CHANNEL_WEB_CHAT, $sessionId);
    }

    /**
     * CRM：{sno}:crm:{ticketId}。
     */
    public static function forCrm(string $tenantSno, string $ticketId): string
    {
        return self::build($tenantSno, self::CHANNEL_CRM, $ticketId);
    }

    public static function isKnownChannel(string $channelType): bool
    {
        return in_array(trim($channelType), self::KNOWN_CHANNELS, true);
    }

    /**
     * @return list<string>
     */
    public static function knownChannels(): array
    {
        return self::KNOWN_CHANNELS;
    }
}
