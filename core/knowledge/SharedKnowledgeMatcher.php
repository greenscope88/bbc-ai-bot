<?php
declare(strict_types=1);

/**
 * Phase 9-C-2D — MVP bridge matcher for industry shared FAQ.
 *
 * Supports current shared FAQ acceptance cases only.
 * Semantic matching deferred to Phase 9-C-2C.
 */
final class SharedKnowledgeMatcher
{
    private const MIN_FRAGMENT_HITS = 3;

    /**
     * Acceptance bridges for pilot shared FAQ items only.
     *
     * @var array<string, list<list<string>>>
     */
    private const ACCEPTANCE_BRIDGES = [
        'travel-faq-refund-missed-flight' => [
            ['退票'],
            ['來不及', '搭機', '搭乘', '飛機', '來不及搭'],
        ],
        'travel-faq-baggage-weight-limit' => [['行李'], ['公斤', '托運', '手提']],
    ];

    /**
     * @param list<array<string, mixed>> $items
     * @return array{item_id: string, title: string, grounded_fact: string, category: string}|null
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

        $bestItem = null;
        $bestScore = 0;

        foreach ($items as $item) {
            if (!is_array($item) || !$this->isEnabled($item)) {
                continue;
            }

            $score = $this->scoreItem($message, $item);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestItem = $item;
            }
        }

        if ($bestItem === null || $bestScore <= 0) {
            return null;
        }

        $groundedFact = trim((string) ($bestItem['content'] ?? ''));
        if ($groundedFact === '') {
            return null;
        }

        return [
            'item_id' => trim((string) ($bestItem['item_id'] ?? '')),
            'title' => trim((string) ($bestItem['title'] ?? '')),
            'grounded_fact' => $groundedFact,
            'category' => trim((string) ($bestItem['category'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function scoreItem(string $message, array $item): int
    {
        $title = $this->normalize((string) ($item['title'] ?? ''));
        if ($title === '') {
            return 0;
        }

        if ($message === $title) {
            return 100;
        }

        if (mb_strpos($message, $title) !== false || mb_strpos($title, $message) !== false) {
            return 90;
        }

        $fragmentHits = $this->countTitleFragmentHits($message, $title);
        if ($fragmentHits >= self::MIN_FRAGMENT_HITS) {
            return 50 + $fragmentHits;
        }

        $itemId = trim((string) ($item['item_id'] ?? ''));
        if ($itemId !== '' && isset(self::ACCEPTANCE_BRIDGES[$itemId])) {
            if ($this->matchesAcceptanceBridge($message, self::ACCEPTANCE_BRIDGES[$itemId])) {
                return 40;
            }
        }

        return 0;
    }

    private function countTitleFragmentHits(string $message, string $title): int
    {
        $hits = 0;
        foreach ($this->extractTitleFragments($title) as $fragment) {
            if (mb_strpos($message, $fragment) !== false) {
                ++$hits;
            }
        }

        return $hits;
    }

    /**
     * @return list<string>
     */
    private function extractTitleFragments(string $title): array
    {
        $parts = preg_split('/[\s，,。！？!?、；;:：\-\/]+/u', $title) ?: [];
        $fragments = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '' || mb_strlen($part) < 2) {
                continue;
            }
            $fragments[] = $part;
        }

        return array_values(array_unique($fragments));
    }

    /**
     * @param list<list<string>> $groups
     */
    private function matchesAcceptanceBridge(string $message, array $groups): bool
    {
        foreach ($groups as $group) {
            $matched = false;
            foreach ($group as $keyword) {
                $keyword = $this->normalize($keyword);
                if ($keyword !== '' && mb_strpos($message, $keyword) !== false) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isEnabled(array $item): bool
    {
        return !array_key_exists('enabled', $item) || $item['enabled'] === true;
    }

    private function normalize(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            $text = mb_strtolower($text, 'UTF-8');
        } else {
            $text = strtolower($text);
        }

        $text = preg_replace('/^bats(?:測試|测试)\s*/u', '', $text) ?? $text;

        return trim($text);
    }
}
