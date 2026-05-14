<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ErrorResponseBuilder.php';

/**
 * Phase 4 Stage 3 isolated service gate.
 * Intersects API key allowedServices with optional tenant allowed_services.
 * No SQL, no HTTP, not wired to production entry.
 */
final class AllowedServiceResolver
{
    private const MAX_SERVICE_LEN = 128;

    /**
     * @param array<string, mixed> $authenticatedContext From ApiKeyVerifier (includes allowedServices)
     * @param list<string>|null $tenantAllowedServices From tenantContext['allowedServices']; null skips tenant-level filter
     * @return array{
     *   ok: bool,
     *   httpStatus: int,
     *   errorCode: string|null,
     *   message: string|null,
     *   normalizedService: string|null
     * }
     */
    public function resolve(
        string $requestedService,
        array $authenticatedContext,
        ?array $tenantAllowedServices = null
    ): array {
        $raw = trim($requestedService);
        if ($raw === '') {
            return $this->errorResult(400, 'MISSING_SERVICE', 'Missing service identifier.');
        }

        if (strlen($raw) > self::MAX_SERVICE_LEN) {
            return $this->errorResult(400, 'INVALID_SERVICE_FORMAT', 'Service identifier is too long.');
        }

        if (!$this->isValidServiceToken($raw)) {
            return $this->errorResult(400, 'INVALID_SERVICE_FORMAT', 'Invalid service identifier format.');
        }

        $normalized = strtolower($raw);
        $keyAllowed = $authenticatedContext['allowedServices'] ?? [];
        if (!is_array($keyAllowed)) {
            $keyAllowed = [];
        }

        if (!in_array($normalized, $this->stringList($keyAllowed), true)) {
            return $this->errorResult(403, 'SERVICE_NOT_ALLOWED', 'Service is not allowed for this API key.');
        }

        if ($tenantAllowedServices !== null && $tenantAllowedServices !== []) {
            $tenantList = $this->stringList($tenantAllowedServices);
            if (!in_array($normalized, $tenantList, true)) {
                return $this->errorResult(403, 'SERVICE_NOT_ALLOWED_BY_TENANT', 'Service is not allowed for this tenant.');
            }
        }

        return [
            'ok' => true,
            'httpStatus' => 200,
            'errorCode' => null,
            'message' => null,
            'normalizedService' => $normalized,
        ];
    }

    /**
     * @param array{
     *   ok: bool,
     *   httpStatus: int,
     *   errorCode: string|null,
     *   message: string|null,
     *   normalizedService: string|null
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
            (string) ($resolverResult['message'] ?? 'Service resolution failed.'),
            $traceId,
            [
                'httpStatus' => (int) ($resolverResult['httpStatus'] ?? 500),
            ]
        );
    }

    /**
     * @param array<int|string, mixed> $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            if (!is_string($v)) {
                continue;
            }
            $t = strtolower(trim($v));
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return $out;
    }

    private function isValidServiceToken(string $service): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $service);
    }

    /**
     * @return array{
     *   ok: false,
     *   httpStatus: int,
     *   errorCode: string,
     *   message: string,
     *   normalizedService: null
     * }
     */
    private function errorResult(int $httpStatus, string $errorCode, string $message): array
    {
        return [
            'ok' => false,
            'httpStatus' => $httpStatus,
            'errorCode' => $errorCode,
            'message' => $message,
            'normalizedService' => null,
        ];
    }
}
