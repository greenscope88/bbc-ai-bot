# BATS AI Intent Understanding v2

**專案：** BBC AI SaaS / BATS / Phase 2-D
**定位：** L3 架構 SSOT — **AI Intent Understanding Runtime（v2）** 唯一正式依據（Runtime 定位 / 職責 / Boundary / `AiIntentUnderstandingResult` Contract / Integration）
**版本：** v1.2 Freeze（SSOT Refinement — Understand）  
**狀態：** ✅ Architecture Freeze（Phase 2-D-0 + v1.2 Understand Refinement）— 後續 Coding 之唯一架構依據
**主機參考：** 103.1.222.14（主機 A · `C:\bbc-ai-bot`）
**Branch：** feature/api-gateway-mvp

> 本文件為 AI Intent Understanding v2 之 **L3 實作 SSOT**。它在 `BATS_AI_CONVERSATION_ARCHITECTURE.md`（L2）所定義之統一 Pipeline 下，正式定位 **AI Intent Understanding Runtime** 的職責、邊界、流程與輸出契約（`AiIntentUnderstandingResult`）。
>
> 本文件 **不新增任何新架構**，僅整合 Phase 2-D Proposal、Architecture Refinement、Architecture Review 之已確認結論，並予以 Freeze。本文件 **不定義**：意圖類別本體（見 L2 §3 / CA-009）、搜尋語意欄位（見 `BATS_AI_SEMANTIC_SEARCH.md`）、回覆話術（見 `BATS_AI_PERSONA.md` / `BATS_AI_TRAVEL_CONSULTANT_POLICY.md`）、Grounded 回覆契約（見 `PHASE_9C2C_GROUNDED_RESPONSE_COMPOSER.md`）、程式實作。

---

## 上層 / 關聯文件

| 層級 | 文件 | 關係 |
|------|------|------|
| L0 | `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md` | 協作與文件治理 |
| L0 | `BATS_AI_PERSONA.md` §0 | BBC Core Principles（**Core Principle #1** 為本文件最高設計信條來源） |
| L2 | `BATS_AI_CONVERSATION_ARCHITECTURE.md` | 統一 Conversation Architecture / Pipeline（CA-001）/ Intent 類別（CA-009）/ State 唯一管理者（CA-010）/ Grounding（CA-008） |
| L3 | **本文件** | **AI Intent Understanding Runtime（v2）職責 / Boundary / `AiIntentUnderstandingResult` Contract** 唯一 SSOT |
| L3 | `BATS_AI_SEMANTIC_SEARCH.md` | Product Search 子意圖與語意欄位（`BatsSearchIntent`） |
| L3 | `BATS_AI_CONVERSATION_MEMORY.md` | Customer Memory Card（context_snapshot / resume_context 之讀取來源） |
| L3 | `BATS_AI_RUNTIME_DESIGN.md` | Runtime 接入順序 / Legacy Governance / Engineering Governance |
| L3 | `PHASE_9C2C_GROUNDED_RESPONSE_COMPOSER.md` | Grounding 之後的 presentation 契約 |

**衝突處理：** 統一 Pipeline / Owner / Status / State Runtime / Grounding 一律以 L2（`BATS_AI_CONVERSATION_ARCHITECTURE.md`）為準；意圖類別以 L2 §3 / CA-009 為準；Core Principles 以 `BATS_AI_PERSONA.md` §0 為準。**AI Intent Understanding Runtime 之定位、職責、Boundary、`AiIntentUnderstandingResult` 契約以本文件為準。**

---

## Adopted Decisions（Phase 2-D Freeze）

