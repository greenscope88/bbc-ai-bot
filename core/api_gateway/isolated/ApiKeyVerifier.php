<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ErrorResponseBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ApiKeyRepository.php';

/**
 * Phase 4 Stage 2 isolated API key verifier.
 * Expects TenantMappingResolver result first; not wired to production entry.
 */
final class ApiKeyVerifier
{
    private ApiKeyRepository $repository;

    public function __construct(?ApiKeyRepository $repository = null)
    {
        $this->repository = $repository ?? new ApiKeyRepository();
    }

    /**
     * @param array{
     *   ok: bool,
     *   httpStatus: int,
     *   errorCode: string|null,
     *   message: string|null,
     *   tenantContext: array<string, mixed>|null
     * } $tenantResolveResult
     * @return array{
     *   ok: bool,
     *   httpStatus: int,
     *   errorCode: string|null,
     *   message: string|null,
     *   authenticatedContext: array<string, mixed>|null
     * }
     */
    public function verify(
        array $tenantResolveResult,
        ?string $rawApiKey,
        ?string $service,
        ?string $clientIp
    ): array {
        if (!($tenantResolveResult['ok'] ?? false)) {
            return $this->forwardTenantFailure($tenantResolveResult);
        }

        $tenantContext = $tenantResolveResult['tenantContext'] ?? null;
        if (!is_array($tenantContext)) {
            return $this->errorResult(500, 'GW_INTERNAL_ERROR', 'Missing tenant context.');
        }

        $inactive = $this->tenantInactiveGuard($tenantContext);
        if ($inactive !== null) {
            return $inactive;
        }

        $key = trim((string) $rawApiKey);
        if ($key === '') {
            return $this->errorResult(401, 'MISSING_API_KEY', 'Missing API key.');
        }

        if (!$this->isValidApiKeyFormat($key)) {
            return $this->errorResult(400, 'INVALID_API_KEY_FORMAT', 'Invalid API key format.');
        }

        $row = $this->repository->findByIncomingPlainKey($key);
        if ($row === null) {
            if ($this->repository->hasPrefixButHashMismatch($key)) {
                return $this->errorResult(401, 'API_KEY_HASH_MISMATCH', 'API key verification failed.');
            }

            return $this->errorResult(401, 'API_KEY_NOT_FOUND', 'API key not found.');
        }

        $rowSno = trim((string) ($row['sno'] ?? ''));
        $ctxSno = trim((string) ($tenantContext['sno'] ?? ''));
        if ($rowSno === '' || $ctxSno === '' || !hash_equals($rowSno, $ctxSno)) {
            return $this->errorResult(403, 'API_KEY_TENANT_MISMATCH', 'API key does not belong to this tenant.');
        }

        $status = strtolower(trim((string) ($row['key_status'] ?? '')));
        if ($status === 'disabled') {
            return $this->errorResult(403, 'API_KEY_DISABLED', 'API key is disabled.');
        }
        if ($status === 'revoked') {
            return $this->errorResult(403, 'API_KEY_REVOKED', 'API key is revoked.');
        }
        if ($status === 'expired' || $this->isExpiredByTimestamp($row)) {
            return $this->errorResult(401, 'API_KEY_EXPIRED', 'API key is expired.');
        }

        $allowedIps = $row['allowed_ips'] ?? [];
        if (is_array($allowedIps) && $allowedIps !== []) {
            $ip = trim((string) ($clientIp ?? ''));
            if ($ip === '' || !in_array($ip, array_map('strval', $allowedIps), true)) {
                return $this->errorResult(403, 'API_KEY_IP_NOT_ALLOWED', 'Client IP is not allowed for this API key.');
            }
        }

        $svc = $service !== null ? trim($service) : '';
        if ($svc !== '') {
            $allowedServices = $row['allowed_services'] ?? [];
            if (!is_array($allowedServices) || !in_array($svc, $allowedServices, true)) {
                return $this->errorResult(403, 'API_KEY_SERVICE_NOT_ALLOWED', 'API key is not allowed to call this service.');
            }
        }

        return [
            'ok' => true,
            'httpStatus' => 200,
            'errorCode' => null,
            'message' => null,
            'authenticatedContext' => $this->buildAuthenticatedContext($tenantContext, $row),
        ];
    }

