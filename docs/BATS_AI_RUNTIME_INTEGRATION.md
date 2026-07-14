# BATS AI Runtime Integration — Implementation Specification（L3）

**專案：** BBC AI SaaS / BATS / AIU v2  
**定位：** L3 Implementation Specification — Phase B Runtime Integration 唯一實作規格  
**版本：** v1.8 — Frozen + L3 Coding Spec  
**狀態：** Implementation Spec — B0 Fail-Closed + §11 Architecture Freeze + §12 L3 Coding Specification（Docs Only）  
**依據 Commit：** `f73124fe3b7af6de4a8a089d626099750788a99f`  
**主機參考：** 103.1.222.14（主機 A · `C:\bbc-ai-bot`）

---

## Frozen References（不得修改）

| SSOT | 狀態 |
|------|------|
| AIU v2 Entity Core Final | Frozen |
| AIU v2 Entity Definition v1.0 Rev.1 | Frozen |
| AIU v2 Gemini Output Contract v1.0 Rev.1 | Frozen |
| AIU Normalize v1.0 Rev.1 | Frozen（`docs/BATS_AI_NORMALIZE.md`） |
| BBC AI Runtime v1.0 Rev.1 | Frozen（`docs/BATS_AI_RUNTIME.md`） |
| Grounded Response Composer v1.2 Rev.1 | Frozen（`docs/BATS_AI_GROUNDED_RESPONSE_COMPOSER.md`） |

本文件 **不得**修改上述 SSOT；僅定義如何將其接入現有 Runtime。

---

## Integration Rules（IR-1～IR-6）

| ID | 規則 |
|----|------|
| **IR-1** | Gemini 是唯一 Semantic Understanding Source |
| **IR-2** | Normalize 僅 Translation；不得新增／刪除／改變 Gemini Semantic |
| **IR-3** | Runtime 僅 Routing／Dispatch／Coordination；不得重新理解 |
| **IR-4** | Business Logic 留在 Runtime Modules；Runtime Core 不承擔 Product／Knowledge／Human／Memory Logic |
| **IR-5** | Composer 僅依 Runtime Facts 回覆；No New Facts；Persona Read Only |
| **IR-6** | 本階段不處理 Conversation Memory Runtime、Conversation State Runtime、Human Service Isolation |

---

## 1. Discovery（Evidence-Based）

### 1.1 Discovery Table

| # | 元件 | File | Class / Function | Current Responsibility | Required Change |
|---|------|------|------------------|------------------------|-----------------|
| 1 | LINE Webhook | `webhook/callback.php` → `core/safe_gateway.php` | `handleWebhook()` → `routeAIRequest()` | 驗簽／解析／轉交 SaaSRouter | 維持 transport only；不插入 Understanding |
| 1b | Orchestrator entry | `core/saas_router.php` | `routeAIRequest()` / `SaaSRouter::handleEvent()` | 租戶解析、Feature Gate、路徑分流 | Phase9C1 成為 travel_b 主線；避免落回 legacy Tour/Gemini |
| 2 | AIU Prompt Scheme | `core/intent/AiuPromptBuilder.php` | `build()`（B-01～B-11） | 組裝 Understanding Prompt | 對齊 Frozen Output Contract（`entities` 31 鍵；禁止 Execution Decision） |
| 2b | Prompt Request | `core/intent/AiuPromptRequest.php` | DTO | 唯讀 context／utterance | 維持 |
| 3 | Gemini API | `core/intent/AiuGeminiUnderstandingClient.php` | `understand()` / `callGeminiJson()` | 呼叫 Gemini JSON Understanding | 輸出形狀對齊 Output Contract；移除／忽略 `semantic_notes` |
| 3b | HTTP helper | `core/gemini_service.php` | `callGeminiUrl()` | 共用 HTTP／env | 維持 |
| 4 | Gemini Output Parse | `AiuGeminiUnderstandingClient` | `decodeSemanticJson()` / `normalizeSemanticShape()` | 解碼並 coerce `{intent, entity, …}` | **改為** `{intent, entities, confidence, clarification}`（無 `semantic_notes`） |
| 5 | Normalize | `core/intent/AiuSemanticJsonNormalizer.php` | `normalize()` | Semantic → canonical intent／entity；含日期 resolve | 對齊 Frozen Normalize：保留結構、不產出 Execution Decision；日期僅映射不重算政策外推 |
| 5b | Date helper | `core/intent/AiuDateEntityResolver.php` | `resolve()` | Hybrid Date Policy 對齊 | 明確劃為 Normalize 允許之結構映射／或移出（見 Risk） |
| 6 | AIU Runtime façade | `core/intent/AiIntentUnderstandingRuntime.php` | `understand()` / **`planDispatch()`** | Gemini→Normalize→**Dispatch Planning**→Result | **移除／停用 `planDispatch` 作為 Understanding 產物**；Routing 改由 BBC AI Runtime |
| 6b | Result DTO | `core/intent/AiIntentUnderstandingResult.php` | 9 欄位含 `dispatch_plan` | AIU 輸出契約 | Phase B 需拆：語意 Contract vs Runtime Routing（對齊 Frozen） |
| 6c | Selector | `core/intent/AiIntentUnderstandingRuntimeSelector.php` | `resolve()` / `legacySelection()` | Flag 閘道；failure 時 Legacy 接管 | **B0：** 移除 `legacySelection()` 正式 Dispatch；AIU Failure→Fail-closed（§4.2） |
| 7 | Runtime Orchestration | `core/saas_router.php` | `attemptPhase9C1StructuredPilotPath()` | travel_b 主編排 | 正式承擔 BBC AI Runtime；**移除** Legacy Understanding 正式路徑 |
| 7a | Product Module | `core/tour_prompt_context_service.php` | `buildTourContextResult()` | 搜尋執行 | 僅消費 AIU Contract；**移除** Legacy parser 正式路徑 |
| 7b | Product translate | `core/intent/AiuProductIntentTranslator.php` | `translate()` | AIU entity→`BatsSearchIntent` | 對齊新 Runtime Entity（`destination[]` 等） |
| 7c | Knowledge Module | `core/knowledge/TenantPrivateKnowledgeRuntime.php` | `handle()` | 知識匹配與回覆素材 | 由 Runtime Dispatch 進入；不重判 Intent |
| 7d | Human Module | `core/knowledge/HumanServiceResponseComposer.php` | `compose()` | 轉真人固定文案 | IR-6：本階段不重做 Isolation；維持既有觸發 |
| 7e | Clarification | `ClarificationPolicy` / `DateClarificationLineFormatter` / translator | 追問文案與 gate | Detection 來自 AIU；Execution 由 Runtime 協調 |
| 8 | Grounding | `core/grounding/GroundingPipelineRuntime.php` | `composeProductReply` / `composeKnowledgeReply` | 組裝 GroundedInput→Composer | 確保 Facts 來自 Module 輸出 |
| 8b | Composer | `core/response/GroundedResponseComposer.php` | `compose*` | Express／pass-through（generative flag OFF） | 對齊 Express Layer；不得搜尋／不得新 Facts |
| 9 | LINE Reply | `core/line_service.php` | `replyToLine` / `pushToLine` | Channel 送出 | 維持；FinalReplyGate 之後 |
| 10 | Feature Flag | `config/bats_feature.php` | flags + tenant allowlist | rollout／observability 閘道 | **不得**作 Gemini↔Legacy Understanding 切換（§4.0.1） |

### 1.2 Critical Gaps（Code vs Frozen）

| Gap | Frozen SSOT | Current Code |
|-----|-------------|--------------|
| **G-1** Entity 載體 | Output Contract：`entities`（31 鍵 object） | `entity`（singular map） |
| **G-2** Execution Decision | Normalize／Gemini **不得**產出；Runtime 自行 Routing | `AiIntentUnderstandingRuntime::planDispatch()` 寫入 `dispatch_plan`／`execution_hint` |
| **G-3** Composer 角色 | Express；僅 Grounded Facts | Product 文案常在 Composer 前由 Gemini reply／Formatter 產生；`grounded_composer_generative_enabled=false` |
| **G-4** Destination | 保留 `destination[]` | Translator／Search 多為單一 `destination` string |

---

## 2. Current Integration Flow（A）

```text
LINE Webhook (callback.php / safe_gateway)
        ↓
SaaSRouter::handleEvent
        ↓
Phase9C1FeatureGate (travel_b sno)
        ↓
attemptPhase9C1StructuredPilotPath
        ↓
KnowledgeIntentDetector + AIU Selector  ★ Legacy Understanding = Compatibility Debt（§4.0.1）
        ↓
AiIntentUnderstandingRuntime（Gemini／AIU v2 — 唯一正式 Understanding）
   → AiuPromptBuilder / AiuGeminiUnderstandingClient
   → AiuSemanticJsonNormalizer
   → planDispatch()  ★ 產出 dispatch_plan（與 Frozen 衝突）
        ↓
Branches: Human | Knowledge | Product(+clarification folded)
        ↓
TourPromptContextService / KnowledgeRuntime / HumanServiceResponseComposer
        ↓
（Product）Gemini reply renderer / TourFallbackFormatter 常先產文案
        ↓
GroundingPipelineRuntime → GroundedResponseComposer（多為 pass-through）
        ↓
FinalReplyGate → LineService::replyToLine / pushToLine
```

---

## 3. Target Integration Flow（B）

