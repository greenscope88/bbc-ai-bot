# BATS AI Runtime Design

**專案：** BBC AI SaaS / BATS / Phase 9-C-1  
**定位：** L3 實作 SSOT — **Phase 9-C-1 Runtime Design** 唯一正式依據  
**版本：** v1.0 Draft  
**狀態：** Draft — 待 Review / Adopt  
**主機參考：** 103.1.222.14（主機 A · `C:\bbc-ai-bot`）

**上層文件：**

| 層級 | 文件 | 本文件角色 |
|------|------|------------|
| L0 | `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md` | 協作與文件治理 |
| L3 | `BATS_AI_SEMANTIC_SEARCH.md` | 語意解析契約（`BatsSearchIntent`） |
| L3 | `BATS_AI_TRAVEL_CONSULTANT_POLICY.md` | 回覆行為政策（Consultant / Takeover / Ack） |
| L3 | `BATS_GEMINI_RESPONSE_CONTRACT.md` | Response DTO |
| L3 | `BATS_GEMINI_CLIENT.md` | Gemini Client Adapter |
| L3 | **本文件** | **Runtime 接入與實作順序** 唯一 SSOT |

**衝突處理：** 語意契約以 `BATS_AI_SEMANTIC_SEARCH.md` 為準；回覆政策以 `BATS_AI_TRAVEL_CONSULTANT_POLICY.md` 為準；**Runtime 元件職責、檔案影響、實作順序以本文件為準**。

### Adopted Runtime Decisions

| ID | 決策 |
|----|------|
| **RD-001** | `GeminiContextDocument.schema_version = 2`（新增必填 `bats_search_intent`） |
| **RD-002** | `TourPromptContextService` 回傳 **structured result object**（含 `intent`、`search_condition`、`search_results`、`legacy_context`、`clarification_required`、`clarification_reason`） |
| **RD-003** | **Pilot Scope = travel_b only**（`sno = 5f99b8d665e8444d`）；feature gate 保護 |
| **RD-004** | **Minimum Refactor Path** — `BatsSearchIntent` façade 包裝既有 `HybridSearchConditionBuilder` |
| **RD-005** | **Acknowledgement Reply** 接入於 `saas_router` / Orchestrator **搜尋啟動前**（`clarification_required = false` 且 `AI_ACTIVE`） |
| **RD-006** | **Automatic Human Takeover** 接入於 **Webhook 入口層**（訊息分類後、AI Outbound 前） |
| **RD-007** | **Final Reply Check** 接入於 **LINE 送出前最後一道閘門**（`LineService::replyToLine` 呼叫前） |

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §2 | Current Runtime Architecture |
| §3 | Target Runtime Architecture |
| §4 | Adopted Runtime Decisions |
| §5 | Runtime Components |
| §6 | File Impact Analysis |
| §7 | MVP Implementation Order |
| §8 | Feature Gate Strategy |
| §9 | Out of Scope |
| §10 | Success Criteria |
| §11 | Cross-Reference Index |

---

## 1. Purpose

### 1.1 文件定位

本文件定義 **Phase 9-C-1** 如何將下列兩條已 Adopt 的產品線接入現有 BATS Runtime：

| 產品線 | SSOT | Runtime 職責 |
|--------|------|----------------|
| **AI Semantic Search** | `BATS_AI_SEMANTIC_SEARCH.md` | 自然語言 → `BatsSearchIntent` → `SearchCondition` → 搜尋 |
| **AI Travel Consultant** | `BATS_AI_TRAVEL_CONSULTANT_POLICY.md` | Context 組裝 → Consultant 行為 → `GeminiResponseContract` → LINE Reply |

### 1.2 設計目標

| 目標 | 說明 |
|------|------|
| **最低重構** | 重用 `HybridSearchConditionBuilder`、`HybridDateRequiredGate`、Multi-Source、既有 Host B 管線 |
| **雙軌並存** | Legacy path 可 fall-through；Pilot 以 feature gate 隔離 |
| **契約驅動** | `BatsSearchIntent` + `GeminiContextDocument` v2 為結構化邊界 |
| **可驗收** | §10 Success Criteria 可作為 9-C-1 MVP sign-off |

