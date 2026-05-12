# SQL Safe Migration 5.0 — Phase 5 Step 3-B  
# Maintenance Window Validator Implementation Report

**類型：** 實作完成報告  
**產出日期：** 2026-05-12  
**範圍：** 獨立 **`maintenance_window_validator.ps1`**、單元測試、激活套件掛載；**不含** SQL、**未**修改 `approval_gate.ps1`／`invoke_governed_migration.ps1`、**未**進行 git 操作。  

---

## 1. 新增檔案清單

| 檔案 |
|------|
| `db/migrations/v5/maintenance_window_validator.ps1` |
| `db/migrations/v5/tests/test_maintenance_window_validator.ps1` |
| `db/migrations/v5/step3b_maintenance_window_validator_report.md`（本報告） |

---

## 2. 修改檔案清單

| 檔案 | 變更 |
|------|------|
| `db/migrations/v5/tests/activation_test_suite.ps1` | 於 `test_recovery_readiness_checker.ps1` 之後新增一行 **`Run-TestScript (Join-Path $testDir "test_maintenance_window_validator.ps1")`**。 |

---

## 3. Validator 驗證規則

| # | 規則 |
|---|------|
| 1 | **`ContractInputPath`** 無法解析或檔案不存在 → **FAIL** |
| 2 | JSON 無效 → **FAIL** |
| 3 | **`mode`** 必須為 `MOCK`／`DRY_RUN`／`LIVE_EXECUTE`（來自 **`-Mode`** 參數，否則來自 JSON **`mode`**） |
| 4 | **`environment`** 必須為 `DEV`／`STAGING`／`PRODUCTION`（來自 **`-Environment`** 參數，否則來自 JSON **`environment`**） |
| 5 | **`maintenanceWindow`** 必須存在且非 null → 否則 **FAIL** |
| 6 | **`maintenanceWindow.approved`** 必須為 **`$true`** |
| 7 | **`windowStart`**／**`windowEnd`** 須可解析為 **`DateTimeOffset`**（InvariantCulture + RoundtripKind） |
| 8 | **`windowEnd`** 必須**嚴格晚於** **`windowStart`**（`>`） |
| 9 | **`approvedBy`** Trim 後不可空白 |
| 10 | **`mode = LIVE_EXECUTE`** 且 **`environment = PRODUCTION`**：UTC **現在時間**須落在 **`[windowStart, windowEnd]`**（含邊界；區間外則 **FAIL**） |
| 11 | **`MOCK`** 或 **`DRY_RUN`**：僅執行規則 1～9，**不**檢查目前時間是否在維護窗內 |

**說明：** 本腳本為 **plan-only**（與 `recovery_readiness_checker.ps1` 註解精神一致），**不連線** SQL Server、**不執行** SQL。

---

## 4. 輸出與 Exit code

**stdout JSON** 欄位（至少）：

- `component` = `"maintenance_window_validator"`  
- `pass` = `true`／`false`  
- `mode`、`environment`（有效化後之字串）  
- `checkedAt`（UTC ISO 8601）  
- `reasons`（字串陣列；通過時為空陣列）  
- `windowStart`、`windowEnd`（來自輸入之字串回显；失敗早期可能為空字串）  

**Exit code：** `pass == true` → **0**；`pass == false` → **1**。

---

## 5. 測試結果

| 指令 | 結果 |
|------|------|
| `tests/test_maintenance_window_validator.ps1` | **PASS**（exit 0） |
| `tests/activation_test_suite.ps1` | **PASS**（exit 0） |

**涵蓋案例（單元測試）：** 缺檔、`maintenanceWindow` 缺失、`approved=false`、結束早於開始、`approvedBy` 空白、MOCK／DRY_RUN 格式通過、PRODUCTION+LIVE 窗外在過去、PRODUCTION+LIVE 窗內含目前 UTC。

---

## 6. 是否修改 `approval_gate.ps1`

**否。**

---

## 7. 是否修改 `invoke_governed_migration.ps1`

**否。**

---

## 8. 是否執行 SQL

**否。**

---

## 9. 是否 git add / commit / push

**否。**

---

## 10. 建議下一步

1. **Phase 5 Step 4（wrapper）** — 在 **`invoke_governed_migration.ps1`** 於未來允許之治理鏈中，選擇性呼叫 **`maintenance_window_validator.ps1`**（或內嵌等效邏輯），並維持 **`LIVE_EXECUTE`** 預設拒絕直至組織啟用。  
2. **可選 `-ReferenceUtc`** — 供 CI 完全決定性測試（見 `step3a_maintenance_window_validator_audit.md`）；目前測試 9 以寬窗＋動態起訖達成穩定通過。  
3. **契約延伸** — 若導入 **`emergencyOverride`**／**`timezone`**，同步更新 schema 與本 validator。  
4. **輸出契約** — 可新增 `contracts/maintenance_window_validator_output.schema.json` 供 report generator 引用。

---

*本 validator 通過不代表已啟用 production `LIVE_EXECUTE`；wrapper 與組織流程仍為最終守門。*
