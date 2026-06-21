<?php
declare(strict_types=1);

/**
 * Phase 9-C-2B-4 — Rule-based special_prices matcher (MVP).
 *
 * Matches user questions to item_name via Chinese keyword overlap.
 * Semantic matching deferred to 9-C-2C.
 */
final class SpecialPriceMatcher
{
    /** @var list<string> */
    private const PRICE_INTENT_KEYWORDS = [
        '多少錢',
        '多少',
        '費用',
        '價格',
        '價錢',
        '要多少',
        '收多少',
        '報價',
        '怎麼收',
        '幾元',
        '元',
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

    /**
     * @param list<array<string, mixed>> $items
     * @return array{price_id: string, item_name: string, price_amount: int|float, grounded_fact: string}|null
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

        $hasPriceIntent = $this->hasPriceIntent($message);
        $bestItem = null;
        $bestScore = 0;
        $bestSortOrder = PHP_INT_MAX;

        foreach ($items as $item) {
            if (!is_array($item) || !$this->isEnabled($item)) {
                continue;
            }

            $score = $this->scoreItem($message, $item, $hasPriceIntent);
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

        $itemName = trim((string) ($bestItem['item_name'] ?? ''));
        $priceAmount = $bestItem['price_amount'] ?? null;
        if ($itemName === '' || !$this->hasValidPriceAmount($priceAmount)) {
            return null;
        }

        $priceText = $this->formatPriceAmount($priceAmount);

        return [
            'price_id' => trim((string) ($bestItem['price_id'] ?? '')),
            'item_name' => $itemName,
            'price_amount' => is_int($priceAmount) || is_float($priceAmount) ? $priceAmount : (float) $priceAmount,
            'grounded_fact' => $itemName . '：' . $priceText . '元',
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function scoreItem(string $message, array $item, bool $hasPriceIntent): int
    {
        $itemName = $this->normalize((string) ($item['item_name'] ?? ''));
        if ($itemName === '') {
            return 0;
        }

        if (!$this->isRegionCompatible($message, $itemName)) {
            return 0;
        }

        $bestKeyScore = 0;
        foreach ($this->extractItemKeys($itemName) as $key) {
            if ($key === '' || mb_strlen($key, 'UTF-8') < 2) {
                continue;
            }
            if (mb_strpos($message, $key, 0, 'UTF-8') === false) {
                continue;
            }

            $keyScore = mb_strlen($key, 'UTF-8') * 2;
            if ($key === $itemName) {
                $keyScore += 2;
            }
            if ($keyScore > $bestKeyScore) {
                $bestKeyScore = $keyScore;
            }
        }

        if ($bestKeyScore === 0) {
            return 0;
        }

        if (!$hasPriceIntent) {
            return 0;
        }

        return $bestKeyScore;
    }

    /**
     * @return list<string>
     */
    private function extractItemKeys(string $itemName): array
    {
        $itemName = $this->normalize($itemName);
        if ($itemName === '') {
            return [];
        }

        $keys = [$itemName];

        foreach (['代辦', '簽證', '證'] as $suffix) {
            $suffixPos = mb_strpos($itemName, $suffix, 0, 'UTF-8');
            if ($suffixPos === false) {
                continue;
            }

            $prefix = mb_substr($itemName, 0, $suffixPos, 'UTF-8');
            if (mb_strlen($prefix, 'UTF-8') >= 2) {
                $keys[] = $prefix;
            }
            if ($suffix === '代辦' || $suffix === '簽證') {
                $keys[] = $prefix . $suffix;
            }
        }

        $unique = [];
        foreach ($keys as $key) {
            $key = trim($key);
            if ($key === '' || mb_strlen($key, 'UTF-8') < 2) {
                continue;
            }
            $unique[$key] = true;
        }

        $sorted = array_keys($unique);
        usort($sorted, static function (string $a, string $b): int {
            return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
        });

        return $sorted;
    }

    private function isRegionCompatible(string $message, string $itemName): bool
    {
        $messageRegions = $this->extractRegionTokens($message);
        if ($messageRegions === []) {
            return true;
        }

        $itemRegions = $this->extractRegionTokens($itemName);
        foreach ($messageRegions as $region) {
            if (!in_array($region, $itemRegions, true)) {
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

    private function hasPriceIntent(string $message): bool
    {
        foreach (self::PRICE_INTENT_KEYWORDS as $keyword) {
            if (mb_strpos($message, $this->normalize($keyword), 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $priceAmount
     */
    private function hasValidPriceAmount($priceAmount): bool
    {
        if (!is_int($priceAmount) && !is_float($priceAmount)) {
            if (!is_string($priceAmount) || !is_numeric($priceAmount)) {
                return false;
            }
        }

        return (float) $priceAmount >= 0;
    }

    /**
     * @param int|float|string $priceAmount
     */
    private function formatPriceAmount($priceAmount): string
    {
        $numeric = (float) $priceAmount;
        if (abs($numeric - (int) $numeric) < 0.00001) {
            return (string) (int) $numeric;
        }

        return rtrim(rtrim(number_format($numeric, 2, '.', ''), '0'), '.');
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
