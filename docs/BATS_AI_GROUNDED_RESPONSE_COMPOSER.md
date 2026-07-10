# BATS AI Grounded Response Composer

**專案：** BBC AI SaaS / BATS / Phase 2-E
**定位：** L3 架構 SSOT — **Grounded Response Composer** 唯一正式依據（定位 / 職責 / Boundary / Input-Output Contract / Persona 整合 / Conversation Continuity / Conversation Commerce）
**版本：** Grounded Response Composer v1.2 Rev.1 — Frozen  
**狀態：** Frozen
**主機參考：** 103.1.222.14（主機 A · `C:\bbc-ai-bot`）
**Branch：** feature/api-gateway-mvp

> 本文件為 **Grounded Response Composer** 之 **L3 架構 SSOT**。它在 `BATS_AI_CONVERSATION_ARCHITECTURE.md`（L2）所定義之統一 Pipeline 下，正式定位 Composer 的職責、邊界、輸入輸出契約，以及與 Persona / Memory / Human Takeover 之整合原則。
>
> **Rev.1（0710）：** Composer 正式定位為 **Express Layer**（表達層）。**不是** Understanding、Normalize、Runtime、Execution。
>
> 本文件 **不重複定義 Persona**（一律引用 `BATS_AI_PERSONA.md` 為 **Read Only Persona**）、**不重複定義 Intent / Routing / State**（引用 L2、`BATS_AI_INTENT_UNDERSTANDING_V2.md`、`BATS_AI_NORMALIZE.md`、`BATS_AI_RUNTIME.md`）、**不重複定義 Memory 內容本體**（引用 `BATS_AI_CONVERSATION_MEMORY.md`）。
>
> **v1.1 契約收斂：** `GroundedInput` / `GroundedOutput` **唯一欄位表** 以本文件 **§8.5 / §9.5** 為準。`PHASE_9C2C_GROUNDED_RESPONSE_COMPOSER.md` 降為 **implementation companion**（接入 Phase / 元件對照 / 測試策略）；欄位語意衝突時以本文件為準。

> **Architecture Freeze（Phase 2-E-0）：** 本文件已凍結 Responsibilities、Out of Scope、Input / Output Contract（§8.5 / §9.5）、Grounded Output Validator、Response Prioritization、Human Takeover Guard、Next Best Action Boundary、P3 Future Boundary。後續 Phase 2-E Coding **不得**反向修改本文件之架構決策；不一致時依 SSOT Authority 修正程式。  
> **Rev.1 Correction** 僅對齊 AIU v2 主線定位／Input／Golden Rules／Persona／Out of Scope；**不**重新設計 Architecture，**不**修改 §8.5／§9.5 欄位表。

---

## Frozen Architecture Decisions（Phase 2-E-0）

| ID | 決策 | 狀態 |
|----|------|------|
| **F-GRC-1** | Composer 為 L3 **NLG 層**；位於 Grounding 之後、Channels 之前 | ✅ Frozen |
| **F-GRC-2** | **Generative NLG + Grounded Facts**（Facts are Grounded, Language is Generative） | ✅ Frozen |
| **F-GRC-3** | Input / Output **唯一欄位表**（§8.5 / §9.5） | ✅ Frozen |
| **F-GRC-4** | `conversation_context` 由 **Grounding Layer** 組裝；Composer read-only consumer | ✅ Frozen |
| **F-GRC-5** | **Human Takeover 雙層 Guard**（Orchestrator Primary + Composer Defense-in-Depth） | ✅ Frozen |
| **F-GRC-6** | **Grounded Output Validator** 為 Composer 內部必要 Gate（fact fidelity；非 Policy Safety） | ✅ Frozen |
| **F-GRC-7** | **Response Prioritization**（GRC-010）僅 Presentation Ordering | ✅ Frozen |
| **F-GRC-8** | **Next Best Action**：Input hint 由 Grounding 映射（Optional）；Composer consume-only | ✅ Frozen |
| **F-GRC-9** | **Persona by Reference**（`BATS_AI_PERSONA.md`）；Composer 不重複定義 | ✅ Frozen |
| **F-GRC-10** | **P3 項目**（Promotion Push / BI / Dashboard / Report）不納入 MVP | ✅ Frozen |

---

## Adopted Decisions（v1.1 Refinement → 已併入 Freeze）

| ID | 決策 |
|----|------|
| **AD-GRC-001** | Input / Output **唯一欄位表** 定義於本文件 §8.5 / §9.5（Freeze Preparation） |
| **AD-GRC-002** | `conversation_context` 由 **Grounding Layer** 組裝注入 `GroundedInput`；Composer 為 **read-only consumer** |
| **AD-GRC-003** | Human Takeover：**Primary Guard = Orchestrator**（不呼叫 Composer Outbound）；**Defense-in-Depth = Composer** 回傳 `reply_suppressed` |
| **AD-GRC-004** | **Grounded Output Validator** 為 Composer **內部必要 Gate**；驗證 fact fidelity，**非** Policy / Content Safety |
| **AD-GRC-005** | `next_best_action_hint`：**Input 可選 MVP**（由 Grounding 映射，Composer consume）；**Output `next_best_action_presented` 可選**；Composer **不得**自創未 Grounded 之 NBA |
| **AD-GRC-006** | **Response Prioritization** 為 Composer 正式職責（GRC-010）；僅 **Presentation Ordering**，不改變 facts |

