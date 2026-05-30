# BBC 旅業資料中心 — Phase 9-A MVP 實作計畫（唯讀盤點與低重構評估）

**副標：** Product Source Registry / Multi-Source Search — 可行性評估與 Phase 9-B 建議

**文件代號：** `TRAVEL_DATA_CENTER_PHASE9_MVP_IMPLEMENTATION_PLAN`

**版本：** Phase 9-A（規劃 only · 唯讀盤點）

**狀態：** Planning — 本文件不觸發程式、JSON、cache、SQL、Git 變更

**規劃主機：** 主機 A `103.1.222.14` · 專案路徑 `C:\bbc-ai-bot`

**關聯文件：**

- [TRAVEL_DATA_CENTER_PHASE8_GEMINI_CONTEXT_CACHE_DESIGN.md](./TRAVEL_DATA_CENTER_PHASE8_GEMINI_CONTEXT_CACHE_DESIGN.md)（Phase 8，含 §13 附錄 C）
- [BBC_TRAVEL_DATA_CENTER_SOURCE_ADAPTER_PLAN.md](./BBC_TRAVEL_DATA_CENTER_SOURCE_ADAPTER_PLAN.md)（Phase 7）
- [BBC_TRAVEL_DATA_CENTER_GOOGLE_SHEET_SYNC_FLOW_PLAN.md](./BBC_TRAVEL_DATA_CENTER_GOOGLE_SHEET_SYNC_FLOW_PLAN.md)（Phase 6）

**盤點日期：** 2026-05-29

**盤點方式：** 唯讀檢視 `core/`、`config/`、`tests/` 與 Phase 1～8 規劃文件；**未修改任何程式**。

---

## 1. Phase 9-A 目標

| 目標 | 說明 |
|------|------|
| **唯讀盤點** | 確認現有 Hybrid / BATS / URL / Formatter / Tenant 程式與 Phase 8 附錄 C 對齊程度 |
| **低重構評估** | 評估未來 **1～200 家旅行社**、每社新增商品源時，修改面積能否維持 **小** |
| **MVP 計畫** | 產出 Phase 9-B 實作順序、首改檔案、風險與 **不可大改** 之核心流程 |
| **本階段不做** | 不寫 PHP、不建 JSON、不建 `cache/`、不執行 SQL、不 git |

---

## 2. 現有程式盤點結果

### 2.1 SearchUrlBuilder 相關（§盤點項 1）

| 檔案 | 路徑 | 現況 |
|------|------|------|
| **SearchUrlBuilder** | `core/search/SearchUrlBuilder.php` | ✅ **可重用 URL Builder**；單一硬編碼基底 `bonusmee.com/.../cloud_store_tourdate.php` |
| **SearchCondition** | `core/search/SearchCondition.php` | ✅ 已有 `keyword`、`destination`、`date_from`/`date_to`、`departure_city` |
| **SearchConditionCanonicalizer** | `core/search/SearchConditionCanonicalizer.php` | ✅ 與 API / URL 共用 canonical |
| **ApiQueryMapper** | `core/search/ApiQueryMapper.php` | Host B 查詢參數映射（與 URL 同源） |
| **測試** | `tests/hybrid_search/test_search_url_builder.php` | 單源 URL 測試 |

**評估：多商品源 URL template**

| 能力 | 現況 | 缺口 |
|------|------|------|
| `tenant_sno` | ✅ `build($sno, $condition)` | 無 |
| `keyword` / `destination` / `date` | ✅ 經 `SearchCondition` + canonical | 無 |
| **`product_category`** | ❌ 不存在 | 需新增參數或 `SearchCondition` 擴充 |
| **`source_type` / `source_id`** | ❌ 不存在 | 需 **Adapter** 或 template registry |
| **多 URL 同時輸出** | ❌ 僅回傳 **單一 string** | 需 `MultiSourceSearchUrlBuilder` facade 回傳 `urls[]` |

**結論：** 現有 `SearchUrlBuilder` 是 **bbcshops/bonusmee（cloud_store_tourdate）專用** 的良好基礎；擴充多源應採 **組合模式**（保留類別、新增 per-source adapter），**不宜** 在單類內堆疊 200 個 if-else。