```text
Customer (LINE OA)
        ↓
LINE Webhook → SaaSRouter（transport + tenant + flags）
        ↓
AIU Prompt Scheme（AiuPromptBuilder）
        ↓
Gemini（唯一 Understanding）
        ↓
Gemini Output Contract（intent + entities + confidence + clarification）
        ↓
AIU Normalize（Translation Only → `AiIntentCategory`；無 Execution Decision）
        ↓
`AiRuntimeIntentTranslator`（Current Contract Translation）
        ↓
`AiRuntimeIntent`（Current Runtime Intent — canonical `intent_type` 值集）
        ↓
BBC AI Runtime（saas_router Phase9C1 編排）
   Routing / Dispatch / Coordination
   （依 `intent_type` / entities / `clarification_required` / Runtime State 投影）
        ↓
Runtime Modules
   Product | Knowledge | Human | Clarification Execution
        ↓
Grounding（組裝 Runtime Facts → GroundedInput）
        ↓
Grounded Response Composer（Express；No New Facts）
        ↓
LINE Reply / Push
```

**Invariant：** Understanding ≠ Normalize ≠ Runtime ≠ Module ≠ Composer。

---

## 4. Contract Handoff & AIU Failure Policy（C）

> **v1.4 Cleanup（B0）：** Fail-closed Failure Boundary；Response Mapping Out of Scope。  
> **v1.5 Cleanup（B0）：** 移除 Legacy Understanding 正式授權；Production 僅 Gemini／AIU v2；Legacy Mode 列為 Compatibility Debt。  
> **v1.6 Draft（B0-2C）：** Fail-closed Boundary Contract — Selector 2-field Formal Failure Result、Router Stop-Dispatch Handoff、No-Legacy-Fallthrough。  
> **判定優先：** Gemini Single Understanding Source → No Compatibility Design → AIU v2 Contract Purity → BBC Runtime Execute／Govern。

### 4.0 Production Understanding Authority

| 項目 | 規格 |
|------|------|
| **唯一正式 Understanding Source** | **Gemini／AIU v2**（Prompt → Gemini → Output Contract Validation → Normalize → Runtime Contract） |
| **BBC Runtime 角色** | 僅 Execute／Govern；**不得**重新理解 Customer Message |
| **AIU Disabled／Unavailable／Failure** | **一律 Fail-closed**（§4.2～§4.4）；**不得** Legacy Understanding 接管 |
| **Feature Flag** | 僅 rollout／tenant allowlist／observability；**不得**作 Gemini↔Legacy Understanding 切換 |
| **Shadow／Parity** | 僅非正式 observability；**不得**成為 Understanding Authority；**不得**改變正式 Dispatch |

**Production Flow（正式 Architecture）：**

```text
Customer Message
  → Gemini／AIU v2
  → Gemini Output Contract Validation
  → AIU Normalize
  → BBC Runtime Contract
  → BBC Runtime Execute／Govern
```

**Invariant：** Gemini 是唯一 Intent／Entity Understanding Core；Customer Message 在本輪正式路徑中**只允許**被 Gemini 理解一次（IR-1、UC-1、UC-3）。

### 4.0.1 Compatibility Debt（非正式 Architecture）

以下存在於 **Current Code**，**不得**定義為正式 Production Architecture；**B0 Minimal Coding 須移除其 Understanding Authority**：

| Compatibility Debt | Current Code 示例 | B0 目標 |
|--------------------|-------------------|---------|
| Flag OFF → Legacy MVP | `intent_understanding_authoritative_enabled=false` | **移除** Legacy 正式 Understanding 路徑 |
| Shadow → Legacy authoritative | Shadow probe + Legacy Dispatch | Shadow 僅 log；**不得** Legacy Dispatch |
| AIU Failure → Legacy fallback | `legacySelection()`、`legacy_fallback` | **Fail-closed** |
| Legacy Intent parser | `KnowledgeIntentDetector` 作 routing | **不得**作 Understanding Authority |
| Legacy Entity parser | `BatsSearchIntentBuilder`、`TourQueryIntentDetector` | **不得**作 Understanding Authority |
| Dual Authority Feature Flag | Flag 切換 Gemini↔Legacy | **移除**；Flag 僅 rollout／observability |

### 4.1 Contract Handoff Table

| From | To | Input | Output | Failure |
|------|----|-------|--------|---------|
| Webhook | SaaSRouter | LINE event + signature | tenant／utterance／replyToken | 驗簽失敗→拒絕；無 tenant→fail-closed |
| SaaSRouter | AIU Prompt | utterance + read-only snapshots | `AiuPromptRequest` | snapshot 缺失→空投影，不阻斷 Understanding |
| Prompt | Gemini | Prompt string | Raw JSON | HTTP／timeout／empty／non-JSON→**AIU Failure**→§4.2；**禁止** `legacySelection()` 或 Legacy Understanding |
| Gemini | Normalize | Output Contract JSON | Runtime Contract（語意欄位） | 缺鍵／型別錯／legacy `entity`／Validation fail→**AIU Failure**→§4.2；禁止空白 Contract 繼續 |
| Normalize | BBC AI Runtime | Runtime Contract（無 dispatch_plan） | 同左 + 可讀 State 投影 | Normalize／Runtime exception→**AIU Failure**→§4.2 |
| Runtime | Module | Contract 投影 + dispatch 目標 | Module Result | 無有效 Runtime Contract→**Fail-closed**（§4.4）；不得進入 AIU-dependent Module |
| Module | Grounding | runtime_type + facts + context | `GroundedInput` | 缺 facts→空陣列；Composer 不得補洞 |
| Grounding | Composer | `GroundedInput` | `GroundedOutput` | Validator fail→降級 reply_type；不得 silent invent |
| Composer | LINE | `reply_text` + gate | LINE API OK | Gate block／HUMAN→不送；API fail→log／retry 政策既有 |

### 4.2 Production AIU Failure Policy

以下**全部**視為 **AIU Failure**（含 AIU Disabled／Unavailable）：

| 類別 | 失敗情境 |
|------|----------|
| Disabled／Unavailable | Feature 關閉、tenant 不在 allowlist、AIU path 未執行 |
| Transport | Gemini HTTP failure、timeout、empty response |
| Parse | Non-JSON response、decode 失敗 |
| Output Contract | 缺鍵、型別錯誤、legacy `entity` input、非 object 之 `entities` |
| Normalize／Validation | Normalize failure、Entity Validation failure |
| Runtime | `AiIntentUnderstandingRuntime` exception、Selector 上游 AIU path 中斷 |

**Production 時必須：**

1. **停止**本次 AIU Understanding Flow。
2. **不**產生虛假或空白 Semantic Contract（含 `entities: []` silent coercion）。
3. **不**呼叫 `legacySelection()` 或 Legacy Understanding 接管 Dispatch。
4. **不**使用 `KnowledgeIntentDetector` 作正式 routing intent。
5. **不**使用 Legacy parser 補 Intent／Entity Understanding。
6. **不**執行 AIU-dependent Runtime（含 Product Search）。
7. **保留**可觀測 failure reason（§4.3；log only）。
8. **不得**將 AIU Failure 偽裝為 Clarification（合法 `clarification.required=true` 除外）。
9. **終點：** 回傳 §4.2.1 Formal Failure Result → Router Stop-Dispatch（§4.4.2）→ Handled Fail-closed → Integration Boundary（§4.5 Out of Scope）。

**Clarification 邊界：** 僅合法 Runtime Contract + Gemini Detection 可執行 Clarification。AIU Failure **≠** Clarification。

#### 4.2.1 Selector Formal Failure Result

AIU Runtime Failure 與 AIU Authority Disabled **共用單一** Formal Failure Result Contract；**只以** `failure_reason` 區分情境。

| Field | Type | Required | Meaning |
|-------|------|:--------:|---------|
| `runtime_source` | string | Yes | 固定 `fail_closed`（`SOURCE_FAIL_CLOSED`）；**唯一** Fail-closed Authority |
| `failure_reason` | string | Yes | 正式 Failure 原因（見下表） |

**Router 唯一判定條件：**

```text
runtime_source === SOURCE_FAIL_CLOSED
```

**不得**增加第二個 Authority 欄位（如 `fail_closed` boolean）。

**AIU Runtime Failure：**

```php
[
    'runtime_source' => 'fail_closed',
    'failure_reason' => 'AIU_RUNTIME_FAILURE',
]
```

- AIU **已執行**；因 transport、contract、normalize 或 runtime error 失敗。
- Error detail **只**寫入既有 operational log（§4.3）；**不**進入 Selector→Router handoff。

**AIU Authority Disabled：**

```php
[
    'runtime_source' => 'fail_closed',
    'failure_reason' => 'AIU_AUTHORITY_DISABLED',
]
```

- AIU Authority 未啟用（Feature 關閉或 tenant 不在 allowlist）；AIU **未執行**。
- **不得**轉入 Legacy Understanding。

**Fixed Prohibited Fields（Formal Failure Result 固定禁止）：**

| 禁止欄位 | 禁止理由 |
|----------|----------|
| `fail_closed` | 防止與 `runtime_source` 雙重 Authority |
| `human_blocked` | 防止混入 HUMAN Owner Block 語意（§4.4.2） |
| `intent_type` | 防止 Legacy Intent 回流 Dispatch |
| `legacy_intent_type` | 防止 Legacy Intent 投影 |
| `aiu_result` | 防止虛假 Semantic Contract |
| `fallback_reason` | 防止 Legacy fallback 語意 |
| `failure_detail` | 防止 Exception detail 進入 Runtime handoff |
| `failure_stage` | 防止新增非必要 Observability handoff taxonomy |

**不得**新增 `failure_stage` handoff taxonomy；log 類別／stage 僅 §4.3 operational log 原則。

### 4.3 Failure Observability（原則）

AIU Failure 時：

| 原則 | 規格 |
|------|------|
| **可觀測** | Failure 寫入 operational log（`trace_id`、`tenant_sno`、`conversation_id`） |
| **可機讀** | Log 含 failure 類別／stage（如 transport、contract、normalize、runtime）；**不得**暴露給客戶 |
| **Handoff 邊界** | Error detail／stage **只**進 operational log；**不**進 §4.2.1 Formal Failure Result |
| **禁止 Legacy 掩蓋** | **不得**以 Legacy Understanding 或 `legacy_fallback` 掩蓋 Failure |

