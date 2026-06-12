<?php
declare(strict_types=1);

/**
 * BDS Phase 6B-2C-2 — Platform Drive Registry loader.
 *
 * Resolves platform root folder IDs from config/bds_platform_drive_registry.php.
 * Fully separate from BdsSourceRegistryLoader (Tenant Registry).
 *
 * @see docs/BATS_DATA_SOURCE_REGISTRY.md §6.7
 */
final class BdsPlatformDriveRegistryLoader
{
    /** @var array<string, mixed>|null */
    private static $registry = null;

    /**
     * @return array{schema_version: string, platform_roots: array<string, ?string>}
     */
    public static function load(): array
    {
        $registry = self::readRegistry();

        return [
            'schema_version' => isset($registry['schema_version'])
                ? trim((string) $registry['schema_version'])
                : '',
            'platform_roots' => self::getPlatformRoots(),
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
