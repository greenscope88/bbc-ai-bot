<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'search' . DIRECTORY_SEPARATOR . 'SearchCondition.php';

/**
 * Adapter-layer source query mapping (AIU v2 P1 Patch 2 / Product Type).
 *
 * Maps Runtime Contract fields (destination + optional keyword + optional
 * product_type) to platform wire keyword when the product source has no native
 * destination / product_type search fields.
 *
 * Does not modify SearchCondition, canonicalizer, translator, or normalize.
 */
final class SourceQueryMapper
{
    /** Platforms with a native destination search field (wire may send both). */
    private const PLATFORMS_WITH_DESTINATION_FIELD = [
        'hostb',
        'host_b',
    ];

    /**
     * Platforms with a native product_type search field.
     * Empty today — travel_b keyword-only sources fold product_type into
     * source_keyword_query instead.
     *
     * @var list<string>
     */
    private const PLATFORMS_WITH_PRODUCT_TYPE_FIELD = [];

    /** External / storefront sources that only accept a combined keyword query. */
    private const KEYWORD_ONLY_PLATFORMS = [
        'grp',
        'bbctravel',
        'tourcenter',
        'bonusmee',
        'bbcshops',
    ];

    private function __construct()
    {
    }

    public static function supportsDestinationField(string $platformId): bool
    {
        $id = strtolower(trim($platformId));

        return $id !== '' && in_array($id, self::PLATFORMS_WITH_DESTINATION_FIELD, true);
    }

    public static function supportsProductTypeField(string $platformId): bool
    {
        $id = strtolower(trim($platformId));

        return $id !== '' && in_array($id, self::PLATFORMS_WITH_PRODUCT_TYPE_FIELD, true);
    }

    public static function isKeywordOnlyPlatform(string $platformId): bool
    {
        $id = strtolower(trim($platformId));

        return $id !== '' && in_array($id, self::KEYWORD_ONLY_PLATFORMS, true);
    }

    /**
     * Build combined wire keyword: destination + optional keyword + optional product_type.
     *
     * Duplicate tokens are de-duplicated; empty values are skipped.
     * Does not mutate Runtime Contract fields.
     */
    public static function buildSourceKeywordQuery(
        ?string $destination,
        ?string $keyword,
        ?string $productType = null
    ): string {
        return self::joinUniqueTokens([$destination, $keyword, $productType]);
    }

    /**
     * Entity Token Mapping — adapter composes wire keyword from independent runtime tokens.
     * Does not merge or overwrite Runtime Contract fields.
     */
    public static function buildSourceKeywordQueryFromSearchCondition(SearchCondition $condition): string
    {
        $candidates = [];

        $area = self::trimNonEmpty($condition->getArea());
        if ($area !== null) {
            $candidates[] = $area;
        }

        foreach ($condition->getDestination() as $destToken) {
            $part = self::trimNonEmpty($destToken);
            if ($part !== null) {
                $candidates[] = $part;
            }
        }

        $keyword = self::trimNonEmpty($condition->getKeyword());
        if ($keyword !== null) {
            $candidates[] = $keyword;
        }

        foreach ($condition->getTravelStyle() as $token) {
            $candidates[] = $token;
        }
        foreach ($condition->getMustHave() as $token) {
            $candidates[] = $token;
        }
        foreach ($condition->getSpecialTags() as $token) {
            $candidates[] = $token;
        }

        $productType = self::trimNonEmpty($condition->getProductType());
        if ($productType !== null) {
            $candidates[] = $productType;
        }

        return self::joinUniqueTokens($candidates);
    }

    /**
     * @param array<string, mixed> $document
     */

