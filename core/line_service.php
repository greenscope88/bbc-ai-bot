<?php
declare(strict_types=1);

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
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
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
