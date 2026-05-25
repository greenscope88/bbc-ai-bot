# Short URL Phase 1B-B — 行程表（schLink）唯讀盤點與風險分析

**主機：** 103.1.222.14（主機 A）  
**文件類型：** 唯讀盤點 / 風險分析（**不含程式變更**）  
**建立日期：** 2026-05-25  
**狀態：** Phase 1B-B 規劃前調查  
**前置完成：** Phase 1A（search_url）、Phase 1B-A（詳細內容）、5 碼 code 策略（`SHORT_URL_CODE_LENGTH_STRATEGY.md`）

---

## 0. 本次調查範圍與限制

| 項目 | 說明 |
|------|------|
| 允許 | 唯讀檢視 `C:\bbc-ai-bot`、`C:\Web\xampp\htdocs\www`、Apache 文件紀錄 |
| 禁止 | 修改 PHP / Apache / `.env` / SQL 執行 / git commit |
| 未執行 | `SELECT` 統計 `bs_Coupon` / `bs_CouponSchLink` domain 分布（依產品要求） |

**產品策略（本次盤點依據）：**

- **允許自動短網址化：** `drive.google.com`、`docs.google.com`（URL 過長、LINE 顯示不佳）
- **不再縮短：** `agttrip.com`、`lturl.cc` 及既有第三方短網址（避免 **short → short → final** redirect chain）

---

## 1. schLink / 行程表 URL 來源盤點

### 1.1 資料層（SQL Server，唯讀 schema）

| 表 | 欄位 | 語意 |
|----|------|------|
| `dbo.bs_Coupon` | `schLink`, `schLinkName` | 單一行程表連結（常見於 `couponAttr=99` 等） |
| `dbo.bs_CouponSchLink` | `schLink`, `schLinkName` | 同一商品多筆行程表連結（依 `depID` + `storeNo` + `couponNo`） |

後台寫入路徑（`www/obj`）：

| 檔案 | 函式 / 行為 |
|------|-------------|
| `C:\Web\xampp\htdocs\www\obj\CouponDB.php` | `Coupon_AddDB` / `Coupon_EditDB` — INSERT/UPDATE `bs_Coupon.schLink` |
| `C:\Web\xampp\htdocs\www\obj\CouponSchLinkDB.php` | `CouponSchLink_ReAddDB` — 先 DELETE 再 INSERT `bs_CouponSchLink`；`CouponSchLink_List` — 讀取列表 |

**欄位約束（前台表單）：** `schLink` 輸入框 `maxlength='150'`（`editTour.php` / `addTour.php`），實際 DB 為 `nvarchar`，可存較長 URL 若他處寫入。

---

### 1.2 後台維護（旅行社上架）

| 檔案 | 行為 |
|------|------|
| `www/view/tour/addTour.php` | 表單 `#schLink` / `#schLinkName` |
| `www/view/tour/editTour.php` | 同上；`couponAttr=99` 時單一 `schLink` |
| `www/view/tour/addTourDo.php` | `$_POST["schLink"]` → `CouponDB` |
| `www/view/tour/editTourDo.php` | 同上 |
| `www/view/coupon/addCoupon.php` | `schLinkFlag` 控制多連結 UI |
| `www/view/coupon/editCoupon.php` | `hidCouponSchLink[]` 格式 `名稱!@#:URL` → `couponSchLinkAry` |
| `www/view/coupon/editCouponDo.php` | `couponSchLinkAry` → `CouponSchLink_ReAddDB` |
| `www/view/tour/js/tour.js` | `couponSchLinkStr` 驗證與動態 `<li>` |
| `www/view/coupon/js/coupon.js` | 同上（coupon 模組） |

**URL 組裝邏輯：** 無伺服器端組裝；**旅行社手動貼上完整外部 URL**（Google Drive/Docs、代理商短鏈、PDF 等）。

---

### 1.3 前台 Web（列表 / 內頁）

