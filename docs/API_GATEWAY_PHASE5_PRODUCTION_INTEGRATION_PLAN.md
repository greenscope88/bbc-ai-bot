# API Gateway Phase 5 — Production Integration Plan

**專案根目錄：** `C:\bbc-ai-bot`  
**文件版本：** 2026-05-14  
**性質：** 規劃文件（Planning only）。**不**含程式實作、**不**含 DDL／DML、**不**變更現有 production 程式或 `.htaccess`。

---

## 文件目的

本文件規劃如何將 **Phase 4 已完成之 isolated modules** 正式整合進 **production API Gateway** HTTP 管線，並維持：

- 分層架構與依賴方向清晰  
- 可回滾、可觀測、可驗證  
- **Multi-tenant 隔離**與安全治理（憑證、稽核、限流、錯誤回應一致）

### Phase 4 已完成模組（整合來源）

1. Tenant Mapping Resolver（`TenantMappingResolver` + mock repository）  
2. API Key Verifier（`ApiKeyVerifier` + mock repository）  
3. Service Permission Validator（`AllowedServiceResolver` + middleware 內交集邏輯）  
4. Host B Request Builder（`HostBRequestBuilder`）  
5. Mock Host B Proxy Middleware（`HostBProxyMiddleware` + injectable mock responder）  
6. Audit Log Record Builder（`AuditLogRecordBuilder`）  
7. In-Memory Audit Logger（`AuditLogger` + `InMemoryAuditLogSink`）  
8. In-Memory Rate Limiter（`RateLimiter` + `RateLimitPolicyRepository` mock）

---

## 1. Phase 5 目標

| 目標 | 說明 |
|------|------|
| **整合至 production HTTP entry** | 在受控路由下掛載 Gateway，使正式流量可依設定比例或 header 開關進入新管線。 |
| **保持分層架構與可回滾能力** | Middleware／Service／Repository 分層；每階段可獨立啟用／停用；保留 Phase 4 CLI isolated tests 作為回歸基線。 |
| **維持 multi-tenant 隔離** | 所有授權與限流 key 必須包含 `sno`、金鑰維度與 service 維度；禁止跨租戶資料混用。 |
| **安全治理** | 不記錄完整 API key／敏感 body；錯誤與稽核帶 `traceId`；Host B 憑證與設定走 secret management；變更需 code review + 部署檢查清單。 |

---

## 2. Production Pipeline Flow

以下為建議之 **正式請求處理順序**（成功路徑與錯誤路徑均需可追溯到同一 `traceId`）。

> **說明：** `ErrorResponseBuilder` 為「建立標準 JSON 錯誤封套」之工具，實務上在任一階段失敗時呼叫；並非一定要在 `GatewayKernel` 之前執行其邏輯，但為與 Phase 4 模組命名對齊，下列編號依專案約定列出。

1. **HTTP Request** — Web server 進入點（router / front controller）收到請求。  
2. **TraceIdMiddleware** — 解析或產生 `traceId`，寫入 request context；非法外來值則捨棄並改用系統產生值（行為對齊既有 `TraceIdMiddleware`）。  
3. **ErrorResponseBuilder** — （錯誤路徑）由各層呼叫，產出統一錯誤 JSON（含 `traceId`、UTC `timestamp` 等）；成功路徑可不經此類別。  
4. **GatewayKernel** — 組裝 context、驅動 middleware chain、統一例外邊界與最終 JSON 輸出責任（可逐步從骨架擴充）。  
5. **Tenant Mapping Resolver** — 以 `sno`（或約定之 tenant 識別）解析租戶；失敗則錯誤回應 + 稽核（若該階段已啟用 persistence）。  
6. **API Key Verification** — 驗證 API key 與租戶一致性、狀態、允許 IP／service；失敗則 4xx + 標準錯誤。  
7. **Service Permission Validation** — 確認請求 service 與租戶／金鑰允許清單；與 Phase 4 `AllowedServiceResolver` 語意對齊。  
8. **Rate Limiting** — 於進入 Host B 前執行；超限 429、`RATE_LIMIT_EXCEEDED`，錯誤 payload 含 `traceId`；後續可換 Redis store。  
9. **Host B Request Builder** — 依通過驗證之 context 建構上游請求（path、method、headers、body）；禁止客戶端覆寫敏感維度。  
10. **Host B HTTP Client** — 真實對外 HTTP（timeout、連線錯誤、非 JSON 等）；取代 Phase 4 mock responder。  
11. **Response Normalizer** — 將上游回應正規化為對外一致格式（成功／錯誤 envelope）；錯誤須帶 `traceId`。  
12. **Audit Logger** — 成功與失敗皆寫入稽核（實作階段接上 DB 或佇列 sink）；欄位與遮罩規則對齊 Phase 3／Phase 4 設計。  
13. **JSON Response** — 輸出 HTTP status + body；可選擇性輸出 `X-Trace-Id` response header（與 `GatewayKernel` TODO 對齊）。