---

## 上層 / 關聯文件

| 層級 | 文件 | 關係 |
|------|------|------|
| L0 | `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md` | 協作與文件治理 |
| L0 | `BATS_AI_PERSONA.md` §0 | BBC Core Principles（**Core Principle #2**） |
| L2 | `BATS_AI_CONVERSATION_ARCHITECTURE.md` | 統一 Pipeline（CA-001）/ Grounding（CA-008）/ Owner & Status |
| L3 | **本文件** | **Composer 架構 / Contract / Boundary** 唯一 SSOT |
| L3 | `BATS_AI_INTENT_UNDERSTANDING_V2.md` | AIU Understanding；Composer **不得**做 Intent Understanding |
| L3 | `BATS_AI_NORMALIZE.md` | AIU Normalize；Composer **不得**做 Normalize |
| L3 | `BATS_AI_RUNTIME.md` | BBC AI Runtime（Execution）；Composer **不得**做 Routing／Dispatch／Execution |
| L3 | `BATS_AI_CONVERSATION_MEMORY.md` | Customer Memory Card 欄位語意；Composer **不得**實作 Memory Runtime |
| L3 | `BATS_AI_RUNTIME_DESIGN.md` | Runtime 接入 / Legacy Governance（實作參考；Execution 架構以 `BATS_AI_RUNTIME.md` 為準） |
| L3 | `PHASE_9C2C_GROUNDED_RESPONSE_COMPOSER.md` | Implementation companion（接入 Phase / 元件 / 測試；**契約欄位見本文件 §8.5 / §9.5**） |
| L3 | `BATS_AI_TRAVEL_CONSULTANT_POLICY.md` | 旅遊顧問回覆行為政策 |

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Document Purpose |
| §2 | Phase Goal |
| §3 | Architecture Position |
| §4 | Responsibilities |
| §5 | Out of Scope |
| §6 | Golden Rules |
| §7 | Scope |
| §8 | Input Contract |
| §9 | Output Contract |
| §10 | Grounded Output Validator |
| §11 | Runtime Boundary |
| §12 | Persona Integration |
| §13 | Conversation Continuity |
| §14 | Conversation Commerce（Sales Guidance） |
| §15 | Human Takeover Guard |
| §16 | Design Philosophy |
| §16.1 | Facts vs Expression |
| §16.2 | Natural Response Philosophy |
| §17 | Future Extension（P3 Boundary） |
| §18 | Cross-Reference Index |
| §19 | Architecture Freeze Result |
| §20 | Post-Freeze / Coding Phase Notes |

---

## 1. Document Purpose

| 目的 | 說明 |
|------|------|
| **定位** | Composer 為 L3 **Express Layer（表達層／NLG）**；位於 BBC AI Runtime／Grounding 之後、Channel 之前 |
| **是** | Express — 依已 Grounded 之事實生成自然語言回覆 |
| **不是** | Understanding、Normalize、Runtime、Execution |
| **收斂** | v1.1 合併 `PHASE_9C2C` 契約為 **唯一欄位表**；補強 Response Prioritization、Validator 邊界、Human Guard、NBA Contract |
| **約束** | Freeze 後 Coding 須依本文件；SSOT Authority 優先於程式 |
| **非目的** | 不定義 Persona、Intent、Retrieval、State、BI / Dashboard |

---

## 2. Phase Goal

> **在 Grounded Facts 約束下，以 Generative 方式產出自然、連貫、符合 Persona 的客戶回覆；Facts are Grounded，Language is Generative。**

---

## 3. Architecture Position

### 3.1 AIU v2 主線位置（Rev.1）

```
Customer Message
        → Gemini / AIU Understanding
        → AIU Normalize
        → BBC AI Runtime（Execution：Routing / Dispatch / Coordination）
        → Runtime Modules（Product / Knowledge / Human / …）
        → Grounding（組裝 GroundedInput）
        → ★ Grounded Response Composer（Express Layer）★
        → Channels
```

| Composer **是** | Composer **不是** |
|-----------------|-------------------|
| Express Layer | Understanding |
| NLG / Presentation | Normalize |
| Fact-constrained Expression | Runtime / Execution |
| Read-only consumer of Grounded Input | Product / Knowledge / Human Search |

### 3.2 Pipeline（既有表述，對齊主線）

```
Customer Message → AIU → BBC AI Runtime → Execution Modules
        → Grounding（組裝 GroundedInput，含 conversation_context）
        → Grounded Response Composer（Express / NLG + Validator + Prioritization）
        → Channels
```

**設計信條：** `Facts are Grounded. Language is Generative.`

---

## 4. Responsibilities

