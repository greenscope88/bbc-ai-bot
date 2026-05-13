# API Gateway Phase 2 — Step 2-C HTTP 測試報告

**專案根目錄：** `C:\bbc-ai-bot`  
**測試時間（主機 A）：** 2026-05-13（UTC+8 約 15:32 前後）  
**目的：** 驗證 isolated HTTP test entry 在正確 URL 下可回傳預期 JSON、TraceId 與 query 解析；並記錄錯誤路徑與非入口路徑的 Apache 行為，作為後續 front-controller／rewrite 設計依據。

---

## 1. 測試時間與目的

- **時間：** 2026-05-13（Step 2-C 補測執行時段）
- **目的：**
  - 確認 `GatewayKernel` 經由 isolated `index.php` 在 HTTP 下可正常回應 JSON。
  - 確認 `Content-Type`、`traceId`、query string 傳入 `requestContext` 是否正確。
  - 區分「Gateway 內錯誤 JSON」與「Apache 層 403 HTML」（尚未統一為 Gateway error）。

---

## 2. Isolated HTTP test entry 路徑

`C:\Web\xampp\htdocs\www\bbc-ai-gateway-test\index.php`

（此為 XAMPP 下之測試入口，**不可**接正式流量。）

---

## 3. 正確測試網址

`http://localhost/bbc-ai-gateway-test/index.php`

（`DocumentRoot` 為 `C:\Web\xampp\htdocs\www` 時，路徑不應再重複 `/www/`。）

---

## 4. 本次已確認（正確網址）

以 `Invoke-WebRequest` / 實測為準：

- **HTTP 200**
- **Content-Type：** `application/json; charset=utf-8`
- **JSON：** `success` 為 `true`（`errorCode`: `OK`）
- **traceId：** 正常產生（UUID 格式；欄位名為 `traceId`）
- **Query string：** 可被解析並出現在 `details.context.request.query`（例如 `sno`、`service`）

---

## 5. 測試案例摘要

| 案例 | URL | 結果摘要 |
|------|-----|----------|
| 基本 GET | `http://localhost/bbc-ai-gateway-test/index.php` | **成功**：200，JSON，`traceId` 正常 |
| 含 query | `http://localhost/bbc-ai-gateway-test/index.php?sno=test001&service=ping` | **成功**：200，JSON，query 出現在 context |
| 不存在路徑 | `http://localhost/bbc-ai-gateway-test/not-found` | **Apache 403**：`text/html`，標準 Forbidden 頁；**尚未**進 Gateway JSON error |
| 錯誤前綴 | `http://localhost/www/bbc-ai-gateway-test/index.php` | **Apache 403**：`text/html`；屬 **錯誤路徑**（多一層 `/www/`） |

---

## 6. 安全確認

- **未修改**正式 LINE webhook
- **未修改**正式 SaaS router
- **未修改**主機 B API
- **未執行** SQL

（本報告僅文件化測試結果；產出報告當下不變更執行環境。）

---

## 7. 已知限制

- **目前僅有** `index.php` 作為進入 Gateway 的 HTTP 入口；直接請求其他路徑不會自動載入同一支程式。
- **非** `index.php` 或不存在之路徑仍由 **Apache** 處理（例如 403 HTML），**不會**自動轉成 `ErrorResponseBuilder` 的 JSON。
- 若需「所有錯誤路徑均回 Gateway 一致 JSON」，後續需 **rewrite / front controller**（僅限 isolated 測試目錄或獨立 vhost，避免影響正式站）。

---

## 8. 建議下一步

1. **Step 2-D：** 為 `bbc-ai-gateway-test` **單獨**設計 front-controller rewrite（例如統一導向 `index.php`），**不**直接改正式站 `.htaccess` 行為範圍外之路由。
2. **或 Step 2-E：** 先彙整 Phase 2 文件與測試證據，產出 **Phase 2 completion report**，再決定是否實作統一錯誤 JSON。

---

*本文件由 API Gateway Phase 2 Step 2-C 任務產出。*
