# API Gateway Phase 5 Stage 1 — HTTP Entry Integration Plan

**專案根目錄：** `C:\bbc-ai-bot`  
**文件版本：** 2026-05-14  
**性質：** 規劃文件（Planning only）。**不**實作程式、**不**建立 `index.php`、**不**修改 `.htaccess`、**不**執行 SQL、**不**呼叫 Host B API。

---

## 文件目的

本文件規劃如何將 API Gateway **正式掛入主機 A 的 HTTP entry**，並維持：

- **完全可回滾**（獨立入口、可一鍵關閉或還原）  
- **低風險、分階段導入**（先 smoke、再放量）  
- **與既有網站／API 隔離**，避免影響 LINE webhook 或其他 production 主路徑  

本階段**只做設計**，不包含實作或部署執行。

---

## 1. Stage 1 Objective

| 目標 | 說明 |
|------|------|
| **建立 production HTTP entry integration 設計** | 定義 URL 前綴、Document Root 與專案 `bootstrap` 的銜接方式。 |
| **定義新入口檔案位置** | 以獨立 `index.php`（或單一 front controller）為 Gateway 唯一 HTTP 邊界。 |
| **規劃與現有網站的隔離方式** | 專用路徑前綴（例如 `/api/gateway/`）、獨立 vhost 或子目錄；與既有 `public/`、webhook 入口分離。 |
| **確保可快速 rollback** | 不覆蓋既有 entry；以「新增檔案 + rewrite」為主，回滾時移除 rewrite 或改指舊路徑即可。 |

---

## 2. Recommended Entry Location

### 建議正式入口（主機 A / XAMPP 慣例 Document Root）

**首選：**

`C:\Web\xampp\htdocs\www\api\gateway\index.php`

**理由摘要：**

- 與主站台 `www` 分層，`/api/gateway/` 語意清楚，便於監控與 WAF 規則。  
- 獨立子目錄利於權限與部署包分離；rollback 時可整段停用目錄或 rewrite。  
- 與 Phase 5 總計畫「不直接覆蓋 production entry」一致。

### 替代位置

| 路徑（概念） | 說明 |
|--------------|------|
| `/api/index.php`（例如 `htdocs\www\api\index.php`） | 較短 URL，但易與其他 REST API 混淆；需更嚴格路由判斷。 |
| `/gateway/index.php`（例如 `htdocs\www\gateway\index.php`） | 與業務 path 命名空間分離，但若站內已有 `gateway` 語意需避免衝突。 |

### 與儲存庫（repo）路徑的對應說明

部署時可擇一策略（實作階段再定案）：

- **策略 A：** 在 repo 維護 `public/api/gateway/index.php` 作為**版本化來源**，建置／發佈時複製或同步至 `htdocs\www\api\gateway\index.php`。  
- **策略 B：** 僅在 `htdocs` 建立入口，透過 `require` 指向 `C:\bbc-ai-bot\bootstrap.php`（或相對／絕對路徑已驗證之載入點）。  

**設計原則：** 無論檔案落在 `htdocs` 或 repo `public/`，**對外 URL** 應穩定為 **`/api/gateway/*`**（或由 vhost 根前綴映射），且**僅**該 front controller 接收 Gateway 流量。

---

## 3. HTTP Request Lifecycle

以下為建議之**完整請求流程**（由外而內；錯誤路徑仍應保留同一 `traceId`）。

1. **Apache Virtual Host** — 綁定網域、`DocumentRoot`、TLS（HTTPS）、錯誤與存取 log。  
2. **`.htaccess` Rewrite** — 將 `/api/gateway/*` 導向單一 `index.php`（**本文件不修改**既有 `.htaccess`；實作階段另開變更單）。  
3. **`public/api/gateway/index.php`（或已部署之對應 `htdocs` 路徑）** — 唯一 Gateway HTTP 邊界；僅做載入與委派（見第 4 節）。  
4. **`bootstrap.php`** — 自動載入、環境變數、錯誤層級、DI 容器（若專案採用）等。  
5. **`TraceIdMiddleware`** — 解析／產生 `traceId`，寫入 request context。  
6. **`ErrorResponseBuilder`** — 錯誤路徑統一 JSON 封套（含 `traceId`、UTC 時間戳）。  
7. **`GatewayKernel`** — 驅動 middleware／service chain、統一例外邊界。  
8. **Tenant Mapping Resolver** — `sno`（或約定識別）→ 租戶 context。  
9. **API Key Verification** — 金鑰驗證與租戶一致性。  
10. **Service Permission Validation** — service 與允許清單交集。  
11. **Rate Limiting** — 於進 Host B 前執行；超限 429。  
12. **Host B Proxy** — 建構上游請求並由 HTTP client 送出（Stage 1 可仍為 stub／503 feature flag，依實作切分）。  
13. **Response Normalizer** — 上游回應 → 對外一致 JSON。  
14. **Audit Logger** — 成功／失敗皆記錄（持久化於後續 Stage）。  
15. **JSON Response** — HTTP status + body；可選 `X-Trace-Id` response header。

> **對齊 Phase 5 總計畫：** 檢查順序與 `API_GATEWAY_PHASE5_PRODUCTION_INTEGRATION_PLAN.md` 第 2 節一致；Stage 1 重點在 **步驟 1～4 與 15** 的可運行最小閉環，其餘模組可 stub 或 feature-flag 關閉直至後續 Stage。

---

## 4. Entry File Responsibilities

`index.php`（Gateway entry）**只應負責**：

- 載入 **`C:\bbc-ai-bot\bootstrap.php`**（或專案約定之唯一 bootstrap）。  
- 建立 **Request Context**（method、path、headers、body 指標、`traceId` 占位等）。  
- 呼叫 **`GatewayKernel`**（或等價之單一 orchestrator）並取得 response DTO／陣列。  
- 輸出 **JSON**（`Content-Type: application/json`）與適當 HTTP status。

