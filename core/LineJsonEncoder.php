<?php
declare(strict_types=1);

final class LineJsonEncoder
{
    /**
     * @param mixed $value
     */
    public static function encode($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('line_json_encode_failed');
        }

        return $json;
    }
}
