# SQL Safe Migration 5.0 - Step 2-B-3 Risk Checker Results

- Test Time: 2026-05-09 15:16:40 +08:00
- Scope: `risk_checker.ps1` dry-run validation for 7 valid proposal JSON files
- Confirmation: This is risk_checker dry-run only. No SQL was executed.

## Result Summary

| Proposal | Expected calculatedRiskLevel | Actual calculatedRiskLevel | autoExecutable | Match |
| --- | --- | --- | --- | --- |
| low_add_nullable_column | Low | Low | true | YES |
| medium_add_not_null_column | Medium | Medium | true | YES |
| high_alter_column | High | High | false | YES |
| high_drop_column | High | High | false | YES |
| critical_drop_table | Critical | Critical | false | YES |
| critical_update_without_sno | Critical | Critical | false | YES |
| high_unclear_tenant_scope | at least High | High | false | YES |

## Rule Notes

- `medium_add_not_null_column`: Current rule marks `ADD_COLUMN` with `nullable=false` as `Medium`; `autoExecutable` remains `true` because only `High` and `Critical` are blocked by default.
- High/Critical auto-execution guard: All `High` and `Critical` cases in this run are `autoExecutable=false`.
- Invalid proposal flow: `invalid_missing_required_fields.proposal.json` was intercepted by `proposal_checker` in Step 2-B-2 and did not enter normal `risk_checker` flow.

## Raw Risk Checker Results

- low_add_nullable_column: `calculatedRiskLevel=Low`, `autoExecutable=true`
- medium_add_not_null_column: `calculatedRiskLevel=Medium`, `autoExecutable=true`
- high_alter_column: `calculatedRiskLevel=High`, `autoExecutable=false`
- high_drop_column: `calculatedRiskLevel=High`, `autoExecutable=false`
- critical_drop_table: `calculatedRiskLevel=Critical`, `autoExecutable=false`
- critical_update_without_sno: `calculatedRiskLevel=Critical`, `autoExecutable=false`
- high_unclear_tenant_scope: `calculatedRiskLevel=High`, `autoExecutable=false`