**B0 不界定：** customer-facing 欄位（§4.5 Out of Scope）。

### 4.4 Fail-closed Boundary（B0 終點）

| 項目 | 規格 |
|------|------|
| **觸發** | §4.2 任一 AIU Failure |
| **停止** | AIU Flow；Runtime Contract routing；AIU-dependent Module |
| **禁止** | Legacy Understanding；空白 Contract；Clarification 冒充 |
| **B0 終點** | 無合法 Runtime Contract → 停止 AIU-dependent Runtime |
| **B0 不含** | Response Mapping（§4.5） |

#### 4.4.1 Fail-closed Flow

```text
AIU Disabled／Unavailable／Failure
        ↓
Selector Formal Failure Result（§4.2.1）
        ↓
Router Stop-Dispatch Handler（§4.4.2）
        ↓
Handled Non-null Result
        ↓
No Legacy Fallthrough
        ↓
Boundary End（Response Mapping Out of Scope §4.5）
```

#### 4.4.2 Router Stop-Dispatch Handoff

`attemptPhase9C1StructuredPilotPath()` 收到 Selector Result 且：

```text
runtime_source === SOURCE_FAIL_CLOSED
```

**必須立即**（在 `human_blocked` 及正常 Dispatch 判斷**之前**）：

1. **不**讀取 `intent_type` 作 Dispatch
2. **不**建立 `authoritativeProductIntent`
3. **不**進入 Human／Knowledge／Product Runtime
4. **不**呼叫 Legacy Intent／Entity Parser
5. 回傳 **non-null** handled array（§4.4.4）
6. **不得** fallthrough 至 Legacy Flow

**Handler 插入點：** `AiIntentUnderstandingRuntimeSelector::resolve()` 回傳之後；`human_blocked` 判斷之前（`core/saas_router.php`）。

#### 4.4.3 Result State Distinction

| State | Meaning | Handled | Dispatch | Fallthrough |
|-------|---------|:-------:|:--------:|:-----------:|
| **Not Applicable** | 本次請求不適用 Phase9C1 Path（gate OFF、credentials 缺失等） | No | No | **Yes** — `return null` |
| **AIU Success** | AIU 完成正式 Understanding（`runtime_source=aiu`） | Yes | Yes，或依正式 Owner Policy Block | No |
| **Handled Fail-closed** | AIU Runtime Failure 或 AIU Authority Disabled | Yes | **No** | **No** — `return array` |

**Invariant：**

- `null` **只**代表 Not Applicable。
- Handled Fail-closed **不得**使用 `null`。
- AIU Failure **不得**以 uncaught Exception 或 `null` 進入 Legacy fallthrough。
- `handleEvent()` 收到 Handled Fail-closed 的 non-null array 時 **必須**直接 return，**不得** fallthrough。

#### 4.4.4 Router Envelope Semantics

Router Handled Fail-closed 回傳既有 Runtime **envelope**（投影層，**非**第二套 Failure Authority）：

| Envelope 欄位 | 語意 |
|---------------|------|
| `ok=true` | Handler **已完成處理**；**不代表** AIU 或 Business 成功（對齊既有 blocked 路徑） |
| `message` | Webhook HTTP response 中的 **non-chat route marker**（如 `phase_9c1_aiu_fail_closed`）；**不是** LINE customer-facing chat message |
| `phase_9c1` | 可投影 `runtime_source`、`failure_reason`、`final_route` 等 operational 欄位 |

**規則：**

- Selector §4.2.1 Formal Failure Result 是**唯一** Failure Authority Contract。
- Envelope 投影欄位**不得**形成第二套 Authority。
- Fail-closed Boundary **不**呼叫 `LineService`。
- Customer-facing failure response 仍屬 §4.5 Out of Scope。

#### 4.4.5 Fail-closed Boundary Invariants

| ID | Invariant |
|----|-----------|
| **FB-1** | AIU Runtime Failure **不得**呼叫 `legacySelection()` |
| **FB-2** | AIU Authority Disabled **不得**呼叫 Legacy Understanding |
| **FB-3** | Formal Failure Result **只含** `runtime_source` + `failure_reason` |
| **FB-4** | `runtime_source` 是**唯一** Fail-closed Authority |
| **FB-5** | Router 收到 Failure Result 後**不得**讀取 Dispatch Intent |
| **FB-6** | Router 收到 Failure Result 後**不得**進入 Human／Knowledge／Product Runtime |
| **FB-7** | Failure Result **不得**觸發 Legacy Parser |
| **FB-8** | Handled Fail-closed **不得以** `null` 表示 |
| **FB-9** | Handled Fail-closed **不得** fallthrough 至 Legacy Flow |
| **FB-10** | Not Applicable、AIU Success、Handled Fail-closed **必須**明確分離 |
| **FB-11** | 本 Boundary **不得**依賴 Compatibility Layer |

### 4.5 Out of Scope — Safe Failure Response Mapping

以下屬**後續 Runtime Integration Scope**；**不在 B0 Failure Boundary**：

| 項目 | 狀態 |
|------|------|
| Safe Failure Response Mapping | ⏳ 後續 Integration 獨立設計 |
| Customer-facing reply text | Out of Scope |
| Human Service／`HumanServiceResponseComposer` 作為 AIU Failure 回覆 | Out of Scope |
| Human Owner／State／`markHumanActive` | Out of Scope（IR-6） |
| Grounding／Composer failure mapping | Out of Scope |
| Channel Dispatch／`FinalReplyGate`／`LineService` | Out of Scope |
| LINE OA 話術／UX | Out of Scope |
| 新 DTO、reply type、route、**customer-facing failure schema** | **禁止**在 B0 新增 |
| Runtime Fail-closed **handoff array**（§4.2.1） | **允許** — 屬 Selector→Router handoff Contract；**不**屬 Response schema |

### 4.6 B0 Validation Criteria（Failure Boundary）

| # | 必須成立 |
|---|----------|
| V-B0-F1 | Production AIU Failure：**不**呼叫 `legacySelection()` 接管 Dispatch |
| V-B0-F2 | **不**使用 `KnowledgeIntentDetector` 結果作正式 routing |
| V-B0-F3 | **不**使用 Legacy parser 補 Intent／Entity |
| V-B0-F4 | **不**產生空白或虛假 Semantic Contract |
| V-B0-F5 | **不**進入 Product Search 或其他 AIU-dependent Runtime |
| V-B0-F6 | AIU Failure **≠** Clarification Execution |
| V-B0-F7 | Failure 可觀測（§4.3）；**不得** Legacy Understanding 掩蓋 |
| V-B0-F8 | AIU Runtime Failure 產出 §4.2.1 正式 2-field Contract（`failure_reason=AIU_RUNTIME_FAILURE`） |
| V-B0-F9 | AIU Authority Disabled 產出同一 Contract（`failure_reason=AIU_AUTHORITY_DISABLED`） |
| V-B0-F10 | Formal Failure Result **不含** §4.2.1 Prohibited Fields |
| V-B0-F11 | Router **只以** `runtime_source === SOURCE_FAIL_CLOSED` 判定 |
| V-B0-F12 | Router 停止 Human／Knowledge／Product Dispatch 及 Legacy Parser |
| V-B0-F13 | Handled Fail-closed 回傳 **non-null** array |
| V-B0-F14 | `handleEvent()` 收到 Handled Fail-closed 時**不得** fallthrough |
| V-B0-F15 | Router envelope **不得**成為第二套 Failure Authority |
| V-B0-F16 | Customer-facing failure response 仍 Out of Scope（§4.5） |

### 4.7 B0 Implementation Gap & Minimal Coding Scope

#### 4.7.1 Implementation Gap（Compatibility Debt → 待移除）

| Gap | Current Code | B0 Target |
|-----|--------------|-----------|
| Flag OFF Legacy MVP | `intent_understanding_authoritative_enabled=false` 走 Legacy Understanding | **移除** Legacy 正式 Understanding 路徑 |
| Shadow Legacy authoritative | Shadow probe + Legacy Dispatch | Shadow 僅 observability；**不得** Legacy Dispatch |
| Selector failure path | `legacySelection()` + Legacy dispatch | Fail-closed；**不** Legacy Understanding |
| Orchestrator downstream | `KnowledgeIntentDetector` always-run；failure 時 `BatsSearchIntentBuilder` 等 | Stop before AIU-dependent Runtime |
| Dual Authority Feature Flag | Flag 切換 Gemini↔Legacy | Flag 僅 rollout／observability |
| Response Mapping | 未定義 | **後續 Integration Scope**（§4.5） |

#### 4.7.2 B0 Minimal Coding Scope（Legacy Authority Removal）

| # | 動作 | 檔案／元件 |
|---|------|-----------|
| MC-1 | **移除** `legacySelection()`；改回傳 §4.2.1 Formal Failure Result | `AiIntentUnderstandingRuntimeSelector.php` |
| MC-2 | AIU Failure／Authority Disabled **傳播 Fail-closed**（§4.2.1→§4.4.2）；禁止 Legacy fallback | `AiIntentUnderstandingRuntimeSelector.php`、`core/saas_router.php` |
| MC-3 | **停止** failure path 呼叫 `KnowledgeIntentDetector` 作 routing | `core/saas_router.php` |
| MC-4 | **停止** failure path 呼叫 Legacy parser（`BatsSearchIntentBuilder` 等） | `core/saas_router.php` |
| MC-5 | 更新測試：`legacy_fallback` 預期改為 Fail-closed | `tests/intent/test_aiu_v2_legacy_freeze.php` 等 |
| MC-6 | Feature Flag **不得**切換至 Legacy Understanding | `config/bats_feature.php`（僅 rollout／observability） |

