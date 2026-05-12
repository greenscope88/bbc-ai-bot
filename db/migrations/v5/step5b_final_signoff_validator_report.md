# SQL Safe Migration 5.0 — Phase 5 Step 5-B  
## Final Sign-Off Validator Implementation Report

**Date:** 2026-05-12  
**Scope:** New plan-only `final_signoff_validator.ps1`, human template `FINAL_PRODUCTION_SIGNOFF_TEMPLATE.md`, unit tests, activation suite hook-up, and this report.

---

## 1. 新增檔案清單

| 檔案 | 說明 |
|------|------|
| `db/migrations/v5/final_signoff_validator.ps1` | Production Final Sign-Off 治理欄位驗證（plan-only、無 SQL）。 |
| `db/migrations/v5/FINAL_PRODUCTION_SIGNOFF_TEMPLATE.md` | 人工作業 Sign-Off 模板（13 節 + 注意事項）。 |
| `db/migrations/v5/tests/test_final_signoff_validator.ps1` | 10 項單元測試。 |
| `db/migrations/v5/step5b_final_signoff_validator_report.md` | 本報告。 |

---

## 2. 修改檔案清單

| 檔案 | 說明 |
|------|------|
| `db/migrations/v5/tests/activation_test_suite.ps1` | 於 `test_maintenance_window_validator.ps1` 之後加入 `Run-TestScript test_final_signoff_validator.ps1`。 |

---

## 3. Validator 驗證規則（`final_signoff_validator.ps1`）

**參數**

- `-ContractInputPath`（必填）  
- `-Mode`（可選）：`MOCK` \| `DRY_RUN` \| `LIVE_EXECUTE`；若提供則覆寫合約內 `mode` 作為 effective mode。  
- `-Environment`（可選）：`DEV` \| `STAGING` \| `PRODUCTION`；若提供則覆寫合約內 `environment`。

**規則摘要**

1. 合約路徑無法解析／不存在 → **FAIL**  
2. JSON 無法解析 → **FAIL**  
3. effective `mode`／`environment` 必須可解析且為允許列舉值（來自參數或合約）  
4. 合約必須包含 **`finalSignOff`** 屬性，且物件非 null  
5. **`finalSignOff.approved`** 必須為 **`true`**  
6. **`finalSignOff.approvedBy`** 不可空白（trim 後）  
7. **`finalSignOff.approvedAt`** 必須可解析為 **ISO / Round-trip** date-time（`DateTimeOffset::Parse`，InvariantCulture）  
8. **`finalSignOff.ticketId`** 不可空白  
9. **`auditMetadata.changeRequestId`** 不可空白  
10. **`migrationFile`** 不可空白  

**PRODUCTION / LIVE_EXECUTE（需求第 9 點）**  
上述 `finalSignOff` 與關聯欄位檢查在 effective 組合下**一律執行**；MOCK／DRY_RUN 通過案例仍須滿足相同欄位完整性，避免「寬鬆通過」被誤解為 production live（並由輸出欄位註記，見下）。

**輸出 JSON（至少）**

- `component` = `"final_signoff_validator"`  
- `pass` = `true` / `false`  
- `mode`、`environment`、`checkedAt`  
- `reasons`（字串陣列）  
- `ticketId`、`changeRequestId`、`approvedBy`（通過時回傳所讀取之值；失敗時可能為空字串）  
- **`liveExecutionEnabled`**：**恒為 `false`**  
- **`note`**：聲明本工具僅 plan-only 檢查，**不**啟用 `LIVE_EXECUTE`、**不**執行 SQL  

**Exit code：** `pass=true` → `0`；`pass=false` → `1`

---

## 4. Template 內容摘要（`FINAL_PRODUCTION_SIGNOFF_TEMPLATE.md`）

依序涵蓋：**Change Request ID**、**Migration File**、**Production Owner**、**Risk Accepted By**、**Approved By**、**Approved At**、**Ticket ID**、**Rollback Plan Reviewed**、**Recovery Mode Acknowledged**、**Maintenance Window Confirmed**、**Backup Confirmed**、**Final Human Signature**、以及 **§13 簽核完成不代表 LIVE_EXECUTE 已啟用**（並指向 policy 與 `final_signoff_validator` 行為）。

---

## 5. 測試結果

| 測試 | 結果 |
|------|------|
| `tests/test_final_signoff_validator.ps1` | **PASS** |
| `tests/activation_test_suite.ps1` | **PASS** |

---

## 6. 是否修改 `approval_gate.ps1`

**否。**

---

## 7. 是否修改 `invoke_governed_migration.ps1`

**否。**

---

## 8. 是否啟用 `LIVE_EXECUTE`

**否。** 未變更 wrapper／gate；validator 僅讀合約 JSON，輸出 **`liveExecutionEnabled: false`**。

---

## 9. 是否執行 SQL

**否。**

---

## 10. 是否 `git add` / `commit` / `push`

**否。**

---

## 11. 建議下一步

1. **Step 5-C（可選）：** 在 `invoke_governed_migration.ps1` 的 `LIVE_EXECUTE` 鏈中**選擇性**呼叫 `final_signoff_validator.ps1`（需另開工作項；本次依限制未改 wrapper）。  
2. **Schema 版本化：** 若要把模板中的 Production Owner／Risk 等納入機讀契約，擴充 `governed_migration_input.schema.json` 並同步範例 JSON。  
3. **Checklist 對照：** 於 `PRODUCTION_ACTIVATION_CHECKLIST.md` 增加「欄位 ↔ 合約 JSON 路徑」對照列（文件 PR）。  
4. **時間盒：** 參考 Step 5-A 稽核，將 `approvedAt` 與維護窗／核准逾時規則串接（可由 gate 或獨立規則腳本實作）。

---

*本步驟遵守：不啟用 LIVE_EXECUTE、不執行 SQL、不改 `approval_gate.ps1`／`invoke_governed_migration.ps1`、不執行 git 寫入。*
