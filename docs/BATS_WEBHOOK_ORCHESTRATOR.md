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

---

## Phase 9-B-26B-2 實作紀錄

**GeminiClient** adapter skeleton 已建立（見 `docs/BATS_GEMINI_CLIENT.md`）。

| 檔案 | 說明 |
|------|------|
| `core/product_source/integration/GeminiClient.php` | Context → Response adapter |
| `tests/product_sources/test_gemini_client.php` | 測試 |

### 已驗證流程

```
GeminiContextDocument
        ↓
GeminiClient.generateResponse() / generateMockResponse()
        ↓
GeminiResponseContract (+ Validator)
```

**下一步（Phase 9-B-26B-3）：** LineTransport skeleton。

---

## Phase 9-B-26B-3 實作紀錄

**LineTransport** adapter skeleton 已建立（見 `docs/BATS_LINE_TRANSPORT.md`）。

| 檔案 | 說明 |
|------|------|
| `core/product_source/integration/LineTransport.php` | TransportResult + LineTransport |
| `tests/product_sources/test_line_transport.php` | 測試 |

### 已驗證流程

```
LineMessagePayload
        ↓
LineTransport.prepareReply() / preparePush() / simulateSend()
        ↓
TransportResult
```

**下一步（Phase 9-B-26B-4B）：** SaaSRouter hook + feature flag dry-run（見下方 4B 紀錄）。

---

## Phase 9-B-26B-4B — SaaSRouter Hook + Feature Flag Dry-run

### 1. SaaSRouter Hook 位置

`SaaSRouter::handleEvent()` 內，**`TenantResolver::resolve()` 之後**、**`TourService` / `AiPromptBuilder` / `callGemini` legacy 之前**：

```
簽章驗證 → 訊息擷取 → (legacy: 你好 / 天氣)
    → TenantResolver::resolve()
    → attemptBatsWebhookHook()   ← 4B
    → null? → legacy Tour + Gemini + LineService
    → array? → return bats_dry_run / bats_enabled_skeleton（不 LINE）
```

可測試入口：`SaaSRouter::attemptBatsWebhookHook()`（單元測試注入 `BatsFeatureGate`）。

### 2. Feature Flag 設計

| 檔案 | 說明 |
|------|------|
| `config/bats_feature.php` | `defaults` / `tenants` / `channels` → `mode` |

解析順序：`tenants[tenant_sno]` → `channels[channel]` → `defaults.mode`（fail-closed → `disabled`）。

| mode | 4B 行為 |
|------|---------|
| `disabled` | **fall-through** 原 legacy，不呼叫 orchestrator |
| `dry_run` | `BatsWebhookOrchestrator` → `message: bats_dry_run`，**不** LINE / Gemini |
| `enabled` | 同 skeleton → `message: bats_enabled_skeleton`，**不**接正式 API |

### 3. disabled / dry_run / enabled skeleton 差異

- **disabled：** `attemptBatsWebhookHook()` 回傳 `null`，SaaSRouter 繼續 `TourService` → `callGemini` → `LineService::replyToLine`（與 4B 前相同）。
- **dry_run：** orchestrator `status: dry_run`，router `message: bats_dry_run`，提前 `return`，無商品搜尋、無 HTTP。
- **enabled（4B）：** orchestrator `status: accepted`，router `message: bats_enabled_skeleton`，仍為 skeleton，行為等同不接 LINE。

### 4. Rollback 方法

1. **Config rollback（建議）：** 將 `config/bats_feature.php` 的 `defaults.mode` 與所有 `tenants` / `channels` 設為 `disabled`（或清空 `tenants`），立即回到 legacy。
2. **Git rollback：** `git revert` 本 Phase commit（僅 `saas_router.php` + config + docs + test）。
3. **不需** 改 webhook、`callback.php`、`safe_gateway.php`、`.env`、SQL。

### 5. 目前不支援 hello / weather 進 BATS

Hook 在 **TenantResolver 之後**：

- `你好`：在 `callback.php` 路徑由 `safe_gateway` 提前回覆；在 `callback_core` / router 內亦在 resolve **之前** 回覆。
- `weather_query`：在 resolve **之前** 走 `callGemini` + `LineService`。

因此 **4B pilot 的 dry_run tenant** 若使用者送「你好」或天氣句，仍走 legacy 短路，**不會**進 `BatsWebhookOrchestrator`。全量 BATS 需未來 Phase 將 hook 上移並預解析 tenant。