### 1.3 非目標

本文件 **不** 重複定義語意欄位或 Consultant 話術政策；見上層 SSOT。

---

## 2. Current Runtime Architecture

### 2.1 主線（Legacy Production）

```text
LINE OA Webhook
    ↓
webhook/callback.php → safe_gateway.php
    ↓
SaaSRouter::handleEvent()
    ├─ LineCredentialResolver / signature verify
    ├─ IntentRouter::detect()
    │     └─ tour_query → TourQueryIntentDetector（keyword only）
    ├─ [Optional] BatsWebhookOrchestrator（controlled pilot；fixture）
    ├─ TenantResolver
    ├─ TourService（DB 舊路徑）
    ├─ AiPromptBuilder::build()
    ├─ TourPromptContextService::buildTourContextForPrompt() → string
    │     ├─ [Hybrid ON] HybridSearchConditionBuilder → SearchCondition
    │     │     → HybridDateRequiredGate
    │     │     → ApiQueryMapper → TourSearchApiClient（Host B）
    │     │     → TravelBMultiSourceLinkBuilder（travel_b）
    │     │     → GeminiTourContextBuilder（字串 context）
    │     └─ [Hybrid OFF] legacy keyword search
    ├─ TourLineReplyComposer::resolve()
    │     ├─ DateClarificationLineFormatter
    │     ├─ TourFallbackFormatter（主要輸出）
    │     └─ callGemini($prompt) fallback
    └─ LineService::replyToLine()
```

### 2.2 BATS Pilot 管線（骨架）

```text
SaaSRouter::attemptControlledRealLineReplyPath()
    ↓
BatsWebhookOrchestrator::handle()（skeleton）
    ↓
resolveControlledLineReplyText()
    ├─ GeminiRenderer::renderPrompts()
    ├─ GeminiClient::generateResponseFromPrompt()（mock）
    └─ LineRenderer::renderFromGeminiResponse()
    ↓
LineService::replyToLine()
```

### 2.3 現況缺口摘要

| 缺口 | 說明 |
|------|------|
| 無 `BatsSearchIntent` | 語意層 SSOT 未落地 |
| `TourPromptContextService` 回傳 `string` | 無 structured intent / results |
| `GeminiContextDocument` v1 | 無 `bats_search_intent` |
| 雙軌未統一 | Legacy 字串 context vs BATS structured path |
| 無 Acknowledgement / Takeover Runtime | 政策已 Adopt，程式未接 |

### 2.4 現有關鍵檔案對照

| 職責 | 檔案 |
|------|------|
| Webhook 入口 | `core/saas_router.php` |
| Intent 路由 | `core/intent_router.php` |
| Tour keyword | `core/tour_query_intent_detector.php` |
| Hybrid 解析 | `core/search/HybridSearchConditionBuilder.php` |
| 執行層 DTO | `core/search/SearchCondition.php` |
| 日期 Hard Gate | `core/search/HybridDateRequiredGate.php` |
| 搜尋編排 | `core/tour_prompt_context_service.php` |
| 字串 Context | `core/gemini_tour_context_builder.php` |
| Structured Context | `core/product_source/renderer/gemini/GeminiContextDocument.php` |
| Context 組裝 | `core/product_source/renderer/gemini/GeminiRenderer.php` |
| Gemini Adapter | `core/product_source/integration/GeminiClient.php` |
| Response 契約 | `core/product_source/renderer/gemini/GeminiResponseContract.php` |
| LINE 回覆 | `core/tour_line_reply_composer.php` |
| Multi-Source | `core/product_source/TravelBMultiSourceLinkBuilder.php` |
| Orchestrator 骨架 | `core/product_source/integration/BatsWebhookOrchestrator.php` |

---

## 3. Target Runtime Architecture

### 3.1 端到端目標流程