---

### 2.2 Tenant Resolver / Tenant Registry（§盤點項 2）

| 檔案 | 路徑 | 現況 |
|------|------|------|
| **tenant_registry.php** | `config/tenant_registry.php` | `travel_a` sno=`e1fd133c7e8e45a1`；`travel_b` sno=`5f99b8d665e8444d` |
| **ConfigTenantRegistry** | `core/tenant/ConfigTenantRegistry.php` | `resolveByChannel`、`resolveBySno`、`getAllTenants()` |
| **TenantResolver** | `core/tenant_resolver.php` | Registry **優先**，再 fallback DB / legacy map |
| **SaaSRouter** | `core/saas_router.php` L162 | `TenantResolver::resolve($pdo, $event, $config)` → `$tenant['sno']` |

**travel_b 對應：** ✅ `5f99b8d665e8444d` 已於 registry 與測試（`tests/tenant/test_travel_b_staging_readiness.php`）驗證。

**未來 `tenants/{sno}/config/product_sources.json`：**

| 項目 | 評估 |
|------|------|
| registry 是否需改結構 | **短期不必**；`product_sources` 可放 **獨立 GCS/本機 cache**，以 `sno` 關聯 |
| 200 家擴充 | 每社一筆 `tenants/{sno}/config/product_sources.json` + 本機 mirror；**不需** 改 `tenant_registry.php` 每加一家（僅 onboarding 時加 tenant 列） |

**結論：** Tenant 解析層 **適合** 作為 Product Source 的 **索引鍵**；商品源清單與 **tenant 主檔分離** 可維持低重構。

---

### 2.3 LINE OA 回覆流程 / Formatter（§盤點項 3）

| 階段 | 檔案 | 職責 |
|------|------|------|
| Webhook 入口 | `core/saas_router.php` | Tenant、Intent、Tour context、Gemini、LINE reply |
| 行程 context | `core/tour_prompt_context_service.php` | Hybrid → Host B → `GeminiTourContextBuilder` |
| Context 文字 | `core/gemini_tour_context_builder.php` | 組裝行程列表 + **單一** `SEARCH_URL_LABEL` + `search_url` |
| 回覆編排 | `core/tour_line_reply_composer.php` | fixed formatter 或 Gemini |
| 固定格式 | `core/tour_fallback_formatter.php` | 解析 context，**extractSearchUrl** → 文末 **一條** URL |

**search_url 產生位置：**

```
HybridSearchConditionBuilder → SearchCondition
    → TourSearchApiClient (Host B)
    → TourPromptContextService::resolveSearchUrlForApiResult()
        → SearchUrlBuilder::build() 或 buildStorefrontListingUrl()
    → apiResult['search_url']
    → GeminiTourContextBuilder::appendSearchUrlLines()  // 單一 URL 進 context
    → TourFallbackFormatter::extractSearchUrl()         // 單一 URL 進 LINE
```

**評估：加入更多商品源連結**

| 方式 | 重構面積 | 建議 |
|------|----------|------|
| **A. 擴充 context 為多 URL 區塊** | **小～中** | 在 `GeminiTourContextBuilder` / `TourFallbackFormatter` 增加 **可選** `search_links[]` 區段；固定 label 迴圈輸出 |
| **B. 改 Formatter 完全重寫** | **大** | ❌ 不建議 |
| **C. 僅 Gemini 動態帶連結** | **中** | 不穩定；與 fixed formatter 策略衝突 |

**結論：** **適合** 在 context / formatter 增加 **結構化多連結區塊**（2～5 條），**不需** 大改 fixed formatter 主邏輯（仍解析既有行程區塊）。

---

### 2.4 Hybrid Search / BATS 流程（§盤點項 4）

**現有商品搜尋資料流：**

```
LINE message
  → saas_router (BATS)
  → TourPromptContextService::buildTourContextForPrompt
      → TourQueryIntentDetector
      → HybridSearchFeatureGate::isEnabled
      → HybridSearchConditionBuilder::parse(userText)
      → ApiQueryMapper → TourSearchApiClient → Host B API
      → SearchUrlBuilder → search_url（覆寫 API 回傳）
      → GeminiTourContextBuilder::build(apiResult)
  → TourLineReplyComposer
```

