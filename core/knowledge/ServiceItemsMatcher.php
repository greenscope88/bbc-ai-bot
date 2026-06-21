<?php
declare(strict_types=1);

/**
 * Phase 9-C-2B-5 — Rule-based service_items matcher (MVP).
 *
 * Matches list or single service name queries against stored items.
 * Semantic matching deferred to 9-C-2C.
 */
final class ServiceItemsMatcher
{
    /** @var list<string> */
    private const LIST_QUERY_KEYWORDS = [
        '哪些服務',
        '什麼服務',
        '有哪些服務',
        '提供哪些',
        '服務項目',
        '服務內容',
        '提供什麼服務',
        '有什麼服務',
        '有哪些服務項目',
    ];

    /** @var list<string> */
    private const AVAILABILITY_KEYWORDS = [
        '有辦',
        '可以辦',
        '可以代辦',
        '有代辦',
        '有提供',
        '有沒有',
        '有没有',
        '是否能',
        '能不能',
        '可否',
        '能辦',
        '會辦',
        '能否',
    ];

    /**
     * Agency / proxy-service semantic group for category list queries.
     *
     * @var list<string>
     */
    private const AGENCY_PROXY_MARKERS = [
        '代辦',
        '代訂',
        '協助辦理',
        '代為處理',
    ];

    /** @var list<string> */
    private const REGION_TOKENS = [
        '日本',
        '泰國',
        '泰国',
        '韓國',
        '韩国',
        '越南',
        '新加坡',
        '馬來西亞',
        '马来西亚',
        '印尼',
        '印度',
        '菲律賓',
        '菲律宾',
        '美國',
        '美国',
        '歐洲',
        '欧洲',
        '澳洲',
        '澳大利亚',
        '台胞',
    ];

    private const MIN_SCORE = 4;

    private const CATEGORY_AGENCY_PROXY = 'agency_proxy';

