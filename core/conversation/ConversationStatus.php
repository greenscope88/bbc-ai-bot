<?php
declare(strict_types=1);

/**
 * Phase 2-B Step 2-A — Conversation Status（對話狀態）.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md §6.2（CA-002）.
 *
 * Conversation Status 為狀態機值，與 Conversation Owner 為兩個獨立維度。
 * 廢止 AI_ACTIVE / HUMAN_ACTIVE 作為正式 State（§6.3）。
 */
final class ConversationStatus
{
    public const ACTIVE = 'ACTIVE';
    public const WAITING_CUSTOMER = 'WAITING_CUSTOMER';
    public const WAITING_HUMAN = 'WAITING_HUMAN';
    public const COMPLETED = 'COMPLETED';
    public const CLOSED = 'CLOSED';

    /** @var list<string> */
    private const VALID = [
        self::ACTIVE,
        self::WAITING_CUSTOMER,
        self::WAITING_HUMAN,
        self::COMPLETED,
        self::CLOSED,
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::VALID, true);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::VALID;
    }

    public static function assertValid(string $status): void
    {
        if (!self::isValid($status)) {
            throw new \InvalidArgumentException('invalid conversation status: ' . $status);
        }
    }

    /**
     * 終態：COMPLETED / CLOSED 視為對話生命週期收尾（Conversation Policy Layer §12）。
     */
    public static function isTerminal(string $status): bool
    {
        return $status === self::COMPLETED || $status === self::CLOSED;
    }
}
