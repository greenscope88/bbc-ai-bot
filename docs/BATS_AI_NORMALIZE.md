# BATS AI Normalize（AIU v2）

**專案：** BBC AI SaaS / BATS / AI Intent Understanding v2  
**定位：** L3 架構 SSOT — **AIU v2 Normalize** 唯一正式依據（Translation Layer）  
**版本：** AIU Normalize v1.0 Rev.2 — Frozen（Product Type Closed-set Coordinated Contract v1.0）  
**狀態：** Frozen  

**主機參考：** 103.1.222.14（主機 A · `C:\bbc-ai-bot`）

---

## Frozen Inputs（不得修改）

| # | SSOT | 狀態 |
|---|------|------|
| 1 | AIU v2 Entity Core Final | Frozen |
| 2 | AIU v2 Entity Definition v1.0 Rev.1 | Frozen |
| 3 | AIU v2 Gemini Output Contract v1.0 Rev.1 | Frozen |

**本文件不得修改上述 SSOT。**  
Normalize **僅**負責 Translation。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Document Purpose |
| §2 | Responsibilities |
| §3 | Out of Scope |
| §4 | Normalize Principles |
| §5 | Normalize Flow |
| §6 | Runtime Contract |
| §6.5 | Product Type Closed Set v1（唯一清單） |
| §7 | Normalize Rules |
| §8 | Runtime Examples |
| §9 | Freeze Readiness Review |
| §10 | Minimal Coding Gate（與 `BATS_AI_RUNTIME.md` 聯鎖） |

---

## 1. Document Purpose

### 1.1 目標

**AIU v2 Normalize** 為 **Translation Layer**。

唯一目的：

```text
Gemini Output Contract（Frozen）
        ↓
AIU Entity Validation（執行／owner：`BATS_AI_RUNTIME.md`；值域契約：本文件 §6.5）
        ↓
   AIU Normalize（本文件）
        ↓
BBC AI Runtime Contract
```

Normalize 完成後，BBC AI Runtime 可直接依契約執行（Execute / Govern）。

### 1.2 定位

| 項目 | 規格 |
|------|------|
| **層級** | AIU Runtime 內之 Translation Layer（對齊 `BATS_AI_INTENT_UNDERSTANDING_V2.md` §14.5 L4） |
| **輸入** | 已通過 Entity Validation 之 Gemini Output Contract 形狀結果 + 唯讀 Runtime Snapshots（由 AIU 注入，非 Gemini 改寫） |
| **輸出** | BBC AI Runtime Contract（本文件 §6；含 §6.5 `product_type` Closed Set 結果） |
| **性質** | Mapping / Transformation / Default / Runtime-ready Structure |
| **非性質** | Semantic Understanding、Intent Re-judgment、Entity Re-extraction、Date Re-inference、Product Search Strategy、Keyword Composition、Entity Validation **執行** |

### 1.3 與 Entity Validation 的順序

```text
Gemini Output Contract
        ↓
AIU v2 Entity Validation（前置閘道；Production owner = `AiuOutputContractValidator`；見 `BATS_AI_RUNTIME.md`）
        ↓
AIU v2 Normalize（本文件）
        ↓
BBC AI Runtime Contract
```

| 項目 | 權威文件 |
|------|----------|
| **順序** | **必須** `Entity Validation → Normalize`（不得反轉） |
| **`product_type` Closed Set 值清單與 null 輸出契約** | **本文件 §6.5（唯一）** |
| **Validation 執行位置／owner／authoritative・shadow・resume／consumer boundary** | `BATS_AI_RUNTIME.md` |

> **Invariant：** Normalize **假設**輸入已通過 Entity Validation（結構合法、鍵完整、型別符合 Output Contract；且 `product_type` 已符合 §6.5 或為 `null`）。  
> Normalize **不**擁有 Closed Set allowed-values 的執行驗證；**不**恢復 Validation 已置為 `null` 的非法 `product_type`。
---

## 2. Responsibilities

### 2.1 AIU Normalize 負責

| ID | 職責 | 說明 |
|----|------|------|
| **N-R1** | Entity Mapping | 將已 Validation 之 Gemini `entities` 固定鍵投影至 Runtime Entity 欄位 |
| **N-R2** | Runtime Field Mapping | 僅在 Runtime 無法直接承接時做必要欄位轉換（不改語意；優先保留原始結構） |
| **N-R3** | Runtime Default Value | 對缺失／空值套用契約預設（如 `[]`、`null`、boolean default）；含 trim、empty／whitespace → `null` |
| **N-R4** | Runtime Contract Transformation | 組裝完整 BBC AI Runtime Contract |
| **N-R5** | Runtime-ready Structure | 保證輸出可被 BBC AI Runtime 直接消費 |
| **N-R6** | `product_type` schema／Closed Set v1 契約 | **本文件 §6.5 為唯一允許值清單與 null 輸出契約**（Semantic Contract 結果定義） |

