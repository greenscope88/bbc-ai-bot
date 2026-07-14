<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationStatusResolver.php';

/**
 * Phase 0703 LINE OA — clarification follow-up query passthrough.
 *
 * B0: Non-Gemini utterance merge removed. Multi-turn semantic merge is owned by
 * Gemini AIU + conversation memory, not lexicon/rules in Runtime.
 */
final class ConversationQueryMerger
{
    public static function mergeDateClarificationFollowUp(
        ConversationStatusResolver $resolver,
        string $conversationId,
        string $currentQuery
    ): string {
        unset($resolver, $conversationId);

        return trim($currentQuery);
    }
}
