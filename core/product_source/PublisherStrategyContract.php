<?php
declare(strict_types=1);

/**
 * BATS publisher strategy contract (Phase 9-B-18.1).
 *
 * Channel publish policy only — not LINE Flex, Gemini prompts, HTML, or Telegram payloads.
 */
final class PublisherStrategyContract
{
    public const SCHEMA_VERSION = 1;

    /** @var list<int> */
    public const SUPPORTED_SCHEMA_VERSIONS = [1];

    /** @var list<string> */
    public const CHANNELS = [
        'line',
        'gemini',
        'web_chat',
        'telegram',
        'app',
    ];

    /** @var list<string> */
    public const POLICY_FIELDS = [
        'link_policy',
        'text_format_policy',
        'button_policy',
        'fallback_policy',
        'tenant_override_policy',
    ];

    private int $schemaVersion;

    private string $channel;

    private string $strategyName;

    private int $maxItems;

    private string $messageMode;

    /** @var array<string, mixed> */
    private array $linkPolicy;

    /** @var array<string, mixed> */
    private array $textFormatPolicy;

    /** @var array<string, mixed> */
    private array $buttonPolicy;

    /** @var array<string, mixed> */
    private array $fallbackPolicy;

    private int $payloadSchemaVersion;

    /** @var array<string, mixed> */
    private array $tenantOverridePolicy;

    /**
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document): self
    {
        $normalized = self::normalizeDocument($document);

        return new self(
            (int) $normalized['schema_version'],
            (string) $normalized['channel'],
            (string) $normalized['strategy_name'],
            (int) $normalized['max_items'],
            (string) $normalized['message_mode'],
            $normalized['link_policy'],
            $normalized['text_format_policy'],
            $normalized['button_policy'],
            $normalized['fallback_policy'],
            (int) $normalized['payload_schema_version'],
            $normalized['tenant_override_policy']
        );
    }

    /**
     * @param array<string, mixed> $linkPolicy
     * @param array<string, mixed> $textFormatPolicy
     * @param array<string, mixed> $buttonPolicy
     * @param array<string, mixed> $fallbackPolicy
     * @param array<string, mixed> $tenantOverridePolicy
     */
    private function __construct(
        int $schemaVersion,
        string $channel,
        string $strategyName,
        int $maxItems,
        string $messageMode,
        array $linkPolicy,
        array $textFormatPolicy,
        array $buttonPolicy,
        array $fallbackPolicy,
        int $payloadSchemaVersion,
        array $tenantOverridePolicy
    ) {
        $this->schemaVersion = $schemaVersion;
        $this->channel = $channel;
        $this->strategyName = $strategyName;
        $this->maxItems = $maxItems;
        $this->messageMode = $messageMode;
        $this->linkPolicy = $linkPolicy;
        $this->textFormatPolicy = $textFormatPolicy;
        $this->buttonPolicy = $buttonPolicy;
        $this->fallbackPolicy = $fallbackPolicy;
        $this->payloadSchemaVersion = $payloadSchemaVersion;
        $this->tenantOverridePolicy = $tenantOverridePolicy;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'channel' => $this->channel,
            'strategy_name' => $this->strategyName,
            'max_items' => $this->maxItems,
            'message_mode' => $this->messageMode,
            'link_policy' => $this->linkPolicy,
            'text_format_policy' => $this->textFormatPolicy,
            'button_policy' => $this->buttonPolicy,
            'fallback_policy' => $this->fallbackPolicy,
            'payload_schema_version' => $this->payloadSchemaVersion,
            'tenant_override_policy' => $this->tenantOverridePolicy,
        ];
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getStrategyName(): string
    {
        return $this->strategyName;
    }

    public function getMaxItems(): int
    {
        return $this->maxItems;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function normalizeDocument(array $document): array
    {
        $out = [];

        if (isset($document['schema_version'])) {
            $out['schema_version'] = (int) $document['schema_version'];
        } else {
            $out['schema_version'] = self::SCHEMA_VERSION;
        }

        $out['channel'] = isset($document['channel']) ? strtolower(trim((string) $document['channel'])) : '';
        $out['strategy_name'] = isset($document['strategy_name']) ? trim((string) $document['strategy_name']) : '';
        $out['max_items'] = isset($document['max_items']) ? (int) $document['max_items'] : 0;
        $out['message_mode'] = isset($document['message_mode']) ? trim((string) $document['message_mode']) : '';
        $out['payload_schema_version'] = isset($document['payload_schema_version'])
            ? (int) $document['payload_schema_version']
            : self::SCHEMA_VERSION;

        foreach (self::POLICY_FIELDS as $field) {
            $out[$field] = isset($document[$field]) && is_array($document[$field])
                ? $document[$field]
                : [];
        }

        return $out;
    }
}
