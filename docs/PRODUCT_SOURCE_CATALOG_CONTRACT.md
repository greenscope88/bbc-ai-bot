# Product Source Catalog Contract

**代號：** `PRODUCT_SOURCE_CATALOG_CONTRACT`  
**版本：** Phase 9-B-1b  
**機器可讀 Schema：** [config/product_source/product_source_catalog.schema.json](../config/product_source/product_source_catalog.schema.json)  
**執行期驗證：** `core/product_source/ProductSourceCatalogValidator.php`

---

## 1. Contract Purpose

本 Contract 定義 **平台商品源目錄**（`product_source_catalog.json`）的正式欄位、列舉與驗證規則，使未來 **2 / 20 / 200 家旅行社** 新增商品源時，維持：

```
config（catalog + tenant product_sources）
    +
registry（ProductSourceRegistry）
    +
adapter（per source_id，如 BbcshopsAdapter）
```

**本 Contract 不涵蓋：** GCS 同步、SearchUrlBuilder、LINE、Hybrid、Gemini、Host B HTTP 契約變更。

---

## 2. Schema Overview

**根物件：**

| 欄位 | 類型 | 必填 |
|------|------|------|
| `schema_version` | integer | ✅（目前僅 `1`） |
| `catalog_id` | string | ✅ |
| `sources` | array | ✅（≥1） |

**每個 `sources[]` 項目：** 見 §3。

**範例檔：** [docs/sample/product_source_catalog.sample.json](../docs/sample/product_source_catalog.sample.json)

---

## 3. Required Fields

### 3.1 根層

| 欄位 | 說明 |
|------|------|
| `schema_version` | 契約主版本；Loader 僅接受 `ProductSourceCatalogContract::SUPPORTED_SCHEMA_VERSIONS` |
| `catalog_id` | 平台 catalog 識別（如 `bbc_travel_product_sources_v1`） |
| `sources` | 商品源定義陣列 |

### 3.2 每個 source（必填）

| 欄位 | 類型 | 說明 |
|------|------|------|
| `source_id` | string | 穩定鍵；`^[a-z][a-z0-9_]{0,63}$`；**不可重複** |
| `source_name` | string | 顯示名稱（`display_name` 為相容別名） |
| `source_type` | enum | §6 |
| `product_categories` | string[] | ≥1；值為 §5 enum（`product_category` 單數為相容別名） |
| `adapter` | string | §7；須在 `KNOWN_ADAPTERS` |
| `url_template_id` | string | URL 模板識別；`^[a-z][a-z0-9_]{0,127}$` |
| `enabled` | boolean | catalog 層是否啟用 |
| `priority` | integer | catalog 預設排序 0–9999（租戶可覆寫） |
| `short_url_enabled` | boolean | §8 |
| `short_url_domain` | string | `short_url_enabled=true` 時必填 |
| `domain_namespace` | string | `short_url_enabled=true` 時必填 |
| `supports_search` | boolean | §9 |
| `supports_detail` | boolean | §9 |
| `supports_inventory` | boolean | §9 |
| `supports_registration_flow` | boolean | §9 |
| `supports_google_sheet_registration` | boolean | §9；僅 `source_type=storefront` 允許 `true` |

---

## 4. Enum Definition

### 4.1 `product_categories[]` / `product_category`

| 值 | 說明 |
|----|------|
| `group_tour` | 團體行程（現行主力） |
| `hotel` | 飯店 |
| `car_rental` | 租車 |
| `fit` | 自由行 |
| `private_group` | 小包團 |
| `other` | 其它 |

### 4.2 `source_type`

| 值 | 說明 |
|----|------|
| `host_b` | Host B API 搜尋／庫存（內部） |
| `catalog` | 平台目錄／彙總層 |
| `storefront` | 站內 storefront（如 bbcshops / cloud_store） |
| `external` | 外部第三方搜尋 URL |
| `future` | 占位；搭配 `StubProductSourceAdapter` |

---

## 5. Product Category Strategy

| 原則 | 說明 |
|------|------|
| **多類別** | 單一 `source_id` 可支援多個 `product_categories`（如 bbctravel：`group_tour` + `fit`） |
| **租戶子集** | `tenants/{sno}/product_sources.json` 可只啟用其中部分 category |
| **擴充** | 新增 `hotel` 等 **不需改** Hybrid 核心；新增 catalog 列 + adapter + tenant 啟用 |

---

## 6. Source Type Strategy

| source_type | 典型 source_id | adapter |
|-------------|----------------|---------|
| `storefront` | `bbcshops` | `BbcshopsAdapter` |
| `external` | `grp`, `bbctravel`, `tourcenter` | `GrpAdapter`, `BbcTravelAdapter`, `TourCenterAdapter` |
| `host_b` | （預留） | `HostBAdapter` |
| `future` | 預留列 | `StubProductSourceAdapter` |

**決策：** 以 **語意分類**（內部／外部／未來）區分，而非 `internal_storefront` 等 ad-hoc 字串。

---

## 7. Adapter Strategy

### 7.1 命名規範

| 規則 | 範例 |
|------|------|
| PascalCase + `Adapter` 後綴 | `BbcshopsAdapter` |
| 與 `source_id` 對應但不必同名 | `grp` → `GrpAdapter` |
| 未實作 / 占位 | `StubProductSourceAdapter` |

