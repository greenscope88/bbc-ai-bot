<?php
declare(strict_types=1);

/**
 * Region keyword mapping failures (Phase 9-B-13).
 */
final class RegionKeywordMapperException extends \RuntimeException
{
    public const KEYWORD_NOT_FOUND = 'REGION_KEYWORD_NOT_FOUND';

    public const PLATFORM_SCOPE_NOT_FOUND = 'REGION_PLATFORM_SCOPE_NOT_FOUND';

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
