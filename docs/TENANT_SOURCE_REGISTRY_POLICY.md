# TENANT_SOURCE_REGISTRY_POLICY.md

**專案：** BBC AI SaaS / BATS / ETT / API Gateway / Travel Data Center  
**定位：** L1 架構政策層 — Tenant Registry 與 Product Source Registry 正式 SSOT  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**適用範圍：** `travel_a`、`travel_b`、`travel_c` 及未來 **20～200 家旅行社**  
**適用對象：** ChatGPT、Cursor、開發者、維運人員  
**衝突處理：** 若與下層功能文件衝突，**以本文件為準**；若與上層 L0 政策衝突，**以上層為準**。

---

## 文件目的

本文件定義 BBC AI SaaS / BATS 之 **Tenant Registry**、**Product Source Registry**、**Source Platform**、**Source Instance** 的正式規範。

目的包括：

- 統一多租戶（multi-tenant）與多商品源（multi-source）的治理方式
- 避免 tenant / platform 規則散落於程式各處
- 為 20～200 家旅行社擴展提供 **Config Driven、Registry Driven、Adapter Driven** 之唯一正式依據
- 與 Hybrid Search、Multi-Source URL、Publisher 整合之邊界對齊

---

## 核心原則

### 必須遵守

| 原則 | 說明 |
|------|------|
| **Config Driven** | Tenant、source、template、mapping 以設定／registry 檔為權威，非程式常數 |
| **Registry Driven** | Registry 為唯一正式來源；新增旅行社以 registry 完成，不修改核心搜尋管線 |
| **Adapter Driven** | 各平台差異封裝於 Adapter / Platform Layer，核心流程只消費契約 |

### 禁止事項

| 禁止 | 原因 |
|------|------|
| **平台名稱硬寫於核心流程** | 破壞多平台擴展；應走 `platform_id` + registry |
| **Tenant 特例散落各處** | formatter、router、builder 內 `if (travel_b)` 不可維護 |
| **duplicated mapping rules** | 同一映射（如 departure_city → code）不得多處定義 |
| **核心搜尋流程為單一 tenant 改寫** | 新增 tenant 應只增 registry + adapter，不 fork 核心 |

---

## Tenant Registry 定義

### Tenant 概念

**Tenant** 代表一家旅行社（或獨立營運單位）在系統中的邏輯隔離邊界。

**範例：**

| tenant_key（內部） | 說明 |
|--------------------|------|
| `travel_a` | 驗收基線 tenant |
| `travel_b` | Pilot / 多商品源 tenant |
| `travel_c` | 擴展驗證 tenant |
| （未來 20～200 家） | 每家一筆 registry 記錄 |

### 正式識別欄位

| 欄位 | 說明 |
|------|------|
| **`sno`** | 對外／對內權威租戶識別（如 LINE、Host B、搜尋 API） |
| **`tenant_code`** | 內部簡碼（如 `travel_a`、`travel_b`）；Host A 權威，**不得**由 LINE client 傳入 |
| **`tenant_name`** | 顯示名稱（營運／文件用） |

### Tenant Registry 職責

- 解析 `sno` → tenant context（credential、feature flag、storeNo 等）
- 定義該 tenant **啟用哪些 source instance**
- 與 `config/tenant_registry.php`（及未來 GCS `tenants/{sno}/`）對齊

**規則：** 程式透過 **Tenant Resolver** 消費 registry，不得 hardcode `sno` 於業務邏輯（Pilot gate 除外且須文件化）。

---

## Source Platform 定義

### Platform 概念

**Source Platform** 為商品來源之 **平台層**：同一套平台規則可被多個 tenant 的 instance 共用。

### 範例平台

| platform_id | 平台網域（概念） | 備註 |
|-------------|------------------|------|
| `agenttour` | `agenttour.com.tw` | RegionCode 等平台級規則 |
| `grp` | `grp.com.tw` | subdomain 識別 |
| `bbctravel` | `bbctravel.com.tw` | searchlist + departure path |
| `tourcenter` | `dayitourcenter.com.tw` 等 | 入口型平台 |
| `trip`（未來） | `Trip.com` | 外部平台 adapter |

