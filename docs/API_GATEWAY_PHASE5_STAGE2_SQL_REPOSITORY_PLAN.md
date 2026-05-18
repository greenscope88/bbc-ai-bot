# API Gateway Phase 5 Stage 2 — SQL Repository Integration Plan

**專案根目錄：** `C:\bbc-ai-bot`  
**文件版本：** 2026-05-14  
**性質：** 規劃文件（Planning only）。**不**實作 PHP、**不**建立資料表、**不**執行 SQL、**不**呼叫 Host B API、**不**進行 git 寫入。

---

## 文件目的

本文件規劃如何將 Phase 4 之 **In-Memory／Mock Repository** 替換為 **正式 SQL Repository**，使 API Gateway 可從正式資料表讀取：

- Tenant Mapping  
- API Key（含 hash／metadata）  
- Rate Limit Policy  
- （選用）集中化之 Service Permission  
- （建議於後續 Stage 4 實作寫入）Audit Log  

並維持 **Service 與 Repository 分層**、以及未來可替換為 **Cache／外部 API** 之擴充性。

---

## 1. Stage 2 Objective

| 目標 | 說明 |
|------|------|
| **規劃正式 SQL Repository 架構** | 定義 `contracts`（介面）、`repositories`（SQL 實作）、`services`（用例與規則）之依賴方向。 |
| **定義 Repository 與資料表對應** | 一表一主責或明確主表；避免跨域巨型 SQL 散落於 entry。 |
| **保持 Service Layer 與 Repository Layer 分離** | Resolver／Verifier／Limiter **不依賴**具體 PDO 型別，只依賴介面；商業規則留在 service／middleware。 |
| **支援未來替換資料來源** | 介面不變下可換 `CachedTenantMappingRepository`、`GrpcPolicyClient` 等 decorator。 |

---

## 2. Repository Responsibilities

| Repository | 職責 |
|--------------|------|
| **TenantMappingRepository** | 依 `sno`（或內部 tenant id）讀取租戶列：啟用狀態、`provider_id_no`、`depID`、`store_uid`、`storeNo`、預設 allowed services／profile 等；**不**含 HTTP 或授權決策。 |
| **ApiKeyRepository** | 依 `key_prefix` + `key_hash`（或 lookup 策略）查詢金鑰列：`sno` 綁定、`key_status`、過期、`allowed_ips`、`allowed_services`；**永不**回傳完整明文 API key（DB 亦不存）。 |
| **ServicePermissionRepository** | （選用／正規化）讀取 **租戶或金鑰維度**之 service 允許清單、黑／白名單、或 per-service flag；若短期仍由 tenant／key 表內嵌 JSON 表達，則本 repository 可為 facade 聚合讀取，避免商業邏輯進 SQL 字串。 |
| **RateLimitPolicyRepository** | 依優先序解析 rate limit policy（對齊 Phase 4 `resolvePolicy` 語意）：讀取 limit／burst／status；不執行計數（計數屬 `RateLimiter` + store）。 |
| **AuditLogRepository** | **建議於 Phase 5 Stage 4（Audit persistence）** 實作：append-only 寫入 `api_gateway_audit_log`（或佇列）；Stage 2 可先定義介面與 schema 對齊，避免與 `AuditLogRecordBuilder` 欄位漂移。 |

---

## 3. Proposed SQL Tables

| 資料表 | 用途（摘要） |
|--------|----------------|
| **api_gateway_tenant_mapping** | 租戶主檔：`sno`、組織／門市維度、啟用狀態、預設允許 service 等。 |
| **api_gateway_keys** | API Key：`key_prefix`、`key_hash`、`sno`、狀態、過期、允許 IP／service。 |
| **api_gateway_service_permissions** | （選用）細粒度 service 權限：租戶或 key 維度之多列／版本化；亦可先以 tenant／key 內嵌欄位過渡。 |
| **api_gateway_rate_limit_policy** | 限流政策：維度鍵、limit／burst、狀態、優先序或有效區間。 |
| **api_gateway_audit_log** | 稽核主表：與 Phase 3／4 設計欄位對齊（`trace_id`、`sno`、摘要欄位等）。 |

> **DDL：** 本文件不建立資料表、不產出可執行 migration；僅作為後續 DBA／Change Request 的對齊基礎。

---

## 4. Repository-to-Table Mapping

| Repository | Primary Table | Purpose |
|------------|---------------|---------|
| `TenantMappingRepository` | `api_gateway_tenant_mapping` | 租戶解析與狀態讀取；為 Tenant Mapping Resolver 之唯一讀源（可外加 cache decorator）。 |
| `ApiKeyRepository` | `api_gateway_keys` | 金鑰列查詢與 hash 比對；供 API Key Verifier 使用。 |
| `ServicePermissionRepository` | `api_gateway_service_permissions`（若採正規化） | 讀取 service 允許／拒絕規則；若未建表則介面實作可暫時讀取 tenant／key 內嵌欄位並標註技術債。 |
| `RateLimitPolicyRepository` | `api_gateway_rate_limit_policy` | 讀取限流政策列並解析最終適用 policy。 |
| `AuditLogRepository` | `api_gateway_audit_log` | 稽核持久化（寫入為主；查詢可走唯讀副本／報表庫）。 |

---

## 5. Suggested PHP Class Structure（實作階段；本文件不建立檔案）

