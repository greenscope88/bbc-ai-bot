# BBC 旅業資料中心 — Phase 9-A.5 Storage & Short URL Strategy

**副標：** Product Source 儲存策略與多網域短網址整合分析（唯讀盤點）

**文件代號：** `TRAVEL_DATA_CENTER_PHASE9A5_STORAGE_AND_SHORTURL_STRATEGY`

**版本：** Phase 9-A.5（規劃 only）

**狀態：** Planning — 本文件不觸發程式、JSON、cache、SQL、Git 變更

**規劃主機：** 主機 A `103.1.222.14` · 專案路徑 `C:\bbc-ai-bot`

**關聯文件：**

- [TRAVEL_DATA_CENTER_PHASE9_MVP_IMPLEMENTATION_PLAN.md](./TRAVEL_DATA_CENTER_PHASE9_MVP_IMPLEMENTATION_PLAN.md)（Phase 9-A）
- [TRAVEL_DATA_CENTER_PHASE8_GEMINI_CONTEXT_CACHE_DESIGN.md](./TRAVEL_DATA_CENTER_PHASE8_GEMINI_CONTEXT_CACHE_DESIGN.md)（Phase 8 §13）
- [SHORT_URL_PHASE1B_B_READONLY_AUDIT.md](./SHORT_URL_PHASE1B_B_READONLY_AUDIT.md)（既有短網址盤點）

**盤點日期：** 2026-05-29

---

## 1. 目的

本文件在 **Phase 9-A**（Product Source Registry / Multi-Source Search 可行性）之上，補充兩項正式架構決策之 **唯讀分析**：

| 主題 | 決策背景 |
|------|----------|
| **Storage Strategy** | `product_source_catalog` 與 `tenants/{sno}/product_sources.json` 應放 Git、GCS 或混合 |
| **Short URL Integration** | 外部商品源（GRP、BBC Travel、TourCenter 等）搜尋 URL 可能很長；LINE 應顯示短網址；且 **不可硬編碼 bbcshops.com only** |

**本階段交付：** 架構建議與 Phase 9-B 實作順序調整；**不** 修改程式、不建 JSON/cache、不 git。

---

## 2. 現有短網址架構盤點

### 2.1 元件對照表

| 元件 | 路徑 / 位置 | 角色 |
|------|-------------|------|
| **`bs_ShortUrl`** | SQL Server（legacy www DB） | `code`（多為 5 碼）↔ `url`（完整長網址） |
| **`ShortUrl_Add()`** | `C:\Web\xampp\htdocs\www\obj\ShortUrl.php` | 依 `LOWER(url)` 查重；否則寫入空列取得 `code` |
| **`ShortUrl_Get()` / `ShortUrl_EditDB()`** | `www\obj\ShortUrlDB.php` | 查詢 code / url |
| **`redirect2.php`** | `C:\Web\xampp\htdocs\www\redirect2.php` | `?code=` → 讀 DB → **JS `location.href`** 跳轉 |
| **Apache Rewrite** | bbcshops HTTPS vhost（`httpd-ssl.conf`） | `^/([a-zA-Z0-9]+)$` → `redirect2.php?code=$1` |
| **`ShortUrlService`** | `core/short_url_service.php` | 包裝 `ShortUrl_Add()`；**fail-open**（失敗回長網址） |
| **`SearchUrlBuilder`** | `core/search/SearchUrlBuilder.php` | 組長網址；可選內建呼叫 `ShortUrlService` |
| **設定** | `config/config.php` → `short_url.*`；`.env` | `SHORT_URL_ENABLED`、`SHORT_URL_PUBLIC_BASE` 等 |

> **盤點範圍說明：** `ShortUrl.php` / `redirect2.php` 位於 **legacy www**（`C:\Web\xampp\htdocs\www`），不在 `bbc-ai-bot` repo 內；行為依 [SHORT_URL_PHASE1B_B_READONLY_AUDIT.md](./SHORT_URL_PHASE1B_B_READONLY_AUDIT.md) 與 `short_url_service.php` 唯讀引用。

