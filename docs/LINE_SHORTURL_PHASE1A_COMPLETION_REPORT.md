# BBC AI SaaS / LINE OA 商品回覆短網址化 — Phase 1A 完成報告

**主機：** 103.1.222.14（主機 A）  
**文件類型：** 唯讀整理 / 完成紀錄  
**建立日期：** 2026-05-25  
**狀態：** Phase 1A 已實作、API 實測與 LINE OA 真人測試通過

---

## 1. 文件目的

本文件記錄 **LINE OA / Gemini 商品搜尋回覆** 中 `search_url` 由 bonusmee 長網址改為 **`https://bbcshops.com/{code}`** 之 Phase 1A 實作範圍、驗證結果、目前架構狀態、風險與 rollback，並銜接後續 Phase 1B / 2D–2F 規劃。

供維運、程式審查、commit 前簽核與多網域短網址後續遷移參考。

---

## 2. 背景

BBC AI SaaS 在 LINE OA 處理「東京行程」等商品查詢時，流程為：

```
LINE webhook → safe_gateway.php → saas_router.php
→ TourPromptContextService → TourSearchApiClient
→ api/gateway/tour/search.php → TourSearchService::buildSearchUrl()
→ GeminiTourContextBuilder（context 含 search_url）
→ TourLineReplyComposer / Gemini → LINE 回覆客人
```

在 Phase 1A 之前，基礎建設已完成：

| 項目 | 狀態 |
|------|------|
| bbcshops.com `/{code}` Apache Rewrite（Phase 2B / 2B-Fix） | 已上線，實測 200 |
| `redirect2.php` + `bs_ShortUrl` 全域 `code` | 運作中 |
| Domain Mode DM-1 / DM-2 | bbcshops 可開 cloud 頁，無 redirect loop |
| bonusmee.com 與 bbcshops.com | **並存**，未互設 302 |
| `Config.php` `_WebSiteURL` | 仍為 `https://bonusmee.com/`（本次未改） |

Phase 1A 專注於 **bbc-ai-bot 內 `search_url` 產生與對外顯示**，不改 Apache、不改 SQL schema、不改詳情頁 URL。

---

## 3. Phase 1A 目標

| 目標 | 說明 |
|------|------|
| 對外 `search_url` | `https://bbcshops.com/{code}` |
| 長網址入庫 | 沿用既有 `ShortUrl_Add()` → `bs_ShortUrl` |
| 失敗策略 | **fail-open**：短網址失敗時回傳原 bonusmee 長網址 |
| Feature flag | `SHORT_URL_ENABLED` 可一鍵 rollback |
| 設定 | `SHORT_URL_PUBLIC_BASE`（預設 `https://bbcshops.com/`） |
| 不在範圍 | 商品詳情 `tourdate_dm.php`、domain+code migration、Apache |

---

## 4. 原本架構

### 4.1 `search_url` 產生位置（改動前）

| 項目 | 內容 |
|------|------|
| 源頭檔案 | `C:\bbc-ai-bot\core\tour_search_service.php` |
| 函式 | `TourSearchService::buildSearchUrl(string $sno, string $keyword)` |
| 常數 | `SEARCH_URL_BASE = https://bonusmee.com/view/cloud/cloud_store_tourdate.php` |

### 4.2 原本 URL 格式

```
https://bonusmee.com/view/cloud/cloud_store_tourdate.php?openExternalBrowser=1&UnCarousel=1&fromDMDetailFlag=1&clearParam=Y&mode=1&sno={sno}&keyword={keyword}
```

### 4.3 傳遞至 LINE / Gemini

1. 公開 API `api/gateway/tour/search.php` 回傳 JSON `search_url`
2. `TourSearchApiClient` 透傳至 `GeminiTourContextBuilder`
3. Context 區塊標籤：`更多行程 & 出團日：`
4. `TourFallbackFormatter`（Scheme C 固定清單）從 context 擷取 URL 置於回覆結尾

### 4.4 與詳情 URL 分離（本次未改）

| 用途 | 檔案 | 網址 |
|------|------|------|
| 列表搜尋（本次目標） | `tour_search_service.php` | `cloud_store_tourdate.php` |
| 單品詳情 | `tour_detail_url_builder.php` | `https://bonusmee.com/view/cloud/tourdate_dm.php?...` |

---

## 5. 修改內容

### 5.1 `ShortUrlService`（新增）

