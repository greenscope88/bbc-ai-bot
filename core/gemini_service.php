<?php

declare(strict_types=1);

/**
 * Independent Gemini integration module.
 * Returns ['ok' => bool, 'text' => ?string, 'error' => ?string, 'response' => mixed]
 */
function callGemini(string $message): array
{
    $apiKey = readGeminiEnvValue('GEMINI_API_KEY');

    $requestPayload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $message],
                ],
            ],
        ],
        'generationConfig' => [
            'thinkingConfig' => [
                'thinkingBudget' => 0,
            ],
        ],
    ];

    if ($apiKey === '') {
        logGeminiError('N/A', $requestPayload, null, 'GEMINI_API_KEY is empty');
        return ['ok' => false, 'text' => null, 'error' => 'GEMINI_API_KEY 未設定', 'response' => null];
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . rawurlencode($apiKey);
    $result = callGeminiUrl($url, $requestPayload);

    if (!$result['ok']) {
        logGeminiError($url, $requestPayload, $result['response'], 'endpoint_failed: ' . (string) $result['error']);
    }

    return $result;
}

/**
 * @return array{ok: bool, text: ?string, error: ?string, response: mixed}
 */
function callGeminiUrl(string $url, array $requestPayload): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'text' => null, 'error' => 'curl_init failed', 'response' => null];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($requestPayload, JSON_UNESCAPED_UNICODE),
    ]);

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlErrNo = curl_errno($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $decoded = null;
    if (is_string($raw) && $raw !== '') {
        $tmp = json_decode($raw, true);
        $decoded = is_array($tmp) ? $tmp : ['raw' => $raw];
    }

    if ($curlErrNo !== 0 || $status < 200 || $status >= 300) {
        $errorMsg = 'Gemini HTTP/CURL error';
        if ($curlErr !== '') {
            $errorMsg .= ' - ' . $curlErr;
        } else {
            $errorMsg .= ' - status ' . $status;
        }
        return ['ok' => false, 'text' => null, 'error' => $errorMsg, 'response' => $decoded];
    }

    $text = null;
    if (is_array($decoded) && isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
        $text = trim((string) $decoded['candidates'][0]['content']['parts'][0]['text']);
    }

    if ($text === null || $text === '') {
        return ['ok' => false, 'text' => null, 'error' => 'Gemini 回傳內容為空', 'response' => $decoded];
    }

    return ['ok' => true, 'text' => $text, 'error' => null, 'response' => $decoded];
}

function readGeminiEnvValue(string $key): string
{
    $val = getenv($key);
    if (is_string($val) && $val !== '') {
        return $val;
    }

    $envPath = 'C:/bbc-ai-bot/.env';
    if (!is_file($envPath)) {
        return '';
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return '';
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }

        if (trim($parts[0]) === $key) {
            return trim($parts[1]);
        }
    }

    return '';
}

function logGeminiError(string $url, array $request, $response, string $error): void
{
    $path = 'C:/bbc-ai-bot/logs/ai_error.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . json_encode([
        'api_url' => $url,
        'request' => $request,
        'response' => $response,
        'error' => $error,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($path, $line, FILE_APPEND);
}