### 2.2 目前短網址建立流程圖

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. 產生長網址（bbc-ai-bot）                                      │
├─────────────────────────────────────────────────────────────────┤
│ HybridSearchConditionBuilder → SearchCondition                    │
│     → SearchUrlBuilder::build(sno, condition)                   │
│         基底：https://bonusmee.com/view/cloud/cloud_store_tourdate.php │
│     或 buildStorefrontListingUrl(sno)（零結果）                  │
│     或 GeminiTourContextBuilder 內 detail / schedule 連結         │
└────────────────────────────┬────────────────────────────────────┘
                             │ long URL
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│ 2. ShortUrlService（可選，feature flag）                         │
├─────────────────────────────────────────────────────────────────┤
│ toPublicShortUrl()           ← search_url（SHORT_URL_ENABLED）   │
│ toPublicShortUrlForItemLink()← 詳細內容（ITEM_LINKS_ENABLED）    │
│ toPublicShortUrlForScheduleLink() ← 僅 drive/docs（SCHEDULE）   │
│     → bootLegacyShortUrlStack()                                   │
│     → ShortUrl_Add($longUrl, $ret)  【www/obj/ShortUrl.php】     │
│     → $ret['code']                                                │
│     → public_base + code  例：https://bbcshops.com/abc12           │
└────────────────────────────┬────────────────────────────────────┘
                             │ short URL（或 fail-open 回 long）
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│ 3. 進入 LINE 可見文字                                            │
├─────────────────────────────────────────────────────────────────┤
│ GeminiTourContextBuilder::appendSearchUrlLines()                  │
│ TourFallbackFormatter::extractSearchUrl()                         │
│     辨識 bbcshops.com/{code} 或 bonusmee 長鏈                    │
└────────────────────────────┬────────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│ 4. 使用者點擊（legacy www）                                        │
├─────────────────────────────────────────────────────────────────┤
│ GET https://bbcshops.com/{code}                                 │
│     → Apache rewrite → redirect2.php?code=                      │
│     → SELECT url FROM bs_ShortUrl WHERE code=                   │
│     → JavaScript location.href = url                            │
└─────────────────────────────────────────────────────────────────┘
```

### 2.3 現況與 Phase 9 缺口

| 項目 | 現況 |
|------|------|
| **public_base** | 預設 `https://bbcshops.com/`（`ShortUrlService::DEFAULT_PUBLIC_BASE`）；可經 `.env` `SHORT_URL_PUBLIC_BASE` 覆寫 |
| **多網域** | **DB 層無 domain 欄位**；短碼顯示網域由 **public_base 設定單一決定** |
| **SearchUrlBuilder** | 長網址 host 為 **bonusmee.com**；短網址 host 為 **bbcshops.com**（已分離，非同一 host 縮短） |
| **外部商品源 URL** | **尚未接入**；GRP 等長 URL 若直接進 LINE 會過長 |
| **598go.com** | repo **未出現**；列為 **未來 domain_namespace** |

### 2.4 與 SearchUrlBuilder 的現有耦合

```php
// tour_prompt_context_service.php — createSearchUrlBuilder()
$shortEnabled = app_config_get('short_url.enabled', false);
return new SearchUrlBuilder($shortEnabled);

// SearchUrlBuilder::build() 末端
if ($useShort) {
    return (new ShortUrlService())->toPublicShortUrl($longUrl);
}
```

**結論：** 短網址 **已可在 SearchUrlBuilder 內產生**，但 **僅 search_url 一條**、**僅單一 public_base**；Multi-Source 應 **統一在 Builder 之後、Formatter 之前** 處理。

---

## 3. Product Source Catalog Storage Strategy

**對象：** `shared/config/product_source_catalog.json`（平台支援哪些 `source_id`、capability、URL template、short_url 設定）

