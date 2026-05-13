# API Gateway Phase 3 - Implementation Plan

## 1. 文件目的

本文件整合 Phase 3 六份設計文件（Tenant Mapping、API Key、Host B Proxy、Audit Log、Rate Limit 與本計畫），提供後續實作的**建議順序**、每階段的**輸入／輸出／驗證方式**、**隔離測試策略**、**上線策略**、**Git 與 SQL 治理**，以及 **Phase 3 完成條件**。  
本文件為治理與排程規格，**不包含程式碼實作**。

---

## 2. Phase 3 文件清單

1. `docs/API_GATEWAY_PHASE3_TENANT_MAPPING_DESIGN.md`
2. `docs/API_GATEWAY_PHASE3_API_KEY_VERIFICATION_DESIGN.md`
3. `docs/API_GATEWAY_PHASE3_HOST_B_PROXY_DESIGN.md`
4. `docs/API_GATEWAY_PHASE3_AUDIT_LOG_DESIGN.md`
5. `docs/API_GATEWAY_PHASE3_RATE_LIMIT_DESIGN.md`
6. `docs/API_GATEWAY_PHASE3_IMPLEMENTATION_PLAN.md`（本文件）

---

## 3. 實作順序建議

**Stage 1**

- `TenantMappingRepository`
- `TenantMappingResolver`

**Stage 2**

- `ApiKeyRepository`
- `ApiKeyVerifier`

**Stage 3**

- `ServiceRegistry`
- `HostBProxyService`

**Stage 4**

- `AuditLogger`

**Stage 5**

- `RateLimitChecker`

**Stage 6**

- Isolated HTTP Integration Test

**Stage 7**

- Production Rollout

---

## 4. 每個 Stage 的輸入 / 輸出 / 驗證方式

### Stage 1：Tenant Mapping

| 項目 | 說明 |
|------|------|
| **輸入** | 請求中的 `sno`（格式依凍結規格）；可選 trace 上下文。 |
| **輸出** | `tenant context`（含 `providerIdNo`、`depID`、`storeUid`、`storeNo`、`hostBBaseUrl`、`allowedServices`、`tenant_status` 等，與 Tenant Mapping 設計一致）。 |
| **驗證** | 單元／整合測試：缺少 `sno`、格式錯、mapping 不存在、`disabled`/`suspended` 皆回標準錯誤 JSON 且含 `traceId`；成功路徑產出完整 context。 |

### Stage 2：API Key

| 項目 | 說明 |
|------|------|
| **輸入** | `sno`、Header `X-BBC-API-Key`、目標 `service`（若此階段已可解析）；Stage 1 產出之 tenant context。 |
| **輸出** | `authenticated context`（合併 tenant + `apiKeyId`、`apiKeyPrefix`、`allowedServices`、`allowedIps` 等，與 API Key 設計一致）。 |
| **驗證** | 測試：缺 key、hash 失敗、跨 `sno`、revoked/expired、tenant 非 active 仍拒絕；成功路徑 context 可供下游使用。 |

### Stage 3：Service Registry + Host B Proxy

| 項目 | 說明 |
|------|------|
| **輸入** | `authenticated context`、Gateway 解析之 `service`、client 允許之 query/body（白名單）。 |
| **輸出** | 對 Host B 組裝後之請求、正規化後之 Gateway JSON 回應（成功／失敗契約與 Proxy 設計一致）。 |
| **驗證** | 測試：禁止任意 path、禁止 client 注入 `depID`/`storeNo` 等、不轉傳 `X-BBC-API-Key`／cookies／不明 headers；mock Host B 回 JSON／timeout／非 JSON／4xx／5xx 時行為符合錯誤矩陣。 |

### Stage 4：Audit Logger

| 項目 | 說明 |
|------|------|
| **輸入** | 各階段事件：`trace_id`、`sno`、tenant/API key 維度、`service`、path/method、upstream 摘要、`http_status`、`error_code`、`duration_ms`、遮罩後 `request_summary`/`response_summary` 等。 |
| **輸出** | 符合 `api_gateway_audit_log` 設計之持久化或佇列寫入（實作階段再定儲存後端）。 |
| **驗證** | 測試：成功與失敗皆寫入；Tenant/API Key 失敗、Host B timeout 皆有紀錄；不寫入完整 API Key、password、token、cookie。 |

### Stage 5：Rate Limit

| 項目 | 說明 |
|------|------|
| **輸入** | `sno`、`api_key_id`、`service`、`client_ip`；`api_gateway_rate_limit_policy` 對應規則（實作後由 repository 載入）。 |
| **輸出** | 允許通過或 `429` + `RATE_LIMIT_EXCEEDED` + `traceId`。 |
| **驗證** | 測試：檢查順序在 Proxy 之前；超限寫入 Audit Log；錯誤高頻仍受限流（不繞過）。 |

### Stage 6：Isolated HTTP Integration Test

