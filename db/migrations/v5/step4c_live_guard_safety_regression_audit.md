# SQL Safe Migration 5.0 — Phase 5 Step 4-C  
## Live Guard Safety Regression Audit

**Date:** 2026-05-12  
**Nature:** Read-only audit and report only. No repository files were modified for this step. No SQL. No `git add` / `git commit` / `git push`.

---

## 1. Step 4-B 實際修改了哪些安全關鍵檔案

依 `step4b_wrapper_live_guard_skeleton_report.md` 與目前程式內容交叉檢視，**與 LIVE 守門／合約放行直接相關**的檔案如下：

| 檔案 | 安全角色 |
|------|----------|
| `db/migrations/v5/invoke_governed_migration.ps1` | **主包裝**：`LIVE_EXECUTE` 分支串接 gate、維護窗、recovery checker、`report_generator`；**終點固定**呼叫 `FailLiveSkeletonPassed`（`executed=false`）。 |
| `db/migrations/v5/approval_gate.ps1` | **合約驗證**：僅讀取合約 JSON 並 `exit 0/1`；`LIVE_EXECUTE` 且 `enableLiveExecution===true` 時不再被額外政策一律否決（仍受欄位與 PRODUCTION `finalSignOff` 約束）。 |
| `db/migrations/v5/tests/test_governed_wrapper_live_guard_skeleton.ps1` | **迴歸**：鎖定 LIVE skeleton 與 MOCK/DRY 行為。 |
| `db/migrations/v5/tests/test_approval_gate_phase5_contract.ps1` | **迴歸**：合約路徑下 gate 對 LIVE / PRODUCTION 的預期。 |
| `db/migrations/v5/tests/activation_test_suite.ps1` | **總套**：納入 skeleton 測試與 MOCK/DRY/LIVE 負向情境。 |

（Step 4-B 另可能調整 `test_invoke_governed_migration.ps1` 等；本稽核以「LIVE 安全邊界」為主軸。）

`maintenance_window_validator.ps1` 在 Step 4-B 為 **LIVE 鏈中被呼叫的依賴**；檔頭註明 **plan-only、無 SQL**，本次稽核未發現 Step 4-C 要求外之程式變更需求。

---

## 2. `approval_gate.ps1` 修改後，是否可能「單獨」放行 LIVE_EXECUTE

**語意上：** 是。在傳入 **`-ContractInputPath`** 且合約通過 `Test-ContractInput` 時，gate 可對 **`mode: LIVE_EXECUTE`** 回傳 **`success: true` / `approved: true`**（例如測試中 **STAGING + `enableLiveExecution: true` + `finalSignOff.approved: true`** 案例）。

**執行面：** **否。** `approval_gate.ps1` **不讀取 migration SQL**、**不呼叫 `invoke_governed_migration.ps1`**、**不連線資料庫**；僅輸出 JSON 並結束程序。  
因此「放行」僅代表 **合約欄位層級的核准訊號**，**不構成 production SQL 執行**。

---

## 3. `invoke_governed_migration.ps1` 是否仍在最後「拒絕」LIVE_EXECUTE（實際執行）

**是。** 當 Phase 4 payload 的 `mode` 為 `LIVE_EXECUTE` 且所有前置檢查與子程序皆成功時，流程最後**必定**呼叫 `FailLiveSkeletonPassed`（約第 48–56、252 行）：輸出 JSON 中 **`success: false`**、**`pass: false`**，並 **`exit 1`**。  
不存在任何「成功結束且 `success: true`」的 LIVE 路徑。

`LIVE_EXECUTE` 分支結束後的 `Fail "LIVE_EXECUTE is not supported in this phase"`（約 255–257 行）在邏輯上為 **MOCK/DRY 以外之防呆**；正常 LIVE 已在上方 `exit 1` 離開。

---

## 4. 是否存在任何 SQL execution branch 被 LIVE_EXECUTE 呼叫

**就靜態檢視 `invoke_governed_migration.ps1` 之 LIVE 區塊：** 子程序僅為：

- `approval_gate.ps1`
- `maintenance_window_validator.ps1`（檔頭：無 SQL）
- `recovery_readiness_checker.ps1`（檔頭：plan-only，不連 SQL Server／不執行 SQL）
- `report_generator.ps1`（檔頭：不連 SQL、不執行 SQL）

**未發現** `Invoke-SqlCmd`、`sqlcmd`、ADO/SqlClient、或執行 `migrationFile` 指向之 `.sql` 的程式碼。  
`contract.migrationFile` 僅存在於合約資料中，**wrapper 未在 LIVE 路徑開啟或執行該檔**。