### 2.2 AIU Normalize 不負責

| 禁止項 | 說明 |
|--------|------|
| 重新理解客戶 | 不得對 Customer Utterance 再做 NLU |
| 重新判斷 Intent | 不得覆寫 Gemini `intent` 語意結論 |
| 重新抽取 Entity | 不得從原文再抽新 Entity |
| 重新推論日期 | 不得依口語重算 `date_range`；僅映射 Gemini 已輸出之值 |
| 修改 Gemini Semantic（理解修正） | 不得為「修正理解」改寫語意；**不得**把 Validation 已置 `null` 的 `product_type` 恢復為非法值 |
| Closed Set **執行**驗證 | **不**擁有／**不**執行 `product_type` allowed-values 檢查（歸 `AiuOutputContractValidator`／`BATS_AI_RUNTIME.md`） |
| 搬移 Entity 欄位 | 不得把非法 `product_type` 搬到 `keyword`／其他 entity |
| Clarification Execution | 不得決定是否向客戶追問 |
| Product Search Strategy | 不得替商品源決定 Keyword 組合或搜尋策略 |
| Product Search Execution | 不得組 URL、呼叫商品源、排序商品 |
| Prompt / Composer | 不屬於 Normalize |
| Entity Validation 執行順序／owner | 歸 `BATS_AI_RUNTIME.md` |

### 2.3 Responsibility Boundary

Normalize **輸出** BBC AI Runtime Contract。

以下 **均不屬於** Normalize：

| 排除項 | 歸屬 |
|--------|------|
| Search Query | BBC AI Runtime / Product Adapter |
| Product Mapping | BBC AI Runtime / Product Adapter |
| Source Mapping | Product Adapter（如 BBCTravel、GRP、TourCenter） |
| URL Mapping | Product Adapter / Search URL Builder |
| Keyword Composition（商品源搜尋策略） | Runtime / Product Adapter |
| Entity Validation 執行 | `AiuOutputContractValidator`（見 `BATS_AI_RUNTIME.md`） |

### 2.4 責任分離

| 層級 | 元件 | 職責 |
|------|------|------|
| Understanding | Gemini | Semantic Understanding + Entity Extraction + Clarification Detection |
| Validation | `AiuOutputContractValidator` | 值域／契約 Validation（含 §6.5 `product_type`）；執行順序見 `BATS_AI_RUNTIME.md` |
| Translation | **AIU Normalize** | Validated Gemini Output → Runtime Contract；**定義** §6.5 Closed Set 契約 |
| Execution | BBC AI Runtime | Routing / Dispatch / Coordination（含是否追問、Search Strategy） |
---

## 3. Out of Scope

本文件 **不**定義、**不**修改：

| 排除項 | 歸屬 |
|--------|------|
| Entity Core / Entity 清單 / Entity 邊界 | Entity Core Final（Frozen） |
| Entity Definition | Entity Definition v1.0 Rev.1（Frozen） |
| Gemini Output JSON 結構／型別 | Gemini Output Contract v1.0 Rev.1（Frozen） |
| Entity Validation **執行順序／Production owner／authoritative・shadow・resume／consumer boundary** | `BATS_AI_RUNTIME.md` |
| 日期解析政策（口語 → `YYYY-MM-DD`） | Gemini / Date Policy；Normalize **不**重算 |
| Product Search Mapping / Source Adapter | Adapter Layer（如 `SourceQueryMapper`） |
| Search Query / Keyword Composition | Runtime / Product Adapter |
| Policy filtering（含 `product_type_strict`） | `ProductSearchPolicyRuntime`（執行層；非 AIU Closed Set authority） |
| Execution Mapping / URL Builder | BBC AI Runtime / Hybrid Search |
| Prompt Scheme 文案（含 Gemini 抽取 guidance） | AIU Prompt Scheme；**不得**取代 Runtime Validation |
| Flex Carousel／Short URL／Published Product Set | LINE／Publication 路徑 |
| Coding / Legacy / Migration | 實作與遷移專案 |

> **本文件唯一擁有：** `product_type` 欄位 schema、Product Type Closed Set v1 值清單、null 輸出契約（§6.2／§6.5）。
---

## 4. Normalize Principles

