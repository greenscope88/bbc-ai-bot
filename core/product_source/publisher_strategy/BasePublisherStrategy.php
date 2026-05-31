<?php
declare(strict_types=1);

/**
 * Shared publisher strategy helpers (Phase 9-B-19 skeleton).
 */
abstract class BasePublisherStrategy
{
    protected function truncateText(string $text, int $maxLength, string $suffix = '…'): string
    {
        if ($maxLength <= 0) {
            return '';
        }

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $suffixLength = mb_strlen($suffix);
        $cutLength = max(0, $maxLength - $suffixLength);

        return mb_substr($text, 0, $cutLength) . $suffix;
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    protected function limitItems(array $items, int $maxItems): array
    {
        if ($maxItems <= 0) {
            return [];
        }

        return array_slice($items, 0, $maxItems);
    }

    /**
     * @param array<string, mixed> $fallbackPolicy
     * @return array<string, mixed>|null
     */
    protected function applyFallback(array $fallbackPolicy, int $maxItems, int $originalCount): ?array
    {
        if ($originalCount === 0) {
            $onEmpty = isset($fallbackPolicy['on_empty_results'])
                ? trim((string) $fallbackPolicy['on_empty_results'])
                : '';

            if ($onEmpty === '') {
                return null;
            }

            return [
                'type' => $onEmpty,
                'message' => null,
            ];
        }

        if ($originalCount <= $maxItems) {
            return null;
        }

        $onOverflow = isset($fallbackPolicy['on_item_overflow'])
            ? trim((string) $fallbackPolicy['on_item_overflow'])
            : '';

        if ($onOverflow === '') {
            return null;
        }

        $noticeTemplate = isset($fallbackPolicy['overflow_notice'])
            ? (string) $fallbackPolicy['overflow_notice']
            : '';

        $notice = str_replace('{max_items}', (string) $maxItems, $noticeTemplate);

        return [
            'type' => $onOverflow,
            'message' => $notice !== '' ? $notice : null,
        ];
    }
}
