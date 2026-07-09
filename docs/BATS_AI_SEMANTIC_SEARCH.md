# BATS AI Semantic Search

**專案：** BBC AI SaaS / BATS / Hybrid Smart Search / Multi-Source Search  
**定位：** L3 功能 SSOT — **Phase 9-C-1 AI Semantic Search MVP**  
**版本：** v1.0 Adopt Draft  
**狀態：** Adopt Draft — R1/R2/R3 已定案；待實作  
**主機參考：** 103.1.222.14（主機 A · `C:\bbc-ai-bot`）

**上層文件：**

| 層級 | 文件 | 本文件角色 |
|------|------|------------|
| L0 | `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md` | 協作與文件治理 |
| L1 | `BATS_HYBRID_DATE_POLICY.md` | **日期語意 SSOT**（本文件僅引用，不重複定義） |
| L1 | `TENANT_SOURCE_REGISTRY_POLICY.md` | 租戶／商品源 Registry 治理 |
| L2 | `TENANT_SOURCE_INSTANCE_CONTRACT.md` | Source Instance 資料契約 |
| L3 | **本文件** | AI Semantic Search MVP 唯一 SSOT |
| L3 | `HYBRID_SMART_SEARCH_PHASE2A_CORE_LAYER.md` 等 | Hybrid 實作階段文件（**互補**） |

**衝突處理：** 日期語意以 `BATS_HYBRID_DATE_POLICY.md` 為準；租戶／商品源以 `TENANT_SOURCE_REGISTRY_POLICY.md` 為準；**語意解析輸出契約以本文件為準**。

### Adopted Decisions

| ID | 決策 |
|----|------|
| **R1** | 關西 canonical = **關西**（區域語意；**不等同** 大阪）；大阪／京都／神戶／奈良 城市展開列 Future |
| **R2** | Multi Destination **Sequential MVP**；`destination` = 第一順位；`multi_destination[]` = **其餘目的地**（不含第一順位）；MVP 先搜尋 `destination`；`multi_destination[]` 供 Gemini context／追問／Future parallel search |
| **R3** | MVP Alias Registry 路徑 = **`config/search/`**（`config/search/destination_alias_registry.php`）；**不使用** GCS 作為 MVP Alias Registry |

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §2 | Scope |
| §3 | BatsSearchIntent Contract |
| §4 | Travel Intent |
| §5 | Destination Alias |
| §6 | Multi Destination |
| §7 | Departure Semantic |
| §8 | Date Semantic Integration |
| §9 | Budget Parsing |
| §10 | Clarification Policy |
| §11 | Architecture |
| §12 | Future Extension |
| §13 | Cross-Reference Index |

---

## 1. Purpose

### 1.1 目標

**BATS AI Semantic Search（Phase 9-C-1）** 將客戶 **自然語言旅遊需求** 轉換為結構化 **`BatsSearchIntent`**，供下游管線消費：

```text
Customer Query（自然語言）
        ↓
AI Semantic Parser（Phase 9-C-1）
        ↓
BatsSearchIntent（本文件契約）
        ↓
Hybrid Smart Search（SearchCondition / HybridSearchConditionBuilder）
        ↓
Multi Source Search（MultiSourceSearchUrlBuilder + Registry）
        ↓
Gemini Context Builder（商品 context 注入）
```

### 1.2 解決問題

| 現況缺口 | 9-C-1 目標 |
|----------|------------|
| `TourQueryIntentDetector` 僅產單一 `keyword` | 結構化語意欄位 |
| Rule parsers 分散（Date / Area / Budget） | 統一 **Semantic Intent** 輸出契約 |
| 複合目的地、別名、追問決策未 SSOT 化 | 本文件定案 |
| Multi-Source 僅消費 `keyword` | Intent → Registry-driven URL 參數 |

### 1.3 非目標

本 Phase **不** 負責商品排序、訂位、報名、Bonusmee Booking Assistant（見 §2、§12）。

---

## 2. Scope

### 2.1 In Scope（Phase 9-C-1 MVP）

| 能力 | 說明 |
|------|------|
| **Destination Extraction** | 從自然語言抽取 canonical 目的地 |
| **Semantic Parsing** | 整體語意結構化（含 free_text 保留） |
| **Travel Intent Detection** | 親子、蜜月、跟團等旅遊意圖標籤 |
| **Date Semantic Parsing** | 委派 `BATS_HYBRID_DATE_POLICY.md`；本層只整合 |
| **Budget Parsing** | 預算上下限（TWD） |
| **Departure Semantic Parsing** | 出發地語意（非必要欄位） |
| **Clarification Decision** | 是否必須追問（對齊 Date Semantic Required Rule） |