    /**
     * Host B wire keyword when destination is sent as a separate API param.
     * Excludes destination/area tokens; folds product_type and preferences into keyword.
     */
    public static function buildHostBKeywordFromSearchCondition(SearchCondition $condition): string
    {
        $exclude = [];
        $area = self::trimNonEmpty($condition->getArea());
        if ($area !== null) {
            $exclude[$area] = true;
        }
        foreach ($condition->getDestination() as $destToken) {
            $part = self::trimNonEmpty($destToken);
            if ($part !== null) {
                $exclude[$part] = true;
            }
        }

        $candidates = [];
        $keyword = self::trimNonEmpty($condition->getKeyword());
        if ($keyword !== null) {
            foreach (preg_split('/\s+/u', $keyword) ?: [] as $token) {
                $part = self::trimNonEmpty($token);
                if ($part !== null && !isset($exclude[$part])) {
                    $candidates[] = $part;
                }
            }
        }
        foreach ($condition->getTravelStyle() as $token) {
            $part = self::trimNonEmpty($token);
            if ($part !== null && !isset($exclude[$part])) {
                $candidates[] = $part;
            }
        }
        foreach ($condition->getMustHave() as $token) {
            $part = self::trimNonEmpty($token);
            if ($part !== null && !isset($exclude[$part])) {
                $candidates[] = $part;
            }
        }
        foreach ($condition->getSpecialTags() as $token) {
            $part = self::trimNonEmpty($token);
            if ($part !== null && !isset($exclude[$part])) {
                $candidates[] = $part;
            }
        }
        $productType = self::trimNonEmpty($condition->getProductType());
        if ($productType !== null) {
            $candidates[] = $productType;
        }

        return self::joinUniqueTokens($candidates);
    }
    public static function buildSourceKeywordQueryFromDocument(array $document): string
    {
        $candidates = [];

        $area = isset($document['area']) ? self::trimNonEmpty(is_string($document['area']) ? $document['area'] : null) : null;
        if ($area !== null) {
            $candidates[] = $area;
        }

        if (isset($document['destination']) && is_array($document['destination'])) {
            foreach ($document['destination'] as $token) {
                $part = self::trimNonEmpty(is_string($token) ? $token : null);
                if ($part !== null) {
                    $candidates[] = $part;
                }
            }
        }

        $keyword = isset($document['keyword']) ? self::trimNonEmpty(is_string($document['keyword']) ? $document['keyword'] : null) : null;
        if ($keyword !== null) {
            $candidates[] = $keyword;
        }

        foreach (['travel_style', 'must_have', 'special_tags'] as $listField) {
            if (!isset($document[$listField]) || !is_array($document[$listField])) {
                continue;
            }
            foreach ($document[$listField] as $token) {
                $candidates[] = (string) $token;
            }
        }

        $productType = isset($document['product_type'])
            ? self::trimNonEmpty(is_string($document['product_type']) ? $document['product_type'] : null)
            : null;
        if ($productType !== null) {
            $candidates[] = $productType;
        }

        return self::joinUniqueTokens($candidates);
    }

    /**
     * Enrich a platform-agnostic search document with source_keyword_query when needed.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function enrichSearchDocument(array $document): array
    {
        $out = $document;

        $query = self::buildSourceKeywordQueryFromDocument($document);

        if ($query === '') {
            $query = self::wireFallbackFromDocument($document);
        }

        if ($query !== '') {
            $out['source_keyword_query'] = $query;
        }

        return $out;
    }

    /**
     * Resolve the keyword value sent on platform wire for URL / API adapters.
     *
     * @param array<string, mixed> $document
     */
    public static function resolveWireKeyword(array $document, string $platformId): string
    {
        if (self::supportsDestinationField($platformId)) {
            return isset($document['keyword']) ? trim((string) $document['keyword']) : '';
        }

        if (!self::isKeywordOnlyPlatform($platformId)) {
            return isset($document['keyword']) ? trim((string) $document['keyword']) : '';
        }

        if (isset($document['source_keyword_query'])) {
            $fromDoc = trim((string) $document['source_keyword_query']);
            if ($fromDoc !== '') {
                return $fromDoc;
            }
        }

        $built = self::buildSourceKeywordQueryFromDocument($document);

        if ($built !== '') {
            return $built;
        }

        return self::wireFallbackFromDocument($document);
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function wireFallbackFromDocument(array $document): string
    {
        foreach (['area', 'free_text'] as $field) {
            if (!isset($document[$field])) {
                continue;
            }
            $value = trim((string) $document[$field]);
            if ($value !== '') {
                return $value;
            }
        }

        if (!isset($document['must_have']) || !is_array($document['must_have'])) {
            return '';
        }

        $parts = [];
        foreach ($document['must_have'] as $item) {
            $s = trim((string) $item);
            if ($s !== '') {
                $parts[] = $s;
            }
        }

        return implode(' ', array_values(array_unique($parts)));
    }

    /**
     * @param list<mixed> $candidates
     */
    private static function joinUniqueTokens(array $candidates): string
    {
        $parts = [];
        $seen = [];

        foreach ($candidates as $raw) {
            $part = self::trimNonEmpty(is_string($raw) ? $raw : (is_scalar($raw) ? (string) $raw : null));
            if ($part === null) {
                continue;
            }

            $tokens = preg_split('/\s+/u', $part) ?: [];
            foreach ($tokens as $token) {
                $normalized = self::trimNonEmpty($token);
                if ($normalized === null || isset($seen[$normalized])) {
                    continue;
                }
                $seen[$normalized] = true;
                $parts[] = $normalized;
            }
        }

        return implode(' ', $parts);
    }

    private static function trimNonEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}