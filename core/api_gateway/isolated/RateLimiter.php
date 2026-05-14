<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ErrorResponseBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'RateLimitPolicyRepository.php';

/**
 * Phase 4 Stage 5 — in-memory sliding-window rate limiter (isolated).
 * Keys: sno + api_key_id + service + client_ip + window bucket.
 * No DB, Redis, file I/O, or Host B calls.
 */
final class RateLimiter
{
    private RateLimitPolicyRepository $policyRepository;

    /** @var array<string, int> */
    private array $windowCounts = [];

    public function __construct(?RateLimitPolicyRepository $policyRepository = null)
    {
        $this->policyRepository = $policyRepository ?? new RateLimitPolicyRepository();
    }

    /**
     * Clear all in-memory counters (for isolated tests).
     */
    public function resetCounters(): void
    {
        $this->windowCounts = [];
    }

    /**
     * @param array{
     *   traceId: string,
     *   tenantContext: array<string, mixed>|null,
     *   authenticatedContext: array<string, mixed>|null,
     *   service: string,
     *   clientIp: string,
     *   now?: float|int|null
     * } $gate
     * @return array{
     *   ok: bool,
     *   httpStatus: int,
     *   errorCode: string|null,
     *   message: string|null,
     *   policy: array<string, mixed>|null,
     *   hitWindow: string|null
     * }
     */
    public function consume(array $gate): array
    {
        $traceId = trim((string) ($gate['traceId'] ?? ''));
        if ($traceId === '') {
            return $this->fail(400, 'MISSING_TRACE_ID', 'Missing trace id.', null, null);
        }

        $tenant = $gate['tenantContext'] ?? null;
        if (!is_array($tenant)) {
            return $this->fail(400, 'MISSING_TENANT_CONTEXT', 'Missing tenant context.', null, null);
        }

        $auth = $gate['authenticatedContext'] ?? null;
        if (!is_array($auth)) {
            return $this->fail(400, 'MISSING_AUTHENTICATED_CONTEXT', 'Missing authenticated context.', null, null);
        }

        $sno = trim((string) ($tenant['sno'] ?? ''));
        if ($sno === '') {
            return $this->fail(400, 'MISSING_TENANT_CONTEXT', 'Missing sno on tenant context.', null, null);
        }

        $apiKeyId = $auth['apiKeyId'] ?? $auth['api_key_id'] ?? null;
        if ($apiKeyId === null || (is_string($apiKeyId) && trim($apiKeyId) === '')) {
            return $this->fail(400, 'MISSING_AUTHENTICATED_CONTEXT', 'Missing api key id on authenticated context.', null, null);
        }

        $service = trim((string) ($gate['service'] ?? ''));
        if ($service === '') {
            return $this->fail(400, 'MISSING_SERVICE', 'Missing service.', null, null);
        }

        $clientIp = trim((string) ($gate['clientIp'] ?? ''));
        if ($clientIp === '') {
            return $this->fail(400, 'MISSING_CLIENT_IP', 'Missing client IP.', null, null);
        }

        $nowRaw = $gate['now'] ?? null;
        $now = is_numeric($nowRaw) ? (float) $nowRaw : microtime(true);

        $policy = $this->policyRepository->resolvePolicy($sno, $apiKeyId, $service);
        if ($policy === null) {
            return $this->fail(500, 'GW_INTERNAL_ERROR', 'No rate limit policy resolved.', null, null);
        }

        $status = $this->normalizePolicyStatus((string) ($policy['policy_status'] ?? ''));
        if ($status === 'disabled') {
            return $this->fail(403, 'RATE_LIMIT_POLICY_DISABLED', 'Rate limit policy is disabled for this route.', $policy, null);
        }

        $baseKey = $this->composeBaseKey($sno, $apiKeyId, $service, $clientIp);

        $windows = $this->buildWindowDescriptors($now, $policy);
        foreach ($windows as $w) {
            $storageKey = $baseKey . '|' . $w['kind'] . '|' . (string) $w['bucket'];
            $current = $this->windowCounts[$storageKey] ?? 0;
            $limit = $w['limit'];
            if ($limit < 0) {
                continue;
            }
            if ($current + 1 > $limit) {
                return $this->fail(429, 'RATE_LIMIT_EXCEEDED', 'Rate limit exceeded. Please retry later.', $policy, (string) $w['kind']);
            }
        }

        foreach ($windows as $w) {
            $storageKey = $baseKey . '|' . $w['kind'] . '|' . (string) $w['bucket'];
            if (!isset($this->windowCounts[$storageKey])) {
                $this->windowCounts[$storageKey] = 0;
            }
            $this->windowCounts[$storageKey]++;
        }

        return [
            'ok' => true,
            'httpStatus' => 200,
            'errorCode' => null,
            'message' => null,
            'policy' => $policy,
            'hitWindow' => null,
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
            (string) ($result['message'] ?? 'Rate limit check failed.'),
            $traceId,
            [
                'httpStatus' => (int) ($result['httpStatus'] ?? 500),
                'policyId' => isset($result['policy']['policy_id']) ? $result['policy']['policy_id'] : null,
                'hitWindow' => $result['hitWindow'] ?? null,
            ]
        );
    }