| ID | 原則 |
|----|------|
| **NP-1** | Normalize 不得重新理解客戶語意。 |
| **NP-2** | Normalize 不得重新判斷 Intent。 |
| **NP-3** | Normalize 不得重新抽取或修改 Entity。 |
| **NP-4** | Normalize 不得因轉換而新增、刪除或改變 Gemini 已表達的語意。若 Runtime 可直接承接原始結構，應優先保留原始結構，不應為了方便而提前拆分、合併或指定主要值。 |
| **NP-5** | Normalize 不負責 Product Search Strategy。 |
| **NP-6** | Normalize 唯一責任是將 Gemini Output Contract 轉換為 BBC AI Runtime Contract。 |

### 4.1 Supporting Notes（非新增原則）

| 主題 | 說明 |
|------|------|
| Date | `date_range` 有值 → 映射為 Runtime 日期欄位；`null` → Runtime 日期為 `null`；不得依 `date_expression` 重算 |
| Empty value | 空值規則對齊 Output Contract：scalar → `null`；array → `[]`；不得用 `""` 代替 `null` |
| Clarification | `clarification` 原樣投影；Normalize **不**決定是否追問 |
| Execution fields | Gemini 不得輸出、Normalize 亦不得新增任何 Execution Decision 欄位（Routing／Dispatch 由 BBC AI Runtime 自行處理） |
| Frozen SSOT | 與 Frozen Entity / Definition / Output Contract 衝突時，以 Frozen SSOT 為準 |

---

## 5. Normalize Flow

```text
[1] Gemini Output Contract（Validated）
        ↓
[2] Intent Projection
        ↓
[3] Entity Field Mapping（31 keys → Runtime Entity；優先保留原始結構）
        ↓
[4] Date Field Mapping（date_range → date_from / date_to；不重算）
        ↓
[5] Constraint / Exclusion Projection（must_have / avoid；語意等價投影）
        ↓
[6] Runtime Defaults（補齊契約預設）
        ↓
[7] Clarification / Confidence Projection
        ↓
[8] Assemble BBC AI Runtime Contract
        ↓
BBC AI Runtime 可執行（Routing / Dispatch / Coordination 由 Runtime 自行處理）
```

### 5.1 Flow 邊界說明

| 步驟 | 屬於 Normalize？ | 說明 |
|------|------------------|------|
| [1]～[8] | ✅ 是 | Translation / Mapping / Default |
| Routing / Dispatch / Coordination | ❌ 否 | BBC AI Runtime 依 Runtime Contract 自行處理 |
| Product Search / Keyword Composition / Source Mapping / URL Mapping | ❌ 否 | BBC AI Runtime / Product Adapter |
| Knowledge / Human Execution | ❌ 否 | BBC AI Runtime |

### 5.2 輸入／輸出

| 方向 | 契約 |
|------|------|
| **In** | Gemini Output Contract v1.0 Rev.1（已 Validation） |
| **In（唯讀注入）** | `context_snapshot` / `owner_snapshot` / `conversation_stage` / `resume_context`（來自 Conversation Memory / State；非 Gemini） |
| **Out** | BBC AI Runtime Contract（§6） |

---

## 6. Runtime Contract

### 6.1 BBC AI Runtime Contract（Normalize 輸出）

Normalize 最終輸出對齊 AIU Runtime 對外契約形狀：

```text
BBC AI Runtime Contract {
  intent:             string
  entity:             object          // Runtime Entity Object（§6.2）
  context_snapshot:   array           // 唯讀注入；非 Gemini
  owner_snapshot:     string          // 唯讀注入；非 Gemini
  conversation_stage: string          // 唯讀注入；非 Gemini
  resume_context:     array | null    // 唯讀注入；非 Gemini
  clarification:      object          // { required: bool, reason: string }
  confidence:         number          // 0.0～1.0；供觀測參考
}
```

> **說明：** Normalize 僅輸出語意與 Translation 結果。  
> Routing／Dispatch／Execution Decision **不**由 Normalize 產出；由 **BBC AI Runtime** 依本 Contract 自行處理。  
> Normalize **不得**新增任何 Execution Decision 欄位。

### 6.2 Runtime Entity Object

`entity` 為 **Object**（固定鍵）。來源為 Gemini `entities`（31 鍵）經 Mapping 後之 Runtime-ready 形狀。

> **NP-4：** 若 Runtime 可直接承接原始結構，應優先保留原始結構。  
> **不得**將 `destination[]` 拆成 Primary + multi_destination。  
> **不得**於 Normalize 產生商品源 Keyword 組合策略欄位。