```text
Customer Query（LINE OA）
    ↓
[RD-006] ConversationStatusResolver
    ├─ Human Agent Message → HUMAN_ACTIVE（更新 human_takeover_at）
    └─ Customer Message + AI_ACTIVE → 繼續
    ↓
BatsSearchIntentBuilder::parse()
    → BatsSearchIntent
    ↓
ClarificationPolicy::decide()
    ├─ clarification_required = true
    │     → Clarification Reply（Hard Gate）
    │     → 不搜尋
    └─ clarification_required = false
          ↓
    [RD-005] Acknowledgement Reply（可選；AI_ACTIVE only）
          ↓
    BatsSearchIntentMapper::toSearchCondition()
          ↓
    Hybrid Smart Search（Host B via ApiQueryMapper）
          ↓
    Multi Source Search（TravelBMultiSourceLinkBuilder / Registry）
          ↓
    TourPromptContextResult（RD-002 structured object）
          ↓
    GeminiContextDocument v2（+ bats_search_intent）
          ↓
    Travel Consultant Policy
    ├─ GeminiRenderer（voice / guard / scope）
    ├─ GeminiClient → GeminiResponseContract
    └─ LineRenderer
          ↓
    [RD-007] FinalReplyGate::assertAiActive()
          ↓
    LineService::replyToLine()
          ↓
    Customer Reply
```

### 3.2 分層邊界

```text
┌─────────────────────────────────────────────────────────┐
│ L1 入口層：saas_router, ConversationStatusResolver       │
├─────────────────────────────────────────────────────────┤
│ L2 語意層：BatsSearchIntentBuilder, ClarificationPolicy│
├─────────────────────────────────────────────────────────┤
│ L3 執行層：SearchCondition, Hybrid Search, Multi-Source│
├─────────────────────────────────────────────────────────┤
│ L4 Context：TourPromptContextResult, GeminiContextDoc v2│
├─────────────────────────────────────────────────────────┤
│ L5 回覆層：GeminiClient, ResponseContract, LINE Reply  │
└─────────────────────────────────────────────────────────┘
```

### 3.3 與上層 SSOT 對齊

| Runtime 節點 | 政策 SSOT |
|--------------|-----------|
| `BatsSearchIntent` 欄位 | `BATS_AI_SEMANTIC_SEARCH.md` §3 |
| `ClarificationPolicy` Hard/Soft | `BATS_AI_TRAVEL_CONSULTANT_POLICY.md` AD-002；Semantic Search §10 |
| `bats_search_intent` in Context | Consultant Policy AD-001 |
| Acknowledgement Reply | Consultant Policy AD-004；RD-005 |
| Human Takeover / Final Check | Consultant Policy AD-005～AD-006A；RD-006、RD-007 |

---

## 4. Adopted Runtime Decisions

### RD-001 — GeminiContextDocument schema_version = 2

| 項目 | 規格 |
|------|------|
| **schema_version** | `2` |
| **新增必填** | `bats_search_intent`（`BatsSearchIntent::toArray()`） |
| **向後相容** | v1 consumer 須升級；測試一併更新 |
| **驗證** | `GeminiContextDocumentValidator` 強制 `bats_search_intent` 存在 |

### RD-002 — TourPromptContextService structured result

**不再** 以 `string` 作為唯一回傳；新增 **`TourPromptContextResult`**（建議類名）：

| 欄位 | 類型 | 說明 |
|------|------|------|
| `intent` | `BatsSearchIntent` | 語意層 SSOT 輸出 |
| `search_condition` | `SearchCondition` \| null | 映射後執行層；clarification 時可 null |
| `search_results` | `array` | Host B + metadata |
| `legacy_context` | `string` | `GeminiTourContextBuilder` 字串（過渡期保留） |
| `clarification_required` | `bool` | 與 intent 同步 |
| `clarification_reason` | `string` \| null | 機器可讀原因碼 |

**過渡策略：** `buildTourContextForPrompt()` 可保留 wrapper 回傳 `legacy_context` 字串供舊 caller；新 path 消費 full result object。

### RD-003 — Pilot Scope = travel_b only

| 項目 | 值 |
|------|-----|
| **Pilot tenant** | `travel_b` |
| **Pilot sno** | `5f99b8d665e8444d` |
| **保護** | 多層 feature gate（§8） |
| **其他 tenant** | 維持 legacy path；行為不變 |

### RD-004 — Minimum Refactor Path（BatsSearchIntent façade）