---

## 5. `executed` 是否在 LIVE_EXECUTE 下仍固定為 `false`

**是。**

- `FailLive`（約 32–45 行）：一律 **`executed = $false`**。
- `FailLiveSkeletonPassed`（約 48–56 行）：**`executed = $false`**。
- 成功路徑（僅 `MOCK` / `DRY_RUN`，約 326–334 行）：**`executed = $false`**。

LIVE 路徑**沒有** `executed: true` 的輸出分支。

---

## 6. `liveExecutionEnabled` 是否仍固定為 `false`

**是。** `FailLive` 預設參數為 `$LiveExecutionEnabled = $false`；LIVE 鏈中的失敗呼叫均未傳入 `$true`。  
`FailLiveSkeletonPassed` 明確設 **`liveExecutionEnabled: false`**。

---

## 7. `FinalManualConfirm` 是否只是守門條件，不代表真正執行

**是。** 其僅與參數 `-FinalManualConfirm` 及固定字串 `I_UNDERSTAND_THIS_IS_PRODUCTION_LIVE_EXECUTION`（約 207–209 行）比對；通過後仍進入報告產生與 **`FailLiveSkeletonPassed`**，**不觸發任何執行旗標為 true 的輸出**。

---

## 8. MOCK / DRY_RUN 是否未受影響

**是（由測試背書）。**  

- `test_governed_wrapper_live_guard_skeleton.ps1` 案例 7、8 直接驗證 wrapper。  
- `activation_test_suite.ps1` 內 MOCK/DRY 流程與治理負向測試仍執行。

本次稽核執行之 **`activation_test_suite.ps1` 結果為 PASS**（見第 9 節）。

---

## 9. `activation_test_suite` 是否仍 PASS

**是。** 2026-05-12 執行結果：

| 指令 | 結果 |
|------|------|
| `tests/test_approval_gate_phase5_contract.ps1` | **PASS** |
| `tests/test_governed_wrapper_live_guard_skeleton.ps1` | **PASS** |
| `tests/activation_test_suite.ps1` | **PASS** |

---

## 10. 是否有任何測試可能誤把 skeleton pass 當成 production execution enabled

**風險評估：低，但非零。**

- **案例 6** 明確斷言：`pass === false`、`executed === false`、`liveExecutionEnabled === false`，且 **`reason` 需等於**固定 skeleton 字串（含 *"skeleton guard passed"* 與 *"production execution is not enabled"*）。若僅以人眼瀏覽 log 中的 *"passed"* 而忽略 **`success`/`executed`**，仍可能誤解。
- `test_approval_gate_phase5_contract.ps1` 中 **`LIVE_EXECUTE` + `enableLiveExecution: true`（STAGING）** 註解寫明 *「gate allows … still not wrapper execution」*，有助區分 **gate approved** 與 **實際執行**。

---

## 11. 是否建議補強 wording 或測試名稱，避免誤解

**建議（僅供後續步驟參考，本步驟未改檔）：**

1. **JSON `reason` 字串**：可於未來考慮加入更直白的前綴，例如 `SKELETON_ONLY_NO_SQL_EXECUTED:`（需改 wrapper 與測試期望值，非本步驟範圍）。  
2. **測試輸出／案例註解**：案例 6 註解可強調 *「全守門通過後仍必須 exit 1 + executed false」*。  
3. **`FailLiveSkeletonPassed` 內文** 仍寫 *"Phase 5 Step 4-B"*：與 Step 4-C 時間並存時，營運文件可註明「理由字串為歷史步驟標籤，不代表已啟用執行」。

---

## 12. 下一步是否可進行 Step 5：Final Sign-Off Governance

**條件式同意：可進行「治理／簽核流程」層級的 Step 5**，前提是範圍界定清楚：

- 目前 **LIVE_EXECUTE 仍無實際 SQL 執行**；Step 5 應以 **政策、證據、角色簽核、變更凍結** 為主，**不應**將 `approval_gate` 的 `approved: true` 直接等同「已核准上線執行」。  
- 若 Step 5 包含「啟用真實執行模組」，應另列 **獨立 Phase／獨立 PR、連線隔離、回滾與 CAB 再核准**，並以**新旗標／新路徑**實作，避免與本 skeleton 混淆。

---

## 附錄：稽核執行紀錄

- **修改檔案數（本 Step 4-C）：** 僅新增本報告 `step4c_live_guard_safety_regression_audit.md`。  
- **測試：** 見第 9 節（三組皆 PASS）。  
- **SQL：** 未執行。  
- **Git：** 未執行 add / commit / push。