### 3.1 方案比較

| 維度 | A. Host A Git Repo | B. GCS only | C. 混合（建議） |
|------|-------------------|-------------|-----------------|
| **路徑範例** | `config/product_source_catalog.json` | `gs://…/shared/config/…` | Git **預設模板** + GCS **營運權威** |
| **維護成本** | 低（PR review）；需 deploy 才上線 | 中（需 Sync/UI）；與程式 repo 分離 | Git 管 **結構與預設**；GCS 管 **營運調整** |
| **200 家擴充** | catalog **不因租戶數膨脹** | 同左 | 同左 |
| **Git 依賴** | **高**（改 catalog 要 commit） | **低** | **中**（結構變更仍走 Git） |
| **SaaS 可維護性** | 需工程師改 repo | BBC 維運後台可改 GCS | **維運改 GCS**；工程師改 schema/adapter |
| **上線風險** | 與 code deploy 綁定 | 錯 JSON 即時影響 | **可 staging GCS**；failover 讀 Git 預設 |

### 3.2 建議

**採用 C. 混合模式：**

| 層 | 內容 |
|----|------|
| **Git（bbc-ai-bot）** | `config/product_source_catalog.default.json` — schema 範例、內建 `bbcshops`/`host_b` stub、文件化欄位 |
| **GCS（正式）** | `shared/config/product_source_catalog.json` — BBC 管理者維護啟用源、capability、short_url_domain |
| **執行時** | `ProductSourceRegistry` **優先讀 GCS**；缺失或不可用時 **fallback Git default** |

**Phase 9-B MVP：** 可僅用 **Git sample** 驗證 loader，不建正式 GCS bucket。

---

## 4. Tenant Product Sources Storage Strategy

**對象：** `tenants/{sno}/config/product_sources.json`（每社啟用哪些源、priority）

### 4.1 方案比較

| 維度 | A. Host A Repo | B. GCS only | C. 混合（建議） |
|------|----------------|-------------|-----------------|
| **路徑** | `config/tenants/{sno}/product_sources.json` | `gs://…/tenants/{sno}/config/…` | GCS 權威 + Git **onboarding 範本** |
| **200 家** | **200 個檔進 Git 不建議** | **200 個 GCS 物件合理** | onboarding 腳本寫 GCS；Git 僅 `travel_a`/`travel_b` sample |
| **租戶自助** | 困難 | 可接後台 / Sheet sync | **GCS + metadata（Host B 可選）** |
| **與 tenant_registry** | 易重複維護 | `sno` 為關聯鍵即可 | registry 仍管 channel/sno；product_sources **分檔** |
| **上線風險** | 每開一社要 PR | 單社 JSON 錯誤隔離 | **單社 rollback** 容易 |

### 4.2 建議

**採用 B 為正式權威、A 僅開發樣本：**

| 環境 | 存放 |
|------|------|
| **正式** | `gs://bbc-travel-data-center/tenants/{sno}/config/product_sources.json` |
| **開發/CI** | `config/tenants/samples/travel_b_product_sources.json`（可選進 Git） |
| **禁止** | 200 家全放 Git main |

**travel_b（`5f99b8d665e8444d`）：** 正式應在 **GCS**；Git 可保留 **sample** 供單元測試。

---

## 5. Host A Local Cache Strategy

**對象：** `C:\bbc-ai-bot\cache\tenants\{sno}\product_sources.json`（及可選 catalog mirror）

### 5.1 是否合理

**是，合理且建議保留**，作為 **執行時讀取層**（與 Phase 9-A、Phase 8 C.5 一致）。

| 理由 | 說明 |
|------|------|
| LINE 延遲 | 每則 webhook 不應同步讀 GCS |
| 200 家 | 本機檔按 `sno` 分檔，記憶體可按需載入 |
| 與知識 cache 分離 | `cache/tdc/json/`（知識）vs `cache/tenants/{sno}/product_sources.json`（商品源設定） |