| 元件 | 檔案 | 可變更性 |
|------|------|----------|
| Hybrid 條件解析 | `HybridSearchConditionBuilder.php` | **不建議大改** |
| Host B 客戶端 | `tour_search_api_client.php` | 保持單一 Host B 商品搜尋 |
| Feature gate | `HybridSearchFeatureGate.php` + registry `features.hybrid_search` | 每 tenant 開關 |

**Product Source Registry 最適合接入層：**

| 優先序 | 接入點 | 理由 |
|--------|--------|------|
| **1（推薦）** | **`TourPromptContextService` 末尾**（Host B 成功後） | 已有 `sno`、`SearchCondition`；可並行產生 **額外** 商品源 URL，不動 Hybrid 核心 |
| **2** | 新增 **`ProductSourceOrchestrator`**，由 `saas_router` 在 tour context 前後呼叫 | BATS 編排清晰；與 Phase 8 C.7 一致 |
| **3（不推薦）** | 塞入 `HybridSearchConditionBuilder` | 職責混淆（解析 vs 來源路由） |

**結論：** Registry 應作 **BATS 編排層** 或 **TourPromptContextService 衛星模組**，**不** 放入 Hybrid 條件解析核心。

---

### 2.5 Gemini Context Cache / Knowledge Context（§盤點項 5）

對照 [TRAVEL_DATA_CENTER_PHASE8_GEMINI_CONTEXT_CACHE_DESIGN.md](./TRAVEL_DATA_CENTER_PHASE8_GEMINI_CONTEXT_CACHE_DESIGN.md) §13：

| 邊界 | 程式現況 | 結論 |
|------|----------|------|
| Context Cache 不參與 URL | 程式 **尚無** Context Cache；僅 GCS/知識規劃 | ✅ 設計已明確；實作時 **禁止** URL Builder 讀 cache |
| Product Registry ≠ Context Cache | 無 Product Registry 程式 | ✅ 新模組獨立 |
| 商品 vs 知識 | `TourPromptContextService` 僅 Host B **商品** + 無獨立知識 Adapter | Phase 7 知識層 **未接線**；與商品源擴充 **可並行** |

---

### 2.6 未來商品類別擴充（§盤點項 6）

| 類別 | 現有程式支援 | 擴充方式（建議） | 重構面積 |
|------|--------------|------------------|----------|
| **group_tour** | ✅ 完整（Hybrid + cloud_store_tourdate） | 既有 | — |
| **hotel** | ❌ | catalog + adapter + URL template | **小**（若前置抽象完成） |
| **car_rental** | ❌ | 同上 | **小** |
| **fit** | ❌ | 同上 | **小** |
| **private_group** | ❌ | 同上 | **小** |
| **other** | ❌ | 同上 | **小** |

**不需修改（若依 Phase 8 C.3 檢查清單）：**

| 核心 | 理由 |
|------|------|
| Hybrid Search **主流程** | 仍處理 `group_tour` + Host B；新類別走 **額外 URL / 可選 API** |
| Tenant Resolver | 仍只解析 `sno` |
| LINE Formatter **主結構** | 僅擴充 **連結區塊** |
| Gemini Context Cache | 與商品類別無關 |

---

### 2.7 盤點檔案清單（摘要）

| 類別 | 已盤點檔案 |
|------|------------|
| URL | `core/search/SearchUrlBuilder.php`, `SearchCondition.php`, `SearchConditionCanonicalizer.php`, `ApiQueryMapper.php` |
| Tenant | `config/tenant_registry.php`, `core/tenant/ConfigTenantRegistry.php`, `core/tenant_resolver.php` |
| BATS | `core/saas_router.php` |
| Hybrid | `core/tour_prompt_context_service.php`, `core/search/HybridSearchConditionBuilder.php`, `core/search/HybridSearchFeatureGate.php` |
| Host B | `core/tour_search_api_client.php` |
| Context / Reply | `core/gemini_tour_context_builder.php`, `core/tour_line_reply_composer.php`, `core/tour_fallback_formatter.php` |
| Gate | `core/tour_prompt_feature_gate.php` |
| 測試 | `tests/hybrid_search/test_search_url_builder.php`, `tests/tenant/test_travel_b_staging_readiness.php` |

