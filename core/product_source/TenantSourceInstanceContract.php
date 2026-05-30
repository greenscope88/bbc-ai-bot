<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ProductSourceContractException.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SourcePlatformContract.php';

/**
 * Tenant source instance contract (Phase 9-B-8).
 *
 * Binds a tenant (tenant_sno) to a platform with tenant-specific identifier values.
 */
final class TenantSourceInstanceContract
{
    public const SCHEMA_VERSION = 1;

    public const SOURCE_INSTANCE_ID_PATTERN = '/^[a-z][a-z0-9_]{0,127}$/';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $document
     * @throws ProductSourceContractException
     */
    public static function validateInstance(array $document, ?array $platform = null): void
    {
        $violations = self::collectInstanceViolations($document, $platform);
        if ($violations !== []) {
            throw new ProductSourceContractException(
                'TENANT_SOURCE_INSTANCE_CONTRACT_INVALID',
                'Tenant source instance contract invalid (' . count($violations) . ' issue(s)): ' . implode('; ', $violations)
            );
        }
    }

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    public static function collectInstanceViolations(array $document, ?array $platform = null): array
    {
        $violations = [];

        $instanceId = isset($document['source_instance_id']) ? trim((string) $document['source_instance_id']) : '';
        if ($instanceId === '') {
            $violations[] = 'missing source_instance_id';
        } elseif (!preg_match(self::SOURCE_INSTANCE_ID_PATTERN, $instanceId)) {
            $violations[] = 'invalid source_instance_id format';
        }

        $tenantSno = isset($document['tenant_sno']) ? trim((string) $document['tenant_sno']) : '';
        if ($tenantSno === '') {
            $violations[] = 'missing tenant_sno';
        }

        $platformId = isset($document['platform_id']) ? trim((string) $document['platform_id']) : '';
        if ($platformId === '') {
            $violations[] = 'missing platform_id';
        }

        if ($platform !== null) {
            $expectedPlatformId = isset($platform['platform_id']) ? trim((string) $platform['platform_id']) : '';
            if ($expectedPlatformId !== '' && $platformId !== $expectedPlatformId) {
                $violations[] = 'platform_id mismatch with platform definition';
            }
        }

        $identifierType = isset($document['identifier_type']) ? trim((string) $document['identifier_type']) : '';
        if ($identifierType === '' || !SourcePlatformContract::isValidIdentifierType($identifierType)) {
            $violations[] = 'invalid identifier_type';
        } elseif ($platform !== null) {
            $platformIdentifierType = isset($platform['identifier_type']) ? trim((string) $platform['identifier_type']) : '';
            if ($platformIdentifierType !== '' && $identifierType !== $platformIdentifierType) {
                $violations[] = 'identifier_type mismatch with platform definition';
            }
        }

        if (!isset($document['enabled']) || !is_bool($document['enabled'])) {
            $violations[] = 'enabled must be boolean';
        }

        if (!isset($document['priority']) || !is_int($document['priority'])) {
            $violations[] = 'priority must be integer';
        } elseif ((int) $document['priority'] < 0 || (int) $document['priority'] > 9999) {
            $violations[] = 'priority out of range (0-9999)';
        }

        $identifierValues = $document['identifier_values'] ?? null;
        if (!is_array($identifierValues)) {
            $violations[] = 'identifier_values must be an object';
        } elseif ($identifierType !== '') {
            $violations = array_merge(
                $violations,
                self::collectIdentifierValueViolations($identifierType, $identifierValues, $platform)
            );
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $identifierValues
     * @param array<string, mixed>|null $platform
     * @return list<string>
     */
    public static function collectIdentifierValueViolations(
        string $identifierType,
        array $identifierValues,
        ?array $platform = null
    ): array {
        $violations = [];
        $type = trim($identifierType);

        if ($type === 'subdomain') {
            $subdomain = isset($identifierValues['subdomain']) ? trim((string) $identifierValues['subdomain']) : '';
            if ($subdomain === '') {
                $violations[] = 'subdomain identifier_values.subdomain required';
            }
        } elseif ($type === 'query_param') {
            $paramKeys = self::resolveParamKeys($platform, ['GetStore']);
            $violations = array_merge($violations, self::validateRequiredParamKeys($identifierValues, $paramKeys, 'query_param'));
        } elseif ($type === 'affiliate_param') {
            $paramKeys = self::resolveParamKeys($platform, ['Allianceid', 'SID']);
            $violations = array_merge($violations, self::validateRequiredParamKeys($identifierValues, $paramKeys, 'affiliate_param'));
        } elseif ($type === 'fixed_url') {
            $url = isset($identifierValues['url']) ? trim((string) $identifierValues['url']) : '';
            if ($url === '') {
                $violations[] = 'fixed_url identifier_values.url required';
            } elseif (stripos($url, 'http://') !== 0 && stripos($url, 'https://') !== 0) {
                $violations[] = 'fixed_url identifier_values.url must start with http:// or https://';
            }
        } elseif ($type === 'custom') {
            if ($identifierValues === []) {
                $violations[] = 'custom identifier_values must not be empty';
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $identifierValues
     * @param list<string> $requiredKeys
     * @return list<string>
     */
    private static function validateRequiredParamKeys(array $identifierValues, array $requiredKeys, string $label): array
    {
        $violations = [];
        foreach ($requiredKeys as $key) {
            $value = isset($identifierValues[$key]) ? trim((string) $identifierValues[$key]) : '';
            if ($value === '') {
                $violations[] = $label . ' identifier_values.' . $key . ' required';
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed>|null $platform
     * @param list<string> $defaultKeys
     * @return list<string>
     */
    private static function resolveParamKeys(?array $platform, array $defaultKeys): array
    {
        if ($platform === null || !isset($platform['identifier_param_keys']) || !is_array($platform['identifier_param_keys'])) {
            return $defaultKeys;
        }

        $keys = [];
        foreach ($platform['identifier_param_keys'] as $key) {
            if (is_string($key) && trim($key) !== '') {
                $keys[] = trim($key);
            }
        }

        return $keys !== [] ? $keys : $defaultKeys;
    }
}