| 檔案 | 輸出位置 | 邏輯 |
|------|----------|------|
| `www/view/cloud/cloud_store_tourdate.php` | 列表「行程」欄 | AJAX `ret.sData[p].schLink` → `<a href='{schLink}'>` + `openSchLink(...)` |
| `www/view/cloud/cloud_dm_list_tourdate.php` | DM 列表 | 同上 |
| `www/view/cloud/tourdate_dm.php` | 內頁「點選看行程」 | `couponAttr=99`：`$arCouponTour["schLink"]`；否則 `CouponSchLink_List` → 多個 `<a href='{schLink}'>` |
| `www/view/cloud/cloud_coupon_dmDetail.php` | 商品 DM 詳情 | `bs_CouponSchLink` 列表 |
| `www/view/tour/tourIndex.php` | 後台列表 | 「行程網址」連結 |

列表資料來源為 cloud AJAX（`ret.sData`），欄位名 **`schLink` / `schLinkName`** 與 API 契約一致；具體 AJAX handler 在 `www/view/cloud/` 系列（與 `cloudCouponTourC_ajax.php` 同族），**不在 `bbc-ai-bot` repo**。

---

### 1.4 Host B API → Host A Gateway → LINE

```text
Host B  GET /api/tour/search
    → items[].schLink（legacy 單一）
    → items[].schLinks[]（多筆：schLinkName + schLink）
        ↓
Host A  TourSearchService::mapItem() / normalizeSchLinksFromRow()
    → 原樣 passthrough（無 URL 改寫）
        ↓
GeminiTourContextBuilder::formatSchLinksDisplayLines()
    → context 文字：
       單筆：「行程表：https://...」
       多筆：「行程表：」+「1. https://...」…（URL-only，不含 schLinkName）
        ↓
TourFallbackFormatter::formatFromTourContext()
    → LINE：「🗓️ 行程表：」+ URL（Scheme C：解析 context，不重新產 URL）
```

| 層級 | 檔案 | 函式 |
|------|------|------|
| Gateway 正規化 | `core/api_gateway/production/http/HostBResponseNormalizer.php` | `SCHEDULE_URL_KEYS` 保留 `schLink` / `schLinks` 內 https URL（其餘 URL 欄位 redact） |
| 搜尋編排 | `core/tour_search_service.php` | `mapItem()`、`normalizeSchLinksFromRow()` |
| Context | `core/gemini_tour_context_builder.php` | `formatSchLinksDisplayLines()`、`normalizeSchLinksList()`、`extractSchLinkValueStatic()` |
| LINE 排版 | `core/tour_fallback_formatter.php` | `formatScheduleOutputLine()` → `🗓️ 行程表：` |
| Prompt 入口 | `core/tour_prompt_context_service.php` | `buildTourContextForPrompt()` → `TourSearchApiClient::search()` |

**Phase 1A / 1B-A 已短網址化（與行程表無關）：**

| 標籤 | 服務 | Flag |
|------|------|------|
| 更多行程 & 出團日 | `ShortUrlService::toPublicShortUrl()` | `SHORT_URL_ENABLED` |
| 詳細內容 | `ShortUrlService::toPublicShortUrlForItemLink()` | `SHORT_URL_ITEM_LINKS_ENABLED` |

**Phase 1B-B 現況：** `schLink` / `schLinks` **未經** `ShortUrlService`，LINE 仍為 API 回傳之原始 URL。

---

## 2. schLink Domain 類型分析（唯讀推論）

> **說明：** 本次**未執行 SQL**。以下分類結合：(a) 產品文件與後台語意、(b) staging LINE sign-off 實測紀錄（`LINE_FIXED_FORMATTER_FINAL_SIGNOFF_REPORT.md`）、(c) 單元測試假資料。

### 2.1 Domain 分類表

| Domain 類型 | 範例（文件 / 實測 / 測試） | 出現位置 | 是否已是短網址 | 是否適合再短網址化 |
|-------------|------------------------------|----------|----------------|-------------------|
| **Google Drive** | `https://drive.google.com/...` | G331 sign-off、測試 fixture | 否（長 URL） | **是（建議）** |
| **Google Docs** | `https://docs.google.com/...` | 後台常見類型（文件描述） | 否 | **是（建議）** |
| **代理商短鏈** | `https://agt.tw/F3IbS5`（釜山 4 筆） | LINE 釜山實測 | **是** | **否** |
| **代理商入口** | `https://bbc.agenttour.com.tw/...` | LINE 釜山第二筆 | 否（但非 Google 長鏈） | **否**（非 allowlist；且可能已是供應商體系） |
| **第三方短鏈** | `https://reurl.cc/EmmVXm`（北京） | LINE 北京實測 | **是** | **否** |
| **產品提及** | `agttrip.com`、`lturl.cc` | 產品策略（待 DB 佐證） | 通常 **是** | **否** |
| **自家前台** | `bonusmee.com/view/cloud/...` | search_url / detail_url | 否 | **否**（另有專用 flag） |
| **自家短碼** | `bbcshops.com/{code}` | Phase 1A/1B-A | **是** | **否**（勿再包一層） |
| **靜態 PDF / 其它 host** | `https://example.com/p.pdf` | 單元測試 | 視長度 | **預設否**（除非納入 allowlist 擴充） |

