<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantProductSourcesGcsPathConfig.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantProductSourcesProviderInterface.php';

/**
 * GCS tenant product sources provider skeleton (Phase 9-B-5). No Google SDK or network I/O.
 */
final class GcsTenantProductSourcesProvider implements TenantProductSourcesProviderInterface
{
    /** @var string */
    private $tenantSno;

    /** @var string */
    private $objectPath;

    public function __construct(string $tenantSno, ?string $objectPath = null)
    {
        $sno = trim($tenantSno);
        if ($sno === '') {
            throw new \InvalidArgumentException('tenant_sno is required for GCS tenant product sources provider.');
        }

        $this->tenantSno = $sno;
        $this->objectPath = $objectPath !== null && trim($objectPath) !== ''
            ? trim($objectPath)
            : TenantProductSourcesGcsPathConfig::resolveObjectPath($sno);
    }

    public function getProviderId(): string
    {
        return 'gcs';
    }

    public function getTenantSno(): string
    {
        return $this->tenantSno;
    }

    public function getObjectPath(): string
    {
        return $this->objectPath;
    }

    public function fetchTenantProductSourcesDocument(): array
    {
        return $this->buildEmptyDocument();
    }

    /**
     * Safe fallback: always returns an empty tenant document without throwing.
     *
     * @return array{
     *   schema_version: int,
     *   tenant_sno: string,
     *   tenant_key: string,
     *   default_category: string,
     *   enabled_sources: list<array<string, mixed>>
     * }
     */
    public function safeFetchTenantProductSourcesDocument(): array
    {
        try {
            return $this->fetchTenantProductSourcesDocument();
        } catch (\Throwable $e) {
            return $this->buildEmptyDocument();
        }
    }

    /**
     * @return array{
     *   schema_version: int,
     *   tenant_sno: string,
     *   tenant_key: string,
     *   default_category: string,
     *   enabled_sources: list<array<string, mixed>>
     * }
     */
    private function buildEmptyDocument(): array
    {
        return [
            'schema_version' => 1,
            'tenant_sno' => $this->tenantSno,
            'tenant_key' => '',
            'default_category' => 'group_tour',
            'enabled_sources' => [],
        ];
    }
}
