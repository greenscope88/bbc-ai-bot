<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'channel_publish_plan' . DIRECTORY_SEPARATOR . 'ChannelPublishPlan.php';

/**
 * Builds LINE text messages from ChannelPublishPlan items (Phase 9-B-22).
 *
 * No truncate, max_items, config loading, or API calls.
 */
final class LineTextMessageBuilder
{
    /**
     * @return list<array<string, string>>
     */
    public function buildMessages(ChannelPublishPlan $plan): array
    {
        $messages = [];

        foreach ($plan->getItems() as $item) {
            $messages[] = [
                'type' => 'text',
                'text' => $this->formatItemText($item),
            ];
        }

        $fallback = $plan->toArray()['fallback'];
        if (is_array($fallback)) {
            $notice = isset($fallback['message']) ? trim((string) $fallback['message']) : '';
            if ($notice !== '') {
                $messages[] = [
                    'type' => 'text',
                    'text' => $notice,
                ];
            }
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatItemText(array $item): string
    {
        $title = isset($item['title']) ? trim((string) $item['title']) : '';
        $summary = isset($item['summary']) && is_string($item['summary'])
            ? trim($item['summary'])
            : '';
        $primaryUrl = isset($item['primary_url']) ? trim((string) $item['primary_url']) : '';

        $lines = [
            '🚩 ' . $title,
            '',
        ];

        if ($summary !== '') {
            $lines[] = $summary;
            $lines[] = '';
        }

        $lines[] = '詳細內容：';
        $lines[] = $primaryUrl;

        return implode("\n", $lines);
    }
}
