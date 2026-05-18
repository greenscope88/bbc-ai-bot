# API Gateway Phase 5 Stage 6 — End-to-End Testing Plan

**專案根目錄：** `C:\bbc-ai-bot`  
**文件版本：** 2026-05-14  
**性質：** 規劃文件（Planning only）。**不**實作程式、**不**建立測試程式碼、**不**呼叫 Host B API、**不**執行 SQL、**不**寫入正式 DB／Redis／檔案 log、**不**進行 git 寫入。

---

## 文件目的

本文件規劃 API Gateway 自 **HTTP Entry** 至 **Host B Proxy** 之 **端對端（E2E）測試策略**，以確保在 **Phase 5 後續各 Stage 正式實作與接線前**，已具備：

- 完整測試範圍與情境矩陣  
- 錯誤處理與 **errorCode／HTTP status** 期待值  
- **安全驗證**標準（tenant 隔離、遮罩、不可指定 upstream）  
- **測試不影響 production** 之分層環境策略  

本階段**只做設計**，不實作、不執行測試。

---

## 1. Stage 6 Objective

| 目標 | 說明 |
|------|------|
| **規劃完整 E2E 測試範圍** | 涵蓋成功／失敗／安全／回滾；與 Phase 4 isolated tests 互補而非取代。 |
| **驗證 API Gateway production pipeline** | 於 **staging** 或 **feature-flag 下之 canary** 驗證完整鏈路；預設不對 production 全量。 |
| **確保各模組皆有案例** | Tenant、API Key、Permission、Rate limit、Audit、Host B Proxy、Normalizer 均需可追溯到 `traceId`。 |
| **確保測試不影響 production** | 禁止未核准之寫入正式 DB／呼叫正式 Host B；production smoke 僅允許 **受控、唯讀、低頻** 且經人工核准。 |

---

## 2. E2E Testing Scope

| 範圍 | 測試重點（摘要） |
|------|------------------|
| **HTTP Entry** | 路由可達、bootstrap 載入、JSON `Content-Type`、非 Gateway path 不被誤導。 |
| **TraceIdMiddleware** | 外來 trace 驗證、非法值替換、全鏈路一致。 |
| **ErrorResponseBuilder** | `success`／`errorCode`／`traceId`／`timestamp`／`details` 形狀。 |
| **GatewayKernel** | pipeline 編排、例外不洩漏 stack、header `X-Trace-Id`（若啟用）。 |
| **Tenant Mapping Resolver** | 成功 mapping、各種租戶失敗碼。 |
| **API Key Verification** | 成功、缺失、格式、hash、過期、停用、租戶不一致等。 |
| **Service Permission Validation** | 允許／tenant 拒絕／key 拒絕、大小寫正規化。 |
| **Rate Limiter** | burst／分／時／日、429、`Retry-After`、不同 IP／service 分桶。 |
| **Host B Proxy** | mock → real 邊界、timeout、非 JSON、4xx／5xx 映射。 |
| **Response Normalizer** | 對外 envelope 一致、敏感欄位剔除。 |
| **Audit Logger** | 成功／失敗／限流／上游錯誤皆寫入（或佇列）；寫入失敗不阻斷主回應（除非 fail-closed 已核准）。 |

---

## 3. Test Environment Strategy

| 階層 | 說明 |
|------|------|
| **Isolated／mock** | 沿用 `tests/api_gateway/test_*_stage*_isolated.php`；無 DB、無 Host B 真實 HTTP；CI 必跑。 |
| **Staging config** | 連 **staging DB**／**mock 或 staging Host B**；完整 pipeline；可寫入 staging audit。 |
| **Controlled production smoke** | 僅於變更窗口、低流量、feature flag、**人工核准**後執行；**不得**直接操作正式 DB 作為測試手段。 |
| **測試不得直接操作正式 DB** | 正式資料變更僅能走變更流程；測試用資料與帳號分離。 |
| **測試不得直接呼叫正式 Host B API** | 除非已進入本 Stage 之 **controlled E2E** 子階段且 **人工確認** 網路／憑證／合約；預設使用 mock／staging B。 |

---

## 4. Test Case Categories

| 分類 | 涵蓋方向 |
|------|----------|
| **Success Path Tests** | 第 5 節完整 happy path；多組 service／method。 |
| **Tenant Mapping Failure Tests** | 缺 sno、格式錯、not found、disabled、suspended。 |
| **API Key Failure Tests** | 缺 key、格式錯、not found、hash mismatch、revoked、IP 不允許等。 |
| **Permission Failure Tests** | service 不在 key 或 tenant 允許清單。 |
| **Rate Limit Tests** | 429、burst、分鐘窗、跨 IP／跨 service 隔離。 |
| **Host B Timeout Tests** | connect／read timeout；504／502 映射與稽核。 |
| **Host B Error Mapping Tests** | 401／403／404／429／500 → Gateway 對外語意。 |
| **Audit Log Tests** | 欄位齊備、遮罩、寫入失敗 fallback 與 metrics。 |
| **Security Tests** | 第 7 節；含跨租戶與 injection。 |
| **Rollback Tests** | 第 12 節；驗證開關與降級路徑。 |

