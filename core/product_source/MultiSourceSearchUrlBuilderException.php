<?php
declare(strict_types=1);

/**
 * Multi-source search URL builder failures (Phase 9-B-14).
 */
final class MultiSourceSearchUrlBuilderException extends \RuntimeException
{
    public const NO_SOURCE_INSTANCE = 'MULTI_SOURCE_NO_SOURCE_INSTANCE';

    public const UNKNOWN_SOURCE_INSTANCE = 'MULTI_SOURCE_UNKNOWN_SOURCE_INSTANCE';

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
