<?php
declare(strict_types=1);

/**
 * Immutable raw HTTP result from Host B (transport layer only).
 */
final class HostBHttpRawResponse
{
    private int $statusCode;
    private string $body;

    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $statusCode, string $body, array $headers = [])
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = $headers;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
