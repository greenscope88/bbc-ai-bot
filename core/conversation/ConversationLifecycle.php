<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationStatus.php';

/**
 * Phase 2-B Step 3 — Conversation Lifecycle（對話生命週期）.
 *
 * SSOT: docs/BATS_AI_CONVERSATION_ARCHITECTURE.md §12.1（CA-011）.
 *
 *   New → Active → Resolved → Closing → Completed → Closed
 *
 * Lifecycle 為「對話階段（Conversation Stage）」視角，與 Customer Memory Card
 * 之 Conversation Stage 對齊；Conversation Status（§6）為 State Runtime 的狀態機值。
 * 兩者為不同維度，本檔提供 Lifecycle → Status 之 canonical 對應（不重複定義）。
 *
 * 本檔為純值物件：不持有對話資料、不管理 State、不組句。
 */
final class ConversationLifecycle
{
    public const STAGE_NEW = 'New';
    public const STAGE_ACTIVE = 'Active';
    public const STAGE_RESOLVED = 'Resolved';
    public const STAGE_CLOSING = 'Closing';
    public const STAGE_COMPLETED = 'Completed';
    public const STAGE_CLOSED = 'Closed';

    /** @var list<string> 依 SSOT §12.1 之順序。 */
    private const ORDER = [
        self::STAGE_NEW,
        self::STAGE_ACTIVE,
        self::STAGE_RESOLVED,
        self::STAGE_CLOSING,
        self::STAGE_COMPLETED,
        self::STAGE_CLOSED,
    ];

    public static function isValid(string $stage): bool
    {
        return in_array($stage, self::ORDER, true);
    }

    public static function assertValid(string $stage): void
    {
        if (!self::isValid($stage)) {
            throw new \InvalidArgumentException('invalid conversation lifecycle stage: ' . $stage);
        }
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ORDER;
    }

    /**
     * Lifecycle 階段的順序索引（用於判定前進 / 後退）。
     */
    public static function order(string $stage): int
    {
        self::assertValid($stage);

        return (int) array_search($stage, self::ORDER, true);
    }

    /**
     * Completed / Closed 為終態。
     */
    public static function isTerminal(string $stage): bool
    {
        return $stage === self::STAGE_COMPLETED || $stage === self::STAGE_CLOSED;
    }

    /**
     * 是否為 $to 較 $from 前進（不允許從終態回退）。
     */
    public static function isForward(string $from, string $to): bool
    {
        return self::order($to) > self::order($from);
    }

    /**
     * Lifecycle → canonical Conversation Status（SSOT §12.1 對應表）。
     *
     * | New / Active / Resolved / Closing | ACTIVE   |
     * | Completed                         | COMPLETED|
     * | Closed                            | CLOSED   |
     */
    public static function toCanonicalStatus(string $stage): string
    {
        self::assertValid($stage);

        switch ($stage) {
            case self::STAGE_COMPLETED:
                return ConversationStatus::COMPLETED;
            case self::STAGE_CLOSED:
                return ConversationStatus::CLOSED;
            default:
                return ConversationStatus::ACTIVE;
        }
    }
}