---

## 5. Success Path Test Plan

**正常流程（建議 E2E 腳本／手動 runbook 對照）：**

1. Request 帶入 **`sno`**（及必要 headers／body）。  
2. **Tenant mapping** 成功取得 tenant context。  
3. **API Key** 驗證成功，取得 `authenticatedContext`。  
4. **Service permission** 通過（tenant ∩ key）。  
5. **Rate limit** 通過（counter 遞增成功）。  
6. **Host B proxy** 回傳 **mock 或 staging** 成功 response（Stage 6 規劃期以 mock／staging 為主）。  
7. **Response normalizer** 產出標準成功 JSON（`success: true` 等—依專案凍結規格）。  
8. **Audit logger** 記錄成功事件（`http_status` 2xx、`error_code` 可為 null／`OK`—依凍結規格）。  

**斷言：** 回應 body 與 audit 列均可依 **`traceId`** 關聯。

---

## 6. Failure Path Test Plan

| 需測試情境 | 期待（摘要） |
|------------|----------------|
| **missing sno** | 4xx + 對應 errorCode（如 `MISSING_SNO`）。 |
| **invalid sno** | 4xx + 格式錯誤碼。 |
| **tenant disabled** | 403 + tenant 相關碼。 |
| **missing API key** | 401 + `MISSING_API_KEY`。 |
| **invalid API key** | 400／401 + 格式或驗證失敗碼。 |
| **expired API key** | 401 + `API_KEY_EXPIRED`。 |
| **service not allowed** | 403 + `SERVICE_NOT_ALLOWED` 或細分碼。 |
| **rate limit exceeded** | 429 + `RATE_LIMIT_EXCEEDED`。 |
| **Host B timeout** | 504／502 + 上游 timeout 類 errorCode。 |
| **Host B 500** | 502／500 + 對外遮罩後 errorCode。 |
| **audit write failed** | 主回應仍成功／失敗與業務一致；internal log／metrics 記錄失敗。 |

---

## 7. Security Test Plan

| 項目 | 期待 |
|------|------|
| **Client 不可指定 upstream URL** | 請求中含 host／url 欄位時被拒絕或忽略且不影響 Host B base URL。 |
| **API Key 不得出現在 log** | access／audit／debug 皆掃描；自動化比對 `bbc_*` 長字串。 |
| **Authorization header 不得被記錄** | audit 與 access log 欄位白名單。 |
| **SQL injection payload** | 參數化查詢下無效或安全拒絕；不反映 DB 錯誤於 client。 |
| **Path traversal** | 拒絕 `..`、非法 path；只允許 allowlist service→path。 |
| **Cross-tenant access** | 他租戶 `sno` + 本租戶 key → 403／401；不得洩漏存在與否以外的資訊。 |

---

## 8. Expected Error Code Matrix

以下為 **E2E 期待矩陣（草案）**；實際 errorCode 以 `API_GATEWAY_ERROR_AND_AUDIT_LOG_DESIGN.md` 凍結表為準，若命名與現有 Phase 4 微差，實作時以 **單一來源** 對齊。

| Scenario | Expected HTTP Status | Expected Error Code |
|----------|----------------------|---------------------|
| Missing sno | 400 | `MISSING_SNO` |
| Invalid sno format | 400 | `INVALID_SNO_FORMAT`（或矩陣中 `INVALID_TENANT`—實作統一） |
| Tenant not found / invalid tenant | 404 | `TENANT_NOT_FOUND` 或 **`INVALID_TENANT`**（擇一凍結） |
| Tenant disabled / suspended | 403 | **`TENANT_DISABLED`**／`TENANT_SUSPENDED`（依設計細分） |
| Missing API key | 401 | **`MISSING_API_KEY`** |
| Invalid API key format | 400 | `INVALID_API_KEY_FORMAT` 或 **`INVALID_API_KEY`** |
| API key not found / hash mismatch | 401 | `API_KEY_NOT_FOUND`／`API_KEY_HASH_MISMATCH` |
| API key expired | 401 | **`API_KEY_EXPIRED`** |
| Service not allowed | 403 | **`SERVICE_NOT_ALLOWED`**（或 `SERVICE_NOT_ALLOWED_BY_TENANT` 等細分） |
| Rate limit exceeded | 429 | **`RATE_LIMIT_EXCEEDED`** |
| Host B timeout | 504 或 502 | **`HOST_B_TIMEOUT`** 或 `GW_UPSTREAM_TIMEOUT`（凍結） |
| Host B upstream 5xx / mapping | 502 或 500 | **`HOST_B_ERROR`** 或 `GW_UPSTREAM_ERROR`（凍結） |
| Audit persistence failed（內部） | 主流程 HTTP **不**因 audit 而改變（預設） | **`AUDIT_LOG_FAILED`**（internal metric／log code；對外可不出現） |

