# SQL Safe Migration 5.0 — Phase 5 Step 6-B  
## Working Tree Cleanup Audit

**Date:** 2026-05-12  
**Nature:** Working tree inspection, attempted removal of obvious temp files, classification for Phase 5 Completion.  
**Constraints honored:** No edits to `.ps1`, `.json`, or existing tests. No SQL. No `git add` / `commit` / `push`. **Did not delete** `db/tenant_service_limits.sql` or any file under `db/migrations/v5/`.

---

## 1. 第一次 `git status --short`（清理前）

執行目錄：`C:\bbc-ai-bot`

```
 M db/migrations/v5/PHASE4_COMPLETION_REPORT.md
 M db/migrations/v5/approval_gate.ps1
 M db/migrations/v5/contracts/governed_migration_input.schema.json
 M db/migrations/v5/invoke_governed_migration.ps1
 M db/migrations/v5/tests/activation_test_suite.ps1
 M db/migrations/v5/tests/test_invoke_governed_migration.ps1
?? db/migrations/v5/FINAL_PRODUCTION_SIGNOFF_TEMPLATE.md
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
?? db/migrations/v5/tests/test_approval_gate_phase5_contract.ps1
?? db/migrations/v5/tests/test_final_signoff_validator.ps1
?? db/migrations/v5/tests/test_governed_wrapper_live_guard_skeleton.ps1
?? db/migrations/v5/tests/test_maintenance_window_validator.ps1
?? db/tenant_service_limits.sql
?? skel_err.txt
?? skel_out.txt
```

---

## 2. 暫存檔刪除作業（`skel_err.txt` / `skel_out.txt`）

| 動作 | 結果 |
|------|------|
| `Remove-Item -Force`（PowerShell） | **失敗** — 訊息指出檔案正由另一程序使用（`IOException` / file busy）。 |
| `cmd.exe del /f /q` | **未成功移除** — 執行後檔案仍存在（見下節 `git status`）。 |

**後續建議（需人工／環境配合，本步驟未再改檔）：**

- 關閉可能鎖定根目錄檔案的程式（例如 IDE、防毒即時掃描、背景 `Get-Content`），再於 repo root 手動刪除 `skel_err.txt`、`skel_out.txt`。  
- 或在維護視窗以系統管理員身分重試刪除（依組織規範）。

---

## 3. 第二次 `git status --short`（清理嘗試後）

與第一次比對：**`skel_err.txt` / `skel_out.txt` 仍為 `??`**，其餘列與 Phase 5 相關項目相同（`db/tenant_service_limits.sql` 仍為 `??`）。

```
 M db/migrations/v5/PHASE4_COMPLETION_REPORT.md
 M db/migrations/v5/approval_gate.ps1
 M db/migrations/v5/contracts/governed_migration_input.schema.json
 M db/migrations/v5/invoke_governed_migration.ps1
 M db/migrations/v5/tests/activation_test_suite.ps1
 M db/migrations/v5/tests/test_invoke_governed_migration.ps1
?? db/migrations/v5/FINAL_PRODUCTION_SIGNOFF_TEMPLATE.md
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
?? db/migrations/v5/tests/test_approval_gate_phase5_contract.ps1
?? db/migrations/v5/tests/test_final_signoff_validator.ps1
?? db/migrations/v5/tests/test_governed_wrapper_live_guard_skeleton.ps1
?? db/migrations/v5/tests/test_maintenance_window_validator.ps1
?? db/tenant_service_limits.sql
?? skel_err.txt
?? skel_out.txt
```

> 註：本報告建立後，下次 `git status` 將多一筆 `?? db/migrations/v5/step6b_working_tree_cleanup_audit.md`。

---

## 4. Phase 5 應納入 vs 應排除／另案處理

### 4.1 建議納入 Phase 5 版控（`db/migrations/v5/` 與相關修改）

| 類別 | 路徑示例 |
|------|----------|
| 已修改 `M` | `approval_gate.ps1`、`invoke_governed_migration.ps1`、`contracts/governed_migration_input.schema.json`、`tests/activation_test_suite.ps1`、`tests/test_invoke_governed_migration.ps1`、`PHASE4_COMPLETION_REPORT.md` |
| 未追蹤 Phase 5 產物 `??` | 政策／計畫／checklist、各 `step*.md` 稽核報告、`maintenance_window_validator.ps1`、`final_signoff_validator.ps1`、`FINAL_PRODUCTION_SIGNOFF_TEMPLATE.md`、`contracts/CONTRACT_DESIGN_NOTES.md`、`contracts/governed_migration_input.example.json`、Phase 5 測試腳本 |

### 4.2 應排除於「僅 Phase 5 治理 bundle」或需單獨決策

| 路徑 | 建議 |
|------|------|
| `db/tenant_service_limits.sql` | **保留檔案**（依限制未刪除）。是否納入 Git 由變更單／DB 團隊決定；**不應**與「治理腳本-only」commit 混為一體時未加說明。 |
| `skel_err.txt`、`skel_out.txt` | **應排除**於正式交付；目前因鎖定無法刪除，應於解鎖後刪除或加入 `.gitignore`（若組織允許產生此類暫存）。 |

---

## 5. 限制與安全聲明（本步驟）

| 項目 | 狀態 |
|------|------|
| 修改任何 `.ps1` | **否** |
| 修改任何 `.json` | **否** |
| 修改任何既有測試 | **否** |
| 執行 SQL | **否** |
| `git add` / `commit` / `push` | **否** |
| 刪除 `db/tenant_service_limits.sql` | **否**（未刪除） |
| 刪除 `db/migrations/v5/` 下檔案 | **否** |

---

## 6. 是否可進入 `PHASE5_COMPLETION_REPORT.md`

**結論：可以進入撰寫** `PHASE5_COMPLETION_REPORT.md`。

**建議於 Completion 中明載：**

1. `skel_err.txt` / `skel_out.txt` 清理狀態（本機鎖定時未刪除之處理方式）。  
2. `db/tenant_service_limits.sql` 為獨立 artifact，與 Phase 5 治理腳本 commit 策略分開說明。  
3. 未提交檔案清單與建議分批 PR（延續 Step 6-A 建議）。

---

*本步驟僅新增本檔 `step6b_working_tree_cleanup_audit.md`。*