```text
BatsSearchIntentBuilder::parse(message)
    ├─ HybridSearchConditionBuilder::parse()（重用）
    ├─ 補強：travel_type, multi_destination, clarification flags
    └─ return BatsSearchIntent

BatsSearchIntentMapper::toSearchCondition(intent)
    └─ SearchCondition（供 Host B / Multi-Source）
```

**不** 從 `SearchCondition` 反推 Intent；**Builder 正向** 產出 Intent 再映射。

### RD-005 — Acknowledgement Reply 接入位置

| 接入點 | 時機 |
|--------|------|
| **主接入** | `saas_router` 內，Pilot path：`BatsSearchIntent` 完成且 `clarification_required = false` 後 |
| **條件** | `conversation_status = AI_ACTIVE` |
| **動作** | 非阻塞 `LineService::replyToLine()` 發送 Ack；並行啟動搜尋 |
| **禁止** | Ack 含商品／價格／URL；Hard Gate 時不發 Ack |

**建議元件：** `AcknowledgementReplyComposer`（新）；policy 來自 `BATS_AI_TRAVEL_CONSULTANT_POLICY.md` §8.5。

### RD-006 — Automatic Human Takeover 接入位置

| 接入點 | 時機 |
|--------|------|
| **主接入** | Webhook 入口層 — `saas_router` 收到 event 後、AI pipeline 前 |
| **偵測** | 訊息來源 = Human Agent（LINE OA 客服帳號／內部 sender 識別） |
| **動作** | 設 `conversation_status = HUMAN_ACTIVE`；更新 `human_takeover_at` |
| **儲存** | Conversation store（session / DB / cache — 實作細節 9-C-1d） |
| **持續** | `HUMAN_ACTIVE` 期間仍寫入 Customer + Human messages（AD-006） |

**不採用：** `#接手` / `#human` 指令解析。

### RD-007 — Final Reply Check 接入位置

| 接入點 | 時機 |
|--------|------|
| **主接入** | **所有** AI Outbound 送出前 — `LineService::replyToLine()` 呼叫前 |
| **檢查** | `FinalReplyGate::maySendAiReply(conversation_status)` |
| **若 HUMAN_ACTIVE** | 取消該 AI Reply（含 Ack、Final、Clarification 由 AI 產生者） |
| **適用** | 非阻塞搜尋完成後的 race condition 防護（AD-005C） |

**建議元件：** `FinalReplyGate`（新）；可注入 `saas_router` 與 `BatsWebhookOrchestrator`。

---

## 5. Runtime Components

### 5.1 BatsSearchIntent

| 項目 | 說明 |
|------|------|
| **定位** | 語意層 SSOT DTO |
| **契約** | `BATS_AI_SEMANTIC_SEARCH.md` §3 |
| **建議路徑** | `core/search/BatsSearchIntent.php` |
| **職責** | 承載 destination、multi_destination、travel_type、budget、clarification 等 |
| **方法** | `toArray()`, `fromArray()`, getters |

### 5.2 BatsSearchIntentBuilder

| 項目 | 說明 |
|------|------|
| **定位** | AI Semantic Search Runtime 入口（façade） |
| **建議路徑** | `core/search/BatsSearchIntentBuilder.php` |
| **職責** | `parse(string $message, array $context): BatsSearchIntent` |
| **內部重用** | `HybridSearchConditionBuilder`, `TravelIntentLexicon`, `TourQueryIntentDetector`（fallback keyword） |
| **輸出** | 完整 `BatsSearchIntent`；呼叫 `ClarificationPolicy` 填 `clarification_required` |

### 5.3 BatsSearchIntentMapper

| 項目 | 說明 |
|------|------|
| **定位** | 語意層 → 執行層映射 |
| **建議路徑** | `core/search/BatsSearchIntentMapper.php` |
| **職責** | `toSearchCondition(BatsSearchIntent): SearchCondition` |
| **規則** | 僅 `destination`（第一順位）進入 keyword；`multi_destination[]` 不進 URL（R2） |
| **阻斷** | `clarification_required = true` 時 **不** 映射、不搜尋 |

### 5.4 ClarificationPolicy