### 5.2 更新策略

| 觸發 | 動作 |
|------|------|
| **SaaS 啟動 / 定時** | 全量或增量 sync GCS → `cache/` |
| **單租戶 onboarding** | 寫 GCS 後立即 refresh 該 `sno` 本機檔 |
| **手動** | CLI `tdc:cache:refresh --sno=…` |
| **版本** | 檔內含 `synced_at`、`content_hash` |

### 5.3 TTL

| 項目 | 建議 |
|------|------|
| **本機檔 TTL** | **15～60 分鐘** 背景 refresh；或 **hash 變更** 事件驅動 |
| **請求內** | 不重複讀 GCS；讀本機檔 + mtime 檢查 |
| **過期行為** | 仍用 **上一份** 本機檔（stale-while-revalidate）；背景 refresh |

### 5.4 Failover（GCS 不可用）

```
嘗試讀 GCS catalog / tenant product_sources
    ↓ 失敗
讀 Host A cache（若存在且未過期）
    ↓ 仍失敗
讀 Git default catalog + 該社最小 enabled 集合（bbcshops only）
    ↓ 仍失敗
僅 Host B + 既有 SearchUrlBuilder（現行行為）
```

**禁止寫入 cache：** PII、即時庫存、即時價格、報名人數（與 Phase 8/9-A 一致）。

---

## 6. SearchUrlBuilder × ShortUrl Service Strategy

### 6.1 是否應拆成三層

**建議：是（邏輯拆分，不必立刻拆三個 repo 專案）**

```
SearchUrlBuilder / MultiSourceSearchUrlBuilder
    → 產出 long URL[]（每 source_id 一筆）
        ↓
ShortUrlService（擴充 product_source 入口）
    → 產出 public URL[]（依 registry 的 short_url_* 設定）
        ↓
GeminiTourContextBuilder / TourFallbackFormatter
    → LINE 可見文字
```

### 6.2 最佳接入位置

| 方案 | 位置 | 評估 |
|------|------|------|
| **A. 在 SearchUrlBuilder 內** | 現況 `build()` 末端 | 僅適用 **單源**；Multi-Source 會重複邏輯 |
| **B. 在 TourPromptContextService** | `resolveSearchUrlForApiResult` 之後 | ✅ **主搜尋 URL**；可擴 `search_urls[]` |
| **C. 在 Formatter 前統一** | 新 `PublicUrlResolver` | ✅ **所有連結**（含多源、detail）一次處理 |
| **D. 在 Formatter 內** | `extractSearchUrl` | ❌ 太晚；難處理結構化多連結 |

**建議接入點（Phase 9-B）：**

1. **`MultiSourceSearchUrlBuilder::buildAll()`** → `long_urls[]`  
2. **`ProductSourceUrlPublisher::toLineUrls()`**（新薄層）→ 呼叫 `ShortUrlService` + registry 的 `short_url_domain`  
3. **`GeminiTourContextBuilder`** 吃 `line_urls[]`（label + url）  
4. **保留** `SearchUrlBuilder` 內既有 short 行為 **向後相容**（`bbcshops`/`bonusmee` 路徑）

### 6.3 與現有三種 ShortUrl 方法

| 方法 | 用途 | Phase 9 擴充 |
|------|------|--------------|
| `toPublicShortUrl` | search_url | 可委派給 **product search** kind |
| `toPublicShortUrlForItemLink` | 詳細內容 | 不變 |
| `toPublicShortUrlForScheduleLink` | Google 行程表 | 不變；**不** 對 GRP 搜尋頁亂縮 |

**新增（規劃）：** `toPublicShortUrlForProductSource(string $longUrl, array $sourceMeta)` — 依 `short_url_enabled`、`short_url_domain`、`domain_namespace` 決定。

---

## 7. Multi-Domain Short URL Strategy

### 7.1 問題

未來短連結顯示網域可能包含：

