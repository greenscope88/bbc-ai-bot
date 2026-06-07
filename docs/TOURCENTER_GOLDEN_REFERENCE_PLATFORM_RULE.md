# TourCenter Golden Reference — Platform Rule

**專案：** BBC AI SaaS / BATS  
**定位：** tourcenter.com.tw Platform Rule SSOT / Golden Reference（L2）  
**文件別名：** tourcenter_com_tw（僅文件用；**platform_id 沿用 `tourcenter`**）  
**上層文件：** `TENANT_SOURCE_REGISTRY_POLICY.md`、`BATS_HYBRID_DATE_POLICY.md`  
**衝突處理：** tourcenter URL 平台規則以**本文件為準**；日期語意以 `BATS_HYBRID_DATE_POLICY.md` 為準。

---

## 文件定位

本文件定義 **tourcenter.com.tw** 商品源之正式 Platform Rule，作為 travel_b Golden Reference 第三商品源與未來 URL Builder / registry template 設計之 SSOT。

**本階段（Phase 1B）：** 僅文件；不修改程式、registry、測試。

---

## 適用範圍

| 項目 | 值 |
|------|-----|
| **platform_id** | `tourcenter` |
| **platform_domain** | `tourcenter.com.tw` |
| **Pilot tenant** | travel_b（`sno`: `5f99b8d665e8444d`） |
| **Source Instance** | `dayitravel_tourcenter` |
| **source_instance / subdomain** | `dayitravel`（travel_b 在 tourcenter.com.tw 之 Source Instance） |
| **Host** | `dayitravel.tourcenter.com.tw` |

### Canonical Source Instance（同層級）

下列 host 為 **同一 Source Instance / subdomain 概念**（`dayitravel`），僅平台網域不同：

| 平台 | Canonical Host |
|------|----------------|
| **bbctravel** | `dayitravel.bbctravel.com.tw` |
| **grp** | `dayitravel.grp.com.tw` |
| **tourcenter** | `dayitravel.tourcenter.com.tw` |

---

## 正確 URL 範例

**日期區間 + 出發地 + 關鍵字：**

```text
https://dayitravel.tourcenter.com.tw/travel/search?DepartureID=TPE&ArriveID=&GoDateStart=2026-06-04&GoDateEnd=2028-06-04&TravelType=0&Keywords=北京&KeywordsCity=
```

**單日搜尋（`date_from = date_to`；仍維持雙參數）：**

```text
https://dayitravel.tourcenter.com.tw/travel/search?DepartureID=KHH&ArriveID=&GoDateStart=2026-06-11&GoDateEnd=2026-06-11&TravelType=0&Keywords=東京&KeywordsCity=
```

**不限出發地（第五種合法出發地狀態）：**

```text
https://dayitravel.tourcenter.com.tw/travel/search?DepartureID=&ArriveID=A1-A-6,&GoDateStart=2026-06-07&GoDateEnd=2028-06-07&TravelType=0&Keywords=
```

| 輸入語意（概念） | 參數 |
|------------------|------|
| 出發地 | `DepartureID={departure_id}`（見 §TourCenter Departure Mapping） |
| 出發日期起日 | `GoDateStart={date_from}` |
| 出發日期截止日 | `GoDateEnd={date_to}` |
| 目的地／關鍵字 | `Keywords={keyword}` |
| 固定參數 | `TravelType=0` |
| 可選 | `ArriveID`、`KeywordsCity`（可空） |

---

## Platform Rule（摘要表）

| 欄位 | 規則 |
|------|------|
| **scheme** | `https` |
| **host** | `{subdomain}.tourcenter.com.tw`（例：`dayitravel.tourcenter.com.tw`） |
| **path** | `/travel/search` |
| **query: DepartureID** | `{departure_id}`（出發地代碼；空值＝不限出發地） |
| **query: ArriveID** | `{arrive_id}`（可空） |
| **query: GoDateStart** | `{date_from}`（出發起日），格式 `YYYY-MM-DD` |
| **query: GoDateEnd** | `{date_to}`（出發截止日），格式 `YYYY-MM-DD` |
| **query: TravelType** | 固定常數 `0` |
| **query: Keywords** | `{keyword}`（目的地／搜尋關鍵字） |
| **query: KeywordsCity** | `{keywords_city}`（可空） |
| **departure** | **支援**；`departure_city` → `DepartureID`（TourCenter 專用映射） |

---

## BATS → TourCenter URL Mapping

| BATS 欄位 | TourCenter query 參數 |
|-----------|----------------------|
| **keyword** | `Keywords` |
| **date_from** | `GoDateStart` |
| **date_to** | `GoDateEnd` |
| **departure_city** | `DepartureID` |

