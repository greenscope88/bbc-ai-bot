# SQL Safe Migration 5.0 - Step 2-B Checker Dry-Run Report

## 1) 測試時間

- Report Time: 2026-05-09 15:18:31 +08:00
- proposal_checker Test Time: 2026-05-09 15:15:14 +08:00
- risk_checker Test Time: 2026-05-09 15:16:40 +08:00

## 2) 測試範圍

- Step 2-B dry-run validation for proposal structure and risk classification
- Proposal files under `db/migrations/v5/tests/proposals/`
- 8 proposals for `proposal_checker.ps1`
- 7 valid proposals for `risk_checker.ps1` (invalid proposal excluded)

## 3) 測試工具

- `proposal_checker.ps1`
- `risk_checker.ps1`

## 4) 每個 proposal 的 proposal_checker 結果

| Proposal | proposal_checker Expected | proposal_checker Actual | Match |
| --- | --- | --- | --- |
| low_add_nullable_column | PASS | PASS | YES |
| medium_add_not_null_column | PASS | PASS | YES |
| high_alter_column | PASS | PASS | YES |
| high_drop_column | PASS | PASS | YES |
| critical_drop_table | PASS | PASS | YES |
| critical_update_without_sno | PASS | PASS | YES |
| invalid_missing_required_fields | FAIL (missing required fields) | FAIL (missing: `database`, `table`, `action`, `dataType`, `riskLevel`, `generatedBy`) | YES |
| high_unclear_tenant_scope | PASS | PASS | YES |

## 5) 每個 proposal 的 risk_checker 結果

| Proposal | risk_checker Expected | risk_checker Actual (`calculatedRiskLevel`) | `autoExecutable` | Match |
| --- | --- | --- | --- | --- |
| low_add_nullable_column | Low | Low | true | YES |
| medium_add_not_null_column | Medium | Medium | true | YES |
| high_alter_column | High | High | false | YES |
| high_drop_column | High | High | false | YES |
| critical_drop_table | Critical | Critical | false | YES |
| critical_update_without_sno | Critical | Critical | false | YES |
| high_unclear_tenant_scope | at least High | High | false | YES |

## 6) 預期結果與實際結果是否一致

- proposal_checker: all 8 cases matched expected results.
- risk_checker: all 7 valid cases matched expected results.

## 7) High / Critical 是否皆 `autoExecutable=false`

- YES. All High/Critical cases are `autoExecutable=false`.

## 8) invalid_missing_required_fields 是否正確 FAIL

- YES. `invalid_missing_required_fields` correctly failed in `proposal_checker` with missing fields:
  - `database`
  - `table`
  - `action`
  - `dataType`
  - `riskLevel`
  - `generatedBy`
- This invalid proposal was intercepted by `proposal_checker` and did not enter normal `risk_checker` flow.

## 9) 是否確認沒有執行 SQL

- YES. No SQL execution was performed.

## 10) 是否確認沒有連 SQL Server

- YES. No SQL Server connection was made.

## 11) 是否確認沒有修改正式 DB

- YES. No production DB changes were made.

## 12) 是否確認沒有修改 `.env`

- YES. `.env` was not modified.

## 13) 結論

This is Step 2-B dry-run validation only. No SQL was executed.
