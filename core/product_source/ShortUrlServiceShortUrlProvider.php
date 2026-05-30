<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ShortUrlProviderInterface.php';

/**
 * Delegates to core/short_url_service.php without modifying ShortUrlService (Phase 9-B-3).
 *
 * Not used in unit tests (MockShortUrlProvider only) to avoid writing bs_ShortUrl.
 */
final class ShortUrlServiceShortUrlProvider implements ShortUrlProviderInterface
{
    /** @var object|null ShortUrlService instance when available */
    private $service;

    public function __construct(?object $service = null)
    {
        if ($service !== null) {
            $this->service = $service;

            return;
        }

        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'short_url_service.php';
        if (is_file($path)) {
            require_once $path;
            if (class_exists('ShortUrlService', false)) {
                $this->service = new ShortUrlService(true, null, true, false);
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function shortenSearchUrl(string $longUrl, array $context): string
    {
        $service = $this->serviceForContext($context, true, false);
        if ($service === null) {
            return trim($longUrl);
        }

        return $service->toPublicShortUrl($longUrl);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function shortenDetailUrl(string $longUrl, array $context): string
    {
        $service = $this->serviceForContext($context, true, true);
        if ($service === null) {
            return trim($longUrl);
        }

        return $service->toPublicShortUrlForItemLink($longUrl);
    }

    /**
     * @param array<string, mixed> $context
     * @return object|null object with toPublicShortUrl / toPublicShortUrlForItemLink
     */
    private function serviceForContext(array $context, bool $searchEnabled, bool $itemEnabled): ?object
    {
        if ($this->service === null) {
            return null;
        }

        $domain = isset($context['short_url_domain']) ? trim((string) $context['short_url_domain']) : '';
        $publicBase = $domain !== '' ? 'https://' . rtrim($domain, '/') . '/' : null;

        return new ShortUrlService($searchEnabled, $publicBase, $itemEnabled, false);
    }
}
