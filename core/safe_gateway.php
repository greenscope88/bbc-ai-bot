<?php
declare(strict_types=1);

require_once 'C:/bbc-ai-bot/config/bootstrap.php';
require_once 'C:/bbc-ai-bot/core/logger.php';
require_once 'C:/bbc-ai-bot/core/line_service.php';
require_once 'C:/bbc-ai-bot/core/saas_router.php';

function safe_gateway_healthcheck(): void
{
    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'service' => 'safe_gateway',
        'message' => 'Webhook gateway is healthy. Use POST for LINE events.',
    ], JSON_UNESCAPED_UNICODE);
}

function handleWebhook(): void
{
    $logDir = 'C:/bbc-ai-bot/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $dailyLogPath = $logDir . '/webhook-' . date('Y-m-d') . '.log';
    $fixedLogPath = $logDir . '/webhook.log';

    $appendWebhookLog = static function (string $step, array $data) use ($dailyLogPath, $fixedLogPath): void {
        $line = '[' . date('Y-m-d H:i:s') . '][' . $step . '] ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        @file_put_contents($dailyLogPath, $line, FILE_APPEND);
        @file_put_contents($fixedLogPath, $line, FILE_APPEND);
    };

    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            safe_gateway_healthcheck();
            return;
        }

        $input = file_get_contents('php://input');
        if ($input === false) {
            $input = '';
        }

        $hasBom = false;
        if (strlen($input) >= 3) {
            $hasBom = (ord($input[0]) === 239 && ord($input[1]) === 187 && ord($input[2]) === 191);
            if ($hasBom) {
                $input = substr($input, 3);
            }
        }

        $appendWebhookLog('request_received', [
            'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
            'bom' => $hasBom,
            'body' => $input,
        ]);

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(200);
            echo json_encode([
                'status' => 'ok',
                'message' => 'BBC AI webhook endpoint is up. LINE Messaging API uses POST with X-Line-Signature.',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($input === '') {
            Logger::log('saas_router.log', 'safe_gateway_empty_input', []);
            $appendWebhookLog('empty_input', []);
            http_response_code(200);
            echo json_encode(['ok' => true, 'message' => 'empty input'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $event = json_decode($input, true);
        if (!is_array($event)) {
            Logger::log('saas_router.log', 'safe_gateway_invalid_json', ['raw' => $input]);
            $appendWebhookLog('invalid_json', ['raw' => $input]);
            http_response_code(200);
            echo json_encode(['ok' => true, 'message' => 'invalid json'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!isset($event['events'][0])) {
            Logger::log('saas_router.log', 'safe_gateway_no_events', ['event' => $event]);
            $appendWebhookLog('no_events', ['event' => $event]);
            http_response_code(200);
            echo json_encode(['ok' => true, 'message' => 'no events'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $firstEvent = $event['events'][0];
        $messageText = isset($firstEvent['message']['text']) ? (string)$firstEvent['message']['text'] : '';
        $replyToken = isset($firstEvent['replyToken']) ? (string)$firstEvent['replyToken'] : '';
        $signature = isset($_SERVER['HTTP_X_LINE_SIGNATURE']) ? (string)$_SERVER['HTTP_X_LINE_SIGNATURE'] : '';

        $appendWebhookLog('line_event_meta', [
            'message_text' => $messageText,
            'replyToken_exists' => $replyToken !== '',
        ]);

        $event['__meta'] = [
            'signature' => $signature,
            'raw_body' => $input,
        ];

        if ($messageText === '你好' && $replyToken !== '') {
            $appendWebhookLog('line_api_prepare', ['mode' => 'safe_gateway_direct_hello']);

            $lineReplyUrl = readEnvValue('LINE_REPLY_API_URL');
            if ($lineReplyUrl === '') {
                $lineReplyUrl = 'https://api.line.me/v2/bot/message/reply';
            }
            $lineToken = readEnvValue('LINE_CHANNEL_ACCESS_TOKEN');

            $appendWebhookLog('line_api_request', [
                'mode' => 'safe_gateway_direct_hello',
                'has_token' => $lineToken !== '',
                'reply_url' => $lineReplyUrl,
            ]);

            $directRes = LineService::replyToLine($lineReplyUrl, $lineToken, $replyToken, '你好，我是 BBC AI 客服小編');
            $appendWebhookLog('line_api_response', ['mode' => 'safe_gateway_direct_hello', 'response' => $directRes]);

            http_response_code(200);
            echo json_encode(['ok' => true, 'message' => 'hello_replied_direct'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $appendWebhookLog('route_start', ['message_text' => $messageText]);
        $result = routeAIRequest($event);
        $appendWebhookLog('route_result', $result);

        $status = isset($result['status']) ? (int)$result['status'] : 200;
        http_response_code($status);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[safe_gateway] ' . $e->getMessage());
        Logger::log('saas_router.log', 'safe_gateway_exception', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        $appendWebhookLog('safe_gateway_exception', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        http_response_code(200);
        echo json_encode(['ok' => true, 'message' => 'gateway handled error'], JSON_UNESCAPED_UNICODE);
    }
}

function readEnvValue(string $key): string
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