| ID | 決策 |
|----|------|
| **IU-001** | **AI Intent Understanding Runtime**（短名 AIU Runtime）為統一 Pipeline 之 **L1 Decision Layer**：唯一的「理解＋規劃 dispatch」入口，位於 Customer Message 之後、Runtime Dispatch 之前 |
| **IU-002** | AIU Runtime 為 **唯讀聚合器（Read-only Aggregator）**：讀取 Conversation Memory / Conversation State 之 snapshot，**絕不**變更 Owner / Status / Memory（Owner First；State 唯一管理者見 L2 CA-010） |
| **IU-003** | AIU Runtime 輸出單一結構化結果 **`AiIntentUnderstandingResult`**（9 欄位，§5）；`dispatch_plan` 為唯一權威路由依據 |
| **IU-004** | **`execution_hint`** 為 **非綁定（advisory）** 之扁平字串、封閉 enum、可空；僅承載 AIU 階段合法可推導之子模式，**不得**編碼 Execution Runtime 內部解析鏈（CEP） |
| **IU-005** | 本 Runtime 對映 **Core Principle #1（AI Intent Understanding Before Runtime）**；設計信條為「**AI Understand First, Runtime Execute Second**」。**不新增**任何新的 Core Principle |
| **IU-006** | 全面 **Additive**：不修改任何既有 Runtime 行為、不修改其他 SSOT 之架構本體 |

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Document Purpose |
| §2 | Architecture Overview |
| §3 | AI Intent Understanding Runtime（定位 / 職責 / Boundary） |
| §3.4 | Semantic Understanding Core Policy |
| §4 | AI Intent Understanding Runtime Flow |
| §5 | `AiIntentUnderstandingResult` 正式 Contract |
| §6 | Runtime Components |
| §7 | Runtime Boundary（屬於 / 不屬於） |
| §8 | Integration |
| §9 | Success Scope |
| §10 | Out of Scope |
| §11 | BBC AI Core Principle |
| §12 | Architecture Freeze Result（F-1～F-5） |

---

## 1. Document Purpose

本文件完成 **AI Intent Understanding v2** 的 L3 SSOT 凍結，作為 Phase 2-D 後續所有 Coding（自 Phase 2-D-1 Contract Foundation 起）之 **唯一架構依據**。

| 目的 | 說明 |
|------|------|
| **收斂** | 整合 Phase 2-D Proposal / Architecture Refinement / Review 之已確認結論為單一 SSOT |
| **凍結** | Freeze Layer 命名、Layer 位置、`AiIntentUnderstandingResult` 契約、`execution_hint`、Core Principle 對映（F-1～F-5，§12） |
| **約束** | 後續實作須完全依本文件；不一致時依 SSOT Authority（`BATS_AI_PERSONA.md` §0.7）修正程式，不得反向改本 SSOT |
| **非目的** | 不新增新架構、不重複定義 L2 Pipeline / 意圖類別 / 語意欄位 / 話術 / Grounded 契約 / 程式實作 |

---

## 2. Architecture Overview

AI Intent Understanding Runtime 在 L2 統一 Pipeline（CA-001）中的位置 **不變**，本文件僅將其第一級「AI Intent Understanding」正式具名化、結構化：

```
Customer Message
   ↓
AI Intent Understanding Runtime        ← 本文件（L1 Decision Layer）
   │  讀取 snapshot：Conversation Memory（context）、Conversation State（owner）
   │  產出：AiIntentUnderstandingResult（intent / entity / …/ dispatch_plan / execution_hint）
   ↓
Runtime Dispatch                        只路由（依 dispatch_plan），不判斷意圖、不管狀態
   ↓
Execution Runtime                       Product / Knowledge / Clarification / Human / Future
   ↓
Grounding                               彙整 grounded facts → GroundedInput
   ↓
Grounded Response Composer              只組句；不得直接查 Runtime（CA-008）
   ↓
LINE / Web / Future Channels
```

**設計信條（IU-005）：** `AI Understand First, Runtime Execute Second`（＝操作化 Core Principle #1）。

---

## 3. AI Intent Understanding Runtime

### 3.1 正式定位（F-1 / F-2）

| 項目 | 規格 |
|------|------|
| **正式名稱** | **AI Intent Understanding Runtime**（短名 **AIU Runtime**） |
| **Pipeline 位置** | **L1 Decision Layer** — Customer Message 之後、Runtime Dispatch 之前的唯一理解入口 |
| **型態** | **Read-only Aggregator + Decision Layer**（唯讀聚合 + 決策；不持有、不變更狀態） |
| **命名理由** | 保留 L2／Core Principle #1 之 frozen 詞「AI Intent Understanding」；`Runtime` 後綴對齊 Runtime family（Memory / State / Human Service / Industry Shared） |

### 3.2 正式職責

| 職責 | 說明 |
|------|------|
| **Intent Understanding** | 判定意圖類別（Product Search / Knowledge / Ambiguous，CA-009） |
| **Entity Extraction** | 萃取與意圖相關之實體（語意欄位本體引用 `BATS_AI_SEMANTIC_SEARCH.md`） |
| **Context Snapshot** | **讀取** Conversation Memory（Customer Memory Card）作為理解上下文 |
| **Owner Snapshot** | **讀取** Conversation State 之有效 Owner（AI / HUMAN） |
| **Conversation Stage** | 取得對話階段（與 Memory / Lifecycle 對齊；唯讀） |
| **Resume Context** | AI Resume 情境下，提供延續所需上下文（讀取，不改 Owner；Owner 轉移由 State Runtime 執行，CA-006 / CA-010） |
| **Clarification** | 判定 `clarification.required` 與 `reason`（Ambiguous → 必須 Clarification，CA-009） |
| **Dispatch Planning** | 產出權威 `dispatch_plan` 與非綁定 `execution_hint` |