### 2.2 Out of Scope（Phase 9-C-1 MVP）

| 排除項 | 歸屬 |
|--------|------|
| **Ranking** | Future — Ranking Layer |
| **Booking / Registration** | 業務系統；非 BATS 搜尋 |
| **Bonusmee Booking Assistant** | Future — 獨立產品線 |
| **商品源爬蟲 / 即時庫存** | Host B / 各 Platform Adapter |
| **短網址產生** | `PRODUCT_SOURCE_SHORTURL_POLICY.md` |
| **租戶 Registry 寫入** | `TENANT_SOURCE_REGISTRY_POLICY.md` |

### 2.3 與既有 Phase 關係

| 既有 Phase | 關係 |
|------------|------|
| Hybrid Smart Search 2-A / 2-B | `SearchCondition` DTO；9-C-1 輸出 **映射至** SearchCondition |
| Hybrid Smart Search 2-C.1 | `TravelIntentLexicon`；9-C-1 **可重用或擴充** lexicon，契約以本文件為準 |
| Multi-Source 9-B-14 | 消費 canonical keyword / 平台參數；**不** 重複定義 URL 模板 |
| Tenant Mapping | `sno` 由 Gateway / Tenant Resolver 提供；**不** 在 Intent 內解析 |

### 2.4 P1 Entity Token Mapping Principle（Adapter Layer）

**Runtime Contract** 必須保持 Entity 獨立（`destination`、`area`、`product_type`、`keyword`、`travel_style`、`must_have` 等各自為獨立 Token）。

**Adapter 責任**（`SourceQueryMapper`）：

| 層級 | 行為 |
|------|------|
| Runtime | 保存完整 Entity，不得 merge / overwrite |
| `source_keyword_query` | 由獨立 Token 以空格組合（例：`首爾` + `自由行` → `首爾 自由行`） |
| Host B API `keyword` | 當 `destination` 已獨立送出時，`keyword` 僅含 preference / `product_type`（例：`destination=首爾` + `keyword=自由行`） |
| Keyword-only 平台 URL | 使用完整 `source_keyword_query`（例：bonusmee `keyword=首爾 自由行`） |

**禁止**：將多個不同語意 Entity 合併為單一 Runtime `keyword`（如 `首爾自由行`）。

---

## 3. BatsSearchIntent Contract

### 3.1 正式 DTO

**`BatsSearchIntent`** 為 Phase 9-C-1 **唯一正式輸出契約**。

| 欄位 | 類型 | 必填 | 說明 |
|------|------|------|------|
| `destination` | string \| null | — | Canonical 主目的地（搜尋用核心地名） |
| `destination_alias` | string[] | — | 解析過程命中的別名／原始片語（稽核用） |
| `multi_destination` | string[] | — | **其餘**目的地（§6）；**不含** `destination` 第一順位；空陣列表示單目的地 |
| `departure_city` | string \| null | — | 出發地 canonical（§7）；`null` = 未指定 |
| `date_from` | string \| null | — | ISO `Y-m-d`；**語意規則見 L1 Hybrid Date Policy** |
| `date_to` | string \| null | — | ISO `Y-m-d` |
| `travel_type` | string[] | — | 旅遊意圖標籤（§4）；如 `親子`、`蜜月` |
| `budget_min` | int \| null | — | 預算下限（TWD） |
| `budget_max` | int \| null | — | 預算上限（TWD） |
| `people_count` | int \| null | — | 人數語意（若可解析） |
| `landmark` | string \| null | — | 地標／ POI（如「東京迪士尼」）；可觸發別名解析 |
| `must_have` | string[] | — | 必須包含語意（如「含機票」「直飛」） |
| `avoid` | string[] | — | 排除語意（如「不要紅眼」「不要轉機」） |
| `clarification_required` | boolean | ✅ | `true` = **不得** 進入搜尋／URL 產生 |
| `clarification_reason` | string \| null | — | 機器可讀原因碼（§10） |
| `confidence` | float | ✅ | `0.0`–`1.0`；語意解析信心 |
| `free_text` | string | ✅ | 原始客戶輸入（保留） |
| `intent` | string | ✅ | 固定 `tour_search`（MVP）；非旅遊另開 intent |

