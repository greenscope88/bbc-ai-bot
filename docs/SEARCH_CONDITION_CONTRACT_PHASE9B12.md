# Search Condition Contract — Phase 9-B-12

**代號：** `SEARCH_CONDITION_CONTRACT_PHASE9B12`

**版本：** Phase 9-B-12

**前置 Commit：** `eb60b06`（Search URL Builder Core）

---

## 1. 目標

建立 **`SearchConditionContract`** 作為 BATS 未來 **統一搜尋條件格式**（platform-agnostic document）。

供 SearchUrlBuilder、Keyword Mapper、Publisher、Host B 橋接層共用，**不綁定** agenttour / grp / Host B / Gemini / LINE OA。

---

## 2. 設計原則

| 原則 | 說明 |
|------|------|
| Config Driven | 以 JSON/array document 描述條件，非硬編碼平台 |
| Platform Agnostic | `platform` / `tenant_instance` 為選填路由欄位 |
| Category Contract | `product_category` 委派 `ProductCategoryContract` |
| 與 Hybrid 分離 | 不修改 `core/search/SearchCondition.php`（Hybrid 專用 DTO） |

---

## 3. 架構位置

```
SearchConditionContract (document schema)
        ↓
SearchConditionValidator
        ↓
SearchUrlBuilder / Keyword Mapper (future)
        ↓
Publisher (future)
```

---

## 4. Document 欄位（選填為主）

| 欄位 | 說明 |
|------|------|
| `schema_version` | 契約版本（預設 1） |
| `keyword` | 搜尋關鍵字（如 東京） |
| `product_category` | `ProductCategoryContract` enum |
| `destination` | 目的地 |
| `region_code` | 區域代碼（如 J、C） |
| `region_name` | 區域名稱 |
| `area` | 區域 / 地區 |
| `departure_city` | 出發地 |
| `date_from` / `date_to` | `YYYY-MM-DD` |
| `budget_min` / `budget_max` | 預算區間 |
| `platform` / `tenant_instance` | 商品源路由（選填） |
| `travel_style` / `special_tags` / `free_text` | 擴充保留 |

**至少需一項搜尋條件**（如 `keyword` 非空）。

---

## 5. 錯誤碼

| 錯誤碼 | 說明 |
|--------|------|
| `SEARCH_CONDITION_INVALID_BUDGET_RANGE` | `budget_max < budget_min` |
| `SEARCH_CONDITION_INVALID_DATE_RANGE` | `date_from > date_to` |
| `SEARCH_CONDITION_INVALID_PRODUCT_CATEGORY` | 未知 `product_category` |
| `SEARCH_CONDITION_INVALID_INPUT` | 其他驗證失敗 |

---

## 6. 核心類別

| 類別 | 路徑 |
|------|------|
| `SearchConditionContract` | `core/product_source/SearchConditionContract.php` |
| `SearchConditionValidator` | `core/product_source/SearchConditionValidator.php` |
| `SearchConditionContractException` | `core/product_source/SearchConditionContractException.php` |

---

## 7. 測試案例

```text
php tests/product_sources/test_search_condition_contract.php
```

| Case | 輸入 | 預期 |
|------|------|------|
| 1 | `keyword=東京` | PASS |
| 2 | 完整條件 | PASS |
| 3 | `budget_max < budget_min` | FAIL `SEARCH_CONDITION_INVALID_BUDGET_RANGE` |
| 4 | `date_from > date_to` | FAIL `SEARCH_CONDITION_INVALID_DATE_RANGE` |
| 5 | 未知 `product_category` | FAIL `SEARCH_CONDITION_INVALID_PRODUCT_CATEGORY` |

---

## 8. 與 Phase 9-B-11 關係

| 層 | 職責 |
|----|------|
| SearchConditionContract | **什麼條件**（keyword、日期、預算、類別） |
| SearchUrlBuilder | **哪個 URL**（registry + template） |
| SearchKeywordMapper（未來） | keyword → region_code 等映射 |

---

## 9. Out of Scope

- SQL / `.env` / GCS
- Hybrid Search / Gemini / LINE OA / Host B 修改
- keyword → RegionCode 實作（Phase 9-B-13+）

---

## 附錄：修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-30 | Phase 9-B-12 初版 |
