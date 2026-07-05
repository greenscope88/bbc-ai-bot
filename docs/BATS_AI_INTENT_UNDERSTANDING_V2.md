# BATS AI Intent Understanding v2

**專案：** BBC AI SaaS / BATS / Phase 2-D
**定位：** L3 架構 SSOT — **AI Intent Understanding Runtime（v2）** 唯一正式依據（Runtime 定位 / 職責 / Boundary / `AiIntentUnderstandingResult` Contract / Integration）
**版本：** v1.5（ASSC Gold Architecture Freeze）
**狀態：** ✅ Architecture Freeze（Phase 2-D-0 + v1.2～v1.3 Understand Refinement + v1.4 ASSC Gold Integration + **v1.5 ASSC Gold Architecture Freeze**）— 後續 Coding 之唯一架構依據
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
| L3 | `ASSC_GOLD_BENCHMARK_v1.md` | **ASSC Gold 正式 Benchmark Corpus**（案例原文；本文件 §13 引用，不內嵌複製） |

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
| §3.4 | Semantic Understanding Core Policy（含 Semantic Entity Extraction Clarification） |
| §4 | AI Intent Understanding Runtime Flow |
| §5 | `AiIntentUnderstandingResult` 正式 Contract |
| §6 | Runtime Components |
| §7 | Runtime Boundary（屬於 / 不屬於） |
| §8 | Integration |
| §9 | Success Scope |
| §10 | Out of Scope |
| §11 | BBC AI Core Principle |
| §12 | Architecture Freeze Result（F-1～F-6） |
| §13 | ASSC Gold（AI Semantic Scenario Collection） |
| §13.14 | ASSC Gold Architecture Freeze Result（AG-1～AG-12） |

---

## 1. Document Purpose

本文件完成 **AI Intent Understanding v2** 的 L3 SSOT 凍結，作為 Phase 2-D 後續所有 Coding（自 Phase 2-D-1 Contract Foundation 起）之 **唯一架構依據**。

| 目的 | 說明 |
|------|------|
| **收斂** | 整合 Phase 2-D Proposal / Architecture Refinement / Review 之已確認結論為單一 SSOT |
| **凍結** | Freeze Layer 命名、Layer 位置、`AiIntentUnderstandingResult` 契約、`execution_hint`、Core Principle 對映（F-1～F-5，§12） |
| **約束** | 後續實作須完全依本文件；不一致時依 SSOT Authority（`BATS_AI_PERSONA.md` §0.7）修正程式，不得反向改本 SSOT |
| **非目的** | 不新增新架構、不重複定義 L2 Pipeline / 意圖類別 / 語意欄位 / 話術 / Grounded 契約 / 程式實作 |
| **ASSC Gold** | 本文件 §13 定義 **ASSC Gold** 架構、Schema、治理與 Golden Rules；正式 Benchmark Cases 見 `ASSC_GOLD_BENCHMARK_v1.md` |

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
| **Entity Extraction** | **語意實體萃取（Semantic Entity Extraction）**；由 Gemini Semantic Understanding 產出；語意欄位本體引用 `BATS_AI_SEMANTIC_SEARCH.md`（詳 §3.4） |
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

> **本節定位：** 補強 AI Intent Understanding v2 之 **Understand** 核心能力；**不**修改 F-1～F-5 契約、**不**新增 Runtime 架構、**不**新增 Runtime Flow。

**Semantic Understanding Core：**

| 項目 | 規格 |
|------|------|
| **唯一核心** | **Gemini**（或未來等效 LLM）為 AI Intent Understanding v2 **唯一** Semantic Understanding Core |
| **目標** | 理解客人**真正意圖**，非字面關鍵字命中 |

**語意解析面向（Clarification）：**

AI Intent Understanding v2 解析使用者輸入中之語意資訊，作為理解與 `dispatch_plan` 規劃之依據。下列面向 **均屬 Understand 範疇**，**不**新增契約欄位、**不**改變 §5 之 9 欄位 Freeze：