### 3.2 對 `SearchCondition` 映射（Hybrid Layer）

9-C-1 Runtime **必須** 提供明確映射至 `core/search/SearchCondition.php`（Phase 2-A）：

| BatsSearchIntent | SearchCondition | 備註 |
|------------------|-----------------|------|
| `destination` | `destination` / `keyword` | 經 Canonicalizer 統一；**僅**第一順位進入搜尋（§6 Sequential MVP） |
| `multi_destination[]` | 不映射至 `keyword`（MVP） | 供 Gemini context／追問；**不** 進入 MVP 搜尋 URL |
| `departure_city` | `departure_city` | |
| `date_from` / `date_to` | 同左 | 須已通過 Date Policy |
| `budget_min` / `budget_max` | 同左 | MVP 可 meta-only |
| `travel_type` | `travel_style[]` / `special_tags[]` | |
| `clarification_required` | 阻斷下游 Builder | 不產 URL / 不呼叫 Host B |

**規則：** `SearchCondition` 為 Hybrid **執行層** DTO；`BatsSearchIntent` 為 **語意層** SSOT。禁止在 Multi-Source Builder 直接消費未映射的 Intent 欄位。

### 3.3 JSON 範例

```json
{
  "destination": "東京",
  "destination_alias": ["東京迪士尼"],
  "multi_destination": [],
  "departure_city": "台北",
  "date_from": "2026-06-20",
  "date_to": "2026-06-30",
  "travel_type": ["親子"],
  "budget_min": null,
  "budget_max": 50000,
  "people_count": 4,
  "landmark": "東京迪士尼",
  "must_have": [],
  "avoid": [],
  "clarification_required": false,
  "clarification_reason": null,
  "confidence": 0.92,
  "free_text": "台北出發六月底東京親子五萬內",
  "intent": "tour_search"
}
```

---

## 4. Travel Intent

### 4.1 MVP 支援清單（第一版）

下列標籤寫入 `travel_type[]`（可多選）：

| 標籤 | 語意 | 典型觸發詞（非 exhaustive） |
|------|------|------------------------------|
| `親子` | 親子旅遊 | 親子、帶小孩、家庭 |
| `蜜月` | 蜜月 | 蜜月、新婚 |
| `長輩` | 長輩／銀髮 | 長輩、爸媽、銀髮、慢活 |
| `自由行` | 自由行 | 自由行、FP |
| `跟團` | 跟團 | 跟團、團體、參團 |
| `賞楓` | 賞楓 | 賞楓、楓葉、紅葉 |
| `賞花` | 賞花 | 賞櫻、賞花、櫻花 |
| `滑雪` | 滑雪 | 滑雪、雪場 |
| `海島` | 海島 | 海島、渡假島、馬爾地夫 |
| `高CP值` | 預算導向 | 高CP、划算、便宜 |
| `第一次出國` | 新手 | 第一次出國、沒出過國 |

### 4.2 解析規則

| 規則 | 說明 |
|------|------|
| **多標籤允許** | 「親子賞楓」→ `["親子","賞楓"]` |
| **不取代 destination** | 意圖標籤 **不得** 單獨成為 `destination` |
| **衝突優先** | `自由行` 與 `跟團` 同時命中 → 保留兩者 + 降低 `confidence` 或觸發 clarification（實作可配置） |
| **與 KeywordNormalizer 對齊** | Phase 2-A `travel_style` / `special_tags` 映射表應與本清單一致 |

---

## 5. Destination Alias

### 5.1 目的

將 **地標、區域別名、口語簡稱** 解析為 **Canonical `destination`**，供 Hybrid / Multi-Source 使用 **單一搜尋核心詞**。

### 5.2 Alias Registry 機制（設計）

| 項目 | 規格 |
|------|------|
| **Registry 位置（MVP）** | `config/search/destination_alias_registry.php`（**唯一** MVP 路徑；見 Adopted Decisions R3） |
| **記錄形狀** | `{ "alias": "東京迪士尼", "canonical": "東京", "scope": "landmark", "country": "日本" }` |
| **解析順序** | 最長片語優先 → canonical → 寫入 `destination`；命中別名寫入 `destination_alias[]` |
| **landmark 欄位** | 若 alias 類型為 landmark，同時填 `landmark` |
| **禁止 hardcode** | 核心 Parser **不得** 內嵌別名表；須 Registry driven（對齊 `TENANT_SOURCE_REGISTRY_POLICY.md` §Config Driven） |