### 3.3 Runtime Boundary（摘要，詳見 §7）

- **唯讀**：對 Memory / State 僅讀 snapshot；**絕不**寫入或變更（IU-002 / Owner First）。
- **不執行業務**：不搜尋、不查知識庫、不組句、不送任何 Channel 訊息。
- **不路由執行**：只產出 `dispatch_plan`；實際路由由 Runtime Dispatch、實際執行由 Execution Runtime。

### 3.4 Semantic Understanding Core Policy

> **本節定位：** 補強 AI Intent Understanding v2 之 **Understand** 核心能力；**不**修改 F-1～F-5 契約、**不**新增 Runtime 架構。

**Semantic Understanding Core：**

| 項目 | 規格 |
|------|------|
| **唯一核心** | **Gemini**（或未來等效 LLM）為 AI Intent Understanding v2 **唯一** Semantic Understanding Core |
| **目標** | 理解客人**真正意圖**，非字面關鍵字命中 |

**理解依據（五要素）：**

| 要素 | 說明 |
|------|------|
| **Semantic** | 自然語言語意理解 |
| **Context** | Customer Memory Card、tenant / 場景上下文 |
| **Conversation** | 多輪對話階段、prior message、Resume 延續 |
| **Intent** | 意圖類別（CA-009）與 Entity 萃取 |
| **Confidence** | 理解信心；低信心或 Ambiguous → `clarification.required = true` |

**禁止（非 AIU v2 長期架構）：**

- PHP Keyword Matching 作為 Intent 終態判斷
- Rule Matching（固定規則表）作為唯一 Intent 依據
- if…else Intent 硬編碼
- 關鍵字硬綁（Marker / Lexicon 命中即 Intent）

**Legacy Transition（MVP 過渡，非長期架構）：**

現行 Production / Pilot 仍可能存在下列 **Rule / Keyword / Lexicon** 元件（§6）：

| 元件 | 過渡定位 | 長期終態 |
|------|----------|----------|
| `BatsSearchIntentBuilder` / `TravelIntentLexicon` | Product Search 語意過渡 | Gemini Semantic Understanding |
| `KnowledgeIntentDetector`（Rule / Marker） | Knowledge / Human 意圖過渡 | Gemini Semantic Understanding |
| `ClarificationPolicy`（Rule） | Clarification 決策過渡 | AIU Clarification + Confidence |

> **規則：** 上述元件 **僅為 MVP 過渡**，**不是** AI Intent Understanding v2 長期架構。`AiIntentUnderstandingResult` 契約（§5）、F-1～F-5、IU-001～IU-006 **維持 Freeze**。新 Intent 能力 **不得**再以 Keyword Patch 擴充。

---

## 4. AI Intent Understanding Runtime Flow

```
輸入：Customer Message（+ tenant 識別 + trace_id）
   ↓
① Owner Snapshot      ← 讀 Conversation State（resolveEffectiveOwner）
   ↓
② Context Snapshot    ← 讀 Conversation Memory（Customer Memory Card）
   ↓
③ Conversation Stage  ← 讀對話階段（唯讀）
   ↓
④ Intent Understanding → intent（Product Search / Knowledge / Ambiguous）
   ↓
⑤ Entity Extraction   → entity
   ↓
⑥ Clarification       → clarification{ required, reason }
   ↓
⑦ Resume Context      → resume_context（僅 Resume 情境；否則 null）
   ↓
⑧ Dispatch Planning   → dispatch_plan（權威）+ execution_hint（非綁定）
   ↓
輸出：AiIntentUnderstandingResult（§5）
```

> 流程全程唯讀且不送訊息；`dispatch_plan` 交由 Runtime Dispatch 路由。Owner = HUMAN 時，AIU 仍可完成理解並輸出 `execution_hint = human_blocked`，但 **不**改變 Owner（Ownership Rule，L2 §7）。

---

## 5. AiIntentUnderstandingResult 正式 Contract（F-3）

AIU Runtime 之唯一輸出，為單一結構化結果物件，**9 個正式欄位**（凍結）：

