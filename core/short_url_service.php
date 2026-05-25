<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'logger.php';

/**
 * Wraps legacy www ShortUrl_Add for bbcshops.com public short links (fail-open).
 */
final class ShortUrlService
{
    private const DEFAULT_PUBLIC_BASE = 'https://bbcshops.com/';

    private const LEGACY_DB_CONFIG_PATH = 'C:/Web/xampp/htdocs/www/conf/DBConfig.php';

    private const LEGACY_SHORT_URL_PATH = 'C:/Web/xampp/htdocs/www/obj/ShortUrl.php';

    private ?bool $enabledOverride;

    private ?string $publicBaseOverride;

    private ?bool $itemLinksEnabledOverride;

    public function __construct(
        ?bool $enabledOverride = null,
        ?string $publicBaseOverride = null,
        ?bool $itemLinksEnabledOverride = null
    ) {
        $this->enabledOverride = $enabledOverride;
        $this->publicBaseOverride = $publicBaseOverride;
        $this->itemLinksEnabledOverride = $itemLinksEnabledOverride;
    }

    /**
     * Phase 1A: search_url ("更多行程 & 出團日") — gated by SHORT_URL_ENABLED only.
     */
    public function toPublicShortUrl(string $longUrl): string
    {
        $longUrl = trim($longUrl);
        if ($longUrl === '') {
            return $longUrl;
        }

        if (!$this->isSearchUrlEnabled()) {
            return $longUrl;
        }

        return $this->encodeToPublicShortUrl($longUrl, 'search_url');
    }

    /**
     * Phase 1B-A: per-item "詳細內容" links — gated by SHORT_URL_ITEM_LINKS_ENABLED only.
     */
    public function toPublicShortUrlForItemLink(string $longUrl): string
    {
        $longUrl = trim($longUrl);
        if ($longUrl === '') {
            return $longUrl;
        }

        if (!$this->isItemLinksEnabled()) {
            return $longUrl;
        }

        return $this->encodeToPublicShortUrl($longUrl, 'item_link');
    }

    private function encodeToPublicShortUrl(string $longUrl, string $kind): string
    {
        if (!$this->bootLegacyShortUrlStack()) {
            $this->logOutcome(false, $longUrl, null, 'shorturl_stack_unavailable', null, $kind);

            return $longUrl;
        }

        try {
            $ret = [];
            if (!ShortUrl_Add($longUrl, $ret)) {
                $this->logOutcome(false, $longUrl, null, 'shorturl_add_returned_false', null, $kind);

                return $longUrl;
            }

            $code = isset($ret['code']) ? trim((string) $ret['code']) : '';
            if ($code === '') {
                $this->logOutcome(false, $longUrl, null, 'shorturl_empty_code', null, $kind);

                return $longUrl;
            }

            $short = rtrim($this->resolvePublicBase(), '/') . '/' . $code;
            $this->logOutcome(true, $longUrl, $code, null, null, $kind);

            return $short;
        } catch (\Throwable $e) {
            $this->logOutcome(false, $longUrl, null, 'shorturl_exception', $e->getMessage(), $kind);

            return $longUrl;
        }
    }

    private function isSearchUrlEnabled(): bool
    {
        if ($this->enabledOverride !== null) {
            return $this->enabledOverride;
        }

        $fromEnv = getenv('SHORT_URL_ENABLED');
        if ($fromEnv !== false && $fromEnv !== '') {
            return self::parseTruthy($fromEnv);
        }

        return (bool) app_config_get('short_url.enabled', false);
    }

    private function isItemLinksEnabled(): bool
    {
        if ($this->itemLinksEnabledOverride !== null) {
            return $this->itemLinksEnabledOverride;
        }

        $fromEnv = getenv('SHORT_URL_ITEM_LINKS_ENABLED');
        if ($fromEnv !== false && $fromEnv !== '') {
            return self::parseTruthy($fromEnv);
        }

        return (bool) app_config_get('short_url.item_links_enabled', false);
    }

    private function resolvePublicBase(): string
    {
        if ($this->publicBaseOverride !== null && trim($this->publicBaseOverride) !== '') {
            return rtrim(trim($this->publicBaseOverride), '/') . '/';
        }

        $fromEnv = getenv('SHORT_URL_PUBLIC_BASE');
        if ($fromEnv !== false && trim($fromEnv) !== '') {
            return rtrim(trim($fromEnv), '/') . '/';
        }

        $fromConfig = app_config_get('short_url.public_base', self::DEFAULT_PUBLIC_BASE);
        if (!is_string($fromConfig) || trim($fromConfig) === '') {
            return self::DEFAULT_PUBLIC_BASE;
        }

        return rtrim(trim($fromConfig), '/') . '/';
    }

    /**
     * @param mixed $value
     */
    private static function parseTruthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $v = strtolower(trim($value));

            return $v === '1' || $v === 'true' || $v === 'yes' || $v === 'on';
        }

        return (bool) $value;
    }

    private function bootLegacyShortUrlStack(): bool
    {
        static $booted = false;
        if ($booted) {
            return function_exists('ShortUrl_Add');
        }

        if (!is_file(self::LEGACY_DB_CONFIG_PATH) || !is_file(self::LEGACY_SHORT_URL_PATH)) {
            return false;
        }

        require_once self::LEGACY_DB_CONFIG_PATH;

        // DBConfig.php assigns $pdo in the including scope; promote to global for ShortUrlDB.
        if (isset($pdo) && $pdo instanceof PDO) {
            $GLOBALS['pdo'] = $pdo;
        }

        if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            return false;
        }

        require_once self::LEGACY_SHORT_URL_PATH;
        $booted = function_exists('ShortUrl_Add');

        return $booted;
    }

    private function logOutcome(
        bool $success,
        string $longUrl,
        ?string $code,
        ?string $errorSummary,
        ?string $exceptionMessage = null,
        string $kind = 'search_url'
    ): void {
        $context = [
            'kind' => $kind,
            'success' => $success,
            'long_url_hash' => substr(hash('sha256', $longUrl), 0, 16),
        ];

        if ($code !== null && $code !== '') {
            $context['code'] = $code;
        }

        if ($errorSummary !== null && $errorSummary !== '') {
            $context['error'] = $errorSummary;
        }

        if ($exceptionMessage !== null && $exceptionMessage !== '') {
            $context['exception'] = mb_substr($exceptionMessage, 0, 120, 'UTF-8');
        }

        Logger::log('short_url_service.log', $success ? 'shorturl_ok' : 'shorturl_fail', $context);
    }
}