**路徑：** `C:\bbc-ai-bot\core\short_url_service.php`

| 能力 | 說明 |
|------|------|
| 方法 | `toPublicShortUrl(string $longUrl): string` |
| 設定 | `SHORT_URL_ENABLED`、`SHORT_URL_PUBLIC_BASE`（`.env` / `config.php`） |
| Legacy 整合 | `require` `www/conf/DBConfig.php` 後將 `$pdo` 提升至 `$GLOBALS`（避免方法內 require 時 PDO 落在區域變數） |
| 短碼 | 呼叫 `www/obj/ShortUrl.php` 之 `ShortUrl_Add()` |
| 日誌 | `logs/short_url_service.log`：僅記 success、code、`long_url_hash`、錯誤摘要（不記完整 query） |

### 5.2 `TourSearchService::buildSearchUrl()`

長網址組法**不變**，最後一行改為：

```php
return (new ShortUrlService())->toPublicShortUrl($longUrl);
```

影響：公開 API JSON、Gemini context、LINE 回覆之 `search_url` 一致。

### 5.3 Feature flag

| 變數 | 行為 |
|------|------|
| `SHORT_URL_ENABLED=0` 或未設定（config 預設 false） | 回傳原長網址 |
| `SHORT_URL_ENABLED=1` | 嘗試短網址；失敗則 fail-open 長網址 |

### 5.4 `TourFallbackFormatter::extractSearchUrl()`

Scheme C 固定清單在標籤解析失敗時，備援改為支援：

- `https://bbcshops.com/{code}`
- `https://bonusmee.com/{code}`
- `cloud_store_tourdate.php` 長網址
- 一般 `https://` URL

### 5.5 `config/config.php`

新增區塊：

```php
'short_url' => [
    'enabled' => … SHORT_URL_ENABLED …,
    'public_base' => … SHORT_URL_PUBLIC_BASE …（預設 https://bbcshops.com/）,
],
```

### 5.6 測試

| 檔案 | 用途 |
|------|------|
| `tests/test_short_url_phase1a.php` | flag on/off、fail-open、formatter 擷取 |
| `tests/test_tour_search_service.php` | 單元測試強制 `SHORT_URL_ENABLED=0` 驗證長網址格式 |

---

## 6. 實際修改檔案

| 動作 | 路徑 |
|------|------|
| **新增** | `core/short_url_service.php` |
| **新增** | `tests/test_short_url_phase1a.php` |
| **修改** | `core/tour_search_service.php` |
| **修改** | `core/tour_fallback_formatter.php` |
| **修改** | `config/config.php` |
| **修改** | `tests/test_tour_search_service.php` |
| **修改（運維）** | `.env`（API 實測時啟用，**非程式碼庫必要變更**） |

**未修改（Phase 1A 明確排除）：**

- Apache / `httpd-ssl.conf` / `.htaccess`
- `bs_ShortUrl` schema、`ShortUrl.php`、`ShortUrlDB.php`
- `www/conf/Config.php`（`_WebSiteURL`）
- `redirect2.php`、`tour_detail_url_builder.php`
- bonusmee.com VirtualHost、598go.com

---

## 7. 備份檔案

| 備份檔 | 說明 |
|--------|------|
| `core/tour_search_service.php.bak.20260525_shorturl_phase1a` | Phase 1A 實作前 |
| `core/tour_fallback_formatter.php.bak.20260525_shorturl_phase1a` | Phase 1A 實作前 |
| `config/config.php.bak.20260525_shorturl_phase1a` | Phase 1A 實作前 |
| `.env.bak.20260525_shorturl_api_test` | API 實測前 `.env` |

**相關（基礎設施，非 Phase 1A 本體）：**

- `httpd-ssl.conf.bak.20260524_phase2b` / `20260525_phase2b_fix`（短網址 Rewrite）
- `index.php.bak.20260524_bbcshops_dm1`、`Jack.php` / `top_cloud*.php.bak.20260524_bbcshops_dm2`（Domain Mode）

---

## 8. Feature flag rollback

### 8.1 恢復長網址（建議）

編輯 `C:\bbc-ai-bot\.env`：

```env
SHORT_URL_ENABLED=0
```

或還原 API 測試備份：

```powershell
Copy-Item C:\bbc-ai-bot\.env.bak.20260525_shorturl_api_test C:\bbc-ai-bot\.env -Force
```

