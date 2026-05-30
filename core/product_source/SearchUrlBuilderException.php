<?php
declare(strict_types=1);

/**
 * Search URL builder failures (Phase 9-B-11).
 */
final class SearchUrlBuilderException extends \RuntimeException
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
