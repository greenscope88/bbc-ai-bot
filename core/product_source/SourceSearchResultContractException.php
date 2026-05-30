<?php
declare(strict_types=1);

/**
 * Source search result contract validation failure (Phase 9-B-15).
 */
final class SourceSearchResultContractException extends \RuntimeException
{
    public const SOURCE_PLATFORM_REQUIRED = 'SOURCE_RESULT_SOURCE_PLATFORM_REQUIRED';

    public const TENANT_INSTANCE_REQUIRED = 'SOURCE_RESULT_TENANT_INSTANCE_REQUIRED';

    public const PRODUCT_CATEGORY_REQUIRED = 'SOURCE_RESULT_PRODUCT_CATEGORY_REQUIRED';

    public const TITLE_REQUIRED = 'SOURCE_RESULT_TITLE_REQUIRED';

    public const URL_REQUIRED = 'SOURCE_RESULT_URL_REQUIRED';

    public const INVALID_PRODUCT_CATEGORY = 'SOURCE_RESULT_INVALID_PRODUCT_CATEGORY';

    public const INVALID_METADATA = 'SOURCE_RESULT_INVALID_METADATA';

    public const INVALID_URL = 'SOURCE_RESULT_INVALID_URL';

    public const INVALID_INPUT = 'SOURCE_RESULT_INVALID_INPUT';

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
