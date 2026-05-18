# API Gateway Phase 5 Stage 3 — Host B HTTP Integration Plan

**專案根目錄：** `C:\bbc-ai-bot`  
**文件版本：** 2026-05-14  
**性質：** 規劃文件（Planning only）。**不**實作程式、**不**建立 PHP class、**不**呼叫 Host B API、**不**執行 SQL、**不**進行 git 寫入。

---

## 文件目的

本文件規劃如何將 Phase 4 之 **Mock Host B Proxy Middleware** 替換為 **正式 Host B HTTP Client**，使主機 A 的 API Gateway 得以在受控條件下安全呼叫主機 B（Server B）之 **ASP.NET Web API**，並維持：

- **Client 無法指定 upstream URL**（僅允許 server-side service → endpoint 對照）  
- **Multi-tenant 隔離**與既有 Phase 3／4 安全治理（trace、遮罩、錯誤正規化）

本階段**只做設計**，不包含實作或對外連線驗證。

---

## 1. Stage 3 Objective

| 目標 | 說明 |
|------|------|
| **規劃正式 Host B HTTP Integration** | 從「mock responder」過渡到「真實 HTTP + 錯誤映射 + 可觀測」。 |
| **定義 HTTP Client 與 Proxy Service 架構** | 明確切分：`HostBRequestBuilder`（描述子）、`HostBHttpClient`（傳輸）、`HostBProxyService`（編排）、`UpstreamErrorMapper`（語意轉換）。 |
| **確保 client 無法指定 upstream URL** | 僅接受 **gateway service id**；實際 URL 由 **config 凍結表** + allowlist 組出。 |
| **保持多租戶隔離與安全治理** | Header／body 白名單；敏感欄位不入 log；`traceId` 貫穿。 |

---

## 2. Host B API Context（環境說明）

以下為目前規劃所依據之 **Host B 環境**（實際連線與憑證以部署後設定為準；本文件不進行連線測試）。

| 項目 | 說明 |
|------|------|
| **Server B** | `103.1.222.11`（主機 B） |
| **資料庫** | SQL Server 2019（業務資料；**非**本 Stage Gateway 直接查詢對象，除非另有設計） |
| **應用類型** | ASP.NET Web API |
| **Swagger 測試頁** | `http://103.1.222.11:8080/swagger/index.html`（**僅供開發／對照用**；production 對外應以 HTTPS 與網路 ACL 重新評估） |

> **注意：** Production 是否允許明文 HTTP、是否改為 HTTPS、以及防火牆來源 IP（僅主機 A）須由資安與維運核定；本文件僅列已知測試位址。

---

## 3. Proposed HTTP Components

| 元件 | 職責 |
|------|------|
| **HostBHttpClient** | 執行實際 HTTP：TLS／timeout、request 送出、response body 讀取、連線層錯誤；**不**解析業務規則。 |
| **HostBRequestBuilder** | 延續 Phase 4 精神：依 `traceId`、normalized `service`、`authenticatedContext`、允許之 query／body 組出 **相對 path + headers + body**；禁止拼接 client 提供之 host。 |
| **HostBResponseNormalizer** | 將 Host B JSON 轉為對外 **統一 envelope**（成功／失敗欄位與 `traceId` 規格對齊專案凍結文件）。 |
| **HostBProxyService** | 編排：呼叫 builder → client → normalizer → 回傳 DTO；集中處理「非 2xx」與「非 JSON」分支。 |
| **UpstreamErrorMapper** | 將 Host B HTTP status／body errorCode（若有）映射為 Gateway `errorCode` + HTTP status（見第 10 節）。 |

---

## 4. Request Flow

以下為 **Host B 整合路徑**之建議順序（假設 Tenant／API Key／Rate limit 已於前序 middleware 通過）。

1. **GatewayKernel** — 進入 proxy 階段、注入已驗證之 context。  
2. **Service Permission Validation** — 再次確認（或快取後）service 仍允許；避免繞過。  
3. **HostBRequestBuilder** — 產出 Host B 請求描述（path、method、headers、body）。  
4. **HostBHttpClient** — 依 **server config base URL** + 固定 endpoint 送出請求。  
5. **Response Normalizer** — 解析 body、處理空 body／非 JSON。  
6. **Audit Logger** — 記錄摘要（成功／失敗、耗時、http status；敏感遮罩）。  
7. **JSON Response** — 回傳客戶端最終 JSON（與 `ErrorResponseBuilder` 錯誤形狀一致）。

---

## 5. Base URL Governance

- **Base URL 僅能由 server config 決定**（環境變數或 `config/*.php` 載入後之唯讀物件）；**禁止**從 request header／body 讀取 host。  
- **Client 不可傳入 upstream URL**；若偵測到嘗試覆寫 host／path 之欄位，應於 **Request Builder 前**拒絕（對齊 Phase 3 設計精神）。  
- **每個 gateway `service` 對應固定 endpoint**（path + method allowlist）；新增 service 必須走變更流程與 code review。

---

## 6. Service-to-Endpoint Mapping

下表為 **規劃用**對照；**實際 path 與方法**須以 Host B Swagger（`103.1.222.11:8080`）與後端團隊確認後凍結。Phase 4 isolated 使用之 `/__gateway__/isolated/hostb/forward` **不得**作為 production upstream。

