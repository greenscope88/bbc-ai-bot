<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'bds' . DIRECTORY_SEPARATOR . 'BdsSourceRegistryLoader.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'logger.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'ConfigTenantRegistry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'tenant' . DIRECTORY_SEPARATOR . 'ResolvedTenant.php';

class TenantResolver
{
    /**
     * @return array{sno: string, company_name: string, ai_tone: string, travel_specialties: string, price_catalog_json: string, channel_id: string}
     */
    public static function resolve(PDO $pdo, array $event, array $config): array
    {
        $channelId = isset($event['destination']) ? (string) $event['destination'] : '';

        $registryTenant = self::resolveViaRegistry($channelId);
        if ($registryTenant !== null) {
            return $registryTenant;
        }

        return self::resolveViaLegacy($pdo, $event, $config, $channelId);
    }

    /**
     * Phase 2A Stage 2: ConfigTenantRegistry first (same outward tenant[] shape).
     *
     * @return array{sno: string, company_name: string, ai_tone: string, travel_specialties: string, price_catalog_json: string, channel_id: string}|null
     */
    private static function resolveViaRegistry(string $channelId): ?array
    {
        if ($channelId === '') {
            return null;
        }

        try {
            $registry = new ConfigTenantRegistry();
            $resolved = $registry->resolveByChannel($channelId);
            if ($resolved === null || $resolved->getSno() === '') {
                self::logRegistryResolve($channelId, false, null, 'registry_miss');

                return null;
            }

            self::logRegistryResolve($channelId, true, $resolved, 'registry');

            return self::tenantArrayFromResolved($resolved, $channelId);
        } catch (\Throwable $e) {
            self::logRegistryResolve($channelId, false, null, 'registry_error', $e->getMessage());

            return null;
        }
    }

    /**
     * Legacy: tenant_context_map.php then SQL tenant_profiles.
     *
     * @return array{sno: string, company_name: string, ai_tone: string, travel_specialties: string, price_catalog_json: string, channel_id: string}
     */
    private static function resolveViaLegacy(PDO $pdo, array $event, array $config, string $channelId): array
    {
        $mapPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tenant_context_map.php';
        if ($channelId !== '' && is_file($mapPath)) {
            /** @var mixed $loaded */
            $loaded = require $mapPath;
            if (is_array($loaded) && isset($loaded[$channelId]) && is_array($loaded[$channelId])) {
                $entry = $loaded[$channelId];
                $mappedSno = isset($entry['sno']) ? trim((string) $entry['sno']) : '';
                if ($mappedSno !== '') {
                    self::logRegistryResolve($channelId, false, null, 'legacy_map');

                    return self::enrichTenantContext([
                        'sno' => $mappedSno,
                        'company_name' => isset($entry['company_name']) ? (string) $entry['company_name'] : '旅行社客服',
                        'ai_tone' => isset($entry['ai_tone']) ? (string) $entry['ai_tone'] : '親切',
                        'travel_specialties' => isset($entry['travel_specialties']) ? (string) $entry['travel_specialties'] : '綜合旅遊',
                        'price_catalog_json' => isset($entry['price_catalog_json']) ? (string) $entry['price_catalog_json'] : '{}',
                        'channel_id' => $channelId,
                    ]);
                }
            }
        }

        $sql = "SELECT TOP 1
                    t.sno,
                    t.company_name,
                    t.ai_tone,
                    t.travel_specialties,
                    t.price_catalog_json,
                    c.channel_id
                FROM tenant_profiles t
                INNER JOIN tenant_line_channels c ON c.sno = t.sno
                WHERE c.channel_id = :channel_id";

        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            self::logRegistryResolve($channelId, false, null, 'legacy_db_prepare_failed');

            return self::enrichTenantContext([
                'sno' => '',
                'company_name' => '旅行社客服',
                'ai_tone' => '親切',
                'travel_specialties' => '綜合旅遊',
                'price_catalog_json' => '{}',
                'channel_id' => $channelId,
            ]);
        }

        $stmt->bindValue(':channel_id', $channelId, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            self::logRegistryResolve($channelId, false, null, 'legacy_empty');

            return self::enrichTenantContext([
                'sno' => '',
                'company_name' => '旅行社客服',
                'ai_tone' => '親切',
                'travel_specialties' => '綜合旅遊',
                'price_catalog_json' => '{}',
                'channel_id' => $channelId,
            ]);
        }

        self::logRegistryResolve($channelId, false, null, 'legacy_db');

        return self::enrichTenantContext($row);
    }

    /**
     * @return array{sno: string, company_name: string, ai_tone: string, travel_specialties: string, price_catalog_json: string, channel_id: string}
     */
    private static function tenantArrayFromResolved(ResolvedTenant $resolved, string $channelId): array
    {
        $profile = $resolved->getProfile();

        return self::enrichTenantContext([
            'sno' => $resolved->getSno(),
            'company_name' => isset($profile['company_name']) ? (string) $profile['company_name'] : '旅行社客服',
            'ai_tone' => isset($profile['ai_tone']) ? (string) $profile['ai_tone'] : '親切',
            'travel_specialties' => isset($profile['travel_specialties']) ? (string) $profile['travel_specialties'] : '綜合旅遊',
            'price_catalog_json' => isset($profile['price_catalog_json']) ? (string) $profile['price_catalog_json'] : '{}',
            'channel_id' => $channelId !== '' ? $channelId : $resolved->getLineChannelId(),
        ], $resolved->getTenantKey());
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    private static function enrichTenantContext(array $tenant, string $tenantKey = ''): array
    {
        $tenant['tenant_key'] = trim($tenantKey);
        $sno = isset($tenant['sno']) ? trim((string) $tenant['sno']) : '';
        $tenant['industry_code'] = self::resolveIndustryCodeBySno($sno);

        return $tenant;
    }

    private static function resolveIndustryCodeBySno(string $sno): string
    {
        $sno = trim($sno);
        if ($sno === '') {
            return '';
        }

        $entry = BdsSourceRegistryLoader::loadBySno($sno);
        if ($entry === null) {
            return '';
        }

        return trim((string) ($entry['industry_code'] ?? ''));
    }

    private static function logRegistryResolve(
        string $channelId,
        bool $registryHit,
        ?ResolvedTenant $resolved,
        string $source,
        ?string $error = null
    ): void {
        $context = [
            'registry_hit' => $registryHit,
            'channel_id' => $channelId,
            'source' => $source,
        ];
        if ($resolved !== null) {
            $context['tenant_key'] = $resolved->getTenantKey();
            $context['sno'] = $resolved->getSno();
        }
        if ($error !== null && $error !== '') {
            $context['error'] = $error;
        }

        Logger::log('saas_router.log', 'tenant_registry_resolve', $context);
    }
}