**B0 Minimal Coding 不含：** Safe Failure Response Mapping、Human Service、Channel Dispatch、Grounding／Composer mapping（§4.5 Out of Scope）。

---

## 5. Coding File Plan（D）

### 5.1 Modify

| File | Change |
|------|--------|
| `core/intent/AiuPromptBuilder.php` | B-10／B-03 對齊 `entities` 31 鍵；禁止 Execution Decision／`semantic_notes` |
| `core/intent/AiuGeminiUnderstandingClient.php` | Parse／shape → Output Contract |
| `core/intent/AiuSemanticJsonNormalizer.php` | Frozen Normalize Mapping；不產出 Routing 欄位 |
| `core/intent/AiIntentUnderstandingRuntime.php` | 移除 Understanding 路徑對 `planDispatch` 的權威產出（改 Runtime） |
| `core/intent/AiIntentUnderstandingResult.php` | 語意欄位與 Routing 欄位分離（或 Runtime 側新 DTO） |
| `core/intent/AiIntentUnderstandingRuntimeSelector.php` | **移除** `legacySelection()` 正式 Dispatch；AIU Failure→Fail-closed |
| `core/intent/AiuProductIntentTranslator.php` | `destination[]`／新 entity 形狀 |
| `core/saas_router.php` | BBC AI Runtime Routing／Dispatch／Coordination 主責 |
| `core/tour_prompt_context_service.php` | 消費新 Contract；不重理解 |
| `core/grounding/*`（必要最小） | GroundedInput 組裝對齊 |
| `config/bats_feature.php` | 僅 flag／allowlist（若需 Phase B 開關）；不改語意 SSOT |

### 5.2 Add（建議）

| File | Purpose |
|------|---------|
| `core/intent/AiuOutputContractValidator.php`（或等價） | Entity Validation 閘道（結構／型別） |
| `core/runtime/BbcAiRuntimeRouter.php`（或等價，最小） | 依 Contract 自行 Routing（自 saas_router 抽出可測單元） |
| `tests/intent/test_aiu_v2_output_contract_normalize.php` | Output→Normalize 契約 |
| `tests/runtime/test_bbc_ai_runtime_routing.php` | Routing 不依賴 dispatch_plan |
| `tests/response/test_composer_no_new_facts_integration.php` | Composer Express 回歸 |

### 5.3 Tests（必跑／擴充）

| Test | 焦點 |
|------|------|
| `tests/intent/test_aiu_v2_legacy_freeze.php` | Production Fail-closed；**不得** Legacy Understanding fallback |
| `tests/intent/test_aiu_p1_*` | Entity／Source mapping |
| `tests/product_sources/test_saas_router_line_oa_0703_fix.php` | LINE OA 路徑 |
| `tests/response/test_grounded_contract_foundation.php` | Composer contract |
| `tests/search/test_final_reply_gate.php` | Reply gate |
| 新增上表 Add tests | Phase B 契約 |

---

## 6. Coding Sequence（E）— 最小風險順序

| Step | 名稱 | 內容 | 風險 |
|------|------|------|------|
| **B0** | Contract Alignment | Prompt＋Parse＋Normalize 對齊 `entities`；**停止** AIU 產出 `dispatch_plan` 權威 | 高（契約根） |
| **B1** | Runtime Routing | saas_router／Router 依 `intent`／`clarification`／State 自行 Dispatch | 高 |
| **B2** | Product Module handoff | Translator＋TourPromptContext 消費新 Contract | 中 |
| **B3** | Knowledge／Human／Clarification | 分支改吃 Runtime Dispatch；不重判 | 中 |
| **B4** | Grounding→Composer | Facts-only handoff；禁止 Composer 前「第二理解」擴權 | 中 |
| **B5** | LINE OA＋Flags | travel_b 驗證；AIU failure 可觀測（§4.3）；**不得** Legacy Understanding fallback | 中 |
| **B6** | Regression pack | 全套 Validation Plan | 低～中 |

**規則：** 未完成 B0／B1 不得開 B2+ 行為變更。

---

## 7. Validation Plan（F）

| Case | 目標 | Pass 條件 |
|------|------|-----------|
| Product | 京都自由行／歐洲賞花等 | Gemini→Normalize→Product Module→Composer→LINE；destination 獨立；無語意重寫 |
| Knowledge | FAQ／服務項目 | Dispatch→Knowledge；Composer 不搜尋 |
| Human | 轉真人 | Dispatch→Human；FinalReplyGate／owner 一致 |
| Clarification | 模糊日期 | `clarification.required` 投影；Runtime 執行追問；Normalize 不重算日期 |
| Invalid JSON | Gemini 壞 JSON | AIU Failure→Fail-closed（§4.2）；可觀測；不靜默幻覺；**禁止** Legacy Understanding |
| Missing Entity | 缺 destination／entities 鍵 | Contract Validation Failure→§4.2；不 invent；**禁止** 空白 contract 繼續 Runtime |
| AIU Failure | Disabled／transport／contract／normalize／runtime | §4.2 + §4.4 Fail-closed；V-B0-F1～V-B0-F16 |
| Composer | 有／無 facts | No New Facts；Persona 不改 Facts |
| LINE OA | travel_b sno | reply／push 正確；signature／token |
| Regression | 既有 intent／search／composer／gate tests | 全綠或明示允許差異 |

---

## 8. Risk Review（G）

| 風險域 | 風險 | 緩解 |
|--------|------|------|
| **Contract** | `entity` vs `entities`；Result 9 欄位含 dispatch_plan | B0 強制對齊 Frozen；**禁止** Compatibility 雙讀／Dual Authority |
| **Legacy** | KnowledgeIntentDetector／BatsSearchIntentBuilder 仍在 | **Compatibility Debt**（§4.0.1）；**不得**作正式 Understanding Authority；AIU Failure→Fail-closed（§4.2）；Shadow／Parity **不得**改 Dispatch；failure 必須 log（§4.3）；B0 Minimal Coding 須移除（§4.7.2） |
| **Routing** | 路由仍在 AIU `planDispatch` | B1 移至 BBC AI Runtime；刪除對 dispatch_plan 依賴 |
| **Reply** | Product 文案在 Composer 前生成 | B4 收斂：Module 只交 Facts；Composer Express（可分階段，generative flag） |
| **Semantic** | DateResolver 可能「重算」日期 | 對齊 NP：僅映射 Gemini 已解析區間；政策外推需標註技術債 |
| **Responsibility** | saas_router 過重 | 抽 Router 單元；Module 邏輯不回流入 Core |

---

## 9. Decision（H）

### **READY FOR CODING**

**條件式就緒（Conditional Ready）：**

- Phase A Frozen SSOT 已 Commit（`f73124f`）  
- Discovery 已對到真實檔案與 travel_b 路徑  
- Coding Sequence B0→B1 已定義為強制閘道  

**Coding 開始前必須遵守：**

1. 不得修改任何 Frozen 文件  
2. 必須先做 **B0 Contract Alignment**，再做行為路由  
3. IR-6 範圍外（Memory／State／Human Isolation）不得擴 scope  
4. 每步以 Validation Plan 對應測試護航  

---

## 10. Runtime Integration Gap Matrix

> **定位：** Frozen Architecture ↔ Existing Code 的唯一 Gap SSOT。  
> **用途：** Coding Scope／Validation Scope／Commit Scope。  
> **規則：** GM-1～GM-4（只描述 Current→Target；可 Coding；一 Gap 一 Phase；可 Validation）。  
> **不修改**既有 §1～§9 章節與任何 Frozen 文件。

### 10.1 Gap Matrix