### 6. 未來 Phase 9-B-26C — LINE OA dry-run 實測

- 單一 pilot `tenant_sno` 設 `dry_run`
- 以真實 LINE OA 送一般行程句（非 hello / 天氣）
- 預期 HTTP 200 + `message: bats_dry_run`，**客人端不應收到 BATS 回覆**（未呼叫 `LineService`）
- 驗證 `saas_router.log` / `webhook.log` 的 `bats_orchestrator_hook` 步驟

### 檔案清單（4B）

| 檔案 | 說明 |
|------|------|
| `config/bats_feature.php` | Feature flag（預設全 disabled） |
| `core/saas_router.php` | `attemptBatsWebhookHook` + resolve 後 hook |
| `tests/product_sources/test_saas_router_bats_hook.php` | Hook 測試 |

```powershell
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\product_sources\test_saas_router_bats_hook.php
```

---

## Phase 9-B-26C-2 — BATS Orchestrator Decision Snapshot Dry-run

### 1. 目標

在 **dry-run** 模式下，`BatsWebhookOrchestrator::handle()` 回傳 `decision_snapshot`，提供後續 BATS pipeline 的契約骨架，但**不接管正式回覆**、不改既有 LINE OA 行為。

### 2. Safety Guard（必須維持）

- `fallthrough_to_legacy = true`（由 router trace 與 snapshot 都標示）
- 不呼叫 Gemini API / LINE Reply API / Host B / SQL
- 不修改 hello / weather / legacy tour 正式流程

### 3. Decision Snapshot（最小契約）

`decision_snapshot` 目前為 placeholder contract（v1）：

- `snapshot_version`, `trace_id`, `tenant_sno`, `channel`
- `dry_run`, `orchestrator_status`, `bats_mode`
- `intent`（`not_available_yet`）
- `query.raw`（保留原始 customer_message）
- `search_condition.available = false`
- `candidate_sources.available = false`
- `publisher_strategy.available = false`
- `channel_publish_plan.available = false`
- `gemini_context.available = false`
- `reason_code`
- `fallthrough_to_legacy = true`
- `generated_at_unix`

### 4. reason_code 說明（26C-2）

- `DRY_RUN_SNAPSHOT_ONLY`：dry-run 模式的 snapshot（本 phase 核心）
- `SNAPSHOT_PLACEHOLDER_ONLY`：非 dry-run 狀態的 placeholder snapshot

### 5. SaaSRouter log 摘要欄位（post-resolve）

`bats_hook_trace_post_resolve` 增加 snapshot summary（避免大 payload）：

- `decision_snapshot_present`
- `snapshot_version`
- `reason_code`

### 6. 後續銜接

26C-2 僅建立契約，不做資料整合。以下欄位待後續 phase 補齊：

- SearchCondition parser
- Multi Source candidate list
- PublisherStrategy / ChannelPublishPlan
- GeminiContextDocument

---

## Phase 9-B-26C-3 — Multi Source Result Summary into Decision Snapshot

### 1. 範圍

本 phase 僅支援以 `source_results`（fixture/mock）輸入 orchestrator，寫入 snapshot 摘要欄位，不做外部搜尋：

- 不做 Hybrid Smart Search
- 不做 Query Normalizer / Alias / RAG
- 不呼叫任何外部商品源 API

### 2. 新增 snapshot 欄位

- `candidate_sources.available`
- `candidate_sources.count`
- `candidate_sources.items`（摘要 only）
- `result_summary.result_count`
- `result_summary.source_count`

每個 `candidate_sources.items[]` 限縮為：

- `source_platform`
- `tenant_instance`
- `product_category`
- `result_count`

### 3. reason_code 規則

- `DRY_RUN_SNAPSHOT_WITH_CANDIDATES`：dry-run 且 `source_results` 有摘要資料
- `DRY_RUN_SNAPSHOT_NO_CANDIDATES`：dry-run 且沒有 candidates
- `SNAPSHOT_PLACEHOLDER_ONLY`：非 dry-run status 的 placeholder snapshot

### 4. 安全原則

- `fallthrough_to_legacy` 持續為 `true`
- 不帶入完整商品明細到 snapshot
- 不紀錄 token / secret / apiKey / replyToken
- 不更動 SaaSRouter 正式回覆 return path

---

## Phase 9-B-26C-4 — GeminiRenderer + GeminiClient Dry-run Integration

