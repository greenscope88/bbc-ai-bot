# GRP Golden Reference — Platform Rule

**專案：** BBC AI SaaS / BATS  
**定位：** grp.com.tw Platform Rule SSOT / Golden Reference（L2）  
**文件別名：** grp_com_tw（僅文件用；**platform_id 沿用 `grp`**）  
**上層文件：** `TENANT_SOURCE_REGISTRY_POLICY.md`、`BATS_HYBRID_DATE_POLICY.md`  
**衝突處理：** grp URL 平台規則以**本文件為準**；日期語意以 `BATS_HYBRID_DATE_POLICY.md` 為準。

---

## 文件定位

本文件定義 **grp.com.tw** 商品源之正式 Platform Rule，作為 travel_b Golden Reference 與未來 URL Builder / registry template 設計之 SSOT。

**本階段（Phase 1B）：** 僅文件；不修改程式、registry、測試。

---

## 適用範圍

| 項目 | 值 |
|------|-----|
| **platform_id** | `grp` |
| **platform_domain** | `grp.com.tw` |
| **Pilot tenant** | travel_b（`sno`: `5f99b8d665e8444d`） |
| **Source Instance** | `dayitravel_grp` |
| **source_instance / subdomain** | `dayitravel`（travel_b 在 grp.com.tw 之 Source Instance） |
| **Host** | `dayitravel.grp.com.tw` |

---

## 正確 URL 範例

**日期區間：**

```text
http://dayitravel.grp.com.tw/ClassifyProduct.aspx?l=l&RadDatePicker1=2026-07-01&RadDatePicker2=2026-07-03&tp=瑞士
```

**單日搜尋（`date_from = date_to`；仍維持雙參數）：**

```text
http://dayitravel.grp.com.tw/ClassifyProduct.aspx?l=l&RadDatePicker1=2026-06-11&RadDatePicker2=2026-06-11&tp=北海道
```

| 輸入語意（概念） | 參數 |
|------------------|------|
| 目的地／關鍵字 | `tp=北海道` |
| 出發日期起日 | `RadDatePicker1={date_from}` |
| 出發日期截止日 | `RadDatePicker2={date_to}` |
| 固定參數 | `l=l` |

---

## Platform Rule（摘要表）

| 欄位 | 規則 |
|------|------|
| **scheme** | `http` 或 `https`（實際以平台回應為準；Phase 2 設計時確認正式 scheme） |
| **host** | `{subdomain}.grp.com.tw`（例：`dayitravel.grp.com.tw`） |
| **path** | `/ClassifyProduct.aspx` |
| **query: l** | 固定常數 `l` |
| **query: RadDatePicker1** | `{date_from}`（出發起日），格式 `YYYY-MM-DD` |
| **query: RadDatePicker2** | `{date_to}`（出發截止日），格式 `YYYY-MM-DD` |
| **query: tp** | `{keyword}`（目的地／搜尋關鍵字） |
| **departure** | **不支援**；忽略 `departure_city` |

---

## Platform Rule（YAML 草案）

```yaml
platform_id: grp
platform_domain: grp.com.tw
document_alias: grp_com_tw

source_instance_example:
  tenant_instance_key: dayitravel_grp
  tenant_sno: 5f99b8d665e8444d
  subdomain: dayitravel

url:
  scheme: http
  host_pattern: "{subdomain}.grp.com.tw"
  path: /ClassifyProduct.aspx
  query:
    l: "l"
    RadDatePicker1: "{date_from}"
    RadDatePicker2: "{date_to}"
    tp: "{keyword}"

keyword_strategy:
  source: SearchCondition.keyword  # 或等價 destination
  mapping: tp={keyword}

date_strategy:
  hybrid_output: [date_from, date_to]
  grp_url_mapping:
    RadDatePicker1: date_from
    RadDatePicker2: date_to
    format: YYYY-MM-DD
    single_day: date_from equals date_to

departure_strategy:
  supported: false
  ignore: departure_city
  forbidden_bbctravel_codes: [tpetsa, tpe, tsa, RMG, khh, tnn, all]

short_url_strategy:
  phase_1b: not_implemented
  future: PRODUCT_SOURCE_SHORTURL_POLICY.md
```

---

## URL 組成規則

### 正式模板（概念）

```text
{scheme}://{source_instance}.grp.com.tw/ClassifyProduct.aspx?l=l&RadDatePicker1={date_from}&RadDatePicker2={date_to}&tp={keyword}
```

`{source_instance}` 與 `{subdomain}` 同義（例：travel_b → `dayitravel`）。

### Query 參數

| 參數 | 必填 | 說明 |
|------|------|------|
| `l` | ✅ | 固定值 `l` |
| `RadDatePicker1` | ✅（有日期語意時） | 出發起日；來自 Hybrid `date_from` |
| `RadDatePicker2` | ✅（有日期語意時） | 出發截止日；來自 Hybrid `date_to` |
| `tp` | ✅ | 搜尋關鍵字；來自 Hybrid `keyword` |

---

## Keyword Strategy

- **映射：** `tp = {keyword}`
- **來源：** `SearchCondition.keyword`（或等價已解析目的地）
- **範例：**
  - 瑞士 → `tp=瑞士`
  - 北海道 → `tp=北海道`
  - 東京 → `tp=東京`
  - 大阪 → `tp=大阪`

### tp 與 URL Encode（平台規格邊界）

**Platform Rule / URL Template 寫法：** `tp={keyword}`（明文關鍵字）。

**不屬於 Platform Rule：**