| ID | Frozen SSOT | Current Code | Gap | Coding Phase | Validation |
|----|-------------|--------------|-----|--------------|------------|
| **B0-1** | Gemini Output Contract：`entities`（31 鍵 object） | `AiuGeminiUnderstandingClient`／Prompt 使用 `entity`（singular） | Contract Alignment：輸出形狀改為 `entities` | **B0** | JSON Contract／Parse shape test |
| **B0-2** | Output Contract：無 `semantic_notes` | Client shape 仍含／允許 `semantic_notes` | 移除非 Contract 欄位 | **B0** | JSON Contract（缺／有皆不進 Runtime） |
| **B0-3** | Normalize：Translation Only；不產出 Execution Decision | `AiIntentUnderstandingRuntime::planDispatch()` 寫入 `dispatch_plan`／`execution_hint` 進 Result | 停止 AIU Understanding 路徑產出 Routing 欄位 | **B0** | Normalize／AIU Result：無 `dispatch_plan`／`execution_hint` |
| **B0-4** | Normalize：保留 `destination[]`；不指定 Primary | Normalizer／下游多為單一 `destination` string | Entity Mapping 對齊 Frozen（array 原樣） | **B0** | Normalize Test：`destination` 為 array |
| **B0-5** | Normalize：`date_range`→`date_from`／`date_to` 僅映射、不重算語意 | `AiuDateEntityResolver` 可能依政策推算區間 | 對齊 NP：僅承接 Gemini 已解析值（或標明允許之結構映射邊界並測） | **B0** | Date mapping test（模糊日期→null，不 invent） |
| **B0-6** | Prompt B-10／Output Schema = Frozen Contract | `AiuPromptBuilder` B-03／B-10 仍描述舊 `entity` 形狀 | Prompt Scheme 對齊 Frozen Output Contract | **B0** | Prompt／live shadow：輸出鍵名與禁止欄位 |
| **B0-7** | Production：AIU Failure→Fail-closed Boundary（§4.2.1／§4.4） | `legacySelection()` 接管；downstream Legacy parser | Fail-closed；停止 AIU-dependent Runtime；**不** Legacy Understanding | **B0** | V-B0-F1～V-B0-F16 |
| **B0-8** | Production Understanding 僅 Gemini／AIU v2；無 Dual Authority | Flag OFF Legacy MVP；Shadow Legacy authoritative；Feature Flag 切換 | **移除** Legacy Understanding 正式授權；Flag 僅 rollout／observability | **B0** | §4.0.1 Compatibility Debt 清零；V-B0-F1～V-B0-F7 |
| **B1-1** | BBC AI Runtime：自行 Routing／Dispatch／Coordination | Selector／saas_router 依 AIU `dispatch_plan` 映射 legacy intent | 將 Routing 責任移至 BBC AI Runtime（依 intent／clarification／State） | **B1** | Runtime Routing Test |
| **B1-2** | Runtime 不依賴 `dispatch_plan`／`execution_hint` | `AiIntentUnderstandingRuntimeSelector::mapToLegacyIntentType()` 讀 `dispatch_plan` | 移除對禁止欄位的執行依賴 | **B1** | Selector／Router：無 dispatch_plan 仍可路由 |
| **B1-3** | Runtime Core 不承擔 Module Logic | Routing 決策散落 `attemptPhase9C1StructuredPilotPath` 與 AIU Result | 抽出／集中 Runtime Routing 決策點（可測單元） | **B1** | Unit：intent×clarification×owner → module target |
| **B2-1** | Product Module 消費 Runtime Contract | `AiuProductIntentTranslator`＋`TourPromptContextService` 吃舊 entity／單一 destination | Product Runtime Handoff 對齊新 Contract | **B2** | Product Search（Production AIU path） |
| **B2-2** | Keyword／Source Mapping 屬 Adapter；Normalize 不組 Keyword | HostB／SourceQueryMapper 與 translator 邊界需吃獨立 Entity Token | 確認 Product 路徑不回寫 Understanding；Adapter 自組 query | **B2** | Source query／entity token mapping tests |
| **B2-3** | Clarification Detection≠Execution；Product 可執行追問 | Clarification 併入 product 路徑（formatter／policy） | Product 路徑正式承接 Runtime 派送之 Clarification Execution | **B2** | Clarification（模糊日期／缺槽）→追問文案，不重理解 |
| **B3-1** | Knowledge Module 由 Runtime Dispatch 進入 | `TenantPrivateKnowledgeRuntime`；入口仍混 legacy detector 訊號 | Knowledge Runtime Integration：只吃 Runtime Dispatch | **B3** | Knowledge QA |
| **B3-2** | Human Module 由 Runtime Dispatch 進入 | `HumanServiceResponseComposer`＋FinalReplyGate／status | Human Runtime Integration：Dispatch→Human；不重判 Intent | **B3** | Human Service |
| **B3-3** | Legacy 不得作 Understanding Authority；Shadow／Parity 不得改 Dispatch | `KnowledgeIntentDetector` 仍 always-run（parity） | **移除** Legacy 正式 routing；Shadow／Parity 僅 observability | **B0／B3** | routing 以 AIU／Runtime 為準；parity log 不改 Dispatch |
| **B4-1** | Composer：Express；僅 Runtime Facts；No New Facts | Product 文案常於 Composer 前由 Gemini reply／Formatter 產生；generative OFF | Runtime／Module→Grounding→Composer handoff：Composer 不搜尋、不補 Facts | **B4** | Grounded Response／Validator |
| **B4-2** | Persona Read Only；不改 Facts | Persona／tone 注入路徑需確認不改 grounded_facts | Composer Persona 僅語氣／排版 | **B4** | Composer：同 Facts 不同措辭不改數值／URL |
| **B5-1** | Composer→LINE Reply／Push | `LineService::replyToLine`／`pushToLine`＋ACK／FinalReplyGate | 確認最終 outbound 僅 Composer（或 Gate 允許之）輸出 | **B5** | LINE OA travel_b |
| **B5-2** | Feature Flag 僅 rollout／observability；AIU failure 可觀測 | `bats_feature.php` AIU／grounding flags | **不得** Gemini↔Legacy Understanding 切換；§4.3 failure log 對齊 | **B5** | Flag 僅 rollout；failure observability |
| **B6-1** | Full Regression | 既有 intent／search／composer／gate／LINE tests | 全套回歸護航 B0～B5 | **B6** | Regression pack 全綠或明示允許差異 |

### 10.2 Coverage Check

| Coding Phase | Gap IDs | 涵蓋 |
|--------------|---------|------|
| B0 Contract Alignment | B0-1～B0-8 | ✅ |
| B1 Runtime Routing | B1-1～B1-3 | ✅ |
| B2 Product Handoff | B2-1～B2-3 | ✅ |
| B3 Knowledge／Human／Legacy lock | B3-1～B3-3 | ✅ |
| B4 Composer | B4-1～B4-2 | ✅ |
| B5 LINE／Flags | B5-1～B5-2 | ✅ |
| B6 Regression | B6-1 | ✅ |

**Out of Scope（非 Gap；IR-6）：** Conversation Memory Runtime、Conversation State Runtime、Human Service Isolation — **不**列入本 Matrix。

### 10.3 Blocking Gaps for Coding Start

| 阻擋？ | Gap | 說明 |
|--------|-----|------|
| **是（必須先做）** | **B0-1～B0-8** | 契約未對齊、Legacy Authority 未移除、Fail-closed policy 未對齊前不得開 B1+ 行為變更 |
| **是（B0 後立即）** | **B1-1～B1-3** | Routing 仍在 AIU 則違反 Frozen Runtime |
| 否（後續） | B2～B6 | 依 Sequence 推進；不阻擋「開始 B0 Coding」 |

### 10.4 Gap Matrix Decision

### **READY FOR CODING**

Gap Matrix 已完整對應 B0～B6；無未定義之可 Coding 主線缺口。  
Coding 必須嚴格依 **B0 → B1 → … → B6**，並以各列 Validation 為 Pass 條件。

---

## 11. Current Runtime Intent Contract Architecture Freeze

> **定位：** AIU v2 Current Runtime Intent Contract 唯一架構 SSOT（Architecture Freeze）。  
> **狀態：** **Frozen** — 本節為 L3 Coding Specification 之正式依據。  
> **依據：** AIU v2 Current Runtime Intent Contract Refinement Proposal（READY FOR ARCHITECTURE FREEZE）。  
> **規則：** Two Layers 為兩個**現行**架構責任，**不是** Compatibility dual-read／dual-write。

### 11.1 Official Flow（Frozen）

```text
Customer Utterance
        ↓
AIU Prompt Scheme
        ↓
Gemini Semantic Result
        ↓
AIU Normalize
        ↓
AiIntentCategory
        ↓
AiRuntimeIntentTranslator
        ↓
AiRuntimeIntent
        ↓
BBC AI Runtime Execute / Govern
```

**Invariant：** Gemini 是唯一 Understanding Source；Runtime 僅正規化、翻譯（current-to-current）、執行與治理；**不得**重新理解 Customer Utterance。

### 11.2 Responsibilities

#### BBC AI GEMINI SOLO（Frozen — 不可變）

> Gemini 是 BBC AI 唯一的 Intent Understanding、Entity Extraction 與 Semantic Decision 來源；BBC AI Runtime 只能正規化、執行及治理 Gemini Semantic Result。任何其他機制均不得理解 Customer Utterance、產生語意條件或決定 Runtime Route。

#### Two-Layer Current Intent Contract（Frozen）

| Layer | Canonical Owner | Responsibility |
|-------|-----------------|----------------|
| **Layer 1 — Gemini Semantic Category** | `AiIntentCategory` | 定義並驗證 Normalize 後之 Gemini-facing semantic category；承載於 `AiIntentUnderstandingResult.intent` |
| **Layer 2 — Current Runtime Intent** | `AiRuntimeIntent` | 定義並驗證**唯一** Current Runtime `intent_type` 值集；作為所有 Runtime Consumer 之唯一 Intent 來源 |

**Layer 1 不得：** 擁有 Runtime routing label、執行 Runtime routing、含 Legacy alias、解析 Customer Utterance。

**Layer 2 不得：** 理解 Customer Utterance、擁有 routing implementation、含 Gemini wire alias、含 Legacy 或 Compatibility 值。

#### `AiIntentCategory`（Layer 1 — Frozen）

- **Define：** `Product Search`、`Knowledge`、`Ambiguous`、`human_service`
- **Validate：** `isValid()` / `assertValid()` on normalized semantic category
- **Carrier：** `AiIntentUnderstandingResult.intent`
- **Role：** Normalize 輸出之 Gemini-facing semantic category 唯一 canonical enum

#### `AiRuntimeIntent`（Layer 2 — Frozen）

- **Define：** 僅下列 Current Runtime Intent 值：
  - `product_search`
  - `knowledge_query`
  - `ambiguous`
  - `human_service_request`
- **Validate：** `isValid()` / `assertValid()` on Runtime Intent value
- **Role：** 所有 Runtime Consumer 消費 `intent_type` 時，值**必須**來自本 Contract

#### `AiRuntimeIntentTranslator`（Translation Boundary — Frozen）

**Contract：**

```text
AiIntentUnderstandingResult → AiRuntimeIntentTranslator → AiRuntimeIntent
```

| 項目 | 規格 |
|------|------|
| **Input** | 已 Normalize 之 `AiIntentUnderstandingResult`（含合法 `AiIntentCategory`） |
| **Output** | 一個合法 `AiRuntimeIntent` 值 |
| **Direction** | 單向、current-to-current |
| **Prohibited Input** | Customer Utterance、任意字串、Legacy 值、alias、fallback intent |
| **Invalid Category** | Unsupported／missing／invalid Category → **Fail-closed**（§4.2.1）；**不**產出 `intent_type` |
| **Inference** | **禁止** infer Intent；僅依 Normalize 後 Category 做 1:1 Current Translation |

**Future Coding 必須刪除（不得保留、包裝、更名或重現 Legacy 責任）：**

