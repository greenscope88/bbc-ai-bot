# SQL Safe Migration 5.0 — Phase 5 Pre-Commit Governance Report

**Date:** 2026-05-12  
**Purpose:** Pre-commit inspection and documentation only. **No** `git add` / `commit` / `push`. **No** SQL execution. **No** deletion of `db/tenant_service_limits.sql`. **Do not** stage `skel_err.txt` / `skel_out.txt`.

---

## 1. `git status --short`（原文）

執行目錄：`C:\bbc-ai-bot`  
（擷取時間：本報告撰寫時；建立本檔後，下次 `git status` 會多一筆 `?? db/migrations/v5/phase5_pre_commit_governance_report.md`，**應一併納入** Phase 5 commit 清單。）

```
 M db/migrations/v5/PHASE4_COMPLETION_REPORT.md
 M db/migrations/v5/approval_gate.ps1
 M db/migrations/v5/contracts/governed_migration_input.schema.json
 M db/migrations/v5/invoke_governed_migration.ps1
 M db/migrations/v5/tests/activation_test_suite.ps1
 M db/migrations/v5/tests/test_invoke_governed_migration.ps1
?? db/migrations/v5/FINAL_PRODUCTION_SIGNOFF_TEMPLATE.md
?? db/migrations/v5/PHASE5_COMPLETION_REPORT.md
?? db/migrations/v5/PHASE5_IMPLEMENTATION_PLAN.md
?? db/migrations/v5/PRODUCTION_ACTIVATION_CHECKLIST.md
?? db/migrations/v5/PRODUCTION_LIVE_EXECUTION_POLICY.md
?? db/migrations/v5/contracts/CONTRACT_DESIGN_NOTES.md
?? db/migrations/v5/contracts/governed_migration_input.example.json
?? db/migrations/v5/final_signoff_validator.ps1
?? db/migrations/v5/maintenance_window_validator.ps1
?? db/migrations/v5/step2a_approval_gate_compatibility_audit.md
?? db/migrations/v5/step2b_approval_gate_enhancement_report.md
?? db/migrations/v5/step3a_maintenance_window_validator_audit.md
?? db/migrations/v5/step3b_maintenance_window_validator_report.md
?? db/migrations/v5/step4a_wrapper_live_guard_audit.md
?? db/migrations/v5/step4b_wrapper_live_guard_skeleton_report.md
?? db/migrations/v5/step4c_live_guard_safety_regression_audit.md
?? db/migrations/v5/step5a_final_signoff_governance_audit.md
?? db/migrations/v5/step5b_final_signoff_validator_report.md
?? db/migrations/v5/step6a_phase5_pre_completion_audit.md
?? db/migrations/v5/step6b_working_tree_cleanup_audit.md
?? db/migrations/v5/tests/test_approval_gate_phase5_contract.ps1
?? db/migrations/v5/tests/test_final_signoff_validator.ps1
?? db/migrations/v5/tests/test_governed_wrapper_live_guard_skeleton.ps1
?? db/migrations/v5/tests/test_maintenance_window_validator.ps1
?? db/tenant_service_limits.sql
?? skel_err.txt
?? skel_out.txt
```

---

## 2. 應納入 Phase 5 commit 的檔案清單

**原則：** 僅 `db/migrations/v5/` 下與 Phase 5 治理、契約、測試、報告相關之變更；含 Phase 4 完成報告之銜接修改（同一路徑下）。

### 已追蹤修改（`M`）

| 路徑 |
|------|
| `db/migrations/v5/PHASE4_COMPLETION_REPORT.md` |
| `db/migrations/v5/approval_gate.ps1` |
| `db/migrations/v5/contracts/governed_migration_input.schema.json` |
| `db/migrations/v5/invoke_governed_migration.ps1` |
| `db/migrations/v5/tests/activation_test_suite.ps1` |
| `db/migrations/v5/tests/test_invoke_governed_migration.ps1` |

### 建議新增追蹤（`??` → 納入 commit）

