# API Gateway Phase 5 Stage 5 — Rate Limiter Persistence Plan

**專案根目錄：** `C:\bbc-ai-bot`  
**文件版本：** 2026-05-14  
**性質：** 規劃文件（Planning only）。**不**實作程式、**不**建立 PHP class、**不**連線 Redis、**不**建立資料表、**不**執行 SQL、**不**寫入正式 DB／Redis／檔案 log、**不**呼叫 Host B API、**不**進行 git 寫入。

---

## 文件目的

本文件規劃如何將 Phase 4 之 **In-Memory Rate Limiter**（`RateLimiter` + `RateLimitPolicyRepository` mock）升級為 **可持久化／集中式** 之限流架構，使 API Gateway 未來可依 **tenant（`sno`）**、**API key**、**service**、**client IP** 等維度進行限流，並保留 **In-Memory／SQL／Redis** 可切換之擴充性。

本階段**只做設計**，不實作。

---

## 1. Stage 5 Objective

| 目標 | 說明 |
|------|------|
| **規劃正式 Rate Limiter Persistence 架構** | 分離 **policy 讀取**（低頻、可 SQL）與 **counter 儲存**（高頻、宜 Redis 或專用 store）。 |
| **定義限流 key 設計** | 多層級 key 與視窗（burst／分／時／日）一致於 Phase 4 行為；支援集中式去重。 |
| **定義 rate limit policy 來源** | 以 `api_gateway_rate_limit_policy` 為主（見第 3～4 節）；解析優先序對齊 Phase 4 `resolvePolicy`。 |
| **保留 Redis／SQL／In-Memory 可切換** | 透過 **driver interface** + 設定檔切換；CI 與本機可長期使用 in-memory。 |
| **確保限流錯誤不影響系統安全** | 限流失敗語意明確（429）；**driver 異常**不得變成無限制放行（見第 11 節）。 |

---

## 2. Rate Limiter Responsibilities

**Rate Limiter（與 Policy Repository、Counter Driver 協作）負責：**

| 職責 | 說明 |
|------|------|
| **讀取 rate limit policy** | 透過 `RateLimitPolicyRepository`（SQL 或快取 decorator）取得適用列；不含商業授權邏輯。 |
| **產生 rate limit key** | 依第 5 節組合維度與視窗 bucket；key 字串不暴露給 client。 |
| **計算目前使用量** | 由 driver 讀取／遞增 counter（原子操作或 Lua／transaction 依 driver 而定）。 |
| **判斷是否允許請求** | 比對各視窗 limit；任一看窗超限即拒絕。 |
| **回傳 remaining quota／retry-after** | 供 429 回應與 `Retry-After` header（秒）使用；可選 `X-RateLimit-Remaining` 等（需產品核准）。 |
| **產生 429 Too Many Requests** | 統一錯誤 envelope（見第 9 節）；**必含 `traceId`**；可寫 audit（超限事件）。 |

---

## 3. Proposed Rate Limit Policy Table

**表名（建議）：** `api_gateway_rate_limit_policy`

- **本文件僅做欄位設計**，不建立資料表、不產出可執行 DDL。  
- 優先序可由 **欄位可為 NULL 之匹配規則**（與 Phase 4 mock 相同精神）或另增 `priority INT` 欄位（實作階段決策）。

---

## 4. Suggested Rate Limit Policy Fields

| Field | Type Suggestion | Purpose | Notes |
|-------|----------------|---------|--------|
| `id` | `BIGINT` IDENTITY PK | 政策列識別 | 對應 Phase 4 `policy_id` 可為字串或改數字 FK（實作統一） |
| `policy_name` | `VARCHAR(128)` | 人類可讀名稱 | 供稽核／設定管理 |
| `sno` | `VARCHAR(64)` NULL | 租戶維度；NULL = 全域 | 與 Phase 3 設計一致 |
| `api_key_id` | `BIGINT` NULL | 金鑰維度；NULL = 不限金鑰 | 精準 policy 用 |
| `service` | `VARCHAR(128)` NULL | Service；NULL = 通用 | 精準 policy 用 |
| `limit_per_minute` | `INT` NULL | 每分鐘上限 | NULL 表略過該視窗檢查（與 Phase 4 `intLimit` 語意對齊） |
| `limit_per_hour` | `INT` NULL | 每小時上限 | 同上 |
| `limit_per_day` | `INT` NULL | 每日上限（UTC 日界） | 同上 |
| `burst_limit` | `INT` NULL | 突發上限（建議定義為「日曆秒」或獨立滑動視窗—見 Phase 4 實作語意） | 文件化避免解讀分歧 |
| `policy_status` | `VARCHAR(16)` | `active`／`disabled` 等 | disabled 對應 403 或略過（與產品一致） |
| `created_at` | `DATETIME2(3)` UTC | 建立時間 | 稽核 |
| `updated_at` | `DATETIME2(3)` UTC | 更新時間 | 變更追蹤 |