- `tp=%e7%91%9e%e5%a3%ab` 等 percent-encoding
- Browser、HTTP Client、Transport Layer 之編碼行為

URL Encode 由執行環境（瀏覽器、HTTP client）處理；**SearchCondition、Platform Rule、URL Template 不得將 percent-encoding 寫入規格**。

**禁止：** 將 `departure_city` 映射為 `tp` 或任何 query 參數。

---

## Date Strategy

### Hybrid 層（不變）

- Hybrid Date Parser 統一產出 `date_from`、`date_to`（見 `BATS_HYBRID_DATE_POLICY.md`）。
- `HybridDateRequiredGate` 仍要求日期語意補齊後才進入搜尋。

### grp URL 日期映射

| Hybrid 欄位 | grp query 參數 | 說明 |
|-------------|----------------|------|
| `date_from` | `RadDatePicker1` | 出發日期起日 |
| `date_to` | `RadDatePicker2` | 出發日期截止日 |

**單日搜尋：** 當 `date_from = date_to`（如「6/10東京」），`RadDatePicker1` 與 `RadDatePicker2` 帶**相同日期**。

**模糊區間：** 「近期」「最近」「本月」「下月」「月初」「月中」「月底」「6月底」「暑假」「寒假」「明年」等，Hybrid Date Parser 已產出 `date_from` / `date_to` 區間；grp URL Builder **直接使用**，**不採用單日代表日策略**。

**資料流：**

```text
Hybrid Date Parser → date_from / date_to → grp URL Builder → RadDatePicker1 / RadDatePicker2
```

| 語意（範例） | Hybrid 產出 | grp URL |
|--------------|-------------|---------|
| 大阪近期 | `date_from` ~ `date_to`（today+60） | `RadDatePicker1` + `RadDatePicker2` |
| 東京6月底 | `date_from` ~ `date_to`（月底區間） | `RadDatePicker1` + `RadDatePicker2` |
| 6/10東京 | `date_from = date_to` | 兩參數同值 |

**禁止：** grp URL Builder 自行解析自然語言日期。

---

## Departure Strategy

| 規則 | 說明 |
|------|------|
| **supported** | `false` |
| **departure_city** | 若 `SearchCondition` 有值（如「高雄東京6月底」），grp URL Builder **必須忽略** |
| **禁止** | 套用 BBCTravel Departure Mapping：`tpetsa`、`tpe`、`tsa`、`RMG`、`khh`、`tnn`、`all` 等 |
| **禁止** | 在 path 或 query 帶出發地參數 |

Hybrid 層仍可解析裸出發地前綴並寫入 `departure_city`；**僅 grp Platform Layer 忽略**。

---

## Short URL Strategy

| 項目 | 說明 |
|------|------|
| **Phase 1B** | 不實作 |
| **現況** | travel_b multi-source LINE 顯示 grp **長鏈**（`dayitravel.grp.com.tw/...`） |
| **後續** | 沿用 `PRODUCT_SOURCE_SHORTURL_POLICY.md` — `ShortUrlService` / `ProductSourceUrlPublisher` |
| **禁止** | 在 grp URL Builder 內直接產 `bbcshops.com` 短碼 |

---

## Deprecated（不可作 Golden Reference）

下列描述為**舊資料**，**不得**再作為 grp Golden Reference：

```text
/Tour/Search?GetStore=dayitravel
```

舊 registry template `grp_dayitravel_subdomain_v1` 與上述路徑，待 Phase 2 以 `ClassifyProduct.aspx` 模板取代。

---

## Phase 2 實作前置條件

1. **文件定案** — 本文件 + `TENANT_SOURCE_REGISTRY_POLICY.md` + `BATS_HYBRID_DATE_POLICY.md` 平台日期消費章節
2. **Registry template 設計** — 新建 template（概念：`grp_dayitravel_classify_v1`）
3. **URL Builder / Adapter** — `date_from`→`RadDatePicker1`、`date_to`→`RadDatePicker2`、`keyword`→`tp`、忽略 `departure_city`
4. **測試案例** — 北海道、東京6月底、大阪近期等
5. **短網址化** — URL 正確後，再接入 Publisher（非 Builder 職責）

---

## CWP / DDD / SSOT Check

| 檢查 | 結果 |
|------|------|
| **CWP** | ✅ 本階段僅文件，未改程式 |
| **DDD** | ✅ 平台規則文件化，修正 SSOT 漂移 |
| **SSOT First** | ✅ 本文件為 grp URL Rule 正式 SSOT |
| **主線** | ✅ 接續 BBCTravel v1.0 → grp Golden Reference |

---

## 相關文件

| 文件 | 關係 |
|------|------|
| `TENANT_SOURCE_REGISTRY_POLICY.md` | Registry 治理；grp 摘要與 cross-ref |
| `BATS_HYBRID_DATE_POLICY.md` | Hybrid 日期語意；平台日期消費規則 |
| `PRODUCT_SOURCE_SHORTURL_POLICY.md` | 短網址分階段現況 |
| `BBCTRAVEL_GOLDEN_REFERENCE_V1_COMPLETION.md` | BBCTravel v1.0 完成盤點 |

---

## Revision History

| 版本 | 日期 | 狀態 | 說明 |
|------|------|------|------|
| 1.0 | 2026-06-06 | Superseded | Phase 1B 初版（單日規格，已廢止） |
| 1.1 | 2026-06-06 | Superseded | Phase 1B-Revise v1：日期區間 RadDatePicker1/RadDatePicker2 |
| 1.2 | 2026-06-06 | Adopted | Phase 1B-Revise v2：tp 明文規格、source_instance、模糊語意擴充 |