    /**
     * @param array<string, mixed>|null $policy
     * @return array<string, mixed>
     */
    private function fail(int $httpStatus, string $errorCode, string $message, ?array $policy, ?string $hitWindow): array
    {
        return [
            'ok' => false,
            'httpStatus' => $httpStatus,
            'errorCode' => $errorCode,
            'message' => $message,
            'policy' => $policy,
            'hitWindow' => $hitWindow,
        ];
    }

    /**
     * @return list<array{kind: string, bucket: string|int|float, limit: int}>
     */
    private function buildWindowDescriptors(float $now, array $policy): array
    {
        $burst = $this->intLimit($policy['burst_limit'] ?? null);
        $perMin = $this->intLimit($policy['limit_per_minute'] ?? null);
        $perHour = $this->intLimit($policy['limit_per_hour'] ?? null);
        $perDay = $this->intLimit($policy['limit_per_day'] ?? null);

        $secondBucket = (int) floor($now);
        $minuteBucket = (int) (floor($now / 60.0) * 60.0);
        $hourBucket = (int) (floor($now / 3600.0) * 3600.0);
        $dayBucket = $this->utcDayBucketId($now);

        $out = [];
        if ($burst >= 0) {
            $out[] = ['kind' => 'burst', 'bucket' => $secondBucket, 'limit' => $burst];
        }
        if ($perMin >= 0) {
            $out[] = ['kind' => '1m', 'bucket' => $minuteBucket, 'limit' => $perMin];
        }
        if ($perHour >= 0) {
            $out[] = ['kind' => '1h', 'bucket' => $hourBucket, 'limit' => $perHour];
        }
        if ($perDay >= 0) {
            $out[] = ['kind' => '1d', 'bucket' => $dayBucket, 'limit' => $perDay];
        }

        return $out;
    }

    /**
     * @param mixed $v
     */
    private function intLimit($v): int
    {
        if ($v === null) {
            return -1;
        }
        if (!is_numeric($v)) {
            return -1;
        }

        return (int) $v;
    }

    private function utcDayBucketId(float $now): string
    {
        $dt = new \DateTimeImmutable('@' . (string) (int) floor($now), new \DateTimeZone('UTC'));

        return $dt->format('Y-m-d');
    }

    /**
     * @param mixed $apiKeyId
     */
    private function composeBaseKey(string $sno, $apiKeyId, string $service, string $clientIp): string
    {
        return $sno . '|' . (string) $apiKeyId . '|' . $service . '|' . $clientIp;
    }

    private function normalizePolicyStatus(string $raw): string
    {
        return strtolower(trim($raw));
    }
}
