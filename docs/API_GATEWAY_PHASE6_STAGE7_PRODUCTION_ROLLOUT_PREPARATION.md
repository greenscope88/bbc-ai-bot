# API Gateway Phase 6 Stage 7 — Production Rollout Preparation

**專案根目錄：** `C:\bbc-ai-bot`  
**文件版本：** 2026-05-14  
**性質：** 上線準備與啟用守衛（documentation + read-only guard）。**本階段不**啟用 live persistence、**不**啟用 Host B 對外 HTTP、**不**執行 SQL、**不**寫入正式 DB／Redis、**不**修改 `C:\Web\xampp\htdocs\www\api\gateway\index.php` 或 Apache／`.htaccess`。

**對齊規劃：** `docs/API_GATEWAY_PHASE5_STAGE7_PRODUCTION_ROLLOUT_PLAN.md`（Phase 5 命名之 Stage 7；本文件為 **Phase 6** 交付物編號）。

---

## 1. 啟用前檢查清單（Pre-activation）

- [ ] **CI／staging**：`tests/api_gateway/phase6_stage6/e2e_controlled_runner.php` 與各 stage skeleton 測試通過（或已登記豁免並核准）。
- [ ] **本文件與 runbook**：負責人、時間窗、go／no-go 決策者已指定。
- [ ] **設定**：`.env` 與部署設定中 **無** `GATEWAY_*` live／Host B 啟用（見第 3 節）；secret **未**進 repo。
- [ ] **Rollback**：關閉 entry、driver 降級、Host B 停用等步驟已演練（見第 6 節）。
- [ ] **監控**：成功率、錯誤率、延遲、429、timeout、audit 失敗等儀表與告警閾值就緒（見第 7 節）。
- [ ] **安全**：敏感資料遮罩、tenant 隔離負例、跨租戶測試已過（見第 8 節）。
- [ ] **人工核准**：技術／業務 sign-off（對齊 Phase 5 Stage 7 第 11 節精神）。

---

## 2. 必要環境變數（選用／預設安全）

以下由 `config/config.php` 讀取；**未設定時預設為安全關閉**。實際鍵名以 repo 內 `config/config.php` 為準。

| 變數 | 用途 | 預設（未設定） | Stage 7 允許值 |
|------|------|----------------|----------------|
| `GATEWAY_HOSTB_HTTP_ENABLED` | 是否允許對 Host B 發出 HTTP | `false` | 僅 `false`（本準備階段） |
| `GATEWAY_HOSTB_BASE_URL` | Host B base URL（啟用後使用） | 空字串 | 可預填 staging URL（仍須 `HTTP_ENABLED=false`） |
| `GATEWAY_HOSTB_CONNECT_TIMEOUT_SEC` / `GATEWAY_HOSTB_READ_TIMEOUT_SEC` | Timeout | 內建預設 | 依 SLO 調整 |
| `GATEWAY_AUDIT_LOG_PERSISTENCE_MODE` | Audit 持久化 | `disabled` | `disabled` 或 `dry_run` |
| `GATEWAY_RATE_LIMIT_PERSISTENCE_MODE` | 限流計數持久化 | `disabled` | `disabled` 或 `dry_run` |

**禁止（本準備階段）：** `GATEWAY_AUDIT_LOG_PERSISTENCE_MODE=live`、`GATEWAY_RATE_LIMIT_PERSISTENCE_MODE=live`、`GATEWAY_HOSTB_HTTP_ENABLED=true`（須經後續核准 rollout 步驟才可開啟）。

---

## 3. disabled / dry_run / live 切換規則

| 模組 | `disabled` | `dry_run` | `live` |
|------|------------|-----------|--------|
| **Audit persistence** | 丟安全例外或僅略過寫入（依實作）；**不寫 DB** | 僅驗證／正規化 payload，**不寫 DB** | 實際 INSERT／佇列（**須 DBA／核准後**） |
| **Rate limit persistence** | 不強制持久化計數；略過或允許通過（依實作） | 僅 `would_allow`／`would_reject` 計算，**不寫 DB／Redis** | 實際 counter store（**須核准後**） |
| **Host B HTTP** | 不發對外 HTTP（client 層拒絕） | N／A（仍以不發真實流量為原則至核准前） | 對設定之 base URL 發送（**須網路／憑證核准**） |

**切換原則：**

1. 僅能由 **設定／環境變數** 切換；不得由 client request body 指定 upstream。
2. 每次切換需 **變更單 + 監控窗口**；`live` 與 `HTTP_ENABLED=true` 須 **書面／工單核准**。
3. Stage 7 **準備期**：程式內建 `RolloutActivationGuard::assertControlledPreparationPhase()`（見 `core/api_gateway/production/rollout/RolloutActivationGuard.php`）會在偵測到 live／Host B 啟用時 **丟出例外**（供 CLI／可選 runtime 使用）。