| 語意面向 | 說明 | 契約投影（§5） |
|----------|------|----------------|
| **Intent** | 意圖類別（Product Search / Knowledge / Ambiguous，CA-009） | `intent` |
| **Semantic Meaning** | 自然語言之整體語意理解（含隱含需求、口語改述、多輪指代） | 內化於 Gemini 理解；投影至 `intent` / `entity` / `clarification` |
| **Entities** | Gemini 語意理解後萃取之結構化語意資訊（§3.4 Semantic Entity Extraction） | `entity` |
| **Context** | Customer Memory Card、tenant / 場景上下文 | `context_snapshot` |
| **Conversation State** | 多輪對話階段、有效 Owner、Resume 延續 | `conversation_stage` / `owner_snapshot` / `resume_context` |
| **Confidence** | 理解信心；低信心或 Ambiguous → 要求 Clarification | `clarification` |

**理解依據（五要素）：**

> 下列五要素為 Semantic Understanding Core 之 **設計依據**；上表六面向為其 **語意解析產出之 Clarification**，兩者並列、互補，**不**構成架構變更。

| 要素 | 說明 |
|------|------|
| **Semantic** | 自然語言語意理解（含 Semantic Meaning） |
| **Context** | Customer Memory Card、tenant / 場景上下文 |
| **Conversation** | 多輪對話階段、prior message、Resume 延續（含 Conversation State） |
| **Intent** | 意圖類別（CA-009）與 Entity 萃取 |
| **Confidence** | 理解信心；低信心或 Ambiguous → `clarification.required = true` |

**Semantic Entity Extraction（語意實體萃取）：**

| 項目 | 規格 |
|------|------|
| **歸屬** | **屬於 AIU v2** — 為理解使用者真正意圖之 **必要組成**，非 Execution Runtime 業務邏輯 |
| **產出方式** | 由 **Gemini Semantic Understanding** 完成語意理解後，萃取結構化 **Entities** 至 `entity` |
| **性質** | 語意資訊之結構化投影；欄位語意本體引用 `BATS_AI_SEMANTIC_SEARCH.md`；**非** Keyword 命中清單 |

**Entities 範例（旅遊語境，非 exhaustive）：**

| 類別 | 說明 |
|------|------|
| **Destination** | 目的地（含口語別名、區域、城市） |
| **Date** | 出發 / 回程 / 月份 / 季節等時間語意 |
| **Duration** | 天數、行程長度 |
| **Budget** | 預算區間、價格敏感度 |
| **Departure City** | 出發地 |
| **Traveler Count** | 人數、同行者組成 |
| **Product Preference** | 產品類型、主題、艙等、飯店等偏好 |
| **Human Service Request** | 轉人工、客服、真人協助等語意請求 |
| **（其他）** | 任何對理解真正意圖具有語意重要性之資訊 |

> **Clarification 守則：** 上表為 **語意類別示例**，**不是** Keyword Whitelist / Blacklist、**不是** Destination 硬編碼表、**不是** 新增 Routing Rule。具體欄位定義仍以 `BATS_AI_SEMANTIC_SEARCH.md` 為準。

**明確區分 — Semantic Entity Extraction vs Programmatic Matching：**

| 類型 | 歸屬 | 規格 |
|------|------|------|
| **Semantic Entity Extraction** | ✅ **AIU v2 長期架構** | 由 Gemini 語意理解後產生；是理解使用者真正意圖之一部分；輸出至 `entity` |
| **Programmatic Keyword Matching / Rule Matching** | ❌ **非 AIU v2 長期架構** | 不可作為 Intent 判斷依據；不可用 PHP keyword、regex-first、if…else、hardcoded routing **取代** Gemini 理解 |

**禁止（非 AIU v2 長期架構）：**

