# SQL Safe Migration 5.0 Pre-Phase 4 Mock-compatible Preflight Design

## 1. 目的

本文件定義 **MOCK_MODE** 下 **preflight**（以 `preflight_orchestrator.ps1` 為中心之整合流程）的**預期行為**，使 Phase 3／Phase 4 前測試能使用 **mock proposal** 與 **mock schema 文字**，**不**因 **`db_connection_guard`** 對非白名單 **mock server** 回報 FAIL 而將整體 **finalStatus** 誤判為失敗；同時**絕不**削弱 **PRODUCTION_PREFLIGHT_MODE** 之 DB guard 與治理要求。  
本文件**不**代表 Phase 4 啟用、**不**引入 Execute Mode。

## 2. 背景

Phase 3 **static test**（見 `phase3_static_test_results.md`）觀察到：

- **valid mock proposal** 經 `preflight_orchestrator.ps1` 執行時，**`proposal_checker`** 與 **risk** 路徑可通過，但 **`db_connection_guard`** 因 **mock server**（例如 `MOCK-NOCONNECT-PHASE3`）**不在允許清單**而 **status = FAIL**，導致 **finalStatus = FAIL**。
- 在 **production** 語意下，此防護**合理**（避免指向未知或錯誤之伺服器別名）。
- 在 **MOCK_MODE** 測試語意下，應提供**安全的** **no-db-check／mock-compatible** 行為：guard **仍不連線**，但**不**將「mock 專用 server」視為與 production 同等之阻擋，而以 **`skipped_by_mode`**（或同等欄位）**記錄**略過靜態 server 白名單檢查之事實。
- **不可**因此移除或預設關閉 production 路徑之 **DB guard**；**不可**以 `.env` 隱性切換。

## 3. 設計原則

- **Production safety first**：任何設計以正式路徑安全為優先。
- **MOCK_MODE 只適用 mock 測試**：僅測試目錄、fixture、CI mock 路徑；不得用於正式變更核准。
- **不可透過 `.env` 隱性切換**：mode 與 guard 行為不得依未宣告之環境檔。
- **mode 必須由明確參數指定**（opt-in）；缺漏則拒絕執行（見 §7、§8）。
- **production preflight 永遠不得跳過 DB guard**（完整靜態規則）。
- **mock mode 不得使用 production connection string**（不得載入、不得驗證實連線字串內容）。
- **mock mode 不得執行 SQL**。
- **dangerous／do_not_execute** 案例**永遠不可**進入 production 核准路徑。

## 4. 建議 Mode 參數

未來 `preflight_orchestrator.ps1`（實作於後續步驟）建議支援：

- **`-Mode MOCK`**
- **`-Mode DRY_RUN`**
- **`-Mode PRODUCTION_PREFLIGHT`**

（參數名稱可為 `-PreflightMode` 等，以實作規格為準；**必須**與 `pre_phase4_mode_separation_design.md` 一致。）

### 各模式 DB guard 行為（設計層級）

| Mode | DB Guard | 是否可連 SQL Server | 是否可讀 .env | 是否可執行 SQL | 用途 |
|------|----------|---------------------|---------------|----------------|------|
| **MOCK** | **不執行**「server 白名單」阻擋；改為 **skipped_by_mode**，並於報告載明；**仍不連線** | **否** | **否** | **否** | Mock proposal／checker／underestimation 演練；finalStatus 不依 DB guard 缺席而 FAIL |
| **DRY_RUN** | **完整執行** `db_connection_guard`（與現行靜態規則一致）；**不**因測試而跳過 | **否** | **預設否** | **否** | 真實命名之 plan-only；可搭配 schema-only 快照**檔案**；**不**連正式 DB |
| **PRODUCTION_PREFLIGHT** | **必須完整執行** DB guard；**不得** skip；**不得** migration SQL | **否**（本階段設計：無實連線診斷） | **預設否** | **否**（**不得**執行 migration SQL） | 正式變更前齊備性與靜態防護 |

> 註：**MOCK** 下「DB Guard skipped」僅表示**不套用**會導致 mock server FAIL 之**該段**靜態規則；**不**表示放寬 risk／underestimation／proposal 失敗條件。

## 5. MOCK_MODE Preflight 流程

