<?php
declare(strict_types=1);

/**
 * Result → publisher mapping failures (Phase 9-B-17).
 */
final class ResultToPublisherMapperException extends \RuntimeException
{
    public const URL_REQUIRED = 'RESULT_TO_PUBLISHER_URL_REQUIRED';

    public const INVALID_SOURCE = 'RESULT_TO_PUBLISHER_INVALID_SOURCE';

    public const INVALID_PUBLISHER = 'RESULT_TO_PUBLISHER_INVALID_PUBLISHER';

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