**資料流：**

```text
Hybrid SearchCondition
  → SearchCondition（keyword / date_from / date_to / departure_city）
  → TourCenter Platform Mapper（departure_city → DepartureID）
  → URL Builder → /travel/search?...
```

---

## Platform Rule（YAML 草案）

```yaml
platform_id: tourcenter
platform_domain: tourcenter.com.tw
document_alias: tourcenter_com_tw

source_instance_example:
  tenant_instance_key: dayitravel_tourcenter
  tenant_sno: 5f99b8d665e8444d
  subdomain: dayitravel

url:
  scheme: https
  host_pattern: "{subdomain}.tourcenter.com.tw"
  path: /travel/search
  query:
    DepartureID: "{departure_id}"
    ArriveID: "{arrive_id}"
    GoDateStart: "{date_from}"
    GoDateEnd: "{date_to}"
    TravelType: "0"
    Keywords: "{keyword}"
    KeywordsCity: "{keywords_city}"

keyword_strategy:
  source: SearchCondition.keyword
  mapping: Keywords={keyword}

date_strategy:
  hybrid_output: [date_from, date_to]
  tourcenter_url_mapping:
    GoDateStart: date_from
    GoDateEnd: date_to
    format: YYYY-MM-DD
    single_day: date_from equals date_to

departure_strategy:
  supported: true
  independent_platform_spec: true
  mapping:
    不限出發地: ""          # DepartureID= 空值（平台實測確認）
    台北: TPE
    台中: TCH
    台南: TNN
    高雄: KHH
  forbidden_bbctravel_codes: [tpetsa, tpe, tsa, RMG, khh, tnn, all, TXG]

short_url_strategy:
  phase_3b: completed
  builder: long_url_only
  display_layer: PRODUCT_SOURCE_SHORTURL_POLICY.md
```

---

## URL 組成規則

### 正式模板（概念）

```text
https://{source_instance}.tourcenter.com.tw/travel/search?DepartureID={departure_id}&GoDateStart={date_from}&GoDateEnd={date_to}&TravelType=0&Keywords={keyword}
```

`{source_instance}` 與 `{subdomain}` 同義（例：travel_b → `dayitravel`）。

可選參數 `ArriveID`、`KeywordsCity` 有值時 appended；空值可省略或保留空 query value（依平台慣例；見 §不限出發地）。

### Query 參數

| 參數 | 必填 | 說明 |
|------|------|------|
| `DepartureID` | ✅（語意上） | 出發地代碼；**空值**＝不限出發地 |
| `ArriveID` | ❌ | 目的地代碼；可空 |
| `GoDateStart` | ✅（有日期語意時） | 出發起日；來自 Hybrid `date_from` |
| `GoDateEnd` | ✅（有日期語意時） | 出發截止日；來自 Hybrid `date_to` |
| `TravelType` | ✅ | 固定值 `0` |
| `Keywords` | ✅（有關鍵字語意時） | 搜尋關鍵字；來自 Hybrid `keyword` |
| `KeywordsCity` | ❌ | 城市級關鍵字；可空 |

---

## Keyword Strategy

- **映射：** `Keywords = {keyword}`
- **來源：** `SearchCondition.keyword`（或等價已解析目的地）
- **範例：**
  - 北京 → `Keywords=北京`
  - 東京 → `Keywords=東京`
  - 大阪 → `Keywords=大阪`
  - 北海道 → `Keywords=北海道`

### Keywords 與 URL Encode（平台規格邊界）

**Platform Rule / URL Template 寫法：** `Keywords={keyword}`（明文關鍵字）。

**不屬於 Platform Rule：**

- percent-encoding（如 `Keywords=%E5%8C%97%E4%BA%AC`）
- Browser、HTTP Client、Transport Layer 之編碼行為

URL Encode 由執行環境處理；**SearchCondition、Platform Rule、URL Template 不得將 percent-encoding 寫入規格**。

---

## Date Strategy

### Hybrid 層（不變）

- Hybrid Date Parser 統一產出 `date_from`、`date_to`（見 `BATS_HYBRID_DATE_POLICY.md`）。
- `HybridDateRequiredGate` 仍要求日期語意補齊後才進入搜尋。

### tourcenter URL 日期映射

| Hybrid 欄位 | tourcenter query 參數 | 說明 |
|-------------|----------------------|------|
| `date_from` | `GoDateStart` | 出發日期起日 |
| `date_to` | `GoDateEnd` | 出發日期截止日 |

**單日搜尋：** 當 `date_from = date_to`，`GoDateStart` 與 `GoDateEnd` 帶**相同日期**。