1. 接收 **mock proposal**（路徑須在測試／mock 契約內）。
2. **驗證 proposal 格式**（`proposal_checker`）。
3. **執行 risk checker**（含計算風險與 `autoExecutable` 等）。
4. **執行 underestimation 判定**（`riskUnderestimated`；與 risk 同腳本輸出）。
5. **DB guard**：標示為 **`skipped_by_mode`**（不執行白名單 FAIL 邏輯），報告中註記原因（例如 `MOCK_MODE: db_connection_guard not applied to server whitelist`）。
6. **tenant_sno_checker**（建議仍執行，以維持與 DRY_RUN 一致之治理覆蓋；若與 mock 資料衝突可於測試 fixture 層調整）。
7. **禁止任何 SQL execution**（無 `Invoke-Sqlcmd`、無批次執行）。
8. 產生 **mock preflight report**（§6 欄位）。
9. **finalStatus** 僅依 **proposal／risk／underestimation／tenant_sno** 等**實際執行之 checker** 決定；**不因** DB guard 被略過而**直接** FAIL；若 risk 低估或 proposal FAIL，仍 **FAIL**。

## 6. MOCK_MODE Report 欄位

Mock preflight 報告（Markdown 或機讀區塊）**至少**應包含：

| 欄位 | 說明 |
|------|------|
| **mode** | 固定或記錄為 `MOCK` |
| **dbGuardStatus** | 例如 `SKIPPED_BY_MODE` |
| **dbGuardReason** | 人類可讀略過原因 |
| **sqlExecutionAllowed** | `false` |
| **envReadAllowed** | `false` |
| **productionConnectionAllowed** | `false` |
| **riskLevel** | 取自 risk_checker 之 calculated／declared 摘要 |
| **riskUnderestimated** | 布林 |
| **finalStatus** | `PASS` / `FAIL` / `BLOCKED`（與現行 orchestrator 語意對齊） |
| **safetyWarnings** | 列舉與 mock 相關之提醒（例如「DB guard skipped；不可用於 production」） |

## 7. Production Guard 不可被削弱

- **PRODUCTION_PREFLIGHT** **不可** skip **DB guard**。
- **production** **不可**接受 **mock server** 作為有效目標（應 FAIL 或阻擋）。
- **production** **不可**接受 **do_not_execute** 類案例進入核准通過路徑。
- **production** **不可**因測試便利而降級檢查或預設寬鬆。
- **若未指定 Mode**：應**拒絕執行**並回傳明確錯誤（與 `pre_phase4_mode_separation_design.md` §7 一致）。

## 8. Misuse 防護

應阻擋或**明確 FAIL** 之情境（實作時以腳本／測試覆蓋）：

- **MOCK** mode **+** production connection string（或偵測到 prod 語意之連線資料）。
- **MOCK** mode **+** 讀取 `.env`。
- **MOCK** mode **+** Execute Mode（或 Execute 旗標）。
- **PRODUCTION_PREFLIGHT** **+** **do_not_execute**／dangerous fixture 混入。
- **未指定 Mode**。
- **mode 名稱不合法**（非三種之一）。
- **dangerous case** 進入 **production** path（含偽造路徑／跳過標籤）。

## 9. 測試案例建議

未來應建立（或擴充）之測試：

- mock **valid** proposal：**MOCK** mode 下應 **PASS**（不因 DB guard 白名單失敗）。
- mock **invalid** proposal：**MOCK** mode 下應 **FAIL**（proposal_checker）。
- mock **underestimation**：**MOCK** mode 下應 **FAIL**（或 **BLOCKED**，與 orchestrator 規則一致）。
- **PRODUCTION_PREFLIGHT** 若錯誤地 skip DB guard：應 **FAIL**（防護測試）。
- **未指定 mode**：應 **FAIL**。
- **invalid mode**：應 **FAIL**。
- **do_not_execute** 意圖進入 **production**：應 **FAIL**。

## 10. 不在本階段執行事項

撰寫本文件之步驟中**不**執行：

- **不修改** `preflight_orchestrator.ps1` 或其他既有工具。
- **不建立** Execute Mode。
- **不執行** SQL。
- **不連線** SQL Server。
- **不讀取或修改** `.env`。
- **不處理** `db/tenant_service_limits.sql`。
- **不進入** Phase 4 正式啟用。

## 11. 結論

- 本文件完成後，**可進入 Step 3：Schema Diff Checker 設計文件**（離線比對兩份 schema-only 與報告格式）。
- **仍不應**進入 Phase 4；需完成後續設計與實作並通過測試與治理 review。
- **`tenant_service_limits.sql`** **仍不可**作為依賴或治理輸入；落地须 **DB Change Request**／**proposal**／**governed migration**。
- **Mock-compatible preflight** 為 **Phase 4 前必要補強之一**（見 `pre_phase4_hardening_plan.md`），與 mode separation 配套後再實作腳本。
