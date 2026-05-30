<?php
declare(strict_types=1);

/**
 * Catalog contract validation failure (Phase 9-B-1b).
 */
final class ProductSourceCatalogValidationException extends \RuntimeException
{
    /** @var string */
    private $errorCode;

    /** @var list<string> */
    private $violations;

    /**
     * @param list<string> $violations
     */
    public function __construct(string $errorCode, string $message, array $violations = [])
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->violations = $violations;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return list<string>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }
}
