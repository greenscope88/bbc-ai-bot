<?php
declare(strict_types=1);

/**
 * Immutable DTO for a normalized gateway audit row (whitelist fields only).
 */
final class GatewayAuditLogPersistDto
{
    /** @var string|null */
    private $traceId;

    /** @var string|null */
    private $sno;

    /** @var string|null */
    private $apiKeyPrefix;

    /** @var string|null */
    private $service;

    /** @var string|null */
    private $requestMethod;

    /** @var string|null */
    private $requestPath;

    /** @var int|null */
    private $httpStatus;

    /** @var string|null */
    private $errorCode;

    /** @var string|null */
    private $clientIp;

    /** @var string|null */
    private $userAgent;

    /** @var int|null */
    private $durationMs;

    /** @var string|null */
    private $createdAt;

    /**
     * @param array<string, mixed> $normalized
     */
    private function __construct(array $normalized)
    {
        $this->traceId = isset($normalized['trace_id']) ? (string) $normalized['trace_id'] : null;
        $this->sno = isset($normalized['sno']) ? (string) $normalized['sno'] : null;
        $this->apiKeyPrefix = isset($normalized['api_key_prefix']) ? (string) $normalized['api_key_prefix'] : null;
        $this->service = isset($normalized['service']) ? (string) $normalized['service'] : null;
        $this->requestMethod = isset($normalized['request_method']) ? (string) $normalized['request_method'] : null;
        $this->requestPath = isset($normalized['request_path']) ? (string) $normalized['request_path'] : null;
        $this->httpStatus = isset($normalized['http_status']) ? (int) $normalized['http_status'] : null;
        $this->errorCode = isset($normalized['error_code']) ? (string) $normalized['error_code'] : null;
        $this->clientIp = isset($normalized['client_ip']) ? (string) $normalized['client_ip'] : null;
        $this->userAgent = isset($normalized['user_agent']) ? (string) $normalized['user_agent'] : null;
        $this->durationMs = isset($normalized['duration_ms']) ? (int) $normalized['duration_ms'] : null;
        $this->createdAt = isset($normalized['created_at']) ? (string) $normalized['created_at'] : null;
    }

    /**
     * @param array<string, mixed> $record Raw or partial record; will be redacted + whitelisted.
     */
    public static function fromLooseRecord(array $record): self
    {
        return new self(AuditLogRowFormatter::formatForPersistence($record));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->traceId !== null) {
            $out['trace_id'] = $this->traceId;
        }
        if ($this->sno !== null) {
            $out['sno'] = $this->sno;
        }
        if ($this->apiKeyPrefix !== null) {
            $out['api_key_prefix'] = $this->apiKeyPrefix;
        }
        if ($this->service !== null) {
            $out['service'] = $this->service;
        }
        if ($this->requestMethod !== null) {
            $out['request_method'] = $this->requestMethod;
        }
        if ($this->requestPath !== null) {
            $out['request_path'] = $this->requestPath;
        }
        if ($this->httpStatus !== null) {
            $out['http_status'] = $this->httpStatus;
        }
        if ($this->errorCode !== null) {
            $out['error_code'] = $this->errorCode;
        }
        if ($this->clientIp !== null) {
            $out['client_ip'] = $this->clientIp;
        }
        if ($this->userAgent !== null) {
            $out['user_agent'] = $this->userAgent;
        }
        if ($this->durationMs !== null) {
            $out['duration_ms'] = $this->durationMs;
        }
        if ($this->createdAt !== null) {
            $out['created_at'] = $this->createdAt;
        }

        return $out;
    }
}
