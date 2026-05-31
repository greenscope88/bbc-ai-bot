# BATS Webhook Orchestrator (Phase 9-B-26B-1)

## 1. BatsFeatureGate

**BatsFeatureGate** 以 PHP array config 判斷 tenant / channel 是否啟用 BATS。

| Mode | 說明 |
|------|------|
| `enabled` | 允許進入 BATS orchestrator（未來可接正式 pipeline） |
| `disabled` | 關閉 BATS，旁路不進入 |
| `dry_run` | 接受請求但僅 skeleton 回應，不搜尋、不 Gemini、不 LINE |

### Config 結構（範例）

```php
[
    'defaults' => ['mode' => 'disabled'],
    'tenants' => [
        '5f99b8d665e8444d' => ['mode' => 'dry_run'],
    ],
    'channels' => [
        'Uxxxxxxxx' => ['mode' => 'enabled'],
    ],
]
```

**解析順序：** `tenants[tenant_sno]` → `channels[channel]` → `defaults.mode`

**原則：** 無 SQL、無 HTTP、fail-closed（未知 mode → `disabled`）。

---

## 2. BatsWebhookOrchestrator

**BatsWebhookOrchestrator** 為未來 `Webhook → BATS Pipeline` 的**唯一入口**（本 Phase 為 skeleton）。

```
Webhook (future)
        ↓
BatsWebhookOrchestrator.handle()
        ↓
orchestrator result (status / trace_id / tenant_sno / message)
        ↓
(future) BATS Search → Strategy → Renderer → Sender
```

### 輸入

| 欄位 | 必填 | 說明 |
|------|------|------|
| `tenant_sno` | **是** | 16 位 hex tenant ID |
| `customer_message` | **是** | 使用者訊息 |
| `channel` | 否 | LINE destination / channel ID |
| `trace_id` | 否 | 追蹤 ID（未提供則自動產生） |

### 輸出

| 欄位 | 說明 |
|------|------|
| `status` | `accepted` / `dry_run` / `disabled` / `rejected` |
| `trace_id` | 追蹤 ID |
| `tenant_sno` | 租戶 ID |
| `message` | skeleton 回應訊息 |
| `bats_mode` | `enabled` / `disabled` / `dry_run` |

9-B-26B-1 **不**搜尋商品、**不**呼叫 Gemini、**不**呼叫 LINE。

---

## 3. 未來與 SaaSRouter 關係

```
SaaSRouter::handleEvent()
        ↓
BatsFeatureGate.resolveMode()
        ↓
├─ disabled → 現有 TourService + AiPromptBuilder 路徑（不動）
└─ enabled/dry_run → BatsWebhookOrchestrator → (future) full BATS pipeline
```

**原則：** 最小 hook + feature flag，不 rewrite STABLE baseline。

---

## 4. 未來與 TenantResolver 關係

| 層 | 職責 |
|----|------|
| `TenantResolver` | `channelId` → `tenant_sno` + profile |
| `BatsFeatureGate` | `tenant_sno` + `channel` → BATS mode |
| `BatsWebhookOrchestrator` | 接收已解析 tenant，編排 BATS |

Tenant 身份在 orchestrator **之前** 解析一次；orchestrator 不查 SQL。

---

## 5. 未來與 GeminiClient 關係

```
BATS Search Results
        ↓
GeminiRenderer → GeminiContextDocument
        ↓
GeminiClient (9-B-26B-2)
        ↓
GeminiResponseContract
```

Orchestrator skeleton 本 Phase 不呼叫 GeminiClient。

---

## 6. 未來與 LineTransport 關係

```
LineRenderer → LineMessagePayload
        ↓
LineSender.prepare()
        ↓
LineTransport (9-B-26B-3)
        ↓
LineService::postJson → LINE Reply API
```

Orchestrator skeleton 本 Phase 不呼叫 LineTransport。

---

## 7. Phase 9-B-26B-2 — GeminiClient

- 將 `GeminiContextDocument` 組裝為 Gemini API request
- 回傳 `GeminiResponseContract`
- wrap 現有 `callGemini` / `callGeminiUrl`，不 rewrite stable HTTP core

---

## 8. Phase 9-B-26B-3 — LineTransport

- 接收 `LineMessagePayload` + `replyToken` + token
- 呼叫 `LineService::postJson`
- 產出 transport result（與 `LineSenderResult` 分離）

---

## 9. 檔案清單

| 檔案 | 說明 |
|------|------|
| `core/product_source/integration/BatsFeatureGate.php` | Feature gate |
| `core/product_source/integration/BatsWebhookOrchestrator.php` | Orchestrator skeleton |
| `tests/product_sources/test_bats_webhook_orchestrator.php` | 測試 |

## 測試

```powershell
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\product_sources\test_bats_webhook_orchestrator.php
```

---

## 相關文件

- `docs/BATS_LINE_SENDER_CONTRACT.md` — Sender 層（9-B-25）
- `docs/BATS_GEMINI_RESPONSE_CONTRACT.md` — Gemini Response（9-B-24）
- `docs/BATS_CHANNEL_PUBLISH_PLAN_CONTRACT.md` — Plan 層（9-B-20~21）
