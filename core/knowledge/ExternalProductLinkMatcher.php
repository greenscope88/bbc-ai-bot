<?php
declare(strict_types=1);

/**
 * Phase 9-C-2B-6 — Rule-based external_product_links matcher (MVP).
 *
 * Matches list or single product link queries against stored name/url pairs.
 * Semantic matching deferred to 9-C-2C.
 */
final class ExternalProductLinkMatcher
{
    /** @var list<string> */
    private const LIST_QUERY_KEYWORDS = [
        '哪些商品入口',
        '有哪些商品連結',
        '有哪些旅遊商品',
        '有哪些旅遊分類',
        '哪些旅遊分類',
        '提供哪些商品',
        '有哪些商品',
    ];

    /** @var list<string> */
    private const LINK_INTENT_KEYWORDS = [
        '連結',
        '链接',
        '入口',
        '網址',
        '网址',
        'url',
        '商品',
        '行程',
    ];

    /** @var list<string> */
    private const AVAILABILITY_KEYWORDS = [
        '有沒有',
        '有没有',
        '請問有',
        '有提供',
        '是否能',
        '能不能',
        '可否',
        '能否',
    ];

    /** @var list<string> */
    private const DESTINATION_TOKENS = [
        '北海道',
        '東京',
        '大阪',
        '京都',
        '沖繩',
        '冲绳',
        '九州',
        '首爾',
        '首尔',
        '韓國',
        '韩国',
        '泰國',
        '泰国',
        '新加坡',
        '美國',
        '美国',
        '歐洲',
        '欧洲',
        '郵輪',
        '邮轮',
        '自由行',
        '國外',
        '国外',
    ];

    private const MIN_SCORE = 4;

