<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationStatusResolver.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TravelIntentLexicon.php';

/**
 * Phase 0703 LINE OA — merge multi-turn clarification follow-ups.
 *
 * When the customer answers a date clarification with a date-only message
 * (e.g. prior "大阪" → follow-up "八月"), merge into a single routing query.
 */
final class ConversationQueryMerger
{
    public static function mergeDateClarificationFollowUp(
        ConversationStatusResolver $resolver,
        string $conversationId,
        string $currentQuery
    ): string {
        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        $currentQuery = trim($currentQuery);
        if ($currentQuery === '') {
            return $currentQuery;
        }

        if (!TravelIntentLexicon::hasDateSignal($currentQuery)) {
            return $currentQuery;
        }

        if (TravelIntentLexicon::findLongestDestination($currentQuery) !== null) {
            return $currentQuery;
        }

        $priorCustomerMessage = self::findLastCustomerMessage($resolver, $conversationId);
        if ($priorCustomerMessage === '') {
            return $currentQuery;
        }

        if (TravelIntentLexicon::findLongestDestination($priorCustomerMessage) === null) {
            return $currentQuery;
        }

        if (TravelIntentLexicon::hasDateSignal($priorCustomerMessage)) {
            return $currentQuery;
        }

        return trim($priorCustomerMessage . ' ' . $currentQuery);
    }

    private static function findLastCustomerMessage(
        ConversationStatusResolver $resolver,
        string $conversationId
    ): string {
        $recent = $resolver->getRecentMessages($conversationId, 20);
        if ($recent === []) {
            return '';
        }

        for ($i = count($recent) - 1; $i >= 0; --$i) {
            $row = $recent[$i];
            if (!is_array($row)) {
                continue;
            }
            if (($row['role'] ?? '') !== 'customer') {
                continue;
            }

            return trim((string) ($row['message'] ?? ''));
        }

        return '';
    }
}