### 平台層特性

- **平台規則共用**：keyword → region、URL template 結構、query 契約
- **不含 tenant 子網域**：子網域／品牌識別屬於 **Source Instance**
- **透過 `platform_id` 引用**，禁止在核心流程寫死網域字串

---

## Source Instance 定義

### Instance 概念

**Source Instance** 為 **Tenant × Platform** 的具體實例：同一平台下，不同旅行社有不同子網域、識別值或 template 參數。

### 範例

**travel_b（sno：`5f99b8d665e8444d`）：**

| tenant_instance_key | 實例 URL（概念） |
|---------------------|------------------|
| `dayitravel_grp` | `dayitravel.grp.com.tw` |
| `dayitravel_bbctravel` | `dayitravel.bbctravel.com.tw` |
| `dayitravel_tourcenter` | `dayitourcenter.com.tw` |

**travel_c（範例）：**

| tenant_instance_key | 實例 URL（概念） |
|---------------------|------------------|
| `abc_grp` | `abc.grp.com.tw` |
| `abc_bbctravel` | `abc.bbctravel.com.tw` |

### 規則

| 規則 | 說明 |
|------|------|
| **平台相同** | 共用 `platform_id`、adapter、平台級 mapping |
| **Tenant 不同** | `tenant_sno`、`identifier_values`（如 subdomain）不同 |
| **一 instance 一 template** | 透過 `url_template_id` 指向 registry 模板 |

---

## Registry Layer

### 定義

**Registry 為唯一正式來源**（SSOT for configuration），包含：

| Registry 類型 | 內容（概念） |
|---------------|--------------|
| **Tenant Registry** | sno、tenant_code、feature、channel 對應 |
| **Product Source Registry** | platforms、instances、templates、catalog |
| **Multi-Source Registry** | 某 tenant 啟用的 `source_instance_keys` 清單 |

### 未來擴展（20～200 家）

新增旅行社時：

1. 新增 **Tenant Registry** 記錄
2. 新增 **Source Instance** 記錄（指向既有 platform + 新 identifier）
3. 必要時新增 **URL Template** 或 catalog 條目
4. **禁止** 為單一 tenant 修改 `MultiSourceSearchUrlBuilder` / Hybrid 核心迴圈

### 儲存策略（摘要）

| 環境 | 建議 |
|------|------|
| Git | sample、schema、default fallback |
| GCS（未來權威） | `tenants/{sno}/config/product_sources.json` |
| Host A 執行時 | cache 降延遲 |

詳見 `DOCUMENTATION_GOVERNANCE_POLICY.md` 與 Travel Data Center 相關計畫文件。

---

## Tenant 與 Source 關係

正式資料流（概念模型）：

```
Tenant（sno / tenant_code）
    ↓
Source Instance（tenant_instance_key + identifier_values）
    ↓
Source Platform（platform_id + 平台級規則）
    ↓
Adapter（平台適配、keyword mapping、path/query 契約）
    ↓
Search URL（long URL；短網址見 PRODUCT_SOURCE_SHORTURL_POLICY）
```

**要點：**

- Hybrid / BATS 管線消費 **SearchCondition + Registry**，不消費散落常數
- 一 Tenant 可對應 **多個 Source Instance**（Multi-Source Search）

---

## Product Category

系統須支援多商品類型，**禁止** 假設僅有團體旅遊。

### 支援類型（現行與未來）

| product_category | 說明 |
|------------------|------|
| `group_tour` | 團體旅遊 |
| `fit` / `free_tour` | 自由行（自由行） |
| `hotel` | 飯店 |
| `flight` / `ticket` | 機票 |
| `cruise` | 郵輪 |
| `charter` / `package` | 包團 |
| （未來擴充） | 以 catalog schema 擴充，不 hardcode 於核心 |

### 規則

- 搜尋文件、registry 條目須帶 **`product_category`**
- Adapter 與 template 依 category 分流
- **禁止** 在 formatter / builder 內寫死「僅團體旅遊」分支而不經 registry