- `mapToLegacyIntentType()`
- `AiIntentUnderstandingRuntimeSelector::INTENT_TYPE_*`
- Consumer-owned duplicate Intent constants
- 誤導之 Legacy／Compatibility comment 與 mapping

#### `AiIntentUnderstandingRuntimeSelector`（Frozen）

- **僅負責：** AIU success（`SOURCE_AIU`）或 Fail-closed（`SOURCE_FAIL_CLOSED`）之 source selection
- **Success path：** 委派 `AiRuntimeIntentTranslator` 產出 `intent_type`；Selector **不得**定義或擁有 Intent 值
- **Failure path：** §4.2.1 Formal Failure Result（**不含** `intent_type`）

#### Runtime Consumers（Frozen）

- **僅消費** `AiRuntimeIntent` canonical 值（透過 `intent_type` transport field）
- **禁止** 定義 local Intent constants
- **禁止** 重新理解 Customer Utterance

#### `intent_type`（Transport Field — Frozen）

- **保留** 為 Current Runtime Intent transport field 名稱
- **值來源：** **僅** `AiRuntimeIntent`（經 `AiRuntimeIntentTranslator`）
- **禁止：** alias、dual-read、dual-write、Legacy fallback

#### `clarification_required`（Orthogonal Flag — Frozen）

- **獨立** 於 Runtime Intent；**不**建立或取代 Runtime Intent category
- **範例：** `intent_type=product_search` + `clarification_required=true` → Product Runtime 依既有 fail-closed／date policy 停止執行並 clarify；**不得** reinterpret Customer Utterance
- **Clarification 不是** 新的 Intent category

#### Fail-closed Boundary（Frozen — 與 §4 對齊）

| 情境 | `intent_type` | Runtime Effect |
|------|:-------------:|----------------|
| Invalid／Unsupported Gemini category | **None** | Selector §4.2.1 Fail-closed；Router Stop-Dispatch（§4.4.2） |
| AIU Runtime Failure | **None** | 同上 |
| AIU Authority Disabled | **None** | 同上 |
| Valid Category + Translation success | **One `AiRuntimeIntent`** | 正常 Dispatch |

### 11.3 Out of Scope

本 Architecture Freeze **不**涵蓋、**不**授權下列工作：

| 項目 | 狀態 |
|------|------|
| Customer Utterance parsing（Gemini 以外） | **禁止** |
| Legacy 或 Compatibility mapping／adapter／bridge／alias／wrapper | **禁止** |
| Runtime re-understanding | **禁止** |
| Composer／Persona presentation | Out of Scope |
| Product Execute Contract（`BatsSearchIntent`、`AiuProductIntentTranslator`） | Out of Scope |
| `RuntimeType`（Grounded Composer 執行面） | Out of Scope — 與 routing `intent_type` 不同責任 |
| `BatsSearchIntent`（Product Execute DTO） | Out of Scope |
| AIU Prompt Scheme 變更 | Out of Scope |
| Date Pipeline 修正 | Out of Scope |
| Regression 修正（含 `test_saas_router_phase9c1_pilot.php`、`test_line_search_footer_ux.php`） | Out of Scope |
| Coding implementation（本節為 Architecture Freeze only） | 後續 L3 Coding Specification |

### 11.4 Frozen Intent Matrix

| Gemini Semantic Category | Current Runtime Intent | Runtime Effect | Invalid Handling |
|--------------------------|------------------------|----------------|------------------|
| `Product Search` | `product_search` | Product Runtime | Normalize invalid → Fail-closed（§4.2.1）；無 `intent_type` |
| `Knowledge` | `knowledge_query` | Knowledge Runtime | 同上 |
| `Ambiguous` | `ambiguous` | Clarification flow | 同上 |
| `human_service` | `human_service_request` | Human Service Runtime | 同上 |
| Invalid／Unsupported | **None** | Fail-closed | Selector 2-field Formal Failure Result；Router Stop-Dispatch |

**Clarification：** 維持為 `clarification_required` 正交旗標；**不**新增 Intent category。Product Search 可同時 `intent_type=product_search` 且 `clarification_required=true`。

### 11.5 Frozen Removal Requirements（Future Coding）

| Obsolete Item | Required Future Action | Compatibility Retained |
|---------------|------------------------|:----------------------:|
| `mapToLegacyIntentType()` | **DELETE**；由 `AiRuntimeIntentTranslator` 取代 | No |
| `AiIntentUnderstandingRuntimeSelector::INTENT_TYPE_*` | **DELETE**；消費者改 `AiRuntimeIntent::*` | No |
| `RecommendationEligibilityEvaluator::INTENT_*` 等 consumer duplicate | **DELETE**；改引用 `AiRuntimeIntent` | No |
| 其他 consumer-local Intent 常數或字串 literal | **DELETE**／改 canonical reference | No |
| Legacy／Compatibility 誤導 comment（如 legacy intent_type mapping） | **DELETE** 或更正為 Current Contract 語意 | No |

**禁止：** 以任何名稱保留、包裝、更名或重現 `mapToLegacyIntentType()` 之 Legacy 責任。

### 11.6 Future Coding Scope（Minimum — Frozen Boundary）

**Production（後續 L3 Coding，本 Freeze 不實作）：**

| 動作 | 元件 |
|------|------|
| **新增** | `core/intent/AiRuntimeIntent.php` |
| **新增** | `core/intent/AiRuntimeIntentTranslator.php` |
| **修改** | `AiIntentUnderstandingRuntimeSelector` — 移除 Intent 定義；委派 Translator |
| **修改** | `core/saas_router.php` — routing 改 `AiRuntimeIntent::*` |
| **修改** | `RecommendationEligibilityEvaluator` — 移除 duplicate constants |

**Tests（後續 Validation）：** `test_ai_runtime_intent_translator.php`（新建）、selector／router／conversation 相關 regression 更新。

**Regression 關係（本 Freeze 不修復）：**

| Test | Related to Intent Contract |
|------|:--------------------------:|
| `test_saas_router_phase9c1_pilot.php` | No（clarification routing vs test setup） |
| `test_line_search_footer_ux.php` | No（Persona presentation） |

---

## 12. Current Runtime Intent Contract — L3 Coding Specification

> **定位：** §11 Architecture Freeze 之**唯一** Minimal Coding 實作規格。  
> **狀態：** L3 Coding Specification — **Ready for Minimal Coding**。  
> **規則：** 本節僅補充實作細節；**不得**修改 §11 Frozen Matrix、Two-Layer 責任、或 BBC AI GEMINI SOLO 原文。  
> **實作者：** Claude Sonnet 4.6（或同等）須**嚴格依本節**實作；遇架構決策缺口須回報 **BLOCKED**，不得自行發明。

### 12.1 `AiRuntimeIntent` — Production Contract

| 項目 | 規格 |
|------|------|
| **Path** | `core/intent/AiRuntimeIntent.php` |
| **PHP** | 7.4-compatible；`declare(strict_types=1);`；`final class` |
| **Pattern** | 對齊 `AiIntentCategory` 之封閉 enum VO 慣例（`isValid`／`all`／`assertValid`） |
| **Responsibility** | 定義並驗證**唯一** Current Runtime `intent_type` 值集 |

**Exact constants（字面值固定，不得增刪改名）：**

| Constant | Value |
|----------|-------|
| `PRODUCT_SEARCH` | `product_search` |
| `KNOWLEDGE_QUERY` | `knowledge_query` |
| `AMBIGUOUS` | `ambiguous` |
| `HUMAN_SERVICE_REQUEST` | `human_service_request` |

**Required public API：**

| Method | Responsibility |
|--------|----------------|
| `isValid(string $intent): bool` | 成員驗證 |
| `all(): array` | 回傳全部合法 Runtime Intent 值（`list<string>`） |
| `assertValid(string $intent): void` | 非法值拋 `\InvalidArgumentException`（message 含非法值） |

**Invalid behavior：** 任何不在上表四值者 → `isValid` 回傳 `false`；`assertValid` 拋例外。

**Prohibited（本 class 不得包含）：**

- Customer Utterance parsing
- Routing／Dispatch implementation
- Gemini wire alias（如 `Product Search`、`knowledge`）
- Legacy 或 Compatibility 值（如 `legacy_intent_type`、`tour_query`）
- `mapToLegacyIntentType` 或任何 Legacy mapping 方法

**Dependencies：** `require_once` 僅限同目錄必要檔；**不** require Selector／Router。

### 12.2 `AiRuntimeIntentTranslator` — Current Translation Boundary

| 項目 | 規格 |
|------|------|
| **Path** | `core/intent/AiRuntimeIntentTranslator.php` |
| **PHP** | 7.4-compatible；`declare(strict_types=1);`；`final class` |
| **Input** | `AiIntentUnderstandingResult`（**已** Normalize；`intent` 已通過 `AiIntentCategory::assertValid`） |
| **Output** | 一個合法 `AiRuntimeIntent` 字串值 |

**Required entry point：**

```text
AiRuntimeIntentTranslator::fromUnderstandingResult(AiIntentUnderstandingResult $result): string
```

**Frozen Matrix（唯一合法映射 — 與 §11.4 一致）：**

| `AiIntentUnderstandingResult.getIntent()`（`AiIntentCategory`） | Output（`AiRuntimeIntent`） |
|----------------------------------------------------------------|----------------------------|
| `AiIntentCategory::PRODUCT_SEARCH` | `AiRuntimeIntent::PRODUCT_SEARCH` |
| `AiIntentCategory::KNOWLEDGE` | `AiRuntimeIntent::KNOWLEDGE_QUERY` |
| `AiIntentCategory::AMBIGUOUS` | `AiRuntimeIntent::AMBIGUOUS` |
| `AiIntentCategory::HUMAN_SERVICE` | `AiRuntimeIntent::HUMAN_SERVICE_REQUEST` |

**Validation order（固定）：**

