# API Gateway Phase 5 Stage 4 — Audit Log Persistence Plan

**專案根目錄：** `C:\bbc-ai-bot`  
**文件版本：** 2026-05-14  
**性質：** 規劃文件（Planning only）。**不**實作程式、**不**建立資料表、**不**執行 SQL、**不**寫入正式 DB／Redis／檔案 log、**不**呼叫 Host B API、**不**進行 git 寫入。

---

## 文件目的

本文件規劃如何將 Phase 4 之 **In-Memory Audit Logger**（`AuditLogger` + `InMemoryAuditLogSink`）升級為 **正式 Audit Log Persistence**，使 API Gateway 未來可安全記錄：

- API 呼叫與結果狀態（HTTP、`errorCode`）  
- **Tenant**／**API key metadata**／**service**／**`traceId`**  
- **Request／response 摘要**（非完整 body）

並支援後續 **監控、除錯、計費與安全稽核**，且全程符合 **敏感資料遮罩** 與 Phase 3 Audit 設計原則。

---

## 1. Stage 4 Objective

| 目標 | 說明 |
|------|------|
| **規劃正式 Audit Log Persistence 架構** | `AuditLogRecordBuilder`（不變更職責）→ `AuditLogRepository`（寫入介面）→ SQL／佇列實作。 |
| **定義 audit log 欄位與責任邊界** | Builder 產「列」、Repository 負責「持久化」、Service 決定「何時寫」；禁止在 HTTP entry 內直接 INSERT。 |
| **確保不記錄敏感資訊** | 延續 Phase 4 遮罩規則；表層欄位僅 prefix／摘要。 |
| **支援未來監控、除錯、計費與安全稽核** | 欄位與索引支援依 `trace_id`、`sno`、時間區間查詢；可再接 SIEM／數倉。 |

---

## 2. Audit Log Responsibilities

**Audit Logger（與 Builder／Repository 協作）負責：**

| 項目 | 說明 |
|------|------|
| **記錄 request summary** | 經白名單與遮罩後之 JSON 摘要（或固定長度字串），**非**完整 request body。 |
| **記錄 response summary** | 同上；成功可記錄業務安全子集；失敗可記錄 `errorCode` 與安全訊息摘要。 |
| **記錄 traceId** | 與 Access／Error log 一致，作為跨系統關聯主鍵之一。 |
| **記錄 tenant context** | `sno`、`provider_id_no`、`depID`、`store_uid`、`storeNo` 等（以可取得且已脫敏為準）。 |
| **記錄 API key metadata** | `api_key_id`、`api_key_prefix`；**不**存完整 key 或 hash 還原資訊於 audit 欄位（hash 留存應限於 keys 表）。 |
| **記錄錯誤碼與 HTTP status** | 最終對外狀態與 `error_code`（成功可為 `NULL` 或約定 `OK`，依專案凍結規格）。 |
| **不得記錄** | 完整 API Key、密碼、`Authorization` 全文、token、cookie、完整個資、完整 Host B 內部錯誤堆疊／SQL。 |

---

## 3. Proposed Audit Log Table

**表名（建議）：** `api_gateway_audit_log`

- **本文件僅做欄位與索引之設計建議**，不建立資料表、不產出可執行 DDL。  
- 實際型別與長度由 DBA 依 SQL Server 版本與容量調整。

---

## 4. Suggested Audit Log Fields

