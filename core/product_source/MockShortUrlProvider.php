<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ShortUrlProviderInterface.php';

/**
 * Deterministic mock short URLs — no DB, no ShortUrl_Add (Phase 9-B-3 tests).
 */
final class MockShortUrlProvider implements ShortUrlProviderInterface
{
    public function shortenSearchUrl(string $longUrl, array $context): string
    {
        return $this->encode($longUrl, $context, 'search');
    }

    public function shortenDetailUrl(string $longUrl, array $context): string
    {
        return $this->encode($longUrl, $context, 'detail');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function encode(string $longUrl, array $context, string $kind): string
    {
        $longUrl = trim($longUrl);
        if ($longUrl === '') {
            return $longUrl;
        }

        $domain = isset($context['short_url_domain']) ? trim((string) $context['short_url_domain']) : 'bbcshops.com';
        if ($domain === '') {
            $domain = 'bbcshops.com';
        }

        $namespace = isset($context['domain_namespace']) ? trim((string) $context['domain_namespace']) : 'bbcshops';
        $sourceId = isset($context['source_id']) ? trim((string) $context['source_id']) : '';

        $seed = $namespace . '|' . $kind . '|' . $sourceId . '|' . $longUrl;
        $code = substr(hash('sha256', $seed), 0, 5);

        return 'https://' . $domain . '/' . $code;
    }
}
