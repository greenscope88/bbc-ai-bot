<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'SourcePlatformContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'OtherSourceListingLinksMessageRenderResult.php';

final class OtherSourceListingLinksMessageBuilder
{
    /** @var array<string, true> */
    private const ALLOWED_SOURCE_REFS = [
        'grp:listing' => true,
        'bbctravel:listing' => true,
        'tourcenter:listing' => true,
    ];

    /** @var list<string> */
    private const ORDINAL_LABELS = ['一館', '二館', '三館'];

    /**
     * @param list<array<string, mixed>> $linkFacts
     */
    public function build(array $linkFacts, ?string $primarySearchDisplayLabel = null): OtherSourceListingLinksMessageRenderResult
    {
        $blocks = [];
        $ids = [];
        $ordinal = 0;
        foreach ($linkFacts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $sourceRef = trim((string) ($fact['source_ref'] ?? ''));
            if (!isset(self::ALLOWED_SOURCE_REFS[$sourceRef])) {
                continue;
            }
            if ($ordinal >= count(self::ORDINAL_LABELS)) {
                break;
            }
            $factId = trim((string) ($fact['fact_id'] ?? ''));
            if ($factId === '') {
                continue;
            }
            $url = $fact['value'] ?? '';
            if (!is_string($url) || !$this->isValidCustomerListingUrl($url)) {
                continue;
            }
            $url = trim($url);
            $platform = substr($sourceRef, 0, strpos($sourceRef, ':'));
            $platformDoc = SourcePlatformContract::getKnownPlatform($platform);
            if ($platformDoc === null) {
                continue;
            }
            $displayLabel = self::ORDINAL_LABELS[$ordinal];
            ++$ordinal;
            $blocks[] = '✅ ' . $displayLabel . "：\n" . $url;
            $ids[] = $factId;
        }

        if ($blocks === []) {
            throw new \RuntimeException('other_source_links_empty');
        }

        $label = $primarySearchDisplayLabel !== null ? trim($primarySearchDisplayLabel) : '';
        $header = $label !== ''
            ? '其他【' . $label . '】相關行程可參考：'
            : '其他相關行程可參考：';

        return new OtherSourceListingLinksMessageRenderResult([
            'type' => 'text',
            'text' => $header . "\n\n" . implode("\n", $blocks),
        ], $ids);
    }

    /**
     * Local format validation only — no network I/O.
     */
    private function isValidCustomerListingUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        if (preg_match('#^https://#i', $url) !== 1) {
            return false;
        }
        if (preg_match('#^https://[^/]*@#i', $url) === 1) {
            return false;
        }
        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return false;
        }
        $host = $parsed['host'] ?? '';
        if (!is_string($host) || $host === '') {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
