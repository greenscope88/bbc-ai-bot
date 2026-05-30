<?php
declare(strict_types=1);

/**
 * Product source registry contract validation failure (Phase 9-B-8).
 */
final class ProductSourceContractException extends \RuntimeException
{
    /** @var string */
    private $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