### 5.3 MVP 範例（正式設計樣本）

| 客戶輸入片語 | `landmark` | `destination`（canonical） | 備註 |
|--------------|------------|----------------------------|------|
| 東京迪士尼 | 東京迪士尼 | 東京 | 地標 → 城市 |
| 關西 | — | **關西** | 區域語意（MVP）；`destination_alias=["關西"]` |
| 黑部立山 | — | 日本 | 景點 → 國家級 fallback |
| 立山黑部 | — | 日本 | 同義別名 → 同一 canonical |

**關西 MVP 策略（R1）：** Phase 9-C-1 canonical = **`關西`**。關西是**區域語意**，**不等同** 大阪。**不得** 將「關西 → 大阪」作為 MVP canonical。大阪／京都／神戶／奈良 城市展開列 **Future**（§12）。

---

## 6. Multi Destination

### 6.1 定義

**Multi Destination** 指客戶在 **單句** 中表達 **兩個及以上** 獨立目的地／區域。

| 欄位 | 語意（R2） |
|------|------------|
| `destination` | **第一順位**目的地；MVP **唯一**進入搜尋的核心地名 |
| `multi_destination[]` | **其餘**目的地；**不得** 包含第一順位；供 Gemini context／追問／Future parallel search |

### 6.2 解析規則（MVP）

**MVP 搜尋策略 = Sequential：** 先搜尋 `destination`（第一順位）；`multi_destination[]` **不** 進入 MVP 搜尋 URL。

| 輸入範例 | `destination`（第一順位） | `multi_destination`（其餘） | MVP 搜尋策略 |
|----------|---------------------------|----------------------------|--------------|
| 北海道東京 | `北海道` | `["東京"]` | Sequential：先搜尋 `北海道` |
| 東京大阪 | `東京` | `["大阪"]` | Sequential：先搜尋 `東京` |
| 九州關西 | `九州` | `["關西"]` | Sequential：先搜尋 `九州` |
| 東京（單一） | `東京` | `[]` | 標準單目的地 |

### 6.3 分隔符號（Rule 優先）

| 模式 | 範例 |
|------|------|
| 並列（無連詞） | `北海道東京` → 最長匹配切分 |
| 頓號／逗號 | `東京、大阪` |
| 斜線 | `東京/大阪` |
| 連詞 | `東京和大阪`、`東京及大阪` |

### 6.4 Clarification 觸發

| 條件 | 行為 |
|------|------|
| `multi_destination` 非空 **且** 無 `date_from/date_to` | `clarification_required=true`（日期優先，對齊 §10） |
| `multi_destination` 非空 **且** 日期完整 | 允許搜尋；UI 可提示「將先搜尋 {destination}」 |

**Future：** Multi-destination parallel search、行程組合 Ranking（§12）。

---

## 7. Departure Semantic

### 7.1 原則

**出發地不是必要條件**（對齊 `BATS_HYBRID_DATE_POLICY.md` 章節五）。

| `departure_city` | 語意 |
|----------------|------|
| `null` | 未指定出發地；**不得** 假設台北 |
| 非 null | 已解析之 canonical 出發城市 |

### 7.2 MVP 解析表

| 客戶輸入 | `departure_city` | 備註 |
|----------|------------------|------|
| 台北出發 | `台北` | |
| 高雄出發 | `高雄` | |
| 台中出發 | `台中` | |
| 南部出發 | `高雄` | 區域 → 預設代表城市（可配置） |
| 北部出發 | `台北` | 區域 → 預設代表城市 |
| 不限出發地 / 哪裡出發都可以 | `null` | 明確不篩出發地 |
| （未提及） | `null` | |

### 7.3 Registry 整合

出發地 **平台編碼**（如 GRP `departureCity` code）由 **Platform Adapter / Golden Reference Rule** 消費，**不在** `BatsSearchIntent` 內定義平台 code。

交叉引用：`GRP_GOLDEN_REFERENCE_PLATFORM_RULE.md`、`TOURCENTER_GOLDEN_REFERENCE_PLATFORM_RULE.md`。

---

## 8. Date Semantic Integration