---

## 3. Product Source Registry 建議接入位置

### 3.1 建議架構

```
config/ 或 GCS shared/config/product_source_catalog.json     ← 平台 catalog
cache/tenants/{sno}/product_sources.json                     ← Host A mirror（Phase 8 C.5）
        ↓
ProductSourceRegistry（新類，core/travel_data_center/ 或 core/product/）
        ↓
ProductSearchUrlAdapterInterface × N（grp, bbctravel, bbcshops, …）
        ↓
MultiSourceSearchUrlBuilder（新 facade，內部可委派既有 SearchUrlBuilder = bbcshops）
        ↓
TourPromptContextService 或 ProductSourceOrchestrator（注入 apiResult / context）
```

### 3.2 首裝點（Phase 9-B）

| 順序 | 位置 | 改動性質 |
|------|------|----------|
| 1 | 新目錄 `core/product_source/`（規劃名） | **純新增** |
| 2 | `TourPromptContextService` **可選** 呼叫 orchestrator | **小改**（feature flag 後） |
| 3 | `GeminiTourContextBuilder` / `TourFallbackFormatter` | **小改**（多連結區塊） |

**不修改：** `HybridSearchConditionBuilder`、`TenantResolver` 核心邏輯。

---

## 4. Product Source Capability 設計建議

### 4.1 能力欄位（建議納入 catalog）

| 欄位 | 類型 | 說明 |
|------|------|------|
| **`supports_search`** | bool | 可否產生搜尋列表 URL |
| **`supports_detail`** | bool | 是否有商品詳情頁 |
| **`supports_inventory`** | bool | 是否提供即時可售／庫存（需 Host B 或 API） |
| **`supports_registration_flow`** | bool | 是否支援站內報名流程 |
| **`supports_google_sheet_registration`** | bool | 是否支援 Google Sheet 收集旅客名單 |

### 4.2 已知商品源能力分級（規劃）

| source_id | 代表 URL / 系統 | supports_search | supports_detail | supports_inventory | supports_registration | supports_google_sheet_registration |
|-----------|-----------------|-----------------|-----------------|--------------------|-----------------------|-------------------------------------|
| **`bbcshops`** / **bonusmee** | `cloud_store_tourdate.php` | ✅ | ✅ | ✅（Host B） | ✅ | ✅ **（目前唯一）** |
| **host_b** | TourSearchApiClient | ✅ | ✅ | ✅ | 視商品 | ❌ |
| **grp** | 外部搜尋頁（規劃） | ✅ | 部分 | ❌ | ❌ | ❌ **預設** |
| **bbctravel** | 外部（規劃） | ✅ | 部分 | ❌ | ❌ | ❌ |
| **tourcenter** | 外部（規劃） | ✅ | 部分 | ❌ | ❌ | ❌ |
| **agenttour** | 外部（規劃） | ✅ | 部分 | ❌ | ❌ | ❌ |
| **future_hotel_provider** | 預留 | ✅ | ✅ | 視 API | 視 API | ❌ |

### 4.3 正式架構決策：旅客名單 Google Sheet

| 項目 | 決策 |
|------|------|
| **資料內容** | 姓名、電話、出生年月日等 |
| **儲存** | **僅** 旅行社私有 Google Sheet |
| **目前適用** | **bbcshops / bonusmee**（`cloud_store_tourdate.php` 相關流程） |
| **GRP / BBC Travel / TourCenter 等** | **預設不支援** `supports_google_sheet_registration`，除非未來另案 |

### 4.4 LINE OA 依能力決定行為（規劃）