| 項目 | 說明 |
|------|------|
| **輸入** | `bbc-ai-gateway-test` 路徑、mock tenant context、mock API Key、mock Host B。 |
| **輸出** | 端到端 JSON 回應、`traceId`、稽核與限流行為可觀測（於測試環境）。 |
| **驗證** | 全鏈路測試通過；不接正式 API、不連真實 Host B（或使用 stub）。 |

### Stage 7：Production Rollout

| 項目 | 說明 |
|------|------|
| **輸入** | 已通過 staging 與 limited rollout 之版本與設定。 |
| **輸出** | 依 Rollout 策略逐步放量之 production 行為與監控／稽核可視性。 |
| **驗證** | 指標與錯誤率符合門檻；可快速回滾；無未核准之 schema 變更。 |

---

## 5. Isolated Test Strategy

- 使用 **`bbc-ai-gateway-test`** 作為隔離 HTTP 入口，驗證 Gateway 管線與 JSON 契約。
- **不接正式 API**；不對外開放 SaaS 生產流量。
- 使用 **mock tenant context**（不依賴真實 `api_gateway_tenant_mapping` 表即可驗證 Resolver 介面與錯誤路徑時，可由測試替身注入）。
- 使用 **mock API Key**（測試用 prefix／hash 驗證流程，不產線上真實金鑰）。
- 使用 **mock Host B response**（本機 stub 或固定回應），驗證 Proxy 組裝、timeout、正規化與錯誤對照，**不呼叫真實 Host B API**。

---

## 6. Production Rollout Strategy

1. **先 staging**  
   在與 production 類似但隔離的環境驗證完整管線、稽核與限流、設定與監控。
2. **再 limited tenant rollout**  
   僅對少數 `sno`／API Key 開放，觀察錯誤率、延遲、Audit Log 與限流命中。
3. **再 full rollout**  
   條件滿足後全面開放，並保留回滾程序與功能旗標（若採用）。

---

## 7. Git Governance

- 每個 Stage 完成後先**程式／文件審核**（PR 或等效流程）。
- **`git diff` review**：變更範圍、敏感檔案、意外納入之設定。
- **git-safe commit**：訊息清楚、原子性提交、避免混雜無關變更。
- **測試成功後再 push**；禁止將已知失敗之 mainline 強推上線分支。

---

## 8. SQL Governance

若需新增資料表（例如 `api_gateway_tenant_mapping`、`api_gateway_keys`、`api_gateway_audit_log`、`api_gateway_rate_limit_policy`）：

- 先 **DB Change Request**（目的、影響範圍、回滾）。
- **Schema diff**（與現行 `schema_only` 快照比對）。
- **Migration SQL**（可重跑、可回滾段落、於非 production 先驗證）。
- 備份 **`.bak`**（或依組織規範之備份證明）。
- 更新／產出 **`schema-only.sql`**（或專案約定之 schema 快照路徑），與 repo 治理一致。
- 使用 **Migration Engine 4.5 / 5.x**（或專案既定版本）執行遷移，禁止 ad-hoc 在 production 手動改表。
- **Human approval**（DBA／負責人簽核）後方可於 production 執行。

**不可直接在 Host B 手動修改 production schema**（應透過變更請求與 migration 流程）。

---

## 9. Completion Criteria

Phase 3 視為完成，需同時滿足：

1. **Tenant Mapping** 可依 `sno` 產出 server-side tenant context，且錯誤與狀態碼符合設計文件。  
2. **API Key** 可依 Header 驗證並綁定 `sno`，跨租戶與失效狀態皆拒絕。  
3. **Host B Proxy** 僅白名單 service／path，參數由 context 注入，回應正規化且不洩漏內部錯誤細節。  
4. **Audit Log** 對成功／失敗／timeout／驗證失敗皆可追溯，且符合遮罩規則。  
5. **Rate Limit** 在 Proxy 前生效，429 與稽核一致，且不取代 API Key。  
6. **Isolated HTTP** 測試通過，`bbc-ai-gateway-test` 可重現主要路徑。  
7. **Production Rollout** 依 staging → limited → full 執行且有文件化回滾步驟。  
8. 所有 schema 變更均經 **SQL Governance**，無未核准之 production 手動變更。

---

## 10. 不做事項

- 本文件不實作任何程式。
- 不建立資料表。
- 不執行 SQL。
- 不呼叫 Host B API。
- 不修改 production PHP。
- 不修改 `.htaccess`。

---

## 11. Cursor 執行回報

1. 文件是否建立：**是**  
2. 完整路徑：**`C:\bbc-ai-bot\docs\API_GATEWAY_PHASE3_IMPLEMENTATION_PLAN.md`**  
3. 是否只建立文件：**是**  
4. 是否沒有修改正式 PHP：**是**  
5. 是否沒有執行 SQL：**是**  
6. 是否沒有呼叫 Host B API：**是**  
7. 是否沒有 git add / commit / push：**是**  
8. 是否建議進行 Phase 3 文件總審核：**是**——建議以同一份 checklist 對六份設計文件做交叉審核（流程順序、錯誤碼命名、`traceId`、Audit 遮罩、SQL/Git 治理與 Isolated Test 是否一致），通過後再進入 Stage 1 實作與 DB Change Request。