| ID | 職責 | 說明 |
|----|------|------|
| **GRC-001** | **Grounded NLG** | 依 GroundedInput **即時生成**自然語言（Generative；非固定 Template） |
| **GRC-002** | **Fact Fidelity** | `reply_text` 中具體事實可逐一對應 Input 來源；不得新增 / 猜測 / 推論 |
| **GRC-003** | **Persona Rendering** | 引用 `BATS_AI_PERSONA.md`（Tone / Style / Greeting / Emoji / Commerce 策略） |
| **GRC-004** | **Conversation Continuity** | 消費 `conversation_context` snapshot，延續多輪對話 |
| **GRC-005** | **Conversation Commerce Guidance** | 在 Grounded 範圍內引導 Next Best Action（§14） |
| **GRC-006** | **Layout & Presentation** | `layout_profile`、段落、開場 / 結尾 |
| **GRC-007** | **Output Contract** | 產出 §9.5 定義之 `GroundedOutput` |
| **GRC-008** | **Grounded Output Validator** | Composer **內部必要 Gate**；驗證輸出 fact 可溯源（§10）；**非** Policy Safety |
| **GRC-009** | **Multi-Tenant Presentation** | 單一 Composer；tenant 差異來自 registry / tone / data |
| **GRC-010** | **Response Prioritization** | 依 Context / Memory / Intent 信號 / Facts / Commerce Goal 決定 **資訊呈現順序**；**僅 Presentation Ordering**（§4.1） |

### 4.1 Response Prioritization（GRC-010 細則）

Composer 在 NLG 前 / 中，依下列 **唯讀輸入** 決定 facts 與段落之 **呈現優先序**：

| 排序信號 | 來源（Input 欄位） | 用途 |
|----------|-------------------|------|
| Conversation Context | `conversation_context` | 已知 destination / dates → 優先呼應、避免重複詢問 |
| Customer Memory | `conversation_context`（Memory Card snapshot） | Outstanding issues 優先處理 |
| Intent 信號 | `runtime_type`、`reply_policy.mode` | 決定 product vs knowledge vs clarification 之段落結構 |
| Grounded Facts | `grounded_facts`、`product_list` | 依 relevance 排列商品 / QA / 連結 |
| Commerce Goal | `reply_policy`、`next_best_action_hint`（若有） | 結尾引導優先序 |

**限制（Invariant）：**

- ❌ 不改變、不增刪 Grounded Facts 內容
- ❌ 不新增未驗證資訊
- ❌ 不取代 Runtime / AIU 決策
- ✅ 僅調整 **Presentation Ordering**（先說什麼、後說什麼、是否合併段落）

---

## 5. Out of Scope

| ID | Out of Scope | 正式 SSOT |
|----|--------------|-----------|
| **GRC-OOS-001** | Intent Understanding | `BATS_AI_INTENT_UNDERSTANDING_V2.md` |
| **GRC-OOS-002** | Runtime Dispatch / Routing | `BATS_AI_RUNTIME.md`；L2 §13 |
| **GRC-OOS-003** | Knowledge Retrieval / Knowledge Search | Knowledge Runtime |
| **GRC-OOS-004** | Product Search | Product Runtime |
| **GRC-OOS-005** | Conversation State 寫入／Conversation State Logic | L2 §5～§12；Conversation State Module |
| **GRC-OOS-006** | Conversation Memory 寫入／Conversation Memory Runtime | `BATS_AI_CONVERSATION_MEMORY.md` |
| **GRC-OOS-007** | Human Service／Human Runtime | Human Service Runtime SSOT |
| **GRC-OOS-008** | Persona 定義（Composer 僅 Read Only 引用） | `BATS_AI_PERSONA.md` |
| **GRC-OOS-009** | Channel Transport | Orchestrator / LineService |
| **GRC-OOS-010** | Human Takeover 期間 Outbound | §15 |
| **GRC-OOS-011** | Post-Conversation Promotion Push | §17 P3 |
| **GRC-OOS-012** | Fact Production | L2 §14 Grounding |
| **GRC-OOS-013** | **Policy Safety / Content Moderation** | 非 Grounded Output Validator；屬 Persona Safety / 未來 Policy 層 |
| **GRC-OOS-014** | **BI / Dashboard / CRM / Daily Report** | §17 P3；非 Composer MVP |
| **GRC-OOS-015** | **`conversation_context` 組裝** | Grounding Layer（§8.3）；Composer 不組裝 |
| **GRC-OOS-016** | **Entity Extraction** | AIU Understanding / Entity Core |
| **GRC-OOS-017** | **AIU Normalize** | `BATS_AI_NORMALIZE.md` |
| **GRC-OOS-018** | **BBC AI Runtime Execution**（Lifecycle／Coordination 以外之領域執行） | `BATS_AI_RUNTIME.md` |

> **RC-5：** Composer **不得**負責 Intent Understanding、Entity Extraction、AIU Normalize、Runtime Routing、Runtime Dispatch、Product Search、Knowledge Search、Human Runtime、Conversation Memory Runtime。

---

## 6. Golden Rules

### 6.1 Grounded Golden Rules（AIU v2 / Rev.1）