**模糊區間：** 「近期」「本月」「6月底」「暑假」等，Hybrid Date Parser 已產出 `date_from` / `date_to` 區間；tourcenter URL Builder **直接使用**，**不採用單日代表日策略**。

**資料流：**

```text
Hybrid Date Parser → date_from / date_to → tourcenter URL Builder → GoDateStart / GoDateEnd
```

**禁止：** tourcenter URL Builder 自行解析自然語言日期。

---

## TourCenter Departure Mapping

> **獨立平台規格。不得共用 BBCTravel Departure Mapping。**

### 正式對照表

| departure_city（Hybrid 中文） | DepartureID | 說明 |
|------------------------------|-------------|------|
| **不限出發地** | ``（空值） | `DepartureID=` — **第五種合法出發地狀態**（No Departure Restriction） |
| **台北** | `TPE` | TourCenter 專用代碼；平台 API／UI `CityName` 為「台北」 |
| **台中** | `TCH` | TourCenter 專用代碼（**非** BBCTravel `RMG`） |
| **台南** | `TNN` | TourCenter 專用代碼 |
| **高雄** | `KHH` | TourCenter 專用代碼（**非** BBCTravel path `khh`） |

### 不限出發地規則

當 Hybrid 產出 `departure_city = null`（語意：不限出發地），tourcenter URL Builder 映射為：

```text
DepartureID=
```

**平台實測確認（Phase 1B-B）：** `DepartureID=` 代表**不限出發地**（No Departure Restriction）。API `depthinfojson` 對空值回傳 `DepartureID: null`；相較單一出發地，搜尋結果範圍更廣（例：`ArriveID=A1-A-6,` 東京條件下，空值 111 筆 vs `TPE` 52 筆）。

**正式範例：**

```text
https://dayitravel.tourcenter.com.tw/travel/search?DepartureID=&ArriveID=A1-A-6,&GoDateStart=2026-06-07&GoDateEnd=2028-06-07&TravelType=0&Keywords=
```

| 規則 | 說明 |
|------|------|
| **空值語意** | `DepartureID=` 代表不限出發地，**不是**錯誤或缺參；**已經過平台實測確認** |
| **與 BBCTravel 差異** | bbctravel `null` → `/searchlist/all/`；tourcenter `null` → `DepartureID=` 空值 |
| **禁止** | 將 `null` 映射為 `TPE` 或其他預設出發地（除非使用者語意明確指定出發城市） |

### TNN 備註

`TNN` 代碼於 TourCenter 平台上**存在**（`CityName`：台南）。部分目的地可能查無團體行程（`TotalCount=0`），**不得**解讀為代碼失效。

### TourCenter 專屬 Departure Mapping 規則

#### BATS Hybrid Departure Normalization（TourCenter 口語正規化）

客戶若輸入下列口語變體，於 **BATS Hybrid Departure Normalization** 階段一律正規化為 **台北**：

| 客戶輸入（口語變體） |
|----------------------|
| 台北 |
| 松山 |
| 桃園 |
| 台北出發 |
| 松山出發 |
| 桃園出發 |

正規化後之 `departure_city = 台北`，**僅在** `platform_id = tourcenter` 時，由 TourCenter Platform Layer 套用：

```text
台北 → TPE
```

#### 平台隔離（Platform Isolation）

下列規則屬於 **TourCenter Platform Rule**，**不得**套用至其他商品源平台：

| 規則 | 適用範圍 |
|------|----------|
| `台北 → TPE` | **僅** `platform_id = tourcenter` |
| `台中 → TCH`、`台南 → TNN`、`高雄 → KHH` | **僅** `platform_id = tourcenter` |
| `DepartureID=`（不限出發地） | **僅** `platform_id = tourcenter` |

**禁止套用至：**

- `platform_id = bbctravel`
- `platform_id = grp`
- 其它未來商品源平台

**目的：** 避免平台間 Departure Mapping 汙染；各平台維持獨立 `departure_city` → 平台代碼映射。

### 禁止套用 BBCTravel 專用代碼

下列代碼 **僅屬 bbctravel** `/searchlist/{code}/`，**禁止**用於 tourcenter `DepartureID`：

| 禁止代碼 | 原因 |
|----------|------|
| `RMG` | BBCTravel 台中 path code |
| `all` | BBCTravel 不限出發地 path |
| `khh` | BBCTravel 高雄 path code（小寫） |
| `TXG` | IATA 台中；非 TourCenter 正式代碼 |
| `tpetsa` | BBCTravel 台北 path code |
| `tpe`、`tsa`、`tnn`（bbctravel 語意） | BBCTravel 專用；tourcenter 台南為 `TNN`（大寫） |

