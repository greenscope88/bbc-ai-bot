# BATS Line Transport (Phase 9-B-26B-3)

## 1. LineTransport

**LineTransport** 是 `LineMessagePayload` 與 `LineService` HTTP 之間的 **Transport Adapter**。

```
LineMessagePayload
        ↓
LineSender.prepare()          (contract, 9-B-25)
        ↓
LineTransport.prepareReply()  (transport skeleton, 9-B-26B-3)
        ↓
(future) LineService::postJson → LINE Messaging API
```

| 方法 | 說明 |
|------|------|
| `prepareReply()` | Reply 模式 dry-run（需 `replyToken`，不寫入 result） |
| `preparePush()` | Push 模式 dry-run |
| `simulateSend()` | 依 `transport_mode` 委派 reply / push |

9-B-26B-3 **不**呼叫 LINE API、**不**使用 HTTP/curl、**不**修改 `line_service.php`。

---

## 2. LineMessagePayload

輸入 contract（Phase 9-B-22）：

```json
{
  "messages": [
    { "type": "text", "text": "..." }
  ]
}
```

由 `LineRenderer` 從 `ChannelPublishPlan` 產生。Transport 層驗證 payload，但不修改內容。

---

## 3. TransportResult

輸出 value object：

| 欄位 | 說明 |
|------|------|
| `success` | 準備是否成功 |
| `message_count` | messages 數量 |
| `payload_size` | JSON 位元組大小 |
| `transport_mode` | `reply` / `push` |
| `trace_id` | 追蹤 ID |

### 禁止欄位

- `replyToken` / `accessToken` / `endpoint` / `headers`
- `http` / `curl` / `status_code` / `response_body`

---

## 4. 未來 LineService Integration

| 本 Phase | 未來 |
|----------|------|
| `prepareReply()` dry-run | `sendReply()` 呼叫 `LineService::postJson` |
| 不帶 token 於 result | runtime 注入 `accessToken` |
| 不帶 replyToken 於 result | runtime 使用 `replyToken` 組 API body |

建議未來 API body 組裝：

```php
[
    'replyToken' => $replyToken,  // runtime only
    'messages' => $payload->getMessages(),
]
```

---

## 5. 未來 Reply API

- Endpoint: `POST https://api.line.me/v2/bot/message/reply`
- 需要: `replyToken` + channel access token
- 一次 reply token 僅能使用一次

LineTransport 未來 `sendReply()` 應在 transport 層封裝，不暴露於 contract result。

---

## 6. 未來 Push API

- Endpoint: `POST https://api.line.me/v2/bot/message/push`
- 需要: `to` (userId) + channel access token
- 不需 replyToken

---

## 7. Phase 9-B-26B-4 — SaaSRouter Hook

```
SaaSRouter::handleEvent()
        ↓
BatsFeatureGate
        ↓
BatsWebhookOrchestrator
        ↓
(future) BATS pipeline → LineRenderer → LineTransport → LineService
```

本 Phase 不修改 `saas_router.php`。

---

## 8. 檔案清單

| 檔案 | 說明 |
|------|------|
| `core/product_source/integration/LineTransport.php` | TransportResult + LineTransport |
| `tests/product_sources/test_line_transport.php` | 測試 |

## 測試

```powershell
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\product_sources\test_line_transport.php
```

---

## 相關文件

- `docs/BATS_LINE_SENDER_CONTRACT.md` — Sender 層（9-B-25）
- `docs/BATS_LINE_RENDERER_CONTRACT.md` — Renderer 層（9-B-22）
- `docs/BATS_WEBHOOK_ORCHESTRATOR.md` — Orchestrator 層
