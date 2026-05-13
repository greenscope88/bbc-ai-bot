# API Gateway Phase 2 — HTTP Entry Precheck / Entry Plan

**專案根目錄：** `C:\bbc-ai-bot`  
**文件日期：** 2026-05-13  
**目的：** 將 Phase 1 的 `GatewayKernel` 從 CLI 測試推進到「正式 HTTP Entry 之前」的準備與規劃；本文件僅為計畫與前檢查清單，**不直接切入正式流量**。

---

## 1. Phase 2 目標

- 將 Phase 1 的模組（TraceId / Response Builder / Kernel）銜接到 **isolated 的 HTTP 測試入口**，以便進行瀏覽器 / curl 測試。
- 先做 **Entry 前置規劃與風險隔離**，不直接改動既有 LINE webhook / Web / API 正式入口。
- 建立可回滾、可移除的測試入口策略，確保不影響 production。

---

## 2. 目前 Phase 1 已完成項目摘要

### 2.1 程式模組

- `core/api_gateway/TraceIdMiddleware.php`
  - 讀取外部 `X-Trace-Id`（多種 header key），驗證長度 ≤ 64 且不得包含控制字元；不合法則改採 UUID v4。
- `core/api_gateway/ErrorResponseBuilder.php`
  - `details` 預設 `null`，外部 JSON 形狀為 `object | null`；`timestamp` 為 UTC ISO-8601 且 `Z` 結尾；`json_encode` 失敗時有安全 fallback JSON。
- `core/api_gateway/GatewayKernel.php`
  - 仍為骨架（尚未接正式入口），保留未來回應 header `X-Trace-Id` 的 TODO（不輸出 `header()`）。

### 2.2 測試入口（CLI）

- `tests/api_gateway/test_gateway_phase1.php`
  - 覆蓋 TraceId 行為、ErrorResponseBuilder JSON 形狀與 timestamp、Kernel execute() 回傳含 traceId 的 context。

### 2.3 文件（Phase 1）

- `docs/API_GATEWAY_PHASE1_COMPLETION_REPORT.md`
- 其他 Phase 1 設計 / 前置文件：`docs/API_GATEWAY_*.md`

---

## 3. 正式 HTTP Entry 候選位置盤點（暫不實作）

> 本節只盤點「可能入口」與「待確認事項」，Phase 2 不直接把 Gateway 接上正式流量。

### 3.1 Public entry 可能位置（待確認）

- `public/` / `htdocs/` / `wwwroot/` 類型目錄是否存在、是否已有 routing front-controller（例如 `index.php`）。
- 是否已有反向代理 / rewrite 規則會導向既有入口。

### 3.2 LINE webhook 入口（待確認）

- 既有 LINE webhook 的 PHP 入口檔案位置與部署路徑（例如 `webhook/` 下的 callback 檔）。
- 是否有固定 header guard、是否有嚴格的回應格式需求與超時限制。

### 3.3 Web Chat 入口（待確認）

- Web chat / admin panel 之對外 endpoint 是否存在、是否有 session/csrf 影響。

### 3.4 API proxy 入口（待確認）

- 是否存在內部 API proxy/relay endpoint（例如 `/api/*` 或 `/gateway/*`）。
- 既有 gateway/proxy 是否已處理 tenant / auth / rate-limit。

### 3.5 待確認清單（Phase 2 前置）

- 目標主機 A 的 web root 實際路徑（XAMPP / IIS / Nginx）。
- 既有正式入口檔是否有「不可修改區段」或保護規範。
- 既有 log / audit / error handling 期望格式。

---

## 4. Phase 2 最小安全範圍（Minimum Safe Scope）

- **只建立 isolated HTTP test entry**（可移除）。
- **不影響**既有 LINE 正式 webhook。
- **不影響**既有 SaaS router / 既有路由規則。
- **不影響**主機 B 的 API。
- **不執行 SQL**、不新增資料表、不碰 DB schema。
- 不引入新套件、不接外部流量切換。

---

## 5. 建議新增測試入口草案（isolated）

> 以下僅為建議路徑與入口形式，Phase 2 先以「不影響既有路由」為第一原則。

### 5.1 候選 isolated test path（範例）

- `C:\Web\xampp\htdocs\www\bbc-ai-gateway-test\index.php`

**必須明確標示：** 此為測試入口，不可接正式流量（不得掛在既有 webhook path、不得替換既有 `index.php`）。

### 5.2 測試入口基本責任

- 讀取 HTTP request（method、path、query、headers、body）。
- 產生 requestContext（至少含 traceId）。
- 呼叫 `GatewayKernel->execute($context)`（Phase 2 仍可先回傳 context / stub response，不接實際業務）。
- 回傳 JSON（包含 traceId 與 timestamp），並保留未來加 `X-Trace-Id` response header 的 TODO。

---

## 6. HTTP Request / Response 測試案例（Phase 2 建議）

### 6.1 正常請求

- GET/POST 基本請求可得到 200（或 4xx/5xx 依 stub 設計），response JSON 具備一致欄位。

### 6.2 缺少 tenant / sno

- 模擬缺少必要參數：應回應一致 error JSON（由 `ErrorResponseBuilder` 產生）。

### 6.3 不合法 method

- 例如 PUT/DELETE：應回應 405 或一致的錯誤格式（明確 message / errorCode）。

### 6.4 模擬 Gateway error

- 模擬 kernel 內部例外：確認回應 JSON 不洩漏敏感資訊、traceId 存在、timestamp 正確。

### 6.5 trace_id 是否回傳

- 無外部 `X-Trace-Id`：回應內 `traceId` 為 UUID v4。
- 有合法 `X-Trace-Id`：回應內 `traceId` 沿用。
- 不合法 `X-Trace-Id`：回應內 `traceId` 改為 UUID v4。
- （未來）response header `X-Trace-Id` 與 body traceId 一致。

### 6.6 error JSON 是否一致

- success/errorCode/message/traceId/timestamp/details 欄位齊全。
- `details=null` 時為 JSON null；`details` 為 object 時，形狀一致（非 array）。
- timestamp 為 UTC 且 `Z` 結尾。

---

## 7. Rollback / 安全策略

- isolated 測試入口可直接移除（刪除單一資料夾 / 虛擬目錄）。
- 不改正式 webhook 檔案，不改正式 router，不改 rewrite 規則（或只新增獨立 path）。
- 不改 DB、不執行 SQL、不建立資料表。
- 不影響 production：不切流量、不替換既有入口、不覆蓋既有路徑。

---

## 8. Phase 2 後續實作分段建議

- **Step 2-A：** 建立 isolated test entry 文件/草案（標註不可接正式流量、列出部署位置與移除方式）。
- **Step 2-B：** 建立 HTTP entry 測試檔（僅 stub kernel 呼叫與 JSON 回應，不接業務）。
- **Step 2-C：** 本機/主機 A 瀏覽器測試（確認回應 JSON 與 traceId 行為）。
- **Step 2-D：** curl 測試（覆蓋 header、method、錯誤案例）。
- **Step 2-E：** Phase 2 completion report（含測試結果與不影響 production 的證據）。
- **Step 2-F：** 獨立 commit/push（只包含 Phase 2 新增的 isolated entry 與文件；避免混入其他變更）。