### 8.1 SSOT 引用（禁止重複定義）

**所有日期語意、解析結果、`date_from` / `date_to` 計算規則，以 `BATS_HYBRID_DATE_POLICY.md` 為唯一 SSOT。**

本文件 **不得** 複製或改寫下列內容：

- 章節三：日期解析正式規則表
- 章節四：日期必要原則
- **Date Semantic Required Rule**
- 日期語意對照表（近期、暑假、月底…）

### 8.2 整合方式（Phase 9-C-1）

```text
Customer Query
    ↓
AI Semantic Parser
    ├→ (L1) DateParser / Hybrid Date Policy 委派
    │       → date_from, date_to
    └→ BatsSearchIntent
            ↓
    clarification_required ← Date Policy 判定（無日期語意）
```

| 步驟 | 元件 | 職責 |
|------|------|------|
| 1 | `DateParser`（既有 Rule）或 AI patch | 產出 `date_from` / `date_to` |
| 2 | Hybrid Date Policy 驗證 | 無日期語意 → 強制 `clarification_required` |
| 3 | `BatsSearchIntent` 組裝 | 寫入日期欄位 + reason code |
| 4 | `HybridSearchConditionBuilder` | 消費已驗證日期；**不再** 自行解日期 |

### 8.3 參考日期

Parser 必須注入 `reference_date`（通常 `today` 租戶時區），與 Hybrid Date Policy Y0/Y1 規則一致。

---

## 9. Budget Parsing

### 9.1 欄位語意

| 欄位 | 單位 | 說明 |
|------|------|------|
| `budget_min` | TWD | 下限；「X 以上」 |
| `budget_max` | TWD | 上限；「X 以內／以下」 |

**MVP 備註：** 與 Phase 2-B 一致，budget 可 **meta-only**（不送 Host B query），供 log / Gemini context / 未來 Ranking。

### 9.2 MVP 解析表

| 客戶輸入 | `budget_min` | `budget_max` | 備註 |
|----------|--------------|--------------|------|
| 五萬內 | null | 50000 | |
| 三萬以下 | null | 30000 | |
| 五至七萬 | 50000 | 70000 | |
| 7萬以上 | 70000 | null | |
| 預算不限 / 沒有預算限制 | null | null | |
| 三萬左右 | 25000 | 35000 | 可配置 ±比例 |

### 9.3 中文數字

須支援 **中文數字 + 萬**（對齊 `ChineseNumberHelper` / `BudgetParser`）：如「三萬」「5萬」。

### 9.4 與 travel_type 交叉

「高CP值」可同時設 `travel_type=["高CP值"]` 與 budget 欄位；**不** 互相覆蓋。

---

## 10. Clarification Policy

### 10.1 目的

決定 **何時必須追問** vs **何時可直接進入 Hybrid Smart Search / Multi Source Search**。

**必須** 與 `BATS_HYBRID_DATE_POLICY.md` **Date Semantic Required Rule** 一致。

### 10.2 必須追問（`clarification_required = true`）

| # | 條件 | `clarification_reason` | 追問範例 |
|---|------|------------------------|----------|
| C1 | 有 destination **但** 無任何日期語意 | `date_required` | 請問您大約想什麼時候出發呢？ |
| C2 | 僅天數無日期（如「東京五日」） | `date_required_duration_only` | 請問您預計哪一段時間出發？ |
| C3 | destination 無法解析（confidence < 閾值） | `destination_unknown` | 請問您想去哪個國家或城市呢？ |
| C4 | 意圖互斥且無法消歧（可選） | `intent_ambiguous` | 請問您偏好自由行還是跟團呢？ |

**C1 正式案例（與 L1 一致）：** 東京、東京行程、大阪、北海道 → **不得** 產生 Search URL。

### 10.3 可直接搜尋（`clarification_required = false`）

| # | 條件 |
|---|------|
| S1 | `destination` 已解析 **且** `date_from` / `date_to` 已依 Date Policy 填寫 |
| S2 | 含日期語意：近期、六月底、暑假、2026/7/15 等（見 L1 對照表） |
| S3 | `departure_city` 缺失 **仍允許** 搜尋（出發地非必要） |
| S4 | `budget_*` 缺失 **仍允許** 搜尋 |

### 10.4 下游阻斷規則

當 `clarification_required = true`：