| Runtime Field | 型別 | 允許 null | 來源／規則 |
|---------------|------|-----------|------------|
| `destination` | array\<string\> | 否 | **直接保留** Gemini `entities.destination[]`；空則 `[]`；**不**指定 Primary |
| `travel_area` | string | 是 | 直接映射 |
| `date_from` | string | 是 | `entities.date_range.from`；`date_range=null` → `null` |
| `date_to` | string | 是 | `entities.date_range.to`；`date_range=null` → `null` |
| `date_expression` | string | 是 | 直接映射 |
| `duration_days` | number | 是 | 直接映射 |
| `date_flexibility` | string | 是 | 直接映射 |
| `product_type` | string \| null | 是 | **§6.5 Product Type Closed Set v1**；已 Validation 結果為 Closed Set 成員或 `null`；Normalize 僅 trim／empty→`null`，**不**恢復非法值 |
| `theme` | array\<string\> | 否 | 直接映射；預設 `[]` |
| `occasion` | string | 是 | 直接映射 |
| `travel_style` | string | 是 | 直接映射 |
| `departure` | string | 是 | 直接映射 |
| `route` | array\<string\> | 否 | 直接映射；預設 `[]` |
| `people_count` | number | 是 | 直接映射 |
| `adult_count` | number | 是 | 直接映射 |
| `child_count` | number | 是 | 直接映射 |
| `senior_count` | number | 是 | 直接映射 |
| `group_type` | string | 是 | 直接映射 |
| `budget_amount` | number | 是 | 直接映射 |
| `budget_unit` | string | 是 | 直接映射 |
| `currency` | string | 是 | 直接映射 |
| `price_sensitivity` | string | 是 | 直接映射 |
| `hotel_preference` | array\<string\> | 否 | 直接映射 |
| `transportation_preference` | array\<string\> | 否 | 直接映射 |
| `airline_preference` | array\<string\> | 否 | 直接映射 |
| `meal_preference` | array\<string\> | 否 | 直接映射 |
| `room_preference` | array\<string\> | 否 | 直接映射 |
| `constraint` | array\<string\> | 否 | 直接映射 |
| `exclusion` | array\<string\> | 否 | 直接映射 |
| `special_need` | array\<string\> | 否 | 直接映射 |
| `preserved_keywords` | array\<string\> | 否 | 直接映射 |
| `unclassified_terms` | array\<string\> | 否 | 直接映射 |
| `must_have` | array\<string\> | 否 | **語意等價投影**（§7.4）；來自 `constraint[]`；預設 `[]` |
| `avoid` | array\<string\> | 否 | **語意等價投影**（§7.4）；來自 `exclusion[]`；預設 `[]` |

### 6.3 Intent Runtime Values

| Gemini `intent` | Runtime `intent` |
|-----------------|------------------|
| `product_search` | `product_search` |
| `knowledge` | `knowledge` |
| `human_service` | `human_service` |
| `ambiguous` | `ambiguous` |

> Normalize **不**改寫 Intent 語意；僅做枚舉正規化（大小寫／別名對齊屬 Validation 後之字面正規化，不得改變類別）。

### 6.4 Clarification Runtime Object

```json
{
  "required": false,
  "reason": ""
}
```

| 規則 | 說明 |
|------|------|
| 直接投影 | 來自 Gemini `clarification` |
| 不升級為 Execution | Normalize **不**因此自動發送追問 |
| 追問決策 | BBC AI Runtime |

### 6.5 Product Type Closed Set v1（唯一清單）

> **Authority：** 本節為 Production／SSOT **唯一** `product_type` allowed-values 清單。  
> **禁止**於 `BATS_AI_RUNTIME.md`、Prompt、Adapter、Policy 或其他 SSOT **複製**本清單為第二份 AIU authority。  
> **執行驗證**由 `AiuOutputContractValidator` 執行（順序與 owner 見 `BATS_AI_RUNTIME.md`）；本節定義契約結果。

#### 6.5.1 合法值（Closed Set v1）

精確允許值（大小寫／空白敏感；必須完全相等）：

| # | 合法 `product_type` |
|---|---------------------|
| 1 | `自由行` |
| 2 | `半自助` |
| 3 | `跟團` |
| 4 | `團體` |
| 5 | `迷你團` |
| 6 | `包車` |
| 7 | `郵輪` |

#### 6.5.2 型別與 null 契約

| 輸入條件 | Semantic Contract 結果 |
|----------|------------------------|
| 欄位未提供 | `product_type = null` |
| 空字串 `""` | `product_type = null` |
| 僅 whitespace | `product_type = null` |
| 值 ∈ Closed Set v1 | 原值保留（合法 `string`） |
| 值 ∉ Closed Set v1（含未知／泛用商品詞） | `product_type = null` |

