<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ResolvedTenant.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantRegistryInterface.php';

/**
 * Pure config-driven tenant registry. No DB, webhook, or Host B dependencies.
 */
final class ConfigTenantRegistry implements TenantRegistryInterface
{
    /** @var array<string, ResolvedTenant> channelId => tenant */
    private $byChannel = [];

    /** @var array<string, ResolvedTenant> sno => tenant */
    private $bySno = [];

    /** @var list<ResolvedTenant> */
    private $all = [];

    /** @var array<string, mixed> */
    private $global = [];

    private int $schemaVersion = 0;

    public function __construct(?string $configPath = null)
    {
        $path = $configPath ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tenant_registry.php';
        $this->loadFromFile($path);
    }

    public function resolveByChannel(string $channelId): ?ResolvedTenant
    {
        $key = trim($channelId);
        if ($key === '') {
            return null;
        }

        return $this->byChannel[$key] ?? null;
    }

    public function resolveBySno(string $sno): ?ResolvedTenant
    {
        $key = trim($sno);
        if ($key === '') {
            return null;
        }

        return $this->bySno[$key] ?? null;
    }

    /**
     * @return list<ResolvedTenant>
     */
    public function getAllTenants(): array
    {
        return $this->all;
    }

    /**
     * @return array<string, mixed>
     */
    public function getGlobalConfig(): array
    {
        return $this->global;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    private function loadFromFile(string $path): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Tenant registry config not found: ' . $path);
        }

        /** @var mixed $loaded */
        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new \RuntimeException('Tenant registry config must return an array.');
        }

        $this->schemaVersion = isset($loaded['schema_version']) ? (int) $loaded['schema_version'] : 0;
        $global = $loaded['global'] ?? [];
        $this->global = is_array($global) ? $global : [];

        $tenants = $loaded['tenants'] ?? [];
        if (!is_array($tenants)) {
            throw new \RuntimeException('Tenant registry config missing tenants array.');
        }

        foreach ($tenants as $tenantKey => $row) {
            if (!is_string($tenantKey) || $tenantKey === '' || !is_array($row)) {
                continue;
            }
            $dto = $this->buildDto($tenantKey, $row);
            $this->all[] = $dto;

            $channel = $dto->getLineChannelId();
            if ($channel !== '') {
                $this->byChannel[$channel] = $dto;
            }

            $sno = $dto->getSno();
            if ($sno !== '') {
                $this->bySno[$sno] = $dto;
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildDto(string $tenantKey, array $row): ResolvedTenant
    {
        $features = $row['features'] ?? [];
        if (!is_array($features)) {
            $features = [];
        }
        $normalizedFeatures = [];
        foreach ($features as $name => $value) {
            if (is_string($name)) {
                $normalizedFeatures[$name] = (bool) $value;
            }
        }

        $profile = $row['profile'] ?? [];
        if (!is_array($profile)) {
            $profile = [];
        }

        $geminiPolicy = $row['gemini_policy'] ?? [];
        if (!is_array($geminiPolicy)) {
            $geminiPolicy = [];
        }

        $sourcePolicy = $row['source_policy'] ?? [];
        if (!is_array($sourcePolicy)) {
            $sourcePolicy = [];
        }

        $status = isset($row['status']) ? trim((string) $row['status']) : 'disabled';
        if ($status === '') {
            $status = 'disabled';
        }

        $prefix = isset($row['credential_env_prefix']) ? trim((string) $row['credential_env_prefix']) : '';
        if ($prefix === '') {
            $prefix = $tenantKey;
        }

        return new ResolvedTenant(
            $tenantKey,
            trim((string) ($row['line_channel_id'] ?? '')),
            trim((string) ($row['sno'] ?? '')),
            (int) ($row['depID'] ?? 0),
            (int) ($row['storeNo'] ?? 0),
            (int) ($row['store_uid'] ?? ($row['storeNo'] ?? 0)),
            (int) ($row['provider_id_no'] ?? 0),
            $status,
            $prefix,
            $normalizedFeatures,
            $profile,
            $geminiPolicy,
            $sourcePolicy
        );
    }
}