---

## Search URL Policy

### 正式規則

**Search URL 應由 Platform Adapter + URL Template Builder 產生。**

標準路徑（與現行實作對齊）：

```
SearchCondition（Hybrid）
    ↓
MultiSourceSearchUrlBuilder（遍歷 source_instance_keys）
    ↓
ProductSourceSearchUrlBuilder
    ↓
SourceInstanceUrlTemplateBuilder（registry template）
    ↓
search_url（long）
```

### 禁止事項

- **各處自行組 URL**（router、formatter、Gemini context 內拼接 host）
- **URL Builder 直接碰 DB**（短網址在 Publisher / ShortUrl Service 層）
- **formatter 內決定平台參數**

短網址治理見 `PRODUCT_SOURCE_SHORTURL_POLICY.md`（規劃 SSOT）。

---

## Platform Mapping

平台級映射規則應位於 **Platform Layer**（adapter / registry / platform mapper），**不得進入 Hybrid Layer**。

### BBCTravel Platform Mapping（departure_city → departure_path_code，BBCTravel Only）

**平台：** `bbctravel.com.tw`（registry template：`bbctravel_dayitravel_searchlist_v1`，path `/searchlist/{departure_path_code}/`）

> **BBCTravel Only：** 下列 departure mapping **僅適用 bbctravel**；grp.com.tw、tourcenter 及其它平台不得套用。

| departure_city（中文） | departure_path_code |
|------------------------|---------------------|
| 台北 | `tpetsa` |
| 桃園 | `tpe` |
| 松山 | `tsa` |
| 台中 | `RMG` |
| 高雄 | `khh` |
| 台南 | `tnn` |
| **`null`（未指定出發地）** | **`all`** |

#### BBCTravel 不限出發地（`null → all`，BBCTravel Only）

當 Hybrid Layer 產出 `departure_city = null`（語意：**不限出發地**，見 `BATS_HYBRID_DATE_POLICY.md` 章節五），bbctravel 映射為：

```text
/searchlist/all/
```

範例：

```text
https://dayitravel.bbctravel.com.tw/searchlist/all/?q=東京&datefrom=2026-06-21&dateto=2026-06-30&order=1&standby=1
```

**注意：** `null` 語意不等於「預設台北」；`tpetsa` 僅在 `departure_city = 台北` 時使用。

### GRP Platform Mapping（grp.com.tw — travel_b / dayitravel）

**平台：** `grp`（`platform_id`；文件別名 `grp_com_tw`）  
**Source Instance：** `dayitravel_grp` → `dayitravel.grp.com.tw`  
**正式 SSOT：** `GRP_GOLDEN_REFERENCE_PLATFORM_RULE.md`

#### 正確 URL 規格（Golden Reference）

```text
http://{source_instance}.grp.com.tw/ClassifyProduct.aspx?l=l&RadDatePicker1={date_from}&RadDatePicker2={date_to}&tp={keyword}
```

`dayitravel` 為 travel_b 在 grp.com.tw 之 **Source Instance**（`source_instance` / `subdomain`）。

**travel_b 範例（日期區間）：**

```text
http://dayitravel.grp.com.tw/ClassifyProduct.aspx?l=l&RadDatePicker1=2026-07-01&RadDatePicker2=2026-07-03&tp=瑞士
```

| 參數 | 規則 |
|------|------|
| `l` | 固定 `l` |
| `RadDatePicker1` | Hybrid `date_from`（出發起日，`YYYY-MM-DD`） |
| `RadDatePicker2` | Hybrid `date_to`（出發截止日，`YYYY-MM-DD`） |
| `tp` | Hybrid `keyword`（**明文** `tp={keyword}`；URL Encode 不屬 Platform Rule） |

**單日搜尋：** `date_from = date_to` 時，`RadDatePicker1` 與 `RadDatePicker2` 帶相同日期（仍為雙參數格式）。

#### grp 平台限制