---

## 5. Rate Limit Key Design

### Key 維度組合（概念）

| 層級 | Key 組成（概念） | 用途 |
|------|------------------|------|
| **tenant-level** | `sno` | 租戶總量上限（若 policy 僅綁 `sno`）。 |
| **api-key-level** | `sno` + `api_key_id` | 單一金鑰總量。 |
| **service-level** | `sno` + `api_key_id` + `service` | 單一服務流量（與 Phase 4 預設一致）。 |
| **client-ip-level** | `sno` + `api_key_id` + `service` + `client_ip` | 來源 IP 維度防刷（Phase 4 已採用）。 |

### 儲存用完整 key（建議）

於實際 counter 中，建議仍附 **視窗** 與 **bucket**，例如：

`rl:{sno}:{api_key_id}:{service}:{client_ip}:{windowKind}:{bucket}`

（與 Phase 3 Rate Limit 設計文件之概念一致。）

### 優先順序

1. **Policy 解析優先序**（哪一條 policy 生效）：**最精準匹配**（非 NULL 欄位最多者）勝出—對齊 Phase 4 `specificityScore`。  
2. **Counter key 維度**：以 **已套用 policy 所定義之維度** 產生；若 policy 僅 tenant 層，可僅用 `sno` + window（實作階段需避免與更細 policy 雙重計數—可用「單一 effective policy」產生單一 key 集合）。  

---

## 6. Storage Driver Options

| Driver | Use Case | Pros | Cons |
|--------|----------|------|------|
| **In-Memory Driver** | 單機開發、CI、極小流量 shadow | 零依賴、最低延遲 | 多進程／多主機不一致；重啟即失 |
| **SQL Driver** | Policy 讀取（主用途）；**可選**低頻 counter（不建議高 QPS） | 與既有 DB 運維一致、易稽核 schema | 高頻 `UPDATE` 競爭與鎖；效能與成本風險 |
| **Redis Driver**（未來可選） | 高頻 counter、TTL、Lua 原子 | 集中式、可水平擴展 | 另套維運／高可用；failover 行為需設計 |

**原則：** **Policy 以 SQL 為主**；**Counter 以 Redis 為主（多 instance）**；In-memory 僅作 fallback 或測試—見第 7 節。

---

## 7. Recommended Production Strategy

- **初期**：**SQL 載入 policy** + **In-Memory counter**（單 instance 或 canary）— 用於驗證邏輯與負載輪廓。  
- **正式多進程／多主機**：改 **Redis counter**（或專用限流服務）；**SQL 不保存高頻 counter**。  
- **Redis unavailable**：需 **fallback**—（1）**fail-closed**（短暫 503／429 保守）或（2）**limited fallback**（單機 in-memory 並強制低限流）— **不得**無提示地變成無限流量。  
- **觀測**：對 driver 錯誤率、fallback 啟用次數、429 比率建立儀表板。

---

## 8. Rate Limit Check Flow

1. **GatewayKernel** 收到 request。  
2. **Tenant Mapping** 完成。  
3. **API Key** 驗證完成。  
4. **Service Permission** 驗證完成。  
5. **`RateLimitPolicyRepository`** 載入 policy（SQL／cache）。  
6. **`RateLimiter`** 依 policy 與 context **產生 key** 與視窗描述。  
7. **Driver** **原子性**增加 counter（或先讀後寫—實作須避免 race）。  
8. **判斷是否超限**；若超限 → **429**（見第 9 節）+ audit。  
9. **若未超限**，繼續 **Host B Proxy**。  

> **順序對齊 Phase 3：** Rate limit **在 Host B 前**執行。

---

## 9. 429 Response Design

**統一錯誤 JSON（建議與 `ErrorResponseBuilder` 相容）：**