| Field | Type Suggestion | Purpose | Sensitive Handling |
|-------|-----------------|---------|---------------------|
| `id` | `BIGINT` IDENTITY PK | 稽核列唯一識別 | 無敏感資料 |
| `trace_id` | `VARCHAR(64)`（或依現行 trace 上限） | 全鏈路追蹤 | 僅允許可列印字元；拒絕控制字元 |
| `sno` | `VARCHAR(64)` | 租戶識別 | 不寫入非 tenant 關聯之自由文字 |
| `provider_id_no` | `VARCHAR(64)` NULL | 租戶維度供報表 | 依個資政策可遮罩或 hash |
| `depID` | `VARCHAR(64)` NULL | 組織／部門 | 同上 |
| `store_uid` | `VARCHAR(64)` NULL | 門市 uid | 同上 |
| `storeNo` | `VARCHAR(64)` NULL | 門市編號相容欄位 | 同上 |
| `api_key_id` | `BIGINT` NULL | 金鑰內部 id | 不與 prefix 組合還原 key |
| `api_key_prefix` | `VARCHAR(16)` NULL | 金鑰前綴 | **僅 prefix**；長度上限固定 |
| `service` | `VARCHAR(128)` | Gateway service id | 固定 allowlist 值 |
| `request_method` | `VARCHAR(16)` | HTTP 方法 | 列舉值 |
| `request_path` | `VARCHAR(512)` | Gateway path | 需 scrub query 內敏感 pattern |
| `upstream_url` | `VARCHAR(512)` NULL | 上游遮罩後 URL 或 path | **禁止**完整 query string 含 secret |
| `http_status` | `SMALLINT` | 最終 HTTP 狀態 | 無敏感 |
| `error_code` | `VARCHAR(64)` NULL | 錯誤碼 | 不寫自由格式 stack |
| `client_ip` | `VARCHAR(45)` NULL | 客戶端 IP | 可依政策部分遮罩 |
| `user_agent` | `VARCHAR(256)` NULL | User-Agent | 截斷長度 |
| `duration_ms` | `INT` | Gateway 端耗時 | 無敏感 |
| `request_summary` | `NVARCHAR(MAX)` 或限長 CLOB | 請求摘要 JSON | 經 Builder 遮罩後寫入 |
| `response_summary` | `NVARCHAR(MAX)` 或限長 CLOB | 回應摘要 JSON | 同上 |
| `created_at` | `DATETIME2(3)` UTC | 建立時間 | UTC；與 API `timestamp` 語意對齊 |

**可選擴充（實作階段）：** `event_type`、`event_stage`、`policy_id`、`upstream_http_status` 等，對齊 Phase 3 文件補充欄位。

---

## 5. Sensitive Data Masking Policy

**不得記錄（含於任何欄位或摘要 JSON 解碼後）：**

- 完整 API Key、Bearer token 全文  
- `Authorization` header 原文、`Cookie` 原文  
- `password`、`refresh_token`、`client_secret` 等鍵值  
- 完整個資（手機、證號、email 等）— 若業務必須留存，須改為 **hash／部分遮罩** 並另走個資評估  
- **Host B 內部錯誤細節**（stack、connection string、內部 host 名）— 可記錄對外 `errorCode` 與安全訊息  

**實作對齊：** Phase 4 `AuditLogRecordBuilder` 之 `SENSITIVE_KEY_FRAGMENTS`、`scrubApiKeySubstrings` 等邏輯應 **作為寫入前唯一關卡**，Repository 不重複實作遮罩規則以免漂移。

---

## 6. Audit Log Write Flow

1. **GatewayKernel** 開始處理 request（建立／注入 context）。  
2. **建立 traceId**（`TraceIdMiddleware`）。  
3. **解析 tenant context**（Tenant Mapping）。  
4. **驗證 API Key**。  
5. **呼叫 Host B**（HTTP client）。  
6. **Normalizer 產生 response**（對外 JSON）。  
7. **`AuditLogRecordBuilder` 建立 log record**（含摘要與遮罩）。  
8. **`AuditLogRepository` 寫入正式儲存層**（INSERT 或 enqueue）。  
9. **回傳 JSON response** 給客戶端（**不因** audit 寫入失敗而改變已成功產出之業務回應語意—見第 7 節）。

> **順序微調：** 若採「非同步稽核」，可在步驟 8 改為寫入佇列並由 worker 落 DB；Kernel 仍須保證 trace 與錯誤路徑一致。

---

## 7. Failure Handling

- **Audit log 寫入失敗不得中斷主要 API response**（已成功組出之 2xx／4xx 回應仍應回傳客戶端；**禁止**因 INSERT 失敗改為 500，除非產品明確要求「fail closed」且已核准）。  
- **需記錄 fallback error**：寫入結構化 **internal** log（非客戶端）、或 metrics counter `audit_write_failure_total`；不含敏感 payload。  
- **未來可加入 queue／retry**：佇列背壓、DLQ、重試上限與 idempotency key（例如 `trace_id` + `stage`）。  
- **Audit failure 應可被 monitoring 偵測**：告警閾值、儀表板、與 on-call runbook。

---

## 8. Retention Policy

