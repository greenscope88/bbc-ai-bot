<?php
declare(strict_types=1);

require_once 'C:/bbc-ai-bot/config/bootstrap.php';
require_once 'C:/bbc-ai-bot/core/saas_router.php';
require_once 'C:/bbc-ai-bot/core/safe_header_guard.php';

header('Content-Type: application/json; charset=utf-8');

$guard = SafeHeaderGuard::scanProtectedFiles();
if (!$guard['ok']) {
    Logger::log('saas_router.log', 'safe_header_guard_runtime_warning', [
        'violations' => $guard['violations'],
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'message' => 'BBC AI webhook endpoint is up. LINE Messaging API uses POST with X-Line-Signature.',
        'header_guard_ok' => $guard['ok'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = file_get_contents('php://input');
if ($input === false) {
    $input = '';
}
$event = json_decode($input, true);

if (!is_array($event) || !isset($event['events'][0])) {
    Logger::log('saas_router.log', 'callback_no_events', ['raw_body' => $input]);
    http_response_code(200);
    echo json_encode(['ok' => true, 'message' => 'no events'], JSON_UNESCAPED_UNICODE);
    exit;
}

$event['__meta'] = [
    'signature' => isset($_SERVER['HTTP_X_LINE_SIGNATURE']) ? (string) $_SERVER['HTTP_X_LINE_SIGNATURE'] : '',
    'raw_body' => $input,
];

try {
    $result = routeAIRequest($event);
    $result['header_guard_ok'] = $guard['ok'];
    $status = isset($result['status']) ? (int) $result['status'] : 200;
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    Logger::log('saas_router.log', 'fatal_exception', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Internal error', 'header_guard_ok' => $guard['ok']], JSON_UNESCAPED_UNICODE);
}