```
AiIntentUnderstandingResult {
  intent:             string          // 意圖類別（CA-009）：Product Search | Knowledge | Ambiguous
  entity:             array           // 萃取之實體集合（語意欄位引用 BATS_AI_SEMANTIC_SEARCH.md）
  context_snapshot:   array           // 讀自 Conversation Memory（唯讀快照）
  owner_snapshot:     string          // 讀自 Conversation State（唯讀）：AI | HUMAN
  conversation_stage: string          // 對話階段（唯讀；與 Lifecycle / Memory 對齊）
  resume_context:     array | null    // AI Resume 延續上下文；非 Resume 情境為 null
  clarification:      object          // { required: bool, reason: string }
  dispatch_plan:      string          // 權威路由目標：product | knowledge | clarification | human
  execution_hint:     string | null   // 非綁定提示（封閉 enum，§5.2）；可空
}
```

### 5.1 欄位規格

| 欄位 | 型別 | 必填 | 規格 |
|------|------|------|------|
| `intent` | string | 是 | CA-009 三類；意圖本體定義引用 L2 §3 |
| `entity` | array | 是（可空陣列） | 與意圖相關實體；欄位語意引用 `BATS_AI_SEMANTIC_SEARCH.md` |
| `context_snapshot` | array | 是（可空陣列） | Customer Memory Card 之唯讀投影；AIU 不改 Memory |
| `owner_snapshot` | string | 是 | `AI` \| `HUMAN`；讀自 State Runtime 有效 Owner |
| `conversation_stage` | string | 是 | 對話階段；唯讀 |
| `resume_context` | array \| null | 是 | Resume 情境提供延續資訊；否則 `null` |
| `clarification` | object | 是 | `{ required: bool, reason: string }`；Ambiguous → `required = true` |
| `dispatch_plan` | string | 是 | `product` \| `knowledge` \| `clarification` \| `human`；**唯一權威路由** |
| `execution_hint` | string \| null | 否 | 非綁定；封閉 enum（§5.2）；可為 `null` |

### 5.2 execution_hint 封閉 enum（F-4）

| Intent 類別 | 允許值 | 推導來源（AIU 已知事實） |
|-------------|--------|--------------------------|
| Product | `product_search` / `product_clarification` / `product_no_search` | 由 `clarification.required` 推導 |
| Knowledge | `knowledge_resolve` | 標示交由 Knowledge Runtime 解析（**不**含 tenant→industry→global 順序） |
| Human | `human_blocked` | 由 `owner_snapshot = HUMAN` 推導 |
| （任意） | `null` | 無適用提示 |

**`execution_hint` 守則（IU-004 / CEP）：**

1. **非綁定**：`dispatch_plan` 為唯一權威；`execution_hint` 僅為提示，Execution Runtime 可忽略。
2. **扁平字串**：採封閉 enum 字串，不採巢狀結構，確保契約長期穩定。
3. **僅投影 AIU 已知事實**：clarification 與 owner snapshot 本就於 AIU 階段算出，hint 僅為其投影，**零額外執行決策權**。
4. **不編碼 Execution 解析鏈**：Knowledge 的 `Tenant → Industry → Global` 解析順序屬 Knowledge / Industry Shared Runtime 內部業務，**不得**進入本契約。

---

## 6. Runtime Components

> 以下為 AIU Runtime 之 **邏輯子職責元件**；既有 SSOT 已定義者直接重用，本文件不新增新架構。
>
> **Legacy Transition 註記（§3.4）：** 表中「重用」之 Rule / Lexicon 元件為 **MVP 過渡實作**，非 AIU v2 目標架構終態；目標終態為 **Gemini Semantic Understanding Core**。

| 元件（邏輯） | 角色 | 來源 / 重用 |
|--------------|------|-------------|
| **AiIntentUnderstandingRuntime** | AIU 統一入口（façade）；編排 §4 流程並輸出 `AiIntentUnderstandingResult` | Phase 2-D-1 新增（Contract Foundation） |
| **AiIntentUnderstandingResult** | 輸出 DTO（§5） | Phase 2-D-1 新增 |
| **Intent Classifier** | 判定 intent；Product Search 子意圖 | 重用 `BatsSearchIntentBuilder` / `BATS_AI_SEMANTIC_SEARCH.md` |
| **Entity Extractor** | 萃取 entity | 重用語意層（`BatsSearchIntent` 欄位） |
| **Clarification Decision** | 產出 `clarification{required,reason}` | 重用 `ClarificationPolicy` |
| **Context Snapshot Reader** | 讀 Customer Memory Card → `context_snapshot` | 唯讀呼叫 Conversation Memory Runtime |
| **Owner / Stage / Resume Snapshot Reader** | 讀有效 Owner / 對話階段 / Resume 上下文 | 唯讀呼叫 Conversation State Runtime |
| **Dispatch Planner** | 產出 `dispatch_plan` + `execution_hint` | Phase 2-D-1 新增（純決策） |