```
core/api_gateway/contracts/          # 介面：XxxRepositoryInterface
core/api_gateway/repositories/       # SqlXxxRepository（PDO／sqlsrv 等）
core/api_gateway/services/           # TenantMappingService、ApiKeyVerificationService 等（組裝規則）
```

**命名示例（規劃用）：**

- `contracts/TenantMappingRepositoryInterface.php`  
- `repositories/SqlTenantMappingRepository.php`  
- 其餘類推；與 Phase 4 `core/api_gateway/isolated/*Repository.php` 並存期間，可透過介面與 DI 切換實作。

---

## 6. Data Access Principles

- **僅允許參數化查詢**（prepared statements）；所有外部輸入以 bound parameter 傳入。  
- **禁止動態拼接 SQL** 產生欄位名／表名；若需動態排序白名單，以固定 allowlist 對照。  
- **Repository 不包含商業邏輯**（例如「是否允許呼叫 Host B」應在 service／middleware）；Repository 只做 CRUD／查詢與資料列對應。  
- **不回傳敏感資訊**：不得 SELECT 明文 API key；log 與例外訊息不附帶 hash 以外之還原資訊。  
- **所有查詢應支援 tenant isolation**：凡涉及租戶資料之 SQL 必須帶 `sno`（或等價 tenant key）條件；跨租戶全表 scan 僅限 admin 工具且不得掛在 Gateway runtime。

---

## 7. Connection Management

- **共用 DB connection factory**（專案既有或新增）：集中建立 PDO／sqlsrv 連線、charset、錯誤模式。  
- **最小權限帳號**：Gateway runtime 帳號僅 `SELECT`（必要時 audit 寫入帳號分離）；禁止與報表／DDL 共用同一帳號。  
- **Connection timeout 與 retry policy**：連線建立 timeout、query timeout；retry 僅限 idempotent read 且設上限與 jitter，避免雪崩。

---

## 8. Caching Opportunities

| 可快取項目 | 建議 | 風險／緩解 |
|------------|------|------------|
| **Tenant Mapping** | 短 TTL（例如 30～120s）+ key=`sno` | 變更延遲生效；租戶停用需主動失效或更短 TTL。 |
| **API Key metadata** | 以 `key_prefix` 或 internal id 快取（**不含**可驗證之過多資訊） | 金鑰撤銷需失效；避免快取整段 row 造成過寬暴露。 |
| **Service permissions** | 與 tenant／key 版本號綁定快取 | 版本 bump 時 purge。 |
| **Rate limit policy** | 中長 TTL + 事件驅動失效 | 政策誤快取導致限流失效／過严；需監控。 |

---

## 9. Security Considerations

| 項目 | 說明 |
|------|------|
| **SQL Injection 防護** | 僅參數化查詢；靜態分析／code review gate。 |
| **Key hash 驗證** | 使用 slow-equals（`hash_equals`）比對；hash 演算法與加鹽策略由資安與 DBA 凍結。 |
| **Tenant isolation** | SQL 層強制 tenant 條件；integration test 覆蓋跨租戶負例。 |
| **Sensitive field masking** | Repository DTO 轉給上層前剔除／遮罩；audit 寫入由 builder 負責摘要。 |

---

## 10. Rollback Strategy

- **先新增** SQL Repository 類別與介面，**保留** Phase 4 `isolated` mock／in-memory 實作。  
- **透過設定切換**資料來源（例如 `GATEWAY_TENANT_REPO=sql|memory`），預設 production 可先用 memory 影子驗證後再切 SQL。  
- **Rollback**：設定切回 `memory`／mock；或部署前一版 artifact；無需刪表（表可先行建立為空）。

---

## 11. Deployment Checklist

- [ ] **Schema 設計完成**（ER、索引、`sno`／`trace_id` 查詢路徑）— 文件／CR 層級，非本機執行 DDL  
- [ ] **Repository 類別建立**（實作階段）— 介面先行、SQL 實作後行  
- [ ] **Unit tests** — mock PDO 或 sqlite in-memory schema fixture  
- [ ] **Integration tests** — 連 staging DB 或 test container；含 tenant isolation 負例  

---

## 12. Success Criteria

- Repository **可正確讀取**各主表資料列，並對應到 Phase 4 已存在之 **array shape**（降低 service 改動面）。  
- **Tenant isolation** 正常（單元／整合測試覆蓋跨租戶查詢必失敗或空集）。  
- **API Key 驗證** 正常（hash 命中、過期／停用／IP 限制與 Phase 4 行為一致）。  
- **Rate limit policy** 可載入並與 `RateLimiter` 的 `resolvePolicy` 結果一致（含 disabled／優先序）。  

---

## 13. Recommended Next Step

建議下一步建立 **Host B 真實 HTTP** 整合規劃（仍為文件）：

**文件路徑：** `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE5_STAGE3_HOST_B_HTTP_PLAN.md`

內容建議涵蓋：TLS、timeout、連線上限、錯誤映射、`HostBHttpClient` 與 `HostBRequestBuilder` 邊界、以及與 Rate limit／Audit 的失敗語意。

---

## 安全與變更聲明（本文件交付）

| 項目 | 狀態 |
|------|------|
| 修改任何 PHP production code | **未進行** |
| 建立任何 PHP class 檔 | **未進行** |
| 建立資料表／執行 SQL | **未進行** |
| 呼叫 Host B API | **未進行** |
| `git add` / `commit` / `push` | **未進行** |

**本文件路徑：** `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE5_STAGE2_SQL_REPOSITORY_PLAN.md`