1. **不得** 呼叫 `HybridSearchConditionBuilder` 產 URL  
2. **不得** 呼叫 `MultiSourceSearchUrlBuilder`  
3. **不得** 呼叫 Host B `tour.search`  
4. **應** 回覆澄清話術（LINE / Gemini 由 Presentation Layer 決定）

### 10.5 與 AI Runtime 分工

| 層級 | 職責 |
|------|------|
| Rule / Lexicon | 高信心結構化（日期、預算、出發地） |
| AI Semantic Parser | 補足複雜句、別名、multi_destination |
| Clarification Policy | **確定性** 閘門；AI 不得繞過 C1/C2 |

---

## 11. Architecture

### 11.1 端到端資料流

```text
┌─────────────────┐
│ Customer Query  │  LINE / Web / 未來通道
└────────┬────────┘
         ↓
┌─────────────────────────┐
│ AI Semantic Parser      │  Phase 9-C-1
│ (Rule + Lexicon + AI)   │
└────────┬────────────────┘
         ↓
┌─────────────────────────┐
│ BatsSearchIntent        │  ← 本文件 SSOT
└────────┬────────────────┘
         ↓ clarification_required?
         ├─ true  → Clarification Reply（不搜尋）
         └─ false ↓
┌─────────────────────────┐
│ Intent → SearchCondition│  Hybrid Layer 映射
│ HybridSearchCondition   │
│ Builder / Canonicalizer │
└────────┬────────────────┘
         ↓
┌─────────────────────────┐
│ Hybrid Smart Search     │  Host B API / search_url
│ (Phase 2-B+)            │
└────────┬────────────────┘
         ↓
┌─────────────────────────┐
│ Multi Source Search     │  ProductSourceRegistry
│ Url Builder             │  + TenantSourceInstance
└────────┬────────────────┘
         ↓
┌─────────────────────────┐
│ Gemini Context Builder  │  商品列 + 搜尋條件摘要
└─────────────────────────┘
```

### 11.2 租戶／Registry 邊界

```text
TenantResolver(sno)
    → enabled source instances（TENANT_SOURCE_INSTANCE_CONTRACT）
    → MultiSourceSearchUrlBuilder.buildAllForTenant()
```

**規則：** Semantic Parser **不** 解析 `sno` / `platform_id`；Tenant Mapping 發生於 Gateway 入口（`API_GATEWAY_TENANT_MAPPING_DESIGN.md`）。

### 11.3 Parser 分層（對齊 Phase 2-A）

| Level | 元件 | Phase |
|-------|------|-------|
| L1 | Rule parsers（Date / Budget / Departure regex） | 2-A / 9-C-1 |
| L1 | `TravelIntentLexicon` | 2-C.1 / 9-C-1 |
| L2 | Destination Alias Registry（`config/search/destination_alias_registry.php`） | 9-C-1 |
| L3 | AI Semantic patch（僅填缺口） | 9-C-1 MVP 可選 |
| — | `GeminiIntentParser` 全量 | Future |

**原則：** Rule First，AI Second；**禁止** AI 覆寫已通過 Date Policy 的日期欄位。

### 11.4 下游回覆政策邊界（Semantic Driven — Global）

本文件產出之 `BatsSearchIntent` 為 **Consultant 回覆語意** 的輸入之一，**不** 定義回覆模板或措辭。

**Semantic Driven Response Policy（AD-007）為 Global 政策** — 亦適用 Clarification、FAQ、Knowledge、Support 等非搜尋場景；見 `BATS_AI_TRAVEL_CONSULTANT_POLICY.md` §8.6.1。

```text
BatsSearchIntent（本文件 SSOT — 商品搜尋場景）
    ↓
SearchCondition → Hybrid Search → search_results
    ↓
GeminiContextDocument v2（含 bats_search_intent）
    ↓
AI Travel Consultant — Semantic Driven Response Generation（Global）
    ↓
GeminiResponseContract → LINE Reply
```

| 層級 | 本文件 | Consultant Policy |
|------|--------|-------------------|
| **解析** | destination、date、budget、clarification | — |
| **語意** | `clarification_required`、是否可搜尋 | Case A/B/C、推薦 vs 追問 |
| **措辭** | **不** 定義 | §8.6 Global；**固定 Persona、動態 wording** |

**MVP 過渡：** Phase 9-C-2A.1 Opening/Closing Pool 為 Product 路徑 Runtime 過渡；**非** 本文件範疇。見 Consultant Policy §8.7。

