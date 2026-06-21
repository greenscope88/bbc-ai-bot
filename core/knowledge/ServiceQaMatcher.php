<?php
declare(strict_types=1);

/**
 * Phase 9-C-2B-3 — Rule-based service_qa matcher (MVP).
 *
 * Uses Chinese topic-group overlap on stored question text.
 * Semantic matching deferred to 9-C-2C.
 */
final class ServiceQaMatcher
{
    /**
     * Synonym groups for Chinese topic overlap (搭機/飛機, 到機場/報到 intent).
     *
     * @var array<string, list<string>>
     */
    private const TOPIC_GROUPS = [
        'travel' => ['搭機', '飛機'],
        'location_intent' => ['機場', '報到'],
        'international' => ['國際線', '國外'],
        'timing' => ['多久', '時間', '起飛'],
        'counter' => ['櫃台'],
    ];

    /** @var list<string> */
    private const INTENT_TOPIC_GROUPS = [
        'location_intent',
        'timing',
        'international',
        'counter',
    ];

    private const MIN_TOPIC_GROUP_OVERLAP = 2;

    private const MIN_SCORE = 4;

    /**
     * @param list<array<string, mixed>> $items
     * @return array{qa_id: string, question: string, grounded_fact: string, category: string}|null
     */
    public function match(string $message, array $items): ?array
    {
        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        $message = $this->normalize($message);
        if ($message === '' || $items === []) {
            return null;
        }

        $messageGroups = $this->extractTopicGroups($message);
        if ($messageGroups === []) {
            return null;
        }

        $bestItem = null;
        $bestScore = 0;
        $bestSortOrder = PHP_INT_MAX;

        foreach ($items as $item) {
            if (!is_array($item) || !$this->isEnabled($item)) {
                continue;
            }

            $score = $this->scoreItem($message, $messageGroups, $item);
            $sortOrder = isset($item['sort_order']) ? (int) $item['sort_order'] : PHP_INT_MAX;
            if ($score > $bestScore || ($score === $bestScore && $sortOrder < $bestSortOrder)) {
                $bestScore = $score;
                $bestSortOrder = $sortOrder;
                $bestItem = $item;
            }
        }

        if ($bestItem === null || $bestScore < self::MIN_SCORE) {
            return null;
        }

        $groundedFact = trim((string) ($bestItem['answer'] ?? ''));
        if ($groundedFact === '') {
            return null;
        }

        return [
            'qa_id' => trim((string) ($bestItem['qa_id'] ?? '')),
            'question' => trim((string) ($bestItem['question'] ?? '')),
            'grounded_fact' => $groundedFact,
            'category' => trim((string) ($bestItem['category'] ?? '')),
        ];
    }

    /**
     * @param list<string> $messageGroups
     * @param array<string, mixed> $item
     */
    private function scoreItem(string $message, array $messageGroups, array $item): int
    {
        $question = $this->normalize((string) ($item['question'] ?? ''));
        if ($question === '') {
            return 0;
        }

        $questionGroups = $this->extractTopicGroups($question);
        if ($questionGroups === []) {
            return 0;
        }

        $overlapGroups = array_values(array_intersect($messageGroups, $questionGroups));
        if (count($overlapGroups) < self::MIN_TOPIC_GROUP_OVERLAP) {
            return 0;
        }

        if (!$this->hasIntentTopicOverlap($overlapGroups)) {
            return 0;
        }

        $score = count($overlapGroups) * 2;

        $tags = trim((string) ($item['tags'] ?? ''));
        if ($tags !== '') {
            foreach (preg_split('/[,，]/u', $tags) ?: [] as $tag) {
                $tag = $this->normalize((string) $tag);
                if (mb_strlen($tag, 'UTF-8') >= 2 && mb_strpos($message, $tag, 0, 'UTF-8') !== false) {
                    ++$score;
                }
            }
        }

        return $score;
    }

    /**
     * @param list<string> $groups
     */
    private function hasIntentTopicOverlap(array $groups): bool
    {
        foreach (self::INTENT_TOPIC_GROUPS as $intentGroup) {
            if (in_array($intentGroup, $groups, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractTopicGroups(string $text): array
    {
        $text = $this->normalize($text);
        if ($text === '') {
            return [];
        }

        $groups = [];
        foreach (self::TOPIC_GROUPS as $groupKey => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_strpos($text, $keyword, 0, 'UTF-8') !== false) {
                    $groups[] = $groupKey;
                    break;
                }
            }
        }

        return $groups;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isEnabled(array $item): bool
    {
        if (!array_key_exists('enabled', $item)) {
            return true;
        }

        return (bool) $item['enabled'];
    }

    private function normalize(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return mb_strtolower($text, 'UTF-8');
    }
}
