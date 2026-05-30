# Product Source Catalog Provider Architecture

**代號：** `PRODUCT_SOURCE_CATALOG_PROVIDER_ARCHITECTURE`

**版本：** Phase 9-B-4（Provider 骨架；**不連 GCS**）

**前置 Commits：** `db77c53`（Registry/Contract）、`6bc1b8a`、`4db514b`

---

## 1. Purpose

將 **Product Source Catalog** 讀取從「硬編碼 local sample 路徑」升級為 **Provider 抽象**，使 2 / 20 / 200 家旅行社未來共用 **同一份 GCS catalog**，Host A 開發仍可用 local sample。

**本階段：** 僅架構 + `LocalCatalogProvider` 實作 + `GcsCatalogProvider` skeleton（`fetch` 拋出 not implemented）。

---

## 2. Current Architecture

```
CatalogProviderFactory
    ├─ MODE_LOCAL → LocalCatalogProvider → docs/sample/product_source_catalog.sample.json
    └─ MODE_GCS   → GcsCatalogProvider (skeleton, no SDK)

ProductSourceCatalogLoader
    → provider.fetchCatalogDocument()
    → ProductSourceCatalogValidator
    → ProductSourceDefinition[]

ProductSourceRegistry::fromLocalFiles()
    → ProductSourceCatalogLoader (default local provider)
    → TenantProductSourceLoader (unchanged; still local sample)
```

---

## 3. Provider Interface

```php
interface ProductSourceCatalogProviderInterface {
    public function fetchCatalogDocument(): array;
    public function getProviderId(): string;
}
```

**回傳：** 原始 catalog 根物件（`schema_version`, `catalog_id`, `sources[]`），**尚未** 轉 DTO。

---

## 4. LocalCatalogProvider

| 項目 | 說明 |
|------|------|
| **provider_id** | `local` |
| **預設路徑** | `docs/sample/product_source_catalog.sample.json` |
| **行為** | `file_get_contents` + `json_decode` |
| **用途** | 開發、CI 單元測試、GCS 不可用時 fallback（未來） |

---

## 5. GcsCatalogProvider

| 項目 | 說明 |
|------|------|
| **provider_id** | `gcs` |
| **預設 object** | `shared/config/product_source_catalog.json`（規劃路徑，未下載） |
| **行為** | `fetchCatalogDocument()` → `RuntimeException` **not implemented** |
| **禁止** | Google Cloud SDK、Service Account、網路、`.env` 變更 |

---

## 6. Factory Pattern

```php
CatalogProviderFactory::create('local', ?$path);
CatalogProviderFactory::create('gcs', null, ?$gcsObjectPath);
CatalogProviderFactory::createDefault(); // local sample
```

**未來切換：** 僅需改 **Factory 建立參數** 或設定注入（如 `catalog_provider_mode=gcs`），不需改 Validator / Definition / Registry 邏輯。

---

## 7. ProductSourceCatalogLoader Integration

| 變更 | 說明 |
|------|------|
| 建構子 | 可注入 `ProductSourceCatalogProviderInterface`；預設 `CatalogProviderFactory::createDefault()` |
| `load(?$path)` | 若提供 `$path`，本次使用 `LocalCatalogProvider($path)`；否則用注入的 provider |
| 回傳 | 新增 `provider_id` 欄位供稽核 |

**向後相容：** 既有 `load($catalogPath)` 測試與 `ProductSourceRegistry::fromLocalFiles()` **無需修改**。

---

## 8. Future GCS Strategy

| 步驟 | 內容 |
|------|------|
| 1 | 實作 `GcsCatalogProvider::fetchCatalogDocument()`（JSON 下載） |
| 2 | 設定：`gs://bbc-travel-data-center/shared/config/product_source_catalog.json` |
| 3 | Factory 依環境變數或 config flag 選 `gcs`（**不** 在本階段改 `.env`） |
| 4 | 部署授予 Host A 讀取 bucket 權限 |

---

## 9. Future Cache Strategy

```
GCS fetch (periodic or on startup)
    ↓
write Host A cache/shared/product_source_catalog.json
    ↓
LocalCatalogProvider(cache path) when GCS down
```

對齊 Phase 9-A.5：`cache/` 作執行層，Provider 作權威來源選擇。

---

## 10. Future Tenant Catalog Strategy

| 檔案 | Provider（規劃） |
|------|------------------|
| `tenants/{sno}/config/product_sources.json` | `TenantProductSourcesProvider`（9-B-5） |
| 與 catalog provider **分離** | catalog = 平台；tenant = 啟用子集 |

---

## 11. Future Shared Catalog Strategy

| 層 | 路徑 |
|----|------|
| **正式** | GCS `shared/config/product_source_catalog.json` |
| **Git** | `config/product_source/product_source_catalog.default.json` fallback |
| **樣本** | `docs/sample/` 開發用 |

200 家旅行社 **共用一份 catalog**；差異在 tenant `product_sources.json`。

---

## 12. Low Refactor Principles

| 元件 | Phase 9-B-4 |
|------|-------------|
| `ProductSourceRegistry` | **未改** |
| `MultiSourceSearchUrlBuilder` | **未改** |
| `ProductSourceUrlPublisher` | **未改** |
| `ProductSourceCatalogValidator` | **未改** |
| Hybrid / saas_router / Host B | **未改** |

---

## 13. Final Recommendation

### 專題回答

| # | 問題 | 答案 |
|---|------|------|
| **1** | Local → GCS 是否只需 Factory 設定？ | **是**（+ 實作 GcsCatalogProvider 下載） |
| **2** | 影響 Registry / Url Builder / Publisher？ | **否**（仍吃 `ProductSourceDefinition`） |
| **3** | 改 Hybrid / Tenant Resolver / Context Cache / Host B？ | **否** |
| **4** | 低重構？ | **是** |

### 測試

```text
php tests/product_source/test_catalog_provider_factory.php
```

### 下一步

**Phase 9-B-5：Tenant Product Sources GCS** — 建議進行；對稱建立 `TenantProductSourcesProvider` + `TenantProductSourceLoader` 注入。

---

## 附錄：修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-29 | Phase 9-B-4 初版 |
