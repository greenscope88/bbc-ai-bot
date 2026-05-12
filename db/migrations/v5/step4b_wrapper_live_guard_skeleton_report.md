# SQL Safe Migration 5.0 — Phase 5 Step 4-B  
## Governed Migration Wrapper — LIVE_EXECUTE Guard Skeleton

**Date:** 2026-05-12  
**Scope:** `invoke_governed_migration.ps1` LIVE_EXECUTE pre-flight chain and skeleton terminal outcome (no production SQL execution).

---

### 1. Modified files (this step / related tests)

| File | Notes |
|------|--------|
| `db/migrations/v5/invoke_governed_migration.ps1` | Added LIVE_EXECUTE guard chain; parameters `-ContractInputPath`, `-EnableLiveExecution`, `-FinalManualConfirm`; child invocation via `Start-Process` + `Wait-Process` (no stdout/stderr file redirect — avoids hang with `report_generator.ps1`); `-NonInteractive` on child `powershell.exe`; terminal skeleton outcome still rejects live execution. |
| `db/migrations/v5/tests/activation_test_suite.ps1` | Registers `test_governed_wrapper_live_guard_skeleton.ps1`. |
| `db/migrations/v5/tests/test_invoke_governed_migration.ps1` | Adjusted expectations for LIVE_EXECUTE messaging where applicable. |
| `db/migrations/v5/tests/test_approval_gate_phase5_contract.ps1` | Aligns gate expectations with contract-driven LIVE path. |
| `db/migrations/v5/approval_gate.ps1` | Contract path validation allows LIVE_EXECUTE with `enableLiveExecution: true` to pass the gate when other contract checks succeed (required for wrapper step 7). |

---

### 2. New files

| File | Notes |
|------|--------|
| `db/migrations/v5/tests/test_governed_wrapper_live_guard_skeleton.ps1` | Eight scenarios covering LIVE failures, skeleton terminal failure, and MOCK/DRY_RUN regression. |

---

### 3. LIVE_EXECUTE guard order (wrapper)

When Phase 4 payload `mode` is `LIVE_EXECUTE`, the wrapper enforces, in order:

1. `-ContractInputPath` is present.  
2. Contract file exists and JSON parses.  
3. `contract.mode` is `LIVE_EXECUTE`.  
4. `contract.environment` is `PRODUCTION`.  
5. Caller passed `-EnableLiveExecution` (switch present and true).  
6. `contract.enableLiveExecution` is `true`.  
7. `approval_gate.ps1 -ContractInputPath` exits `0`.  
8. `maintenance_window_validator.ps1 -ContractInputPath` exits `0`.  
9. `contract.recoveryReadinessChecker` is present with `backupPath`, `schemaSnapshotPath`, `restoreGuidePath`, `recoveryMode`; `recovery_readiness_checker.ps1` is invoked with those paths and exits `0`.  
10. `contract.finalSignOff.approved` is `true`.  
11. `-FinalManualConfirm` equals exactly: `I_UNDERSTAND_THIS_IS_PRODUCTION_LIVE_EXECUTION`.  
12. Audit pre-report: writes `report_generator_pre_live.json` under `OutputDir` and runs `report_generator.ps1` (still no SQL).  
13. **Skeleton terminal outcome:** `FailLiveSkeletonPassed` — JSON with `pass=false`, `executed=false`, `liveExecutionEnabled=false`, and `reason` exactly:  
   `LIVE_EXECUTE skeleton guard passed, but production execution is not enabled in Phase 5 Step 4-B`

---

### 4. Why this phase still does not enable SQL execution

Step 4-B is intentionally a **skeleton**: it proves the governance chain can be wired and audited (including `report_generator`) without introducing any branch that invokes SQL Server or migration SQL. Even when every guard passes, the wrapper **must** exit with `executed=false` and the fixed skeleton `reason` so operators cannot mistake this build for production execution.

---

### 5. Test results (local run)

| Command | Result |
|---------|--------|
| `tests/test_governed_wrapper_live_guard_skeleton.ps1` | **PASS** |
| `tests/activation_test_suite.ps1` | **PASS** |

---

### 6. MOCK / DRY_RUN

Both paths remain **PASS** (unchanged success semantics; `executed=false` for MOCK as before).

---

### 7. LIVE_EXECUTE `executed`

All LIVE_EXECUTE outcomes from this wrapper remain **`executed: false`**, including the “all guards passed” skeleton case (step 13).

---

### 8. SQL execution

**No SQL** was executed as part of this work. The wrapper and child scripts (`approval_gate`, `maintenance_window_validator`, `recovery_readiness_checker`, `report_generator`) are documented or implemented to avoid SQL execution in this phase.

---

### 9. Git add / commit / push

**Not performed** (read-only `git status` used only for inventory).

---

### 10. Suggested next steps

1. **Phase 5 Step 4-C (or later):** introduce an explicit, separately gated `LIVE_EXECUTE` execution module that is **disabled by default** and only callable outside this skeleton path after CAB + infra sign-off.  
2. Consider **structured logging** (file) for child exit codes and durations without capturing full stdout (avoids redirect-related hangs).  
3. Extend contract schema/tests for **`recoveryReadinessChecker`** edge cases (missing files, non-`PASS` recovery script outcomes).  
4. When enabling real execution in a future phase, replace `FailLiveSkeletonPassed` with a **narrow** call into a hardened executor that enforces connection string allowlists and rollback hooks.