| 項目 | grp 行為 |
|------|----------|
| **出發地** | **不支援**；忽略 `departure_city` |
| **BBCTravel Departure Mapping** | **不得套用**（`khh`、`RMG`、`all`、`tpetsa` 等） |
| **日期區間** | 直接使用 Hybrid `date_from` / `date_to`；模糊語意（近期、最近、本月、下月、月初、月中、月底、暑假、寒假、明年等）**不採用單日代表日策略** |

#### Deprecated（舊資料，不可作 Golden Reference）

```text
/Tour/Search?GetStore=dayitravel
```

上述路徑為舊 registry／文件描述，**已廢止**作為 grp Golden Reference。Phase 2 須以 `ClassifyProduct.aspx` 模板取代。

### 平台隔離：不得套用 BBCTravel Departure Mapping

下列商品源**不得**套用 bbctravel 之 `departure_path_code` 映射（含 `null → all`）：

| 平台 | Golden Reference URL（travel_b pilot） | 說明 |
|------|----------------------------------------|------|
| **grp** | `ClassifyProduct.aspx?l=l&RadDatePicker1=…&RadDatePicker2=…&tp=…` | 支援日期區間；無出發地；詳見 `GRP_GOLDEN_REFERENCE_PLATFORM_RULE.md` |
| **tourcenter** | 固定入口 `https://dayitourcenter.com.tw/` | 無 departure path；規則待 tourcenter Golden Reference 定義 |
| **未來其它商品源** | 依各平台 registry／adapter | 須於 Platform Layer 獨立定義；**禁止**假設與 bbctravel 相同 |

Hybrid Layer 對所有平台統一產出 `departure_city`（中文或 `null`）；各平台在 Platform Mapping Layer **自行決定是否使用、如何映射**（grp：**不使用**）。

### 規則

- Hybrid Layer 產出 **`departure_city`**、日期、keyword 等 **語意欄位**（含裸出發地前綴；見 `BATS_HYBRID_DATE_POLICY.md` 章節五）
- Platform Layer 負責平台專用映射：**bbctravel** → `departure_path_code`；**grp** → 忽略 `departure_city`；**agenttour** → RegionCode 等
- **agenttour** 之 RegionCode 等平台映射同屬 Platform Layer（如 `RegionKeywordMapper`）

日期語意規則見 **`BATS_HYBRID_DATE_POLICY.md`**（L2）。平台如何消費 `date_from`/`date_to` 見同文件「平台日期參數消費規則」。出發地語意見章節五；bbctravel path 細節見章節六（**BBCTravel Only**）。

---

## Multi-Source Search

### 定義

**一個查詢**（同一 `SearchCondition`）可對 **多個 Source Instance** 同時產生 URL 清單。

### 輸出契約（概念）

```json
[
  { "platform": "grp", "tenant_instance": "dayitravel_grp", "search_url": "https://..." },
  { "platform": "bbctravel", "tenant_instance": "dayitravel_bbctravel", "search_url": "https://..." },
  { "platform": "tourcenter", "tenant_instance": "dayitravel_tourcenter", "search_url": "https://..." }
]
```

### 治理要點

| 要點 | 說明 |
|------|------|
| **啟用清單** | 由 tenant 之 `source_instance_keys`（config / registry）決定 |
| **不 fetch 頁面** | Builder 只產 URL，不呼叫外部 API |
| **與主 search_url 並存** | bonusmee / Host B 主搜尋 URL 與多源 URL 分離 |
| **顯示層** | Gemini context / LINE formatter 只顯示已產出之 URL，不重新組裝 |

Pilot 參考：`travel_b_multi_source_links.php`、`travel_b_search_registry.php`（實作細節見 L3 文件）。

---

## 新增 Tenant 流程

### 標準流程

| 步驟 | 動作 |
|------|------|
| 1 | **建立 Tenant Registry** — sno、tenant_code、channel、credential 對應 |
| 2 | **建立 Source Registry** — 該 tenant 之 instances、templates、catalog 條目 |
| 3 | **建立 Mapping** — 平台級 mapping（如 departure、region keyword） |
| 4 | **建立 Adapter** — 若為新 platform，新增 adapter；若為既有 platform，僅新 instance 參數 |
| 5 | **驗證** — 單元測試、Multi-Source URL 測試、staging LINE 驗收 |

