<?php
declare(strict_types=1);

/**
 * Phase 4 Stage 4 — builds and validates gateway audit log records (isolated).
 * No DB, no file I/O, no production wiring.
 */
final class AuditLogRecordBuilder
{
    /** @var list<string> */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'passwd',
        'token',
        'cookie',
        'set-cookie',
        'authorization',
        'api_key',
        'apikey',
        'secret',
        'refresh_token',
        'accesstoken',
        'access_token',
        'client_secret',
    ];

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, record: array<string, mixed>|null, error: string|null}
     */
    public function build(array $input): array
    {
        $traceId = trim((string) ($input['trace_id'] ?? ''));
        if ($traceId === '') {
            return ['ok' => false, 'record' => null, 'error' => 'MISSING_TRACE_ID'];
        }

        $tenantCtx = $this->optionalArray($input['tenant_context'] ?? null);
        $authCtx = $this->optionalArray($input['authenticated_context'] ?? null);

        $sno = $this->stringOrNull($input['sno'] ?? null);
        if ($sno === null && $tenantCtx !== null) {
            $sno = $this->stringOrNull($tenantCtx['sno'] ?? null);
        }
        if ($sno === null && $authCtx !== null) {
            $sno = $this->stringOrNull($authCtx['sno'] ?? null);
        }

        $providerIdNo = $this->normalizeNullableScalar($input['provider_id_no'] ?? null);
        if ($providerIdNo === null && $tenantCtx !== null) {
            $providerIdNo = $this->normalizeNullableScalar($tenantCtx['provider_id_no'] ?? $tenantCtx['providerIdNo'] ?? null);
        }
        if ($providerIdNo === null && $authCtx !== null) {
            $providerIdNo = $this->normalizeNullableScalar($authCtx['providerIdNo'] ?? $authCtx['provider_id_no'] ?? null);
        }

        $depId = $this->normalizeNullableScalar($input['depID'] ?? null);
        if ($depId === null && $tenantCtx !== null) {
            $depId = $this->normalizeNullableScalar($tenantCtx['depID'] ?? null);
        }
        if ($depId === null && $authCtx !== null) {
            $depId = $this->normalizeNullableScalar($authCtx['depID'] ?? null);
        }

        $storeUid = $this->normalizeNullableScalar($input['store_uid'] ?? null);
        if ($storeUid === null && $tenantCtx !== null) {
            $storeUid = $this->normalizeNullableScalar($tenantCtx['store_uid'] ?? $tenantCtx['storeUid'] ?? null);
        }
        if ($storeUid === null && $authCtx !== null) {
            $storeUid = $this->normalizeNullableScalar($authCtx['storeUid'] ?? $authCtx['store_uid'] ?? null);
        }

        $storeNo = $this->normalizeNullableScalar($input['storeNo'] ?? null);
        if ($storeNo === null && $tenantCtx !== null) {
            $storeNo = $this->normalizeNullableScalar($tenantCtx['storeNo'] ?? $tenantCtx['store_no'] ?? null);
        }
        if ($storeNo === null && $authCtx !== null) {
            $storeNo = $this->normalizeNullableScalar($authCtx['storeNo'] ?? null);
        }

        $apiKeyId = $this->normalizeNullableScalar($input['api_key_id'] ?? null);
        if ($apiKeyId === null && $authCtx !== null) {
            $apiKeyId = $this->normalizeNullableScalar($authCtx['apiKeyId'] ?? $authCtx['api_key_id'] ?? null);
        }

        $apiKeyPrefix = $this->stringOrNull($input['api_key_prefix'] ?? null);
        if ($apiKeyPrefix === null && $authCtx !== null) {
            $apiKeyPrefix = $this->stringOrNull($authCtx['apiKeyPrefix'] ?? $authCtx['api_key_prefix'] ?? null);
        }
        if ($apiKeyPrefix !== null && strlen($apiKeyPrefix) > 16) {
            $apiKeyPrefix = substr($apiKeyPrefix, 0, 16);
        }

        $service = trim((string) ($input['service'] ?? ''));
        $requestMethod = strtoupper(trim((string) ($input['request_method'] ?? '')));
        $requestPath = self::scrubApiKeySubstrings(trim((string) ($input['request_path'] ?? '')));
        $httpStatus = (int) ($input['http_status'] ?? 0);

        $errorCode = $input['error_code'] ?? null;
        $errorCodeStr = $errorCode === null || $errorCode === '' ? null : (string) $errorCode;

        $clientIp = self::scrubApiKeySubstrings($this->stringOrNull($input['client_ip'] ?? null) ?? '');
        $clientIp = $clientIp === '' ? null : $clientIp;
        $userAgent = self::scrubApiKeySubstrings($this->stringOrNull($input['user_agent'] ?? null) ?? '');
        $userAgent = $userAgent === '' ? null : $userAgent;

        $durationRaw = $input['duration_ms'] ?? null;
        if (!is_numeric($durationRaw)) {
            return ['ok' => false, 'record' => null, 'error' => 'INVALID_DURATION_MS'];
        }
        $durationMs = (int) round((float) $durationRaw);

        $reqSummary = self::scrubApiKeySubstrings($this->buildSummary(
            $input['request_summary'] ?? null,
            $input['request_body_for_summary'] ?? null
        ));
        $resSummary = self::scrubApiKeySubstrings($this->buildSummary(
            $input['response_summary'] ?? null,
            $input['response_body_for_summary'] ?? null
        ));

        $createdAt = isset($input['created_at']) && is_string($input['created_at']) && trim($input['created_at']) !== ''
            ? trim((string) $input['created_at'])
            : self::utcIso8601MillisZ();

        $record = [
            'trace_id' => $traceId,
            'sno' => $sno,
            'provider_id_no' => $providerIdNo,
            'depID' => $depId,
            'store_uid' => $storeUid,
            'storeNo' => $storeNo,
            'api_key_id' => $apiKeyId,
            'api_key_prefix' => $apiKeyPrefix,
            'service' => $service,
            'request_method' => $requestMethod,
            'request_path' => $requestPath,
            'http_status' => $httpStatus,
            'error_code' => $errorCodeStr,
            'client_ip' => $clientIp,
            'user_agent' => $userAgent,
            'duration_ms' => $durationMs,
            'request_summary' => $reqSummary,
            'response_summary' => $resSummary,
            'created_at' => $createdAt,
        ];

        $validation = $this->validate($record);
        if (!($validation['ok'] ?? false)) {
            $joined = implode('; ', $validation['errors'] ?? ['VALIDATION_FAILED']);

            return ['ok' => false, 'record' => null, 'error' => $joined];
        }

        return ['ok' => true, 'record' => $record, 'error' => null];
    }

    /**
     * @return array{ok: bool, errors: list<string>}
     */
    public function validate(array $record): array
    {
        $errors = [];

        $trace = trim((string) ($record['trace_id'] ?? ''));
        if ($trace === '') {
            $errors[] = 'trace_id required';
        }

        if (!array_key_exists('duration_ms', $record) || !is_int($record['duration_ms'])) {
            $errors[] = 'duration_ms must be int';
        }

        $created = (string) ($record['created_at'] ?? '');
        if (!self::isUtcIso8601MillisZ($created)) {
            $errors[] = 'created_at must be UTC ISO-8601 with milliseconds and Z';
        }

        $blob = self::serializeRecordForLeakScan($record);
        if (self::blobLooksLikeFullApiKey($blob)) {
            $errors[] = 'full API key pattern must not appear in record';
        }

        return $errors === [] ? ['ok' => true, 'errors' => []] : ['ok' => false, 'errors' => $errors];
    }

    public static function utcIso8601MillisZ(): string
    {
        $dt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $dt->format('Y-m-d\TH:i:s.v') . 'Z';
    }

    public static function isUtcIso8601MillisZ(string $value): bool
    {
        return (bool) preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $value
        );
    }

    /**
     * @param mixed $explicitSummary
     * @param mixed $body
     */
    private function buildSummary($explicitSummary, $body): string
    {
        if (is_string($explicitSummary) && trim($explicitSummary) !== '') {
            $redacted = $this->redactSummaryString(trim($explicitSummary));

            return $redacted;
        }

        if ($body === null) {
            return '{}';
        }

        if (is_array($body)) {
            $san = $this->redactDeep($body);

            return $this->encodeSummaryJson($san);
        }

        if (is_string($body)) {
            return $this->redactSummaryString($body);
        }

        return '{}';
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function redactDeep(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            $key = (string) $k;
            if ($this->isSensitiveKey($key)) {
                $out[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($v)) {
                $out[$key] = $this->redactDeep($v);
            } elseif (is_string($v)) {
                $out[$key] = $this->redactScalarString($v);
            } else {
                $out[$key] = $v;
            }
        }

        return $out;
    }

    private function redactSummaryString(string $s): string
    {
        $trim = trim($s);
        if ($trim === '') {
            return '{}';
        }

        $decoded = json_decode($trim, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->encodeSummaryJson($this->redactDeep($decoded));
        }

        return $this->redactScalarString($trim);
    }

    private function redactScalarString(string $s): string
    {
        if (self::stringLooksLikeFullApiKey($s)) {
            return '[REDACTED_API_KEY]';
        }

        $lower = strtolower($s);
        if (
            strpos($lower, 'password=') !== false
            || strpos($lower, 'token=') !== false
            || strpos($lower, 'cookie:') !== false
        ) {
            return '[REDACTED]';
        }

        return $s;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encodeSummaryJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '{"summary":"[UNSERIALIZABLE]"}';
        }

        return $json;
    }

    private function isSensitiveKey(string $key): bool
    {
        $norm = strtolower(str_replace(['-', '_'], '', $key));
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $frag) {
            $f = strtolower(str_replace(['-', '_'], '', $frag));
            if ($f !== '' && strpos($norm, $f) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function serializeRecordForLeakScan(array $record): string
    {
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : '';
    }

    private static function blobLooksLikeFullApiKey(string $blob): bool
    {
        if (preg_match_all('/bbc_(test|live)_[A-Za-z0-9_]+/', $blob, $m)) {
            foreach ($m[0] as $hit) {
                if (self::stringLooksLikeFullApiKey($hit)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function scrubApiKeySubstrings(string $s): string
    {
        return (string) preg_replace_callback(
            '/bbc_(test|live)_[A-Za-z0-9_]+/',
            static function (array $m): string {
                $full = $m[0];

                return strlen($full) > 16 ? '[REDACTED_API_KEY]' : $full;
            },
            $s
        );
    }

    private static function stringLooksLikeFullApiKey(string $s): bool
    {
        if (!preg_match('/^bbc_(test|live)_[A-Za-z0-9_]+$/', $s)) {
            return false;
        }

        return strlen($s) > 16;
    }

    /**
     * @param mixed $v
     */
    private function optionalArray($v): ?array
    {
        return is_array($v) ? $v : null;
    }

    /**
     * @param mixed $v
     */
    private function stringOrNull($v): ?string
    {
        if ($v === null) {
            return null;
        }

        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    /**
     * @param mixed $v
     * @return string|int|float|null
     */
    private function normalizeNullableScalar($v)
    {
        if ($v === null) {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }
}