1. 自 `$result->getIntent()` 讀取 Category（**僅**此欄位；**不**讀 utterance）
2. 依上表做 1:1 映射（`switch`／`if` 比對 `AiIntentCategory::*` 常數）
3. 對映射結果呼叫 `AiRuntimeIntent::assertValid($mapped)`
4. 回傳映射字串

**Clarification：** `clarification_required`／`clarification_reason` **不**參與映射；`product_search` + `clarification_required=true` 仍映射為 `product_search`。

**Invalid／Fail-closed behavior：**

- 若 `getIntent()` 非上表四值之一（理論上 Normalize 已阻擋；Translator 仍須防禦）→ 拋 `\InvalidArgumentException`
- Selector `resolve()` **catch** 該例外 → 回傳 §4.2.1 Formal Failure Result（**無** `intent_type`；`failure_reason=AIU_RUNTIME_FAILURE`）
- **禁止** default／fallback Intent（含映射至 `ambiguous` 作為 catch-all）

**Prohibited：**

- 接受 Customer Utterance 參數
- 接受任意 caller 提供之 Intent 字串參數
- Infer Intent（不得讀 entities 推斷 route）
- 接受 retired alias
- 保留、包裝或更名 `mapToLegacyIntentType()`

**Dependencies：** `require_once` `AiIntentUnderstandingResult.php`、`AiIntentCategory.php`、`AiRuntimeIntent.php`。

### 12.3 `AiIntentUnderstandingRuntimeSelector` — Correction Specification

**Future final responsibility（僅下列）：**

| Step | Action |
|------|--------|
| 1 | 判定 tenant AIU authority（既有 `isAuthoritativeEnabled`） |
| 2 | Authority disabled → §4.2.1 Fail-closed（`AIU_AUTHORITY_DISABLED`） |
| 3 | 呼叫 `AiIntentUnderstandingRuntime::understand()` |
| 4 | Success → 呼叫 `AiRuntimeIntentTranslator::fromUnderstandingResult($result)` |
| 5 | 回傳 success array：`runtime_source=aiu`、`intent_type`（Translator 輸出）、`human_blocked`、`aiu_intent`、`aiu_result` |
| 6 | AIU exception 或 Translator exception → §4.2.1 Fail-closed（`AIU_RUNTIME_FAILURE`） |

**Success handoff 欄位（不變）：** `intent_type` 鍵名保留；值**僅**來自 Translator。

**Future Coding 必須 DELETE：**

- `public const INTENT_TYPE_*`（全部四個）
- `public static function mapToLegacyIntentType(...)`
- class doc 中「legacy intent_type mapping」等誤導描述

**Dependency changes：**

- `require_once` 新增 `AiRuntimeIntentTranslator.php`（及 transitively `AiRuntimeIntent.php`）
- **不得** require `AiRuntimeIntent` 於 Selector 本體（除非僅型別註解不需要；比對用 Translator 輸出即可）

**Unchanged behavior：**

- `SOURCE_AIU`／`SOURCE_FAIL_CLOSED` 常數與語意
- `isAuthoritativeEnabled()`、`resolveProductUnderstandingTrace()`、`loadDefaultConfig()`
- Fail-closed 僅 2 fields（§4.2.1）
- `human_blocked` 邏輯（`ConversationOwner::HUMAN`）

### 12.4 Consumer Migration Inventory

| Consumer | Current Dependency | Minimum Future Change | Canonical Dependency | Unchanged Behavior |
|----------|-------------------|----------------------|---------------------|-------------------|
| `core/saas_router.php` | `AiIntentUnderstandingRuntimeSelector::INTENT_TYPE_*` 比對（L1240、L1275、L1456、L1503 等） | `require_once AiRuntimeIntent.php`；所有 routing 比對改 `AiRuntimeIntent::*` | `AiRuntimeIntent` | Human／Knowledge／Product dispatch 分支邏輯；`intent_type` transport 鍵名；clarification 子路徑 |
| `core/conversation/RecommendationEligibilityEvaluator.php` | 自有 `INTENT_PRODUCT_SEARCH`／`INTENT_KNOWLEDGE_QUERY`／`INTENT_AMBIGUOUS` | **DELETE** 三常數；推薦資格改 `ELIGIBILITY_BY_RUNTIME_INTENT`（keys = `AiRuntimeIntent::*`）；docblock 改引用 `AiRuntimeIntent` | `AiRuntimeIntent` | 推薦資格判定邏輯；`human_service_request` 不在 eligibility map（`?? []` deny） |
| `core/conversation/ConversationRuntimeFacade.php` | `facts['intent_type']` string passthrough | **無常數變更**；docblock 註明值須為 `AiRuntimeIntent` canonical | 間接消費 `AiRuntimeIntent` | 不推測 intent；僅傳遞 |
| `core/conversation/ConversationRuntimeShadowProbe.php` | `params['intent_type']` passthrough | **無 production 常數**；docblock 對齊 | 間接消費 | observability only |
| `core/conversation/ConversationPolicyRuntime.php` | 透過 `RecommendationEligibilityEvaluator` 評估 string | **無 production 變更**（Evaluator 遷移後自動 canonical） | `AiRuntimeIntent`（經 Evaluator） | policy 判定 |

**Test consumers（必須更新，非 production whitelist）：**

| Test | Current Dependency | Minimum Future Change |
|------|-------------------|----------------------|
| `tests/intent/test_ai_intent_understanding_runtime_selector.php` | `INTENT_TYPE_*`、`mapToLegacyIntentType()` | 改 `AiRuntimeIntent::*`；刪 `mapToLegacyIntentType` 直接測試；新增 Translator delegation 斷言 |
| `tests/intent/test_aiu_v2_prompt8_regression.php` | `INTENT_TYPE_HUMAN_SERVICE_REQUEST` | 改 `AiRuntimeIntent::HUMAN_SERVICE_REQUEST` |
| `tests/product_sources/test_saas_router_phase9c1_pilot.php` | `INTENT_TYPE_KNOWLEDGE_QUERY` | 改 `AiRuntimeIntent::KNOWLEDGE_QUERY` |
| `tests/product_sources/test_saas_router_line_oa_0703_fix.php` | `INTENT_TYPE_*` | 改 `AiRuntimeIntent::*` |
| `tests/knowledge/test_tenant_private_knowledge_runtime.php` | Selector vocabulary smoke | 改 `AiRuntimeIntent::*` 或刪除重複 smoke（改由 Contract test 覆蓋） |
| `tests/conversation/test_conversation_runtime_facade.php` | `RecommendationEligibilityEvaluator::INTENT_*` | 改 `AiRuntimeIntent::PRODUCT_SEARCH` |
| `tests/conversation/test_conversation_policy_runtime.php` | 字串 literal `'knowledge_query'`、`'ambiguous'` | 改 `AiRuntimeIntent::*` |
| `tests/conversation/test_conversation_runtime_shadow_probe.php` | 字串 literal `'product_search'` | 改 `AiRuntimeIntent::PRODUCT_SEARCH` |

**Consumer rules（Frozen）：**

- 比對**僅**對 `AiRuntimeIntent::*`
- 不得定義 local Runtime Intent 常數
- 不得翻譯 `AiIntentCategory`
- 不得 reinterpret Customer Utterance
- `intent_type` 保留為 transport field 名稱

### 12.5 Clarification and Fail-closed（Coding Behavior）

#### Valid Product Clarification

```text
intent_type = product_search
clarification_required = true
```

| 項目 | 規格 |
|------|------|
| Runtime Intent | **維持** `product_search`（Translator 不受 clarification 影響） |
| Product execution | **停止**（既有 `isClarificationRequired()` gate） |
| Clarification flow | 執行既有 `DateClarificationLineFormatter`／policy |
| 禁止 | 新建 Intent category；reinterpret utterance |

#### Invalid／Unsupported Gemini Intent

```text
runtime_source = fail_closed
failure_reason = AIU_RUNTIME_FAILURE | AIU_AUTHORITY_DISABLED
intent_type = absent
```

| 項目 | 規格 |
|------|------|
| Dispatch | **無** Runtime dispatch |
| Default Intent | **禁止** |
| Legacy fallback | **禁止** |
| Router | §4.4.2 Stop-Dispatch（既有） |

### 12.6 Exact Coding Whitelist

#### Production whitelist（僅下列檔案允許修改）

| File | Minimum Future Change | Reason | Unchanged Behavior |
|------|----------------------|--------|-------------------|
| **NEW** `core/intent/AiRuntimeIntent.php` | 新增 Contract class（§12.1） | Canonical Runtime Intent owner | N/A（新檔） |
| **NEW** `core/intent/AiRuntimeIntentTranslator.php` | 新增 Translator（§12.2） | Current translation boundary | N/A（新檔） |
| `core/intent/AiIntentUnderstandingRuntimeSelector.php` | 刪 `INTENT_TYPE_*`、`mapToLegacyIntentType()`；委派 Translator（§12.3） | Selector 回歸 Select-only | Fail-closed 2-field；authority gate |
| `core/saas_router.php` | routing 比對改 `AiRuntimeIntent::*`；`require_once` 新 Contract | 最大 Runtime consumer | Dispatch 分支結構；clarification 路徑 |
| `core/conversation/RecommendationEligibilityEvaluator.php` | 刪 duplicate `INTENT_*`；改用 Current Policy `ELIGIBILITY_BY_RUNTIME_INTENT`（canonical `AiRuntimeIntent` keys） | Conversation policy consumer | 推薦資格邏輯 |

**不納入 whitelist（本 changeset 禁止修改）：**