- `bbcshops.com`（現行預設）
- `bonusmee.com`（長網址來源與品牌）
- `598go.com`（規劃中）
- 其它品牌 vhost

**不能把架構硬寫成 bbcshops.com only。**

### 7.2 底層事實（唯讀）

| 層 | 多網域支援度 |
|----|--------------|
| **`bs_ShortUrl` 表** | **code 全域唯一**（依現行設計）；**url** 可為任意 host 長鏈 |
| **`redirect2.php`** | 目前掛在 **bbcshops vhost**；`598go.com/{code}` 需 **該網域也有 rewrite → 同一 redirect 或共用 backend** |
| **`ShortUrlService::resolvePublicBase()`** | **單一** `public_base`；可覆寫但非 per-source |

### 7.3 建議：同一套 ShortUrl Service + domain_namespace

**原則：** **共用** `ShortUrl_Add()` / `bs_ShortUrl`（同一 DB、同一 code 空間），**分離**「對外顯示網域」。

**catalog 擴充（規劃）：**

```json
{
  "source_id": "grp",
  "short_url_enabled": true,
  "short_url_domain": "bbcshops.com",
  "domain_namespace": "bbcshops"
}
```

```json
{
  "source_id": "bbcshops_search",
  "short_url_enabled": true,
  "short_url_domain": "598go.com",
  "domain_namespace": "598go"
}
```

| 欄位 | 說明 |
|------|------|
| **`short_url_enabled`** | 是否短網址化 |
| **`short_url_domain`** | 對外短鏈 **顯示用 host**（`https://{domain}/{code}`） |
| **`domain_namespace`** | 邏輯分組；對應 **public_base**、Apache vhost、日後統計 |

**基礎設施需求（非 bbc-ai-bot 單獨完成）：**

| namespace | 需求 |
|-----------|------|
| `bbcshops` | 現有 rewrite + redirect2 ✅ |
| `598go` | 需 **598go vhost** 同樣 `^/([A-Za-z0-9]+)$` → 共用 `redirect2.php` 或等價 |
| `bonusmee` | 若要用 `bonusmee.com/{code}` 顯示，需 **bonusmee vhost** 同上 |

**過渡期建議：** 所有商品源短鏈 **對外仍用 bbcshops.com/{code}**（`short_url_domain` 預設 bbcshops），長網址目標可為任意 host；**不要求** 短鏈 host 與長鏈 host 相同。

### 7.4 避免 short → short

延續 [SHORT_URL_PHASE1B_B_READONLY_AUDIT.md](./SHORT_URL_PHASE1B_B_READONLY_AUDIT.md)：

- 已是 `agt.tw` / `lturl.cc` / `bbcshops.com/{code}` 的 URL **不再縮短**
- `ShortUrlService` 應增加 **host 檢測**：若已是短鏈則 passthrough

---

## 8. Product Source Registry × Short URL Strategy

### 8.1 建議新增欄位（catalog / per source）

| 欄位 | 類型 | 說明 |
|------|------|------|
| **`short_url_enabled`** | bool | 是否經 ShortUrlService |
| **`short_url_domain`** | string | 短鏈顯示網域，如 `bbcshops.com` |
| **`domain_namespace`** | string | `bbcshops` \| `598go` \| `bonusmee` |
| **`short_url_min_length`** | int | 長度低於此值不縮（可選，預設 80） |
| **`short_url_skip_hosts`** | string[] | 已是短鏈的 host 列表（agt.tw, lturl.cc） |

### 8.2 與 Capability 聯動

| capability | short_url |
|------------|-----------|
| `supports_search=true` | 通常 `short_url_enabled=true` |
| 僅 `supports_detail` 且 URL 已短 | `short_url_enabled=false` |
| `supports_google_sheet_registration` | **不影響** short_url（Sheet 連結另案） |

### 8.3 Context Cache 邊界（再次確認）