| 能力組合 | LINE 行為 |
|----------|-----------|
| 僅 `supports_search` | **1. 只提供搜尋連結** |
| + `supports_detail` | **2. 可提供商品詳情連結**（若 context 有） |
| + `supports_registration_flow` | **3. 可提供報名需求單連結**（站內） |
| + `supports_google_sheet_registration` | **4. 可進入 Sheet 報名流程**（需另觸發，非本次 MVP） |

**MVP 建議：** Phase 9-B 僅實作 **1 + 2（連結輸出）**；3、4 保留 flag，預設 OFF。

---

## 5. Host A Local Cache 建議接入位置

| 項目 | 建議 |
|------|------|
| **正式資料** | GCS `shared/config/product_source_catalog.json`、`tenants/{sno}/config/product_sources.json` |
| **本機 mirror** | `C:\bbc-ai-bot\cache\tenants\{sno}\product_sources.json`（Phase 8 C.5） |
| **載入時機** | SaaSRouter 或 ProductSourceRegistry **第一次** resolve 時；Sync 後 refresh |
| **讀取者** | `ProductSourceRegistry` **只讀本機**；不每請求讀 GCS |
| **禁止寫入** | PII、即時庫存、即時價格、報名人數 |

**與現有程式：** 目前 **無** `cache/tenants/` 目錄；Phase 9-B **新建** loader，不影響既有路徑。

---

## 6. Multi-Source Search URL Builder 建議接入位置

### 6.1 建議類別

| 類別 | 職責 |
|------|------|
| **`MultiSourceSearchUrlBuilder`** | 輸入 `sno`, `SearchCondition`, `product_category`, `enabled_sources[]` → 輸出 `SearchUrlResult[]` |
| **`BbcshopsSearchUrlAdapter`** | 包裝既有 `SearchUrlBuilder`（向後相容） |
| **`TemplateSearchUrlAdapter`** | GRP / TourCenter 等：registry 內 URL template + 參數替換 |

### 6.2 參數對照（Phase 8 C.6）

| 參數 | 現有支援 | 接入點 |
|------|----------|--------|
| `tenant_sno` | ✅ | Registry + Builder |
| `product_category` | ❌ 需新增 | `SearchCondition` 或 Builder 選項 |
| `keyword` / `destination` / `date` | ✅ | 沿用 `SearchCondition` |
| `source_type` | ❌ | per-adapter `source_id` |

### 6.3 接入流程

在 `TourPromptContextService` Host B 路徑 **之後**：

```php
// 概念（Phase 9-B）
$urls = $multiSourceBuilder->buildAll($sno, $condition, [
    'categories' => ['group_tour'],
]);
$apiResult['search_urls'] = $urls;  // 新欄位，保留 search_url 向後相容
```

---

## 7. LINE OA 回覆加入商品源連結的建議方式

### 7.1 最小改動路徑

| 步驟 | 檔案 | 改動 |
|------|------|------|
| 1 | `GeminiTourContextBuilder` | 支援 `search_urls[]`；每條 `{label, url, source_id}` |
| 2 | `TourFallbackFormatter` | `extractSearchUrls()` 迴圈；保留既有單一 URL 相容 |
| 3 | 常數 | 各 `source_id` 顯示名稱（如「GRP 搜尋」「平台搜尋」） |

### 7.2 輸出格式（規劃）

```
🚩 …（行程列表不變）…
──────────────

🔍 更多搜尋方式：
・平台行程搜尋：https://bonusmee.com/.../cloud_store_tourdate.php?...
・GRP 商品搜尋：https://...（若租戶啟用）
```

**限制：** 單則 LINE 訊息長度；建議 **最多 3～5 條** 連結。

---

## 8. 低重構可行性評估

### 8.1 總體結論

| 規模 | 評估 |
|------|------|
| **1～200 家旅行社** | **可行**，若嚴格採 config + registry + adapter |
| **每家新增一個商品源** | **小改**（新增 catalog 條目 + tenant `product_sources.json` + 可選新 adapter） |
| **每家 onboarding** | 仍需 `tenant_registry.php` **一列**（既有流程）；與商品源 **分離** |

### 8.2 重構面積矩陣