| ID | 規則 |
|----|------|
| **GR-1** | Composer 只能根據 Runtime 已存在且已驗證（Grounded）的事實回覆。 |
| **GR-2** | 允許自然、多樣化語句。 |
| **GR-3** | 不得新增、推測、修改、補充任何 Runtime 未提供的事實。 |
| **GR-4** | Persona 只能影響語氣、排版、品牌風格；**不得**改變任何 Grounded Facts。 |

### 6.2 Supporting Golden Rules（既有 Freeze，維持）

| ID | 規則 |
|----|------|
| **GR-001** | Grounded Response Only（`BATS_AI_PERSONA.md` Core Principle #2）— 對齊 **GR-1** |
| **GR-002** | Facts are Grounded, Language is Generative — 對齊 **GR-1** / **GR-2** |
| **GR-003** | Composer Never Queries Runtime（CA-008）— 不得自行搜尋任何資料 |
| **GR-004** | Persona by Reference（Read Only）— 對齊 **GR-4** |
| **GR-005** | One Composer, Multi-Tenant |
| **GR-006** | Protect Before Extend |
| **GR-007** | Human Outbound Stop, Context Continue |
| **GR-008** | SSOT Authority |
| **GR-009** | **Ordering ≠ Inventing**（Prioritization 不得變更 fact 集合）— 對齊 **GR-3** |

---

## 7. Scope

### 7.1 Phase 2-E MVP（Architecture + Coding 目標）

| 包含 | 不包含 |
|------|--------|
| Generative NLG（漸進取代 pass-through） | P3 Promotion Push |
| Unified Contract §8.5 / §9.5 | AI Daily Summary |
| Grounded Output Validator | CRM Dashboard |
| Response Prioritization | Sales Pipeline Report |
| Continuity / Commerce / Human Guard | PDF / Excel Daily Report |
| Persona 引用整合 | Dashboard / 營運 UI |

---

## 8. Input Contract

### 8.0 Composer Input 原則（Rev.1 / RC-2）

Composer **僅接收**下列語意類別（經 Grounding 組裝為 `GroundedInput`）：

| 允許 Input 類別 | 說明 |
|-----------------|------|
| **Runtime Facts** | 已驗證之 Grounded Facts／product_list／links 等 |
| **Runtime Context** | `runtime_type`、`reply_policy` 等執行呈現信號 |
| **Conversation Context** | `conversation_context`／owner／status／resume（唯讀 snapshot） |
| **Tenant Context** | tenant／tone registry 投影 |
| **AI Persona** | `BATS_AI_PERSONA.md`（Read Only；見 §12） |

**禁止：**

- Composer **不得自行搜尋**任何資料（Product／Knowledge／Memory／外部 API）
- Composer **不得**回查 Runtime Modules 以補洞
- Composer **不得**消費 `dispatch_plan`／`execution_hint`（raw）／`runtime_action`／`search_action` 作路由

### 8.1 語意區塊概覽

| 區塊 | 組裝責任 | Composer 角色 |
|------|----------|---------------|
| Execution 產出 facts（Runtime Facts） | Grounding ← BBC AI Runtime / Modules | consume |
| `conversation_context`（Conversation Context） | **Grounding** ← Memory Runtime（read） | consume |
| `conversation_owner` / `status` | **Grounding** ← State Runtime（read） | consume |
| `tone` / `tenant`（Tenant Context + Persona key） | Grounding ← Registry | consume |
| Orchestrator 識別 | Orchestrator → Grounding（`conversation_id` 等） | — |

### 8.2 Composer 禁止作為 Input 的內容

| 類別 | 範例 | 原因 |
|------|------|------|
| 路由 metadata | `dispatch_plan`、`execution_hint`（raw） | 非 NLG 決策 |
| Runtime 內部 | Matcher raw、Search API JSON | 未經 Grounding |
| Composer 自產 | 任何由 Composer 生成的欄位 | 違反單向資料流 |

> **例外：** Grounding 可將 AIU `execution_hint` **映射** 為 `reply_policy` / `next_best_action_hint`（Presentation 信號），Composer 僅 consume 映射結果。

### 8.3 `conversation_context` Injection（AD-GRC-002）

**建議定案：Grounding Layer 負責組裝。**

```
Orchestrator
   │  提供 conversation_id, tenant_sno, trace_id
   ▼
Grounding Layer
   │  ① 彙整 Execution Runtime facts → grounded_facts / product_list
   │  ② 唯讀呼叫 Conversation Memory Runtime.get() → conversation_context snapshot
   │  ③ 唯讀呼叫 Conversation State Runtime → owner / status / resume_context
   │  ④ 映射 reply_policy / next_best_action_hint（來自 Runtime 決策，非 Composer）
   ▼
GroundedInput（完整 payload）
   ▼
Composer（read-only consumer）
```

| 層 | 角色 | 可否組裝 `conversation_context` |
|----|------|--------------------------------|
| **Grounding Layer** | **負責組裝** | ✅（唯讀讀取 Memory / State） |
| **Orchestrator** | 提供識別符、觸發 Grounding、Human Guard | ❌（不直接注入 context 進 Composer） |
| **Conversation Memory Runtime** | 提供 get() API | ❌（只提供資料，不組 GroundedInput） |
| **Composer** | NLG | ❌（**禁止**；保持 read-only consumer） |

