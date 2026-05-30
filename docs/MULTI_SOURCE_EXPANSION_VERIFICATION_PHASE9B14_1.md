# Phase 9-B-14.1: travel_b Source Instance Expansion Verification

## 1. 驗證目標

證明新增第二家旅行社 **travel_b**（`tenant_sno=5f99b8d665e8444d`）時：

```
新增旅行社 → 新增 Source Instance 設定 → 註冊至 MultiSourceSearchUrlBuilderRegistry
```

**無需修改**下列核心程式即可參與多來源搜尋 URL 產生：

- `MultiSourceSearchUrlBuilder`
- `SearchUrlBuilder`
- `RegionKeywordMapper`
- `SearchConditionContract`

## 2. travel_b Source Instance

| tenant_instance | 說明 | platform（設定檔） |
|-----------------|------|-------------------|
| `travel_b_grp` | GRP 類搜尋通道 | `ext_search_alpha`（測試用平台代號） |
| `travel_b_bbctravel` | BBCTravel 類通道 | `bbctravel` |
| `travel_b_private` | 私人團 / 聯盟參數通道 | `ext_search_beta` |
| `travel_b_gcs` | 固定目錄 / GCS 入口 | `ext_search_gamma` |

以上皆為 **SearchUrlBuilderRegistry** 內的 instance 設定；核心 Builder 僅讀取 registry，不認識 `travel_b` 字串。

## 3. 測試案例

### Case 6（9-B-14.1）

**Registry：**

- `travel_b_grp`
- `travel_b_bbctravel`
- `travel_b_private`
- `travel_b_gcs`

**條件：**

```json
{
  "keyword": "東京",
  "product_category": "group_tour"
}
```

**預期：** 回傳 **4 筆** `{ platform, tenant_instance, search_url }`。

### 擴充驗證（`test_multi_source_expansion_verification.php`）

1. 核心檔案不得含 `travel_b` / `grp` / `bbctravel` / `gcs` 等字串常值  
2. `travel_c` / `travel_d` / `travel_e` 僅透過 `merge_agency_source_instances()` 新增 instance 即可產生 URL  

## 4. Builder 相依分析

```
SearchCondition (keyword, product_category)
        ↓
MultiSourceSearchUrlBuilderRegistry  ← 只新增 tenant_instance 鍵
        ↓
MultiSourceSearchUrlBuilder        ← 不變
        ↓
RegionKeywordMapper (platform scope) ← 不變
        ↓
SearchUrlBuilderRegistry           ← 新增 instance + 沿用既有 platform/template
        ↓
SearchUrlBuilder                   ← 不變
        ↓
SourceInstanceUrlTemplateBuilder   ← 不變
```

## 5. 是否需要修改核心程式

| 項目 | 是否需要改核心 |
|------|----------------|
| 新增 travel_b 四家 instance | **否** — 僅設定檔 / registry |
| 註冊 travel_b 至 multi-source 清單 | **否** — `registerSourceInstance()` |
| keyword=東京 產生 4 URL | **否** |
| 未來 travel_c / d / e | **否** — 同模式 merge instance |

## 6. 20~200 家旅行社擴充評估

| 面向 | 評估 |
|------|------|
| 新增旅行社 | 每社 N 筆 `tenant_instance` + `tenant_sno` |
| 程式變更 | Multi-source 迴圈 O(n)，n = 註冊 instance 數 |
| 平台差異 | 由 platform/template 設定驅動，非 if-else 硬編碼 |
| AgentTour RegionCode | 仍僅 agenttour scope，不污染其他平台 |
| 營運建議 | Instance 清單可由 GCS/JSON 載入（Phase 9-B-15+） |

## 7. 結論

**已驗證：** 新增 travel_b 只需新增 **Source Instance** 設定並註冊至 **MultiSourceSearchUrlBuilderRegistry**，不需修改 `MultiSourceSearchUrlBuilder`、`SearchUrlBuilder`、`RegionKeywordMapper`、`SearchConditionContract` 核心程式。

符合：

- Config Driven  
- Template Driven  
- Source Instance Driven  
- Platform Scoped Mapping  
- 20~200 家旅行社橫向擴充  

## 測試指令

```powershell
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\product_sources\test_multi_source_search_url_builder.php
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\product_sources\test_multi_source_expansion_verification.php
```

## 相關檔案

| 檔案 | 用途 |
|------|------|
| `tests/product_sources/multi_source_test_fixtures.php` | 共用設定 fixture（含 travel_b_bbctravel） |
| `tests/product_sources/test_multi_source_search_url_builder.php` | Case 1–6 |
| `tests/product_sources/test_multi_source_expansion_verification.php` | 核心無硬編碼 + travel_c/d/e 模擬 |
