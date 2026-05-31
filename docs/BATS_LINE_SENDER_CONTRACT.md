# BATS Line Sender Contract (Phase 9-B-25)

## 1. LineSender

**LineSender** 接收 `LineMessagePayload`，產出 `LineSenderResult`，統一 Reply / Push 發送準備流程。

```
LineMessagePayload
        ↓
LineSender.prepare()
        ↓
LineSenderResult
        ↓
(未來 9-B-26) LINE Messaging API HTTP
```

| 負責 | 不負責 |
|------|--------|
| 驗證 payload | HTTP / curl |
| 計算 message_count / payload_size | replyToken / access token |
| 產生 trace_id | webhook 路由 |
| 回傳 sender contract | 實際 LINE API 呼叫 |

9-B-25 **不發送**，只做 Sender Contract。

---

## 2. LineSenderResult

| 欄位 | 必填 | 說明 |
|------|------|------|
| `schema_version` | 否（預設 1） | Contract 版本 |
| `success` | **是** | 準備是否成功 |
| `sender_type` | **是** | `reply` / `push` |
| `message_count` | **是** | messages 數量 |
| `payload_size` | **是** | JSON payload 位元組大小 |
| `trace_id` | **是** | 追蹤 ID |

### 禁止欄位

- `http` / `curl` / `endpoint` / `headers`
- `status_code` / `response_body`
- `replyToken` / `accessToken` / `api_key`

---

## 3. Sender Boundary

| 層 | 輸入 | 輸出 |
|----|------|------|
| **Renderer** | ChannelPublishPlan | LineMessagePayload |
| **Sender** | LineMessagePayload | LineSenderResult |
| **Transport（未來）** | Payload + token + replyToken | HTTP response |

Sender 不修改 Payload；Transport 不修改 Result contract。

---

## 4. 與 Renderer 邊界

| LineRenderer | LineSender |
|--------------|------------|
| Plan → messages[] | Payload → send result |
| 通道呈現邏輯 | 發送準備 metadata |
| 無 trace_id | 產生 trace_id |
| 無 sender_type | 區分 reply / push |

Renderer 完成後才進入 Sender；Sender 不再做 truncate 或排版。

---

## 5. 與未來 LINE Messaging API 邊界

| 9-B-25（本 Phase） | 9-B-26（未來） |
|--------------------|----------------|
| `prepare()` dry-run | `sendReply()` / `sendPush()` |
| LineSenderResult | HTTP status + LINE response |
| 無 LineService 修改 | 可擴充 LineService adapter |
| 無 .env token | channel access token 注入 |

未來 Transport 層讀取：

- `LineMessagePayload.messages`
- `sendContext.replyToken`（reply 專用）
- `sendContext.accessToken`（runtime 注入，不寫入 Result）

---

## 6. 未來規劃

| Phase | 內容 |
|-------|------|
| **9-B-26** | LINE OA Webhook Integration |

---

## 7. 檔案清單

| 檔案 | 說明 |
|------|------|
| `core/product_source/sender/line/LineSender.php` | Sender orchestration |
| `core/product_source/sender/line/LineSenderResult.php` | Result contract |
| `core/product_source/sender/line/LineSenderResultValidator.php` | 驗證 |
| `tests/product_sources/test_line_sender.php` | 測試 |

## 測試

```powershell
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\product_sources\test_line_sender.php
```

---

## 相關文件

- `docs/BATS_LINE_RENDERER_CONTRACT.md` — Renderer 層（9-B-22）
- `docs/BATS_CHANNEL_PUBLISH_PLAN_CONTRACT.md` — Plan 層
