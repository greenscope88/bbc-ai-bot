# API Gateway Phase 5 Stage 7 — Production Rollout Plan

**專案根目錄：** `C:\bbc-ai-bot`  
**文件版本：** 2026-05-14  
**性質：** 規劃文件（Planning only）。**不**實作程式、**不**實際上線、**不**呼叫 Host B API、**不**執行 SQL、**不**寫入正式 DB／Redis／檔案 log、**不**進行 git 寫入。

---

## 文件目的

本文件規劃 API Gateway **正式導入 production** 之完整策略，包含：

- **分階段啟用**（降低影響半徑）  
- **Smoke test**、**Rollback**、**監控**與**驗收標準**  
- **人工核准**與 sign-off 流程  

以確保在 **Phase 5 Stage 1～6 之實作與測試就緒後**，可安全將 Gateway 導入 production。

本階段**只做設計**，不實際上線。

---

## 1. Stage 7 Objective

| 目標 | 說明 |
|------|------|
| **規劃 Production Rollout Strategy** | 由內部驗證 → 試點租戶 → 受控釋出 → 全量啟用。 |
| **定義上線前檢查與人工核准流程** | checklist、owner、時間窗、go／no-go。 |
| **定義 Smoke Test 與 Rollback 流程** | 可重複執行之 runbook；rollback 須演練過。 |
| **確保可安全導入 production** | 與既有 webhook／主站路由解耦；預設可一鍵還原。 |

---

## 2. Rollout Principles

| 原則 | 說明 |
|------|------|
| **Small blast radius** | 先單一 path／單一試點租戶／低流量百分比；避免全站一次切換。 |
| **Reversible deployment** | Feature flag、reverse proxy 權重、可還原之設定與 artifact。 |
| **Human approval gate** | 真實 Host B、production DB 寫入、全量開關等關卡須 **書面／工單核准**。 |
| **Monitoring first** | 儀表板與告警先於放量就緒；無監控不放量。 |
| **No irreversible changes** | 禁止無備份之 DDL／大量資料破壞性操作；金鑰輪替需可回滾計畫。 |

---

## 3. Deployment Phases

| 階段 | 名稱 | 重點 |
|------|------|------|
| **Phase A** | Internal Testing | 內部／CI、staging 全鏈路；Phase 4 isolated + Phase 5 Stage 6 E2E（staging）。 |
| **Phase B** | Limited Tenant Pilot | 選定 **低風險試點租戶**（第 6 節）；限制 service 與 QPS；24～72h 密切監控。 |
| **Phase C** | Controlled Production Release | Production path 開啟但 **低權重**或 **IP allowlist**；Smoke 與 canary 指標達標後漸進放量。 |
| **Phase D** | Full Production Activation | 全租戶／全服務（依產品範圍）；仍保留 rollback 開關與 runbook。 |

---

## 4. Pre-Deployment Checklist

- [ ] **所有 Phase 6（E2E）測試計畫所列測試**於約定環境 **通過**（或已登記之豁免經核准）  
- [ ] **Rollback plan 已演練**（含關閉 entry、切回 mock／in-memory、停用 Host B／audit／redis driver）  
- [ ] **Config 完整**（Host B base URL、timeout、DB／Redis、feature flag；無 secret 進 repo）  
- [ ] **Monitoring ready**（成功率、錯誤率、延遲、429、timeout、audit failure）  
- [ ] **Human approval 完成**（技術與業務 sign-off，見第 11 節）  

---

## 5. Smoke Test Plan

| 項目 | 目的 |
|------|------|
| **Health check** | Gateway entry 可達、bootstrap 正常、`/health` 或等價路由 200。 |
| **Valid request** | Happy path：`traceId`、2xx、audit 有成功列（若已啟用 persistence）。 |
| **Invalid API key** | 401／400 + 正確 `errorCode`，不洩漏內部細節。 |
| **Rate limit exceeded** | 429 + `RATE_LIMIT_EXCEEDED` + `Retry-After`（若啟用）。 |
| **Host B timeout simulation** | staging 或 fault injection；504／502 映射正確。 |
| **Trace ID verification** | 回應與 access／audit 可同源關聯。 |
| **Audit log verification** | 摘要欄位無敏感資料；寫入失敗有 internal 證據（不阻斷預設主流程）。 |

---

## 6. Pilot Tenant Strategy