#### 6.5.3 非法／非成員明示（非 exhaustive 黑名單）

下列 **不是** Closed Set v1 成員；Validation 結果必須為 `null`（**範例**，契約以「不在 §6.5.1」為準）：

| 值 | 說明 |
|----|------|
| `行程` | 泛用商品詞；不得成為 `product_type` |
| `推薦` | 泛用商品詞；不得成為 `product_type` |
| `親子團` | **不是** product_type；屬 theme／keyword 語意範圍；**不得**映射為 `跟團`／`團體` |

> Validator／Normalize **不得**將非法 `product_type` 搬移至 `keyword` 或其他 entity。  
> Gemini 應在既有語意能力中將親子需求保留於 `keyword`／`theme` 等適當欄位；**本次不新增** theme entity、**不修改** P2 Entity Intelligence。  
> Prompt 改善屬後續 Coding 評估，**不得**取代 Runtime Validation。

#### 6.5.4 不變欄位與語意

| 規則 | 說明 |
|------|------|
| 其他 entity 不變 | `destination`、`date_from`／`date_to`、`keyword`（及除 `product_type` 外之合法 entity）**不得**因 `product_type` Validation 而改變 |
| `product_type = null` | **不**代表查無商品；**不**觸發 Clarification；**不**切 Legacy／Compatibility |
| 契約變更治理 | 未來新增合法 `product_type` **必須先**更新本節唯一 Contract，**再**修改 Production consumer；consumer **不得**自行擴充第二份 AIU allowed-values |

#### 6.5.5 Normalize 對 `product_type` 的輸入假設

Normalize 收到的 `product_type` **必須**已為：

- Closed Set v1 成員，或
- `null`

Normalize **只**得再做 shape／scalar／trim／empty→`null`；**不得**把 `null` 改回非法字串，**不得**重新驗證 Closed Set。

---

## 7. Normalize Rules

### 7.1 規則總表

| 類別 | Gemini 欄位 | Runtime 處理 |
|------|-------------|--------------|
| **直接 Mapping** | 多數 scalar / array Entity（含 `destination[]`） | 原樣投影（含 `null` / `[]`） |
| **Runtime Flatten** | `date_range.from/to` | → `date_from` / `date_to`（結構轉換；不改日期語意） |
| **語意等價投影** | `constraint` / `exclusion` | → `must_have[]` / `avoid[]`（內容等價；來源欄位仍保留） |
| **Default** | 缺鍵／非法空值（Validation 後理論上不應發生） | 依型別補 `null` 或 `[]` |
| **Closed Set（契約定義於 §6.5；執行於 Validation）** | `product_type` | 輸入應為 §6.5 成員或 `null`；Normalize **不**重做 closed-set 判定 |
| **Passthrough** | `clarification` / `confidence` | 投影至 Runtime Contract |
| **Injected（非 Gemini）** | — | `context_snapshot` / `owner_snapshot` / `conversation_stage` / `resume_context` |
| **禁止** | Destination Split | **不得** `destination[]` → Primary + `multi_destination` |
| **禁止** | Keyword Composition | **不得**替商品源決定 Keyword 組合 |
| **禁止** | Execution Decision | **不得**新增任何 Routing／Dispatch／Execution 欄位 |

### 7.2 直接 Mapping 清單

以下欄位 **直接 Mapping**（值不改寫；`product_type` **除外**，見 §6.5）：

`destination`, `travel_area`, `date_expression`, `duration_days`, `date_flexibility`, `theme`, `occasion`, `travel_style`, `departure`, `route`, `people_count`, `adult_count`, `child_count`, `senior_count`, `group_type`, `budget_amount`, `budget_unit`, `currency`, `price_sensitivity`, `hotel_preference`, `transportation_preference`, `airline_preference`, `meal_preference`, `room_preference`, `constraint`, `exclusion`, `special_need`, `preserved_keywords`, `unclassified_terms`

> **`product_type`：** 承接已 Validation 之 §6.5 結果（合法值或 `null`）；Normalize 僅 trim／empty→`null`，不恢復非法值、不搬移欄位。
### 7.3 Date Flatten（非重算）

| Gemini | Runtime | 規則 |
|--------|---------|------|
| `entities.date_range` = `{from,to}` | `date_from`, `date_to` | 直接取值；**不**重算 |
| `entities.date_range` = `null` | `date_from=null`, `date_to=null` | 保留 `date_expression`（若有） |

