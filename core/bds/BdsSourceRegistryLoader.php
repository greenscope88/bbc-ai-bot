<?php
declare(strict_types=1);

/**
 * BDS Phase 6A — Source Registry loader.
 *
 * Resolves tenant entries by tenant_key or sno from config/bds_source_registry.php.
 *
 * @see docs/BATS_DATA_SOURCE_REGISTRY.md §7.9
 * @see docs/BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md §Phase 6A.5
 */
final class BdsSourceRegistryLoader
{
    /** @var array<string, mixed>|null */
    private static $registry = null;

    /**
     * @return array<string, mixed>|null Normalized entry or null when not found / invalid.
     */
    public static function loadByTenantKey(string $tenantKey): ?array
    {
        $tenantKey = trim($tenantKey);
        if ($tenantKey === '') {
            return null;
        }

        $registry = self::readRegistry();
        $tenants = $registry['tenants'] ?? null;
        if (!is_array($tenants) || !isset($tenants[$tenantKey]) || !is_array($tenants[$tenantKey])) {
            return null;
        }

        return self::normalizeEntry($tenants[$tenantKey], $tenantKey);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function loadBySno(string $sno): ?array
    {
        $sno = trim($sno);
        if ($sno === '') {
            return null;
        }

        $registry = self::readRegistry();
        $tenants = $registry['tenants'] ?? null;
        if (!is_array($tenants)) {
            return null;
        }

        foreach ($tenants as $tenantKey => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entrySno = isset($entry['sno']) ? trim((string) $entry['sno']) : '';
            if ($entrySno === $sno) {
                $key = is_string($tenantKey) ? $tenantKey : (string) ($entry['tenant_key'] ?? $entry['tenant_name'] ?? '');
                return self::normalizeEntry($entry, $key);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function resolve(?string $tenantKey, ?string $sno): ?array
    {
        $tenantKey = $tenantKey !== null ? trim($tenantKey) : '';
        $sno = $sno !== null ? trim($sno) : '';

        if ($tenantKey === '' && $sno === '') {
            return null;
        }

        $byKey = $tenantKey !== '' ? self::loadByTenantKey($tenantKey) : null;
        $bySno = $sno !== '' ? self::loadBySno($sno) : null;

        if ($tenantKey !== '' && $sno !== '') {
            if ($byKey === null || $bySno === null) {
                return null;
            }
            $keySno = isset($byKey['sno']) ? (string) $byKey['sno'] : '';
            $snoKey = isset($bySno['tenant_key']) ? (string) $bySno['tenant_key'] : '';
            if ($keySno !== $sno || $snoKey !== $tenantKey) {
                return null;
            }
            return $byKey;
        }

        return $byKey ?? $bySno;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readRegistry(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bds_source_registry.php';
        if (!is_file($path)) {
            self::$registry = [];
            return self::$registry;
        }

        $loaded = require $path;
        self::$registry = is_array($loaded) ? $loaded : [];

        return self::$registry;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function normalizeEntry(array $entry, string $tenantKey): array
    {
        $sno = isset($entry['sno']) ? trim((string) $entry['sno']) : '';
        $sheetId = isset($entry['private_knowledge_sheet_id'])
            ? trim((string) $entry['private_knowledge_sheet_id'])
            : '';

        $envSheetId = self::envString('BDS_PRIVATE_KNOWLEDGE_SHEET_ID');
        if ($envSheetId !== '') {
            $sheetId = $envSheetId;
        }

        $gcsPrefix = isset($entry['gcs_prefix']) ? trim((string) $entry['gcs_prefix']) : '';
        if ($gcsPrefix === '' && $sno !== '') {
            $gcsPrefix = 'tenants/' . $sno . '/';
        }

        return [
            'sno' => $sno,
            'tenant_name' => isset($entry['tenant_name']) ? (string) $entry['tenant_name'] : $tenantKey,
            'tenant_key' => isset($entry['tenant_key']) ? (string) $entry['tenant_key'] : $tenantKey,
            'industry_code' => isset($entry['industry_code']) ? (string) $entry['industry_code'] : '',
            'enabled' => !isset($entry['enabled']) || $entry['enabled'] === true,
            'private_knowledge_sheet_id' => $sheetId,
            'gcs_prefix' => $gcsPrefix,
        ];
    }

    private static function envString(string $name): string
    {
        $value = getenv($name);
        return is_string($value) ? trim($value) : '';
    }
}