| 項目 | 說明 |
|------|------|
| **定位** | 統一 `clarification_required` / `clarification_reason` |
| **建議路徑** | `core/search/ClarificationPolicy.php` |
| **職責** | 整合 `HybridDateRequiredGate`（Hard Gate 日期）+ destination_unknown + intent_ambiguous |
| **輸出** | 更新 `BatsSearchIntent` 之 clarification 欄位 |
| **不負責** | 話術生成（交 `DateClarificationLineFormatter` / Gemini） |

### 5.5 GeminiContextDocument v2

| 項目 | 說明 |
|------|------|
| **定位** | Gemini Runtime 結構化輸入契約 |
| **schema_version** | `2`（RD-001） |
| **新增** | `bats_search_intent`（必填） |
| **既有** | `customer_query`, `search_results`, `voice_profile`, `guard_policy`, `tenant_service_scope`, `fallback_policy` |
| **組裝** | `GeminiRenderer::renderDocument()` + `TourPromptContextResult` |
| **消費** | `GeminiClient`；Consultant Policy 透過 prompt + validator 體現 |

### 5.6 輔助元件（建議新增）

| 元件 | 職責 |
|------|------|
| `TourPromptContextResult` | RD-002 structured result 容器 |
| `ConversationStatusResolver` | RD-006 takeover 狀態機 |
| `AcknowledgementReplyComposer` | RD-005 非阻塞 Ack |
| `FinalReplyGate` | RD-007 送出前檢查 |

---

## 6. File Impact Analysis

### 6.1 必須修改

| 檔案 | 變更 |
|------|------|
| `core/search/BatsSearchIntent.php` | **新增** — 語意 DTO |
| `core/search/BatsSearchIntentBuilder.php` | **新增** — façade parser |
| `core/search/BatsSearchIntentMapper.php` | **新增** — Intent → SearchCondition |
| `core/search/ClarificationPolicy.php` | **新增** — 統一 clarification |
| `core/search/TourPromptContextResult.php` | **新增** — RD-002 result object |
| `core/tour_prompt_context_service.php` | 改為產出 `TourPromptContextResult`；串接 Intent pipeline |
| `core/product_source/renderer/gemini/GeminiContextDocument.php` | v2 + `bats_search_intent` |
| `core/product_source/renderer/gemini/GeminiContextDocumentValidator.php` | v2 驗證 |
| `core/product_source/renderer/gemini/GeminiRenderer.php` | 注入 intent；Consultant prompt |
| `core/saas_router.php` | Pilot path、Ack、Takeover、FinalReplyGate |
| `tests/hybrid_search/*` | Intent + clarification 測試 |
| `tests/product_sources/test_gemini_*` | Context v2 契約測試 |

### 6.2 建議修改

| 檔案 | 變更 |
|------|------|
| `core/product_source/integration/GeminiClient.php` | intent-aware mock / 未來 API |
| `core/product_source/renderer/gemini/GeminiResponseContractValidator.php` | Consultant Case A/B/C |
| `core/tour_line_reply_composer.php` | 消費 structured result；clarification 分流 |
| `core/product_source/integration/BatsWebhookOrchestrator.php` | 接真實搜尋；統一 pilot |
| `core/search/ConversationStatusResolver.php` | **新增** — takeover |
| `core/search/AcknowledgementReplyComposer.php` | **新增** — RD-005 |
| `core/search/FinalReplyGate.php` | **新增** — RD-007 |
| `config/search/destination_alias_registry.php` | **新增** — R3 MVP（可 stub） |

### 6.3 不需修改（MVP 階段）

| 檔案 | 原因 |
|------|------|
| `webhook/callback.php` / `callback_core.php` | 入口不變 |
| `core/search/HybridSearchConditionBuilder.php` | 內部重用 |
| `core/search/HybridDateRequiredGate.php` | ClarificationPolicy 委派 |
| `core/search/SearchCondition.php` | 執行層 DTO 已足夠 |
| `core/tour_query_intent_detector.php` | 保留 fallback |
| `core/intent_router.php` | 上層路由不變 |
| `core/product_source/MultiSourceSearchUrlBuilder.php` | 已消費 SearchCondition |
| `core/product_source/TravelBMultiSourceLinkBuilder.php` | 已接 Hybrid condition |
| Host B / Gateway `tour/search` | API 層不變 |

