<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ErrorResponseBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TenantMappingRepository.php';

/**
 * Phase 4 Stage 1 isolated resolver.
 * Not wired to production entry; intended for CLI/isolated tests.
 */
final class TenantMappingResolver
{
    private TenantMappingRepository $repository;

    public function __construct(?TenantMappingRepository $repository = null)
    {
        $this->repository = $repository ?? new TenantMappingRepository();
    }

    /**
     * @return array{
     *   ok: bool,
     *   httpStatus: int,
     *   errorCode: string|null,
     *   message: string|null,
     *   tenantContext: array<string, mixed>|null
     * }
     */
    public function resolve(?string $sno): array
    {
        $normalizedSno = trim((string) $sno);
        if ($normalizedSno === '') {
            return $this->errorResult(400, 'MISSING_SNO', 'Missing required sno.');
        }

        if (!$this->isValidSnoFormat($normalizedSno)) {
            return $this->errorResult(400, 'INVALID_SNO_FORMAT', 'Invalid sno format.');
        }

        $mapping = $this->repository->findBySno($normalizedSno);
        if ($mapping === null) {
            return $this->errorResult(404, 'TENANT_NOT_FOUND', 'No tenant mapping found for sno.');
        }

        $enabled = (bool) ($mapping['enabled'] ?? true);
        $tenantStatus = strtolower(trim((string) ($mapping['tenant_status'] ?? 'active')));

        if (!$enabled || $tenantStatus === 'disabled') {
            return $this->errorResult(403, 'TENANT_DISABLED', 'Tenant is disabled.');
        }

        if ($tenantStatus === 'suspended') {
            return $this->errorResult(403, 'TENANT_SUSPENDED', 'Tenant is suspended.');
        }

        return [
            'ok' => true,
            'httpStatus' => 200,
            'errorCode' => null,
            'message' => null,
            'tenantContext' => [
                'sno' => $normalizedSno,
                'tenantName' => (string) ($mapping['tenant_name'] ?? ''),
                'provider_id_no' => $mapping['provider_id_no'] ?? null,
                'depID' => $mapping['depID'] ?? null,
                'store_uid' => $mapping['store_uid'] ?? null,
                'storeNo' => $mapping['storeNo'] ?? null,
                'enabled' => $enabled,
                'tenant_status' => $tenantStatus,
                'providerIdNo' => $mapping['provider_id_no'] ?? null,
                'storeUid' => $mapping['store_uid'] ?? null,
                'status' => $tenantStatus,
                'allowedServices' => $mapping['allowed_services'] ?? [],
                'apiProfile' => (string) ($mapping['api_profile'] ?? ''),
                'rateLimitProfile' => (string) ($mapping['rate_limit_profile'] ?? ''),
            ],
        ];
    }

    /**
     * Build payload compatible with ErrorResponseBuilder.
     *
     * @param array{
     *   ok: bool,
     *   httpStatus: int,
     *   errorCode: string|null,
     *   message: string|null,
     *   tenantContext: array<string, mixed>|null
     * } $resolverResult
     * @return array<string, mixed>|null
     */
    public function toErrorPayload(array $resolverResult, string $traceId): ?array
    {
        if (($resolverResult['ok'] ?? false) === true) {
            return null;
        }

        return ErrorResponseBuilder::build(
            false,
            (string) ($resolverResult['errorCode'] ?? 'GW_INTERNAL_ERROR'),
            (string) ($resolverResult['message'] ?? 'Tenant resolve failed.'),
            $traceId,
            [
                'httpStatus' => (int) ($resolverResult['httpStatus'] ?? 500),
            ]
        );
    }

    /**
     * @return array{
     *   ok: false,
     *   httpStatus: int,
     *   errorCode: string,
     *   message: string,
     *   tenantContext: null
     * }
     */
    private function errorResult(int $httpStatus, string $errorCode, string $message): array
    {
        return [
            'ok' => false,
            'httpStatus' => $httpStatus,
            'errorCode' => $errorCode,
            'message' => $message,
            'tenantContext' => null,
        ];
    }

    private function isValidSnoFormat(string $sno): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,63}$/', $sno);
    }
}
