<?php
declare(strict_types=1);

/**
 * Abstraction over short-URL encoding (Phase 9-B-3). Production may use ShortUrlService via adapter; tests use mock.
 */
interface ShortUrlProviderInterface
{
    /**
     * @param array<string, mixed> $context source_id, short_url_domain, domain_namespace, product_category
     */
    public function shortenSearchUrl(string $longUrl, array $context): string;

    /**
     * @param array<string, mixed> $context same as shortenSearchUrl
     */
    public function shortenDetailUrl(string $longUrl, array $context): string;
}