**不得**於 entry 檔內放置：

- 租戶／金鑰／限流／Host B 等**商業或安全邏輯**（應在 `core/api_gateway/` 分層實作）。  
- 直接 SQL、curl、`file_get_contents` 至 Host B（應在 service／http client 層）。

---

## 5. Required Bootstrap Dependencies

| 依賴 | 用途 |
|------|------|
| `C:\bbc-ai-bot\bootstrap.php` | 專案主要載入、autoload、環境初始化。 |
| `C:\bbc-ai-bot\config\bootstrap.php`（若存在） | 組態匯入、環境分段、optional modules。 |
| `C:\bbc-ai-bot\core\api_gateway\*` | GatewayKernel、TraceId、ErrorResponseBuilder 及後續 middleware／services。 |
| **Environment variables** | DB（後續）、Host B base URL／timeout、feature flag、log level；**不得**將 secret 寫入 repo。 |

---

## 6. Proposed Public Directory Structure

```
C:\Web\xampp\htdocs\www\api\gateway\index.php     # 建議對外 Document Root 內之入口（部署產物或同步檔）

C:\bbc-ai-bot\core\api_gateway\                   # Gateway 核心與（未來）middleware／services
C:\bbc-ai-bot\config\                             # 環境與路由相關設定（不含 secret 明文）
C:\bbc-ai-bot\logs\                               # 若專案慣例寫入檔案 log（審慎啟用；稽核優先走 DB／集中 log）
```

**選項：** 若團隊偏好「入口檔納入版控」，可於 repo 增加 `public/api/gateway/index.php` 作為**範本**，與 `htdocs` 部署路徑對應（實作階段決策）。

---

## 7. Routing Strategy

- **路徑前綴：** 所有 **`/api/gateway/*`** 由同一 `index.php` 接收（由 Apache rewrite 保證）。  
- **Service 決策：** 由 **server-side** 路由規則或**單一** JSON／path 參數（例如 `service=tour.search`）決定實際服務；**禁止** client 指定任意 upstream URL。  
- **Upstream 固定：** Host B base URL 僅來自 **config／環境變數**；request builder 組出之相對 path 受 allowlist 約束（對齊 Phase 4 `HostBRequestBuilder` 設計精神）。

---

## 8. Security Considerations

| 項目 | 說明 |
|------|------|
| **HTTPS only** | Production 強制 TLS；HSTS 依站點政策。 |
| **Trace ID injection** | 信任邊界外之 `X-Trace-Id` 需驗證長度與字元集；非法則捨棄並由系統產生（對齊既有 middleware）。 |
| **Header validation** | 必要 header（如 API key、內容類型）與大小上限；防 HTTP 走私與 oversized header。 |
| **Input sanitization** | Query／body 大小限制、JSON depth；禁止 raw URL 作為 upstream。 |
| **Error normalization** | 統一錯誤 JSON；不洩漏內部堆疊於 production。 |
| **Sensitive info masking** | Log／audit 遮罩規則對齊 Phase 3／4 設計；不記錄完整 API key。 |

---

## 9. Rollback Strategy

- **新增獨立入口檔**：不覆蓋既有 `index.php` 或 webhook 入口。  
- **不覆蓋既有 API**：Gateway 僅佔用新前綴 `/api/gateway/`。  
- **Rollback**：移除或註解 rewrite 規則、還原 vhost、或將 `index.php` 改為靜態「503 maintenance」頁面；**數分鐘內**可切回舊行為。  
- **保留 Phase 4 isolated tests**：CI 仍跑 `tests/api_gateway/test_*_isolated.php`，與 HTTP entry 解耦。

---

## 10. Deployment Checklist

- [ ] 建立目錄 `www/api/gateway/`（或等價路徑）  
- [ ] **（實作階段）** 建立 `index.php`（本規劃文件**不**建立該檔）  
- [ ] **（實作階段）** 設定 Apache rewrite／alias；變更前備份現有 `.htaccess`  
- [ ] Smoke test：GET health、404 JSON 形狀、`traceId` 存在  
- [ ] Rollback plan：還原步驟與負責人寫入 runbook  
- [ ] 監控：4xx/5xx 比率、延遲、新 path 命中數  

---

## 11. Success Criteria

- HTTP entry **可成功載入** `C:\bbc-ai-bot\bootstrap.php`（或約定路徑）且無 fatal error。  
- 可呼叫 **`GatewayKernel`** 並取得結構化結果（即使內部為 stub）。  
- 回應 **JSON 格式**符合 `ErrorResponseBuilder`／成功 envelope 約定。  
- **`traceId`** 於成功與錯誤回應中皆可追溯（header 或 body 依專案凍結規格）。  

---

## 12. Recommended Next Step

建議下一步建立 **SQL Repository 整合** 之專章計畫（仍為文件，不執行 DDL）：

**文件路徑：** `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE5_STAGE2_SQL_REPOSITORY_PLAN.md`

內容建議涵蓋：`api_gateway_*` 表與 Phase 4 mock repository 的介面對應、migration 策略、唯讀先行、以及與 Tenant／Key／Audit／Rate limit 的資料擁有權與 DBA 流程。

---

## 安全與變更聲明（本文件交付）

| 項目 | 狀態 |
|------|------|
| 修改任何 PHP production code | **未進行** |
| 建立 `index.php` | **未進行** |
| 修改 `.htaccess` | **未進行** |
| 執行 SQL | **未進行** |
| 呼叫 Host B API | **未進行** |
| `git add` / `commit` / `push` | **未進行** |

**本文件路徑：** `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE5_STAGE1_HTTP_ENTRY_PLAN.md`