**規則：** Hybrid 層統一產出 `departure_city`（中文或 `null`）；**僅 tourcenter Platform Layer** 執行上表映射。

---

## Tenant Source Registry 原則

每個 tenant 透過 registry **`source_instance_keys`** 獨立啟用商品源；**禁止**假設所有 tenant 都有三個商品源。

```text
travel_b
├─ bbctravel
├─ grp
└─ tourcenter

travel_c
└─ bbctravel

travel_d
├─ grp
└─ tourcenter
```

| 規則 | 說明 |
|------|------|
| **travel_b** | Pilot；三源（`dayitravel_bbctravel`、`dayitravel_grp`、`dayitravel_tourcenter`） |
| **travel_c** | 擴展範例；僅 bbctravel（實際 instance key 依 registry 為準） |
| **travel_d** | 擴展範例；grp + tourcenter（無 bbctravel） |
| **禁止** | 在 formatter / builder 假設「每 tenant 必有 tourcenter」 |
| **新增 tenant** | 只增 registry `source_instance_keys` + catalog；不 fork URL Builder 核心 |

---

## Short URL Strategy

| 項目 | 說明 |
|------|------|
| **Phase 3B** | ✅ **已完成**（`93a3b18`）— bbctravel / grp / tourcenter 統一 `bbcshops.com/...` |
| **URL Builder** | **只產 Long URL**（本文件 `/travel/search?...`） |
| **Short URL** | 由 **Publisher / Display Layer**（`TourPromptContextService` + `ShortUrlService`）處理 |
| **禁止** | 在 tourcenter URL Builder 內直接產 `bbcshops.com` 短碼 |
| **SSOT** | 見 `PRODUCT_SOURCE_SHORTURL_POLICY.md` |

---

## Deprecated（不可作 Golden Reference）

下列描述為**舊資料**，**不得**再作為 tourcenter Golden Reference：

```text
https://dayitourcenter.com.tw/
```

舊 registry template `tourcenter_dayitourcenter_entry_v1`（固定入口、無 search query）待 Phase 1C 以 `tourcenter_dayitravel_search_v1`（概念名）取代。

---

## Phase 1C 實作前置條件

1. **文件定案** — 本文件 Adopted + `TENANT_SOURCE_REGISTRY_POLICY.md` + `BATS_HYBRID_DATE_POLICY.md` cross-ref 更新
2. **Registry template 設計** — 新建 template（概念：`tourcenter_dayitravel_search_v1`）；`identifier_type: subdomain`
3. **Platform Departure Mapper** — `departure_city` → `DepartureID`（含空值＝不限出發地）
4. **URL Builder** — `date_from`→`GoDateStart`、`date_to`→`GoDateEnd`、`keyword`→`Keywords`、`TravelType=0`
5. **測試案例** — 北京、東京6月底、高雄東京近期、不限出發地（`DepartureID=`）
6. **短網址** — Display 層已就緒（Phase 3B）；1C 僅須 long URL 正確

---

## CWP / DDD / SSOT Check

| 檢查 | 結果 |
|------|------|
| **CWP** | ✅ 本階段僅文件，未改程式 |
| **DDD** | ✅ 第三商品源平台規則文件化 |
| **SSOT First** | ✅ 本文件為 tourcenter URL Rule 正式 SSOT |
| **主線** | ✅ 接續 BBCTravel v1.0、grp Golden Reference → tourcenter Golden Reference |

---

## 相關文件

| 文件 | 關係 |
|------|------|
| `TENANT_SOURCE_REGISTRY_POLICY.md` | Registry 治理；tourcenter 摘要與 cross-ref（待同步） |
| `BATS_HYBRID_DATE_POLICY.md` | Hybrid 日期語意；平台日期／出發地消費規則（待同步） |
| `PRODUCT_SOURCE_SHORTURL_POLICY.md` | 短網址 Phase 3B 現況 |
| `GRP_GOLDEN_REFERENCE_PLATFORM_RULE.md` | grp 同級 Golden Reference |
| `BBCTRAVEL_GOLDEN_REFERENCE_V1_COMPLETION.md` | BBCTravel v1.0 完成盤點 |

---

## Revision History

| 版本 | 日期 | 狀態 | 說明 |
|------|------|------|------|
| 1.0 | 2026-06-06 | Adopted | Phase 1B：TourCenter Golden Reference — `/travel/search`、DepartureID 映射、Tenant 原則 |
| 1.1 | 2026-06-06 | Adopted | Phase 1B-B：DepartureID 實測校正 — `TPE` 對應平台「台北」、`DepartureID=` 實測確認、TNN 備註、TourCenter 專屬口語正規化與平台隔離 |
