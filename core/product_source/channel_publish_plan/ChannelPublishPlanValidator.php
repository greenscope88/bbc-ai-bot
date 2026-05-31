<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ChannelPublishPlan.php';

/**
 * Validates BATS channel publish plan documents (Phase 9-B-20).
 */
final class ChannelPublishPlanValidator
{
    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    public function collectViolations(array $document): array
    {
        $violations = [];
        $normalized = ChannelPublishPlan::normalizeDocument($document);

        if ($normalized['channel'] === '') {
            $violations[] = 'channel is required';
        } elseif (!in_array($normalized['channel'], ChannelPublishPlan::CHANNELS, true)) {
            $violations[] = 'invalid channel';
        }

        if ($normalized['strategy_name'] === '') {
            $violations[] = 'strategy_name is required';
        }

        if ($normalized['payload_schema_version'] <= 0) {
            $violations[] = 'payload_schema_version must be a positive integer';
        } elseif (!in_array($normalized['payload_schema_version'], ChannelPublishPlan::SUPPORTED_PAYLOAD_SCHEMA_VERSIONS, true)) {
            $violations[] = 'unsupported payload_schema_version';
        }

        if (!isset($document['items']) || !is_array($document['items'])) {
            $violations[] = 'items must be an array';
        } else {
            foreach ($document['items'] as $index => $item) {
                if (!is_array($item)) {
                    $violations[] = 'items[' . $index . '] must be an object';
                    continue;
                }

                $title = isset($item['title']) ? trim((string) $item['title']) : '';
                if ($title === '') {
                    $violations[] = 'items[' . $index . '].title is required';
                }

                $primaryUrl = isset($item['primary_url']) ? trim((string) $item['primary_url']) : '';
                if ($primaryUrl === '' && isset($item['url'])) {
                    $primaryUrl = trim((string) $item['url']);
                }

                if ($primaryUrl === '') {
                    $violations[] = 'items[' . $index . '].primary_url is required';
                } elseif (!$this->isValidHttpUrl($primaryUrl)) {
                    $violations[] = 'items[' . $index . '].primary_url must be a valid http(s) URL';
                }
            }
        }

        if (isset($document['metadata']) && $document['metadata'] !== null && !is_array($document['metadata'])) {
            $violations[] = 'metadata must be an array';
        }

        if (isset($document['fallback']) && $document['fallback'] !== null && !is_array($document['fallback'])) {
            $violations[] = 'fallback must be an array or null';
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $document
     * @throws \InvalidArgumentException
     */
    public function validate(array $document): ChannelPublishPlan
    {
        $violations = $this->collectViolations($document);
        if ($violations !== []) {
            throw new \InvalidArgumentException(
                'Channel publish plan invalid (' . count($violations) . ' issue(s)): '
                . implode('; ', $violations)
            );
        }

        return ChannelPublishPlan::fromArray($document);
    }

    private function isValidHttpUrl(string $url): bool
    {
        if (stripos($url, 'http://') !== 0 && stripos($url, 'https://') !== 0) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
