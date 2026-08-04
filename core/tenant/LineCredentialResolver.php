<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConfigTenantRegistry.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logger.php';

/**
 * Resolve tenant-specific LINE credentials by channelId (destination).
 *
 * Security:
 * - Never logs secret/token values.
 * - Fail-closed when registry hit but secret/token missing (no cross-tenant fallback).
 */
final class LineCredentialResolver
{
    private const ENV_SECRET_PREFIX = 'LINE_CHANNEL_SECRET__';
    private const ENV_TOKEN_PREFIX = 'LINE_CHANNEL_ACCESS_TOKEN__';

    /**
     * @return array{
     *   ok: bool,
     *   tenant_key: string|null,
     *   channel_secret: string|null,
     *   channel_access_token: string|null,
     *   errorCode: string|null,
     *   registry_hit: bool,
     *   missing_keys: list<string>
     * }
     */
    public static function resolveByChannelId(string $channelId): array
    {
        $channelId = trim($channelId);
        if ($channelId === '') {
            return [
                'ok' => false,
                'tenant_key' => null,
                'channel_secret' => null,
                'channel_access_token' => null,
                'errorCode' => 'MISSING_CHANNEL_ID',
                'registry_hit' => false,
                'missing_keys' => [],
            ];
        }

        try {
            $registry = new ConfigTenantRegistry();
            $tenant = $registry->resolveByChannel($channelId);
            if ($tenant === null) {
                self::logDecision(null, false, $channelId, [], 'TENANT_NOT_FOUND');
                return [
                    'ok' => false,
                    'tenant_key' => null,
                    'channel_secret' => null,
                    'channel_access_token' => null,
                    'errorCode' => 'TENANT_NOT_FOUND',
                    'registry_hit' => false,
                    'missing_keys' => [],
                ];
            }

            $tenantKey = $tenant->getTenantKey();
            $prefix = trim($tenant->getCredentialEnvPrefix());
            if ($prefix === '') {
                self::logDecision($tenantKey, true, $channelId, ['credential_env_prefix'], 'MISSING_CREDENTIAL_ENV_PREFIX');
                return [
                    'ok' => false,
                    'tenant_key' => $tenantKey,
                    'channel_secret' => null,
                    'channel_access_token' => null,
                    'errorCode' => 'MISSING_CREDENTIAL_ENV_PREFIX',
                    'registry_hit' => true,
                    'missing_keys' => ['credential_env_prefix'],
                ];
            }

            $secretKey = self::ENV_SECRET_PREFIX . $prefix;
            $tokenKey = self::ENV_TOKEN_PREFIX . $prefix;

            $missing = [];
            $secret = self::getEnvNonEmpty($secretKey);
            if ($secret === null) {
                $missing[] = $secretKey;
            }
            $token = self::getEnvNonEmpty($tokenKey);
            if ($token === null) {
                $missing[] = $tokenKey;
            }

            if ($missing !== []) {
                self::logDecision($tenantKey, true, $channelId, $missing, 'MISSING_CREDENTIALS');
                return [
                    'ok' => false,
                    'tenant_key' => $tenantKey,
                    'channel_secret' => null,
                    'channel_access_token' => null,
                    'errorCode' => 'MISSING_CREDENTIALS',
                    'registry_hit' => true,
                    'missing_keys' => $missing,
                ];
            }

            self::logDecision($tenantKey, true, $channelId, [], null);
            return [
                'ok' => true,
                'tenant_key' => $tenantKey,
                'channel_secret' => $secret,
                'channel_access_token' => $token,
                'errorCode' => null,
                'registry_hit' => true,
                'missing_keys' => [],
            ];
        } catch (\Throwable $e) {
            self::logDecision(null, false, $channelId, [], 'EXCEPTION', $e->getMessage());
            return [
                'ok' => false,
                'tenant_key' => null,
                'channel_secret' => null,
                'channel_access_token' => null,
                'errorCode' => 'EXCEPTION',
                'registry_hit' => false,
                'missing_keys' => [],
            ];
        }
    }

    /**
     * Resolve Messaging API access token by credential_env_prefix only.
     * Used by Admin Bot Info identity derivation when channelId is unknown or wrong.
     * Never logs token/secret values.
     *
     * @return array{
     *   ok: bool,
     *   channel_access_token: string|null,
     *   errorCode: string|null,
     *   missing_keys: list<string>
     * }
     */
    public static function resolveAccessTokenByCredentialEnvPrefix(string $credentialEnvPrefix): array
    {
        $prefix = trim($credentialEnvPrefix);
        if ($prefix === '') {
            return [
                'ok' => false,
                'channel_access_token' => null,
                'errorCode' => 'MISSING_CREDENTIAL_ENV_PREFIX',
                'missing_keys' => ['credential_env_prefix'],
            ];
        }

        $secretKey = self::ENV_SECRET_PREFIX . $prefix;
        $tokenKey = self::ENV_TOKEN_PREFIX . $prefix;
        $missing = [];
        $secret = self::getEnvNonEmpty($secretKey);
        if ($secret === null) {
            $missing[] = $secretKey;
        }
        $token = self::getEnvNonEmpty($tokenKey);
        if ($token === null) {
            $missing[] = $tokenKey;
        }
        if ($missing !== []) {
            self::logDecision(null, false, 'prefix:' . $prefix, $missing, 'MISSING_CREDENTIALS');

            return [
                'ok' => false,
                'channel_access_token' => null,
                'errorCode' => 'MISSING_CREDENTIALS',
                'missing_keys' => $missing,
            ];
        }

        self::logDecision(null, true, 'prefix:' . $prefix, [], null);

        return [
            'ok' => true,
            'channel_access_token' => $token,
            'errorCode' => null,
            'missing_keys' => [],
        ];
    }

    private static function getEnvNonEmpty(string $key): ?string
    {
        $val = getenv($key);
        if (!is_string($val)) {
            return null;
        }
        $val = trim($val);
        return $val !== '' ? $val : null;
    }

    /**
     * @param list<string> $missingKeys
     */
    private static function logDecision(
        ?string $tenantKey,
        bool $registryHit,
        string $channelId,
        array $missingKeys,
        ?string $errorCode,
        ?string $detail = null
    ): void {
        $ctx = [
            'tenant_key' => $tenantKey,
            'registry_hit' => $registryHit,
            'channel_id' => $channelId,
            'credential_source' => 'env',
            'missing_keys' => $missingKeys,
            'errorCode' => $errorCode,
        ];
        if ($detail !== null && $detail !== '') {
            $ctx['detail'] = $detail;
        }

        Logger::log('saas_router.log', 'line_credential_resolver', $ctx);
    }
}

