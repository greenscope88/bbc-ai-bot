<?php
declare(strict_types=1);

/**
 * BDS Phase 6E-2 — Shared Archive Policy loader.
 *
 * Resolves per-industry / global archive promote enablement from config.
 * Policy default = OFF; zero promote until explicitly enabled.
 *
 * @see docs/BATS_DRIVE_GCS_MAPPING.md §18
 */
final class BdsSharedArchivePolicyLoader
{
    public const SCHEMA_VERSION = 'bds_shared_archive_policy.v1';

    /** @var array<string, mixed>|null */
    private static $policy = null;

    /**
     * @return array{
     *   schema_version: string,
     *   defaults: array{promote_requires_explicit_enable: bool},
     *   industries: array<string, array{industry_code: string, archive_promote_enabled: bool, maintainer_role: string}>,
     *   global: array{archive_promote_enabled: bool, maintainer_role: string}
     * }
     */
    public static function load(): array
    {
        $policy = self::readPolicy();

        return [
            'schema_version' => isset($policy['schema_version'])
                ? trim((string) $policy['schema_version'])
                : '',
            'defaults' => [
                'promote_requires_explicit_enable' => self::requiresExplicitEnable(),
            ],
            'industries' => self::getAllIndustryPolicies(),
            'global' => self::getGlobalPolicy(),
        ];
    }

    public static function requiresExplicitEnable(): bool
    {
        $policy = self::readPolicy();
        $defaults = $policy['defaults'] ?? null;
        if (!is_array($defaults)) {
            return true;
        }

        if (!array_key_exists('promote_requires_explicit_enable', $defaults)) {
            return true;
        }

        return (bool) $defaults['promote_requires_explicit_enable'];
    }

    /**
     * @return array{industry_code: string, archive_promote_enabled: bool, maintainer_role: string}|null
     */
    public static function getIndustryPolicy(string $industryCode): ?array
    {
        $industryCode = trim($industryCode);
        if ($industryCode === '') {
            return null;
        }

        $policy = self::readPolicy();
        $industries = $policy['industries'] ?? null;
        if (!is_array($industries) || !isset($industries[$industryCode]) || !is_array($industries[$industryCode])) {
            return null;
        }

        return self::normalizeIndustryPolicy($industryCode, $industries[$industryCode]);
    }

    public static function isIndustryArchiveEnabled(string $industryCode): bool
    {
        if (self::requiresExplicitEnable()) {
            $entry = self::getIndustryPolicy($industryCode);
            if ($entry === null) {
                return false;
            }

            return $entry['archive_promote_enabled'] === true;
        }

        $entry = self::getIndustryPolicy($industryCode);

        return $entry !== null && $entry['archive_promote_enabled'] === true;
    }

    public static function isGlobalArchiveEnabled(): bool
    {
        $global = self::getGlobalPolicy();

        if (self::requiresExplicitEnable() && $global['archive_promote_enabled'] !== true) {
            return false;
        }

        return $global['archive_promote_enabled'] === true;
    }

    /**
     * @return array{archive_promote_enabled: bool, maintainer_role: string}
     */
    public static function getGlobalPolicy(): array
    {
        $policy = self::readPolicy();
        $global = $policy['global'] ?? null;
        if (!is_array($global)) {
            return [
                'archive_promote_enabled' => false,
                'maintainer_role' => 'platform_admin',
            ];
        }

        return [
            'archive_promote_enabled' => self::normalizeBool($global, 'archive_promote_enabled', false),
            'maintainer_role' => self::normalizeMaintainerRole($global, 'maintainer_role', 'platform_admin'),
        ];
    }

    /**
     * @return array<string, array{industry_code: string, archive_promote_enabled: bool, maintainer_role: string}>
     */
    public static function getAllIndustryPolicies(): array
    {
        $policy = self::readPolicy();
        $industries = $policy['industries'] ?? null;
        if (!is_array($industries)) {
            return [];
        }

        $entries = [];
        foreach ($industries as $code => $entry) {
            if (!is_string($code) || !is_array($entry)) {
                continue;
            }

            $normalized = self::normalizeIndustryPolicy($code, $entry);
            $entries[$normalized['industry_code']] = $normalized;
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readPolicy(): array
    {
        if (self::$policy !== null) {
            return self::$policy;
        }

        $path = self::resolveConfigPath();
        if (!is_file($path)) {
            self::$policy = [];
            return self::$policy;
        }

        $loaded = require $path;
        self::$policy = is_array($loaded) ? $loaded : [];

        if (self::$policy !== [] && !self::isValidSchema(self::$policy)) {
            throw new \RuntimeException(
                'Invalid Shared Archive Policy schema. Expected schema_version: ' . self::SCHEMA_VERSION
            );
        }

        return self::$policy;
    }

    /**
     * @param array<string, mixed> $policy
     */
    private static function isValidSchema(array $policy): bool
    {
        $version = isset($policy['schema_version']) ? trim((string) $policy['schema_version']) : '';

        return $version === self::SCHEMA_VERSION;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{industry_code: string, archive_promote_enabled: bool, maintainer_role: string}
     */
    private static function normalizeIndustryPolicy(string $industryCode, array $entry): array
    {
        return [
            'industry_code' => trim($industryCode),
            'archive_promote_enabled' => self::normalizeBool($entry, 'archive_promote_enabled', false),
            'maintainer_role' => self::normalizeMaintainerRole($entry, 'maintainer_role', 'industry_maintainer'),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function normalizeBool(array $entry, string $field, bool $default): bool
    {
        if (!array_key_exists($field, $entry)) {
            return $default;
        }

        return (bool) $entry[$field];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function normalizeMaintainerRole(array $entry, string $field, string $default): string
    {
        if (!array_key_exists($field, $entry)) {
            return $default;
        }

        $trimmed = trim((string) $entry[$field]);

        return $trimmed !== '' ? $trimmed : $default;
    }

    private static function resolveConfigPath(): string
    {
        $override = getenv('BDS_SHARED_ARCHIVE_POLICY_PATH');
        if (is_string($override)) {
            $trimmed = trim($override);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bds_shared_archive_policy.php';
    }
}