| 路徑 |
|------|
| `db/migrations/v5/FINAL_PRODUCTION_SIGNOFF_TEMPLATE.md` |
| `db/migrations/v5/PHASE5_COMPLETION_REPORT.md` |
| `db/migrations/v5/PHASE5_IMPLEMENTATION_PLAN.md` |
| `db/migrations/v5/PRODUCTION_ACTIVATION_CHECKLIST.md` |
| `db/migrations/v5/PRODUCTION_LIVE_EXECUTION_POLICY.md` |
| `db/migrations/v5/contracts/CONTRACT_DESIGN_NOTES.md` |
| `db/migrations/v5/contracts/governed_migration_input.example.json` |
| `db/migrations/v5/final_signoff_validator.ps1` |
| `db/migrations/v5/maintenance_window_validator.ps1` |
| `db/migrations/v5/step2a_approval_gate_compatibility_audit.md` |
| `db/migrations/v5/step2b_approval_gate_enhancement_report.md` |
| `db/migrations/v5/step3a_maintenance_window_validator_audit.md` |
| `db/migrations/v5/step3b_maintenance_window_validator_report.md` |
| `db/migrations/v5/step4a_wrapper_live_guard_audit.md` |
| `db/migrations/v5/step4b_wrapper_live_guard_skeleton_report.md` |
| `db/migrations/v5/step4c_live_guard_safety_regression_audit.md` |
| `db/migrations/v5/step5a_final_signoff_governance_audit.md` |
| `db/migrations/v5/step5b_final_signoff_validator_report.md` |
| `db/migrations/v5/step6a_phase5_pre_completion_audit.md` |
| `db/migrations/v5/step6b_working_tree_cleanup_audit.md` |
| `db/migrations/v5/tests/test_approval_gate_phase5_contract.ps1` |
| `db/migrations/v5/tests/test_final_signoff_validator.ps1` |
| `db/migrations/v5/tests/test_governed_wrapper_live_guard_skeleton.ps1` |
| `db/migrations/v5/tests/test_maintenance_window_validator.ps1` |
| `db/migrations/v5/phase5_pre_commit_governance_report.md`（本檔建立後請納入） |

---

## 3. 必須排除的檔案清單（**不可** `git add`）

| 路徑 | 理由 |
|------|------|
| `db/tenant_service_limits.sql` | 依治理要求：與 Phase 5 治理 bundle 分離；可能為獨立 DB 變更 artifact。 |
| `skel_err.txt` | 根目錄暫存／探針殘留；**不得**混入 Phase 5 commit。 |
| `skel_out.txt` | 同上。 |

---

## 4. 是否有非 Phase 5 檔案混入（依目前 `git status`）

| 路徑 | 判定 |
|------|------|
| `db/tenant_service_limits.sql` | **非** `db/migrations/v5/` 內之 Phase 5 治理檔 → **必須排除**。 |
| `skel_err.txt`、`skel_out.txt` | **非** Phase 5 產物 → **必須排除**。 |
| `db/migrations/v5/PHASE4_COMPLETION_REPORT.md` | 屬 Phase 4 文件名，但路徑在 v5 且為銜接敘述之**意圖內修改**；若 PR 標題註明「Phase 5 + Phase 4 報告銜接」可納入；若堅持「僅 Phase 5 字樣之檔」則可拆 PR（建議與團隊約定）。 |

**結論：** 目前 `git status` 中**唯一明確混入且必須排除**的是 `db/tenant_service_limits.sql` 與兩個 `skel_*.txt`；其餘變更均在 `db/migrations/v5/` 下。

---

## 5. `activation_test_suite.ps1` 測試結果

| 項目 | 結果 |
|------|------|
| 命令 | `powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\bbc-ai-bot\db\migrations\v5\tests\activation_test_suite.ps1` |
| 輸出 | `PASS: activation_test_suite.ps1` |
| **Exit code** | **0** |

---

## 6. `production-live-enabled` 是否仍為 NO

**是。** 與 `PHASE5_COMPLETION_REPORT.md` 及現行實作一致：**未**宣告 production live；僅治理框架與 skeleton。

---

## 7. `LIVE_EXECUTE` 是否仍 `executed=false`

**是。** Wrapper 之 LIVE 路徑仍以 skeleton 終點結束，成功語意僅適用 MOCK／DRY，且 **`executed` 恒為 false**。

---

## 8. 是否有執行 SQL

**否**（本步驟僅 `git status`、執行 PowerShell 測試、寫入本報告）。

---

## 9. 是否有 `git add` / `commit` / `push`

**否。**

---

## 10. 是否可以進入下一步 staging

**可以。** 前提為：

1. 僅對 §2 清單執行 `git add`（路徑限 `db/migrations/v5/…`），**刻意不要** add §3 之排除項。  
2. 建立本報告後，將 `phase5_pre_commit_governance_report.md` 一併納入同一批 staging。  
3. PR 說明中註明：`production-live-enabled = NO`、未執行 production SQL、排除項清單。

---

*本報告為治理檢查紀錄，不構成 commit 或核准 live 之法律／組織效力。*
