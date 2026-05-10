# SQL Safe Migration 5.0 - Step 2-H Approval / Hash Guard Report

## 1) 測試時間

- Test Time: 2026-05-10 15:52:53 +08:00

## 2) 測試工具

- `db/migrations/v5/scripts/approval_hash_guard.ps1`

## 3) Low 有效案例是否 PASS

- **YES.** `low_add_nullable_column.proposal.json` with `step2g_preflight_low_report.md` returned `status = PASS`.
- `proposalHash` and `preflightReportHash` are non-empty (SHA256 hex).

## 4) Critical 無 approvalCode 是否 FAIL

- **YES.** `critical_drop_table.proposal.json` with `step2g_preflight_critical_drop_table_report.md` returned `status = FAIL`.
- `warnings` includes `high risk proposal requires approvalCode` (Critical is treated under high-risk approval rules).
- Both hashes are non-empty.

## 5) 5 個 invalid approval 案例是否全部 FAIL

- **YES.** All five invalid approval proposals (with shared `step2g_preflight_low_report.md`) returned `status = FAIL`.

## 6) 每個 invalid approval 案例的預期 warning

| Proposal | Expected warning |
|----------|------------------|
| `high_missing_approval_code.proposal.json` | `high risk proposal requires approvalCode` |
| `prod_missing_approval_code.proposal.json` | `PROD proposal requires approvalCode` |
| `invalid_approval_code_format.proposal.json` | `approvalCode format invalid` |
| `high_rollback_false.proposal.json` | `high risk proposal requires rollback plan` |
| `requiresApproval_not_boolean.proposal.json` | `requiresApproval must be boolean` |

## 7) 每個 invalid approval 案例的實際 warning

| Proposal | Actual warnings |
|----------|-----------------|
| `high_missing_approval_code.proposal.json` | `high risk proposal requires approvalCode` |
| `prod_missing_approval_code.proposal.json` | `PROD proposal requires approvalCode` |
| `invalid_approval_code_format.proposal.json` | `approvalCode format invalid` |
| `high_rollback_false.proposal.json` | `high risk proposal requires rollback plan` |
| `requiresApproval_not_boolean.proposal.json` | `requiresApproval must be boolean` |

## 8) 每個案例是否都有 proposalHash

- **YES** for all seven runs (Low, Critical, and five invalid cases). Each output included a non-empty `proposalHash` (SHA256).

## 9) 每個案例是否都有 preflightReportHash

- **YES** for all seven runs. Each output included a non-empty `preflightReportHash` (SHA256).

## 10) 預期與實際是否一致

- **YES.** Expected `status` and warning substrings match for every case.

## 11) 是否確認沒有執行 SQL

- **YES.** Only `approval_hash_guard.ps1` was run against local files.

## 12) 是否確認沒有連 SQL Server

- **YES.** No SQL Server connection was made.

## 13) 是否確認沒有修改正式 DB

- **YES.** No production database changes.

## 14) 是否確認沒有修改 `.env`

- **YES.** `.env` was not modified.

## 15) 是否確認沒有讀取 `.env`

- **YES.** The guard script does not read `.env`; this validation did not load `.env`.

## 16) 是否確認沒有 connection string

- **YES.** No connection strings were introduced or used in this step.

## 17) 結論

This is Step 2-H Approval / Hash Guard plan-only validation. No SQL Server connection was made. No SQL was executed.
