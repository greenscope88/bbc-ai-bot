# API Gateway MVP — Stage 1-B-8 Public API Live Success Test Report

**Project root:** `C:\bbc-ai-bot`  
**Branch:** `feature/api-gateway-mvp`  
**Document date:** 2026-05-19  
**Type:** Formal public API entry live verification report (HTTP / curl)

**Related:** `docs/API_GATEWAY_MVP_STAGE_1B6_HTTP_ENTRY_TEST_REPORT.md`, `docs/API_GATEWAY_MVP_STAGE_1B3_LIVE_HOSTB_TEST_REPORT.md`, `docs/API_GATEWAY_MVP_PUBLIC_ENTRY_PLAN.md`

**Formal entry (repo 外):** `C:\Web\xampp\htdocs\www\api\gateway\tour\search.php`

---

## 1. 測試目的

驗證 **Stage 1-B-7** 建立的正式對外 API 入口：

`https://bonusmee.com/api/gateway/tour/search.php`

在 Host A 上可透過實際 HTTP 呼叫 `TourSearchService`，並在暫時啟用 `GATEWAY_HOSTB_HTTP_ENABLED` 時完成 Host B 唯讀查詢成功路徑；測試結束後須立即關閉 Host B HTTP，確認安全降級仍有效。

本階段 **不** 修改 PHP 程式、`.htaccess`、Apache、資料庫；**僅** 在測試窗口內暫時切換 `.env` 旗標（未提交 `.env`）。

---

## 2. 正式 API URL

```
https://bonusmee.com/api/gateway/tour/search.php
```

**完整測試請求範例：**

```
https://bonusmee.com/api/gateway/tour/search.php?sno=e1fd133c7e8e45a1&keyword=%E5%AF%8C%E5%9C%8B%E5%B3%B6&page=1&pageSize=5
```

---

## 3. 測試參數

| 參數 | 值 |
|------|-----|
| **sno** | `e1fd133c7e8e45a1` |
| **keyword** | `富國島` |
| **page** | `1` |
| **pageSize** | `5` |

**對應 tenant context（已於 Stage 1-B-1 / provider mapping 確認）：**

| 欄位 | 值 |
|------|-----|
| depID | 888 |
| storeNo | 6290 |
| store_uid | 6290 |
| provider_id_no | 102 |

---

## 4. 測試流程

| 步驟 | 動作 | 結果 |
|------|------|------|
| 1 | 確認 `.env` 中 `GATEWAY_HOSTB_HTTP_ENABLED=false` | 已確認 |
| 2 | 暫時改為 `GATEWAY_HOSTB_HTTP_ENABLED=true` | 已執行（測試窗口） |
| 3 | 以 curl / HTTP 呼叫正式 API URL | 已執行 |
| 4 | 記錄 JSON 回應與 HTTP 狀態 | 見第 5 節 |
| 5 | **立即** 恢復 `GATEWAY_HOSTB_HTTP_ENABLED=false` | 已執行 |
| 6 | 使用相同 URL 再次請求，確認安全降級 | 見第 6 節 |

---

## 5. 成功測試結果（Host B HTTP 啟用時）

| 項目 | 結果 |
|------|------|
| **HTTP status** | **200** |
| **success** | **true** |
| **error** | `null` |
| **pagination.total** | **2302** |
| **items（本頁筆數）** | **5** |

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

與 `TourSearchService::buildSearchUrl()` 及 Stage 1-B-3 / 1-B-6 隔離入口結果一致。

---

## 6. 安全恢復驗證（Host B HTTP 關閉後）

| 項目 | 結果 |
|------|------|
| **GATEWAY_HOSTB_HTTP_ENABLED** | **`false`**（已恢復） |
| **HTTP status（重測）** | **502** |
| **success** | **false** |
| **error.code** | **HOSTB_HTTP_DISABLED** |
| **error.message** | Host B HTTP is disabled for this environment. |

**結論：** 正式入口在關閉 Host B HTTP 後恢復預期安全降級，無需重啟 Apache。

---

## 7. 回應格式驗證

| 項目 | 結果 |
|------|------|
| **Content-Type** | `application/json; charset=utf-8` |
| **JSON 結構** | 含 `success`、`traceId`、`sno`、`keyword`、`pagination`、`items`、`search_url`；失敗時含 `error.code` / `error.message` |
| **無 PHP warning / stack trace** | 是 |

---

## 8. 安全確認

| 項目 | 狀態 |
|------|------|
| **未修改其他 PHP 程式** | 是 |
| **未修改 `.htaccess`** | 是 |
| **未修改 Apache 設定** | 是 |
| **未修改資料庫** | 是 |
| **未執行 SQL** | 是 |
| **`.env` 僅暫時切換旗標，未 commit** | 是 |
| **未 git add / commit / push** | 是 |
| **API Key 未寫入本報告** | 是 |

---

## 9. 結論

1. **正式 Public API Entry 已通過 Live Host B 成功路徑驗證** — `https://bonusmee.com/api/gateway/tour/search.php` 在 `GATEWAY_HOSTB_HTTP_ENABLED=true` 時回傳 HTTP 200、`pagination.total=2302`、5 筆商品與正確 `search_url`；關閉後回復 `HOSTB_HTTP_DISABLED` / HTTP 502。
2. **API Gateway MVP 已具備正式上線條件** — 租戶 context、TourSearchService、正式 HTTP 入口與安全旗標行為均已驗證；後續 LINE / Gemini 整合可消費同一 JSON 契約。
3. **可進入 Stage 1-B-9** — **Git Push + MVP Launch**（推送 `feature/api-gateway-mvp`、部署與營運啟用需依變更管理流程；上線前仍須以營運窗口控制 `GATEWAY_HOSTB_HTTP_ENABLED` 與 API Key 輪替）。

**與前階段對照：**

| 階段 | 入口 | 驗證重點 |
|------|------|----------|
| 1-B-3 | CLI `test_tour_search_service.php` | TourSearchService + Host B |
| 1-B-6 | 隔離 HTTP `bbc-ai-gateway-test` | 瀏覽器 / curl 薄封裝 |
| 1-B-7 | 正式 `api/gateway/tour/search.php` | 正式 URL 建立 + 降級 |
| **1-B-8** | **同上正式 URL** | **Live 成功路徑 + 恢復驗證** |
