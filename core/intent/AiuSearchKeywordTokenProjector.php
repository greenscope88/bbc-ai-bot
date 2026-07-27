<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuSearchKeywordProjectionResult.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'AiuSearchKeywordTokenException.php';

/**
 * B0-LINE-01D-3: sole structural search-keyword token validator and projector.
 */
final class AiuSearchKeywordTokenProjector
{
    public const MODE_OPTIONAL = 'optional';
    public const MODE_OPTIONAL_PRESENT = 'optional_present';
    public const MODE_REQUIRED = 'required';

    private const MAX_PROJECTION_LENGTH = 48;

    /**
     * @param mixed $rawTokens
     */
    public function project($rawTokens, string $mode): ?AiuSearchKeywordProjectionResult
    {
        if ($mode === self::MODE_OPTIONAL) {
            return null;
        }

        if ($mode === self::MODE_REQUIRED && $rawTokens === null) {
            throw new AiuSearchKeywordTokenException(AiuSearchKeywordTokenException::TOKEN_ARRAY_MISSING);
        }

        if ($rawTokens === null) {
            throw new AiuSearchKeywordTokenException(AiuSearchKeywordTokenException::TOKEN_ARRAY_NULL);
        }

        if (!is_array($rawTokens)) {
            throw new AiuSearchKeywordTokenException(AiuSearchKeywordTokenException::TOKEN_ARRAY_INVALID_SHAPE);
        }

        if ($rawTokens !== [] && array_keys($rawTokens) !== range(0, count($rawTokens) - 1)) {
            throw new AiuSearchKeywordTokenException(AiuSearchKeywordTokenException::TOKEN_ARRAY_INVALID_SHAPE);
        }

        $normalizedTokens = [];
        $duplicateRemoved = 0;
        $seen = [];

        foreach ($rawTokens as $element) {
            if (!is_string($element)) {
                throw new AiuSearchKeywordTokenException(AiuSearchKeywordTokenException::TOKEN_ELEMENT_NON_STRING);
            }

            if (!mb_check_encoding($element, 'UTF-8')) {
                throw new AiuSearchKeywordTokenException(AiuSearchKeywordTokenException::TOKEN_ELEMENT_INVALID_UTF8);
            }

            if (self::containsProhibitedCharacter($element)) {
                throw new AiuSearchKeywordTokenException(AiuSearchKeywordTokenException::TOKEN_ELEMENT_PROHIBITED_CHAR);
            }

            $normalized = self::normalizeApprovedSpaces($element);
            if ($normalized === '') {
                throw new AiuSearchKeywordTokenException(AiuSearchKeywordTokenException::TOKEN_ELEMENT_EMPTY);
            }

            if (isset($seen[$normalized])) {
                ++$duplicateRemoved;
                continue;
            }

            $seen[$normalized] = true;
            $normalizedTokens[] = $normalized;
        }

        if ($normalizedTokens === []) {
            throw new AiuSearchKeywordTokenException(AiuSearchKeywordTokenException::TOKEN_PROJECTION_EMPTY);
        }

        $keyword = implode(' ', $normalizedTokens);
        $projectionLength = mb_strlen($keyword, 'UTF-8');
        if ($projectionLength > self::MAX_PROJECTION_LENGTH) {
            throw new AiuSearchKeywordTokenException(AiuSearchKeywordTokenException::TOKEN_PROJECTION_TOO_LONG);
        }

        return new AiuSearchKeywordProjectionResult(
            $normalizedTokens,
            $keyword,
            $duplicateRemoved,
            $projectionLength
        );
    }

    private static function containsProhibitedCharacter(string $token): bool
    {
        $length = mb_strlen($token, 'UTF-8');
        for ($i = 0; $i < $length; ++$i) {
            $char = mb_substr($token, $i, 1, 'UTF-8');
            if ($char === false || $char === '') {
                return true;
            }
            $code = self::unicodeCodePoint($char);
            if ($code === null) {
                return true;
            }
            if ($code <= 0x001F || $code === 0x007F || $code === 0x2028 || $code === 0x2029) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeApprovedSpaces(string $token): string
    {
        $length = mb_strlen($token, 'UTF-8');
        $chars = [];
        for ($i = 0; $i < $length; ++$i) {
            $char = mb_substr($token, $i, 1, 'UTF-8');
            if ($char === false) {
                continue;
            }
            $code = self::unicodeCodePoint($char);
            if ($code === 0x0020 || $code === 0x3000 || $code === 0x00A0) {
                $chars[] = ' ';
                continue;
            }
            $chars[] = $char;
        }

        $collapsed = preg_replace('/ +/u', ' ', implode('', $chars));

        return trim($collapsed !== null ? $collapsed : '');
    }

    /**
     * @return int|null
     */
    private static function unicodeCodePoint(string $char)
    {
        if (function_exists('mb_ord')) {
            return mb_ord($char, 'UTF-8');
        }

        if (class_exists('IntlChar')) {
            return \IntlChar::ord($char);
        }

        $utf32 = @iconv('UTF-8', 'UCS-4LE', $char);
        if ($utf32 === false || strlen($utf32) < 4) {
            return null;
        }

        $unpacked = unpack('V', $utf32);

        return is_array($unpacked) ? (int) $unpacked[1] : null;
    }
}