### 8.4 `next_best_action_hint` Input（AD-GRC-005）

| 項目 | 規格 |
|------|------|
| **欄位** | `reply_policy.next_best_action_hint` 或 `metadata.next_best_action_hint` |
| **產生者** | **Grounding Layer**（映射自 Runtime / `reply_policy.mode` / 合法之 AIU advisory 信號） |
| **Composer** | **僅 consume**；用於 GRC-010 Prioritization 與 §14 Commerce 呈現 |
| **MVP** | **Optional**（有則用；無則依 `reply_policy.mode` 推導呈現順序） |
| **禁止** | Composer 自創未 Grounded 之 NBA |

### 8.5 Unified Input Contract Table（Freeze Preparation）

> **唯一欄位表。** 標記：🧊 Frozen Candidate｜✅ MVP Required｜⭕ Optional｜🔮 Future｜🚫 Composer 不得產生

| 欄位 / 區塊 | 標記 | 說明 |
|-------------|------|------|
| `schema_version` | 🧊 ✅ | 契約版本（int；目前 candidate = `1`） |
| `runtime_type` | 🧊 ✅ | `product_search` \| `knowledge_private` \| `knowledge_shared` \| `human_service` \| `clarification` \| `waiting_ack` |
| `tenant.tenant_key` | 🧊 ✅ | Registry |
| `tenant.tenant_sno` | 🧊 ✅ | Host B authority |
| `tenant.company_name` | 🧊 ✅ | 呈現用 |
| `tenant.industry_code` | ⭕ | 行業呈現修飾 |
| `tone.persona` | 🧊 ✅ | Persona registry key |
| `tone.allow_emoji` | 🧊 ✅ | Emoji policy gate |
| `grounded_facts[]` | 🧊 ✅ | 事實清單；可空陣列 |
| `grounded_facts[].fact_id` | ⭕ | 稽核追蹤 |
| `grounded_facts[].fact_type` | 🧊 ✅ | `qa` \| `price` \| `service_item` \| `link` \| … |
| `grounded_facts[].value` | 🧊 ✅ | 必須來自 Runtime |
| `grounded_facts[].source_ref` | ⭕ | trace / 來源憑證 |
| `product_list` | 🧊 ✅ | Product 路徑；非 product 可空 |
| `product_list.items[]` | 🧊 ✅ | title required；dates / price / url 等 |
| `external_links[]` | 🧊 ✅ | 可空 |
| `reply_policy.mode` | 🧊 ✅ | `recommend` \| `no_results` \| `clarification` \| … |
| `reply_policy.grounded_only` | 🧊 ✅ | 強制 `true` |
| `reply_policy.max_products` | ⭕ | 預設 3 |
| `reply_policy.next_best_action_hint` | ⭕ | Advisory；Grounding 映射 |
| `metadata.customer_query` | 🧊 ✅ | 本輪客戶原文 |
| `metadata.trace_id` | 🧊 ✅ | 稽核 |
| `metadata.conversation_id` | 🧊 ✅ | 對話識別 |
| `metadata.bats_search_intent` | ⭕ | Prioritization 信號 |
| `conversation_context` | 🧊 ✅ | Memory Card snapshot；Grounding 組裝 |
| `conversation_owner` | 🧊 ✅ | `AI` \| `HUMAN` |
| `conversation_status` | 🧊 ✅ | `ACTIVE` \| `WAITING_*` \| `COMPLETED` \| `CLOSED` |
| `resume_context` | ⭕ | AI Resume 呈現 |
| `dispatch_plan` | 🚫 | **禁止**進入 Composer Input |
| `execution_hint`（raw） | 🚫 | **禁止** raw；僅允許 Grounding 映射後信號 |
| Composer 生成之草稿 | 🚫 | 單向資料流 |

---

## 9. Output Contract

### 9.1 架構不變式（Invariants）

| ID | 不變式 |
|----|--------|
| **OUT-001** | `grounded=true` 且 `used_facts_count=0` → 禁止 `normal_reply` |
| **OUT-002** | `used_facts_count` ≤ Input 可用 facts 總數 |
| **OUT-003** | `reply_text` 具體事實可對應 Input |
| **OUT-004** | `conversation_owner=HUMAN` → §15 Human Guard |
| **OUT-005** | 不得引入未提供之優惠 / 名額 / 商品 |
| **OUT-006** | Validator 未 PASS → 不得輸出 `normal_reply`（§10） |

### 9.2 `next_best_action_presented` Output（AD-GRC-005）

| 項目 | 規格 |
|------|------|
| **欄位** | `next_best_action_presented`（string \| null） |
| **語意** | Composer 在 NLG 中 **實際呈現** 的引導類型摘要（advisory echo） |
| **產生者** | **Composer**（僅描述已 Grounded 呈現之引導，非新決策） |
| **MVP** | **Optional** |
| **Future** | 可擴充 enum 化 |
| **禁止** | 值不得暗示 Input 未 Grounded 之優惠 / 名額 / 商品 |

