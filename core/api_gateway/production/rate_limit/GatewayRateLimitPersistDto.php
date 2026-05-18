<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'RateLimitFieldWhitelist.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'RateLimitSensitiveDataRedactor.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'RateLimitPersistRowFormatter.php';

/**
 * Immutable DTO for a normalized rate-limit persistence row.
 */
final class GatewayRateLimitPersistDto
{
    /** @var array<string, mixed> */
    private $row;

    /**
     * @param array<string, mixed> $row
     */
    private function __construct(array $row)
    {
        $this->row = $row;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function fromLooseContext(array $context): self
    {
        return new self(RateLimitPersistRowFormatter::formatForPersistence($context));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->row;
    }
}