| Exclusion | Reason |
|-----------|--------|
| `config/bats_feature.php` | 非 immediate Contract consumer；misleading comment 另案 |
| `core/intent/AiIntentCategory.php` | Layer 1 Frozen；本 changeset 不變 |
| `core/intent/AiuSemanticJsonNormalizer.php` | Normalize 邊界已 Frozen |
| `core/intent/AiuProductIntentTranslator.php` | Product Execute；Out of Scope |
| `core/search/BatsSearchIntent.php` | Product Execute DTO |
| `core/response/RuntimeType.php` | Composer 執行面；不同責任 |
| Composer／Persona／Prompt Scheme | §11.3 Out of Scope |
| `test_saas_router_phase9c1_pilot.php` 行為修復 | 授權外既有 FAIL |
| `test_line_search_footer_ux.php` | 授權外既有 FAIL |

#### Test whitelist（Contract changeset 必須同步）

| Action | File |
|--------|------|
| **NEW** | `tests/intent/test_ai_runtime_intent_translator.php` |
| **UPDATE** | `tests/intent/test_ai_intent_understanding_runtime_selector.php` |
| **UPDATE** | `tests/intent/test_aiu_v2_prompt8_regression.php` |
| **UPDATE** | `tests/product_sources/test_saas_router_phase9c1_pilot.php`（僅常數引用） |
| **UPDATE** | `tests/product_sources/test_saas_router_line_oa_0703_fix.php` |
| **UPDATE** | `tests/knowledge/test_tenant_private_knowledge_runtime.php` |
| **UPDATE** | `tests/conversation/test_conversation_runtime_facade.php` |
| **UPDATE** | `tests/conversation/test_conversation_policy_runtime.php` |
| **UPDATE** | `tests/conversation/test_conversation_runtime_shadow_probe.php` |

### 12.7 Regression Specification

#### New Contract tests — `tests/intent/test_ai_runtime_intent_translator.php`

| # | Coverage |
|---|----------|
| T-1 | 四組 Category → Runtime 映射（Frozen Matrix 全覆蓋） |
| T-2 | `AiRuntimeIntent::all()` 恰為四值 |
| T-3 | `AiRuntimeIntent::isValid`／`assertValid` 接受四值、拒絕非法值 |
| T-4 | `clarification_required=true` 不改變 Runtime Intent（product 仍 `product_search`） |
| T-5 | Translator **無** Customer Utterance 參數（method signature 驗證） |
| T-6 | 非法 `getIntent()` → exception（模擬防禦路徑） |
| T-7 | codebase **無** `mapToLegacyIntentType` symbol（grep／`method_exists` negative） |

#### Updated tests

| Test | Required Validation |
|------|-------------------|
| `test_ai_intent_understanding_runtime_selector.php` | Success path `intent_type` = Translator 輸出；Fail-closed 無 `intent_type`；**無** `mapToLegacyIntentType` |
| `test_aiu_v2_prompt8_regression.php` | `human_service_request` via `AiRuntimeIntent` |
| `test_saas_router_phase9c1_pilot.php` | 常數引用 canonical（**不修** case3 行為 FAIL） |
| `test_saas_router_line_oa_0703_fix.php` | 常數引用 canonical |
| `test_conversation_runtime_facade.php` | `AiRuntimeIntent::PRODUCT_SEARCH` in facts |
| `test_conversation_policy_runtime.php` | canonical literals |
| `test_conversation_runtime_shadow_probe.php` | canonical literals |
| `test_tenant_private_knowledge_runtime.php` | 移除 Selector vocabulary smoke 或改 canonical |

#### Required Regression commands（Future Coding 必須執行並記錄）

```text
C:\Web\xampp\php\php.exe tests\intent\test_ai_runtime_intent_translator.php
C:\Web\xampp\php\php.exe tests\intent\test_ai_intent_understanding_runtime_selector.php
C:\Web\xampp\php\php.exe tests\intent\test_aiu_v2_b0_contract_alignment.php
C:\Web\xampp\php\php.exe tests\intent\test_aiu_v2_legacy_freeze.php
C:\Web\xampp\php\php.exe tests\intent\test_aiu_v2_prompt8_regression.php
C:\Web\xampp\php\php.exe tests\intent\test_aiu_v2_date_clarification.php
C:\Web\xampp\php\php.exe tests\intent\test_aiu_v2_last_mile.php
C:\Web\xampp\php\php.exe tests\product_sources\test_saas_router_phase9c1_pilot.php
C:\Web\xampp\php\php.exe tests\product_sources\test_saas_router_line_oa_0703_fix.php
C:\Web\xampp\php\php.exe tests\conversation\test_conversation_runtime_facade.php
C:\Web\xampp\php\php.exe tests\conversation\test_conversation_policy_runtime.php
C:\Web\xampp\php\php.exe tests\conversation\test_conversation_runtime_shadow_probe.php
C:\Web\xampp\php\php.exe tests\knowledge\test_tenant_private_knowledge_runtime.php
```

**Six Focused Regressions（必含）：** `test_aiu_v2_b0_contract_alignment.php`、`test_aiu_v2_legacy_freeze.php`、`test_aiu_v2_prompt8_regression.php`、`test_aiu_v2_date_clarification.php`、`test_aiu_v2_last_mile.php`、`test_ai_intent_understanding_runtime_selector.php`。

#### Separately reported existing failures（本 changeset **不得**修改以通過）

| Test | Future Coding 處理 |
|------|-------------------|
| `tests/product_sources/test_saas_router_phase9c1_pilot.php` | 執行並**單獨報告** FAIL；不修行為 |
| `tests/test_line_search_footer_ux.php` | 執行並**單獨報告** FAIL；不修 Persona |

### 12.8 Completion Gates（Future Coding）

#### PASS — Contract Coding Complete

僅當**全部**成立：

| Gate | Criterion |
|------|-----------|
| G-1 | `AiRuntimeIntent.php` 與 `AiRuntimeIntentTranslator.php` 存在且符合 §12.1～§12.2 |
| G-2 | `mapToLegacyIntentType` 與 Selector `INTENT_TYPE_*` **不存在**於 codebase |
| G-3 | `RecommendationEligibilityEvaluator::INTENT_*` **不存在** |
| G-4 | 所有 whitelist production consumers 使用 `AiRuntimeIntent::*` |
| G-5 | Frozen Matrix 四映射通過 |
| G-6 | Invalid path → Fail-closed **無** `intent_type` |
| G-7 | 無 Compatibility Design（無 dual Contract／alias／fallback） |
| G-8 | §12.7 Required Regression **全執行**；Contract 相關測試 PASS |
| G-9 | 授權外兩項 FAIL **單獨報告**且不掩蓋 |

#### FAIL — Contract Coding Incomplete

任一 Gate 未滿足。

#### BLOCKED — Implementation Boundary Unresolved

遇下列須停止並回報：

- 需新增 whitelist 外 production 檔才能編譯
- 需保留 Legacy 值或 dual mapping
- 需變更 §11 Frozen Matrix 或 Intent category 集合
- 需修改 `AiIntentCategory`／Normalize／Prompt Scheme

### 12.9 L3 Responsibilities and Out of Scope（Coding 邊界）

**本 L3 Coding Specification 授權：**

- 實作 `AiRuntimeIntent` + `AiRuntimeIntentTranslator`
- Selector 修正與 whitelist consumer 遷移
- 刪除 obsolete mapping／duplicate constants
- §12.7 所列 Contract-focused tests 新增／更新

**本 L3 Coding Specification 不授權：**

- Customer Utterance parsing（Gemini 以外）
- Legacy／Compatibility mapping
- Runtime re-understanding
- Composer／Persona／greeting／response text
- `RuntimeType`／`BatsSearchIntent`／`AiuProductIntentTranslator` 變更
- AIU Prompt Scheme／Date Pipeline
- `test_saas_router_phase9c1_pilot.php`／`test_line_search_footer_ux.php` 行為修復
- SSOT 或其他文件修改

---

## Revision History

| 版本 | 日期 | 說明 |
|------|------|------|
| v1.0 | 2026-07-10 | Phase B Runtime Integration Implementation Specification（No Coding） |
| v1.1 | 2026-07-10 | 新增 §10 Runtime Integration Gap Matrix（不修改 §1～§9） |
| v1.2 | 2026-07-11 | B0-1 Failure Policy Refinement：Runtime Mode Boundary、Unified AIU Failure Policy |
| v1.3 | 2026-07-11 | B0-1 Safe Failure Response Mapping（已於 v1.4 收斂移除） |
| v1.4 | 2026-07-11 | **B0 Failure Boundary SSOT Cleanup：** 移除 Human／Channel Response Mapping；保留 Fail-closed Boundary；Response Mapping 列為 §4.5 Out of Scope |
| v1.5 | 2026-07-11 | **B0 Legacy Authority Architecture Cleanup：** 移除 Flag OFF／Shadow／Authoritative 三模式 Legacy 正式授權；Production 僅 Gemini／AIU v2；Legacy 列為 §4.0.1 Compatibility Debt；新增 §4.7.2 B0 Minimal Coding Scope |
| v1.6 Draft | 2026-07-11 | **B0-2C Fail-Closed Boundary Contract：** §4.2.1 Selector 2-field Formal Failure Result；§4.4.2～§4.4.5 Router Stop-Dispatch／State Distinction／Envelope／Invariants FB-1～FB-11；§4.6 V-B0-F8～F16 |
| v1.7 | 2026-07-13 | **Current Runtime Intent Contract Architecture Freeze：** 新增 §11 Two-Layer Contract（`AiIntentCategory`／`AiRuntimeIntent`／`AiRuntimeIntentTranslator`）；Frozen Intent Matrix；Removal Requirements；更新 §3 Target Flow |
| v1.8 | 2026-07-13 | **L3 Coding Specification：** 新增 §12（`AiRuntimeIntent`／`AiRuntimeIntentTranslator`／Selector correction／Consumer migration／Regression／Completion Gates） |

---

**本階段：§12 L3 Coding Specification only。未修改 §11 Architecture Freeze。未 Coding。未 Commit。未 Push。**
