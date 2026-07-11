# BATS AI Runtime Integration — Implementation Specification（L3）

**專案：** BBC AI SaaS / BATS / AIU v2  
**定位：** L3 Implementation Specification — Phase B Runtime Integration 唯一實作規格  
**版本：** v1.5  
**狀態：** Implementation Spec — B0 Legacy Authority Architecture Cleanup（Docs Only）  
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
AIU Normalize（Translation Only → Runtime Contract；無 Execution Decision）
        ↓
BBC AI Runtime（saas_router Phase9C1 編排）
   Routing / Dispatch / Coordination
   （依 intent / entities / clarification / Runtime State 投影）
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
9. **終點：** 無合法 Runtime Contract → Fail-closed → 傳遞至 Integration Boundary（§4.5 Out of Scope）。

**Clarification 邊界：** 僅合法 Runtime Contract + Gemini Detection 可執行 Clarification。AIU Failure **≠** Clarification。

### 4.3 Failure Observability（原則）

AIU Failure 時：

| 原則 | 規格 |
|------|------|
| **可觀測** | Failure 寫入 operational log（`trace_id`、`tenant_sno`、`conversation_id`） |
| **可機讀** | Log 含 failure 類別／stage；**不得**暴露給客戶 |
| **禁止 Legacy 掩蓋** | **不得**以 Legacy Understanding 或 `legacy_fallback` 掩蓋 Failure |

**B0 不界定：** 新 observability schema、customer-facing 欄位（§4.5 Out of Scope）。

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
無合法 AIU Runtime Contract
        ↓
Fail-closed Boundary
   ├─ STOP：Legacy Understanding／legacySelection()／Legacy parser
   ├─ STOP：AIU-dependent Runtime
   └─ PASS：Failure → Integration Boundary（Response Mapping Out of Scope）
```

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
| 新 DTO、reply type、route、failure schema | **禁止**在 B0 新增 |

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
| MC-1 | **移除** `legacySelection()` 作正式 Dispatch | `AiIntentUnderstandingRuntimeSelector.php` |
| MC-2 | AIU Failure **傳播 Fail-closed**；禁止 Legacy fallback | `AiIntentUnderstandingRuntimeSelector.php` |
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
| AIU Failure | Disabled／transport／contract／normalize／runtime | §4.2 九項 + §4.4 Fail-closed；V-B0-F1～V-B0-F7 |
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
| **B0-7** | Production：AIU Failure→Fail-closed Boundary（§4.2／§4.4） | `legacySelection()` 接管；downstream Legacy parser | Fail-closed；停止 AIU-dependent Runtime；**不** Legacy Understanding | **B0** | V-B0-F1～V-B0-F7 |
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

## Revision History

| 版本 | 日期 | 說明 |
|------|------|------|
| v1.0 | 2026-07-10 | Phase B Runtime Integration Implementation Specification（No Coding） |
| v1.1 | 2026-07-10 | 新增 §10 Runtime Integration Gap Matrix（不修改 §1～§9） |
| v1.2 | 2026-07-11 | B0-1 Failure Policy Refinement：Runtime Mode Boundary、Unified AIU Failure Policy |
| v1.3 | 2026-07-11 | B0-1 Safe Failure Response Mapping（已於 v1.4 收斂移除） |
| v1.4 | 2026-07-11 | **B0 Failure Boundary SSOT Cleanup：** 移除 Human／Channel Response Mapping；保留 Fail-closed Boundary；Response Mapping 列為 §4.5 Out of Scope |
| v1.5 | 2026-07-11 | **B0 Legacy Authority Architecture Cleanup：** 移除 Flag OFF／Shadow／Authoritative 三模式 Legacy 正式授權；Production 僅 Gemini／AIU v2；Legacy 列為 §4.0.1 Compatibility Debt；新增 §4.7.2 B0 Minimal Coding Scope |

---

**本階段：B0 Legacy Authority Architecture Cleanup only。未修改其他 Frozen 文件。未 Coding。未 Commit。未 Push。**
