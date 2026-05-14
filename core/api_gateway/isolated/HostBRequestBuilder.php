<?php
declare(strict_types=1);

/**
 * Phase 4 Stage 3 isolated outbound request descriptor for Host B.
 * Returns method/path/headers/body only — no network, no absolute URLs.
 */
final class HostBRequestBuilder
{
    /** Relative path only (no scheme/host). */
    private const FORWARD_PATH = '/__gateway__/isolated/hostb/forward';

    /** @var list<string> */
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param array<string, mixed> $authenticatedContext From ApiKeyVerifier
     * @param array<string, mixed>|null $jsonBody Optional client JSON body (decoded array)
     * @param array<string, string>|null $queryStringParams Flat query key => value
     * @return array{
     *   ok: bool,
     *   httpStatus: int,
     *   errorCode: string|null,
     *   message: string|null,
     *   request: array<string, mixed>|null
     * }
     */
    public function build(
        string $traceId,
        string $normalizedService,
        string $httpMethod,
        array $authenticatedContext,
        ?array $jsonBody = null,
        ?array $queryStringParams = null
    ): array {
        $tid = trim($traceId);
        if ($tid === '') {
            return $this->errorResult(400, 'MISSING_TRACE_ID', 'Missing trace id.');
        }

        $method = strtoupper(trim($httpMethod));
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return $this->errorResult(400, 'INVALID_HTTP_METHOD', 'Invalid HTTP method for Host B proxy.');
        }

        $svc = strtolower(trim($normalizedService));
        if ($svc === '') {
            return $this->errorResult(400, 'MISSING_SERVICE', 'Missing service identifier.');
        }

        $headers = [
            'Content-Type' => 'application/json',
            'X-Trace-Id' => $tid,
            'X-Gateway-Service' => $svc,
            'X-Gateway-Sno' => (string) ($authenticatedContext['sno'] ?? ''),
        ];

        $body = [
            'gatewayVersion' => 1,
            'service' => $svc,
            'httpMethod' => $method,
            'tenant' => [
                'sno' => $authenticatedContext['sno'] ?? null,
                'tenantName' => $authenticatedContext['tenantName'] ?? null,
                'providerIdNo' => $authenticatedContext['providerIdNo'] ?? $authenticatedContext['provider_id_no'] ?? null,
                'depID' => $authenticatedContext['depID'] ?? null,
                'storeUid' => $authenticatedContext['storeUid'] ?? $authenticatedContext['store_uid'] ?? null,
                'storeNo' => $authenticatedContext['storeNo'] ?? null,
                'apiProfile' => $authenticatedContext['apiProfile'] ?? null,
                'rateLimitProfile' => $authenticatedContext['rateLimitProfile'] ?? null,
            ],
            'caller' => [
                'apiKeyId' => $authenticatedContext['apiKeyId'] ?? null,
                'apiKeyPrefix' => $authenticatedContext['apiKeyPrefix'] ?? null,
            ],
            'clientPayload' => $jsonBody ?? new \stdClass(),
            'query' => $this->normalizeQuery($queryStringParams),
        ];

        $request = [
            'method' => $method,
            'path' => self::FORWARD_PATH,
            'headers' => $headers,
            'body' => $body,
        ];

        return [
            'ok' => true,
            'httpStatus' => 200,
            'errorCode' => null,
            'message' => null,
            'request' => $request,
        ];
    }

    /**
     * @return array{
     *   ok: false,
     *   httpStatus: int,
     *   errorCode: string,
     *   message: string,
     *   request: null
     * }
     */
    private function errorResult(int $httpStatus, string $errorCode, string $message): array
    {
        return [
            'ok' => false,
            'httpStatus' => $httpStatus,
            'errorCode' => $errorCode,
            'message' => $message,
            'request' => null,
        ];
    }

    /**
     * @param array<string, string>|null $queryStringParams
     * @return array<string, string>
     */
    private function normalizeQuery(?array $queryStringParams): array
    {
        if ($queryStringParams === null) {
            return [];
        }

        $out = [];
        foreach ($queryStringParams as $k => $v) {
            if (!is_string($k) || trim($k) === '') {
                continue;
            }
            if (!is_scalar($v)) {
                continue;
            }
            $out[trim($k)] = trim((string) $v);
        }

        return $out;
    }
}