### 7.4 Destination 保留規則

| Gemini | Runtime | 規則 |
|--------|---------|------|
| `entities.destination` = `[...]` | `destination: [...]` | **原樣保留 array** |
| `entities.destination` = `[]` | `destination: []` | 空陣列 |

**禁止：**

- 指定 Primary Destination
- 拆分為 `destination` + `multi_destination`
- 為搜尋方便提前選取單一主要值

如 Runtime 或 Product Adapter 需要 Primary / Sequential Search，由後續 Runtime 自行處理。

### 7.5 Keyword Composition — 不屬於 Normalize

**Normalize 不負責 Product Search Strategy。**

**不得**替商品源決定 Keyword 組合方式。

各 Product Source（BBCTravel、GRP、TourCenter…）如何組合 Keyword，屬 **Runtime / Product Adapter**。

Normalize Runtime Contract **保留原始 Entity**，不產出搜尋策略用 `keyword` 組合欄位。

### 7.6 must_have / avoid 語意等價投影

| Runtime | 來源 | 規則 |
|---------|------|------|
| `must_have` | `constraint[]` | 直接複製；去重；來源 `constraint` 仍保留 |
| `avoid` | `exclusion[]` | 直接複製；去重；來源 `exclusion` 仍保留 |

不得把 `transportation_preference`（希望直飛）自動升級為 `must_have`。  
不得把 `exclusion`（不要轉機）改寫為搜尋 Keyword。

### 7.7 Runtime Default Value

| 型別 | Default |
|------|---------|
| string scalar 無值 | `null` |
| number scalar 無值 | `null` |
| array 無值 | `[]` |
| `clarification.required` 缺省 | `false` |
| `clarification.reason` 缺省 | `""` |
| `confidence` 缺省 | `0.0`（並 clamp 至 `[0.0, 1.0]`） |

---

## 8. Runtime Examples

> **Date disclaimer：** 範例中 `date_range` / `date_from` / `date_to` 僅示範 Mapping。  
> **不代表**固定日期解析政策。日期解析不在 Normalize 凍結。  
> **Destination：** Runtime 保留 `destination[]`；不指定 Primary。  
> **Keyword：** Normalize **不**組合商品源 Keyword；保留原始 Entity。

---

### Example 1 — 京都自由行 10 月

**Customer：** `想安排京都自由行，10月出發`

**Gemini Output（摘要）：**

```json
{
  "intent": "product_search",
  "entities": {
    "destination": ["京都"],
    "travel_area": null,
    "date_range": { "from": "2026-10-01", "to": "2026-10-31" },
    "date_expression": "10月",
    "product_type": "自由行",
    "theme": [],
    "exclusion": [],
    "preserved_keywords": []
  },
  "confidence": 0.92,
  "clarification": { "required": false, "reason": "" }
}
```

**Normalize → Runtime Entity（關鍵欄位）：**

```json
{
  "destination": ["京都"],
  "travel_area": null,
  "date_from": "2026-10-01",
  "date_to": "2026-10-31",
  "date_expression": "10月",
  "product_type": "自由行",
  "theme": [],
  "must_have": [],
  "avoid": []
}
```

**Runtime Contract（摘要）：**

```json
{
  "intent": "product_search",
  "entity": {
    "destination": ["京都"],
    "date_from": "2026-10-01",
    "date_to": "2026-10-31",
    "product_type": "自由行"
  },
  "clarification": { "required": false, "reason": "" },
  "confidence": 0.92
}
```

---

### Example 2 — 歐洲賞花

**Customer：** `請推薦歐洲賞花，10月`

**Gemini → entities 關鍵：** `travel_area=歐洲`, `theme=["賞花"]`, `date_range={from,to}`, `destination=[]`

**Normalize：**

```json
{
  "destination": [],
  "travel_area": "歐洲",
  "date_from": "2026-10-01",
  "date_to": "2026-10-31",
  "date_expression": "10月",
  "theme": ["賞花"],
  "must_have": [],
  "avoid": []
}
```

**Runtime：** `intent=product_search`；Routing／Dispatch 由 BBC AI Runtime 依 Contract 自行處理

---

### Example 3 — 峇里島蜜月

**Customer：** `10月去峇里島蜜月，想要悠閒一點`

**Normalize：**

```json
{
  "destination": ["峇里島"],
  "date_from": "2026-10-01",
  "date_to": "2026-10-31",
  "date_expression": "10月",
  "occasion": "蜜月",
  "travel_style": "悠閒",
  "group_type": "夫妻",
  "must_have": [],
  "avoid": []
}
```