### 2.2 Staging 實測摘要（2026-05-23）

| 查詢 | 行程表 URL 型態 | 筆數 |
|------|-----------------|------|
| 釜山 | `agt.tw` 短鏈 ×4 + `bbc.agenttour.com.tw` | 多商品 |
| G331 | 含 `drive.google.com`（sign-off 表） | 多商品 |
| 北京 | `reurl.cc` 短鏈 | 單筆為主 |

**結論：** 同一 LINE 回覆可**混用** Google 長鏈、代理商短鏈、reurl 短鏈；Phase 1B-B **不可**「全 external 一律縮短」，必須 **domain allowlist**。

### 2.3 建議後續量化（實作前，需另案唯讀 SQL）

```sql
-- 僅供規劃參考；本次未執行
-- SELECT host = 解析 schLink 之 domain, COUNT(*) FROM bs_Coupon WHERE schLink <> '' GROUP BY ...
-- UNION 同邏輯於 bs_CouponSchLink
```

---

## 3. 短網址系統唯讀分析

### 3.1 元件對照

| 元件 | 路徑 | 角色 |
|------|------|------|
| `bs_ShortUrl` | SQL Server | `code`（5 碼為主）↔ `url`（完整長網址） |
| `ShortUrl_Add()` | `www/obj/ShortUrl.php` | 依 `LOWER(url)` 查既有列重用 code；否則取 `url IS NULL` 空列寫入 |
| `ShortUrl_Get()` / `ShortUrl_EditDB()` | `www/obj/ShortUrlDB.php` | 查 code / url；**無 domain 白名單** |
| `redirect2.php` | `www/redirect2.php` | `?code=` → 讀 `bs_ShortUrl.url` → **JS `location.href`** 跳轉 |
| Apache Rewrite | `httpd-ssl.conf`（bbcshops HTTPS vhost） | `^/([a-zA-Z0-9]+)$` → `/redirect2.php?code=$1` |
| `ShortUrlService` | `bbc-ai-bot/core/short_url_service.php` | 包裝 `ShortUrl_Add()`；fail-open；**僅** search + detail 兩種 gate |

### 3.2 是否允許 external URL

| 問題 | 答案 |
|------|------|
| `bs_ShortUrl.url` 可否存外部 URL？ | **可以** — 歷史上存 `bonusmee.com`、`cloud_store_tourdate.php`、未來可存 `drive.google.com` 等 |
| `ShortUrl_Add($url)` 是否限制 host？ | **否** — 任意字串 URL（含 `https://agt.tw/...`）均可寫入 |
| LINE Phase 1A/1B-A 實際存入 | 多為 **bonusmee 前台長網址**（搜尋列表 / tourdate_dm） |

### 3.3 安全機制現況

| 機制 | redirect2.php / ShortUrl.php | bbc-ai-bot（行程表 context） |
|------|------------------------------|------------------------------|
| Protocol 驗證 | **無**（DB 內容直接跳轉） | `GeminiTourContextBuilder::isSafeExternalUrl()` 僅要求 `^https?://` |
| Domain 驗證 | **無** | **無**（行程表尚未進 ShortUrlService） |
| Open redirect 防護 | **無** — 若 `url` 被寫入 `javascript:` 或惡意站，由 JS 導向 | 同上風險僅在**未來**把 schLink 寫入 `bs_ShortUrl` 時成立 |
| 敏感 query redact | N/A | 擋 `api_key=`、`traceid=`、`depid=` |
| Host B normalizer | N/A | 保留 `schLink` URL；其餘 `url` 鍵 redact |

### 3.4 Redirect Flow（現行 vs Phase 1B-B 風險）

**現行（行程表未短網址化）：**

