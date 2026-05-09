# SQL Safe Migration 5.0 - Step 2-C-3 Proposal Checker Invalid Value Report

## 1) 測試時間

- Test Time: 2026-05-09 15:31:52 +08:00

## 2) 測試工具

- `proposal_checker.ps1`

## 3) 有效案例 low_add_nullable_column 是否 PASS

- `low_add_nullable_column.proposal.json`: PASS
- Actual Output: `PASS: Proposal validation passed.`

## 4) 6 個 invalid value 案例是否全部 FAIL

- YES. All 6 invalid value cases returned FAIL.

## 5) 每個 invalid 案例的預期錯誤

- `invalid_environment.proposal.json`: `environment` invalid
- `invalid_action.proposal.json`: `action` invalid
- `invalid_risk_level.proposal.json`: `riskLevel` invalid
- `invalid_boolean_type.proposal.json`: `nullable` / `snoRequired` / `requiresApproval` / `rollbackPlanRequired` type invalid
- `invalid_affectedSystems_type.proposal.json`: `affectedSystems` must be array
- `prod_requiresApproval_false.proposal.json`: PROD requires `requiresApproval=true`

## 6) 每個 invalid 案例的實際錯誤

- `invalid_environment.proposal.json`: `environment: environment invalid (allowed: DEV, TEST, PROD)`
- `invalid_action.proposal.json`: `action: action invalid (not in whitelist)`
- `invalid_risk_level.proposal.json`: `riskLevel: riskLevel invalid (allowed: Low, Medium, High, Critical)`
- `invalid_boolean_type.proposal.json`:
  - `nullable: must be boolean`
  - `snoRequired: must be boolean`
  - `requiresApproval: must be boolean`
  - `rollbackPlanRequired: must be boolean`
- `invalid_affectedSystems_type.proposal.json`: `affectedSystems: must be array`
- `prod_requiresApproval_false.proposal.json`: `requiresApproval: must be true when environment is PROD`

## 7) 預期與實際是否一致

- YES. Expected and actual results are consistent for all 6 invalid cases and the 1 valid case.

## 8) 是否確認沒有執行 SQL

- YES. No SQL was executed.

## 9) 是否確認沒有連 SQL Server

- YES. No SQL Server connection was made.

## 10) 是否確認沒有修改正式 DB

- YES. No production DB changes were made.

## 11) 是否確認沒有修改 `.env`

- YES. `.env` was not modified.

## 12) 結論

This is Step 2-C proposal_checker invalid value dry-run validation only. No SQL was executed.