- **選擇低風險 tenant**：非尖峰業務、可容忍短暫問題、與內部關係良好之合作方。  
- **限制服務範圍**：allowlist 少數 `service`（例如唯讀查詢）。  
- **限制流量**：QPS 上限、並發上限、或僅內部 IP。  
- **密切監控**：專用儀表板、縮短 on-call 回應時間、每日檢討至穩定為止。  

---

## 7. Rollback Plan

| 動作 | 說明 |
|------|------|
| **關閉新 HTTP entry** | rewrite／vhost 還原；流量不再進 Gateway。 |
| **切回 mock／in-memory driver** | Repository／rate limit／audit 依設定降級。 |
| **停用 Host B integration** | `mock` transport 或 503 維護 JSON。 |
| **停用 audit persistence** | in-memory sink 或僅 stdout metrics；避免 DB 壓力。 |
| **停用 rate limiter driver** | 保守 fail-closed 或已知安全之 memory 上限（依風險文件）。 |

**驗證：** rollback 後舊有 API 與 webhook **行為與上線前一致**。

---

## 8. Monitoring During Rollout

| 監控項目 | 說明 |
|----------|------|
| **Success rate** | 2xx 比例；試點階段基線與告警閾值。 |
| **Error rate** | 4xx／5xx 分佈；依 `errorCode` 聚合。 |
| **Latency** | P50／P95／P99；Host B 與 DB 分段。 |
| **Timeout count** | 上游與 DB timeout 分開計數。 |
| **429 count** | 限流命中；避免誤判為攻擊。 |
| **Audit failures** | 寫入失敗率；觸發 ops 告警。 |

---

## 9. Incident Response Plan

| 項目 | 內容 |
|------|------|
| **Severity levels** | 例：SEV1 全站不可用、SEV2 單租戶大範圍失敗、SEV3 邊角案例。 |
| **Escalation path** | L1 on-call → 工程負責人 → 管理層（依組織定義）。 |
| **Temporary mitigation** | 先 rollback／降流量／關閉非必要功能；再修 root cause。 |
| **Root cause analysis** | 事後 RCA：時間線、trace 樣本、變更單連結、預防再發措施。 |

---

## 10. Acceptance Criteria

- **Smoke test** 全部通過（第 5 節）。  
- **Error rate** 在可接受範圍（與試點前基線或 SLO 對照）。  
- **無 tenant isolation 問題**（抽查與自動化 cross-tenant 負例）。  
- **無敏感資訊洩漏**（抽樣 audit／access log）。  
- **Rollback** 可於約定時間內成功執行並驗證。  

---

## 11. Production Sign-Off

| 角色 | 確認事項 |
|------|----------|
| **Technical approval** | 架構、安全、效能、rollback、監控、測試報告。 |
| **Business approval** | 影響範圍、客戶溝通、服務時間窗、合約／SLA。 |
| **Final go／no-go decision** | 指定決策者；書面紀錄（工單或 release ticket）。 |

---

## 12. Post-Deployment Review

| 項目 | 說明 |
|------|------|
| **實際結果** | 與預期 KPI／SLO 對照表。 |
| **問題清單** | 含 severity、是否已結案。 |
| **改進項目** | 文件、監控、測試缺口。 |
| **Lessons learned** | 下輪 rollout 可複用或需修正之流程。 |

---

## 13. Success Criteria

- **Pilot tenant** 階段穩定（錯誤率、延遲、無 isolation 事件）。  
- **Controlled rollout** 階段穩定（放量曲線符合計畫）。  
- **Full production** 階段穩定（若適用）。  
- **Rollback** 經實際或演練驗證有效。  

---

## 14. Recommended Next Step

建議下一步彙整 **Phase 5 全流程文件與決策**，撰寫：

**文件：** `API Gateway Phase 5 Completion Report`  

**路徑：** `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE5_COMPLETION_REPORT.md`

內容建議包含：Stage 1～7 文件索引、關鍵決策摘要、未實作項目清單、與「Phase 6」若組織另訂名義時之對照說明（避免與 API Gateway **Phase 4** 混淆）。

---

## 安全與變更聲明（本文件交付）

| 項目 | 狀態 |
|------|------|
| 修改任何 PHP production code | **未進行** |
| 建立任何 PHP class | **未進行** |
| 實際上線／變更 production 流量 | **未進行** |
| 呼叫 Host B API | **未進行** |
| 執行 SQL | **未進行** |
| 寫入正式 DB／Redis／file log | **未進行** |
| `git add` / `commit` / `push` | **未進行** |

**本文件路徑：** `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE5_STAGE7_PRODUCTION_ROLLOUT_PLAN.md`