- PHP Keyword Matching 作為 Intent 終態判斷
- Rule Matching（固定規則表）作為唯一 Intent 依據
- if…else Intent 硬編碼
- 關鍵字硬綁（Marker / Lexicon 命中即 Intent）
- regex-first 或 hardcoded routing **取代** Gemini Semantic Understanding
- 以 Keyword / Rule 命中結果 **冒充** Semantic Entity Extraction 產出

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
| `entity` | array | 是（可空陣列） | **Semantic Entity Extraction** 產出之語意實體集合；由 Gemini 理解後萃取；欄位語意引用 `BATS_AI_SEMANTIC_SEARCH.md`（§3.4）；**非** Keyword / Rule 命中產物 |
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
| **F-6** | Semantic Understanding Core Policy | Gemini 唯一 Semantic Core；§3.4 五要素 / Semantic Entity Extraction / 禁止項 / Legacy Transition | ✅ Frozen（v1.2 Refinement + v1.3 Clarification） |
| **F-7** | ASSC Gold Integration | §13 ASSC Gold 架構 / Schema / Taxonomy / Governance / Golden Rules；Benchmark Corpus 引用 `ASSC_GOLD_BENCHMARK_v1.md` | ✅ Frozen（v1.5 ASSC Gold Architecture Freeze） |

---

## 13. ASSC Gold（AI Semantic Scenario Collection）

> **本節定位：** ASSC Gold 為 **AI Intent Understanding v2 的一部分** — 非獨立 SSOT。本節定義 ASSC Gold 之架構、Schema、Scenario Taxonomy、治理與 Golden Rules；**正式 Benchmark Cases 維持於** `ASSC_GOLD_BENCHMARK_v1.md`，**不**將 ASSC-001～100 複製至本文件。
>
> **✅ Architecture Freeze（v1.5）：** 本節 §13.1～§13.14 已正式 Freeze。不得新增架構設計、不得新增功能、不得修改 §1～§12 Runtime Flow / Intent / Contract。後續僅允許：Benchmark Corpus 累積（ASSC-101+）、明確錯誤修正、Roadmap 文件化擴充（不納入 v1.0）。

### 13.1 Document Purpose

| 目的 | 說明 |
|------|------|
| **定位** | ASSC Gold 為 AIU v2 之 **Semantic Benchmark 子系統** — 蒐集、保存、治理真實客戶語意情境 |
| **關係** | AIU v2 為 ASSC Gold 之 **SSOT**（架構與治理）；Benchmark Corpus 為 **獨立引用文件** |
| **用途** | 離線驗證 Gemini Semantic Understanding、BBC AI Runtime 理解與流程治理、Regression / Capability Coverage |
| **非目的** | 不定義 Runtime 實作、不取代 Gemini 理解、不規範 AI 回覆話術、不複製 Benchmark 全文 |

### 13.2 Why ASSC Exists

大型語言模型具備強大的自然語言理解能力，但仍需要一套**穩定、可持續擴充、可回歸驗證**的真實客戶語意基準集，以客觀評估 BBC AI Runtime 在各種對話情境中的語意理解、流程治理與服務品質，並支援長期持續演進。

> **✅ Frozen（v1.5）：** ASSC Gold **不參與 Production Runtime**；**僅作為 Offline Benchmark**（離線 Semantic Benchmark / Regression / Capability Validation）。

### 13.3 Mission

ASSC（AI Semantic Scenario Collection）旨在蒐集真實客戶語意情境，作為 BBC AI Runtime 的離線 Semantic Benchmark，用於驗證、評估與持續提升 AI 的理解、推理與回覆能力。

> **✅ Frozen（v1.5）**

### 13.4 Core Principles