- **Product Source Registry** 管 **商品搜尋 URL**  
- **Gemini Context Cache** 管 **知識文本**  
- **`short_url_*` 不寫入 Context Cache**

---

## 9. 低重構可行性驗證

### 9.1 新增商品類別（group_tour / hotel / …）

| 類別 | 重構面積 | 方式 |
|------|----------|------|
| group_tour | 已有 | 現行 Hybrid + SearchUrlBuilder |
| hotel / car_rental / fit / private_group / other | **小** | catalog + adapter + url template + category 參數 |

**仍可維持：** config + registry + adapter（+ short_url 欄位）。

### 9.2 是否影響核心元件

| 元件 | 是否需要改版 | 說明 |
|------|--------------|------|
| **Hybrid Search 主流程** | **否** | 仍負責 Host B `group_tour` 搜尋 |
| **Tenant Resolver** | **否** | 仍只解析 `sno` |
| **Context Cache** | **否** | 知識層獨立 |
| **Host B API** | **否（MVP）** | 外部源僅 URL；庫存仍 Host B |
| **ShortUrlService** | **小擴充** | 新 kind + domain_namespace；非重寫 |
| **SearchUrlBuilder** | **小** | Multi-Source facade；保留原類 |
| **Formatter** | **小** | 多連結區塊 |

### 9.3 DB schema / Host B

| 項目 | Phase 9-B / 9-A.5 |
|------|-------------------|
| **DB schema** | **不需要**（短網址沿用 `bs_ShortUrl`） |
| **Host B API** | **不需要**（除非未來 hotel 庫存走 B） |

---

## 10. 建議正式架構

```
┌─────────────────────────────────────────────────────────────┐
│ 儲存（混合）                                                  │
├─────────────────────────────────────────────────────────────┤
│ GCS 權威：shared/config/product_source_catalog.json          │
│ GCS 權威：tenants/{sno}/config/product_sources.json          │
│ Git 預設：config/*.default.json（fallback + schema）         │
│ Host A：cache/tenants/{sno}/product_sources.json（執行時）   │
└────────────────────────────┬────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────┐
│ ProductSourceRegistry（新）                                 │
│  - capabilities                                             │
│  - short_url_enabled / short_url_domain / domain_namespace  │
└────────────────────────────┬────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────┐
│ MultiSourceSearchUrlBuilder → long URLs                     │
│ Host B path（Hybrid，不變）                                  │
└────────────────────────────┬────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────┐
│ ShortUrlService（擴充，共用 bs_ShortUrl）                    │
│ fail-open → LINE 仍顯示長鏈若失敗                            │
└────────────────────────────┬────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────┐
│ Context Builder + TourFallbackFormatter → LINE            │
└─────────────────────────────────────────────────────────────┘

平行（不參與 URL）：
  SourceAdapterRegistry / Gemini Context Cache → 知識 FAQ
```

**Feature flags（規劃）：**

- `product_source_registry_enabled`
- `multi_source_search_links_enabled`
- `short_url.product_source_enabled`（或 per-source 僅用 catalog）
- 既有 `SHORT_URL_ENABLED` 保持向後相容

---

## 11. 建議 Phase 9-B 第一個實作項目

**調整 Phase 9-A 建議，9-B-1 改為：**

| 順序 | 項目 |
|------|------|
| **9-B-1a** | `ProductSourceRegistry` loader（Git default + 可選讀本地 sample cache 檔） |
| **9-B-1b** | catalog schema 含 **`short_url_enabled`、`short_url_domain`、`domain_namespace`** |
| **9-B-2** | `MultiSourceSearchUrlBuilder` + `BbcshopsSearchUrlAdapter`（委派現有 `SearchUrlBuilder`） |
| **9-B-3** | `ProductSourceUrlPublisher` → 擴充 `ShortUrlService`（**單一 public_base 過渡**） |
| **9-B-4** | Formatter 多連結輸出（2～3 條含短鏈） |

