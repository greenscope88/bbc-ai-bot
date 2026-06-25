# Phase 9-C-2C — Grounded Response Composer SSOT

**專案：** BBC AI SaaS / BATS / Phase 9-C-2C
**定位：** L3 實作 SSOT — Grounded Response Composer 唯一正式依據
**版本：** v1.0 Draft
**狀態：** Docs Phase — 待 Adopt（尚未 Coding）
**主機參考：** 103.1.222.14（主機 A · `C:\bbc-ai-bot`）

**上層 / 關聯文件：**

| 層級 | 文件 | 關係 |
|------|------|------|
| L0 | `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md` | 協作與文件治理 |
| L3 | `BATS_AI_RUNTIME_DESIGN.md` | Runtime 接入 SSOT；Legacy Runtime Governance 落於該文件 §13 |
| L3 | `BATS_AI_TRAVEL_CONSULTANT_POLICY.md` | 回覆行為政策（Persona / Grounded / Takeover / Ack） |
| L3 | `BATS_GEMINI_RESPONSE_CONTRACT.md` | Gemini Response DTO |
| L3 | `BATS_GEMINI_CLIENT.md` | Gemini Client Adapter |
| L3 | **本文件** | **Grounded Response Composer 契約與接入順序** 唯一 SSOT |

**衝突處理：** Persona / Grounded 政策以 `BATS_AI_TRAVEL_CONSULTANT_POLICY.md` 為準；Runtime 接入順序與 Legacy Governance 以 `BATS_AI_RUNTIME_DESIGN.md` 為準；**Grounded Response Composer 契約（GroundedInput / GroundedOutput）、Composer 職責與接入順序以本文件為準**。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | One-line Goal |
| §2 | Architecture Position |
| §3 | GroundedInput Contract |
| §4 | GroundedOutput Contract |
| §5 | Non-goals |
| §6 | Multi-tenant Rule |
| §7 | Grounded Safety Rules |
| §8 | Implementation Phases |
| §9 | Test Strategy |
| §10 | Relation to Existing Components |
| §11 | Legacy Runtime Governance（指向 `BATS_AI_RUNTIME_DESIGN.md` §13） |
| §12 | Adoption Checklist |

---

## 1. One-line Goal

> **AI 可以自由組織、潤飾語句，但不得新增任何未被 Runtime Grounded Data 支持的事實。**

Composer 只負責「怎麼說」（語氣、排列、開場、結尾、適量 Emoji），事實「說什麼」一律來自 Runtime 已取得的 grounded data。**No Hallucination** 為不可違反的硬約束。

---

## 2. Architecture Position

```
Runtime（Product / Knowledge / Human / Clarification / Waiting）
        │   僅輸出 grounded facts，不組裝 LINE 文案
        ▼
GroundedInput（本文件 §3 契約）
        │
        ▼
GroundedResponseComposer（單一入口；One Runtime / Multi Tenant）
        │   依 reply_policy + tone 組句；受 §7 Grounded Safety 約束
        ▼
GroundedOutput（本文件 §4 契約）
        │
        ▼
LINE Reply / Push（LineService 傳輸層，不組句）
```

**接入點：** `SaaSRouter`（Orchestrator）於 Runtime 產出 grounded payload 之後、`LineService` 傳輸之前呼叫 Composer。Runtime 與 LINE 傳輸層皆不變。

**對齊：** 等同 `BATS_AI_RUNTIME_DESIGN.md` AD-007「Global 語意驅動、Grounded First」之 presentation 層落地。

---

## 3. GroundedInput Contract

> 概念契約（schema 描述，非程式碼）。實作時可為值物件或結構化陣列；欄位語意以本表為準。

```yaml
schema_version: 1
runtime_type: product_search | knowledge_private | knowledge_shared | human_service | clarification | waiting_ack
tenant:
  tenant_key: string          # 來自 tenant registry（非 per-tenant class）
  tenant_sno: string          # Host B wire authority
  company_name: string
  industry_code: string
tone:
  persona: string             # 例 travel_consultant_young_female；來自 registry / tone config
  allow_emoji: bool
grounded_facts:               # 通用 grounded 事實（Knowledge / Human / 補充）
  - fact_id: string|null      # qa_id / price_id / service_id / link_id / 來源追蹤
    fact_type: qa | price | service_item | link | profile_field | faq_shared | product_row
    label: string|null
    value: string             # 必須來自 runtime；composer 不得發明
    source_ref: string|null   # trace / 來源憑證（稽核用）
product_list:                 # product_search 專用；其餘 runtime_type 可為空
  items:
    - title: string           # required（已對齊 title/couponName/name/行程名稱 fallback）
      dates: [string]
      price: string|null
      departure: string|null
      detail_url: string|null
      schedule_urls: [string]
  primary_url: string|null
  multi_source_links:
    - platform: string
      search_url: string
external_links:               # knowledge external_product_links 等
  - platform: string|null
    label: string|null
    url: string
reply_policy:
  mode: recommend | no_results | clarification | acknowledge | human_handoff | empty_profile
  grounded_only: true         # 強制；無 facts 時只能走 no_results / human_handoff
  max_products: int           # 例 3
  include_preference_hints: bool
metadata:
  customer_query: string
  bats_search_intent: object|null
  conversation_status: AI_ACTIVE | HUMAN_ACTIVE
  trace_id: string|null
```

