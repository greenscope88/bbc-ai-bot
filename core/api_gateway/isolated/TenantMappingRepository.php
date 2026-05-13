<?php
declare(strict_types=1);

/**
 * Phase 4 Stage 1 isolated repository.
 * Uses in-memory mock mappings only; no SQL and no production wiring.
 */
final class TenantMappingRepository
{
    /** @var array<string, array<string, mixed>> */
    private array $mockMappingsBySno;

    /**
     * @param array<int, array<string, mixed>>|null $seedRows
     */
    public function __construct(?array $seedRows = null)
    {
        $rows = $seedRows ?? $this->defaultSeedRows();
        $this->mockMappingsBySno = [];

        foreach ($rows as $row) {
            $sno = trim((string) ($row['sno'] ?? ''));
            if ($sno === '') {
                continue;
            }
            $this->mockMappingsBySno[$sno] = $row;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySno(string $sno): ?array
    {
        $normalized = trim($sno);
        if ($normalized === '') {
            return null;
        }

        return $this->mockMappingsBySno[$normalized] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultSeedRows(): array
    {
        return [
            [
                'sno' => 'e1fd133c7e8e45a1',
                'tenant_name' => 'Example Travel',
                'provider_id_no' => 1,
                'depID' => 1,
                'store_uid' => 1001,
                'storeNo' => 1001,
                'enabled' => true,
                'tenant_status' => 'active',
                'allowed_services' => ['tour.search', 'order.query'],
                'api_profile' => 'standard',
                'rate_limit_profile' => 'standard',
            ],
            [
                'sno' => 'd4isabledtenant001',
                'tenant_name' => 'Disabled Travel',
                'provider_id_no' => 2,
                'depID' => 2,
                'store_uid' => 2001,
                'storeNo' => 2001,
                'enabled' => false,
                'tenant_status' => 'disabled',
                'allowed_services' => ['tour.search'],
                'api_profile' => 'standard',
                'rate_limit_profile' => 'standard',
            ],
            [
                'sno' => 'suspendedtenant01',
                'tenant_name' => 'Suspended Travel',
                'provider_id_no' => 3,
                'depID' => 3,
                'store_uid' => 3001,
                'storeNo' => 3001,
                'enabled' => true,
                'tenant_status' => 'suspended',
                'allowed_services' => ['tour.search'],
                'api_profile' => 'standard',
                'rate_limit_profile' => 'standard',
            ],
        ];
    }
}