**與 Phase 3 設計文件對齊：** Tenant → API Key → Service → **Rate Limit** → Host B Proxy（見 `API_GATEWAY_PHASE3_RATE_LIMIT_DESIGN.md` 檢查順序）。

---

## 3. Phase 4 → Production Mapping Table

| Phase 4 Module | Production Location（建議） | Notes |
|----------------|----------------------------|--------|
| `TenantMappingRepository`（mock） | `core/api_gateway/repositories/SqlTenantMappingRepository.php`（或既有 DAL 封裝） | 改讀 `api_gateway_tenant_mapping`；介面與 Resolver 注入對齊。 |
| `TenantMappingResolver` | `core/api_gateway/middleware/TenantMappingMiddleware.php` 或 `services/TenantMappingService.php` | 維持 `resolve` / `toErrorPayload` 語意；由 Kernel 呼叫。 |
| `ApiKeyRepository`（mock） | `core/api_gateway/repositories/SqlApiKeyRepository.php` | 讀 `api_gateway_keys`；hash 比對與 prefix 邏輯不變。 |
| `ApiKeyVerifier` | `core/api_gateway/middleware/ApiKeyMiddleware.php` 或 `services/ApiKeyVerificationService.php` | 與 Tenant 結果銜接；錯誤碼凍結表對齊設計文件。 |
| `AllowedServiceResolver` | `core/api_gateway/services/ServicePermissionService.php`（或併入 ApiKey／Tenant service） | 維持 tenant ∩ key 交集規則。 |
| `HostBRequestBuilder` | `core/api_gateway/services/HostBRequestFactory.php` | 僅組 request，不發 HTTP。 |
| `HostBProxyMiddleware`（mock） | `core/api_gateway/middleware/HostBProxyMiddleware.php` + `core/api_gateway/http/HostBHttpClient.php` | Mock 改為真實 client；timeout／重試策略另文件化。 |
| `AuditLogRecordBuilder` | `core/api_gateway/services/AuditLogRecordFactory.php`（名稱可併用 Builder） | 遮罩規則不變；`created_at` UTC ISO-8601。 |
| `AuditLogger` + `InMemoryAuditLogSink` | `core/api_gateway/services/AuditLogger.php` + `repositories/SqlAuditLogRepository.php` 或 async queue | Production sink 不可長留 in-memory only。 |
| `RateLimitPolicyRepository`（mock） | `core/api_gateway/repositories/SqlRateLimitPolicyRepository.php` | 讀 `api_gateway_rate_limit_policy`；`resolvePolicy` 優先序不變。 |
| `RateLimiter`（in-memory） | `core/api_gateway/services/RateLimiter.php` + Redis adapter（可選） | 視窗計數改為集中式或 Redis；語意與錯誤碼不變。 |
| Phase 4 CLI tests | `tests/api_gateway/test_*_stage*_isolated.php` | **保留**作為 CI 回歸；與 production e2e 分開。 |

---

## 4. Required Infrastructure Components

| 元件 | 用途 |
|------|------|
| **SQL tables** | 持久化租戶、金鑰、稽核、限流政策（僅規劃；本文件不執行 DDL）。建議表名： |
| | `api_gateway_tenant_mapping` — 租戶對應、啟用狀態、允許 service 等。 |
| | `api_gateway_keys` — 金鑰 hash、prefix、狀態、允許 IP／service、過期時間。 |
| | `api_gateway_audit_log` — 稽核欄位對齊 Phase 3 Audit 設計。 |
| | `api_gateway_rate_limit_policy` — 限流政策維度與上限。 |
| **Redis（未來可選）** | 分散式 rate limit counter、短期 dedupe；非上線第一日必須，但建議納容量計畫。 |
| **Host B HTTP client** | 真實呼叫上游；TLS、DNS、timeout、連線上限、circuit breaker 等。 |
| **Config loader** | 區分環境（dev/stage/prod）之 Host B base URL、timeout、feature flag。 |
| **Secret management** | Host B 簽章／token、DB 連線字串、Redis URL；禁止進 repo 明文。 |

---

## 5. Production Directory Structure

建議在不大翻現有結構前提下，逐步收斂至：

```
core/api_gateway/
├── GatewayKernel.php              # 既有／擴充
├── TraceIdMiddleware.php          # 既有
├── ErrorResponseBuilder.php       # 既有
├── middleware/                    # HTTP 管線層（單一職責）
├── repositories/                  # DB／外部讀寫（介面 + SQL 實作）
├── services/                      # 純邏輯、組裝、domain 規則
└── http/                          # HostBHttpClient、normalizer 等

tests/api_gateway/
├── test_*_stage*_isolated.php     # Phase 4 保留
└── （未來）e2e / integration      # Phase 5 Stage 6+
```

**原則：** Phase 4 已存在之 `core/api_gateway/isolated/` 可於過渡期保留，production 程式以 **新檔案** 漸進引入，避免大範圍搬檔造成 merge 衝突。

