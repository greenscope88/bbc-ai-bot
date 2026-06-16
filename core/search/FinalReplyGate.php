<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationStatusResolver.php';

/**
 * Phase 9-C-1d-β2 final AI outbound gate (AD-005C / RD-007).
 */
final class FinalReplyGate
{
    public static function maySendAiReply(string $conversationStatus): bool
    {
        return $conversationStatus === ConversationStatusResolver::STATUS_AI_ACTIVE;
    }

    /**
     * @return array{allowed: bool, conversation_status: string, block_reason: string|null}
     */
    public static function evaluate(string $conversationStatus): array
    {
        $allowed = self::maySendAiReply($conversationStatus);

        return [
            'allowed' => $allowed,
            'conversation_status' => $conversationStatus,
            'block_reason' => $allowed ? '' : 'human_active',
        ];
    }
}