| 原則 | 說明 |
|------|------|
| **Preserve Customer Utterance** | Customer Utterance 必須完整保留原文；不得改寫、修飾、最佳化或重新組句 |
| **Gemini is the Semantic Core** | 語意理解驗證以 **Gemini（AIU v2）** 為核心；ASSC **不得**以規則取代 Gemini 自然語言理解 |
| **Benchmark First** | 先建立可回歸、可累積之 Benchmark Corpus，再驅動 Runtime 持續改善 |
| **Runtime Independence** | ASSC 為離線 Benchmark；**不**耦合 Production Runtime 路徑；**不**取代 Runtime 執行 |
| **Continuous Evolution** | Scenario 與 Benchmark Cases 可持續新增；ASSC-101 起依序累積 |

> **✅ Frozen（v1.5）** — Preserve Customer Utterance / Gemini is the Semantic Core / Benchmark First / Runtime Independence / Continuous Evolution 五項原則正式固定。

### 13.5 Architecture Responsibilities

| Component | Responsibility |
|-----------|----------------|
| **Gemini（AI Intent Understanding v2）** | **Understand**（理解客戶語意） |
| **BBC AI Runtime** | **Execute / Govern**（流程執行與治理） |
| **ASSC Gold** | **Benchmark**（Benchmark、Regression、Capability Validation） |

```
Customer Utterance
   ↓
Gemini / AIU v2          ← Understand（語意理解；生產路徑）
   ↓
BBC AI Runtime          ← Execute / Govern（執行與治理）
   ↓
Channels

（離線驗證路徑）
Customer Utterance → ASSC Gold Benchmark → 驗證 AIU 理解與 Runtime 行為
```

> **Invariant：** ASSC Gold **不**參與 Production 路由；**不**取代 AIU Understand Layer。

> **✅ Frozen（v1.5）** — 三方責任正式固定：
>
> | Component | Responsibility |
> |-----------|----------------|
> | **Gemini（AIU v2）** | **Understand**（理解） |
> | **BBC AI Runtime** | **Execute / Govern**（執行與治理） |
> | **ASSC Gold** | **Benchmark**（Benchmark / Regression / Capability Validation） |

### 13.6 Responsibilities

ASSC 負責：

- 蒐集真實客戶語意情境
- 保留 Customer Utterance 原文
- 建立 Semantic Benchmark
- 驗證 Gemini Semantic Understanding
- 驗證 BBC AI Runtime
- Regression Testing
- Capability Coverage
- AI Benchmark
- 支援 Runtime 持續改善
- 支援跨產業案例累積

> **✅ Frozen（v1.5）**

### 13.7 Out of Scope

ASSC **不負責**：

| 排除項 | 歸屬 |
|--------|------|
| Runtime 實作與路由 | BBC AI Runtime |
| AI Response Generation | Grounded Response Composer / NLG |
| Prompt Engineering | 開發流程；非 ASSC 本體 |
| Knowledge Base | Knowledge Runtime |
| Product Search | Product Execution Runtime |
| Grounded Response Composer | L3 Composer SSOT |
| AI Persona | `BATS_AI_PERSONA.md` |
| Customer Memory | Conversation Memory Runtime |
| Conversation State | Conversation State Runtime |
| Human Service Runtime | Human Service Runtime |
| Intent Engine（生產權威） | AIU v2 Runtime（§3～§5） |
| Semantic Parser（生產實作） | AIU v2 Gemini Adapter |
| Keyword Matching | Legacy Transition；非 ASSC 設計目標 |

> **✅ Frozen（v1.5）**

### 13.8 ASSC Gold Schema

ASSC Gold 每一筆 Benchmark Case 之正式 Schema（**獨立於** `AiIntentUnderstandingResult` 9 欄位契約；用於 Benchmark 標註與驗證規劃）：