```text
LINE 客人點擊 🗓️ 行程表 URL（原始 schLink）
    → 可能直接到 Google / agt.tw / reurl.cc（一跳）
```

**若錯誤「全 external 短網址化」：**

```text
LINE → https://bbcshops.com/{code}
    → redirect2.php → JS 跳轉
    → https://agt.tw/xxxx   （已是短鏈）
    → 代理商最終 PDF / Drive
```

→ **雙層短鏈 + 雙跳**，延遲、追蹤與 Safe Browsing 風險上升。

**建議 Phase 1B-B（僅 Google）：**

```text
LINE → https://bbcshops.com/{code}   （僅當 host ∈ {drive.google.com, docs.google.com}）
    → redirect2.php → Google 資源（一跳短鏈）
```

**應跳過縮短（維持原 URL）：**

```text
agt.tw / agttrip.com / lturl.cc / reurl.cc / bbcshops.com / bonusmee.com/短碼 ...
```

---

## 4. 風險分析

| 風險 | 等級 | 說明 | Phase 1B-B 緩解方向 |
|------|------|------|---------------------|
| **Open redirect** | 高（若無 allowlist） | `redirect2.php` 信任 `bs_ShortUrl.url` 全文；後台或 API 若可間接寫入惡意 URL 則危險 | 僅 allowlist domain 呼叫 `ShortUrl_Add`；寫入前 `https` only + host 比對 |
| **Redirect chain** | 中～高 | `bbcshops` → `agt.tw` → 目的地 | **禁止**對已是短鏈的 domain 再 `ShortUrl_Add` |
| **LINE reputation** | 中 | 過多跳轉、不同 domain 混用可能影響預覽 / 信任 | 僅縮 Google 長鏈；短鏈保持原樣 |
| **Google Safe Browsing** | 低～中 | 多跳、短鏈嵌短鏈可能觸發警示 | 單跳 `bbcshops` → Google；避免 chain |
| **外部短網址再短網址化** | 高（產品已否決） | 無益且可能破壞代理商追蹤 query | 明確 denylist：`agt.tw`、`agttrip.com`、`lturl.cc`、`reurl.cc` 等 |
| **Gemini 改寫 URL** | 低 | fixed formatter 路徑已改解析 context | 維持 Scheme C；縮短在 **context 組裝前** 完成 |
| **code 池耗盡** | 低 | 僅 Google 連結新增短碼 | 沿用 5 碼池 + `SHORT_URL_CODE_LENGTH_STRATEGY.md` |
| **多筆 schLinks 部分允許** | 中 | 同一商品可能 1 條 Google + 3 條 agt.tw | **逐 URL** 判斷，非逐商品一刀切 |

---

## 5. 最小可行安全策略（MVP）

### 5.1 原則

1. **僅** `drive.google.com`、`docs.google.com`（建議含子網域 `*.google.com` 限於這兩個 host 精確比對，**不**放寬到整個 `google.com`）。
2. **其餘一律原樣輸出**（含 `agt.tw`、`agttrip.com`、`lturl.cc`、`reurl.cc`、`bbcshops.com`、已是短鏈者）。
3. **Fail-open：** `ShortUrl_Add` 失敗 → 保留長 Google URL（與 Phase 1A 一致）。
4. **獨立 feature flag**（建議）：`SHORT_URL_SCHEDULE_LINKS_ENABLED`（與 `SHORT_URL_ITEM_LINKS_ENABLED` 分離）。
5. **不修改** `ShortUrl.php` / `ShortUrlDB.php` / `redirect2.php` / Apache（第一階段）。

### 5.2 建議 Allowlist / Denylist

**Allowlist（可呼叫 `ShortUrl_Add`）：**

| Host | 理由 |
|------|------|
| `drive.google.com` | 產品指定；URL 長 |
| `docs.google.com` | 產品指定 |

**Denylist（絕不短網址化，即使 URL 很長）：**

| Host / 模式 | 理由 |
|-------------|------|
| `agt.tw` | staging 實測為代理商短鏈 |
| `agttrip.com` | 產品指定 |
| `lturl.cc` | 產品指定 |
| `reurl.cc` | 北京實測 |
| `bbcshops.com` | 已是自家短碼 |
| `bonusmee.com` | 前台長網址另有策略 |
| `bit.ly`、`goo.gl`、`tinyurl.com` 等 | 通用短鏈（可配置擴充） |
| 非 `http:`/`https:` | 安全 |

