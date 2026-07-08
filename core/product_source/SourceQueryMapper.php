<?php
declare(strict_types=1);

/**
 * Adapter-layer source query mapping (AIU v2 P1 Patch 2).
 *
 * Maps Runtime Contract fields (destination + optional keyword) to platform wire
 * keyword when the product source has no native destination search field.
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

    public static function isKeywordOnlyPlatform(string $platformId): bool
    {
        $id = strtolower(trim($platformId));

        return $id !== '' && in_array($id, self::KEYWORD_ONLY_PLATFORMS, true);
    }

    /**
     * Build combined wire keyword: destination + optional keyword (space-separated).
     */
    public static function buildSourceKeywordQuery(?string $destination, ?string $keyword): string
    {
        $parts = [];
        $dest = self::trimNonEmpty($destination);
        $kw = self::trimNonEmpty($keyword);

        if ($dest !== null) {
            $parts[] = $dest;
        }

        if ($kw !== null && $kw !== $dest) {
            $parts[] = $kw;
        }

        return implode(' ', $parts);
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

        $query = self::buildSourceKeywordQuery(
            isset($document['destination']) ? (string) $document['destination'] : null,
            isset($document['keyword']) ? (string) $document['keyword'] : null
        );

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

        $built = self::buildSourceKeywordQuery(
            isset($document['destination']) ? (string) $document['destination'] : null,
            isset($document['keyword']) ? (string) $document['keyword'] : null
        );

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

    private static function trimNonEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