---

### Example 4 — 北海道滑雪

**Customer：** `想去北海道滑雪，寒假出發`

**Gemini：** `date_range=null`, `date_expression="寒假"`, `theme=["滑雪"]`

**Normalize：**

```json
{
  "destination": ["北海道"],
  "date_from": null,
  "date_to": null,
  "date_expression": "寒假",
  "theme": ["滑雪"],
  "must_have": [],
  "avoid": []
}
```

> **NP-1 / Date note：** 不得把「寒假」重算成日期區間。

---

### Example 5 — 東京進大阪出

**Customer：** `想走東京進大阪出，7天行程`

**Normalize：**

```json
{
  "destination": ["東京", "大阪"],
  "duration_days": 7,
  "route": ["東京進", "大阪出"],
  "date_from": null,
  "date_to": null,
  "must_have": [],
  "avoid": []
}
```

> 保留完整 `destination[]`；**不**指定 Primary；**不**拆 `multi_destination`。

---

### Example 6 — 親子沖繩

**Customer：** `親子旅遊去沖繩，兩大一小，希望直飛`

**Normalize：**

```json
{
  "destination": ["沖繩"],
  "people_count": 3,
  "adult_count": 2,
  "child_count": 1,
  "group_type": "親子",
  "transportation_preference": ["直飛"],
  "must_have": [],
  "avoid": []
}
```

> 「希望直飛」留在 `transportation_preference`；**不**自動進 `must_have`；**不**組 Keyword。

---

### Example 7 — 高雄出發

**Customer：** `高雄出發，六月底去東京`

**Normalize：**

```json
{
  "destination": ["東京"],
  "departure": "高雄",
  "date_from": null,
  "date_to": null,
  "date_expression": "六月底",
  "must_have": [],
  "avoid": []
}
```

---

### Example 8 — 小資日本

**Customer：** `小資日本自由行，預算每人3萬`

**Normalize：**

```json
{
  "destination": ["日本"],
  "product_type": "自由行",
  "budget_amount": 30000,
  "budget_unit": "每人",
  "currency": "TWD",
  "price_sensitivity": "小資",
  "travel_style": null,
  "must_have": [],
  "avoid": []
}
```

> 「小資」在 `price_sensitivity`，**不**進入 `travel_style`（對齊 Entity Definition）。Keyword 組合由 Adapter 決定。

---

### Example 9 — 帝王蟹北海道（含 exclusion）

**Customer：** `想吃帝王蟹，北海道溫泉飯店，不要轉機`

**Normalize：**

```json
{
  "destination": ["北海道"],
  "theme": ["溫泉"],
  "hotel_preference": ["溫泉飯店"],
  "meal_preference": ["帝王蟹"],
  "transportation_preference": [],
  "exclusion": ["不要轉機"],
  "must_have": [],
  "avoid": ["不要轉機"]
}
```

> 「不要轉機」→ `exclusion` / `avoid`；**不**進入 `transportation_preference`；**不**組 Keyword。

---

### Example 10 — 有空再去日本（模糊日期）

**Customer：** `有空再去日本看看，還沒決定時間`

**Gemini：**

```json
{
  "intent": "product_search",
  "entities": {
    "destination": ["日本"],
    "date_range": null,
    "date_expression": "有空再去",
    "date_flexibility": "時間很彈性"
  },
  "confidence": 0.62,
  "clarification": {
    "required": true,
    "reason": "目的地已明確但出發時間模糊，無法形成可用日期區間"
  }
}
```

**Normalize：**

```json
{
  "destination": ["日本"],
  "date_from": null,
  "date_to": null,
  "date_expression": "有空再去",
  "date_flexibility": "時間很彈性",
  "must_have": [],
  "avoid": []
}
```

**Runtime Contract（摘要）：**

```json
{
  "intent": "product_search",
  "clarification": {
    "required": true,
    "reason": "目的地已明確但出發時間模糊，無法形成可用日期區間"
  },
  "confidence": 0.62
}
```

> Normalize **不**決定是否真的追問；僅投影 Detection。Routing／Dispatch 由 BBC AI Runtime 依 Contract 自行處理。

---

## 9. Freeze Readiness Review

### 9.1 是否符合 AIU 架構

| 檢查 | 結果 |
|------|------|
| Gemini = Understanding Core | ✅ Normalize 不取代 |
| AIU = Orchestrate / Normalize | ✅ 本文件定位 Translation Layer |
| BBC = Execute / Govern | ✅ 追問與搜尋執行不在 Normalize |
| Frozen Entity / Definition / Output Contract 未被修改 | ✅ |