### 9.5 Unified Output Contract Table（Freeze Preparation）

| 欄位 | 標記 | 說明 |
|------|------|------|
| `reply_text` | 🧊 ✅ | 客戶可見文案；Human Guard 時為空字串 |
| `reply_type` | 🧊 ✅ | `normal_reply` \| `clarification` \| `waiting_ack` \| `human_agent_fallback` \| `no_results` \| `suppressed_human_takeover` |
| `grounded` | 🧊 ✅ | bool |
| `used_facts_count` | 🧊 ✅ | int；稽核 |
| `layout_profile` | 🧊 ✅ | 例 `product_rich_v1` / `knowledge_standard_v1` / `minimal_v1` |
| `voice_profile_used` | 🧊 ✅ | 實際 persona key |
| `reply_suppressed` | 🧊 ✅ | bool；Human Takeover defense-in-depth |
| `validation_passed` | 🧊 ✅ | bool；Grounded Output Validator 結果 |
| `validation_notes` | ⭕ | Validator 備註（非 Policy Safety） |
| `safety_notes` | ⭕ | 契約 invariant 備註 |
| `referenced_fact_ids` | ⭕ | 引用追蹤 |
| `next_best_action_presented` | ⭕ | Advisory echo（§9.2） |
| `dispatch_plan` | 🚫 | Composer **不得**輸出 |
| `routing_decision` | 🚫 | Composer **不得**輸出 |

---

## 10. Grounded Output Validator

> **命名定案：** **Grounded Output Validator**（**非**「AI Safety Gate」／「Policy Safety」）。

### 10.1 定位

| 項目 | 規格 |
|------|------|
| **所屬** | Composer **內部**子元件（`GroundedOutputValidator` 或等價） |
| **時機** | NLG 完成後、回傳 `GroundedOutput` 前；**必要 Gate** |
| **與 GRC-008 關係** | GRC-008 = 職責；§10 = 行為規格 |

### 10.2 負責驗證（In Scope）

| 檢查 | 說明 |
|------|------|
| **Fact traceability** | `reply_text` 中價格 / 日期 / URL / 電話 / 商品名 / 政策是否可對應 Input facts |
| **Count bound** | `used_facts_count` ≤ 可用 facts 數 |
| **Grounded / reply_type 一致** | OUT-001 / OUT-006 |
| **No invented URLs / prices** | 字串比對 / 來源子集檢查（實作細節留 Coding Phase） |
| **Human Guard 一致** | `conversation_owner=HUMAN` → `reply_suppressed=true` 且 `reply_text` 空 |

### 10.3 不負責（Out of Scope）

| 不負責 | 負責層 |
|--------|--------|
| 仇恨 / 暴力 / 色情等 **Content Moderation** | 未來 Policy 層（非 MVP） |
| **Persona Safety 政策**（品牌禁語） | `BATS_AI_PERSONA.md` §5 + Persona Engine |
| **Intent / Routing 正確性** | AIU / Dispatch |
| **Facts 正確性（業務真偽）** | Execution Runtime + Grounding |
| **是否該轉真人** | Runtime / FinalReplyGate |

### 10.4 與 Grounded Facts 的關係

- Validator **只驗證 NLG 輸出是否為 Input facts 之子集表述**（syntactic / referential fidelity）。
- Validator **不**重新查詢 Runtime、**不**修正 facts、**不**補洞生成文案。
- 驗證失敗 → 降級為 `no_results` / `human_agent_fallback` / `validation_passed=false`（**不得** silent pass）。

---

## 11. Runtime Boundary

（同 v1.0；補充 Grounding 組裝 `conversation_context`、Validator 為 Composer 內部。）

**單句：** Composer 是 **Express Layer**；只消費 `GroundedInput`；只產出經 Validator 之 `GroundedOutput`；只負責 NLG + Presentation Ordering。  
**不是** Understanding／Normalize／Runtime／Execution；**不得**自行搜尋資料。

---

## 12. Persona Integration

（同 v1.0；Persona 一律引用 `BATS_AI_PERSONA.md`。Validator **不**取代 Persona Safety。）

### 12.1 Read Only Persona（Rev.1 / RC-4）

| 項目 | 規格 |
|------|------|
| **來源** | `BATS_AI_PERSONA.md` |
| **Composer 角色** | **Read Only** 引用 |
| **可影響** | 語氣、排版、品牌風格（對齊 **GR-4**） |
| **不得影響** | Runtime Facts／Grounded Facts／任何事實內容 |
| **不得** | 在 Composer 內重新定義或覆寫 Persona SSOT |
---

## 13. Conversation Continuity

（同 v1.0；`conversation_context` 由 Grounding 注入 §8.3。）

---

## 14. Conversation Commerce（Sales Guidance）

（同 v1.0；NBA 須 Grounded；`next_best_action_hint` consume-only §8.4。）

---

## 15. Human Takeover Guard

### 15.1 雙層 Guard（AD-GRC-003）

