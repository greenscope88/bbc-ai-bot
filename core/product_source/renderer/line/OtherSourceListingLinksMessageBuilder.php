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
            if ($ordinal >= count(self::ORDINAL_LABELS)) {
                break;
            }
            $displayLabel = self::ORDINAL_LABELS[$ordinal];
            ++$ordinal;
            $blocks[] = $displayLabel . "：\n" . $url;
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
            'text' => $header . "\n\n" . implode("\n\n", $blocks),
        ], $ids);
    }
}