---

## 7. MVP Implementation Order

### 9-C-1a — Semantic Layer（Intent façade）

| 交付 | 說明 |
|------|------|
| `BatsSearchIntent` DTO | 對齊 Semantic Search SSOT §3 |
| `BatsSearchIntentBuilder` | 包 `HybridSearchConditionBuilder` |
| `BatsSearchIntentMapper` | → `SearchCondition` |
| `ClarificationPolicy` | 包 `HybridDateRequiredGate` + reason codes |
| 單元測試 | 日期 Hard Gate、基本 destination |

**閘門：** 無需改 webhook；可獨立測試。

### 9-C-1b — Context Contract v2

| 交付 | 說明 |
|------|------|
| `GeminiContextDocument` schema v2 | RD-001 |
| Validator + Renderer 更新 | `bats_search_intent` 必填 |
| 契約測試 | `test_gemini_renderer`, `test_gemini_client` |

**閘門：** 無生產流量；契約層 only。

### 9-C-1c — Runtime Design Review（本文件）

| 交付 | 說明 |
|------|------|
| `BATS_AI_RUNTIME_DESIGN.md` | Runtime SSOT（本文件） |
| Review sign-off | SAFE TO START MVP IMPLEMENTATION |

### 9-C-1d — Integration + Pilot

| 交付 | 說明 |
|------|------|
| `TourPromptContextResult` + `TourPromptContextService` 重構 | RD-002 |
| `saas_router` pilot path（travel_b gate） | RD-003 |
| `AcknowledgementReplyComposer` | RD-005 |
| `ConversationStatusResolver` + `FinalReplyGate` | RD-006、RD-007 |
| `GeminiClient` intent-aware path | Consultant 分流 |
| E2E 測試 | travel_b + `BATS測試*` 或等效 pilot 關鍵字 |

**閘門：** Feature gate ON 才啟用；其他 tenant legacy fall-through。

---

## 8. Feature Gate Strategy

### 8.1 多層閘門（Defense in Depth）

```text
Layer 1: TourPromptFeatureGate
    → tour context 總開關（既有）

Layer 2: HybridSearchFeatureGate
    → hybrid 解析（既有；default OFF）

Layer 3: Phase 9-C-1 Feature Gate（新建）
    → semantic_intent_enabled
    → tenant_sno allowlist: 5f99b8d665e8444d only

Layer 4: BatsFeatureGate（既有）
    → controlled_real_reply / orchestrator pilot
```

### 8.2 Pilot 啟用條件（建議 config）

| 設定鍵 | 預設 | 說明 |
|--------|------|------|
| `phase_9c1_enabled` | `false` | 9-C-1 總開關 |
| `phase_9c1_tenant_sno` | `5f99b8d665e8444d` | travel_b only |
| `phase_9c1_ack_enabled` | `false` | Acknowledgement Reply |
| `phase_9c1_takeover_enabled` | `false` | Automatic Human Takeover |

### 8.3 正式環境保護原則

| 原則 | 說明 |
|------|------|
| **Default OFF** | 所有新 gate 預設關閉 |
| **Allowlist only** | 僅 `travel_b` sno 可進 9-C-1 path |
| **Fall-through** | Gate 未過 → 現行 `TourLineReplyComposer` + legacy context |
| **No silent behavior change** | 非 pilot tenant 回歸測試必須 PASS |
| **獨立 log** | `phase_9c1_decision` trace 寫入 `saas_router.log` |

### 8.4 與既有 Pilot 對齊

| 既有機制 | 關係 |
|----------|------|
| `controlled_real_reply_tenant_sno` | 同 sno `5f99b8d665e8444d`；可合併或串聯 |
| `TravelBMultiSourceLinkBuilder::TRAVEL_B_SNO` | Multi-Source pilot 已對齊 travel_b |
| `HybridSearchFeatureGate` | 9-C-1 path 依賴 hybrid ON；需一併啟用 |

---

## 9. Out of Scope

Phase 9-C-1 MVP **明確排除**：

