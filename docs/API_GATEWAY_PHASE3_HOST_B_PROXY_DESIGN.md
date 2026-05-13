# API Gateway Phase 3 - Host B API Proxy Design

## 1. 文件目的

本文件定義 API Gateway 在 Tenant Mapping 與 API Key 驗證通過後，如何安全地代理請求到 Host B API，並確保路由、參數注入、錯誤回應與資訊揭露都符合安全邊界。

---

## 2. 背景與前提

- Tenant Mapping Design 已完成並審核 PASS。
- API Key Verification Design 已完成並審核 PASS。
- Host A 是 API Gateway / SaaS Gateway。
- Host B 是既有 API / SQL / 舊 ASP / ASP.NET 系統所在主機。
- Host B IP：`103.1.222.11`。
- Host A 不應直接讓外部 client 存取 Host B 敏感 API。
- Host A 應作為安全 Proxy / Orchestrator。
- 所有 Host B Proxy 請求必須先取得 authenticated context。

---

## 3. Proxy 流程

HTTP Entry  
→ TraceIdMiddleware  
→ ErrorResponseBuilder  
→ GatewayKernel  
→ Tenant Mapping Resolver  
→ API Key Verification  
→ Service Permission Check  
→ Rate Limit Check  
→ Host B API Proxy  
→ Normalize Response  
→ Audit Log  
→ Response

---

## 4. Host B Proxy 核心原則

- 不允許 client 直接指定 Host B URL。
- Host B base URL 必須來自 tenant context 的 `hostBBaseUrl`。
- 不允許 client 直接傳 `depID` / `storeNo` / `provider_id_no` / `store_uid`。
- 所有 Host B 查詢參數必須由 Gateway server-side context 注入。
- Proxy 只能呼叫白名單 service。
- 每個 service 必須有固定 mapping。
- 不允許任意 path proxy。
- 不允許把 API Key 轉傳給 Host B。
- Host B 只應收到內部服務需要的必要參數。
- `traceId` 應傳給 Host B，方便追蹤。

---

## 5. Service Mapping 設計

以下為 service 對應設計範例（本階段不建立程式）：

| service | Host B path | method | required tenant fields | allowed query fields |
|---|---|---|---|---|
| tour.search | /api/tour/search | GET | sno, depID, storeNo | keyword, page, pageSize |
| order.query | /api/order/query | GET | sno, depID, storeNo | orderNo |
| member.profile | /api/member/profile | GET | sno, depID, storeNo | memberId |

說明：

- service 由 Gateway 控制，不由 client 任意指定 path。
- `allowed query fields` 必須白名單化。
- 不在白名單內的 query 參數必須拒絕或忽略。
- `required tenant fields` 必須從 authenticated context 取得。

---

## 6. Request 組裝規則

- 從 client request 只接受允許的 query/body 欄位。
- 從 authenticated context 注入 `sno` / `depID` / `storeNo` / `provider_id_no` / `store_uid`。
- 加入 `traceId` header。
- 加入 Gateway internal header（例如 `X-BBC-Trace-Id`）。
- 不轉傳 `X-BBC-API-Key`。
- 不轉傳 client 任意 `Authorization` header。
- 不轉傳 cookies。
- 不轉傳不明 headers。

---

## 7. Response 正規化

- Host B 回應不直接原樣回 client。
- Gateway 統一包成標準 JSON。
- 成功回應包含 `success`、`traceId`、`data`。
- 失敗回應包含 `success=false`、`traceId`、`errorCode`、`message`。
- 不暴露 Host B stack trace。
- 不暴露 SQL error。
- 不暴露 Host B 內部 URL。
- 不暴露敏感欄位。

---

## 8. Timeout 與 Retry 設計

- Host B Proxy 必須設定 timeout。
- 不可無限等待。
- GET 查詢可考慮安全 retry。
- POST / 寫入型請求預設不可自動 retry。
- Timeout 應回傳標準錯誤 JSON。
- Timeout 必須寫入 Audit Log。

---

## 9. 錯誤情境設計

| 情境 | errorCode | HTTP status | 是否寫入 Audit Log | 回應是否包含 traceId |
|---|---|---|---|---|
| service 不存在 | `SERVICE_NOT_FOUND` | 404 | 是 | 是 |
| service 未啟用 | `SERVICE_DISABLED` | 403 | 是 | 是 |
| tenant 無權使用 service | `TENANT_SERVICE_NOT_ALLOWED` | 403 | 是 | 是 |
| Host B base URL 未設定 | `HOST_B_BASE_URL_NOT_CONFIGURED` | 503 | 是 | 是 |
| Host B path 未設定 | `HOST_B_PATH_NOT_CONFIGURED` | 500 | 是 | 是 |
| client 傳入禁止欄位 | `FORBIDDEN_REQUEST_FIELD` | 400 | 是 | 是 |
| client 嘗試傳 `depID` / `storeNo` | `FORBIDDEN_TENANT_FIELD_OVERRIDE` | 403 | 是 | 是 |
| Host B timeout | `HOST_B_TIMEOUT` | 504 | 是 | 是 |
| Host B 回傳非 JSON | `HOST_B_INVALID_RESPONSE_FORMAT` | 502 | 是 | 是 |
| Host B 回傳 4xx | `HOST_B_CLIENT_ERROR` | 502 | 是 | 是 |
| Host B 回傳 5xx | `HOST_B_SERVER_ERROR` | 502 | 是 | 是 |
| Host B connection failed | `HOST_B_CONNECTION_FAILED` | 502 | 是 | 是 |
| Response normalize failed | `RESPONSE_NORMALIZATION_FAILED` | 500 | 是 | 是 |

---

## 10. 安全設計

- 不做 open proxy。
- 不允許任意 URL。
- 不允許任意 path。
- 不轉傳 API Key。
- 不轉傳 cookies。
- 不暴露 Host B 內部錯誤。
- 不暴露 SQL error。
- 不暴露敏感欄位。
- Proxy 必須依 Tenant Mapping + API Key context 運作。
- 所有可查欄位必須白名單化。
- 寫入型 API 未來必須額外審核，不在本階段開放。

---

## 11. 本階段不做事項

- 不實作 Host B Proxy 程式。
- 不呼叫 Host B API。
- 不修改 Host B。
- 不修改 production PHP。
- 不修改 `.htaccess`。
- 不執行 SQL。
- 不接正式 API。
- 不開放寫入型 API。
- 不建立 service mapping 程式碼。
- 不做 git add / commit / push。

---

## 12. 與後續 Phase 3 文件的關係

本文件將作為以下文件基礎：

- `API_GATEWAY_PHASE3_AUDIT_LOG_DESIGN.md`
- `API_GATEWAY_PHASE3_RATE_LIMIT_DESIGN.md`
- `API_GATEWAY_PHASE3_IMPLEMENTATION_PLAN.md`

後續文件應沿用本文件之 service mapping、錯誤碼與 response normalize 原則，整合稽核欄位、限流策略與實作切分順序。

---

## 13. Cursor 執行回報

1. 文件是否已建立：是。  
2. 文件完整路徑：`C:\bbc-ai-bot\docs\API_GATEWAY_PHASE3_HOST_B_PROXY_DESIGN.md`。  
3. 是否只建立文件：是。  
4. 是否完全沒有修改正式 PHP：是。  
5. 是否完全沒有修改 `.htaccess`：是。  
6. 是否完全沒有執行 SQL：是。  
7. 是否沒有呼叫 Host B API：是。  
8. 是否沒有 git add / commit / push：是。  
9. 建議下一步文件：`API_GATEWAY_PHASE3_AUDIT_LOG_DESIGN.md`。  
