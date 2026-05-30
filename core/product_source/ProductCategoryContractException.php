<?php
declare(strict_types=1);

/**
 * Product category contract validation failure (Phase 9-B-7).
 */
final class ProductCategoryContractException extends \RuntimeException
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
