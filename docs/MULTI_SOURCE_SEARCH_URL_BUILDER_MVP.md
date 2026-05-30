# Multi-Source Search URL Builder MVP

**代號：** `MULTI_SOURCE_SEARCH_URL_BUILDER_MVP`  
**版本：** Phase 9-B-2  
**狀態：** MVP — 僅產生 **Long URL**；不接 ShortUrlService / BATS / GCS

**規劃主機：** 主機 A `103.1.222.14` · `C:\bbc-ai-bot`

**前置：** Phase 9-B-1a/1b（`db77c53`）— `ProductSourceRegistry` + Catalog Contract

---

## 1. Purpose

驗證多商品源架構下：

```
ProductSourceRegistry
    ↓
UrlTemplateResolver（source_id → url_template_id）
    ↓
UrlTemplateRegistry（sample templates）
    ↓
MultiSourceSearchUrlBuilder
    ↓
ProductSourceUrlResult（search_url / detail_url）
```

**本階段不做：** 短網址、LINE 回覆、Gemini、Hybrid、Host B HTTP、GCS。

---

## 2. Current Architecture

```
docs/sample/product_source_catalog.sample.json     ← catalog + url_template_id
docs/sample/travel_b_product_sources.sample.json   ← tenant enabled sources
docs/sample/product_source_url_templates.sample.json ← URL 模板（本階段新增）

core/product_source/
  ProductSourceRegistry.php
  UrlTemplateRegistry.php
  UrlTemplateResolver.php
  MultiSourceSearchUrlBuilder.php
  ProductSourceUrlResult.php
```

**既有（未修改）：** `core/search/SearchUrlBuilder.php`、`core/short_url_service.php`

---

## 3. ProductSourceRegistry 關聯

| 用途 | 方法 |
|------|------|
| 解析 source 定義 | `getCatalogSource` / `getEnabledSource` |
| 租戶啟用清單 | `getEnabledSourcesByCategory($category)` |
| 模板 id | `ProductSourceDefinition::getUrlTemplateId()` |

`MultiSourceSearchUrlBuilder::buildAllForTenant()` 依 registry 遍歷啟用源。

---

## 4. UrlTemplateRegistry

| 項目 | 說明 |
|------|------|
| **檔案** | `docs/sample/product_source_url_templates.sample.json` |
| **類別** | `UrlTemplateRegistry` |
| **欄位** | `template_id`, `kind`（`search` \| `detail`）, `url`（含 `{placeholder}`） |

**MVP 模板 id：**

| template_id | 商品源 |
|-------------|--------|
| `bbcshops_search_v1` | bbcshops 搜尋 |
| `bbcshops_detail_v1` | bbcshops 詳情 |
| `grp_search_v1` | GRP |
| `bbctravel_search_v1` | BBC Travel |
| `tourcenter_search_v1` | TourCenter |

---

## 5. ProductSourceUrlResult

| 欄位 | 類型 | 說明 |
|------|------|------|
| `source_id` | string | 商品源 |
| `product_category` | string | 如 `group_tour` |
| `search_url` | string\|null | 長搜尋 URL |
| `detail_url` | string\|null | 長詳情 URL（不支援則 null） |

---

## 6. MultiSourceSearchUrlBuilder

| 方法 | 說明 |
|------|------|
| `buildSearchUrl($sourceId, $keyword, $category, $tenantSno, $registry?)` | 單源搜尋 URL |
| `buildDetailUrl(..., $detailContext?)` | 單源詳情 URL |
| `buildForSource(...)` | 搜尋 + 詳情 |
| `buildAllForTenant($registry, $keyword, $category)` | 租戶啟用源批次 |

**Placeholder（sample）：** `{sno}`, `{keyword}`, `{area_no}`, `{category_code}`, `{category_slug}`, `{tour_seq_no}`

---

## 7. Search URL Strategy

| source_id | 範例（long） |
|-----------|--------------|
| **bbcshops** | `https://bonusmee.com/view/cloud/cloud_store_tourdate.php?openExternalBrowser=1&sno={sno}&keyword={keyword}` |
| **grp** | `http://dayitravel.grp.com.tw/Exhibition.aspx?area_no={area_no}` |
| **bbctravel** | `https://dayitravel.bbctravel.com.tw/searchlist/tpe/{category_code}` |
| **tourcenter** | `https://dayitravel.tourcenter.com.tw/category/zh-tw/travel/{category_slug}` |

`area_no` / `category_code` / `category_slug` 由 builder 內 **sample 對照表** 產生（非真實 API）。

---

## 8. Detail URL Strategy

| source_id | supports_detail | MVP |
|-----------|-----------------|-----|
| **bbcshops** | true | `cloud_store_tourdetail.php?...&tourSeqNo={tour_seq_no}` |
| **grp** | false | `detail_url = null` |
| **bbctravel** | false | null |
| **tourcenter** | false | null |

Detail 模板 id 規則：`{url_template_id}` 中 `_search_` → `_detail_`（若 registry 存在）。

---

## 9. Future Short URL Integration

**Phase 9-B-3（規劃）：**

```
MultiSourceSearchUrlBuilder → long URLs
    ↓
ProductSourceUrlPublisher（新）
    ↓
ShortUrlService（依 catalog short_url_*）
    ↓
LINE / Formatter
```

**不修改** `short_url_service.php` 契約；僅在上層呼叫。

---

## 10. Future GCS Integration

| 檔案 | GCS 路徑（規劃） |
|------|------------------|
| Catalog | `shared/config/product_source_catalog.json` |
| Templates | `shared/config/product_source_url_templates.json` |
| Tenant | `tenants/{sno}/config/product_sources.json` |

Loader 路徑注入即可；**Builder 不變**。

---

## 11. Future BATS Integration

**規劃接入點（不改 saas_router 本階段）：**

`TourPromptContextService` Host B 路徑之後：

```php
// 概念
$results = $builder->buildAllForTenant($registry, $keyword, 'group_tour');
$apiResult['search_urls'] = array_map(fn ($r) => $r->toArray(), $results);
```

保留既有 `search_url` 單一欄位向後相容。

---

## 12. Future Product Categories

| category | category_code (sample) | category_slug (sample) |
|----------|------------------------|-------------------------|
| group_tour | tpe | group-tour-travel |
| fit | fit | fit-travel |
| hotel | hotel | hotel-travel |
| car_rental | car | car-rental-travel |
| private_group | private | private-group-travel |
| other | other | other-travel |

新增類別：**catalog + template 對照 + adapter**，不需改 Hybrid 核心。

---

## 13. Low Refactor Principles

| 元件 | Phase 9-B-2 |
|------|-------------|
| `SearchUrlBuilder` | **未改**；bbcshops 模板與其語意對齊 |
| `saas_router` / Hybrid / Gemini | **未改** |
| `TenantResolver` / Host B | **未改** |
| `ProductSourceRegistry` | **未改** 公開 API |

---

## 14. Final Recommendation

### 專題回答

| # | 問題 | 答案 |
|---|------|------|
| **1** | 新增 hotel / car_rental / fit / private_group 是否 catalog+adapter+url template？ | **是** |
| **2** | 是否需改 Hybrid / Tenant Resolver / Context Cache / Host B？ | **否**（本階段） |
| **3** | 低重構？ | **是** |

### 測試

```text
php tests/product_source/test_multi_source_search_url_builder.php
```

### 下一步

**Phase 9-B-3：ShortUrlService Integration** — 在 long URL 之上加 `ProductSourceUrlPublisher`；仍不建議與 9-B-2 混在同一 PR 若需獨立驗收。

---

## 附錄：修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-29 | Phase 9-B-2 MVP |