| # | 欄位 | 說明 |
|---|------|------|
| 1 | **ASSC ID** | 唯一識別（如 `ASSC-001`） |
| 2 | **Customer Utterance** | 客戶原文（不可改寫） |
| 3 | **Domain** | 產業領域（v1.0：`Travel`） |
| 4 | **Primary Scenario** | 主要情境分類（Scenario Taxonomy） |
| 5 | **Secondary Scenario** | 次要情境（可空） |
| 6 | **Expected Intent** | 預期意圖（對齊 CA-009 / `dispatch_plan` 驗證用） |
| 7 | **Required Capabilities** | 驗證所需能力（如 Entity Extraction、Clarification、Human Resume） |
| 8 | **Conversation Type** | 對話型態（Single-turn / Multi-turn / Interrupted 等） |
| 9 | **Benchmark Purpose** | 本案例驗證目的 |
| 10 | **Expected Runtime Behavior** | 預期 Runtime 行為（理解與路由層；非回覆話術） |
| 11 | **Notes** | 備註 |
| 12 | **Runtime Phase Coverage** | 覆蓋之 Runtime Phase |
| 13 | **Difficulty** | 難度（Benchmark 標註） |
| 14 | **Tags** | 標籤（Benchmark 標註） |

> **Corpus 精簡表：** `ASSC_GOLD_BENCHMARK_v1.md` 目前公開欄位為 **ASSC ID / Customer Utterance / Primary Scenario**；其餘 Schema 欄位於完整 Benchmark 標註與後續驗證工具中擴充，**不**改變 Corpus 原文。

> **✅ Frozen（v1.5）** — 14 欄位 Schema 正式固定（§13.8 表格 #1～#14）。

### 13.9 Scenario Taxonomy

ASSC Gold v1.0 官方 Taxonomy：**40 種 Scenario Types**（見 `ASSC_GOLD_BENCHMARK_v1.md` § Benchmark Statistics）。

> **✅ Frozen（v1.5）：** **40 Scenario** 為 v1.0 **官方 Taxonomy**，正式 Freeze。
>
> **Roadmap（不納入 v1.0）：** ASSC Scenario 補充（**41～50**）保留於未來擴充 Roadmap；新增時不影響 v1.0 Freeze 範圍。

**Primary Scenario 分類可持續新增；40 不是 Scenario 擴充之上限。**

ASSC-001～100 之 Primary Scenario 欄位目前已出現 **36 種**（如下表）；其餘 Scenario Types 屬 v1.0 官方 Taxonomy 或 Roadmap 擴充空間，供 ASSC-101+ 與跨 Domain 案例使用。

| # | Primary Scenario |
|---|------------------|
| 1 | Adversarial Prompt |
| 2 | Booking Intent |
| 3 | Capability Inquiry |
| 4 | Clarification Required |
| 5 | Company Service |
| 6 | Complaint |
| 7 | Complex Multi-turn |
| 8 | Confirmation |
| 9 | Consultant |
| 10 | Contact Request |
| 11 | Context Switching |
| 12 | Conversation Memory |
| 13 | Correction |
| 14 | Decision Making |
| 15 | Emotional Support |
| 16 | Farewell |
| 17 | Greeting |
| 18 | Grounding Challenge |
| 19 | Human Resume |
| 20 | Human Service |
| 21 | Identity Question |
| 22 | Incomplete Input |
| 23 | Interruptions |
| 24 | Knowledge QA |
| 25 | Long Context |
| 26 | Mixed Languages |
| 27 | Out of Scope |
| 28 | Preference Update |
| 29 | Pricing |
| 30 | Product Search |
| 31 | Recommendation |
| 32 | Safety & Compliance |
| 33 | Small Talk |
| 34 | Thanks |
| 35 | Typos & ASR Errors |
| 36 | Urgency |

> 完整 Scenario 清單與案例對照以 `ASSC_GOLD_BENCHMARK_v1.md` 為準；新增 Scenario 不影響 AIU v2 Runtime Flow（§4）與 Intent 類別（CA-009）。

### 13.10 Benchmark Corpus