---

## 7. Runtime Boundary（屬於 / 不屬於）

### 7.1 屬於 AI Intent Understanding Runtime

- 判定 `intent` 與 `entity`。
- 讀取並投影 `context_snapshot` / `owner_snapshot` / `conversation_stage` / `resume_context`（唯讀）。
- 判定 `clarification`。
- 產出 `dispatch_plan`（權威）與 `execution_hint`（非綁定）。
- 輸出單一 `AiIntentUnderstandingResult`。

### 7.2 不屬於 AI Intent Understanding Runtime

| 不屬於 | 歸屬 |
|--------|------|
| 變更 Conversation Owner / Status / Memory | Conversation State Runtime（CA-010）/ Memory Runtime |
| Human Takeover / Sliding Timeout / AI Resume 之 **狀態轉移** | Conversation State Runtime（CA-005 / CA-006） |
| 實際路由 | Runtime Dispatch |
| 執行搜尋 / 查知識庫 / 業務執行 | Execution Runtime（Product / Knowledge / Human / …） |
| Knowledge `Tenant → Industry → Global` 解析鏈 | Knowledge / Industry Shared Runtime |
| 彙整 grounded facts | Grounding Layer（CA-008） |
| 組句 / 措辭 / 送 Channel 訊息 | Grounded Response Composer / Channel（CA-008） |
| 話術 / 人格 | `BATS_AI_PERSONA.md` / `BATS_AI_TRAVEL_CONSULTANT_POLICY.md` |

---

## 8. Integration

> 所有整合皆為 **Additive**；AIU 對其他 Runtime 一律 **唯讀**，不改變其行為（IU-002 / IU-006）。

| 對象 Runtime | 整合方式 | 邊界 |
|--------------|----------|------|
| **Conversation Memory Runtime** | AIU **讀取** Customer Memory Card → `context_snapshot` / `resume_context` | Memory 仍唯一擁有內容；AIU 不寫入 |
| **Conversation State Runtime** | AIU **讀取** 有效 Owner / Stage → `owner_snapshot` / `conversation_stage` | State 為唯一狀態管理者（CA-010）；AIU 不改 Owner/Status（Owner First） |
| **Human Service Runtime** | `owner_snapshot = HUMAN` 時，`execution_hint = human_blocked`；AIU 完成理解但不送回覆 | Takeover / Resume 狀態轉移仍由 State Runtime 執行；AIU 不介入 |
| **Industry Shared Runtime** | AIU 僅以 `dispatch_plan = knowledge` + `execution_hint = knowledge_resolve` 交付 | `Tenant → Industry → Global` 解析鏈完全保留於 Industry Shared / Knowledge Runtime |
| **Grounded Response Composer** | 無直接整合；AIU 輸出為路由 metadata，不作為 fact | Composer 只消費 Grounding 輸出（CA-008）；`execution_hint` 不得進入 Composer |

---

## 9. Success Scope

| # | 條件 |
|---|------|
| SS-1 | AIU Runtime 對任一 Customer Message 產出完整 `AiIntentUnderstandingResult`（9 欄位） |
| SS-2 | `dispatch_plan` 正確反映 intent（Ambiguous → `clarification`；Owner=HUMAN 之 routing 語意明確） |
| SS-3 | `execution_hint` 僅取自封閉 enum（§5.2），且為非綁定 |
| SS-4 | AIU 全程唯讀：不變更 Owner / Status / Memory（以 State / Memory 前後快照驗證一致） |
| SS-5 | `owner_snapshot` 與 State Runtime 有效 Owner 一致；`context_snapshot` 與 Memory 一致 |
| SS-6 | 完全 Additive：既有 Runtime 與 Customer Route 行為零變更（回歸測試 PASS） |
| SS-7 | Ambiguous 一律 `clarification.required = true`，不產出進入其他 Execution 之 `dispatch_plan`（CA-009） |

---

## 10. Out of Scope