**基礎設施可並行（非 bbc-ai-bot）：** `598go.com` vhost rewrite（若要用該 domain 顯示短碼）。

---

## 12. 風險與不建議事項

| 風險 | 說明 | 控管 |
|------|------|------|
| **bbcshops-only 硬編碼** | `DEFAULT_PUBLIC_BASE`、formatter 辨識 | registry 驅動 + 過渡期統一 bbcshops |
| **雙層短鏈** | 對已是 agt.tw 再 ShortUrl_Add | skip_hosts + 長度閾值 |
| **Open redirect** | `bs_ShortUrl.url` 無 host 白名單 | 僅允許 `https?://`；營運審核 catalog |
| **GCS 強依賴** | 無 cache 時延遲 | Git fallback + stale cache |
| **598go 無 vhost** | 設了 domain 卻 404 | 基礎設施清單與 namespace 對照表 |
| **在 SearchUrlBuilder 塞 200 個 if** | 不可維護 | adapter 模式 |

**不建議：**

- 把 GRP 長鏈 **只** 塞進 Context Cache  
- 每個商品源 **獨立** ShortUrl DB 表（先用共用 `bs_ShortUrl`）  
- Phase 9-B 同時上線 GCS + 多網域 vhost + Cache API  

---

## 13. 最終結論

### 13.1 專題回答

| # | 問題 | 建議答案 |
|---|------|----------|
| **1** | Product Source Catalog 最適合放哪裡？ | **C. 混合：GCS 正式權威 + Git default fallback** |
| **2** | Tenant Product Sources 最適合放哪裡？ | **GCS 正式（`tenants/{sno}/config/product_sources.json`）；Git 僅 sample，200 家不進 Git** |
| **3** | Host A Cache 是否需要？ | **需要** — 執行時讀取、降 GCS 延遲；含 TTL 與 GCS failover |
| **4** | 短網址在哪一層產生最合理？ | **MultiSourceSearchUrlBuilder 之後、Formatter 之前**；共用 **ShortUrlService** |
| **5** | 多網域短網址如何設計？ | **同一套 ShortUrl_Add/bs_ShortUrl** + **`domain_namespace` / `short_url_domain`**；Apache 多 vhost 指向同一 redirect；過渡期可全顯示 **bbcshops.com** |
| **6** | 200 家新增商品源是否仍 config+registry+adapter？ | **是**；不改 Hybrid/Tenant Resolver/Context Cache 核心 |

### 13.2 是否適合開始實作

**是。** Storage 與 Short URL 策略已可支撐 Phase 9-B；建議 **短網址與 registry 同階段設計**，避免 Formatter 二次改版。

### 13.3 核心流程不可大改

- HybridSearchConditionBuilder / TourSearchApiClient  
- TenantResolver 解析順序  
- TourLineReplyComposer 決策樹  
- `bs_ShortUrl` / `ShortUrl_Add` 契約（可擴充、不替換）

---

## 附錄 A：盤點檔案清單

| 類別 | 路徑 |
|------|------|
| 短網址 | `core/short_url_service.php`, `config/config.php`（short_url） |
| URL | `core/search/SearchUrlBuilder.php`, `core/tour_prompt_context_service.php` |
| Context / Formatter | `core/gemini_tour_context_builder.php`, `core/tour_fallback_formatter.php` |
| 文件 | `docs/SHORT_URL_PHASE1B_B_READONLY_AUDIT.md` |
| Phase 9 | `docs/TRAVEL_DATA_CENTER_PHASE9_MVP_IMPLEMENTATION_PLAN.md` |
| Legacy（路徑引用） | `C:\Web\xampp\htdocs\www\obj\ShortUrl.php`, `redirect2.php`（未在本 repo） |

---

## 附錄 B：文件修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-29 | Phase 9-A.5 初版：Storage & Short URL Strategy |

---

*本文件為唯讀盤點與架構建議，不構成實作承諾。*
