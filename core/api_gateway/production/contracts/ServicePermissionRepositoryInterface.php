<?php
declare(strict_types=1);

/**
 * Production contract: api_gateway_service_permissions (optional table) or facade reads.
 *
 * @return list<array<string, mixed>>
 */
interface ServicePermissionRepositoryInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listRulesForTenantAndKey(string $sno, $apiKeyId): array;
}