**必含欄位（最小集）：** `schema_version`、`runtime_type`、`tenant`、`tone`、`grounded_facts`、`product_list`、`external_links`、`reply_policy`、`metadata`。

---

## 4. GroundedOutput Contract

```yaml
reply_text: string                         # 最終 LINE 文案（單一字串）
reply_type: normal_reply | clarification | waiting_ack | human_agent_fallback | no_results
grounded: bool                             # 是否由 grounded facts 支撐
used_facts_count: int                      # 實際引用的 facts 數（測試與稽核用）
layout_profile: product_rich_v1 | knowledge_standard_v1 | minimal_v1
voice_profile_used: string                 # 實際採用之 persona
```

**必含欄位（最小集）：** `reply_text`、`reply_type`、`grounded`、`used_facts_count`、`layout_profile`、`voice_profile_used`。

**不變式：**
- `grounded_only = true` 且 `used_facts_count = 0` → `reply_type ∈ { no_results, human_agent_fallback }`，**禁止** 產出含具體事實之 `normal_reply`。
- `used_facts_count` 不得大於 input 中可用 facts / product items 總數（防止虛構）。

---

## 5. Non-goals

本 Phase（9-C-2C）**不修改**：

- Product Runtime（`TourPromptContextService` 搜尋 pipeline）
- SearchCondition
- Host B API
- Tenant Private Runtime matcher（`ServiceQaMatcher`、`SpecialPriceMatcher`、`ServiceItemsMatcher`、`ExternalProductLinkMatcher`）
- Industry Shared matcher（`SharedKnowledgeMatcher`）
- GeminiClient 架構
- Waiting Reply 觸發時機
- Human Takeover gate（`ConversationStatusResolver` / `FinalReplyGate`）

Composer 只接收 Runtime 既有輸出並組句；不改變上述任何決策邏輯。

---

## 6. Multi-tenant Rule

**禁止建立：**
- `travel_a Composer`
- `travel_b Composer`
- `travel_c Composer`
- 任何「每 Tenant 專用 Composer / Formatter / Runtime」

**唯一 Composer，全租戶共用。** Tenant 差異只能來自：

| 來源 | 內容 |
|------|------|
| tenant registry | tenant_key、company_name、feature flags、industry_code |
| tone config | persona、allow_emoji |
| grounded data | 各 tenant 自有 corpus / catalog / FAQ |
| source adapters | 各 tenant 來源實例（multi-source / knowledge provider） |

200 tenants → **1 個 Composer + N 份 registry/data**，新增 tenant 不得新增 Composer 類別。

---

## 7. Grounded Safety Rules

**Composer 可以：**
- 調整語氣
- 排列順序
- 加開場白
- 加結尾
- 適量 Emoji
- 將既有 facts 整理成自然語句

**Composer 禁止：**
- 新增價格
- 新增日期
- 新增 URL
- 新增電話
- 新增政策
- 新增商品
- 擴寫未命中的知識
- 自行推測事實

**落地約束：** 所有具體事實（價格 / 日期 / URL / 電話 / 政策 / 商品標題）必須能逐一對應到 `grounded_facts` 或 `product_list` 內的來源欄位；無對應者一律不得出現在 `reply_text`。

---

## 8. Implementation Phases

| 階段 | 範圍 | 重點 |
|------|------|------|
| **9-C-2C-1** | GroundedResponseComposer 薄封裝；**Knowledge Path 先接入** | 委派現有 `KnowledgeResponseComposer` / `HumanServiceResponseComposer`；行為**完全不變**（E2E 對照） |
| **9-C-2C-2** | Product Path 接入 Composer | 統一 Product Layout 與 No Results Fallback（對齊 grounded `product_list`） |
| **9-C-2C-3** | Gemini Optional Semantic Polish | Gemini 僅作語意潤飾，**必須完全受 Grounded Contract 約束**（不得新增事實） |
| **9-C-2C-4** | Human Service Resume 接入同一 Composer | Human handoff 文案統一由 Composer 產出 |

**順序原則：** 風險由低到高。Knowledge path E2E 已穩定，先接入；Product layout 統一其次；Gemini live 與 Human resume 最後，且皆受同一 contract validator 約束。

---

## 9. Test Strategy