**可選 heuristics：**

- path 長度 &lt; N 且 host 在 denylist → skip  
- host 在 allowlist 但 URL 已含 `bbcshops.com/{code}` → skip（避免 double wrap）

### 5.3 建議實作掛點（規劃用，本次不實作）

| 順序 | 位置 | 變更 |
|------|------|------|
| 1 | `core/short_url_service.php` | `toPublicShortUrlForScheduleLink(string $url): string` + `isScheduleShortUrlAllowedHost(string $host): bool` |
| 2 | `core/gemini_tour_context_builder.php` | 在 `normalizeSchLinksList()` / `formatSchLinksDisplayLines()` 輸出前對每個 `schLink` 呼叫 schedule 短網址（**逐 URL**） |
| 3 | `config/config.php` + `.env` | `SHORT_URL_SCHEDULE_LINKS_ENABLED` |
| 4 | `tests/test_short_url_phase1b_schedule.php` | Google allow、agt/reurl deny、fail-open、flag off |
| 5 | `docs/` | 更新 completion report |

**不建議修改：**

- `TourSearchService::mapItem()`（保持 API 原始值；短網址僅 LINE-facing context 層）
- `HostBResponseNormalizer`（已正確保留 schLink）
- `bs_ShortUrl` schema

### 5.4 決策流程（建議 pseudocode）

```text
function maybeShortenScheduleUrl(url):
    if not SHORT_URL_SCHEDULE_LINKS_ENABLED:
        return url
    if url is empty:
        return url
    parse host from url
    if host in DENYLIST_OR_ALREADY_SHORT_SERVICE:
        return url
    if host not in {drive.google.com, docs.google.com}:
        return url
    short = ShortUrl_Add(url)  // fail-open
    return short ?? url
```

---

## 6. 與現行 Phase 1A / 1B-A 差異

| 項目 | search_url (1A) | detail (1B-A) | schedule (1B-B 規劃) |
|------|-----------------|---------------|----------------------|
| Context 標籤 | 更多行程 & 出團日 | 詳細內容 | 行程表 / 🗓️ |
| 資料來源 | Host A 組裝 bonusmee 列表 URL | Host A `TourDetailUrlBuilder` | Host B `schLink` / `schLinks[]` |
| 目標 host | 自家前台 | 自家 `tourdate_dm.php` | **外部**（多為 Google / 代理商） |
| 篩選 | 無（一律嘗試短網址） | 無 | **必須 allowlist** |
| 短網址化 | 已上線 | 已上線 | **未實作** |

---

## 7. 建議驗收要點（實作階段）

1. LINE 查詢含 **Google Drive** 行程表 → 顯示 `https://bbcshops.com/{5碼}`，點擊到達 Google。  
2. 含 **agt.tw / reurl.cc** 商品 → 行程表 URL **與 API 完全相同**（無 bbcshops 包裝）。  
3. 同一商品 4 筆 `schLinks`：僅 Google 行縮短，其餘不變。  
4. `SHORT_URL_SCHEDULE_LINKS_ENABLED=0` → 全部長 URL（rollback）。  
5. `tests/test_short_url_phase1a.php`、`test_short_url_phase1b_detail.php` 不受影響。

---

## 8. 相關文件

| 文件 | 說明 |
|------|------|
| `docs/SHORT_URL_CODE_LENGTH_STRATEGY.md` | 5 碼池；與 1B-B 獨立 |
| `docs/LINE_SHORTURL_PHASE1A_COMPLETION_REPORT.md` | search_url；§11 註明 schLink 仍外部 URL |
| `docs/LINE_FIXED_FORMATTER_FINAL_SIGNOFF_REPORT.md` | 釜山 agt.tw、北京 reurl.cc 實測 |
| `docs/HYBRID_SMART_SEARCH_DETAIL_URL_AND_LINE_RESPONSE_PLAN.md` | schLink 語意定義 |
| `docs/BBCSHOPS_SHORTURL_PHASE2B_2C_COMPLETION_REPORT.md` | Apache `/{code}` → redirect2 |

---

## 9. 修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| v1 | 2026-05-25 | 初版唯讀盤點；未執行 SQL；未修改程式 |

---

*本文件為 Phase 1B-B 實作前之安全與範圍基線，供產品與工程評審。*
