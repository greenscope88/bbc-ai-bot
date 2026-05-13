<?php
declare(strict_types=1);

final class ErrorResponseBuilder
{
    /**
     * @param array<string, mixed>|null $details
     * @return array{
     *   success: bool,
     *   errorCode: string,
     *   message: string,
     *   traceId: string,
     *   timestamp: string,
     *   details: array<string, mixed>|object|null
     * }
     */
    public static function build(
        bool $success,
        string $errorCode,
        string $message,
        string $traceId,
        ?array $details = null
    ): array {
        return [
            'success' => $success,
            'errorCode' => $errorCode,
            'message' => $message,
            'traceId' => $traceId,
            'timestamp' => self::utcIso8601MillisZ(),
            'details' => self::detailsForPayload($details),
        ];
    }

    /**
     * @param array<string, mixed>|null $details
     */
    public static function toJson(
        bool $success,
        string $errorCode,
        string $message,
        string $traceId,
        ?array $details = null
    ): string {
        $payload = self::build($success, $errorCode, $message, $traceId, $details);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            return $json;
        }

        return self::encodingFailedFallbackJson($traceId);
    }

    private static function utcIso8601MillisZ(): string
    {
        $dt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $dt->format('Y-m-d\TH:i:s.v') . 'Z';
    }

    /**
     * @param array<string, mixed>|null $details
     * @return object|null
     */
    private static function detailsForPayload(?array $details)
    {
        if ($details === null) {
            return null;
        }

        return (object) $details;
    }

    private static function encodingFailedFallbackJson(string $traceId): string
    {
        $fallback = [
            'success' => false,
            'errorCode' => 'GW_INTERNAL_ERROR',
            'message' => 'Response encoding failed',
            'traceId' => $traceId,
            'timestamp' => self::utcIso8601MillisZ(),
            'details' => null,
        ];

        $json = json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            return $json;
        }

        return '{"success":false,"errorCode":"GW_INTERNAL_ERROR","message":"Response encoding failed","traceId":'
            . json_encode($traceId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ',"timestamp":'
            . json_encode(self::utcIso8601MillisZ(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ',"details":null}';
    }
}
