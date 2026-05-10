# Step 2-G Preflight Orchestrator Report

## 1) Test Time

- 2026-05-10 15:30:46 +08:00

## 2) ProposalPath

- `C:\bbc-ai-bot\db\migrations\v5\tests\proposals\invalid_missing_required_fields.proposal.json`

## 3) Checkers Executed

- proposal_checker.ps1
- risk_checker.ps1
- db_connection_guard.ps1
- tenant_sno_checker.ps1

## 4) proposal_checker Result

- Exit code: 1
- Status: FAIL

```
FAIL: Proposal validation failed.
Missing fields:
- database
- table
- action
- dataType
- riskLevel
- generatedBy
Invalid fields:
- (none)
```

## 5) risk_checker Result

- declaredRiskLevel: ``
- calculatedRiskLevel: `Medium`
- riskUnderestimated: `True`
- autoExecutable: `True`
- riskWarning: `Declared riskLevel is lower than calculatedRiskLevel.`

```json
{
    "requestId":  "DBCR-TEST-INVALID-001",
    "action":  "",
    "declaredRiskLevel":  "",
    "calculatedRiskLevel":  "Medium",
    "riskUnderestimated":  true,
    "autoExecutable":  true,
    "riskWarning":  "Declared riskLevel is lower than calculatedRiskLevel.",
    "reason":  "Unknown action defaults to Medium. Medium may be auto-executable by current rule set. Declared riskLevel is lower than calculatedRiskLevel."
}
```

## 6) db_connection_guard Result

```json
{
    "requestId":  "DBCR-TEST-INVALID-001",
    "environment":  "DEV",
    "server":  "HostB-SQLServer",
    "database":  "",
    "requiresApproval":  true,
    "status":  "FAIL",
    "warnings":  [
                     "database is required"
                 ],
    "conclusion":  "DB Connection Guard plan-only check failed. No SQL Server connection was made."
}
```

## 7) tenant_sno_checker Result

```json
{
    "requestId":  "DBCR-TEST-INVALID-001",
    "environment":  "DEV",
    "action":  "",
    "tenantScope":  "single_or_multi_tenant",
    "snoRequired":  true,
    "affectedSystems":  [
                            "API"
                        ],
    "status":  "PASS",
    "warnings":  [

                 ],
    "conclusion":  "Tenant / sno plan-only check passed. No SQL Server connection was made. No SQL was executed."
}
```

## 8) finalStatus

- `FAIL`

## 9) blockingReasons

- proposal_checker failed
- db_connection_guard failed
- risk underestimated

## 10) Conclusion

This is Step 2-G Preflight Orchestrator plan-only validation. No SQL Server connection was made. No SQL was executed.