| Service Name（Gateway） | Host B Endpoint（草案／待確認） | Allowed Methods |
|-------------------------|----------------------------------|-----------------|
| `tour.search` | `TBD`（例：`/api/tour/search` 或 Swagger 對應路由） | `GET`, `POST`（以 Swagger 為準） |
| `order.query` | `TBD`（例：`/api/order/query`） | `GET`, `POST`（以 Swagger 為準） |
| `svc.minute` / `svc.disabled` 等 **測試用 service** | **不映射** | —（僅限 isolated／staging mock；production allowlist 應剔除） |

> **實作前必做：** 由後端提供「service id → (path, methods)」正式表，並納入單元測試 allowlist。

---

## 7. Timeout and Retry Policy

| 項目 | 建議（可於實作時依 SLO 調整） |
|------|-------------------------------|
| **Connect timeout** | 1～3 秒（內網可偏低；跨網段則調高） |
| **Read timeout** | 依 service 分級：查詢型 5～15 秒；重操作另表 |
| **Retry 次數** | 預設 **0～1**；僅對 **幂等** 且 **GET** 或明確 idempotent 之操作允許；**POST 預設不重試** |
| **Backoff** | 若啟用 retry：指數退避 + jitter；總重試時間上限避免阻塞 worker |

**原則：** Timeout 必須寫入稽核（對齊 Phase 3 Audit 設計）；避免無限重試造成雪崩。

---

## 8. Response Normalization

- **統一 JSON 格式**：對外 `success`／錯誤碼／`traceId`／`timestamp` 等欄位與 `ErrorResponseBuilder`／既有 API 規格對齊。  
- **遮蔽 Host B 內部錯誤**：不向前端暴露 stack、內部 host name、SQL 片段；可映射為 `GW_UPSTREAM_ERROR` 等。  
- **保留 `traceId`**：與 Access／Audit log 一致；必要時回應 header `X-Trace-Id`（與 `GatewayKernel` TODO 對齊）。

---

## 9. Security Considerations

| 項目 | 說明 |
|------|------|
| **Upstream URL 固定** | 由 config + mapping 表組合；拒絕任意 URL。 |
| **Header 白名單** | 僅轉發必要 header（如 `Content-Type`、`X-Trace-Id`、Host B 所需之簽章 header）；禁止轉發 client `Authorization` 至 Host B 除非架構明確要求。 |
| **Query 參數白名單** | 對齊 `HostBRequestBuilder` 之 `normalizeQuery` 策略擴充為 production allowlist。 |
| **Sensitive header masking** | Log／audit 不記錄 token／cookie／完整 key。 |

---

## 10. Error Mapping

| 情境 | Gateway 建議處理 |
|------|------------------|
| **Host B timeout** | HTTP **504** 或 **502**（依產品一致）；`errorCode` 例：`GW_UPSTREAM_TIMEOUT`；稽核記錄。 |
| **401** | 視為上游拒絕憑證；映射 **401** 或 **502**（避免洩漏上游細節；需與產品一致）。 |
| **403** | 映射 **403**；`errorCode` 與訊息通用化。 |
| **404** | 映射 **404** 或業務型 **400**（若不希望暴露資源存在性）。 |
| **429** | 可映射 **429**；與 Gateway rate limit 區分（`details` 標示來源 `upstream`）。 |
| **500** | 映射 **502** 或 **500**（依「是否暴露上游故障」政策）；預設避免回傳 raw body。 |

---

## 11. Rollback Strategy

- **保留 Mock Proxy**（Phase 4 `HostBProxyMiddleware` + injectable mock）於 codebase。  
- **透過 config 切換** `hostb.transport=mock|http`。  
- **關閉 integration**：切回 mock 或回傳 503「維護模式」JSON；無需移除 DB 或 Gateway entry（與 Stage 1 rollback 相容）。

---

## 12. Deployment Checklist

- [ ] **Config 設定**：base URL、timeout、TLS 信任鏈、（若有）client cert  
- [ ] **HTTP Client 建立**：集中 factory、連線上限、DNS 快取策略  
- [ ] **Integration tests**（staging）：對真實或 mock server 之契約測試  
- [ ] **Timeout 測試**：人為延遲／toxiproxy 類工具驗證行為與稽核  

---

## 13. Success Criteria

- 在允許之網路條件下，Gateway **可安全呼叫** Host B API（來源 IP、ACL、TLS 已核定）。  
- **回應正常化**符合對外 JSON 規格且不洩漏內部細節。  
- **Timeout 可控**且可觀測（metrics + audit）。  
- **`traceId` 傳遞正常**（請求入 Host B、錯誤回應出 Gateway 均可追蹤）。  

---

## 14. Recommended Next Step

建議下一步建立 **Audit Log 持久化** 規劃文件：

**文件路徑：** `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE5_STAGE4_AUDIT_LOG_PERSISTENCE_PLAN.md`

內容建議涵蓋：`api_gateway_audit_log` 與 `AuditLogRecordBuilder` 欄位對齊、寫入失敗重試／降級、與 Host B 失敗事件之對應。

---

## 安全與變更聲明（本文件交付）

| 項目 | 狀態 |
|------|------|
| 修改任何 PHP production code | **未進行** |
| 建立任何 PHP class 檔 | **未進行** |
| 呼叫 Host B API | **未進行** |
| 執行 SQL | **未進行** |
| `git add` / `commit` / `push` | **未進行** |

**本文件路徑：** `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE5_STAGE3_HOST_B_HTTP_PLAN.md`