| 層級 | 角色 | 行為 | 優先 |
|------|------|------|------|
| **Primary** | **Orchestrator**（`SaaSRouter` + Owner / FinalReplyGate） | `conversation_owner=HUMAN` 或 legacy `HUMAN_ACTIVE` → **不得呼叫** Composer 產出 Outbound；**不得**送 LINE | **Authoritative** |
| **Defense-in-Depth** | **Composer** | 若仍被呼叫且 Input 含 `conversation_owner=HUMAN` → 回傳 **`reply_suppressed=true`**、`reply_text=""`、`reply_type=suppressed_human_takeover`、`validation_passed=true` | Fallback |

### 15.2 一致規格

```
IF owner == HUMAN:
  Orchestrator: SKIP compose outbound + SKIP LineService send
  Composer (if invoked): RETURN suppressed output (never throw)
  AIU / Memory: CONTINUE read-only understanding (not Composer scope)
```

### 15.3 Channel 層

- `reply_suppressed=true` 或 `reply_type=suppressed_human_takeover` → Channel **必須**不發送。
- Primary Guard 正常時，Composer defense 路徑 **不應**在 production 觸發；保留供測試與 regression。

### 15.4 Context 延續（不變）

Human Takeover **停止 AI Outbound，不停止 AI Context Understanding**（Memory / AIU read-only 仍運作；見 `BATS_AI_PERSONA.md` Matrix ⑨）。

---

## 16. Design Philosophy

> **本節定位：** 補強 Grounded Response Composer 之 **Express** 核心能力；**不**修改 §8.5 / §9.5 契約、**不**新增 Runtime 架構。

**核心信條（維持 F-GRC-2 Freeze）：**

```
Facts are Grounded. Language is Generative.
```

| 角色 | 規格 |
|------|------|
| **Gemini 不負責** | 決定 Facts |
| **Gemini 負責** | 最佳 **Expression**（自然語言生成） |

Grounded Response Composer 以 **Gemini** 為自然語言生成核心；依據 **Grounded Facts** 自然組織回覆。Facts 權威來自 Execution + Grounding（GRC-OOS-012）。

### 16.1 Facts vs Expression

| 維度 | 責任 | 規則 |
|------|------|------|
| **Facts** | Execution Runtime + Grounding Layer | 決定 `grounded_facts[]`、`product_list`、Knowledge 事實 |
| **Expression** | Gemini（Grounded Response Composer） | 在 Grounded Facts 約束下生成 `reply_text` |

**Grounded Composer 不得：**

- 新增 Facts
- 修改 Facts
- 推測 Facts
- 補充未 Grounded 之 Facts

**維持既有 Freeze：** GRC-002（Fact Fidelity）、**GR-1～GR-4**、GR-002、GR-009（Ordering ≠ Inventing）、Grounded Output Validator（§10）。

### 16.2 Natural Response Philosophy

Grounded Response Composer **不是**固定 Template Engine。

Gemini 可依據 **Grounded Facts** 自由運用自然語言（對齊 **GR-2**）：

| 允許 | 說明 |
|------|------|
| 自然語言 | 符合 Persona 的口語化表達 |
| 多種句型 | 同義、不同結構的 Grounded 表述 |
| 多種語氣 | 溫暖、專業等（Persona 約束內；**GR-4**） |
| 多樣化回覆 | 同一組 Facts 可有多種合法措辭 |

**避免：**

- 固定 Template 作為長期 SSOT
- 公版回覆（每次完全相同字串）
- LINE OA 等 Channel **每次一模一樣**的回覆

**Invariant（不可突破）：**

- 自然語言變化 **不得**改變任何 Grounded Facts 之語意或數值（**GR-3**／**GR-4**）
- 須通過 Grounded Output Validator（§10）
- Opening/Closing Pool（Consultant Policy §8.7）為 **MVP 過渡**，非長期 Template SSOT（見 AD-007 Semantic Driven）

---

## 17. Future Extension（P3 Boundary）

> **以下均非 Phase 2-E MVP**；不得納入本 Phase Coding Scope。

| ID | 項目 | 說明 | Roadmap |
|----|------|------|---------|
| **P3-001** | Post-conversation Promotion Push | 對話完成後 **一次** Grounded 促銷推播 | P3 |
| **P3-002** | AI Daily Summary | 營運摘要 | P3 |
| **P3-003** | CRM Dashboard | 管理介面 | P3 |
| **P3-004** | Sales Pipeline Report | 銷售漏斗報表 | P3 |
| **P3-005** | PDF / Excel Daily Report | 匯出報表 | P3 |
| **P3-006** | Gemini Semantic Polish | 須 100% 通過 Grounded Output Validator | Post-MVP |
| **P3-007** | Content Moderation Policy Layer | 與 Grounded Output Validator 分離 | Post-MVP |

**MVP 邊界句：** Grounded Response Composer MVP = **NLG + Prioritization + Validator + Contract**；**不含** 推播、報表、Dashboard、CRM。

---

## 18. Cross-Reference Index