| 區域 | 1 家新增源 | 新 category | 200 家僅啟用不同源組合 |
|------|------------|-------------|-------------------------|
| Hybrid 主流程 | **無** | **無～極小** | **無** |
| Tenant Resolver | **無** | **無** | **無**（僅 registry 列） |
| LINE Formatter | **小**（多連結） | **小** | **無**（若不改程式） |
| SearchUrlBuilder | **無**（新 adapter） | **小** | **無** |
| Gemini Context Cache | **無** | **無** | **無** |

**綜合：低重構可行性 = 高（前提：新程式在獨立模組，feature flag 預設 OFF）**

---

## 9. 新增商品源類別時的影響範圍

| 變更類型 | 影響檔案（典型） |
|----------|------------------|
| 新 `source_id`（同 category） | catalog JSON、tenant product_sources、新 Adapter 類、測試 |
| 新 `product_category` | catalog、`SearchCondition` 或 Builder 選項、template |
| 新租戶 | `tenant_registry.php` 一列、GCS/cache `product_sources.json` |
| 啟用多連結顯示 | `gemini_tour_context_builder.php`、`tour_fallback_formatter.php`（小） |

**不應影響：** `HybridSearchConditionBuilder.php`（除非刻意支援 hotel 自然語言，另案）。

---

## 10. Product Source Capability 對 LINE OA 流程的影響

```
TenantResolver → sno
    ↓
ProductSourceRegistry::getEnabledSources(sno)
    ↓
foreach source → read capabilities
    ↓
┌─ supports_search ──► MultiSourceSearchUrlBuilder → 連結進 context
├─ supports_inventory ──► 仍走 Host B（即時），不走 Cache
├─ supports_google_sheet_registration ──► MVP 不觸發（僅 bbcshops 未來）
└─ 無能力 ──► 跳過
    ↓
TourLineReplyComposer（formatter 輸出連結）
```

**關鍵：** Capability 在 **編排層** 判斷，**不** 在 Gemini Context Cache 判斷。

---

## 11. Phase 9-B MVP 實作建議

### 11.1 建議分階段

| 階段 | 內容 | Feature flag |
|------|------|--------------|
| **9-B-1** | `ProductSourceRegistry` + sample catalog + 本機 cache loader | `product_source_registry_enabled=false` |
| **9-B-2** | `BbcshopsSearchUrlAdapter` 包裝既有 `SearchUrlBuilder` | 同上 |
| **9-B-3** | `MultiSourceSearchUrlBuilder` + 1 個外部 template（如 `grp` stub URL） | 同上 |
| **9-B-4** | `GeminiTourContextBuilder` / `TourFallbackFormatter` 多連結（2 條） | `multi_source_search_links_enabled=false` |
| **9-B-5** | `TourPromptContextService` 注入（travel_b allowlist） | 同上 |
| **9-B-6** | 單元測試 + travel_b 手動驗收 | — |

### 11.2 第一個實作項目（建議）

**Phase 9-B-1：`ProductSourceRegistry` + sample `product_source_catalog` + `cache/tenants/5f99b8d665e8444d/product_sources.json`（僅開發樣本，可不進 git）**

理由：無生產路徑風險；可驗證 200 家僅增 JSON 的可擴性。

### 11.3 不納入 Phase 9-B MVP

- Gemini Context Cache 正式 API  
- Google Sheet Sync 實作  
- 報名流程 / Sheet 寫入  
- Host B schema 變更  
- 正式 GCS bucket  

---

## 12. 風險與不建議事項

| 風險 | 說明 | 控管 |
|------|------|------|
| **單一 URL 假設散落** | `search_url` 字串多處假設 | 保留 `search_url` 主欄位；`search_urls` 增量 |
| **Formatter 硬編碼 cloud_store** | `tour_fallback_formatter` L108-109 辨識 URL | 改為 **registry 驅動** 的 URL 辨識 |
| **200 家全寫進 tenant_registry** | 檔案肥大 | registry 仍一列/家；**商品源獨立 JSON** |
| **外部源無庫存卻承諾可售** | 使用者误解 | Capability 驅動文案；Hybrid 仍標 Host B 庫存 |
| **Context Cache 與商品混淆** | 錯誤快取價格 | 嚴守 Phase 8 邊界 |
| **每請求讀 GCS** | 延遲 | 必須 Host A local cache |