    /**
     * @param list<array<string, mixed>> $items
     * @return array{
     *   match_type: string,
     *   links: list<array{name: string, url: string, link_id?: string}>,
     *   link_id?: string,
     *   grounded_fact: string
     * }|null
     */
    public function match(string $message, array $items): ?array
    {
        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        $message = $this->normalize($message);
        $enabledItems = $this->filterEnabledItems($items);
        if ($message === '' || $enabledItems === []) {
            return null;
        }

        if ($this->isListQuery($message)) {
            return $this->buildListMatch($enabledItems);
        }

        $bestItem = null;
        $bestScore = 0;
        $bestSortOrder = PHP_INT_MAX;

        foreach ($enabledItems as $item) {
            $score = $this->scoreItem($message, $item);
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

        if (!$this->allowsSingleMatch($message, $bestItem, $bestScore)) {
            return null;
        }

        return $this->buildSingleMatch($bestItem);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function filterEnabledItems(array $items): array
    {
        $enabledItems = [];
        foreach ($items as $item) {
            if (!is_array($item) || !$this->isEnabled($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));
            if ($name === '' || $url === '') {
                continue;
            }

            $enabledItems[] = $item;
        }

        usort($enabledItems, static function (array $a, array $b): int {
            $sortA = isset($a['sort_order']) ? (int) $a['sort_order'] : PHP_INT_MAX;
            $sortB = isset($b['sort_order']) ? (int) $b['sort_order'] : PHP_INT_MAX;
            if ($sortA === $sortB) {
                return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            }

            return $sortA <=> $sortB;
        });

        return $enabledItems;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{match_type: string, links: list<array{name: string, url: string, link_id?: string}>, grounded_fact: string}
     */
    private function buildListMatch(array $items): array
    {
        $links = [];
        foreach ($items as $item) {
            $links[] = $this->normalizeLinkItem($item);
        }

        return [
            'match_type' => 'list',
            'links' => $links,
            'grounded_fact' => $this->formatLinksFact($links),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array{match_type: string, links: list<array{name: string, url: string, link_id?: string}>, link_id?: string, grounded_fact: string}
     */
    private function buildSingleMatch(array $item): array
    {
        $link = $this->normalizeLinkItem($item);

        return [
            'match_type' => 'single',
            'links' => [$link],
            'link_id' => $link['link_id'] ?? '',
            'grounded_fact' => $this->formatLinksFact([$link]),
        ];
    }

    /**
     * @param list<array{name: string, url: string, link_id?: string}> $links
     */
    private function formatLinksFact(array $links): string
    {
        return implode("\n", array_map(static function (array $link): string {
            return '• ' . $link['name'] . '：' . $link['url'];
        }, $links));
    }

    /**
     * @param array<string, mixed> $item
     * @return array{name: string, url: string, link_id?: string}
     */
    private function normalizeLinkItem(array $item): array
    {
        $link = [
            'name' => trim((string) ($item['name'] ?? '')),
            'url' => trim((string) ($item['url'] ?? '')),
        ];

        $linkId = trim((string) ($item['link_id'] ?? ''));
        if ($linkId !== '') {
            $link['link_id'] = $linkId;
        }

        return $link;
    }

    private function isListQuery(string $message): bool
    {
        foreach (self::LIST_QUERY_KEYWORDS as $keyword) {
            if (mb_strpos($message, $this->normalize($keyword), 0, 'UTF-8') !== false) {
                return true;
            }
        }

        if (preg_match('/(哪些|什麼|提供).*(商品|旅遊).*(入口|連結|链接)/u', $message) === 1) {
            return true;
        }

        return preg_match('/(哪些|什麼).*(旅遊商品|商品入口|商品連結|商品链接)/u', $message) === 1;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function allowsSingleMatch(string $message, array $item, int $score): bool
    {
        $name = $this->normalize((string) ($item['name'] ?? ''));

        if ($this->hasLinkIntent($message) || $this->hasAvailabilityIntent($message)) {
            return true;
        }

        if ($name !== '' && ($message === $name || mb_strpos($message, $name, 0, 'UTF-8') !== false)) {
            return true;
        }

        return $score >= 6;
    }

    private function hasLinkIntent(string $message): bool
    {
        foreach (self::LINK_INTENT_KEYWORDS as $keyword) {
            if (mb_strpos($message, $this->normalize($keyword), 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private function hasAvailabilityIntent(string $message): bool
    {
        foreach (self::AVAILABILITY_KEYWORDS as $keyword) {
            if (mb_strpos($message, $this->normalize($keyword), 0, 'UTF-8') !== false) {
                return true;
            }
        }

        if (mb_strpos($message, '有', 0, 'UTF-8') !== false
            && (mb_strpos($message, '嗎', 0, 'UTF-8') !== false
                || mb_strpos($message, '吗', 0, 'UTF-8') !== false)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function scoreItem(string $message, array $item): int
    {
        $name = $this->normalize((string) ($item['name'] ?? ''));
        if ($name === '') {
            return 0;
        }

        if (!$this->isDestinationCompatible($message, $name)) {
            return 0;
        }

        $bestKeyScore = 0;
        foreach ($this->extractNameKeys($name) as $key) {
            if (mb_strpos($message, $key, 0, 'UTF-8') === false) {
                continue;
            }

            $keyScore = mb_strlen($key, 'UTF-8') * 2;
            if ($key === $name) {
                $keyScore += 2;
            }
            if ($keyScore > $bestKeyScore) {
                $bestKeyScore = $keyScore;
            }
        }

        return $bestKeyScore;
    }

    /**
     * @return list<string>
     */
    private function extractNameKeys(string $name): array
    {
        $name = $this->normalize($name);
        if ($name === '') {
            return [];
        }

        $keys = [$name];

        foreach (['旅遊', '行程', '商品', '團體', '自由行', '郵輪', '邮轮', '機票', '訂房'] as $token) {
            if (mb_strpos($name, $token, 0, 'UTF-8') !== false && mb_strlen($token, 'UTF-8') >= 2) {
                $keys[] = $token;
            }
        }

        foreach (self::DESTINATION_TOKENS as $token) {
            $normalizedToken = $this->normalize($token);
            if ($normalizedToken !== '' && mb_strpos($name, $normalizedToken, 0, 'UTF-8') !== false) {
                $keys[] = $normalizedToken;
            }
        }

        $unique = [];
        foreach ($keys as $key) {
            if (mb_strlen($key, 'UTF-8') >= 2) {
                $unique[$key] = true;
            }
        }

        $sorted = array_keys($unique);
        usort($sorted, static function (string $a, string $b): int {
            return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
        });

        return $sorted;
    }

    private function isDestinationCompatible(string $message, string $name): bool
    {
        $messageDestinations = $this->extractDestinationTokens($message);
        if ($messageDestinations === []) {
            return true;
        }

        $nameDestinations = $this->extractDestinationTokens($name);
        foreach ($messageDestinations as $destination) {
            if (!in_array($destination, $nameDestinations, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function extractDestinationTokens(string $text): array
    {
        $text = $this->normalize($text);
        $found = [];
        foreach (self::DESTINATION_TOKENS as $token) {
            $normalizedToken = $this->normalize($token);
            if ($normalizedToken !== '' && mb_strpos($text, $normalizedToken, 0, 'UTF-8') !== false) {
                $found[] = $normalizedToken;
            }
        }

        return array_values(array_unique($found));
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