| 排除項 | 歸屬 / 原因 |
|--------|-------------|
| 變更 Owner / Status / Memory | State / Memory Runtime（CA-010） |
| Human Takeover / Resume 狀態轉移實作 | Conversation State Runtime（已存在） |
| 實際搜尋 / 知識解析 / 業務執行 | Execution Runtime |
| Knowledge `Tenant→Industry→Global` 解析鏈 | Industry Shared / Knowledge Runtime |
| Grounding 彙整 / 組句 / Channel 傳送 | Grounding / Composer / Channel |
| 意圖類別本體、語意欄位、話術人格 | L2 §3 / `BATS_AI_SEMANTIC_SEARCH.md` / Persona / Consultant Policy |
| 新增任何 Core Principle | 禁止（IU-005） |
| 程式實作 | Phase 2-D-1+（本文件僅 Freeze 架構） |

---

## 11. BBC AI Core Principle

本 Runtime 以 `BATS_AI_PERSONA.md` §0 **Core Principle #1 — AI Intent Understanding Before Runtime** 為最高設計依據（已 Freeze，不得新增新原則）：

> **AI 必須先理解客戶真正意圖，再決定 Runtime。**

**v2 設計信條（操作化 Core Principle #1）：**

```
AI Understand First, Runtime Execute Second
```

| 規則 | 說明 |
|------|------|
| **對映既有原則** | 本信條 = Core Principle #1 之操作化表述，**非**新原則（IU-005） |
| **不新增 Core Principle** | BBC Core Principles 維持三條（#1/#2/#3）且 Freeze |
| **未來如入 Persona** | 若欲將本信條收入 `BATS_AI_PERSONA.md` 作為 Principle #1 之標語強化，須走正式 SSOT 變更流程；屬 documentation-only，非新增原則 |

---

## 12. Architecture Freeze Result（F-1～F-5）

| Freeze 項 | 內容 | 正式凍結值 | 狀態 |
|-----------|------|------------|------|
| **F-1** | Layer Naming | **AI Intent Understanding Runtime**（短名 AIU Runtime） | ✅ Frozen |
| **F-2** | Layer Position | **L1 Decision Layer**（Customer Message 之後、Runtime Dispatch 之前；Read-only Aggregator） | ✅ Frozen |
| **F-3** | `AiIntentUnderstandingResult` | 9 欄位（intent / entity / context_snapshot / owner_snapshot / conversation_stage / resume_context / clarification / dispatch_plan / execution_hint） | ✅ Frozen |
| **F-4** | `execution_hint` | 非綁定、扁平字串、封閉 enum（§5.2）；可空；不編碼 Execution 解析鏈 | ✅ Frozen |
| **F-5** | Core Principle Mapping | 對映 Core Principle #1；信條「AI Understand First, Runtime Execute Second」；不新增原則 | ✅ Frozen |
| **F-6** | Semantic Understanding Core Policy | Gemini 唯一 Semantic Core；§3.4 五要素 / 禁止項 / Legacy Transition | ✅ Frozen（v1.2 Refinement） |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | **v1.2 Freeze（Understand Refinement）** — AI Intent Understanding v2 L3 SSOT |
| **Adopted Decisions** | IU-001～IU-006 |
| **Architecture Freeze** | ✅ 已 Freeze（Phase 2-D-0）；F-1～F-5 凍結 |
| **Additive 確認** | 是 — 不修改任何既有 Runtime 行為、不修改其他 SSOT 架構本體 |
| **SAFE TO START Phase 2-D-1（Contract Foundation Coding）** | 是 |

---

## Revision History

| 版本 | 日期 | 狀態 | 說明 |
|------|------|------|------|
| **1.2** | 2026-07-04 | Freeze（Refinement） | §3.4 補強 Understand：Gemini 唯一 Semantic Core、五要素、禁止 Keyword/Rule/if-else、Legacy Transition 明確化。聚焦 Understand；不修改其它 SSOT。 |
| **1.1** | 2026-07-04 | Freeze（Refinement） | 新增 §3.4 Semantic Understanding Core Policy；§6 Legacy 註記；F-6。 |
| **1.0** | 2026-06-30 | Freeze | 初版 Freeze（F-1～F-5；IU-001～IU-006）。 |

---

*本文件為 AI Intent Understanding v2 唯一 L3 SSOT。AIU Runtime 之定位 / 職責 / Boundary / `AiIntentUnderstandingResult` 契約以本文件為準；統一 Pipeline / Owner / Status / State / Grounding 以 `BATS_AI_CONVERSATION_ARCHITECTURE.md` 為準；Core Principles 以 `BATS_AI_PERSONA.md` §0 為準。不涉及程式實作。*