    /**
     * @param list<array<string, mixed>> $items
     * @return array{match_type: string, service_names: list<string>, service_id?: string, grounded_fact: string}|null
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
            $categoryFilter = $this->detectCategoryFilter($message);
            $matchedItems = $this->filterItemsByCategory($enabledItems, $categoryFilter);
            if ($matchedItems === []) {
                return null;
            }

            return $this->buildListMatch($matchedItems);
        }

        if (!$this->hasAvailabilityIntent($message)) {
            return null;
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

        $name = trim((string) ($bestItem['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        return [
            'match_type' => 'single',
            'service_names' => [$name],
            'service_id' => trim((string) ($bestItem['service_id'] ?? '')),
            'grounded_fact' => $name,
        ];
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
            if ($name === '') {
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
     * @return array{match_type: string, service_names: list<string>, grounded_fact: string}
     */
    private function buildListMatch(array $items): array
    {
        $names = [];
        foreach ($items as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return [
            'match_type' => 'list',
            'service_names' => $names,
            'grounded_fact' => implode("\n", array_map(static function (string $name): string {
                return '• ' . $name;
            }, $names)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function filterItemsByCategory(array $items, ?string $categoryFilter): array
    {
        if ($categoryFilter === null || $categoryFilter === '') {
            return $items;
        }

        if ($categoryFilter === self::CATEGORY_AGENCY_PROXY) {
            $filtered = [];
            foreach ($items as $item) {
                if ($this->itemMatchesAgencyProxyGroup($item)) {
                    $filtered[] = $item;
                }
            }

            return $filtered;
        }

        $filtered = [];
        foreach ($items as $item) {
            $name = $this->normalize((string) ($item['name'] ?? ''));
            $category = $this->normalize((string) ($item['category'] ?? ''));
            $needle = $this->normalize($categoryFilter);
            if ($needle !== '' && (mb_strpos($name, $needle, 0, 'UTF-8') !== false || mb_strpos($category, $needle, 0, 'UTF-8') !== false)) {
                $filtered[] = $item;
            }
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function itemMatchesAgencyProxyGroup(array $item): bool
    {
        $text = $this->normalize((string) ($item['name'] ?? '')) . ' '
            . $this->normalize((string) ($item['category'] ?? ''));

        foreach (self::AGENCY_PROXY_MARKERS as $marker) {
            if (mb_strpos($text, $this->normalize($marker), 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private function isListQuery(string $message): bool
    {
        foreach (self::LIST_QUERY_KEYWORDS as $keyword) {
            if (mb_strpos($message, $this->normalize($keyword), 0, 'UTF-8') !== false) {
                return true;
            }
        }

        if (preg_match('/(哪些|什麼|提供).*(服務)/u', $message) === 1) {
            return true;
        }

        return mb_strpos($message, '代辦服務', 0, 'UTF-8') !== false
            || mb_strpos($message, '代辦項目', 0, 'UTF-8') !== false;
    }

    private function detectCategoryFilter(string $message): ?string
    {
        if (mb_strpos($message, '代辦', 0, 'UTF-8') === false) {
            return null;
        }

        if (mb_strpos($message, '代辦服務', 0, 'UTF-8') !== false
            || mb_strpos($message, '代辦項目', 0, 'UTF-8') !== false
            || mb_strpos($message, '哪些代辦', 0, 'UTF-8') !== false
            || preg_match('/(哪些|什麼).*(代辦)/u', $message) === 1) {
            return self::CATEGORY_AGENCY_PROXY;
        }

        return null;
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
                || mb_strpos($message, '吗', 0, 'UTF-8') !== false
                || mb_strpos($message, '有没有', 0, 'UTF-8') !== false
                || mb_strpos($message, '有沒有', 0, 'UTF-8') !== false)) {
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

        if (!$this->isRegionCompatible($message, $name)) {
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

        $compositeScore = $this->scoreCompositeOverlap($message, $name);
        if ($compositeScore > $bestKeyScore) {
            $bestKeyScore = $compositeScore;
        }

        return $bestKeyScore;
    }

    private function scoreCompositeOverlap(string $message, string $name): int
    {
        $pairs = [
            ['企業', '旅遊'],
            ['租車', '服務'],
        ];

        foreach ($pairs as $pair) {
            $left = $pair[0];
            $right = $pair[1];
            if (mb_strpos($message, $left, 0, 'UTF-8') !== false
                && mb_strpos($message, $right, 0, 'UTF-8') !== false
                && mb_strpos($name, $left, 0, 'UTF-8') !== false
                && mb_strpos($name, $right, 0, 'UTF-8') !== false) {
                return 6;
            }
        }

        return 0;
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

        foreach (['代辦', '代訂', '服務', '規劃', '簽證', '機票', '旅遊', '訂房', '租車'] as $token) {
            if (mb_strpos($name, $token, 0, 'UTF-8') !== false && mb_strlen($token, 'UTF-8') >= 2) {
                $keys[] = $token;
            }
        }

        foreach (['代辦', '代訂', '服務', '規劃', '簽證', '機票', '旅遊'] as $suffix) {
            $suffixPos = mb_strpos($name, $suffix, 0, 'UTF-8');
            if ($suffixPos === false) {
                continue;
            }

            $prefix = mb_substr($name, 0, $suffixPos, 'UTF-8');
            if (mb_strlen($prefix, 'UTF-8') >= 2) {
                $keys[] = $prefix;
            }
            if (mb_strlen($prefix . $suffix, 'UTF-8') >= 2) {
                $keys[] = $prefix . $suffix;
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

    private function isRegionCompatible(string $message, string $name): bool
    {
        $messageRegions = $this->extractRegionTokens($message);
        if ($messageRegions === []) {
            return true;
        }

        $nameRegions = $this->extractRegionTokens($name);
        foreach ($messageRegions as $region) {
            if (!in_array($region, $nameRegions, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function extractRegionTokens(string $text): array
    {
        $text = $this->normalize($text);
        $found = [];
        foreach (self::REGION_TOKENS as $token) {
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
