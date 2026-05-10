# SQL Safe Migration 5.0 - Step 2-F Tenant / sno Checker Report

## 1) 測試時間

- Test Time: 2026-05-10 15:21:13 +08:00

## 2) 測試工具

- `db/migrations/v5/scripts/tenant_sno_checker.ps1`

## 3) 有效案例 low_add_nullable_column 是否 PASS

- **YES.** `low_add_nullable_column.proposal.json` returned `status = PASS`.
- `conclusion` contains `No SQL Server connection was made. No SQL was executed.`

## 4) 既有風險案例 high_unclear_tenant_scope 是否 FAIL

- **YES.** `status = FAIL`.
- `warnings` includes `tenantScope unclear requires manual review` (also includes `tenant scope must be clear when affected systems include API or legacy systems`).

## 5) 既有風險案例 critical_update_without_sno 是否 FAIL

- **YES.** `status = FAIL`.
- `warnings` includes `all_tenants with high-risk action is not allowed in plan-only validation`.

## 6) 5 個 invalid tenant 案例是否全部 FAIL

- **YES.** All five invalid tenant proposals returned `status = FAIL`.

## 7) 每個 invalid tenant 案例的預期 warning

| Proposal | Expected warning(s) |
|----------|---------------------|
| `invalid_tenant_scope.proposal.json` | `tenantScope invalid` |
| `snoRequired_not_boolean.proposal.json` | `snoRequired must be boolean` |
| `prod_all_tenants.proposal.json` | `PROD proposal must use explicit tenant scope` |
| `snoRequired_false_update.proposal.json` | `data-changing action requires sno guard` |
| `unclear_with_api.proposal.json` | `tenantScope unclear requires manual review`; `tenant scope must be clear when affected systems include API or legacy systems` |

## 8) 每個 invalid tenant 案例的實際 warning

| Proposal | Actual warnings |
|----------|-----------------|
| `invalid_tenant_scope.proposal.json` | `tenantScope invalid` |
| `snoRequired_not_boolean.proposal.json` | `snoRequired must be boolean` |
| `prod_all_tenants.proposal.json` | `PROD proposal must use explicit tenant scope` |
| `snoRequired_false_update.proposal.json` | `data-changing action requires sno guard` |
| `unclear_with_api.proposal.json` | `tenantScope unclear requires manual review`; `tenant scope must be clear when affected systems include API or legacy systems` |

## 9) 預期與實際是否一致

- **YES.** All expected `status` values and warning substrings match; `high_unclear_tenant_scope` has an extra warning beyond the minimum expected, which is consistent with checker rules.

## 10) 是否確認沒有執行 SQL

- **YES.** Only `tenant_sno_checker.ps1` was run against local JSON files.

## 11) 是否確認沒有連 SQL Server

- **YES.** No SQL Server connection was made.

## 12) 是否確認沒有修改正式 DB

- **YES.** No production database changes.

## 13) 是否確認沒有修改 `.env`

- **YES.** `.env` was not modified.

## 14) 是否確認沒有讀取 `.env`

- **YES.** The checker script does not read `.env`; this validation did not load `.env`.

## 15) 是否確認沒有 connection string

- **YES.** No connection strings were introduced or used in this step.

## 16) 結論

This is Step 2-F Tenant / sno Checker plan-only validation. No SQL Server connection was made. No SQL was executed.
