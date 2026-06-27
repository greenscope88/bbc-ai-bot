<?php
declare(strict_types=1);

/**
 * Phase 2-B Step 2-A — Conversation Owner（對話擁有者）.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md §6.1（CA-002）.
 *
 * 任一時間，對話只能有一位 Owner（Conversation Ownership Rule, CA-003）。
 * 只有 Owner 可以正式回覆客戶；另一方僅同步 Memory + State。
 */
final class ConversationOwner
{
    public const AI = 'AI';
    public const HUMAN = 'HUMAN';

    /** @var list<string> */
    private const VALID = [
        self::AI,
        self::HUMAN,
    ];

    public static function isValid(string $owner): bool
    {
        return in_array($owner, self::VALID, true);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::VALID;
    }

    public static function assertValid(string $owner): void
    {
        if (!self::isValid($owner)) {
            throw new \InvalidArgumentException('invalid conversation owner: ' . $owner);
        }
    }
}
