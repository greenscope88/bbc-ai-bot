# Phase 3 Mock Cases

## Purpose

本資料夾提供 **SQL Safe Migration 5.0 Phase 3 測試版**使用，內容僅為 **mock / sample**，供 dry-run、靜態檢查與流程演練。  
JSON 欄位 `requestId` 即對應政策文件中的 **DB Change Request 編號**；`invalid_missing_change_request_id.proposal.json` 刻意省略 `requestId` 以驗證 `proposal_checker.ps1` 會拒絕缺漏。

## Safety Rules

- Mock only
- Do not execute
- Do not connect SQL Server
- Do not use `.env`
- Do not use production DB
- Do not use `db/tenant_service_limits.sql`

## Case List

| 檔案 | 用途 |
|------|------|
| `valid_add_nullable_column.proposal.json` | 低風險 nullable `ADD_COLUMN` 之有效 mock，供 proposal / risk dry-run 通過路徑。 |
| `invalid_missing_change_request_id.proposal.json` | 缺少 `requestId`（Change Request id），預期 **proposal_checker** 失敗。 |
| `dangerous_drop_column.proposal.json` | `DROP_COLUMN`，預期 **risk_checker** 計算為 **High**（`reason` 標示 `do_not_execute`）。 |
| `dangerous_delete_without_where.proposal.json` | `DELETE` 動作代表無 WHERE 情境，預期計算為 **Critical**（`do_not_execute`）。 |
| `risk_underestimation_case.proposal.json` | 宣告 `Low` 但 `DROP_COLUMN`，預期 `riskUnderestimated` 為 true。 |
| `mock_schema_before.sql` | Schema diff 用純文字 mock「變更前」DDL，**不可執行**。 |
| `mock_schema_after.sql` | Schema diff 用純文字 mock「變更後」DDL，**不可執行**。 |
