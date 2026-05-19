# API Gateway MVP — Stage 1-B-6 Isolated HTTP Entry Live Test Report

**Project root:** `C:\bbc-ai-bot`  
**Branch:** `feature/api-gateway-mvp`  
**Document date:** 2026-05-19  
**Type:** Isolated HTTP entry live verification report (browser / curl)

**Related:** `docs/API_GATEWAY_MVP_STAGE_1B3_LIVE_HOSTB_TEST_REPORT.md`, `docs/API_GATEWAY_MVP_PUBLIC_ENTRY_PLAN.md`, Stage 1-B-5 isolated entry (`C:\Web\xampp\htdocs\www\bbc-ai-gateway-test\index.php`)

---

## 1. 測試目的

驗證 **Stage 1-B-5** 建立的 **隔離 HTTP 測試入口** 可透過瀏覽器或 `curl` 在 Host A 上呼叫 `TourSearchService`，並完成：

- GET 參數解析（`sno`、`keyword`、`page`、`pageSize`）
- JSON 回應契約（`success`、`traceId` / `request_id`、`pagination`、`items`、`search_url`、`error`）
- Host B 實際 HTTP 成功路徑（在暫時啟用 `GATEWAY_HOSTB_HTTP_ENABLED` 時）
- 關閉 Host B HTTP 後的安全降級（`HOSTB_HTTP_DISABLED`）

本階段 **不** 修改正式站台入口、`saas_router.php`、LINE webhook、`.htaccess`、Apache 設定或資料庫。

---

## 2. 測試入口

| 項目 | 值 |
|------|-----|
| **實體路徑** | `C:\Web\xampp\htdocs\www\bbc-ai-gateway-test\index.php` |
| **類型** | XAMPP 隔離 vhost / 子目錄（非 production 路由） |
| **載入** | `C:\bbc-ai-bot\core\tour_search_service.php`（含 bootstrap / production client） |
| **與正式站區隔** | 不影響 `bonusmee.com` / `598go.com` / `kowanbo.com` 正式 LINE / SaaS 路由 |

---

## 3. 測試 URL

**預設參數（未帶 query 時由入口套用）：**

| 參數 | 預設值 |
|------|--------|
| `sno` | `e1fd133c7e8e45a1` |
| `keyword` | `富國島` |
| `page` | `1` |
| `pageSize` | `5` |

**範例 URL（Host A 本機 / 內網，依實際 Apache 主機名調整）：**

```http
GET http://{host-a}/bbc-ai-gateway-test/?sno=e1fd133c7e8e45a1&keyword=%E5%AF%8C%E5%9C%8B%E5%B3%B6&page=1&pageSize=5
```

**回應標頭：** `Content-Type: application/json; charset=utf-8`

---

## 4. 第一輪失敗結果（Host B HTTP 關閉）

**前置：** `.env` 維持 `GATEWAY_HOSTB_HTTP_ENABLED=false`（預設安全狀態）。

| 項目 | 結果 |
|------|------|
| **HTTP status** | **502** |
| **success** | **false** |
| **error.code** | **HOSTB_HTTP_DISABLED** |
| **error.message** | Host B HTTP is disabled for this environment. |
| **items** | `[]` |
| **pagination** | `null` |

**說明：** 隔離入口與 `TourSearchService` 正常運作；失敗原因為營運旗標關閉 Host B 出站 HTTP，屬預期安全行為，非入口程式錯誤。

---

## 5. 第二輪成功結果（暫時啟用 Host B HTTP）

**前置：** 測試窗口內 **暫時** 將 `.env` 設為 `GATEWAY_HOSTB_HTTP_ENABLED=true`（未 commit `.env`）。

| 項目 | 結果 |
|------|------|
| **HTTP status** | **200** |
| **success** | **true** |
| **error** | `null` |
| **pagination.total** | **2302** |
| **items（本頁筆數）** | **5** |
| **page / pageSize** | `1` / `5` |

### 5.1 第一筆商品（`items[0]`）

| 欄位 | 值 |
|------|-----|
| **title** | 太陽航空、直飛富國島～珍珠雙樂園、跳島海景纜車五日 直售23,888起 💎 |
| **price** | **23888** |
| **tourDate** | **2026/05/24** |

### 5.2 search_url

```
https://bonusmee.com/view/cloud/cloud_store_tourdate.php?openExternalBrowser=1&UnCarousel=1&fromDMDetailFlag=1&clearParam=Y&mode=1&sno=e1fd133c7e8e45a1&keyword=%E5%AF%8C%E5%9C%8B%E5%B3%B6
```

與 Stage 1-B-3 CLI live 測試及 `TourSearchService::buildSearchUrl()` 規則一致。

---

## 6. 安全恢復確認

| 步驟 | 結果 |
|------|------|
| 測試完成後將 `GATEWAY_HOSTB_HTTP_ENABLED` 還原為 **`false`** | 已執行 |
| 使用 **相同測試 URL** 再次請求 | **HTTP 502** |
| **success** | **false** |
| **error.code** | **HOSTB_HTTP_DISABLED** |

**結論：** 隔離入口在關閉 Host B HTTP 後恢復安全降級，無需重啟 Apache。

---

## 7. 安全確認

| 項目 | 狀態 |
|------|------|
| **未修改資料庫** | 是 |
| **未執行 SQL** | 是 |
| **未修改 PHP 程式**（`C:\bbc-ai-bot` repo） | 是 |
| **未修改 `.htaccess`（隔離目錄既有檔未改）** | 是 |
| **未修改 Apache 設定** | 是 |
| **未 git add / commit / push** | 是 |
| **API Key 未寫入本報告** | 是 |
| **`.env` 僅測試窗口暫時切換旗標，未提交** | 是 |

---

## 8. 結論

1. **隔離 HTTP 入口已通過 Live Host B 成功路徑** — 在 `GATEWAY_HOSTB_HTTP_ENABLED=true` 時，瀏覽器 / `curl` 可取得 HTTP 200、`pagination.total=2302`、5 筆商品與正確 `search_url`；關閉旗標後回復 `HOSTB_HTTP_DISABLED` / HTTP 502。
2. **與 Stage 1-B-3 一致** — HTTP 層結果與 CLI `TourSearchService` live 測試相符，證明入口僅為薄封裝，未改變業務邏輯。
3. **可進入 Stage 1-B-7** — **正式 Public Entry / LINE + Gemini 整合前準備**（規劃見 `docs/API_GATEWAY_MVP_PUBLIC_ENTRY_PLAN.md`）；實作前仍需另案授權，且不得將隔離測試 URL 當 production 契約。

**Stage 1-B-5 入口檔案位置（參考，位於 repo 外）：** `C:\Web\xampp\htdocs\www\bbc-ai-gateway-test\index.php`