### 9.2 是否符合 Responsibility Separation

| 檢查 | 結果 |
|------|------|
| 不重新理解／不重抽 Entity／不重判 Intent | ✅ NP-1～NP-3 |
| 優先保留原始結構；不提前拆分／指定主要值 | ✅ NP-4 |
| 不負責 Product Search Strategy / Keyword Composition | ✅ NP-5 / §2.3 / §7.5 |
| Clarification Detection vs Execution 劃界 | ✅ §2 / §6.4 |
| 不產出 Execution Decision；Routing 歸 BBC AI Runtime | ✅ §5.1 / §6.1 |

### 9.3 是否存在 Boundary 衝突

| 項目 | 狀態 | 是否阻擋 Freeze |
|------|------|-----------------|
| Gemini `entities{}` → Runtime `entity{}` 形狀轉換 | 已定義 Mapping；`destination[]` 原樣保留 | 否 |
| Keyword Composition 移出 Normalize | 已明確歸 Runtime / Product Adapter | 否 |
| Entity Validation **執行**未在本文件 | 刻意：執行／owner／順序歸 `BATS_AI_RUNTIME.md`；**Closed Set 契約**歸本文件 §6.5 | 否 |
| 日期解析政策 | 明確不在 Normalize | 否 |
| Primary Destination / Search Strategy | 明確不在 Normalize | 否 |

**無阻擋 Freeze 的 Boundary 衝突。**

### 9.4 Ready for Freeze？

### **Frozen — AIU Normalize v1.0 Rev.2（Product Type Closed-set Coordinated Contract v1.0）**

**理由：**

1. Rev.1 架構不變；Rev.2 僅凍結 `product_type` Closed Set v1 與 null 契約。
2. 官方 NP-1～NP-6 仍成立；Normalize 仍不重新理解／不執行 Closed Set 驗證。
3. `destination[]` 原樣保留；Keyword Composition / Search Strategy 仍劃出 Normalize。
4. Execution Decision 不由 Normalize 產出。
5. 與 `BATS_AI_RUNTIME.md` Rev.2 協調：`Validation → Normalize`；Closed Set 清單僅本文件一份。

---

## 10. Minimal Coding Gate（與 `BATS_AI_RUNTIME.md` 聯鎖）

下一階段 Minimal Coding **必須**同時滿足本文件 §6.5 與 `BATS_AI_RUNTIME.md` Coding Gate：

| 要求 | 說明 |
|------|------|
| Validator | 新增或完成 `AiuOutputContractValidator`；PHP 7.4 |
| 順序 | Gemini Semantic Result → **Validator** → **Normalizer** → Result／Translator（**不得**反轉） |
| Closed Set | Production 定義 **只有**本文件 §6.5 一份 |
| 非法 `product_type` | 輸出 `null`；不修改其他 entity；不搬移至 keyword |
| 禁止改動 | Flex／Short URL／Published Set；Host B API；在 `SourceQueryMapper`／`ProductSearchPolicyRuntime` 新增 AIU 黑名單 |
| Commit | 不 Amend 已 Push commit；修正後建立新的 Validation Fix Commit |

**必測（與 Runtime SSOT 相同清單）：** `行程`／`推薦`／unknown／empty／whitespace → `null`；七個合法值不變；`親子團` as `product_type` → `null` 且不搬移；destination／date／keyword 不變；Host B 不再因非法 `product_type` 產生 `keyword=行程`；非法值不觸發 `product_type_strict`；合法值維持既有 strict；Resume／Shadow／Knowledge／Human／Clarification regression；Flex 檔案零修改；不發真實外部請求。

---

## Revision History

| 版本 | 日期 | 說明 |
|------|------|------|
| v1.0 | 2026-07-10 | AIU v2 Normalize Architecture Definition（Freeze Candidate） |
| v1.0 Rev.1 | 2026-07-10 | Correction：官方 NP-1～NP-6；取消 destination split；Keyword Composition 移出；Examples 同步 |
| v1.0 Rev.1 Frozen | 2026-07-10 | Phase A Freeze：移除 Execution Decision 殘留；狀態標示 Frozen |
| v1.0 Rev.2 Frozen | 2026-07-18 | **Product Type Closed-set Coordinated Contract v1.0**：§6.5 Closed Set v1 唯一清單；null 契約；與 `BATS_AI_RUNTIME.md` Rev.2 聯鎖 Validation→Normalize |

---

**本階段：Coordinated Multi-SSOT Contract Freeze only。未 Coding。未 Commit。未修改 Production code／tests。**