---

## 9. Mock vs Real Integration Boundary

| 項目 | 說明 |
|------|------|
| **Phase 5 文件階段** | 僅規劃；**不**對正式 Host B 做自動化實測。 |
| **Phase 5 Stage 6（本文件所指 E2E）** | **Controlled E2E** 可於 **staging** 或 **canary** 執行；含 mock／real 邊界切換。 |
| **Host B real API** | 測試前須 **人工確認**（憑證、IP ACL、合約、尖峰時段禁止）。 |
| **測試必須可回滾** | feature flag、route 還原、driver 切回 mock；見第 12 節。 |

---

## 10. Test Data Strategy

- **Mock tenant**：使用 staging 專用 `sno` 與 mapping；不使用真實客戶識別作為測試預設。  
- **Mock API key hash**：於 staging DB 或 fixture 建立測試 key；**禁止**使用 production 金鑰明文。  
- **Mock Host B response**：契約測試與 fault injection（timeout、500、非 JSON）。  
- **不使用正式客戶個資**；測試 payload 使用合成資料。  
- **不使用正式 API key**；若需驗證 rotation，使用即將作廢之 **測試用** key。  

---

## 11. Logging and Evidence

每次 E2E 執行（手動或 CI）建議留存：

| 證據項目 | 說明 |
|----------|------|
| **test command** | 完整 CLI 或 curl／httpie 指令列。 |
| **request sample** | 去敏後之 headers／body 摘要。 |
| **response sample** | HTTP status + body（去敏）。 |
| **`traceId`** | 用於與 audit／access log 對查。 |
| **expected result** | 對照第 8 節矩陣。 |
| **actual result** | 截圖或 JSON diff。 |
| **pass／fail** | 與 build number／commit SHA 綁定。 |

---

## 12. Rollback Test Plan

| 項目 | 驗證方式 |
|------|----------|
| **停用 HTTP entry** | rewrite 關閉後，舊路徑 100% 恢復。 |
| **切回 mock repository** | config 切換後 resolver 讀 mock／memory。 |
| **切回 mock Host B proxy** | `hostb.transport=mock` 或等價設定。 |
| **停用 Redis driver** | 限流 fallback 符合第 5 Stage 文件之 fail-closed／limited 策略。 |
| **停用 audit persistence** | 切回 in-memory sink；業務回應不受阻。 |

---

## 13. Deployment Checklist

- [ ] **測試案例文件完成**（本文件 + 矩陣 + runbook）  
- [ ] **測試資料準備完成**（staging tenant／key／policy）  
- [ ] **mock／staging／production 邊界確認**（網路 ACL、flag 預設值）  
- [ ] **rollback plan 確認**（負責人、步驟、驗證點）  
- [ ] **人工 approval gate**（真實 Host B、production smoke）  

---

## 14. Success Criteria

- **所有 success path** 於 staging（或約定環境）通過。  
- **所有 failure path** 回應正確 **HTTP status** 與 **errorCode**（與第 8 節矩陣一致）。  
- **所有 security tests** 通過。  
- **不洩漏** API key／token／Host B internal error 細節。  
- **每個 request** 皆可取得或產生一致之 **`traceId`**。  
- **Rollback 測試**通過（第 12 節）。  

---

## 15. Recommended Next Step

建議下一步建立 **Production Rollout** 規劃文件：

**文件路徑：** `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE5_STAGE7_PRODUCTION_ROLLOUT_PLAN.md`

內容建議涵蓋：灰度比例、監控與告警、變更窗口、溝通計畫、與 Phase 5 Stage 1～6 的依賴順序。

---

## 安全與變更聲明（本文件交付）

| 項目 | 狀態 |
|------|------|
| 修改任何 PHP production code | **未進行** |
| 建立任何 PHP class 或測試程式 | **未進行** |
| 呼叫 Host B API | **未進行** |
| 執行 SQL | **未進行** |
| 寫入正式 DB／Redis／file log | **未進行** |
| `git add` / `commit` / `push` | **未進行** |

**本文件路徑：** `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE5_STAGE6_E2E_TESTING_PLAN.md`
