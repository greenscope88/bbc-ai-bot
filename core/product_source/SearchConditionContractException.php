<?php
declare(strict_types=1);

/**
 * Search condition contract validation failure (Phase 9-B-12).
 */
final class SearchConditionContractException extends \RuntimeException
{
    public const INVALID_BUDGET_RANGE = 'SEARCH_CONDITION_INVALID_BUDGET_RANGE';

    public const INVALID_DATE_RANGE = 'SEARCH_CONDITION_INVALID_DATE_RANGE';

    public const INVALID_PRODUCT_CATEGORY = 'SEARCH_CONDITION_INVALID_PRODUCT_CATEGORY';

    public const INVALID_INPUT = 'SEARCH_CONDITION_INVALID_INPUT';

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
