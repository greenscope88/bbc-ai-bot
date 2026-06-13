<?php
declare(strict_types=1);

/**
 * BDS Phase 6B-2C-2 — Platform Drive Registry loader.
 *
 * Resolves platform root folder IDs from config/bds_platform_drive_registry.php.
 * Fully separate from BdsSourceRegistryLoader (Tenant Registry).
 *
 * @see docs/BATS_DATA_SOURCE_REGISTRY.md §6.7
 * @see docs/BATS_DRIVE_GCS_MAPPING.md §18
 */
final class BdsPlatformDriveRegistryLoader
{
    public const SCHEMA_VERSION = 'bds_platform_drive_registry.v1';

    /** @var array<string, mixed>|null */
    private static $registry = null;

    /**
     * @return array{
     *   schema_version: string,
     *   platform_roots: array<string, ?string>,
     *   industries: array<string, array{industry_code: string, industry_folder_id: ?string, shared_layer_folder_id: ?string}>,
     *   global_shared_layer_folder_id: ?string
     * }
     */
    public static function load(): array
    {
        $registry = self::readRegistry();

        return [
            'schema_version' => isset($registry['schema_version'])
                ? trim((string) $registry['schema_version'])
                : '',
            'platform_roots' => self::getPlatformRoots(),
            'industries' => self::getAllIndustryEntries(),
            'global_shared_layer_folder_id' => self::getGlobalSharedLayerFolderId(),
        ];
    }

    /**
     * @return array{
     *   industries_root_folder_id: ?string,
     *   global_root_folder_id: ?string,
     *   registrations_root_folder_id: ?string
     * }
     */
    public static function getPlatformRoots(): array
    {
        $registry = self::readRegistry();
        $roots = $registry['platform_roots'] ?? null;
        if (!is_array($roots)) {
            $roots = [];
        }

        return [
            'industries_root_folder_id' => self::normalizeFolderId($roots, 'industries_root_folder_id'),
            'global_root_folder_id' => self::normalizeFolderId($roots, 'global_root_folder_id'),
            'registrations_root_folder_id' => self::normalizeFolderId($roots, 'registrations_root_folder_id'),
        ];
    }

    public static function getIndustriesRootFolderId(): ?string
    {
        return self::getPlatformRoots()['industries_root_folder_id'];
    }

    public static function getGlobalRootFolderId(): ?string
    {
        return self::getPlatformRoots()['global_root_folder_id'];
    }

    public static function getRegistrationsRootFolderId(): ?string
    {
        return self::getPlatformRoots()['registrations_root_folder_id'];
    }

    /**
     * @return array{industry_code: string, industry_folder_id: ?string, shared_layer_folder_id: ?string}|null
     */
    public static function getIndustryEntry(string $industryCode): ?array
    {
        $industryCode = trim($industryCode);
        if ($industryCode === '') {
            return null;
        }

        $registry = self::readRegistry();
        $industries = $registry['industries'] ?? null;
        if (!is_array($industries) || !isset($industries[$industryCode]) || !is_array($industries[$industryCode])) {
            return null;
        }

        return self::normalizeIndustryEntry($industryCode, $industries[$industryCode]);
    }

    public static function getIndustryFolderId(string $industryCode): ?string
    {
        $entry = self::getIndustryEntry($industryCode);

        return $entry['industry_folder_id'] ?? null;
    }

    public static function getSharedLayerFolderId(string $industryCode): ?string
    {
        $entry = self::getIndustryEntry($industryCode);

        return $entry['shared_layer_folder_id'] ?? null;
    }

    public static function getGlobalSharedLayerFolderId(): ?string
    {
        $registry = self::readRegistry();
        $global = $registry['global'] ?? null;
        if (!is_array($global)) {
            return null;
        }

        return self::normalizeFolderId($global, 'shared_layer_folder_id');
    }

    public static function hasSharedLayerFolder(string $industryCode): bool
    {
        return self::getSharedLayerFolderId($industryCode) !== null;
    }

    /**
     * @return array<string, array{industry_code: string, industry_folder_id: ?string, shared_layer_folder_id: ?string}>
     */
    public static function getAllIndustryEntries(): array
    {
        $registry = self::readRegistry();
        $industries = $registry['industries'] ?? null;
        if (!is_array($industries)) {
            return [];
        }

        $entries = [];
        foreach ($industries as $code => $entry) {
            if (!is_string($code) || !is_array($entry)) {
                continue;
            }

            $normalized = self::normalizeIndustryEntry($code, $entry);
            $entries[$normalized['industry_code']] = $normalized;
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readRegistry(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        $path = self::resolveConfigPath();
        if (!is_file($path)) {
            self::$registry = [];
            return self::$registry;
        }

        $loaded = require $path;
        self::$registry = is_array($loaded) ? $loaded : [];

        return self::$registry;
    }

    private static function resolveConfigPath(): string
    {
        $override = getenv('BDS_PLATFORM_DRIVE_REGISTRY_PATH');
        if (is_string($override)) {
            $trimmed = trim($override);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bds_platform_drive_registry.php';
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{industry_code: string, industry_folder_id: ?string, shared_layer_folder_id: ?string}
     */
    private static function normalizeIndustryEntry(string $industryCode, array $entry): array
    {
        return [
            'industry_code' => trim($industryCode),
            'industry_folder_id' => self::normalizeFolderId($entry, 'industry_folder_id'),
            'shared_layer_folder_id' => self::normalizeFolderId($entry, 'shared_layer_folder_id'),
        ];
    }

    /**
     * @param array<string, mixed> $roots
     */
    private static function normalizeFolderId(array $roots, string $field): ?string
    {
        if (!array_key_exists($field, $roots)) {
            return null;
        }

        $value = $roots[$field];
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
