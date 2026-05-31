<?php
declare(strict_types=1);

/**
 * BATS webhook feature gate (Phase 9-B-26B-1).
 *
 * Config-driven tenant/channel mode resolution. No SQL or HTTP.
 */
final class BatsFeatureGate
{
    public const MODE_ENABLED = 'enabled';

    public const MODE_DISABLED = 'disabled';

    public const MODE_DRY_RUN = 'dry_run';

    /** @var list<string> */
    public const MODES = [
        self::MODE_ENABLED,
        self::MODE_DISABLED,
        self::MODE_DRY_RUN,
    ];

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? self::defaultConfig();
    }

    /**
     * @param array<string, mixed> $context tenant_sno, channel
     */
    public function resolveMode(array $context): string
    {
        $tenantSno = isset($context['tenant_sno']) ? trim((string) $context['tenant_sno']) : '';
        $channel = isset($context['channel']) ? trim((string) $context['channel']) : '';

        if ($tenantSno !== '' && isset($this->config['tenants'][$tenantSno]) && is_array($this->config['tenants'][$tenantSno])) {
            return $this->normalizeMode($this->config['tenants'][$tenantSno]['mode'] ?? self::MODE_DISABLED);
        }

        if ($channel !== '' && isset($this->config['channels'][$channel]) && is_array($this->config['channels'][$channel])) {
            return $this->normalizeMode($this->config['channels'][$channel]['mode'] ?? self::MODE_DISABLED);
        }

        $defaults = isset($this->config['defaults']) && is_array($this->config['defaults'])
            ? $this->config['defaults']
            : [];

        return $this->normalizeMode($defaults['mode'] ?? self::MODE_DISABLED);
    }

    public function isPipelineAllowed(string $mode): bool
    {
        return $mode === self::MODE_ENABLED || $mode === self::MODE_DRY_RUN;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultConfig(): array
    {
        return [
            'defaults' => [
                'mode' => self::MODE_DISABLED,
            ],
            'tenants' => [
                '5f99b8d665e8444d' => [
                    'mode' => self::MODE_DRY_RUN,
                ],
            ],
            'channels' => [],
        ];
    }

    private function normalizeMode($mode): string
    {
        $normalized = strtolower(trim((string) $mode));

        if (!in_array($normalized, self::MODES, true)) {
            return self::MODE_DISABLED;
        }

        return $normalized;
    }
}