---

## 4. 建議上線順序（Rollout order）

對齊 `API_GATEWAY_PHASE5_STAGE7_PRODUCTION_ROLLOUT_PLAN.md` 之 Phase A→D 精神，建議實務順序：

1. **Internal**：CI + staging 全鏈路；Phase 6 E2E controlled runner。
2. **Pilot tenant**：單一低風險 `sno`、allowlist service、低 QPS。
3. **Controlled production**：entry 低權重或 IP allowlist；Smoke 通過後漸進放量。
4. **Full**（若產品需要）：保留 rollback 開關與 runbook。

**技術依賴建議：** HTTP entry 穩定 → tenant／API key／permission → rate limit → audit → Host B（最後）。

---

## 5. Smoke test checklist（濃縮）

| 項目 | 期待 |
|------|------|
| Health／entry | 可達、bootstrap 正常、JSON `Content-Type` 合理 |
| 合法請求 | `traceId` 一致、2xx 路徑可驗證 |
| 無效 API Key | 401／400、正確 `errorCode`、無內部堆疊外洩 |
| Rate limit | 若啟用持久化驗證：429／`RATE_LIMIT_EXCEEDED`（staging） |
| Host B 錯誤／timeout | 502／504 映射與 audit 摘要無敏感欄位 |
| Trace | 回應與稽核可追溯同一 `traceId` |

詳細矩陣見 `docs/API_GATEWAY_PHASE5_STAGE6_E2E_TESTING_PLAN.md`。

---

## 6. Rollback plan（要點）

| 動作 | 說明 |
|------|------|
| 關閉 Gateway HTTP entry | rewrite／vhost 還原；流量不再進新 pipeline |
| 降級 driver | audit／rate limit 切回 `disabled` 或 in-memory／mock |
| 停用 Host B | `GATEWAY_HOSTB_HTTP_ENABLED=false`；必要時維護 JSON |
| 設定還原 | 回退 `.env` 部署版次或前次核准設定 bundle |

**驗證：** rollback 後既有 **webhook／主站 API** 行為與上線前一致。

---

## 7. Monitoring / Audit 檢查項目

| 類別 | 檢查 |
|------|------|
| 成功率 | 2xx 比例、試點基線 |
| 錯誤率 | 4xx／5xx、依 `errorCode` 聚合 |
| 延遲 | P50／P95／P99；Host B 分段 |
| Timeout | 上游與 DB 逾時分開計數 |
| 429 | 限流命中量；避免誤判 |
| Audit | 寫入失敗率、摘要無敏感欄位、失敗不阻斷主流程（預設） |

---

## 8. Security checklist

- [ ] API Key／token／Authorization **不**入 audit 白名單欄位與摘要。
- [ ] Client **不可**指定 Host B upstream URL；僅 server allowlist。
- [ ] Tenant 隔離：cross-tenant 負例通過。
- [ ] Log injection：trace／path 長度與字元集受限。
- [ ] 變更與金鑰輪替可回滾。

---

## 9. 程式守衛（Activation guard）

- **類別：** `core/api_gateway/production/rollout/RolloutActivationGuard.php`
- **方法：** `RolloutActivationGuard::assertControlledPreparationPhase()`
- **行為：** 若 `gateway.audit_log.persistence_mode`、`gateway.rate_limit.persistence_mode` 為 `live`，或 `gateway.host_b.http_enabled` 為 `true`，則丟出 `RuntimeException`（僅讀設定，**不**寫入任何 store）。

**CLI 驗證：** `tests/api_gateway/phase6_stage7/validate_rollout_readiness.php`

---

## 10. 文件索引

| 文件 | 說明 |
|------|------|
| `docs/API_GATEWAY_PHASE5_STAGE7_PRODUCTION_ROLLOUT_PLAN.md` | 原始 rollout 規劃 |
| `docs/API_GATEWAY_PHASE6_STAGE7_PRODUCTION_ROLLOUT_PREPARATION.md` | 本準備文件（Phase 6 編號） |
| `docs/API_GATEWAY_PHASE5_STAGE6_E2E_TESTING_PLAN.md` | E2E 範圍與矩陣 |

---

## 11. 變更聲明（本交付）

| 項目 | 狀態 |
|------|------|
| 啟用 live persistence／Host B HTTP | **未進行**（僅文件 + 讀取設定之 guard） |
| SQL／schema／DB／Redis | **未進行** |
| 修改 htdocs gateway entry／`.htaccess`／Apache | **未進行** |
| 修改 `core/api_gateway/isolated/` | **未進行** |
| `git add`／`commit`／`push` | **未進行** |

**本文件路徑：** `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE6_STAGE7_PRODUCTION_ROLLOUT_PREPARATION.md`
