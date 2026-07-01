<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'GroundedInput.php';

/**
 * Phase 2-E Step 2-E-2d — builds referential allowlists from GroundedInput facts.
 *
 * SSOT: docs/BATS_AI_GROUNDED_RESPONSE_COMPOSER.md §10.2 / §10.4.
 */
final class GroundedFactAllowlistBuilder
{
    /**
     * @return array{
     *   fact_ids: list<string>,
     *   urls: list<string>,
     *   phones: list<string>,
     *   titles: list<string>,
     *   prices: list<string>,
     *   text_fragments: list<string>
     * }
     */
    public static function build(GroundedInput $input): array
    {
        $factIds = [];
        $urls = [];
        $phones = [];
        $titles = [];
        $prices = [];
        $textFragments = [];

        foreach ($input->getGroundedFacts() as $fact) {
            if (!is_array($fact)) {
                continue;
            }

            $factId = $fact['fact_id'] ?? null;
            if ($factId !== null && $factId !== '') {
                $factIds[] = (string) $factId;
            }

            $value = trim((string) ($fact['value'] ?? ''));
            if ($value !== '') {
                $textFragments[] = $value;
                self::collectFromText($value, $urls, $phones, $prices, $titles);
            }

            $title = trim((string) ($fact['title'] ?? ''));
            if ($title !== '') {
                $titles[] = $title;
            }
        }

        foreach ($input->getProductList() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            if ($title !== '') {
                $titles[] = $title;
                $textFragments[] = $title;
            }

            $url = trim((string) ($row['primary_url'] ?? ''));
            if ($url !== '') {
                $urls[] = self::normalizeUrl($url);
            }
        }

        foreach ($input->getExternalLinks() as $link) {
            if (!is_array($link)) {
                continue;
            }

            $url = trim((string) ($link['url'] ?? ''));
            if ($url !== '') {
                $urls[] = self::normalizeUrl($url);
            }

            $title = trim((string) ($link['title'] ?? ''));
            if ($title !== '') {
                $titles[] = $title;
            }
        }

        $summary = $input->getRecommendationSummary();
        if (is_array($summary)) {
            $primaryUrl = trim((string) ($summary['primary_url'] ?? ''));
            if ($primaryUrl !== '') {
                $urls[] = self::normalizeUrl($primaryUrl);
            }

            $topProducts = $summary['top_products'] ?? [];
            if (is_array($topProducts)) {
                foreach ($topProducts as $product) {
                    if (!is_array($product)) {
                        continue;
                    }

                    $title = trim((string) ($product['title'] ?? ''));
                    if ($title !== '') {
                        $titles[] = $title;
                    }

                    $url = trim((string) ($product['primary_url'] ?? ''));
                    if ($url !== '') {
                        $urls[] = self::normalizeUrl($url);
                    }
                }
            }
        }

        return [
            'fact_ids' => array_values(array_unique($factIds)),
            'urls' => array_values(array_unique($urls)),
            'phones' => array_values(array_unique($phones)),
            'titles' => array_values(array_unique(array_filter($titles, static function (string $title): bool {
                return $title !== '';
            }))),
            'prices' => array_values(array_unique($prices)),
            'text_fragments' => array_values(array_unique($textFragments)),
        ];
    }

    /**
     * @param list<string> $urls
     * @param list<string> $phones
     * @param list<string> $prices
     * @param list<string> $titles
     */
    private static function collectFromText(
        string $text,
        array &$urls,
        array &$phones,
        array &$prices,
        array &$titles
    ): void {
        foreach (self::extractUrls($text) as $url) {
            $urls[] = self::normalizeUrl($url);
        }

        foreach (self::extractPhoneDigits($text) as $phone) {
            $phones[] = $phone;
        }

        foreach (self::extractPrices($text) as $price) {
            $prices[] = $price;
        }
    }

    /**
     * @return list<string>
     */
    public static function extractUrls(string $text): array
    {
        if ($text === '') {
            return [];
        }

        if (!preg_match_all('#https?://[^\s\)\]\"\'<>]+#iu', $text, $matches)) {
            return [];
        }

        $urls = [];
        foreach ($matches[0] as $url) {
            $urls[] = rtrim((string) $url, '.,;:)');
        }

        return $urls;
    }

    /**
     * @return list<string>
     */
    public static function extractPhoneDigits(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $phones = [];
        if (preg_match_all('/\d[\d\-\s\(\)]{7,}\d/u', $text, $matches)) {
            foreach ($matches[0] as $candidate) {
                $normalized = preg_replace('/\D+/', '', (string) $candidate);
                if (is_string($normalized) && strlen($normalized) >= 8) {
                    $phones[] = $normalized;
                }
            }
        }

        return array_values(array_unique($phones));
    }

    /**
     * @return list<string>
     */
    public static function extractPrices(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $prices = [];
        $patterns = [
            '/NT\$?\s*[\d,]+(?:\.\d+)?/iu',
            '/[\d,]+(?:\.\d+)?\s*元/u',
            '/\$[\d,]+(?:\.\d+)?/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $price) {
                    $normalized = strtolower(preg_replace('/\s+/', '', (string) $price));
                    if ($normalized !== '') {
                        $prices[] = $normalized;
                    }
                }
            }
        }

        return array_values(array_unique($prices));
    }

    public static function normalizeUrl(string $url): string
    {
        return rtrim(strtolower(trim($url)), '/');
    }

    /**
     * @param list<string> $allowedUrls
     */
    public static function isUrlAllowed(string $url, array $allowedUrls): bool
    {
        $normalized = self::normalizeUrl($url);
        if ($normalized === '') {
            return true;
        }

        foreach ($allowedUrls as $allowed) {
            $allowedNormalized = self::normalizeUrl($allowed);
            if ($allowedNormalized === '') {
                continue;
            }

            if (
                $normalized === $allowedNormalized
                || strpos($normalized, $allowedNormalized) !== false
                || strpos($allowedNormalized, $normalized) !== false
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $allowedPhones
     */
    public static function isPhoneAllowed(string $phoneDigits, array $allowedPhones): bool
    {
        if ($phoneDigits === '') {
            return true;
        }

        foreach ($allowedPhones as $allowed) {
            if ($allowed === '') {
                continue;
            }

            if ($phoneDigits === $allowed || strpos($phoneDigits, $allowed) !== false || strpos($allowed, $phoneDigits) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $allowedPrices
     */
    public static function isPriceAllowed(string $price, array $allowedPrices): bool
    {
        if ($price === '') {
            return true;
        }

        foreach ($allowedPrices as $allowed) {
            if ($allowed !== '' && $price === $allowed) {
                return true;
            }
        }

        return false;
    }
}