| 項目 | 建議 |
|------|------|
| **保存期間** | 線上熱資料 90～180 天（依法遵與容量調整）；對齊 Phase 3 Audit 文件建議。 |
| **歸檔策略** | 週期性匯出至冷儲存（Parquet／壓縮 CSV）或數倉；保留不可逆刪除稽核。 |
| **清除策略** | 分區表或依 `created_at` 批次 purge；刪除操作本身需稽核。 |
| **查詢效能** | 以 `created_at` 分區／索引為主；避免全表 scan；大欄位 `request_summary` 與索引分離評估。 |

---

## 9. Indexing Suggestions（僅建議，不執行 SQL）

以下為 **查詢模式導向**之索引方向（實際 DDL 由 DBA 執行）：

- **`trace_id`** — 客服／工程依單筆 trace 追查。  
- **`sno`** — 租戶維度報表與異常偵測。  
- **`api_key_id`** — 金鑰維度稽核。  
- **`service`** — 服務別流量與錯誤率。  
- **`http_status`** — 錯誤熱點分析。  
- **`error_code`** — 依錯誤碼聚合。  
- **`created_at`** — 時間區間查詢、分區鍵候選。  

**複合索引範例（概念）：** `(sno, created_at DESC)`、`(trace_id, created_at DESC)`— 實作前需依慢查詢 log 驗證。

---

## 10. Security Considerations

| 項目 | 說明 |
|------|------|
| **最小權限寫入** | Gateway DB 帳號僅 `INSERT`（必要時 `SELECT` 限於查核工具）；與報表帳號分離。 |
| **欄位遮罩** | 寫入前唯一通過 Builder；禁止繞過 Builder 直接寫 DB。 |
| **Log injection 防護** | 摘要 JSON 需合法編碼；長度上限；禁止未轉義拼接使用者字串進自由文字欄位。 |
| **Tenant isolation** | 應用層保證 `sno` 與 context 一致；DB 層可評估 row-level 或檢查 constraint（選配）。 |
| **查詢權限控管** | 稽核表限制直接 SQL 查詢；僅允許受控 API／報表角色。 |

---

## 11. Rollback Strategy

- **保留 In-Memory Audit Logger**（Phase 4 `InMemoryAuditLogSink`）於 codebase 與 CI。  
- **透過 config 切換 audit driver**：`memory` \| `sql` \| `queue`。  
- **Audit 寫入失敗不影響主流程**（第 7 節）；rollback 時切回 `memory` 或關閉非同步 worker 即可。  

---

## 12. Deployment Checklist

- [ ] **欄位設計完成**（與本文件第 4 節對齊 DBA review）  
- [ ] **Masking policy 完成**（與資安／法遵 sign-off）  
- [ ] **`AuditLogRepository` 設計完成**（介面 + SQL 實作計畫）  
- [ ] **Failure handling 設計完成**（metrics、retry、DLQ）  
- [ ] **測試案例規劃完成**（成功／失敗／timeout／寫入失敗不影響 response）  

---

## 13. Success Criteria

- **每次 API 呼叫**皆可關聯 **`traceId`**（成功與失敗皆然）。  
- **Audit log 不含敏感資訊**（靜態掃描 + 抽樣人工複核）。  
- **錯誤與成功請求皆可追蹤**（至少 `trace_id` + `http_status` + `error_code` + 時間）。  
- **Audit failure 不會造成 API 中斷**（或僅在明確核准之 fail-closed 模式下例外，且需文件化）。  

---

## 14. Recommended Next Step

建議下一步建立 **Rate Limiter 持久化／集中式計數** 規劃文件：

**文件路徑：** `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE5_STAGE5_RATE_LIMITER_PERSISTENCE_PLAN.md`

內容建議涵蓋：Redis（或 SQL）作為 counter store、與 Phase 4 `RateLimiter` 語意對齊、多 instance 一致性、及與 audit 之超限關聯。

---

## 安全與變更聲明（本文件交付）

| 項目 | 狀態 |
|------|------|
| 修改任何 PHP production code | **未進行** |
| 建立任何 PHP class 檔 | **未進行** |
| 建立資料表／執行 SQL | **未進行** |
| 寫入正式 DB／Redis／file log | **未進行** |
| 呼叫 Host B API | **未進行** |
| `git add` / `commit` / `push` | **未進行** |

**本文件路徑：** `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE5_STAGE4_AUDIT_LOG_PERSISTENCE_PLAN.md`
