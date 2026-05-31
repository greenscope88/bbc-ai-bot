<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'PublisherStrategyContract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'PublisherStrategyContractValidator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'LinePublisherStrategy.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'PublisherStrategyInterface.php';

/**
 * Resolves publisher strategy config and channel adapters (Phase 9-B-19).
 *
 * Phase 9-B-19 supports channel=line only.
 */
final class PublisherStrategyResolver
{
    public const SUPPORTED_CHANNELS = ['line'];

    /** @var array<string, mixed> */
    private array $config;

    private PublisherStrategyContractValidator $validator;

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(?array $config = null, ?PublisherStrategyContractValidator $validator = null)
    {
        $this->config = $config ?? $this->loadDefaultConfig();
        $this->validator = $validator ?? new PublisherStrategyContractValidator();
    }

    public function resolve(string $channel, ?string $tenantSno = null): PublisherStrategyContract
    {
        $normalizedChannel = strtolower(trim($channel));
        if (!in_array($normalizedChannel, self::SUPPORTED_CHANNELS, true)) {
            throw new \RuntimeException(
                'Unsupported publisher strategy channel: ' . $normalizedChannel
            );
        }

        $defaults = isset($this->config['defaults']) && is_array($this->config['defaults'])
            ? $this->config['defaults']
            : [];

        if (!isset($defaults[$normalizedChannel]) || !is_array($defaults[$normalizedChannel])) {
            throw new \RuntimeException(
                'Publisher strategy defaults not found for channel: ' . $normalizedChannel
            );
        }

        $merged = $defaults[$normalizedChannel];

        if ($tenantSno !== null && trim($tenantSno) !== '') {
            $merged = $this->mergeTenantOverride(
                $merged,
                $this->findTenantOverride(trim($tenantSno), $normalizedChannel)
            );
        }

        return $this->validator->validate($merged);
    }

    public function getStrategy(string $channel): PublisherStrategyInterface
    {
        $normalizedChannel = strtolower(trim($channel));
        if ($normalizedChannel === 'line') {
            return new LinePublisherStrategy();
        }

        throw new \RuntimeException(
            'Unsupported publisher strategy channel: ' . $normalizedChannel
        );
    }

    /**
     * @param list<array<string, mixed>> $publisherContracts
     * @return array<string, mixed>
     */
    public function apply(
        string $channel,
        array $publisherContracts,
        ?string $tenantSno = null
    ): array {
        $strategy = $this->resolve($channel, $tenantSno);

        return $this->getStrategy($channel)->applyStrategy($publisherContracts, $strategy);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDefaultConfig(): array
    {
        $path = dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR . 'config'
            . DIRECTORY_SEPARATOR . 'examples'
            . DIRECTORY_SEPARATOR . 'publisher_strategies.example.php';

        if (!is_file($path)) {
            throw new \RuntimeException('Publisher strategy config not found: ' . $path);
        }

        /** @var array<string, mixed> $config */
        $config = require $path;

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function findTenantOverride(string $tenantSno, string $channel): array
    {
        $tenants = isset($this->config['tenants']) && is_array($this->config['tenants'])
            ? $this->config['tenants']
            : [];

        if (!isset($tenants[$tenantSno]) || !is_array($tenants[$tenantSno])) {
            return [];
        }

        $tenantConfig = $tenants[$tenantSno];
        if (!isset($tenantConfig[$channel]) || !is_array($tenantConfig[$channel])) {
            return [];
        }

        return $tenantConfig[$channel];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function mergeTenantOverride(array $base, array $override): array
    {
        if ($override === []) {
            return $base;
        }

        $tenantOverridePolicy = isset($base['tenant_override_policy']) && is_array($base['tenant_override_policy'])
            ? $base['tenant_override_policy']
            : [];

        if (!($tenantOverridePolicy['enabled'] ?? false)) {
            return $base;
        }

        $allowedKeys = isset($tenantOverridePolicy['override_keys']) && is_array($tenantOverridePolicy['override_keys'])
            ? $tenantOverridePolicy['override_keys']
            : [];

        $merged = $base;
        foreach ($allowedKeys as $key) {
            if (!is_string($key) || !array_key_exists($key, $override)) {
                continue;
            }

            if (is_array($override[$key]) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = array_merge($merged[$key], $override[$key]);
            } else {
                $merged[$key] = $override[$key];
            }
        }

        return $merged;
    }
}
