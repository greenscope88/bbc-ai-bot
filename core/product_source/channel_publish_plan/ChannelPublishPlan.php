<?php
declare(strict_types=1);

/**
 * BATS channel publish plan contract (Phase 9-B-20).
 *
 * Renderer-friendly intermediary format. Must not contain Flex JSON, prompts, or channel payloads.
 */
final class ChannelPublishPlan
{
    public const PAYLOAD_SCHEMA_VERSION = 1;

    /** @var list<int> */
    public const SUPPORTED_PAYLOAD_SCHEMA_VERSIONS = [1];

    /** @var list<string> */
    public const CHANNELS = [
        'line',
        'gemini',
        'web_chat',
        'telegram',
        'app',
        'wechat',
    ];

    private string $channel;

    private string $strategyName;

    private int $payloadSchemaVersion;

    /** @var list<array<string, mixed>> */
    private array $items;

    /** @var array<string, mixed>|null */
    private ?array $fallback;

    /** @var array<string, mixed> */
    private array $metadata;

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>|null $fallback
     * @param array<string, mixed> $metadata
     */
    private function __construct(
        string $channel,
        string $strategyName,
        int $payloadSchemaVersion,
        array $items,
        ?array $fallback,
        array $metadata
    ) {
        $this->channel = $channel;
        $this->strategyName = $strategyName;
        $this->payloadSchemaVersion = $payloadSchemaVersion;
        $this->items = $items;
        $this->fallback = $fallback;
        $this->metadata = $metadata;
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document): self
    {
        $normalized = self::normalizeDocument($document);

        return new self(
            (string) $normalized['channel'],
            (string) $normalized['strategy_name'],
            (int) $normalized['payload_schema_version'],
            $normalized['items'],
            $normalized['fallback'],
            $normalized['metadata']
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'strategy_name' => $this->strategyName,
            'payload_schema_version' => $this->payloadSchemaVersion,
            'items' => $this->items,
            'fallback' => $this->fallback,
            'metadata' => $this->metadata,
        ];
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function normalizeDocument(array $document): array
    {
        $out = [];

        $out['channel'] = isset($document['channel']) ? strtolower(trim((string) $document['channel'])) : '';
        $out['strategy_name'] = isset($document['strategy_name']) ? trim((string) $document['strategy_name']) : '';
        $out['payload_schema_version'] = isset($document['payload_schema_version'])
            ? (int) $document['payload_schema_version']
            : self::PAYLOAD_SCHEMA_VERSION;

        $out['items'] = [];
        if (isset($document['items']) && is_array($document['items'])) {
            foreach ($document['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $out['items'][] = self::normalizeItem($item);
            }
        }

        if (array_key_exists('fallback', $document) && $document['fallback'] !== null) {
            $out['fallback'] = is_array($document['fallback']) ? $document['fallback'] : null;
        } else {
            $out['fallback'] = null;
        }

        $out['metadata'] = isset($document['metadata']) && is_array($document['metadata'])
            ? $document['metadata']
            : [];

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function normalizeItem(array $item): array
    {
        $normalized = [
            'title' => isset($item['title']) ? trim((string) $item['title']) : '',
            'summary' => null,
            'primary_url' => isset($item['primary_url']) ? trim((string) $item['primary_url']) : '',
            'secondary_urls' => [],
            'actions' => [],
            'metadata' => [],
        ];

        if (isset($item['summary']) && is_string($item['summary'])) {
            $summary = trim($item['summary']);
            $normalized['summary'] = $summary !== '' ? $summary : null;
        }

        if (isset($item['url']) && $normalized['primary_url'] === '') {
            $normalized['primary_url'] = trim((string) $item['url']);
        }

        if (isset($item['secondary_urls']) && is_array($item['secondary_urls'])) {
            $normalized['secondary_urls'] = self::normalizeStringList($item['secondary_urls']);
        }

        if (isset($item['actions']) && is_array($item['actions'])) {
            $normalized['actions'] = $item['actions'];
        }

        if (isset($item['metadata']) && is_array($item['metadata'])) {
            $normalized['metadata'] = $item['metadata'];
        }

        return $normalized;
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private static function normalizeStringList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return $out;
    }
}
