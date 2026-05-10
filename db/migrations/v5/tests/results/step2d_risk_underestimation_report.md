# SQL Safe Migration 5.0 - Step 2-D-3 Risk Checker Underestimation Report

## 1) 測試時間

- Test Time: 2026-05-09 15:49:24 +08:00

## 2) 測試工具

- `risk_checker.ps1`

## 3) 正常案例 low_add_nullable_column 是否正確 PASS

- `low_add_nullable_column.proposal.json`
  - declaredRiskLevel: `Low`
  - calculatedRiskLevel: `Low`
  - riskUnderestimated: `false`
  - autoExecutable: `true`
  - Result: PASS (no underestimation false positive)

## 4) 4 個 underestimation 案例是否全部 riskUnderestimated=true

- YES. All 4 underestimation cases returned `riskUnderestimated=true`.

## 5) 每個 underestimation 案例的 declaredRiskLevel

- `underestimated_alter_column_as_low.proposal.json`: `Low`
- `underestimated_drop_table_as_low.proposal.json`: `Low`
- `underestimated_update_all_tenants_as_medium.proposal.json`: `Medium`
- `underestimated_unclear_tenant_scope_as_low.proposal.json`: `Low`

## 6) 每個 underestimation 案例的 calculatedRiskLevel

- `underestimated_alter_column_as_low.proposal.json`: `High`
- `underestimated_drop_table_as_low.proposal.json`: `Critical`
- `underestimated_update_all_tenants_as_medium.proposal.json`: `Critical`
- `underestimated_unclear_tenant_scope_as_low.proposal.json`: `High`

## 7) 每個 underestimation 案例的 autoExecutable

- `underestimated_alter_column_as_low.proposal.json`: `false`
- `underestimated_drop_table_as_low.proposal.json`: `false`
- `underestimated_update_all_tenants_as_medium.proposal.json`: `false`
- `underestimated_unclear_tenant_scope_as_low.proposal.json`: `false`

## 8) 每個 underestimation 案例的 riskWarning

- `underestimated_alter_column_as_low.proposal.json`: `Declared riskLevel is lower than calculatedRiskLevel.`
- `underestimated_drop_table_as_low.proposal.json`: `Declared riskLevel is lower than calculatedRiskLevel.`
- `underestimated_update_all_tenants_as_medium.proposal.json`: `Declared riskLevel is lower than calculatedRiskLevel.`
- `underestimated_unclear_tenant_scope_as_low.proposal.json`: `Declared riskLevel is lower than calculatedRiskLevel.`

## 9) 預期與實際是否一致

- YES. Expected and actual outputs match for all 4 underestimation cases and 1 normal case.

## 10) 是否確認沒有執行 SQL

- YES. No SQL was executed.

## 11) 是否確認沒有連 SQL Server

- YES. No SQL Server connection was made.

## 12) 是否確認沒有修改正式 DB

- YES. No production DB changes were made.

## 13) 是否確認沒有修改 `.env`

- YES. `.env` was not modified.

## 14) 結論

This is Step 2-D risk_checker underestimation dry-run validation only. No SQL was executed.
