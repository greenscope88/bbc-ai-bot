<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedInput.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConversationContextView.php';

/**
 * Phase 2-E Step 2-E-2e — parses conversation_context snapshot (read-only).
 *
 * Does not call Conversation Memory Runtime.
 */
final class ConversationContextReader
{
    public static function fromInput(GroundedInput $input): ConversationContextView
    {
        return self::fromArray($input->getConversationContext());
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function fromArray(array $context): ConversationContextView
    {
        $currentRequirement = self::nullableString($context['current_requirement'] ?? null);
        $conversationStage = self::nullableString($context['conversation_stage'] ?? null);
        $destination = self::nullableString($context['destination'] ?? null);
        $travelDates = self::nullableString($context['travel_dates'] ?? null);
        $partySize = self::nullableString($context['party_size'] ?? null);
        $budget = self::nullableString($context['budget'] ?? null);
        $aiSummary = self::nullableString($context['ai_summary'] ?? null);

        return new ConversationContextView(
            $currentRequirement,
            $conversationStage,
            self::toStringList($context['outstanding_issues'] ?? []),
            self::toStringList($context['recently_recommended_products'] ?? []),
            $destination,
            $travelDates,
            $partySize,
            $budget,
            $aiSummary
        );
    }

    /**
     * @param mixed $value
     */
    private static function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function toStringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                continue;
            }
            $text = trim((string) $item);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }
}
