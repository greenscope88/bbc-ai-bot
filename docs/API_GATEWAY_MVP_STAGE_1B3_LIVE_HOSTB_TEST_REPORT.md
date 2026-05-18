# API Gateway MVP — Stage 1-B-3 Live Host B Integration Test Report

**Project root:** `C:\bbc-ai-bot`  
**Branch:** `feature/api-gateway-mvp`  
**Document date:** 2026-05-15  
**Type:** Live integration test completion report (read-only HTTP verification)

---

## 1. 測試目標

驗證 **Stage 1-B-2** 建立的 `TourSearchService` 在 Host A 上可透過實際 HTTP 呼叫 Host B `GET /api/tour/search`，並正確完成：

- 租戶 context 解析（`TenantContextResolver`）
- Host B 出站請求（`TourSearchRequestBuilder`、`HostBOutboundHeaderBuilder`、`MvpStagingHostBHttpClient`）
- 回應標準化（`items`、`pagination`、`search_url`）

本階段為 **唯讀整合測試**，不修改 `saas_router.php`、LINE webhook 或 Gemini prompt。

---

## 2. 測試環境

| 項目 | 值 |
|------|-----|
| **執行主機** | Host A（103.1.222.14） |
| **專案路徑** | `C:\bbc-ai-bot` |
| **分支** | `feature/api-gateway-mvp` |
| **PHP** | `C:\Web\xampp\php\php.exe` |
| **測試指令** | `tests/test_tour_search_service.php` |
| **Host B 啟用方式** | 測試窗口內暫時將 `.env` 設為 `GATEWAY_HOSTB_HTTP_ENABLED=true` |
| **測試後恢復** | `GATEWAY_HOSTB_HTTP_ENABLED=false` |
| **API Key** | 自 `.env` 的 `GATEWAY_HOSTB_API_KEY` 讀取（未寫入本文件） |

---

## 3. 測試參數

| 參數 | 值 |
|------|-----|
| **sno** | `e1fd133c7e8e45a1` |
| **keyword** | `富國島` |
| **page** | `1` |
| **pageSize** | `5`（測試腳本 live case 使用） |
| **traceId** | `test_trace_stage1b2`（測試套件） / `live_stage1b3`（補充驗證） |

**對應 tenant context（已於 Stage 1-B-1 確認）：**

| 欄位 | 值 |
|------|-----|
| depID | 888 |
| storeNo | 6290 |
| store_uid | 6290 |
| provider_id_no | 102 |

---

## 4. 測試結果

### 4.1 測試執行

```
OK: TourSearchService tests passed.
```

（含 live Host B integration cases；無 `GATEWAY_HOSTB_HTTP_ENABLED` skip。）

### 4.2 HTTP 與分頁

| 項目 | 結果 |
|------|------|
| **HTTP status** | **200** |
| **pagination.total** | **2302** |
| **items_count（本頁）** | **5** |
| **ok** | `true` |
| **errorCode** | `null` |

### 4.3 第一筆商品（標準化 `items[0]`）

| 欄位 | 值 | Host B 來源欄位 |
|------|-----|-----------------|
| **title** | 太陽航空、直飛富國島～珍珠雙樂園、跳島海景纜車五日 直售23,888起 💎 | `couponName` |
| **price** | **23888** | `price` |
| **tourDate** | **2026/05/24** | `tourDate` |
| **couponNo** | **11751** | `couponNo` |

### 4.4 search_url

```
https://bonusmee.com/view/cloud/cloud_store_tourdate.php?openExternalBrowser=1&UnCarousel=1&fromDMDetailFlag=1&clearParam=Y&mode=1&sno=e1fd133c7e8e45a1&keyword=%E5%AF%8C%E5%9C%8B%E5%B3%B6
```

規則與 `TourSearchService::buildSearchUrl()` 一致：保留 `sno`、`keyword`（UTF-8 URL 編碼），並帶前台固定參數（`openExternalBrowser`、`UnCarousel`、`fromDMDetailFlag`、`clearParam`、`mode=1`）。

---

## 5. 安全確認

| 項目 | 狀態 |
|------|------|
| **GATEWAY_HOSTB_HTTP_ENABLED 已恢復 `false`** | 是（測試結束後已還原） |
| **未修改資料庫** | 是 |
| **未執行 SQL** | 是 |
| **未修改其他程式** | 是（僅暫時切換 `.env` 旗標） |
| **未 git add / commit / push** | 是 |
| **API Key 未寫入 repo 或本報告** | 是 |

---

## 6. 結論

1. **`TourSearchService` 已通過 Host B 實際 HTTP 唯讀查詢驗證** — staging `sno` + `keyword=富國島` 回傳 HTTP 200、`pagination.total=2302`、商品列表與 `search_url` 均符合預期。
2. **可進入下一階段** — API Gateway MVP 對外入口整合，以及 LINE AI 回覆整合前準備（例如 `saas_router.php` / `AiPromptBuilder` 串接；需另案授權與審查）。

**相關 commit（參考，非本文件提交）：**

- `39d2962` — `feat(api-gateway): add tour search service`
- `3ad96b2` — `chore(api-gateway): update tenant provider mapping`
- `5619d01` — `feat(api-gateway): add tenant context resolver for tour search`

---

**File path:** `C:\bbc-ai-bot\docs\API_GATEWAY_MVP_STAGE_1B3_LIVE_HOSTB_TEST_REPORT.md`
