<?php
declare(strict_types=1);

/**
 * Publisher contract validation failure (Phase 9-B-16).
 */
final class PublisherContractException extends \RuntimeException
{
    public const SOURCE_PLATFORM_REQUIRED = 'PUBLISHER_CONTRACT_SOURCE_PLATFORM_REQUIRED';

    public const TENANT_INSTANCE_REQUIRED = 'PUBLISHER_CONTRACT_TENANT_INSTANCE_REQUIRED';

    public const PRODUCT_CATEGORY_REQUIRED = 'PUBLISHER_CONTRACT_PRODUCT_CATEGORY_REQUIRED';

    public const TITLE_REQUIRED = 'PUBLISHER_CONTRACT_TITLE_REQUIRED';

    public const PRIMARY_URL_REQUIRED = 'PUBLISHER_CONTRACT_PRIMARY_URL_REQUIRED';

    public const ACTION_URL_REQUIRED = 'PUBLISHER_CONTRACT_ACTION_URL_REQUIRED';

    public const INVALID_PRODUCT_CATEGORY = 'PUBLISHER_CONTRACT_INVALID_PRODUCT_CATEGORY';

    public const INVALID_METADATA = 'PUBLISHER_CONTRACT_INVALID_METADATA';

    public const INVALID_ACTIONS = 'PUBLISHER_CONTRACT_INVALID_ACTIONS';

    public const INVALID_SECONDARY_URLS = 'PUBLISHER_CONTRACT_INVALID_SECONDARY_URLS';

    public const INVALID_URL = 'PUBLISHER_CONTRACT_INVALID_URL';

    public const INVALID_INPUT = 'PUBLISHER_CONTRACT_INVALID_INPUT';

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