- **`traceId`** — 必填。  
- **`errorCode`** — 字串 **`RATE_LIMIT_EXCEEDED`**（若 envelope 使用巢狀 `error`，則 **`error.code`** 為此值；兩種風格擇一凍結）。  
- **`message`** — 人類可讀短訊（避免洩漏內部 key）。  
- **`retryAfter`** — 建議整數秒；可同步 **`Retry-After`** HTTP header。  
- **`limit`** — 觸發超限之視窗上限（可選，避免暴露內部 policy id）。  
- **`remaining`** — 0 或省略；成功路徑可選回傳 `X-RateLimit-Remaining`（非本節重點）。

**HTTP status：** **429 Too Many Requests**。

---

## 10. Security Considerations

| 項目 | 說明 |
|------|------|
| **避免 client 偽造 IP** | 預設以 TCP 連線來源為主；若經反向代理，僅信任 **proxy header 白名單**（如 `X-Forwarded-For` 最右可信 hop 之策略由維運凍結）。 |
| **只信任 proxy header 白名單** | 拒絕任意 client 自帶之偽造 header 覆寫。 |
| **防止 tenant cross-limit** | key 必含 `sno`（或等價 tenant id）；policy 查詢必帶 tenant 條件。 |
| **不暴露內部 Redis key** | 429 與 log 不附 raw storage key；debug mode 須受控。 |
| **不記錄敏感資訊** | Audit 超限事件僅記錄摘要與 policy 名稱／id。 |

---

## 11. Failure Handling

| 情境 | 策略 |
|------|------|
| **Redis 不可用** | **Fail-closed**（短暫拒絕或極嚴格預設限流）或切換 **limited SQL／in-memory**（需上限）；**禁止**直接放行。 |
| **SQL policy 讀取失敗** | 使用 **最後已知 good cache** 或 **fail-closed**；不可 silent fallback 到「無限」。 |
| **Rate limiter driver exception** | 捕獲後計量 + audit internal code；對外建議 **503** 或 **429**（依產品）— **不得**未控管放行。 |
| **建議** | 預設偏向 **fail-closed**；若業務要求 availability 優先，須書面風險承擔與低預設上限。 |

---

## 12. Rollback Strategy

- **保留 Phase 4 In-Memory `RateLimiter`** 與 isolated tests。  
- **透過 config 切換 driver**：`memory` \| `redis` \| `sql_counter`（不建議長期）。  
- **可停用 Redis driver**：切回 memory／或僅 policy from SQL + memory counter。  
- **可切回 Phase 4 isolated 行為**：供 CI 與緊急降級；與 Stage 2「mock／sql 切換」模式一致。  

---

## 13. Deployment Checklist

- [ ] **Policy 欄位設計完成**（第 4 節 DBA review）  
- [ ] **Driver interface 設計完成**（`RateLimitCounterDriverInterface` 等命名於實作階段）  
- [ ] **In-Memory／SQL／Redis driver 行為定義完成**（含原子性與 TTL）  
- [ ] **429 response 格式完成**（與 `ErrorResponseBuilder` 對齊）  
- [ ] **測試案例規劃完成**（burst、分／時／日、跨 IP、Redis 掛掉）  

---

## 14. Success Criteria

- 可依 **`sno`**、**`api_key_id`**、**`service`**、**`client_ip`**（與 policy 綁定）正確限流。  
- **超限時正確回傳 429**，且含 **`traceId`** 與 **`RATE_LIMIT_EXCEEDED`**。  
- **不洩漏**內部儲存 key 或 Redis 結構。  
- **Driver 可切換**（設定或 DI），且切換後行為可測試驗證。  

---

## 15. Recommended Next Step

建議下一步建立 **End-to-End Testing** 規劃文件：

**文件路徑：** `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE5_STAGE6_E2E_TESTING_PLAN.md`

內容建議涵蓋：staging 全鏈路、與 Phase 4 isolated tests 的分工、錯誤碼矩陣、負載與限流／Host B timeout 合併場景。

---

## 安全與變更聲明（本文件交付）

| 項目 | 狀態 |
|------|------|
| 修改任何 PHP production code | **未進行** |
| 建立任何 PHP class 檔 | **未進行** |
| 連線 Redis | **未進行** |
| 建立資料表／執行 SQL | **未進行** |
| 寫入正式 DB／Redis／file log | **未進行** |
| 呼叫 Host B API | **未進行** |
| `git add` / `commit` / `push` | **未進行** |

**本文件路徑：** `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE5_STAGE5_RATE_LIMITER_PERSISTENCE_PLAN.md`
