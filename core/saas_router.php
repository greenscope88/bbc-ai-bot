<?php
declare(strict_types=1);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/tenant_resolver.php';
require_once __DIR__ . '/intent_router.php';
require_once __DIR__ . '/tour_service.php';
require_once __DIR__ . '/ai_prompt_builder.php';
require_once __DIR__ . '/line_service.php';
require_once __DIR__ . '/usage_tracker.php';
require_once __DIR__ . '/gemini_service.php';

class SaaSRouter
{
    public static function handle(string $rawBody, string $signature, array $config): array
    {
        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            Logger::log('saas_router.log', 'invalid_json', ['raw_body' => $rawBody]);
            return ['ok' => true, 'message' => 'ignored'];
        }

        return self::handleEvent($event, $signature, $rawBody, $config);
    }

    public static function handleEvent(array $event, string $signature, string $rawBody, array $config): array
    {
        $start = microtime(true);
        $traceId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));

        $lineSecret = (string) ($config['line']['channel_secret'] ?? '');
        $lineToken = (string) ($config['line']['channel_access_token'] ?? '');
        $lineReplyUrl = (string) ($config['line']['reply_api_url'] ?? 'https://api.line.me/v2/bot/message/reply');

        Logger::log('saas_router.log', 'router_start', ['trace_id' => $traceId]);

        if (!LineService::verifySignature($rawBody, $signature, $lineSecret)) {
            self::appendWebhookLog('invalid_signature', [
                'trace_id' => $traceId,
                'signature_present' => $signature !== '',
            ]);
            Logger::log('saas_router.log', 'invalid_signature', ['trace_id' => $traceId]);
            return ['ok' => false, 'status' => 403, 'message' => 'invalid signature'];
        }

        if (!isset($event['events'][0]) || !is_array($event['events'][0])) {
            Logger::log('saas_router.log', 'no_events', ['trace_id' => $traceId]);
            self::appendWebhookLog('no_events', ['trace_id' => $traceId]);
            return ['ok' => true, 'message' => 'no events'];
        }

        $firstEvent = $event['events'][0];
        $replyToken = isset($firstEvent['replyToken']) ? (string) $firstEvent['replyToken'] : '';
        $userMessage = isset($firstEvent['message']['text']) ? trim((string) $firstEvent['message']['text']) : '';

        self::appendWebhookLog('line_event_received', [
            'trace_id' => $traceId,
            'message_text' => $userMessage,
            'reply_token_exists' => $replyToken !== '',
        ]);

        Logger::log('saas_router.log', 'line_event_received', [
            'trace_id' => $traceId,
            'message_text' => $userMessage,
            'reply_token_exists' => $replyToken !== '',
        ]);

        if ($replyToken === '' || $userMessage === '') {
            Logger::log('saas_router.log', 'empty_reply_or_message', ['trace_id' => $traceId]);
            return ['ok' => true, 'message' => 'ignored'];
        }

        if ($userMessage === '你好') {
            $helloReply = '你好，我是 BBC AI 客服小編';
            $helloRes = LineService::replyToLine($lineReplyUrl, $lineToken, $replyToken, $helloReply);
            self::appendWebhookLog('line_api_response', [
                'trace_id' => $traceId,
                'response' => $helloRes,
            ]);
            Logger::log('saas_router.log', 'hello_reply', [
                'trace_id' => $traceId,
                'line_reply_status' => $helloRes['status'],
            ]);
            return ['ok' => true, 'message' => 'hello_replied'];
        }

        $intent = IntentRouter::detect($userMessage);

        // Weather must go to Gemini directly (no SQL path)
        if ($intent['intent'] === 'weather_query') {
            $geminiResult = callGemini($userMessage);
            if ($geminiResult['ok']) {
                $replyText = (string) $geminiResult['text'];
            } else {
                $replyText = 'AI錯誤：' . (string) $geminiResult['error'];
            }

            $lineReplyRes = LineService::replyToLine($lineReplyUrl, $lineToken, $replyToken, $replyText);
            self::appendWebhookLog('line_api_response', [
                'trace_id' => $traceId,
                'response' => $lineReplyRes,
            ]);

            Logger::log('saas_router.log', 'weather_reply', [
                'trace_id' => $traceId,
                'line_reply_status' => $lineReplyRes['status'],
                'ai_ok' => $geminiResult['ok'],
            ]);

            return ['ok' => true, 'message' => 'weather_replied'];
        }

        $tenant = [
            'sno' => '',
            'company_name' => '旅行社客服',
            'ai_tone' => '親切',
            'travel_specialties' => '綜合旅遊',
            'price_catalog_json' => '{}',
            'channel_id' => isset($event['destination']) ? (string) $event['destination'] : '',
        ];
        $serviceData = [];
        $limits = [];

        try {
            $pdo = self::createPdo($config);
            $tenant = TenantResolver::resolve($pdo, $event, $config);
            $intent = IntentRouter::detect($userMessage);

            $supportCheck = TourService::isServiceSupported($pdo, (string) $tenant['sno'], (string) $intent['service_name']);
            if (!$supportCheck['supported']) {
                $replyText = '目前我們暫時沒有提供【' . (string) $intent['service_name'] . '】，若您需要，我可以協助您查詢其他目前有提供的服務。';
                $replyRes = LineService::replyToLine($lineReplyUrl, $lineToken, $replyToken, $replyText);
                self::appendWebhookLog('line_api_response', [
                    'trace_id' => $traceId,
                    'response' => $replyRes,
                ]);
                Logger::log('saas_router.log', 'unsupported_service', [
                    'trace_id' => $traceId,
                    'sno' => $tenant['sno'],
                    'service' => $intent['service_name'],
                    'line_reply' => $replyRes,
                ]);
                UsageTracker::track((string) $tenant['sno'], 'unsupported_service', mb_strlen($userMessage, 'UTF-8'), mb_strlen($replyText, 'UTF-8'));
                return ['ok' => true, 'message' => 'unsupported handled'];
            }

            $serviceData = TourService::fetchServiceData($pdo, (string) $tenant['sno'], $intent);
            $limits = self::fetchServiceLimits($pdo, (string) $tenant['sno']);
        } catch (Throwable $e) {
            Logger::log('saas_router.log', 'db_layer_fallback', [
                'trace_id' => $traceId,
                'error' => $e->getMessage(),
            ]);
        }

        $prompt = AiPromptBuilder::build($tenant, $intent, $serviceData, $limits);
        $geminiResult = callGemini($prompt);
        $replyText = $geminiResult['ok'] ? (string) $geminiResult['text'] : 'AI錯誤：' . (string) $geminiResult['error'];

        $lineReplyRes = LineService::replyToLine($lineReplyUrl, $lineToken, $replyToken, $replyText);
        self::appendWebhookLog('line_api_response', [
            'trace_id' => $traceId,
            'response' => $lineReplyRes,
        ]);

        UsageTracker::track((string) $tenant['sno'], (string) $intent['intent'], mb_strlen($userMessage, 'UTF-8'), mb_strlen($replyText, 'UTF-8'));
        Logger::log('saas_router.log', 'router_complete', [
            'trace_id' => $traceId,
            'sno' => $tenant['sno'],
            'intent' => $intent,
            'line_reply_status' => $lineReplyRes['status'],
            'elapsed_ms' => (int) ((microtime(true) - $start) * 1000),
            'ai_ok' => $geminiResult['ok'],
        ]);

        return ['ok' => true, 'message' => 'completed'];
    }

    private static function createPdo(array $config): PDO
    {
        $host = (string) ($config['database']['host'] ?? '');
        $db = (string) ($config['database']['name'] ?? '');
        $user = (string) ($config['database']['user'] ?? '');
        $pass = (string) ($config['database']['pass'] ?? '');

        $dsn = 'sqlsrv:Server=' . $host . ';Database=' . $db;
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private static function fetchServiceLimits(PDO $pdo, string $sno): array
    {
        $stmt = $pdo->prepare('SELECT service_name, is_supported, note, updated_at FROM tenant_service_limits WHERE sno = :sno ORDER BY updated_at DESC');
        $stmt->bindValue(':sno', $sno, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    private static function appendWebhookLog(string $step, array $context): void
    {
        $path = 'C:/bbc-ai-bot/logs/webhook.log';
        $line = '[' . date('Y-m-d H:i:s') . '][' . $step . '] ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        @file_put_contents($path, $line, FILE_APPEND);
    }
}

function routeAIRequest(array $event): array
{
    $rawBody = isset($event['__meta']['raw_body']) ? (string) $event['__meta']['raw_body'] : '';
    if ($rawBody === '') {
        $rawBody = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($rawBody)) {
            $rawBody = '';
        }
    }

    $signature = isset($event['__meta']['signature']) ? (string) $event['__meta']['signature'] : '';
    return SaaSRouter::handleEvent($event, $signature, $rawBody, app_config());
}
