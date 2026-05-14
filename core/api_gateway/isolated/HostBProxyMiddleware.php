<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ErrorResponseBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AllowedServiceResolver.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'HostBRequestBuilder.php';

/**
 * Phase 4 Stage 3 isolated Host B proxy orchestration.
 * Chains tenant + API key outcomes, service gate, request build, and injectable mock response.
 * No curl, no SQL, not wired to production entry.
 */
final class HostBProxyMiddleware
{
    private AllowedServiceResolver $serviceResolver;

    private HostBRequestBuilder $requestBuilder;

    /** @var callable(array<string, mixed>): array<string, mixed> */
    private $mockResponder;

    /**
     * @param callable(array<string, mixed>): array<string, mixed>|null $mockResponder Receives built hostb request array; returns mock Host B envelope
     */
    public function __construct(
        ?AllowedServiceResolver $serviceResolver = null,
        ?HostBRequestBuilder $requestBuilder = null,
        ?callable $mockResponder = null
    ) {
        $this->serviceResolver = $serviceResolver ?? new AllowedServiceResolver();
        $this->requestBuilder = $requestBuilder ?? new HostBRequestBuilder();
        $this->mockResponder = $mockResponder ?? [$this, 'defaultMockResponder'];
    }

    /**
     * @param array{
     *   traceId: string,
     *   httpMethod: string,
     *   service: string,
     *   tenantResolve: array<string, mixed>,
     *   keyVerify: array<string, mixed>,
     *   tenantAllowedServices?: list<string>|null,
     *   jsonBody?: array<string, mixed>|null,
     *   query?: array<string, string>|null
     * } $gate
     * @return array{
     *   ok: bool,
     *   httpStatus: int,
     *   errorCode: string|null,
     *   message: string|null,
     *   stage: string|null,
     *   hostbRequest: array<string, mixed>|null,
     *   hostbHttp: array<string, mixed>|null
     * }
     */
    public function handle(array $gate): array
    {
        $traceId = trim((string) ($gate['traceId'] ?? ''));
        if ($traceId === '') {
            return $this->failure('gateway', 400, 'MISSING_TRACE_ID', 'Missing trace id.');
        }

        $tenant = $gate['tenantResolve'] ?? [];
        if (!is_array($tenant) || !($tenant['ok'] ?? false)) {
            return $this->forwardTenantFailure($tenant, 'tenant');
        }

        $key = $gate['keyVerify'] ?? [];
        if (!is_array($key) || !($key['ok'] ?? false)) {
            return $this->forwardKeyFailure($key, 'api_key');
        }

        $authCtx = $key['authenticatedContext'] ?? null;
        if (!is_array($authCtx)) {
            return $this->failure('api_key', 500, 'GW_INTERNAL_ERROR', 'Missing authenticated context.');
        }

        $tenantCtx = $tenant['tenantContext'] ?? null;
        $tenantAllowed = $gate['tenantAllowedServices'] ?? null;
        if ($tenantAllowed === null && is_array($tenantCtx)) {
            $tenantAllowed = $tenantCtx['allowedServices'] ?? null;
        }

        $service = (string) ($gate['service'] ?? '');
        $svcResult = $this->serviceResolver->resolve($service, $authCtx, is_array($tenantAllowed) ? $tenantAllowed : null);
        if (!($svcResult['ok'] ?? false)) {
            return $this->forwardServiceFailure($svcResult, 'service');
        }

        $normalized = (string) ($svcResult['normalizedService'] ?? '');
        $method = (string) ($gate['httpMethod'] ?? 'POST');
        $jsonBody = isset($gate['jsonBody']) && is_array($gate['jsonBody']) ? $gate['jsonBody'] : null;
        $query = isset($gate['query']) && is_array($gate['query']) ? $gate['query'] : null;

        $built = $this->requestBuilder->build(
            $traceId,
            $normalized,
            $method,
            $authCtx,
            $jsonBody,
            $query
        );

        if (!($built['ok'] ?? false)) {
            return $this->failure(
                'request_build',
                (int) ($built['httpStatus'] ?? 400),
                (string) ($built['errorCode'] ?? 'GW_INTERNAL_ERROR'),
                (string) ($built['message'] ?? 'Request build failed.')
            );
        }

        $req = $built['request'] ?? null;
        if (!is_array($req)) {
            return $this->failure('request_build', 500, 'GW_INTERNAL_ERROR', 'Missing built request.');
        }

        $responder = $this->mockResponder;
        $hostbHttp = $responder($req);
        if (!is_array($hostbHttp)) {
            return $this->failure('hostb_mock', 500, 'GW_INTERNAL_ERROR', 'Mock Host B returned invalid response.');
        }

        return [
            'ok' => true,
            'httpStatus' => 200,
            'errorCode' => null,
            'message' => null,
            'stage' => 'hostb_mock',
            'hostbRequest' => $req,
            'hostbHttp' => $hostbHttp,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>|null
     */
    public function toErrorPayload(array $result, string $traceId): ?array
    {
        if (($result['ok'] ?? false) === true) {
            return null;
        }

        return ErrorResponseBuilder::build(
            false,
            (string) ($result['errorCode'] ?? 'GW_INTERNAL_ERROR'),
            (string) ($result['message'] ?? 'Host B proxy failed.'),
            $traceId,
            [
                'httpStatus' => (int) ($result['httpStatus'] ?? 500),
                'stage' => $result['stage'] ?? null,
            ]
        );
    }

    /**
     * Default mock: deterministic JSON body, no network.
     *
     * @param array<string, mixed> $hostbRequest
     * @return array<string, mixed>
     */
    public function defaultMockResponder(array $hostbRequest): array
    {
        $headers = $hostbRequest['headers'] ?? [];
        $body = $hostbRequest['body'] ?? [];
        $svc = is_array($body) ? ($body['service'] ?? '') : '';
        $method = is_array($body) ? ($body['httpMethod'] ?? '') : '';
        $trace = is_array($headers) ? (string) ($headers['X-Trace-Id'] ?? '') : '';

        $payload = [
            'success' => true,
            'mock' => true,
            'service' => is_string($svc) ? $svc : '',
            'httpMethod' => is_string($method) ? strtoupper($method) : '',
            'traceId' => $trace,
            'data' => [
                'echo' => [
                    'path' => $hostbRequest['path'] ?? null,
                ],
            ],
        ];

        return [
            'httpStatus' => 200,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    private function forwardTenantFailure(array $tenant, string $stage): array
    {
        return [
            'ok' => false,
            'httpStatus' => (int) ($tenant['httpStatus'] ?? 400),
            'errorCode' => isset($tenant['errorCode']) ? (string) $tenant['errorCode'] : 'TENANT_ERROR',
            'message' => isset($tenant['message']) ? (string) $tenant['message'] : 'Tenant resolution failed.',
            'stage' => $stage,
            'hostbRequest' => null,
            'hostbHttp' => null,
        ];
    }

    /**
     * @param array<string, mixed> $key
     * @return array<string, mixed>
     */
    private function forwardKeyFailure(array $key, string $stage): array
    {
        return [
            'ok' => false,
            'httpStatus' => (int) ($key['httpStatus'] ?? 401),
            'errorCode' => isset($key['errorCode']) ? (string) $key['errorCode'] : 'API_KEY_ERROR',
            'message' => isset($key['message']) ? (string) $key['message'] : 'API key verification failed.',
            'stage' => $stage,
            'hostbRequest' => null,
            'hostbHttp' => null,
        ];
    }

    /**
     * @param array<string, mixed> $svcResult
     * @return array<string, mixed>
     */
    private function forwardServiceFailure(array $svcResult, string $stage): array
    {
        return [
            'ok' => false,
            'httpStatus' => (int) ($svcResult['httpStatus'] ?? 403),
            'errorCode' => isset($svcResult['errorCode']) ? (string) $svcResult['errorCode'] : 'SERVICE_ERROR',
            'message' => isset($svcResult['message']) ? (string) $svcResult['message'] : 'Service not allowed.',
            'stage' => $stage,
            'hostbRequest' => null,
            'hostbHttp' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $stage, int $httpStatus, string $errorCode, string $message): array
    {
        return [
            'ok' => false,
            'httpStatus' => $httpStatus,
            'errorCode' => $errorCode,
            'message' => $message,
            'stage' => $stage,
            'hostbRequest' => null,
            'hostbHttp' => null,
        ];
    }
}