| 主題 | SSOT |
|------|------|
| **Input / Output 唯一欄位表** | **本文件 §8.5 / §9.5** |
| Pipeline / Grounding | `BATS_AI_CONVERSATION_ARCHITECTURE.md` |
| Persona | `BATS_AI_PERSONA.md`（Read Only） |
| AIU Understanding | `BATS_AI_INTENT_UNDERSTANDING_V2.md` |
| AIU Normalize | `BATS_AI_NORMALIZE.md` |
| BBC AI Runtime | `BATS_AI_RUNTIME.md` |
| Memory Card | `BATS_AI_CONVERSATION_MEMORY.md` |
| 9-C-2C 接入 / 測試 | `PHASE_9C2C_GROUNDED_RESPONSE_COMPOSER.md` |

---

## 19. Architecture Freeze Result

| 凍結項目 | 章節 | 狀態 |
|----------|------|------|
| Responsibilities（GRC-001～010） | §4 | ✅ Frozen |
| Out of Scope（GRC-OOS-001～018） | §5 | ✅ Frozen（Rev.1 補強 AIU v2 排除項） |
| Golden Rules（**GR-1～GR-4** + GR-001～009） | §6 | ✅ Frozen（Rev.1 補強） |
| Input Contract / 唯一欄位表 | §8.5 | ✅ Frozen（Rev.1 僅補強 §8.0 原則；欄位表未改） |
| Output Contract / 唯一欄位表 | §9.5 | ✅ Frozen |
| Grounded Output Validator Boundary | §10 | ✅ Frozen |
| Response Prioritization Boundary | §4.1 | ✅ Frozen |
| Human Takeover Guard | §15 | ✅ Frozen |
| Next Best Action Boundary | §8.4 / §9.2 | ✅ Frozen |
| P3 Future Boundary | §17 | ✅ Frozen |
| Persona Integration（Read Only 引用） | §12 | ✅ Frozen（Rev.1 補強） |
| Conversation Continuity / Commerce | §13 / §14 | ✅ Frozen |
| Design Philosophy（Facts / Expression / Natural Response） | §16 | ✅ Frozen（v1.2 + Rev.1 對齊 GR-1～GR-4） |
| Express Layer 定位（AIU v2 主線） | §1 / §3 | ✅ Frozen（Rev.1） |

**Freeze 日期：** 2026-07-01（v1.0）；2026-07-04（v1.2 Express Refinement）；2026-07-10（**v1.2 Rev.1** AIU v2 Mainline Alignment）  
**Freeze Phase：** Phase 2-E-0 + v1.2 SSOT Refinement（Express）+ Rev.1 Correction  
**下一階段：** 依 0710 AIU v2 主線後續指令（非本文件重新設計）

---

## 20. Post-Freeze / Coding Phase Notes

> 以下項目 **未凍結為架構決策**，留待 Coding Phase 實作細化；**不得**反向修改 §4～§17 之 Frozen Boundary。

| 項目 | 說明 | 階段 |
|------|------|------|
| Validator 機械演算法 | 價格 / URL / 電話 / 商品名之子集比對規則 | Phase 2-E Coding |
| `layout_profile` 完整 enum | 各 `runtime_type` 對應表 | Phase 2-E Coding |
| Gemini Semantic Polish 接入 | 須 100% 通過 Grounded Output Validator | P3-006 / Post-MVP |
| `GroundedInput` / `GroundedOutput` PHP VO 對齊 | 現有 `core/response/*.php` 對齊 §8.5 / §9.5 | Phase 2-E-1 |
| Content Moderation Policy Layer | 與 Grounded Output Validator 分離 | P3-007 |
| Generative NLG 引擎選型 | Persona Engine / LayoutStrategy 實作細節 | Phase 2-E Coding |

---

## Revision History

| 版本 | 日期 | 狀態 | 說明 |
|------|------|------|------|
| **1.2 Rev.1 Frozen** | 2026-07-10 | **Frozen** | Phase A Freeze：狀態標示 Frozen；對齊 AIU Normalize／BBC AI Runtime Frozen。 |
| **1.2 Rev.1** | 2026-07-10 | **Freeze Correction** | AIU v2 主線對齊：Express Layer 定位；Input 原則；GR-1～GR-4；Read Only Persona；Out of Scope 補強。不重新設計 Architecture；不修改 §8.5／§9.5 欄位表。 |
| **1.2** | 2026-07-04 | **Freeze（Refinement）** | §16 聚焦 Express：Facts vs Expression、Natural Response（反 Template/公版/固定回覆）；移除 §16.3 Runtime Boundary。不修改其它 SSOT。 |
| **1.1** | 2026-07-04 | **Freeze（Refinement）** | 擴充 §16 Design Philosophy（§16.1 / §16.2）。 |
| **1.0** | 2026-07-01 | **Freeze** | Phase 2-E-0 Architecture Freeze。 |

---

*本文件為 Phase 2-E Grounded Response Composer **Architecture Freeze** L3 SSOT（含 Rev.1 AIU v2 主線對齊）。Coding 須完全依本文件；程式與 SSOT 不一致時修正程式。*