    /**
     * @param array<string, mixed> $verifyResult
     * @return array<string, mixed>|null
     */
    public function toErrorPayload(array $verifyResult, string $traceId): ?array
    {
        if (($verifyResult['ok'] ?? false) === true) {
            return null;
        }

        return ErrorResponseBuilder::build(
            false,
            (string) ($verifyResult['errorCode'] ?? 'GW_INTERNAL_ERROR'),
            (string) ($verifyResult['message'] ?? 'API key verification failed.'),
            $traceId,
            [
                'httpStatus' => (int) ($verifyResult['httpStatus'] ?? 500),
            ]
        );
    }

    /**
     * @param array<string, mixed> $tenantContext
     * @param array<string, mixed> $keyRow
     * @return array<string, mixed>
     */
    private function buildAuthenticatedContext(array $tenantContext, array $keyRow): array
    {
        $allowedIps = $keyRow['allowed_ips'] ?? [];
        if (!is_array($allowedIps)) {
            $allowedIps = [];
        }

        $allowedServices = $keyRow['allowed_services'] ?? [];
        if (!is_array($allowedServices)) {
            $allowedServices = [];
        }

        return [
            'sno' => (string) ($tenantContext['sno'] ?? ''),
            'tenantName' => (string) ($tenantContext['tenantName'] ?? ''),
            'providerIdNo' => $tenantContext['providerIdNo'] ?? $tenantContext['provider_id_no'] ?? null,
            'depID' => $tenantContext['depID'] ?? null,
            'storeUid' => $tenantContext['storeUid'] ?? $tenantContext['store_uid'] ?? null,
            'storeNo' => $tenantContext['storeNo'] ?? null,
            'apiKeyId' => $keyRow['id'] ?? null,
            'apiKeyName' => (string) ($keyRow['key_name'] ?? ''),
            'apiKeyPrefix' => (string) ($keyRow['key_prefix'] ?? ''),
            'allowedServices' => $allowedServices,
            'allowedIps' => $allowedIps,
            'apiProfile' => (string) ($tenantContext['apiProfile'] ?? ''),
            'rateLimitProfile' => (string) ($tenantContext['rateLimitProfile'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $tenantContext
     * @return array<string, mixed>|null
     */
    private function tenantInactiveGuard(array $tenantContext): ?array
    {
        $enabled = $tenantContext['enabled'] ?? true;
        if ($enabled === false) {
            return $this->errorResult(403, 'TENANT_INACTIVE_WITH_ACTIVE_KEY', 'Tenant is inactive.');
        }

        $st = strtolower(trim((string) ($tenantContext['tenant_status'] ?? ($tenantContext['status'] ?? 'active'))));
        if ($st === 'disabled' || $st === 'suspended') {
            return $this->errorResult(403, 'TENANT_INACTIVE_WITH_ACTIVE_KEY', 'Tenant is inactive.');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isExpiredByTimestamp(array $row): bool
    {
        $expiresAt = $row['expires_at'] ?? null;
        if (!is_string($expiresAt) || trim($expiresAt) === '') {
            return false;
        }

        $ts = strtotime($expiresAt);
        if ($ts === false) {
            return false;
        }

        return $ts < time();
    }

    private function isValidApiKeyFormat(string $key): bool
    {
        if (strlen($key) < 20) {
            return false;
        }

        if (strpos($key, 'bbc_test_') === 0) {
            return true;
        }
        if (strpos($key, 'bbc_live_') === 0) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $tenantResolveResult
     * @return array<string, mixed>
     */
    private function forwardTenantFailure(array $tenantResolveResult): array
    {
        return [
            'ok' => false,
            'httpStatus' => (int) ($tenantResolveResult['httpStatus'] ?? 400),
            'errorCode' => isset($tenantResolveResult['errorCode']) ? (string) $tenantResolveResult['errorCode'] : 'TENANT_ERROR',
            'message' => isset($tenantResolveResult['message']) ? (string) $tenantResolveResult['message'] : 'Tenant resolution failed.',
            'authenticatedContext' => null,
        ];
    }

    /**
     * @return array{
     *   ok: false,
     *   httpStatus: int,
     *   errorCode: string,
     *   message: string,
     *   authenticatedContext: null
     * }
     */
    private function errorResult(int $httpStatus, string $errorCode, string $message): array
    {
        return [
            'ok' => false,
            'httpStatus' => $httpStatus,
            'errorCode' => $errorCode,
            'message' => $message,
            'authenticatedContext' => null,
        ];
    }
}