| 項目 | 規格 |
|------|------|
| **正式文件** | `ASSC_GOLD_BENCHMARK_v1.md` |
| **版本** | ASSC Gold v1.0 |
| **案例範圍** | ASSC-001～100 |
| **案例總數** | 100 Benchmark Cases |
| **狀態** | Complete（100 / 100） |
| **本文件職責** | 維護 ASSC Gold **架構、Schema、Taxonomy、Governance、Golden Rules** |
| **Corpus 職責** | 維護 **Customer Utterance 原文** 與 **Primary Scenario**；獨立文件、獨立版本 |

**引用方式：** 驗證、Regression、Capability 測試 **引用** `ASSC_GOLD_BENCHMARK_v1.md`；**不得**在本文件內嵌複製 ASSC-001～100 全文。

> **✅ Frozen（v1.5）：** `ASSC_GOLD_BENCHMARK_v1.md` 為 **官方 Benchmark Corpus**；**ASSC-001～100** 正式 Freeze。新案例自 **ASSC-101** 起；除 **明確錯誤** 外，不得修改 001～100。

### 13.11 Benchmark Governance

- Benchmark 採 **累積方式** 維護。
- **ASSC-001～100** 為 **ASSC Gold v1.0** 官方 Benchmark。
- 新案例由 **ASSC-101** 起依序新增。
- 除非修正 **明確錯誤**，既有 Benchmark **不應** 任意修改 Customer Utterance 或 Primary Scenario。
- AIU v2 SSOT（本文件）與 Benchmark Corpus **必須保持一致**（架構與治理對齊；案例原文以 Corpus 為準）。

> **✅ Frozen（v1.5）**

### 13.12 Future Expansion

| 維度 | 現況 | 未來 |
|------|------|------|
| **Domain** | `Travel` | Restaurant、Hotel、Retail、Medical、Education、Insurance、Banking 等 |
| **Schema** | §13.8 正式欄位 | **保持相同 Schema**；僅擴充 Domain / Scenario / Cases |
| **Scenario** | 40 種（v1.0 官方 Taxonomy；✅ Frozen） | Roadmap：Scenario 41～50 補充（**不納入 v1.0**）；其後可持續新增 |
| **Cases** | ASSC-001～100（✅ Frozen） | ASSC-101+ 依序累積 |

### 13.13 Golden Rules

1. **ASSC Gold 的存在，是為了驗證 AI 的理解能力，而不是取代 AI 的理解能力。**

2. **ASSC 記錄的是「真實客戶如何表達需求」，而不是「AI 應如何回答問題」。**

3. **ASSC 的目的是驗證 Gemini 與 BBC AI Runtime 是否正確理解真實客戶語意，而不是以 ASSC 規則取代 Gemini 的自然語言理解能力。**

4. **Gemini（AI Intent Understanding v2）負責 Understand（理解客戶語意）。**

5. **BBC AI Runtime 負責 Execute / Govern（流程執行與治理）。**

6. **ASSC Gold 負責 Benchmark（Benchmark、Regression、Capability Validation）。**

> **✅ Frozen（v1.5）** — Golden Rules 1～6 正式固定。

### 13.14 ASSC Gold Architecture Freeze Result（AG-1～AG-12）

