<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'BasePublisherStrategy.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'PublisherStrategyInterface.php';

/**
 * LINE channel publisher strategy skeleton (Phase 9-B-19).
 *
 * Applies max_items and text/url limits only. Does not emit LINE Flex or API payloads.
 */
final class LinePublisherStrategy extends BasePublisherStrategy implements PublisherStrategyInterface
{
    /**
     * @param list<array<string, mixed>> $publisherContracts
     * @return array<string, mixed>
     */
    public function applyStrategy(array $publisherContracts, PublisherStrategyContract $strategy): array
    {
        $config = $strategy->toArray();
        $textPolicy = isset($config['text_format_policy']) && is_array($config['text_format_policy'])
            ? $config['text_format_policy']
            : [];
        $linkPolicy = isset($config['link_policy']) && is_array($config['link_policy'])
            ? $config['link_policy']
            : [];
        $fallbackPolicy = isset($config['fallback_policy']) && is_array($config['fallback_policy'])
            ? $config['fallback_policy']
            : [];

        $maxTitle = isset($textPolicy['max_title_length']) ? (int) $textPolicy['max_title_length'] : 0;
        $maxSummary = isset($textPolicy['max_summary_length']) ? (int) $textPolicy['max_summary_length'] : 0;
        $suffix = isset($textPolicy['truncate_suffix']) ? (string) $textPolicy['truncate_suffix'] : '…';
        $maxUrlLength = isset($linkPolicy['max_url_length']) ? (int) $linkPolicy['max_url_length'] : 0;

        $originalCount = count($publisherContracts);
        $limited = $this->limitItems($publisherContracts, $strategy->getMaxItems());

        $items = [];
        foreach ($limited as $contract) {
            $title = isset($contract['title']) ? trim((string) $contract['title']) : '';
            $summary = isset($contract['summary']) ? trim((string) $contract['summary']) : '';
            $url = isset($contract['primary_url']) ? trim((string) $contract['primary_url']) : '';

            if ($maxTitle > 0) {
                $title = $this->truncateText($title, $maxTitle, $suffix);
            }
            if ($maxSummary > 0 && $summary !== '') {
                $summary = $this->truncateText($summary, $maxSummary, $suffix);
            }
            if ($maxUrlLength > 0 && $url !== '') {
                $url = $this->truncateText($url, $maxUrlLength, $suffix);
            }

            $items[] = [
                'title' => $title,
                'summary' => $summary !== '' ? $summary : null,
                'url' => $url,
            ];
        }

        $fallback = $this->applyFallback($fallbackPolicy, $strategy->getMaxItems(), $originalCount);

        return [
            'channel' => $strategy->getChannel(),
            'strategy_name' => $strategy->getStrategyName(),
            'items' => $items,
            'fallback' => $fallback,
        ];
    }
}
