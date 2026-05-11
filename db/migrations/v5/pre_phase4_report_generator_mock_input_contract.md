# SQL Safe Migration 5.0 Pre-Phase 4 Report Generator Mock Input Contract

## 1. 目的

本文件定義 report generator 在 **MOCK_MODE / DRY_RUN_MODE** 下的**標準輸入格式（input contract）**，避免依賴 `%TEMP%` 暫存檔、未定義 JSON 結構或不穩定輸入來源，讓治理報告可被**離線重現**、可被工具驗證、且安全地納入 Git 版本控管。

## 2. 背景

- Phase 3 中 `plan_report_generator.ps1` 可利用 **mock proposal** 與 **preflight 暫存輸出**產生報告。
- 目前 mock input（特別是 preflight / diff / recovery 相關輸入）之結構尚未正式定義，容易造成「不同測試輸入長相不一致」或「依賴非版本化暫存路徑」。
- Phase 4 前需建立正式 input contract，使報告產生流程在 **MOCK_MODE** 與 **DRY_RUN_MODE** 都可被一致驗證與稽核。
- contract 必須支援 **MOCK_MODE** 與 **DRY_RUN_MODE**，且不得連 SQL Server、不得執行 SQL、不得依賴 `.env`。

## 3. 設計原則

- **Contract-first**
- **Stable schema**
- **Versioned format**
- **Human readable**（可由人直接審閱）
- **Machine readable**（可由 validator/CI 檢查）
- **No .env dependency**
- **No SQL execution**
- **No SQL Server connection**
- **Safe for Git version control**（不得包含密碼/connection string）

## 4. 建議輸入檔案

建議以「一組輸入資料夾」為單位，至少包含：

1. `proposal.json`
2. `preflight_result.json`
3. `schema_diff_result.json`（optional）
4. `recovery_plan.json`（optional）

> 註：本 contract 僅定義輸入檔的結構與最小欄位；實作上 report generator 可再補充欄位，但不得破壞相容性（見 §7）。

## 5. 建議輸入欄位

### 5.1 `proposal.json`

至少包含：

- `contractVersion`
- `requestId`
- `tenant`
- `requestedBy`
- `summary`
- `declaredRiskLevel`
- `actions[]`

建議欄位語意（範例）：

- `contractVersion`: string，例如 `"1.0"`
- `requestId`: string（DBCR id）
- `tenant`: string（例如 `"single"` / `"all"` / `"mock"`；不得放正式 sno 或敏感 id）
- `requestedBy`: string（人員或系統識別）
- `summary`: string（變更摘要）
- `declaredRiskLevel`: `"Low" | "Medium" | "High" | "Critical"`
- `actions`: array（每一項至少可描述 action/table/column/type 等）

### 5.2 `preflight_result.json`

至少包含：

- `contractVersion`
- `mode`
- `finalStatus`
- `riskLevel`
- `riskUnderestimated`
- `dbGuardStatus`
- `safetyWarnings[]`
- `executedChecks[]`

建議欄位語意：

- `mode`: `"MOCK_MODE" | "DRY_RUN_MODE" | "PRODUCTION_PREFLIGHT_MODE"`（本 contract 僅允許 MOCK/DRY_RUN 被用於 mock input）
- `finalStatus`: `"PASS" | "FAIL" | "BLOCKED" | "NEEDS_REVIEW"`
- `dbGuardStatus`: `"PASS" | "FAIL" | "SKIPPED_BY_MODE" | "UNKNOWN"`
- `executedChecks`: 例如 `["proposal_checker", "risk_checker", "db_connection_guard"]`

### 5.3 `schema_diff_result.json`（optional）

至少包含：

- `contractVersion`
- `finalStatus`
- `highestRisk`
- `totalChanges`
- `unexpectedChanges`
- `diffDetails[]`

建議欄位語意：

- `highestRisk`: `"Low" | "Medium" | "High" | "Critical"`
- `diffDetails`: array（每項含 objectType、name、changeType、risk、expected 之摘要）

### 5.4 `recovery_plan.json`（optional）

至少包含：

- `contractVersion`
- `recoveryMode`
- `manualApprovalRequired`
- `rollbackStrategy`
- `backupRequirements[]`

建議欄位語意：

- `recoveryMode`: 例如 `"RecoveryModeA"`
- `manualApprovalRequired`: boolean（在 Recovery Mode A 下應為 true）
- `backupRequirements`: array（例如 `[".bak before execution", "schema-only before/after", "diff report"]`）

## 6. Report Generator 輸出內容

報告應整合並呈現至少下列段落（不限定格式，但應一致且可稽核）：：

- Request Summary
- Proposal Validation
- Risk Assessment
- Underestimation Result
- DB Guard Result
- Schema Diff Result
- Recovery Preparedness
- Final Recommendation
- Safety Warnings

## 7. Contract Versioning

- `contractVersion` **必填**（所有輸入檔都必須有）
- 若版本不支援，report generator 應 **FAIL**
- 報告中應顯示 `contractVersion`（以及各檔案版本是否一致）
- 未來欄位新增須保持 **backward compatibility**：
  - 舊欄位不得移除或改語意
  - 新欄位應為選填或提供合理預設

## 8. Validation Rules

- `proposal.json` **必填**
- `preflight_result.json` **必填**
- `schema_diff_result.json` **選填**
- `recovery_plan.json` **選填**
- 缺少必要欄位：**FAIL**
- JSON 格式錯誤：**FAIL**
- `contractVersion` 不支援：**FAIL**

## 9. MOCK_MODE 使用方式

- 使用 mock `proposal.json`
- 使用 mock `preflight_result.json`
- schema diff / recovery 為可選（視測試目的）
- 可完全**離線**產生治理報告
- 不需 SQL Server
- 不需 `.env`

## 10. DRY_RUN_MODE 使用方式

- 使用真實（或治理流程核准）之 proposal 輸入（仍不得含敏感連線資訊）
- 使用 dry-run `preflight_result.json`
- 使用 `schema_diff_result.json`（若 schema diff checker 已補強）
- 產出完整治理報告
- 不執行 SQL

## 11. Misuse 防護

report generator（或 contract validator）應阻擋：

- 任一 input path 指向 `.env` → **FAIL**
- 任一 input path 指向 `db/tenant_service_limits.sql` → **FAIL**
- missing `proposal.json` → **FAIL**
- invalid `contractVersion` → **FAIL**
- malformed JSON → **FAIL**

## 12. 建議未來檔案位置

建議建立下列目錄（本階段僅設計，不建立目錄）：：

- `db/migrations/v5/tests/mock_report_inputs/`（放置可重現的 mock input fixtures）
- `db/migrations/v5/contracts/`（放置 contract 定義與範例）

## 13. 不在本階段執行事項

- 不修改 `plan_report_generator.ps1`
- 不建立正式 contract validator
- 不執行 SQL
- 不連 SQL Server
- 不讀取或修改 `.env`
- 不處理 `db/tenant_service_limits.sql`
- 不進入 Phase 4

## 14. 結論

- 本文件完成後，**可進入 Step 5：Recovery Mode A Activation Checklist**（將 recovery contract 與治理 gate 納入 checklist）。
- **仍不應**進入 Phase 4 正式啟用。
- **仍不可**處理 `tenant_service_limits.sql`（若落地需走 DB Change Request / proposal / governed migration）。
- Report Generator Input Contract 為 Phase 4 前**必要補強之一**（穩定、可重現、可驗證）。

