# Tenant Mapping Phase 2A Stage 6-6
## travel_b Staging Completion Report

## 1) Purpose
This report documents the completion status of `travel_b` staging onboarding in Phase 2A Stage 6, confirms current fail-closed safety boundaries, and records what must remain off before Stage 7.

## 2) travel_b Onboarding Background
- Target tenant: `travel_b` (staging onboarding)
- LINE destination: `Uc29debdbf97e5e3aa050a6f54cf32091`
- Registry key: `travel_b`
- Scope completed in Stage 6:
  - webhook verify path validated
  - tenant mapping resolved via registry
  - multi-line credential resolver integrated
  - tenant credential isolation validated
  - hello shortcut credential bootstrap fix applied
  - Gemini general reply path verified with LINE `200`
  - direct `你好` shortcut reply verified with LINE `200`

## 3) Tenant Mapping Architecture
Current routing uses config-first tenant resolution:
- Source of truth: `config/tenant_registry.php`
- Channel ID -> tenant key resolution via registry bridge
- Runtime resolves tenant context before per-tenant behavior decisions
- If registry miss occurs, flow remains fail-closed and does not silently enable features

Design outcome:
- `travel_b` is explicitly bound to its own channel and tenant key
- Tenant isolation avoids cross-tenant credential bleed-through

## 4) Multi-line Credential Resolver Architecture
Credential resolution is tenant-scoped and environment-keyed:
- Resolver derives per-tenant key suffix from registry (`credential_env_prefix`)
- Required pair:
  - `LINE_CHANNEL_SECRET__travel_b`
  - `LINE_CHANNEL_ACCESS_TOKEN__travel_b`
- Resolver returns structured status:
  - `missing_keys`
  - `errorCode`
  - tenant identity metadata

Safety behavior:
- Missing credentials => fail-closed (no unsafe fallback to another tenant credential)
- Valid credentials => normal LINE reply path

## 5) Hello Shortcut Fix: Root Cause and Solution
Issue:
- `safe_gateway_direct_hello` path attempted credential resolution before config bootstrap, causing false `MISSING_CREDENTIALS` for `travel_b`.

Root cause:
- `app_config()` not guaranteed before direct hello shortcut resolver execution.

Fix:
- Add config bootstrap guard in `core/safe_gateway.php`:
  - ensure `app_config()` is called before direct hello credential resolve.

Result:
- Direct `你好` shortcut now resolves `travel_b` credentials correctly and replies successfully.

## 6) Smoke Test Results
Stage 6 smoke verification summary:
- `你好` (direct hello shortcut): success after fix, LINE reply `200`
- General travel queries: success in staging path with expected routing
- Credential resolver status for successful path:
  - `tenant_key=travel_b`
  - `missing_keys=[]`
  - `errorCode=null`

## 7) Successful Validation Items
Validated items in current staging baseline:
- destination matches `Uc29debdbf97e5e3aa050a6f54cf32091`
- tenant resolution confirms `travel_b`
- credential resolver confirms no missing keys
- LINE API response status `200`
- direct hello shortcut and standard routing both produce valid replies
- no unintended fallback to `travel_a` on successful travel_b hello flow

## 8) Features Still OFF
`travel_b` remains staging-locked with feature flags OFF:
- `tour_prompt`: OFF
- `hybrid_search`: OFF
- `fixed_formatter`: OFF
- any production-only expansion behavior: OFF

## 9) Host B / Hybrid Status
Current status for Stage 6:
- Host B runtime integration: NOT enabled for travel_b
- Hybrid runtime behavior: NOT enabled for travel_b
- No Stage 6 requirement to activate Host B or Hybrid execution paths

Risk control:
- Keep host-specific expansions disabled until Stage 7 readiness gates pass.

## 10) GCS / Google Drive Future Plan (Architecture Reserved Only)
Future architecture placeholder (not active in Stage 6):
- External artifact storage abstraction for prompts/reports/assets
- Tenant-aware storage namespace isolation
- Read/write policy by environment (staging vs production)
- Optional asynchronous export pipeline with audit trail

No GCS/Drive runtime wiring is enabled in this stage.

## 11) Current Safety Posture
System is currently in safe staging posture:
- fail-closed credential checks preserved
- tenant isolation preserved
- no feature auto-enable side effects
- no runtime expansion to Host B / Hybrid for travel_b
- baseline branch synchronized at the approved commit

## 12) Stage 7 Recommended Sequence
Recommended Stage 7 rollout order:
1. Freeze and review current staging logs and tenant isolation assumptions
2. Define explicit Stage 7 acceptance criteria per feature gate
3. Enable one feature at a time behind tenant-scoped flag
4. Run smoke + regression after each gate change
5. Validate no cross-tenant fallback in every step
6. Prepare rollback switch and verification checklist before production promotion

## 13) Rollback and Fail-Closed Principles
Principles to keep:
- Fail-closed first: missing or ambiguous credentials must block send path safely
- No implicit fallback to other tenant credentials
- Rollback by commit-level reversion only, no ad hoc runtime rewiring
- Keep tenant registry as single mapping source during rollback checks
- Any abnormal response trend => immediate feature gate OFF and staged rollback

## 14) Git Baseline Information
- Branch: `feature/api-gateway-mvp`
- Baseline HEAD: `5e0cf10cad9738ba7cc47f749d4667c1eae1d832`
- Commit message: `fix(tenant): load config before direct hello credential resolve`
- Remote sync status at last push: aligned with `origin/feature/api-gateway-mvp`

---
Report generated for:
- Host A: `103.1.222.14`
- Workspace: `C:\bbc-ai-bot`
- Phase: Tenant Mapping Phase 2A Stage 6-6