**不建議：**

- 在 `HybridSearchConditionBuilder` 內建 GRP URL  
- 用 Context Cache 存 `product_sources.json`  
- Phase 9-B 同時上線 Cache + 多源 URL  
- 修改 Host B API 契約（除非業務必須）  

---

## 13. 明確結論

### 13.1 是否適合開始實作

**是，適合開始 Phase 9-B**，條件：

1. 採 **獨立模組** + **feature flag 預設 OFF**  
2. 第一階段 **不啟用** Gemini Context Cache  
3. 先 **sample JSON + 本機 cache**，後 GCS  

### 13.2 實作時應先改哪些檔案

| 優先 | 檔案 / 目錄 |
|------|-------------|
| 1 | **新增** `core/product_source/ProductSourceRegistry.php`（規劃路徑） |
| 2 | **新增** `core/product_source/MultiSourceSearchUrlBuilder.php` |
| 3 | **新增** `core/product_source/adapters/BbcshopsSearchUrlAdapter.php` |
| 4 | **小改** `core/tour_prompt_context_service.php`（flag 後） |
| 5 | **小改** `core/gemini_tour_context_builder.php`、`core/tour_fallback_formatter.php` |

### 13.3 哪些核心流程不可大改

| 不可大改 | 原因 |
|----------|------|
| **HybridSearchConditionBuilder** | 已驗收 travel_a / travel_b |
| **TourSearchApiClient / Host B 契約** | 正式庫存與價格 |
| **TenantResolver 解析順序** | channel → registry → DB |
| **TourLineReplyComposer 決策樹** | fixed formatter / Gemini  fallback |
| **Gemini Context Cache** | Phase 8 另案；與 9-B 解耦 |

---

### 13.4 專題回答：200 家中 1 家新增商品源

**是否只需 config + registry + adapter 即可完成？**

**是（在 Phase 9-B 基礎建設完成後）。** 典型步驟：

1. `shared/config/product_source_catalog.json` 新增 `source_id`  
2. `tenants/{sno}/config/product_sources.json` 啟用該源  
3. 新增或重用 `XxxSearchUrlAdapter`（若有新 URL 規則）  
4. refresh 本機 `cache/tenants/{sno}/product_sources.json`  

**不需** 改 Hybrid 核心、Tenant Resolver、Formatter 主流程、Context Cache。

---

### 13.5 是否會影響核心元件

| 元件 | 影響 |
|------|------|
| **Hybrid Search 主流程** | **否**（建議） |
| **Tenant Resolver** | **否** |
| **LINE Formatter** | **小**（多連結區塊） |
| **Gemini Context Cache** | **否** |

---

### 13.6 是否需要 DB schema / Host B API 變更

| 項目 | Phase 9-B MVP |
|------|----------------|
| **DB schema** | **不需要**（商品源走 GCS + 本機 cache；可選 Host B metadata 表為 Phase 10） |
| **Host B API** | **不需要**（即時搜尋仍走既有 API；外部源僅 URL） |

未來若需記錄 `sync_status` / `source_id` 於 Host B，建議 **僅加 metadata 表**，與 9-B 解耦。

---

## 附錄 A：Phase 9-A 檢查清單（新增商品源類別）

對照 Phase 8 §C.3.3：

| # | 檢查項 | Phase 9-A 結論 |
|---|--------|----------------|
| 1 | config | `product_source_catalog.json` + tenant `product_sources.json` |
| 2 | registry | `ProductSourceRegistry` 新類 |
| 3 | adapter | per `source_id` URL adapter |
| 4 | SearchUrlBuilder | **擴充 facade**，不重寫核心類 |
| 5 | LINE Formatter | **小改** 多連結 |
| 6 | Context Cache | **否** |
| 7 | DB schema | **否**（MVP） |

---

## 附錄 B：文件修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-29 | Phase 9-A 初版：唯讀盤點與 MVP 實作計畫 |

---

*本文件為 Phase 9-A 評估產出。Phase 9-B 實作須另開變更單與 code review。*