| 排除項 | 歸屬 |
|--------|------|
| **Bonusmee Booking Assistant** | Phase 2 |
| **Traveler Registration / Request No** | Future contract |
| **Ranking Layer** | Future |
| **AI Recommendation Layer** | Future |
| **BDS Knowledge Injection** | Future（Consultant Grounded 擴展） |
| **Full Orchestrator Replacement** | 9-C-1d 僅 pilot 接入；不替換全部 `saas_router` legacy |
| **AI Suggest Reply Mode** | **Not Planned**（Consultant Policy §13.2） |
| **人工指令接手** `#接手` / `#human` | **Not Planned** |
| **destination_alias_registry 完整** | Semantic Search Future；MVP 可 stub |
| **multi_destination parser 完整** | Semantic Search Future；MVP 可空陣列 |
| **Gemini API 全量整合** | 可先 mock + validator；API 後續 phase |

---

## 10. Success Criteria

Phase 9-C-1 MVP **驗收條件**（travel_b pilot + feature gate ON）：

| # | 條件 | 驗證方式 |
|---|------|----------|
| SC-1 | `BatsSearchIntentBuilder` 產出符合 Semantic Search SSOT §3 | 單元測試 |
| SC-2 | 無日期語意 → `clarification_required = true`；**不** 呼叫 Host B / Multi-Source | 整合測試 |
| SC-3 | 有日期 + destination → 產出 `SearchCondition` 並完成 Host B 搜尋 | 整合測試 |
| SC-4 | `GeminiContextDocument` v2 含必填 `bats_search_intent` | 契約測試 |
| SC-5 | `TourPromptContextService` 回傳 `TourPromptContextResult` 全欄位 | 單元測試 |
| SC-6 | Acknowledgement Reply 於搜尋前送出（`AI_ACTIVE`）；不含商品資訊 | E2E / log |
| SC-7 | 客服訊息 → `HUMAN_ACTIVE`；AI Outbound 暫停 | E2E |
| SC-8 | `HUMAN_ACTIVE` 期間搜尋完成 → Final Reply **取消**（RD-007） | E2E / race test |
| SC-9 | 客服停止回覆 5 分鐘後 → `AI_ACTIVE` 恢復 | Timer 測試 |
| SC-10 | AI 恢復後可讀取 takeover 期間 Customer + Human messages | Context 測試 |
| SC-11 | 非 travel_b tenant → legacy path 行為不變 | 回歸測試 |
| SC-12 | Multi-Source URLs 仍由 Registry 產出；Consultant 不自行組 URL | 整合測試 |

---

## 11. Cross-Reference Index

| 主題 | SSOT |
|------|------|
| 語意契約 | `BATS_AI_SEMANTIC_SEARCH.md` |
| 回覆政策 | `BATS_AI_TRAVEL_CONSULTANT_POLICY.md` |
| 日期 Hard Gate | `BATS_HYBRID_DATE_POLICY.md` |
| Gemini Response | `BATS_GEMINI_RESPONSE_CONTRACT.md` |
| Gemini Client | `BATS_GEMINI_CLIENT.md` |
| Multi-Source | `MULTI_SOURCE_SEARCH_URL_BUILDER_PHASE9B14.md` |
| Hybrid 2-A | `HYBRID_SMART_SEARCH_PHASE2A_CORE_LAYER.md` |
| Webhook Orchestrator | `BATS_WEBHOOK_ORCHESTRATOR.md` |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | **v1.0 Draft** — Phase 9-C-1 Runtime SSOT |
| **Adopted Runtime Decisions** | RD-001～RD-007 |
| **程式實作** | **未開始** |
| **依賴** | Semantic Search + Consultant Policy 均已 Adopt Draft |

---

## Revision History

| 版本 | 日期 | 狀態 | 說明 |
|------|------|------|------|
| **1.0** | 2026-06-15 | Draft | 初版：Current/Target Architecture、RD-001～RD-007、MVP 順序 |

---

*本文件為 Phase 9-C-1 Runtime Design 唯一 SSOT。實作前須本文件 Adopt；實作須與 `BATS_AI_SEMANTIC_SEARCH.md`、`BATS_AI_TRAVEL_CONSULTANT_POLICY.md` 一致。*