### 7.2 已註冊 adapter（`KNOWN_ADAPTERS`）

- `BbcshopsAdapter`
- `GrpAdapter`
- `BbcTravelAdapter`
- `TourCenterAdapter`
- `HostBAdapter`
- `StubProductSourceAdapter`

**新增商品源流程：** 實作 adapter 類 → 將名稱加入 `ProductSourceCatalogContract::KNOWN_ADAPTERS` → catalog JSON 引用。

---

## 8. Short URL Strategy

| 欄位 | 規則 |
|------|------|
| `short_url_enabled` | 是否對該源搜尋 URL 做短鏈 |
| `short_url_domain` | 對外顯示 host（如 `bbcshops.com`） |
| `domain_namespace` | 邏輯命名空間（vhost / 統計） |

**驗證：** `short_url_enabled=true` 時，`short_url_domain` 與 `domain_namespace` **不可空**。

**執行：** ShortUrlService 整合屬 Phase 9-B-3+；本 Contract 僅定義欄位。

---

## 9. Capability Strategy

| 欄位 | 意義 |
|------|------|
| `supports_search` | 可產生搜尋列表 URL |
| `supports_detail` | 可產生商品詳情連結 |
| `supports_inventory` | 可對接即時庫存（通常 Host B） |
| `supports_registration_flow` | 站內報名流程 |
| `supports_google_sheet_registration` | Google Sheet 旅客名單（**僅 storefront**） |

**業務規則（驗證器強制）：**

- `supports_google_sheet_registration=true` ⇒ `source_type` 必須為 `storefront`。

---

## 10. Validation Rules

由 `ProductSourceCatalogValidator` 執行（Loader 預設開啟）：

| # | 規則 |
|---|------|
| V1 | 根層必填欄位存在 |
| V2 | `schema_version` 在支援清單內 |
| V3 | 每個 source 必填欄位存在（含 `source_name` 或 `display_name`） |
| V4 | `source_id` 格式合法且 **不重複** |
| V5 | `product_categories` 非空且皆為合法 enum |
| V6 | `source_type` 為合法 enum |
| V7 | `adapter` 在 `KNOWN_ADAPTERS` |
| V8 | `url_template_id` 格式合法 |
| V9 | `enabled`、capabilities、short_url 欄位為 boolean |
| V10 | `priority` 為 integer 0–9999 |
| V11 | short_url 啟用時 domain / namespace 必填 |
| V12 | Sheet 報名能力僅 storefront |

**失敗：** 拋出 `ProductSourceCatalogValidationException`（`errorCode=CATALOG_CONTRACT_INVALID`）；Loader 包裝為 `RuntimeException`。

---

## 11. Error Handling

| 層級 | 行為 |
|------|------|
| **Validator** | 收集全部 violations 一次回報 |
| **Loader** | 驗證失敗不建立 `ProductSourceDefinition` |
| **Registry** | 不修改；上游 Loader 失敗即中止 |
| **Runtime（未來）** | 可選 `product_source_registry_enabled`；失敗時 fallback Git default catalog（Phase 9-A.5） |

**不建議：** 略過驗證靜默載入壞 JSON（僅測試可 `validateContract=false`）。

---

## 12. Versioning Strategy

| 版本 | 說明 |
|------|------|
| `schema_version: 1` | 本 Contract |
| **向後相容** | 新增 optional 欄位 → minor doc 更新，仍為 v1 |
| **破壞性變更** | 刪必填欄位 / 改 enum → `schema_version: 2` + Validator 分支 |

**catalog_id：** 營運可獨立於 `schema_version` 追蹤部署批次。

---

## 13. Future Expansion Rules

| 情境 | 作法 |
|------|------|
| 新商品源（如 AgentTour） | catalog 新增列 + adapter + tenant 啟用 |
| 新類別 `hotel` | `product_categories` 加 `hotel`；新 adapter |
| 新 adapter | 加入 `KNOWN_ADAPTERS` + 實作類 |
| 200 家旅行社 | **共用** catalog；每社 `product_sources.json` |
| GCS 正式檔 | 路徑不變；內容須通過本 Validator |

---

## 14. Low Refactor Principles

| 元件 | Phase 9-B-1b |
|------|----------------|
| `ProductSourceRegistry` | 不變介面；Definition 增加欄位 |
| `TenantProductSourceLoader` | 不變（tenant 檔另案 contract） |
| Hybrid / TenantResolver / Context Cache | **不修改** |
| Host B API | **不修改** |
| `saas_router` | **不修改** |

---

## 15. Final Recommendation

### 專題回答

| # | 問題 | 答案 |
|---|------|------|
| **1** | 新增 hotel / car_rental / fit / private_group 是否仍 catalog+registry+adapter？ | **是** |
| **2** | 是否需改 Hybrid / Tenant Resolver / Context Cache / Host B？ | **否**（本階段） |
| **3** | 能否維持低重構？ | **是** |

### 下一步

- **9-B-2：** `TenantProductSource` contract + tenant 檔驗證  
- **9-B-3：** `BbcshopsAdapter` / `MultiSourceSearchUrlBuilder`（仍不碰 saas_router 直至編排層設計完成）

---

## 附錄：修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-29 | Phase 9-B-1b 初版 Contract |