| ID | 凍結項目 | 狀態 |
|----|----------|------|
| **AG-1** | ASSC 定位與三方責任（Gemini Understand / Runtime Execute·Govern / ASSC Benchmark） | ✅ Frozen |
| **AG-2** | Why ASSC Exists（Offline Benchmark；不參與 Production Runtime） | ✅ Frozen |
| **AG-3** | Mission | ✅ Frozen |
| **AG-4** | Core Principles（五項） | ✅ Frozen |
| **AG-5** | Architecture Responsibilities | ✅ Frozen |
| **AG-6** | Responsibilities | ✅ Frozen |
| **AG-7** | Out of Scope | ✅ Frozen |
| **AG-8** | ASSC Gold Schema（14 欄位） | ✅ Frozen |
| **AG-9** | ASSC 40 Scenario（v1.0 官方 Taxonomy；Roadmap 41～50 不納入 v1.0） | ✅ Frozen |
| **AG-10** | Benchmark Corpus（`ASSC_GOLD_BENCHMARK_v1.md`；ASSC-001～100） | ✅ Frozen |
| **AG-11** | Benchmark Governance | ✅ Frozen |
| **AG-12** | Golden Rules（1～6） | ✅ Frozen |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | **v1.5（ASSC Gold Architecture Freeze）** — AI Intent Understanding v2 L3 SSOT |
| **Adopted Decisions** | IU-001～IU-006 |
| **Architecture Freeze** | ✅ 已 Freeze（Phase 2-D-0）；F-1～F-7 凍結；§13 AG-1～AG-12 凍結 |
| **ASSC Gold Freeze** | ✅ 已 Freeze（v1.5）— §13.1～§13.14 |
| **Additive 確認** | 是 — 不修改任何既有 Runtime 行為、不修改 §1～§12 AIU Flow / Intent / Contract |
| **SAFE TO START AIU v2 CODING** | 是 |
| **SAFE TO START Phase 2-D-1（Contract Foundation Coding）** | 是 |

---

## Revision History

| 版本 | 日期 | 狀態 | 說明 |
|------|------|------|------|
| **1.5** | 2026-07-05 | Freeze | **ASSC Gold Architecture Freeze**：§13.1～§13.14 正式 Freeze（AG-1～AG-12）。確認三方責任、14 欄位 Schema、40 Scenario v1.0 Taxonomy、Corpus ASSC-001～100、Governance、Golden Rules。Roadmap Scenario 41～50 不納入 v1.0。F-7 更新為 v1.5 Freeze。Documentation only；§1～§12 Runtime Flow / Intent / Contract 不變。 |
| **1.4** | 2026-07-05 | SSOT Integration | 新增 §13 ASSC Gold（AI Semantic Scenario Collection）：Purpose / Mission / Core Principles / Architecture Responsibilities / Schema / Scenario Taxonomy / Benchmark Corpus 引用 / Governance / Future Expansion / Golden Rules。F-7。Benchmark Cases 引用 `ASSC_GOLD_BENCHMARK_v1.md`；不內嵌 ASSC-001～100。Documentation only；§1～§12 Runtime Flow / Intent / Contract 不變。 |
| **1.3** | 2026-07-05 | Clarification | §3.4 補充語意解析六面向、Semantic Entity Extraction 定義與旅遊 Entities 示例；明確區分 Semantic Entity Extraction vs Programmatic Keyword/Rule Matching。§3.2 / §5.1 交叉引用。Clarification only；F-1～F-5 / IU-001～IU-006 / §4 Flow 不變。 |
| **1.2** | 2026-07-04 | Freeze（Refinement） | §3.4 補強 Understand：Gemini 唯一 Semantic Core、五要素、禁止 Keyword/Rule/if-else、Legacy Transition 明確化。聚焦 Understand；不修改其它 SSOT。 |
| **1.1** | 2026-07-04 | Freeze（Refinement） | 新增 §3.4 Semantic Understanding Core Policy；§6 Legacy 註記；F-6。 |
| **1.0** | 2026-06-30 | Freeze | 初版 Freeze（F-1～F-5；IU-001～IU-006）。 |

---

*本文件為 AI Intent Understanding v2 唯一 L3 SSOT。AIU Runtime 之定位 / 職責 / Boundary / `AiIntentUnderstandingResult` 契約以本文件為準；ASSC Gold 架構與治理以本文件 §13 為準、Benchmark Cases 以 `ASSC_GOLD_BENCHMARK_v1.md` 為準；統一 Pipeline / Owner / Status / State / Grounding 以 `BATS_AI_CONVERSATION_ARCHITECTURE.md` 為準；Core Principles 以 `BATS_AI_PERSONA.md` §0 為準。不涉及程式實作。*
