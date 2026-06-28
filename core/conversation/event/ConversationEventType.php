<?php
declare(strict_types=1);

/**
 * Phase 2-B Step 4-D-1 — Conversation Event Type（Domain Event 型別）.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md（Conversation Runtime Pipeline /
 *       Event Source）；Step 4-D Human Service Runtime Architecture Review。
 *
 * 定義 Conversation Runtime 可接受之 Domain Event 型別。Channel 無關
 * （LINE / CRM / Web Chat / Telegram / Facebook / APP / Email 皆轉譯為這些型別）。
 *
 * 本回合僅啟用三個型別（Active）；其餘為未來保留（Reserved），本回合不實作對應
 * Event 類別，亦不納入 VALID（不可被建立 / 正規化），以免擴大 Scope。
 */
final class ConversationEventType
{
    // --- Active（本回合啟用） ---
    public const CUSTOMER_MESSAGE = 'customer_message';
    public const HUMAN_AGENT_MESSAGE = 'human_agent_message';
    public const SYSTEM = 'system';

    // --- Reserved（未來；本回合不實作） ---
    public const RESERVED_CONVERSATION_TRANSFER = 'conversation_transfer';
    public const RESERVED_CONVERSATION_CLOSE = 'conversation_close';
    public const RESERVED_CONVERSATION_ARCHIVE = 'conversation_archive';
    public const RESERVED_CONVERSATION_RESUME = 'conversation_resume';
    public const RESERVED_CONVERSATION_PAUSE = 'conversation_pause';

    /** @var list<string> 目前可被建立 / 正規化之型別。 */
    private const VALID = [
        self::CUSTOMER_MESSAGE,
        self::HUMAN_AGENT_MESSAGE,
        self::SYSTEM,
    ];

    /** @var list<string> 已保留但尚未實作之型別（拒絕建立，但可被辨識為「未來」）。 */
    private const RESERVED = [
        self::RESERVED_CONVERSATION_TRANSFER,
        self::RESERVED_CONVERSATION_CLOSE,
        self::RESERVED_CONVERSATION_ARCHIVE,
        self::RESERVED_CONVERSATION_RESUME,
        self::RESERVED_CONVERSATION_PAUSE,
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::VALID, true);
    }

    public static function isReserved(string $type): bool
    {
        return in_array($type, self::RESERVED, true);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::VALID;
    }

    /**
     * @return list<string>
     */
    public static function reserved(): array
    {
        return self::RESERVED;
    }

    public static function assertValid(string $type): void
    {
        if (self::isReserved($type)) {
            throw new \InvalidArgumentException(
                'conversation event type is reserved for a future step and not yet implemented: ' . $type
            );
        }
        if (!self::isValid($type)) {
            throw new \InvalidArgumentException('invalid conversation event type: ' . $type);
        }
    }
}