---

## 6. Integration Stages

| 階段 | 名稱 | 重點交付 |
|------|------|----------|
| **Stage 1** | HTTP Entry Integration | 新增或旁路掛載之 entry；feature flag；不覆蓋既有 LINE／webhook 主路徑除非已核准。 |
| **Stage 2** | SQL Repository Integration | 以 repository 實作取代 mock；migration／DR 計畫；唯讀先行可選。 |
| **Stage 3** | Host B HTTP Integration | `HostBHttpClient`、錯誤映射、timeout；關閉 mock path。 |
| **Stage 4** | Audit Log Persistence | DB 或佇列 sink；失敗重試與降級策略（不得靜默吞掉安全事件）。 |
| **Stage 5** | Redis Rate Limiter | 可選；多 instance 一致計數；與 in-memory 並跑 shadow 比對（可選）。 |
| **Stage 6** | End-to-End Testing | Staging 完整鏈路、負載與混沌測試、錯誤碼矩陣。 |
| **Stage 7** | Production Rollout | 灰度、監控儀表、告警閾值、回滾 runbook。 |

---

## 7. Rollback Strategy

- **每一階段皆可回滾：** 以 feature flag、route 開關、或 reverse proxy 權重將流量切回舊路徑。  
- **新檔案優先：** production 邏輯以新增 `middleware/`、`repositories/`、`http/` 為主，避免一次性覆寫大量舊檔。  
- **不直接覆蓋 production entry：** 先新增專用 entry（例如獨立 `public/api_gateway.php` 或子路徑），驗證後再收斂。  
- **保留 isolated tests：** `tests/api_gateway/test_*_isolated.php` 持續在 CI 執行，作為與 DB／網路解耦之單元／整合基線。

---

## 8. Risks and Mitigations

| 風險 | 緩解 |
|------|------|
| **Tenant Mapping 錯誤** | 凍結錯誤碼與 HTTP 對應；監控 `TENANT_NOT_FOUND` 比率；快取 TTL 與失效策略；避免快取錯誤跨租戶。 |
| **API Key 洩漏** | 僅存 hash；log 遮罩；TLS 強制；金鑰輪替 runbook；最小權限 DB 帳號。 |
| **Host B Timeout** | 合理 timeout、熔斷、429/504 對外語意一致；稽核記錄 `GW_UPSTREAM_ERROR` 類別（依設計）。 |
| **Audit Log 寫入失敗** | 非同步佇列 + 本機 spill（若允許）；**不得**因稽核失敗而中斷已承諾之安全拒絕回應；需告警。 |
| **Rate Limiter 誤判** | Shadow mode、雙寫比對；調權前後 canary；提供緊急關閉限流之 break-glass（審核與稽核必備）。 |

---

## 9. Deployment Checklist

- [ ] Feature flag 與預設關閉策略已文件化  
- [ ] `traceId` 貫穿 access log／audit／錯誤回應  
- [ ] 無明文 API key／secret 進 repo 與 log  
- [ ] Host B URL、timeout、重試上限已於 config 驗證  
- [ ] DB migration 已於 staging 演練且可回滾  
- [ ] 監控與告警（4xx/5xx、延遲、限流命中、稽核寫入失敗）  
- [ ] Runbook：回滾步驟、聯絡窗口、決策權限  
- [ ] Phase 4 isolated tests 於 CI 仍為綠燈  
- [ ] 合規與資料保留週期（audit retention）已對齊  

---

## 10. Success Criteria

- Production 路徑上，**Tenant → API Key → Service → Rate limit → Host B** 順序與設計一致且可觀測。  
- 所有錯誤回應含 **`traceId`**，且與稽核／access log 可關聯。  
- **Multi-tenant：** 無跨租戶資料洩漏；金鑰僅能操作所屬租戶資源。  
- Host B 真實流量下 **SLO** 達標（延遲、錯誤率、限流誤判率在門檻內）。  
- Audit 與 rate limit **持久化**（或佇列）在 production 啟用且可稽核。  
- 回滾演練成功；重大事故可在約定時間內切回舊路徑。  

---

## 11. Recommended Next Step

建議下一步為撰寫 **HTTP Entry 專章計畫**（不實作程式亦可先定案路由與風險）：

**建立文件：** `docs/API_GATEWAY_PHASE5_STAGE1_HTTP_ENTRY_PLAN.md`

內容建議包含：候選 entry 檔案路徑、與既有 `public/`／webhook 關係、rewrite 需求、feature flag、首波只讀 smoke 測試、以及「不修改既有 entry 的過渡方案」。

---

## 文件後設

| 項目 | 內容 |
|------|------|
| 本文件路徑 | `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE5_PRODUCTION_INTEGRATION_PLAN.md` |
| 變更範圍 | **僅新增本 Markdown**；未修改任何 PHP production code、未修改 `.htaccess`、未執行 SQL、未呼叫 Host B API、未執行 git 寫入。 |