---

## 12. Future Extension

下列項目 **明確列為 Future**；不得納入 Phase 9-C-1 MVP 範圍：

| 項目 | 說明 |
|------|------|
| **Ranking Layer** | 多源結果排序、個人化 |
| **AI Recommendation** | 主動推薦行程組合 |
| **Bonusmee Booking Assistant** | 訂位／報名對話 |
| **Multi-destination parallel search** | 多目的地同時搜尋（消費 `multi_destination[]`） |
| **關西城市展開** | 大阪／京都／神戶／奈良 細分映射 |
| **GCS shared alias registry** | `shared/search/destination_aliases.json` 集中別名表 |
| **RAG / 知識庫語意** | BDS tenant knowledge 注入 |
| **Tenant-specific alias override** | 租戶級別名表（非 MVP） |
| **Full GeminiIntentParser** | L3 全量 LLM JSON（Phase 2-C 規劃） |
| **Semantic Driven Response Generation** | Consultant §8.6 Global；Runtime 見 `BATS_AI_RUNTIME_DESIGN.md` §12 |

Future 項目應記錄於 Roadmap 或 `TECH_DEBT.md`，**不得** 阻塞 Hybrid Smart Search 主線（對齊 `DOCUMENTATION_GOVERNANCE_POLICY.md` §14.9）。

---

## 13. Cross-Reference Index

| 主題 | 正式 SSOT |
|------|-----------|
| 日期解析 / Date Clarification | `BATS_HYBRID_DATE_POLICY.md` |
| Tenant / Product Source Registry | `TENANT_SOURCE_REGISTRY_POLICY.md` |
| Source Instance 契約 | `TENANT_SOURCE_INSTANCE_CONTRACT.md` |
| Multi-Source URL Builder | `MULTI_SOURCE_SEARCH_URL_BUILDER_PHASE9B14.md` |
| Source Instance URL Template | `SOURCE_INSTANCE_URL_TEMPLATE_BUILDER.md` |
| Hybrid SearchCondition DTO | `HYBRID_SMART_SEARCH_PHASE2A_CORE_LAYER.md` |
| Hybrid Builder / Mapper | `HYBRID_SMART_SEARCH_PHASE2B_BUILDER_AND_MAPPER.md` |
| Intent Detection 強化 | `HYBRID_SMART_SEARCH_PHASE2C1_INTENT_DETECTION_ENHANCEMENT.md` |
| Tenant Mapping 安全 | `API_GATEWAY_TENANT_MAPPING_DESIGN.md` |
| 短網址 | `PRODUCT_SOURCE_SHORTURL_POLICY.md` |
| 文件成本控制 | `DOCUMENTATION_GOVERNANCE_POLICY.md` §14 |
| Travel Consultant / 回覆語意 | `BATS_AI_TRAVEL_CONSULTANT_POLICY.md` §8.6（AD-007） |
| Runtime 遷移 | `BATS_AI_RUNTIME_DESIGN.md` §12 |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | **v1.0 Adopt Draft** — Phase 9-C-1 SSOT |
| **Adopted Decisions** | R1 關西 canonical；R2 Multi Destination Sequential；R3 `config/search/` Alias Registry |
| **程式實作** | **未開始** — 文件已定案，待 Runtime 實作 |
| **L1 對齊** | 日期 → `BATS_HYBRID_DATE_POLICY.md`；Registry → `TENANT_SOURCE_REGISTRY_POLICY.md` |
| **SAFE TO IMPLEMENT** | **是** — R1/R2/R3 已 Adopt；實作須依本文件契約 |

---

## Revision History

| 版本 | 日期 | 狀態 | 說明 |
|------|------|------|------|
| **1.2** | 2026-06-14 | Adopt Draft | §11.4 Global Semantic Driven 邊界、術語修正 |
| **1.1** | 2026-06-14 | Adopt Draft | §11.4 下游 Semantic Driven Response 邊界 |
| **1.0** | 2026-06-15 | Adopt Draft | Phase 9-C-1 初版 + Adopt R1/R2/R3 |
| **1.0** | 2026-06-14 | Draft | Phase 9-C-1 初版：BatsSearchIntent 契約、Clarification、Architecture |

---

*本文件為 Phase 9-C-1 AI Semantic Search MVP 唯一 SSOT。變更須修訂本文件後再改程式。*
