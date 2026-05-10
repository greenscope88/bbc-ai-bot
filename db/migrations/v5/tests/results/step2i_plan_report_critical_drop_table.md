# Plan Report (SQL Safe Migration 5.0)

## 1) Report Metadata

- generatedAt: 2026-05-10T16:04:57+08:00
- proposalPath: `C:\bbc-ai-bot\db\migrations\v5\tests\proposals\critical_drop_table.proposal.json`
- preflightReportPath: `C:\bbc-ai-bot\db\migrations\v5\tests\results\step2g_preflight_critical_drop_table_report.md`
- approvalHashResultPath: `Approval / Hash result not provided.`
- generatorMode: Plan Mode / Dry-run

## 2) Proposal Summary

- requestId: `DBCR-TEST-CRITICAL-001`
- environment: `DEV`
- server: `HostB-SQLServer`
- database: `Buysmart`
- table: `Member`
- action: `DROP_TABLE`
- column: ``
- dataType: ``
- nullable: ``
- tenantScope: `all_tenants`
- snoRequired: `False`
- affectedSystems: Old ASP Frontend, Old ASP Backend, API, AI Query
- reason: Test critical risk DROP_TABLE

## 3) Declared Risk

- riskLevel: `Critical`
- requiresApproval: `True`
- approvalCode: **missing**
- rollbackPlanRequired: `True`

## 4) Checker Integration Summary

- proposal_checker status: `PASS`
- risk_checker calculatedRiskLevel: `Critical`
- risk_checker riskUnderestimated: `False`
- risk_checker autoExecutable: `False`
- db_connection_guard status: `PASS`
- tenant_sno_checker status: `FAIL`
- preflight finalStatus: `FAIL`
- preflight blockingReasons:

- tenant_sno_checker failed
- high or critical risk requires manual governance
- autoExecutable is false

- approval_hash_guard status: `FAIL`
- approval_hash_guard warnings:

- high risk proposal requires approvalCode

## 5) Hash Summary

- proposalHash: `6C6F5A65CC5B5FF4CF6C5588D2275EE7AD3233F34F2C9BC542A3CC24EED8F1D5`
- preflightReportHash: `27FC11889E88FA89A21DD48A1E3983C2B5A7CA390EC4B27920F2E7A5139E93A7`

## 6) Safety Warnings

- Preflight finalStatus is not PASS (FAIL).
- autoExecutable is false; manual governance required.
- approval_hash_guard status is FAIL.
- approval_hash_guard: high risk proposal requires approvalCode
- approvalCode is missing but High/Critical/PROD rules require an approval code.

## 7) Required Artifacts

- DB Change Request
- proposal JSON
- Plan Report
- Preflight Report
- Approval / Hash result
- .bak backup before execution
- before schema-only.sql
- after schema-only.sql
- schema diff report
- rollback plan
- audit log

## 8) Final Conclusion

- **PLAN_FAIL**

## 9) Explicit safety statement

This is Plan Mode only. No SQL Server connection was made. No SQL was executed. No production database was modified.
