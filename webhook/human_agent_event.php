<?php
declare(strict_types=1);

/**
 * Phase 2-C Step 2-C-3 — Backend Human Event Webhook (additive ingress).
 *
 * 用途：接收 Backend / CRM 傳送的「真人客服已送出訊息」事件，安全送入 Human
 * Service Runtime（Human Takeover, CA-005）。完全獨立於 LINE OA Webhook
 * （callback.php / callback_core.php），不主動送 LINE 訊息，不影響 Production
 * Reply Flow。
 *
 * 契約：
 *   - 僅接受 POST + application/json
 *   - 認證：HTTP header X-Human-Event-Token（或 Authorization: Bearer），
 *     與環境變數 HUMAN_AGENT_EVENT_TOKEN 比對；未設定即拒收。
 *   - 沿用 Freeze 的 Human Event Contract 與既有 Human Feature Flags（default OFF）。
 *
 * 全程委派 HumanAgentEventIngressHandler（never-throw）；此入口僅負責把 HTTP
 * 請求正規化、輸出 JSON 回應。
 */

require_once 'C:/bbc-ai-bot/config/bootstrap.php';
require_once 'C:/bbc-ai-bot/core/logger.php';
require_once 'C:/bbc-ai-bot/core/conversation/HumanAgentEventIngressHandler.php';

header('Content-Type: application/json; charset=utf-8');

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

$providedToken = '';
if (isset($_SERVER['HTTP_X_HUMAN_EVENT_TOKEN'])) {
    $providedToken = trim((string) $_SERVER['HTTP_X_HUMAN_EVENT_TOKEN']);
}
if ($providedToken === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth = trim((string) $_SERVER['HTTP_AUTHORIZATION']);
    if (stripos($auth, 'Bearer ') === 0) {
        $providedToken = trim(substr($auth, 7));
    }
}

$expectedToken = (string) (getenv(HumanAgentEventIngressHandler::ENV_TOKEN_KEY) ?: '');

$response = HumanAgentEventIngressHandler::handle([
    'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    'content_type' => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
    'raw_body' => $rawBody,
    'provided_token' => $providedToken,
    'expected_token' => $expectedToken,
    'trace_id' => 'human_evt_' . bin2hex(random_bytes(6)),
]);

http_response_code((int) ($response['status'] ?? 200));
echo json_encode($response['body'] ?? ['ok' => false, 'reason' => 'no_response'], JSON_UNESCAPED_UNICODE);
