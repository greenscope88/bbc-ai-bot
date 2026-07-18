<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'LineJsonEncoder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'product_source' . DIRECTORY_SEPARATOR . 'renderer' . DIRECTORY_SEPARATOR
    . 'line' . DIRECTORY_SEPARATOR . 'LineMessagePayload.php';

class LineService
{
    public static function verifySignature(string $body, string $signature, string $secret): bool
    {
        if ($signature === '' || $secret === '') {
            return false;
        }
        $hash = hash_hmac('sha256', $body, $secret, true);
        return hash_equals(base64_encode($hash), $signature);
    }

    public static function reply(string $replyApiUrl, string $token, string $replyToken, string $message): array
    {
        $payload = [
            'replyToken' => $replyToken,
            'messages' => [
                ['type' => 'text', 'text' => $message],
            ],
        ];

        return self::postJson(
            $replyApiUrl,
            $payload,
            ['Authorization: Bearer ' . $token]
        );
    }

    public static function replyToLine(string $replyApiUrl, string $token, string $replyToken, string $text): array
    {
        return self::reply($replyApiUrl, $token, $replyToken, $text);
    }

    public static function replyMessages(
        string $replyApiUrl,
        string $token,
        string $replyToken,
        LineMessagePayload $payload
    ): array {
        $document = $payload->toArray();
        $document['replyToken'] = $replyToken;

        return self::postJson(
            $replyApiUrl,
            $document,
            ['Authorization: Bearer ' . $token]
        );
    }

    public static function push(string $pushApiUrl, string $token, string $userId, string $message): array
    {
        $payload = [
            'to' => $userId,
            'messages' => [
                ['type' => 'text', 'text' => $message],
            ],
        ];

        return self::postJson(
            $pushApiUrl,
            $payload,
            ['Authorization: Bearer ' . $token]
        );
    }

    public static function pushToLine(string $pushApiUrl, string $token, string $userId, string $text): array
    {
        return self::push($pushApiUrl, $token, $userId, $text);
    }

    public static function pushMessages(
        string $pushApiUrl,
        string $token,
        string $userId,
        LineMessagePayload $payload
    ): array {
        $document = $payload->toArray();
        $document['to'] = $userId;

        return self::postJson(
            $pushApiUrl,
            $document,
            ['Authorization: Bearer ' . $token]
        );
    }

    public static function postJson(string $url, array $payload, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'curl_error' => 'curl_init_failed'];
        }

        $finalHeaders = array_merge(['Content-Type: application/json'], $headers);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $finalHeaders,
            CURLOPT_POSTFIELDS => LineJsonEncoder::encode($payload),
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $body = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : ['raw' => $raw];
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $body,
            'request_payload' => $payload,
            'curl_errno' => $curlErrNo,
            'curl_error' => $curlErr,
        ];
    }
}
