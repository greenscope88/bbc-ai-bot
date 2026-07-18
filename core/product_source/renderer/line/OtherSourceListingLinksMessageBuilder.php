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

    /**
     * @param list<array<string, mixed>> $linkFacts
     */
    public function build(array $linkFacts): OtherSourceListingLinksMessageRenderResult
    {
        $lines = [];
        $ids = [];
        foreach ($linkFacts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $sourceRef = trim((string) ($fact['source_ref'] ?? ''));
            if (!isset(self::ALLOWED_SOURCE_REFS[$sourceRef])) {
                continue;
            }
            $url = trim((string) ($fact['value'] ?? ''));
            $factId = trim((string) ($fact['fact_id'] ?? ''));
            if ($url === '' || $factId === '') {
                throw new \RuntimeException('other_source_link_fact_invalid');
            }
            $platform = substr($sourceRef, 0, strpos($sourceRef, ':'));
            $platformDoc = SourcePlatformContract::getKnownPlatform($platform);
            if ($platformDoc === null) {
                throw new \RuntimeException('other_source_platform_unknown');
            }
            $label = trim((string) ($platformDoc['platform_name'] ?? $platform));
            $lines[] = $label . '：' . $url;
            $ids[] = $factId;
        }

        if ($lines === []) {
            throw new \RuntimeException('other_source_links_empty');
        }

        return new OtherSourceListingLinksMessageRenderResult([
            'type' => 'text',
            'text' => "其他平台也有相關行程可參考：\n" . implode("\n", $lines),
        ], $ids);
    }
}
