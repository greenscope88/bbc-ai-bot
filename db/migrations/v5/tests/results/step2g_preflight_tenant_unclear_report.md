# Step 2-G Preflight Orchestrator Report

## 1) Test Time

- 2026-05-10 15:30:46 +08:00

## 2) ProposalPath

- `C:\bbc-ai-bot\db\migrations\v5\tests\proposals\high_unclear_tenant_scope.proposal.json`

## 3) Checkers Executed

- proposal_checker.ps1
- risk_checker.ps1
- db_connection_guard.ps1
- tenant_sno_checker.ps1

## 4) proposal_checker Result

- Exit code: 0
- Status: PASS

```
PASS: Proposal validation passed.
```

## 5) risk_checker Result

- declaredRiskLevel: `High`
- calculatedRiskLevel: `High`
- riskUnderestimated: `False`
- autoExecutable: `False`
- riskWarning: ``

```json
{
    "requestId":  "DBCR-TEST-HIGH-003",
    "action":  "ADD_COLUMN",
    "declaredRiskLevel":  "High",
    "calculatedRiskLevel":  "High",
    "riskUnderestimated":  false,
    "autoExecutable":  false,
    "riskWarning":  "",
    "reason":  "ADD_COLUMN with nullable=true defaults to Low. snoRequired=true with unclear tenantScope raises risk to at least High. Core systems with nullable ADD_COLUMN can remain Low. High is not auto-executable."
}
```

## 6) db_connection_guard Result

```json
{
    "requestId":  "DBCR-TEST-HIGH-003",
    "environment":  "DEV",
    "server":  "HostB-SQLServer",
    "database":  "Buysmart",
    "requiresApproval":  true,
    "status":  "PASS",
    "warnings":  [

                 ],
    "conclusion":  "DB Connection Guard plan-only check passed. No SQL Server connection was made."
}
```

## 7) tenant_sno_checker Result

```json
{
    "requestId":  "DBCR-TEST-HIGH-003",
    "environment":  "DEV",
    "action":  "ADD_COLUMN",
    "tenantScope":  "unclear",
    "snoRequired":  true,
    "affectedSystems":  [
                            "API",
                            "AI Query"
                        ],
    "status":  "FAIL",
    "warnings":  [
                     "tenantScope unclear requires manual review",
                     "tenant scope must be clear when affected systems include API or legacy systems"
                 ],
    "conclusion":  "Tenant / sno plan-only check failed. No SQL Server connection was made. No SQL was executed."
}
```

## 8) finalStatus

- `FAIL`

## 9) blockingReasons

- tenant_sno_checker failed
- high or critical risk requires manual governance
- autoExecutable is false

## 10) Conclusion

This is Step 2-G Preflight Orchestrator plan-only validation. No SQL Server connection was made. No SQL was executed.