### 禁止事項

- **直接修改核心搜尋流程**（`HybridSearchConditionBuilder` 核心、saas_router 主徑）
- **複製貼上另一 tenant 的程式分支**
- **未文件化即上線**（須更新本文件或 L3 驗收文件）

### Rollback

新增 tenant 之變更須符合 `RECOVERY_AND_ROLLBACK_POLICY.md`（registry 可透過 git revert / config 關閉）。

---

## 與其他文件關係

### 上層（L0）

| 文件 | 關係 |
|------|------|
| `CO_WORK_POLICY.md` | 最高協作治理；架構分層、禁止 hardcode |
| `DOCUMENTATION_GOVERNANCE_POLICY.md` | L0 文件治理；本文件為 L1 SSOT |

### 同層（L1）

| 文件 | 關係 |
|------|------|
| `RECOVERY_AND_ROLLBACK_POLICY.md` | Registry / 部署變更之 Recovery |
| `PRODUCT_SOURCE_SHORTURL_POLICY.md` | 多源 URL 短網址（規劃 SSOT） |
| `API_POLICY.md` | API / Gateway 與 tenant 隔離 |

### 下層（L2 領域規則）

| 文件 | 關係 |
|------|------|
| `BATS_HYBRID_DATE_POLICY.md` | Hybrid 日期／出發地語意；平台映射不在此文件重複定義 |

### 文件優先順序（本領域）

```
CO_WORK_POLICY.md
    ↓
DOCUMENTATION_GOVERNANCE_POLICY.md
    ↓
TENANT_SOURCE_REGISTRY_POLICY.md   ← 本文件
    ↓
BATS_HYBRID_DATE_POLICY.md（日期語意）
    ↓
PRODUCT_SOURCE_SHORTURL_POLICY.md（短網址）
    ↓
L3 功能 / Contract 文件
```

---

## 未來 Roadmap

本政策支撐以下演進（P3 為主，不阻塞現行 Pilot）：

| 方向 | 說明 |
|------|------|
| **20～200 Tenant** | GCS 權威 + Host A cache；Git 僅 sample |
| **多平台** | 新 platform_id + adapter + template，不擴核心 if |
| **多商品類型** | catalog `product_category` 擴充 |
| **Ranking** | 結果排序層；不污染 URL Builder |
| **RAG** | 知識檢索；與 Search URL 管線分離 |
| **AI Recommendation** | 推薦層消費 registry metadata，不 hardcode source |

Roadmap 項目須記錄於 `TECH_DEBT.md` 或專項 Roadmap 文件，並引用本 SSOT。

---

## 結論

**Tenant Registry** 與 **Source Registry** 必須成為 BBC AI SaaS / BATS **唯一正式治理方式**。

- **Config Driven** — 設定與 registry 為權威  
- **Registry Driven** — 新增旅行社以 registry 完成  
- **Adapter Driven** — 平台差異封裝於 Platform Layer  

所有 `travel_a`、`travel_b`、`travel_c` 及未來 20～200 家旅行社，皆應依本文件擴展，**不得** 以散落 hardcode 或核心流程 fork 取代。

ChatGPT、Cursor、開發者進行 tenant / product source 相關決策或實作前，應以本文件為 **Tenant Registry 與 Product Source Registry 之正式 SSOT**。

---

## Revision History

| 版本 | 日期 | 狀態 | 說明 |
|------|------|------|------|
| 1.0 | 2026-06-05 | Adopted | Initial Version |
| 1.1 | 2026-06-06 | Adopted | Phase 1B：grp.com.tw Platform Mapping（ClassifyProduct.aspx）；deprecated Tour/Search |
| 1.2 | 2026-06-06 | Superseded | Phase 1B-Revise v1：grp 日期區間 RadDatePicker1/RadDatePicker2 |
| 1.3 | 2026-06-06 | Adopted | Phase 1B-Revise v2：tp 明文規格、source_instance、模糊語意擴充 |
