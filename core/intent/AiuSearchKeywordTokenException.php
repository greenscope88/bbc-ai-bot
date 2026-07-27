<?php
declare(strict_types=1);

/**
 * B0-LINE-01D-3: structural search-keyword token projection failure.
 */
final class AiuSearchKeywordTokenException extends \RuntimeException
{
    public const TOKEN_ARRAY_MISSING = 'TOKEN_ARRAY_MISSING';
    public const TOKEN_ARRAY_NULL = 'TOKEN_ARRAY_NULL';
    public const TOKEN_ARRAY_INVALID_SHAPE = 'TOKEN_ARRAY_INVALID_SHAPE';
    public const TOKEN_ELEMENT_NON_STRING = 'TOKEN_ELEMENT_NON_STRING';
    public const TOKEN_ELEMENT_INVALID_UTF8 = 'TOKEN_ELEMENT_INVALID_UTF8';
    public const TOKEN_ELEMENT_PROHIBITED_CHAR = 'TOKEN_ELEMENT_PROHIBITED_CHAR';
    public const TOKEN_ELEMENT_EMPTY = 'TOKEN_ELEMENT_EMPTY';
    public const TOKEN_PROJECTION_EMPTY = 'TOKEN_PROJECTION_EMPTY';
    public const TOKEN_PROJECTION_TOO_LONG = 'TOKEN_PROJECTION_TOO_LONG';
    public const TOKEN_DUPLICATE_REMOVED = 'TOKEN_DUPLICATE_REMOVED';

    private string $reasonCode;

    public function __construct(string $reasonCode)
    {
        $this->reasonCode = $reasonCode;
        parent::__construct('aiu_search_keyword_token_projection:' . $reasonCode);
    }

    public function getReasonCode(): string
    {
        return $this->reasonCode;
    }
}