### 1. 範圍

本 phase 在 dry-run 且有 candidate sources 時，加入下列串接：

`candidate_sources/result_summary -> GeminiRenderer(renderPrompts) -> GeminiClient(generateResponseFromPrompt mock) -> decision_snapshot summary`

限制維持：

- 不呼叫真 Gemini API
- 不送 LINE Reply
- 不接管正式回覆流程（維持 legacy fallthrough）

### 2. GeminiRenderer prompt payload（新增）

`renderPrompts(...)` 輸出：

- `system_prompt`
- `user_prompt`
- `render_metadata`（`trace_id`, `tenant_sno`, `source_count`, `result_count`, `renderer_version`）

`user_prompt` 僅允許使用來源摘要，不帶入完整商品明細。

### 3. GeminiClient mock prompt path（新增）

`generateResponseFromPrompt(...)`：

- 僅 mock，不做外部 API 呼叫
- 回傳 `GeminiResponseContract`
- 欄位符合 contract：`reply_text`, `reply_type`, `used_fallback`, `used_service_scope`, `voice_profile_used`, `schema_version`

### 4. Decision Snapshot 擴充

dry-run 且 candidates 可用時，新增摘要：

- `gemini_context.available = true`
- `gemini_context.renderer_version`
- `gemini_context.prompt_present = true`
- `gemini_reply.available = true`
- `gemini_reply.reply_type`
- `gemini_reply.used_fallback`
- `gemini_reply.voice_profile_used`

不在 router log 或 snapshot 泄漏完整 prompt 內容與敏感欄位。

---

## Phase 9-B-26C-5 — Gemini Reply to LineRenderer + LineSender Controlled Dry-run

### 1. 範圍

本 phase 在 dry-run 且 `gemini_reply.available=true` 時，加入：

`GeminiResponseContract -> LineRenderer -> messages[] -> LineSender dry-run preview -> decision_snapshot summary`

限制維持：

- 不呼叫 LINE Reply API
- 不送真實 LINE 訊息
- 不接管 legacy 正式回覆

### 2. LineRenderer 擴充

新增 `renderFromGeminiResponse(...)`：

- 以 `GeminiResponseContract.reply_text` 建立 LINE text `messages[]`
- 保持 payload 僅含 `messages`
- 加入最小長度保護（超長時截斷）

### 3. LineSender 擴充

新增 `prepareDryRun(...)`，回傳：

- `dry_run = true`
- `sender_type = line_dry_run`
- `message_count`
- `payload_size`
- `trace_id`
- `line_payload_preview`

不帶入 token / secret / endpoint / headers / curl。

### 4. Decision Snapshot 新增摘要

在 dry-run 且 line 轉換成功時：

- `line_render.available = true`
- `line_render.message_count`
- `line_render.render_mode = dry_run`
- `line_sender.available = true`
- `line_sender.dry_run = true`
- `line_sender.sender_type = line_dry_run`
- `line_sender.payload_size`

無 Gemini reply 時維持 unavailable，且不 fatal。

---

## Phase 9-B-26C-6 — travel_b Controlled Reply Gate (Preview-only)

### 1. 三重閘門

Controlled path 僅在以下條件同時成立時啟用：

1. `controlled_reply_enabled = true`
2. `tenant_sno` 在 `controlled_reply_tenants` allowlist
3. 訊息文字嚴格以 `controlled_reply_keyword_prefix` 開頭（目前 `BATS測試`）

其餘情況全部 fallthrough 至 legacy。

### 2. Config（安全預設）

`config/bats_feature.php` 新增：

- `controlled_reply_enabled`（預設 `false`）
- `controlled_reply_tenants`（預設包含 travel_b `5f99b8d665e8444d`）
- `controlled_reply_keyword_prefix`（預設 `BATS測試`）

### 3. Controlled path 行為（本 phase）

本 phase 為 **preview-only**：

- 觸發時呼叫 orchestrator dry-run 取得 `gemini_reply` / `line_render` / `line_sender` 摘要
- 記錄 `controlled_reply_preview`
- 回傳 `bats_controlled_reply_preview`
- 不送真 LINE 訊息、不呼叫 LINE Reply API

### 4. 失敗保護

Controlled path 任一例外：

- 記錄 `controlled_reply_error`
- 立即 fallback legacy（回傳 `null`）
- 不中斷 webhook 主流程