### 8.2 預期行為

`search_url` 恢復為：

```
https://bonusmee.com/view/cloud/cloud_store_tourdate.php?...
```

**無需**回滾 Apache Phase 2B；短網址 Rewrite 與長網址模式可並存。

---

## 9. API 測試結果

**環境：** `.env` 已設 `SHORT_URL_ENABLED=1`、`SHORT_URL_PUBLIC_BASE=https://bbcshops.com/`

**請求：**

```
GET https://bonusmee.com/api/gateway/tour/search.php?sno=e1fd133c7e8e45a1&keyword=東京
```

**結果（摘要）：**

| 欄位 | 值 |
|------|-----|
| `success` | `true` |
| `keyword` | `東京` |
| `pagination.total` | `7070`（當次實測） |
| **`search_url`** | **`https://bbcshops.com/EIU45`** |

**`bs_ShortUrl`（code = EIU45）：**

| 檢查 | 結果 |
|------|------|
| code 存在 | 是 |
| 長網址含 `cloud_store_tourdate.php` | 是 |
| 長網址含 `keyword=%E6%9D%B1%E4%BA%AC`（東京） | 是 |

**回歸抽樣：**

| URL | HTTP |
|-----|------|
| `https://bbcshops.com/view/cloud/cloud_store.php` | 200 |
| `https://bbcshops.com/api/gateway/tour/search.php?...` | 200 |
| `https://bonusmee.com/` | 200 |

**CLI：** `tests/test_short_url_phase1a.php` — 全部通過。

---

## 10. LINE OA 真人測試結果

| 項目 | 結果 |
|------|------|
| 客人輸入 | `東京行程` |
| 回覆結尾標籤 | `更多行程 & 出團日：` |
| 回覆 URL | `https://bbcshops.com/EIU45` |
| 點擊短網址 | 可進入 `cloud_store_tourdate.php` |
| 搜尋關鍵字 | `東京` 正常 |

與 API 實測使用相同 code（`EIU45`）與同一長網址映射，驗證 **端到端** 路徑一致。

---

## 11. 目前架構狀態

```
客人 LINE 詢問「東京行程」
    → search_url 對外：https://bbcshops.com/EIU45
    → bs_ShortUrl.url：bonusmee.com/.../cloud_store_tourdate.php?...&keyword=東京
    → redirect2.php（bbcshops / bonusmee 皆可 /{code}）
    → JS 導向長網址搜尋列表頁

商品詳情（每筆「詳細內容」）
    → 仍為 tourdate_dm.php 長網址（bonusmee.com，本次未改）

行程表 schLink
    → 仍為外部 agt / agenttour 等 URL（與 search_url 無關）
```

| 項目 | 狀態 |
|------|------|
| 全域 `code` 唯一 | **是** — `bbcshops.com/EIU45` 與 `bonusmee.com/EIU45` 同一列 |
| domain + code migration | **未做** |
| tourdetail 詳情網址短網址化 | **未做** |
| bonusmee.com VirtualHost | **未改** |
| 598go.com | **未改** |
| `_WebSiteURL` | 仍 `https://bonusmee.com/`（前台 Ajax 分享鏈仍可能出 bonusmee 短碼） |

---

## 12. 風險評估

| 風險 | 等級 | 說明 | 緩解 |
|------|------|------|------|
| `ShortUrl_Add` / DB 失敗 | 中 | LINE 可能無搜尋連結 | fail-open 回長網址 |
| 方法內 require `DBConfig` 的 PDO 作用域 | 中（已修） | 曾導致 `$pdo` 為 null | 提升至 `$GLOBALS['pdo']` |
| 公開 API 行為變更 | 中 | 所有 `search.php` 消費者見短碼 | `SHORT_URL_ENABLED=0` rollback |
| 長網址仍指向 bonusmee.com | 低 | 點短碼後 JS 跳轉可能仍見 bonusmee 網域 | Phase 2F / 長網址 host 決策 |
| 全域 code 碰撞（跨域） | 低 | 設計如此；未來需 domain+code | Phase 2E |
| Gemini 改寫 URL | 低 | instruction 要求原樣保留 | Scheme C 不依賴 Gemini 改 URL |
| 詳情仍 bonusmee、搜尋為 bbcshops | 低 | 使用者可能混淆兩域 | Phase 1B 評估詳情短網址 |
| `.env` 未設 flag 的環境 | 中 | 預設關閉，仍回長網址 | 部署檢查清單 |

