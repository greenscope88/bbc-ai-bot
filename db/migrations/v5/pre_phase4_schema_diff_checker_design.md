# SQL Safe Migration 5.0 Pre-Phase 4 Schema Diff Checker Design

## 1. 目的

本文件定義 Phase 4 前需補強的 **Schema Diff Checker**：用於**離線**比較兩份 `schema-only.sql`（Before / After），產出可稽核的 **diff report**，以支援 dry-run / governance 流程。此 checker **不連** SQL Server、**不執行** SQL、**不修改**正式 DB、**不讀取**或依賴 `.env`。

## 2. 背景

Phase 3 已確認：

- Phase 2 / Phase 3 目前**無**獨立 schema diff checker script。
- schema diff 只能在文件或 report 層級被**描述**（例如 Plan Report 需求產物清單），缺少可重複執行的離線檢查器。
- Phase 4 前需要一個可獨立執行的**離線 schema diff checker**，以降低「產物缺口」與「變更未對齊預期」的風險。
- 此 checker 必須只做**文字／結構差異分析**，不得連 SQL Server。

## 3. 設計原則

- **Offline only**
- **No SQL execution**
- **No SQL Server connection**
- **No .env access**
- **No production DB modification**
- **Input** 必須是兩份 `schema-only.sql` 檔案（Before / After）
- **Output** 必須是 diff report（可被審查、可歸檔）
- 必須標示 **EXPECTED / UNEXPECTED** changes
- 必須可被 **DRY_RUN / governance** 流程使用（例如 preflight / report generator 整合）

## 4. 建議腳本名稱與位置

建議未來實作腳本（本階段僅設計，不建立檔案）：  
`C:\bbc-ai-bot\db\migrations\v5\scripts\schema_diff_checker.ps1`

## 5. 建議輸入參數

未來腳本參數建議：

- `-BeforeSchemaPath`
- `-AfterSchemaPath`
- `-ExpectedChangePath`
- `-OutputReportPath`
- `-Mode`

其中：

- `Mode` 僅允許：`MOCK` / `DRY_RUN`
- **不允許** `PRODUCTION_EXECUTE`（或任何 Execute 語意）
- **若未指定 Mode，應拒絕執行**（安全預設）

## 6. 輸入檔案規則

### Before / After schema

- 必須是 `schema-only.sql`（僅結構，不含資料）
- 不得是 `.bak`
- 不得是 migration SQL（不得帶有可執行的 DML/DDL 編排語意作為正式腳本）
- 不得包含 production connection string
- 不得是 `.env`
- 不得是 `db/tenant_service_limits.sql`

### ExpectedChange

- 可為 JSON 或 Markdown
- 用於定義**本次預期 schema 變更**（供 EXPECTED / UNEXPECTED 判斷）
- 不可包含正式 DB 密碼或 connection string

## 7. Diff 分析內容

checker 應能分析與報告下列差異（至少以「可辨識與列舉」為目標；細節可依實作逐步擴充）：

- 新增 table
- 刪除 table
- 新增 column
- 刪除 column
- 修改 column type
- nullable 變更
- default constraint 變更
- index 變更
- primary key 變更
- foreign key 變更
- stored procedure / view 變更

## 8. 風險分類

### LOW

- 新增 nullable column
- 新增 index

### MEDIUM

- 新增 NOT NULL column with default
- default constraint change

### HIGH

- column type change
- nullable to NOT NULL
- foreign key change

### CRITICAL

- DROP TABLE
- DROP COLUMN
- primary key destructive change
- data-loss potential change

## 9. Expected / Unexpected Change 判斷

- 若 diff 條目可在 `ExpectedChange` 中對應到，標示為 **EXPECTED**
- 若 diff 條目無法在 `ExpectedChange` 中對應到，標示為 **UNEXPECTED**
- 若出現 **CRITICAL + UNEXPECTED**，則 `finalStatus = FAIL`
- 若僅出現 **LOW + EXPECTED**，則 `finalStatus = PASS`
- 若存在 **HIGH + EXPECTED**（或更高），則 `finalStatus = NEEDS_REVIEW`

> 註：若 `ExpectedChangePath` 缺失或解析失敗，建議預設至少為 `NEEDS_REVIEW`，並在 `safetyWarnings` 明確標示原因（避免「默認 PASS」）。

## 10. 報告格式

輸出報告（Markdown 或 JSON/Markdown 混合）至少包含：

- `mode`
- `beforeSchemaPath`
- `afterSchemaPath`
- `expectedChangePath`
- `totalChanges`
- `expectedChanges`
- `unexpectedChanges`
- `highestRisk`
- `finalStatus`
- `diffDetails`
- `safetyWarnings`

## 11. 與其他元件的關係

- `proposal_checker`：驗證需求格式（proposal JSON）
- `risk_checker`：評估 migration 風險與 underestimation
- `schema_diff_checker`：驗證 schema 結果（Before/After）是否符合預期變更，並分級風險與 EXPECTED/UNEXPECTED
- `report_generator`：彙整治理報告（可引用 schema diff report 與 finalStatus）
- `preflight_orchestrator`：可在 **DRY_RUN** 流程呼叫 `schema_diff_checker`，將結果納入 preflight 的 blocking / warnings（實作需配合 mode separation 設計）

## 12. 測試案例建議

未來應建立的測試（皆為離線 mock fixture，不連 DB）：：

- mock before/after 新增 nullable column → `PASS`
- mock unexpected `DROP COLUMN` → `FAIL`
- expected `HIGH` change（如 column type change）→ `NEEDS_REVIEW`
- missing `ExpectedChangePath` → `NEEDS_REVIEW`（或依治理策略 `FAIL`）
- invalid `Mode` → `FAIL`
- input 指向 `tenant_service_limits.sql` → `FAIL`
- input 指向 `.env` → `FAIL`

## 13. 不在本階段執行事項

- 不建立 `schema_diff_checker.ps1`
- 不執行 diff checker
- 不執行 SQL
- 不連 SQL Server
- 不讀取或修改 `.env`
- 不處理 `db/tenant_service_limits.sql`
- 不進入 Phase 4

## 14. 結論

- 本文件完成後，**可進入 Step 4：Report Generator Mock Input Contract 設計文件**（定義 report_generator 在 MOCK/DRY_RUN 下的固定輸入與輸出契約）。
- **仍不應**進入 Phase 4 正式啟用（需完成後續設計與實作、測試、治理 review）。
- **仍不可**處理 `tenant_service_limits.sql`（若落地必須走 DB Change Request / proposal / governed migration）。
- Schema Diff Checker 為 **Phase 4 前必要補強之一**（離線、可重複、可稽核）。

