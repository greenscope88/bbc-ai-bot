# SQL Safe Migration 5.0 — Phase 5 Step 2-B  
# Approval Gate Compatible Enhancement Report

**類型：** 實作完成報告  
**產出日期：** 2026-05-12  
**範圍：** `approval_gate.ps1` 相容模式擴充、Phase 5 契約驗證測試、激活套件掛載  

---

## 1. 修改了哪些檔案

| 檔案 | 變更摘要 |
|------|----------|
| **`db/migrations/v5/approval_gate.ps1`** | 新增選用參數 **`-ContractInputPath`**；未指定時維持 Phase 4 **Read-Host / exit code / JSON** 行為；指定時進入 **Contract 模式**（讀取 JSON、結構與治理規則驗證，成功時輸出與 legacy 相同之 `success`／`approved`／`timestamp`）。 |
| **`db/migrations/v5/tests/activation_test_suite.ps1`** | 於單元測試區段新增一行：執行 **`test_approval_gate_phase5_contract.ps1`**（介於 `test_approval_gate.ps1` 與 `test_recovery_readiness_checker.ps1` 之間）。 |

---

## 2. 新增了哪些檔案

| 檔案 | 用途 |
|------|------|
| **`db/migrations/v5/tests/test_approval_gate_phase5_contract.ps1`** | Contract 模式與 legacy 迴歸之自動化測試（子程序呼叫 gate，解析 stdout JSON）。 |
| **`db/migrations/v5/step2b_approval_gate_enhancement_report.md`** | 本報告。 |

---

## 3. Contract 模式如何啟用

```powershell
# 範例：以 Phase 5 governed migration input JSON 驗證（不經 Read-Host）
powershell.exe -NoProfile -File .\approval_gate.ps1 -ContractInputPath "C:\path\to\input.json"
```

- **必須**提供非空白之 `-ContractInputPath`，且路徑須可解析、檔案須為有效 UTF-8 JSON。  
- Contract 模式**不**呼叫 `Read-Host`；核准結果完全由 JSON 與內建規則決定。

---

## 4. Legacy 模式是否保留

**是。** 未傳入 `-ContractInputPath`，或僅傳入空白字串時：

- 仍輸出 `Write-Warning "This operation requires explicit human approval."`  
- 仍使用 **`Read-Host "Type YES to continue"`**  
- 非 `YES` 時：`exit 1`，JSON `reason` 仍為 **`User did not type YES`**  
- `YES` 時：`exit 0`，JSON 含 **`timestamp`**

與 **`test_approval_gate.ps1`** 行為一致（已跑通）。

---

## 5. Contract 模式驗證規則（摘要）

| 規則 | 行為 |
|------|------|
| 根屬性 | 須含 `mode`、`environment`、`migrationFile`、`enableLiveExecution`、`humanApprovals`、`maintenanceWindow`、`backupConfirmation`、`recoveryReadiness`、`finalSignOff`、`auditMetadata` |
| **humanApprovals** | 至少 2 筆；每筆須有非空 **`role`**、**`approver`**、**`approvedAt`**、**`signatureRef`** |
| **maintenanceWindow.approved** | 必須為 **`$true`**（所有環境） |
| **backupConfirmation.backupFile** | 必須非空字串 |
| **recoveryReadiness.status** | 必須為字串 **`PASS`** |
| **auditMetadata.changeRequestId** | 必須非空 |
| **PRODUCTION** | **`finalSignOff.approved`** 必須為 **`$true`** |
| **LIVE_EXECUTE** | 若 **`enableLiveExecution -ne $true`** → 拒絕（明確訊息）；若為 **`$true`** → 仍拒絕（**預設政策**：`LIVE_EXECUTE is denied by approval gate default policy`） |
| 缺檔／無效 JSON | `exit 1` 與 JSON **reason** |

---

## 6. 測試結果

| 測試指令 | 結果 |
|----------|------|
| `tests/test_approval_gate.ps1` | **PASS** |
| `tests/test_approval_gate_phase5_contract.ps1` | **PASS** |
| `tests/activation_test_suite.ps1` | **PASS** |

執行環境：本機 `powershell.exe -NoProfile -ExecutionPolicy Bypass -File ...`，**exit code 0**。

---

## 7. LIVE_EXECUTE 是否仍預設拒絕

**是（於 approval_gate Contract 模式）。**

- **`enableLiveExecution = false`** 且 **`mode = LIVE_EXECUTE`** → 拒絕（先於 default policy 檢查）。  
- **`enableLiveExecution = true`** 且 **`mode = LIVE_EXECUTE`** → 仍拒絕（default policy），**不可**僅憑契約欄位即視為 live 已核准。

**`invoke_governed_migration.ps1`** 未於本步驟修改；其對 **`LIVE_EXECUTE`** 之拒絕行為與 Phase 4 一致（激活套件負向測試仍通過）。

---

## 8. 是否有執行 SQL

**否。** 本步驟僅 PowerShell 與 JSON 檔案 I/O，無資料庫連線或 SQL 執行。

---

## 9. 是否有 git add / commit / push

**否。** 未執行任何 git 寫入指令。

---

## 10. 建議下一步

1. **Phase 5 Step 3 或 Step 4（依 `PHASE5_IMPLEMENTATION_PLAN.md`）** — 將 **`recovery_readiness_checker.ps1`** 之輸出與 **`maintenanceWindow`**／**`emergencyOverride`** 對齊，或由 **`invoke_governed_migration.ps1`** 在讀取舊 Phase 4 payload 時組裝／驗證 Phase 5 契約（仍須維持 **wrapper 預設拒絕 LIVE_EXECUTE** 直至組織啟用）。  
2. **選用：** 以 **`governed_migration_input.example.json`** 增列「Contract 模式預期失敗／成功」之固定測試資產路徑，與 CI 對齊。  
3. **文件：** 在 v5 README 或 `CONTRACT_DESIGN_NOTES.md` 補上一行 **`-ContractInputPath`** 啟用說明與與 legacy 互斥關係。

---

*本報告不代表 production live execution 已啟用；gate 通過僅為治理鏈之一環。*