---

## 13. Rollback 流程

### 13.1 僅關閉 LINE/API 短網址（建議優先）

1. `C:\bbc-ai-bot\.env` 設 `SHORT_URL_ENABLED=0`
2. 確認 API：`search_url` 為 bonusmee 長網址
3. LINE 抽測一筆商品查詢

**不需：** Apache 回滾、SQL 刪除、刪除 `bs_ShortUrl` 列。

### 13.2 還原程式（若需完全移除 Phase 1A 程式）

1. 從 `*.bak.20260525_shorturl_phase1a` 還原 `tour_search_service.php`、`tour_fallback_formatter.php`、`config.php`
2. 刪除 `core/short_url_service.php`
3. `.env` 移除 `SHORT_URL_*` 或設 `0`

### 13.3 還原 Apache 短網址 Rewrite（僅當要停用 bbcshops `/{code}`）

使用 `httpd-ssl.conf.bak.20260524_phase2b` 或 `20260525_phase2b_fix`（與 Phase 1A 獨立）。

---

## 14. 後續規劃

### Phase 1B — tourdetail 詳情網址是否短網址化（評估）

| 項目 | 說明 |
|------|------|
| 範圍 | `TourDetailUrlBuilder` → `tourdate_dm.php` |
| 議題 | 每筆商品「詳細內容」是否改 `https://bbcshops.com/{code}` |
| 依賴 | 加密 `sno`/`cid` 參數之長網址穩定性、與 `search_url` 一致體驗 |
| 建議 | 先以數據/營運確認是否必要，再單獨 PR（避免與 Phase 1A 混 roll） |

### Phase 2D — `ShortUrlDomainResolver`

依請求 Host（`bbcshops.com` / `bonusmee.com`）決定對外短網址 base，與前台 `_WebSiteURL` 策略對齊規劃。

### Phase 2E — domain + code migration

`bs_ShortUrl` 增加 domain（或等效）欄位，使同 code 可區分網域；需 migration 與 `ShortUrl_Get` 查詢調整（**本次明確未做**）。

### Phase 2F — 新短網址正式預設 bbcshops.com

| 項目 | 說明 |
|------|------|
| 前台 | `cloudC_ajax.php` `getShortUrl` 回傳 base 改 bbcshops |
| 設定 | 評估是否更新 `_WebSiteURL`（OAuth / callback 風險需另案） |
| Apache | bonusmee 選擇性 302（需 Domain Mode 穩定後） |

### 建議近期動作

1. **LINE OA** — 已在真人測試通過；可擴大 `TourPromptFeatureGate` 允許 sno 清單（若產品允許）
2. **Commit** — 將 Phase 1A 程式與本文件一併提交（`.env` 不進 repo；以 `.env.example` 註記 `SHORT_URL_*`）
3. **監控** — 抽查 `logs/short_url_service.log` 失敗率

---

## 附錄 A — 關鍵路徑速查

| 用途 | 路徑 |
|------|------|
| Webhook | `www/bbc-ai-bot/webhook/callback.php` |
| 路由 | `bbc-ai-bot/core/saas_router.php` |
| Context | `bbc-ai-bot/core/tour_prompt_context_service.php` |
| search_url 源頭 | `bbc-ai-bot/core/tour_search_service.php` → `buildSearchUrl()` |
| 短網址服務 | `bbc-ai-bot/core/short_url_service.php` |
| 公開 API | `www/api/gateway/tour/search.php` |
| Legacy 短碼 | `www/obj/ShortUrl.php`、`www/redirect2.php` |
| 完成報告（Rewrite） | `docs/BBCSHOPS_SHORTURL_PHASE2B_2C_COMPLETION_REPORT.md` |

---

## 附錄 B — 驗證紀錄摘要（2026-05-25）

| 測試類型 | 結果 |
|----------|------|
| CLI `test_short_url_phase1a.php` | 通過 |
| HTTP API `keyword=東京` | `search_url` = `https://bbcshops.com/EIU45` |
| `bbcshops.com/EIU45` | 200，JS → `cloud_store_tourdate.php` + 東京 |
| `bonusmee.com/EIU45` | 200，同上 |
| LINE OA 真人「東京行程」 | 回覆 `https://bbcshops.com/EIU45`，點擊正常 |

---

*文件版本：Phase 1A v1 — 2026-05-25*