| 測試類別 | 驗收標準 |
|----------|----------|
| **Facts 不得遺失** | input 提供的 facts / product items 必須完整出現在 `reply_text`（在 `max_products` 內） |
| **未提供 facts 不得生成** | `used_facts_count = 0` → 只能 `no_results` / `human_agent_fallback`；`reply_text` 不得含任何具體事實 |
| **Knowledge E2E 不變** | 9-C-2C-1 後 `請問客服電話` / `行李超重怎麼辦` 回覆與接入前一致 |
| **Product E2E 不變** | 9-C-2C-2 後 `近期東京` 仍顯示商品、無誤判查無商品 |
| **Human Fallback 不變** | 未命中 → Human Service 文案與接入前一致 |
| **No Hallucination** | 注入「facts 為空 / 缺 URL / 缺價格」案例，確認 Composer 不補值 |
| **200 Tenant 共用** | 不同 tenant_key + 同 grounded data → 同 layout，僅 tone/company 變化；無 per-tenant 類別 |
| **Contract 不變式** | `used_facts_count` 上限、`reply_type` 與 `reply_policy.mode` 對應關係 |

測試應以既有 E2E 路徑作 **regression baseline**，確保接入前後逐字（或結構）一致。

---

## 10. Relation to Existing Components

| 現有元件 | 路徑 | 與 Grounded Response Composer 的未來關係 |
|----------|------|------------------------------------------|
| `KnowledgeResponseComposer` | `core/knowledge/` | 9-C-2C-1 由 Composer **委派**；後續演進為 `KnowledgeLayoutStrategy`（內嵌 strategy，不獨立散落） |
| `HumanServiceResponseComposer` | `core/knowledge/` | 9-C-2C-4 收斂為 `HumanServiceLayoutStrategy`，由 Composer 統一呼叫 |
| `ProductRecommendationBuilder` | `core/product_source/recommendation/` | **保留**；職責為 grounded `recommendation_summary` 結構化，作為 Composer 的 `product_list` 來源，不負責 LINE 文案 |
| `TravelConsultantPersonaRuntime` | `core/product_source/recommendation/` | Product 文案組裝邏輯遷入 `ProductLayoutStrategy` + `PersonaEngine`；`composeNoResultsMessage` 收斂為 `reply_policy=no_results` |
| `TourFallbackFormatter` | `core/tour_fallback_formatter.php` | Legacy 商品列表格式參考；Product layout 統一時對齊其欄位豐富度（🚩📅💰🛫📄），但不為 Legacy 新增能力 |
| `GeminiRenderer` | `core/product_source/renderer/gemini/` | **保留**；負責 `ChannelPublishPlan → GeminiContextDocument`，作為 Gemini polish 前置；不變更架構 |
| `GeminiClient` | `core/product_source/integration/` | 9-C-2C-3 作為 **optional semantic polish**；輸出仍須通過 Grounded Contract 驗證；架構不變 |
| `AcknowledgementReplyComposer` | `core/search/` | Waiting Reply；可選以 `reply_policy=acknowledge` 接入，現階段固定句可保留 |
| `DateClarificationLineFormatter` | `core/date_clarification_line_formatter.php` | 收斂為 `ClarificationLayoutStrategy`（`reply_policy=clarification`） |

**收斂方向：** 多個分散 composer / formatter → 單一 `GroundedResponseComposer` + 數個 LayoutStrategy + 單一 PersonaEngine（tone 來自 registry）。Runtime 與 Matcher 完全不動。

---

## 11. Legacy Runtime Governance

Legacy Runtime Governance 之 SSOT 落於 **`BATS_AI_RUNTIME_DESIGN.md` §13**。摘要：

- **BATS Runtime** 為後續所有功能開發**唯一主線**。
- **Legacy Runtime（travel_a）** 僅保留 Migration Reference / Regression Comparison / Rollback Reference。
- 除 Bug Fix / Regression Fix / Security Fix 外，**不得**於 Legacy Runtime 新增任何能力。
- 不得提議 Legacy 與 BATS 雙軌同步演進。
- 待所有租戶遷移完成後，再規劃 Legacy Runtime Sunset。

本 Phase 之 Grounded Response Composer 一律以 BATS Runtime 為接入主線，不為 Legacy 設計專用 Composer。

---

## 12. Adoption Checklist

| 項目 | 狀態 |
|------|------|
| GroundedInput / GroundedOutput 契約定義 | ✅ 本文件 §3 / §4 |
| Non-goals 明確 | ✅ §5 |
| Multi-tenant 單一 Composer 原則 | ✅ §6 |
| Grounded Safety（No Hallucination） | ✅ §7 |
| Implementation Phases（9-C-2C-1～4） | ✅ §8 |
| Test Strategy | ✅ §9 |
| 與既有元件關係 | ✅ §10 |
| Legacy Runtime Governance | ✅ §11（SSOT 於 `BATS_AI_RUNTIME_DESIGN.md` §13） |

---

## Revision History

| 版本 | 日期 | 狀態 | 說明 |
|------|------|------|------|
| **1.0** | 2026-06-25 | Draft | 初版：Grounded Response Composer SSOT（One-line Goal、Architecture Position、GroundedInput/Output、Non-goals、Multi-tenant、Grounded Safety、Implementation Phases、Test Strategy、Component Relations、Legacy Governance 連結） |

---

*本文件為 Phase 9-C-2C Grounded Response Composer 唯一 SSOT。實作前須本文件 Adopt；接入須與 `BATS_AI_RUNTIME_DESIGN.md`、`BATS_AI_TRAVEL_CONSULTANT_POLICY.md` 一致。*
