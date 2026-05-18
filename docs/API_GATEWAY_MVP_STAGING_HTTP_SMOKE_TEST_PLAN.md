# API Gateway MVP — Staging HTTP Smoke Test Plan

**Project root:** `C:\bbc-ai-bot`  
**Document version:** 2026-05-16  
**Type:** Staging smoke test procedure (**documentation only**).

**Revision 2026-05-15:** Expected Host B request shape updated for **confirmed Host B** rules: **`x-api-key`** (internal Host B key), **query includes `sno`**, **no** `depID` / `storeNo` / `store_uid` / `provider_id_no` on Host B query; **`tenantContext` Host A–only**; real smoke **must not** use dry-run placeholder internal IDs on Host B query.

**Revision 2026-05-16:** Formalized **`GATEWAY_HOSTB_API_KEY`**, mandatory trio for real smoke (**`GATEWAY_HOSTB_BASE_URL`** + **`GATEWAY_HOSTB_API_KEY`** + **`GATEWAY_HOSTB_HTTP_ENABLED=true`**), **NO-GO** if internal key missing, **no full key in logs/audit**, and **production** remains **`GATEWAY_HOSTB_HTTP_ENABLED=false`** unless separately approved.

**Related:** `docs/API_GATEWAY_MVP_STAGING_HTTP_ACTIVATION_PLAN.md`, `docs/API_GATEWAY_MVP_DRY_RUN_TEST_SUITE.md`, `docs/API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md`, `docs/API_GATEWAY_MVP_LAUNCH_PLAN.md`.

---

## 1. Document Purpose

This document specifies a **minimal staging smoke test** for the **first real HTTP** call from Host A to Host B for **`tour.search`**. It is **planning only**: authoring this file **does not** modify code, **does not** change `.env`, **does not** send HTTP, and **does not** run SQL.

**Scope statement:**

- This document **only** plans a **staging** smoke test.  
- It **does not** mean production may enable Host B HTTP.  
- **Production must keep `GATEWAY_HOSTB_HTTP_ENABLED=false`** until a separate production go-live decision and document exist.

---

## 2. Current Status

| Area | Expected state before smoke |
|------|------------------------------|
| **MVP dry-run** | Implemented; used as default when HTTP is off. |
| **MVP Dry-Run Suite** | Must pass via `run_mvp_dry_run_suite.ps1` immediately before any staging HTTP attempt. |
| **Production** | **`GATEWAY_HOSTB_HTTP_ENABLED=false`** — unchanged by this plan. |
| **Staging** | HTTP may be enabled **only** after preconditions, flags, and manual approval per activation plan. |

---

## 3. Smoke Test Objective

Prove **one** successful end-to-end path on **staging**:

**Host A (gateway)** → **real HTTP** → **Host B** `GET /api/tour/search` (allowlisted search params + **authoritative `sno`** in query for TenantResolver + **internal `x-api-key` header**) → **valid JSON response** suitable for downstream handling — **without** widening scope beyond **`tour.search`**.

**Not in scope for Host B query:** Internal `tenantContext` fields (`depID`, `storeNo`, `store_uid`, `provider_id_no`) — they remain on Host A for validation, audit, and trace only (see `docs/API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md`).

---

## 4. Required Preconditions

- [ ] **Dry-run suite green:**  
  `powershell -ExecutionPolicy Bypass -File C:\bbc-ai-bot\tests\api_gateway\run_mvp_dry_run_suite.ps1` → **exit code 0** and **`API Gateway MVP Dry-Run Suite passed.`**  
- [ ] **Activation plan satisfied:** Preconditions and approval from `docs/API_GATEWAY_MVP_STAGING_HTTP_ACTIVATION_PLAN.md`.  
- [ ] **Contract aligned:** `docs/API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md` reflects Host B rules: **`x-api-key` / `X-API-Key`**, internal key via **`GATEWAY_HOSTB_API_KEY`** (secret / env — **never** hardcoded), **`sno` on query**, **no** internal tenant fields on Host B query, **no** `X-BBC-API-Key` to Host B, **no** full key in logs/audit.  
- [ ] **Staging secrets present:** **`GATEWAY_HOSTB_BASE_URL`**, **`GATEWAY_HOSTB_API_KEY`** (non-empty, real staging value from secret store or approved env injection), and **`GATEWAY_HOSTB_HTTP_ENABLED=true`** **only** on the **staging** profile for the smoke window. **If `GATEWAY_HOSTB_API_KEY` is missing or empty → smoke is NO-GO** (do not proceed; do not substitute `X-BBC-API-Key`).  
- [ ] **Production unchanged:** **`GATEWAY_HOSTB_HTTP_ENABLED=false`** on production unless a **separate** production go-live document approves otherwise.

---

## 5. Required Feature Flags and Environment (Real Smoke)

**Mandatory trio for a real staging HTTP smoke** (all must be satisfied **on staging only**):

| Variable / flag | Purpose |
|-----------------|---------|
| **`GATEWAY_HOSTB_BASE_URL`** | Staging Host B base URL (config-only; no hardcoded host in source). |
| **`GATEWAY_HOSTB_API_KEY`** | **Dedicated internal** Host B API key; sent toward Host B as **`x-api-key`** (implementations may emit **`X-API-Key`**). **Must** come from **secret store or environment** — **never** a literal in repo source. |
| **`GATEWAY_HOSTB_HTTP_ENABLED`** | **`true`** only for the approved staging smoke window; **off** otherwise. |

**NO-GO rule:** If **`GATEWAY_HOSTB_API_KEY`** is **missing, empty, or not provisioned** for the target staging profile, the **real HTTP smoke is NO-GO** until operations provisions the secret. Do **not** reuse **`X-BBC-API-Key`** (client → gateway) as a substitute.

**Production:** **`GATEWAY_HOSTB_HTTP_ENABLED=false`** remains required on **production** under this MVP unless a **separate** production rollout is approved in writing.

- **Staging-only** flag (or deployment profile) that enables outbound Host B HTTP **only** when explicitly turned on for the smoke window.  
- **Production:** flag **off**; **`GATEWAY_HOSTB_HTTP_ENABLED=false`**.  
- If smoke **fails**, turn flag **off** immediately and return to dry-run (Section 11).

---

## 6. Required Test Input

| Input | Rule |
|-------|------|
| **Service** | **`tour.search` only** — the **first** real HTTP test must not invoke any other Host B route. |
| **Client params (→ Host A)** | **Allowlist only** (e.g. `keyword`, `page`, `pageSize`, dates per contract) — see `docs/API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md`. |
| **`sno` (→ Host B query)** | Host A MUST include the **authoritative staging `sno`** on the **Host B query string** after tenant mapping / auth so Host B **TenantResolver** resolves the tenant. **Do not** use MVP dry-run placeholder internal IDs on the Host B query. |
| **`tenantContext` (Host A only)** | **`depID`, `storeNo`, `store_uid`, `provider_id_no`** and related internal fields — resolved and used **only on Host A** for validation, **audit**, **trace**, permissions, and rate limits; **MUST NOT** be appended to the Host B `/api/tour/search` query (**`depID` in query → Host B 400**). |
| **Trace** | Valid `traceId` for correlation (prefer header if agreed with Host B). |
| **Forbidden** | Client **must not** pass Host B URL/host, SQL fragments, internal tenant override fields, or arbitrary paths. **Real smoke must not** send dry-run placeholders **`D001` / `S001` / `U001` / `P001`** (or any internal tenant fields) **to Host B query** — use a real staging `sno` and real internal key handling per operations. |

---

## 7. Expected Host B Request

- **Method:** `GET` (per `config/api_gateway_services.php` for `tour.search`).  
- **URL:** **`GATEWAY_HOSTB_BASE_URL`** (from environment / deployment config) **+** **`/api/tour/search`** — **only** this combination; **no hardcoded host in code**; **no client-supplied URL**.  
- **Headers (Host A → Host B):**  
  - **`x-api-key`** (or **`X-API-Key`**): value from **`GATEWAY_HOSTB_API_KEY`** (or equivalent secret injection). **Dedicated internal** credential only.  
  - Host A MUST **not** forward **`X-BBC-API-Key`** (the **client → gateway** key) to Host B.  
  - **Logging / audit:** never record the **full** internal API key; redact or omit per gateway audit policy.
- **Query string:** Allowlisted client search/paging parameters (e.g. `keyword`, `page`, `pageSize`) **plus** **`sno`** (authoritative, server-chosen for Host B **TenantResolver**).  
- **Query MUST NOT include:** `depID` (Host B **400** if present), `storeNo`, `store_uid`, `provider_id_no`, or other internal-only tenant fields — those belong to **Host A `tenantContext` only** (audit / trace / validation), not the Host B query.

---

## 8. Expected Host B Response

- **HTTP:** `2xx` for happy path.  
- **Body:** JSON matching agreed contract (e.g. `success`, `items`, paging fields — exact shape per Host B Swagger + gateway normalizer).  
- **No** raw SQL errors, stack traces, or internal connection strings in payload.  

---

## 9. Success Criteria

- Dry-run suite **passed** immediately before smoke.  
- **GO gate:** **`GATEWAY_HOSTB_API_KEY`** is present and non-empty for staging (otherwise **NO-GO** — Section 5).  
- Single **`tour.search`** request returns **2xx** with **valid JSON** and tenant-appropriate data, using **`GATEWAY_HOSTB_BASE_URL`** + **`x-api-key`** from internal key material + **`GATEWAY_HOSTB_HTTP_ENABLED=true`** on staging only.  
- `traceId` traceable in Host A and Host B logs.  
- No policy violation (no client upstream URL, no non-whitelist params; **no** full internal API key in logs/audit).

---

## 10. Failure Criteria

Any of the following **stops** the smoke as **failed** and triggers **rollback** (Section 11):

- Missing **`GATEWAY_HOSTB_API_KEY`** or empty key material when attempting real HTTP (**NO-GO** — treat as failed readiness, Section 5).  
- Non-2xx or **non-JSON** body (unless contract defines error JSON).  
- Timeout / connection error.  
- Response indicates **wrong tenant** scope or **sensitive** leakage.  
- Gateway accepts a **disallowed** client parameter or **host** override.  
- Dry-run suite **fails** when re-run after rollback.

---

## 11. Rollback Procedure

1. Set **`GATEWAY_HOSTB_HTTP_ENABLED=false`** on staging (or disable feature flag).  
2. Confirm gateway **no longer** opens outbound Host B connections.  
3. Re-run **`run_mvp_dry_run_suite.ps1`** — must pass (**exit 0**).  
4. Record failure in ticket with `traceId` and timestamps.  

**If HTTP fails, immediately switch back to dry-run** (this is mandatory).

---

## 12. Security Rules

- **Staging only** for this smoke plan; **production** remains **`GATEWAY_HOSTB_HTTP_ENABLED=false`** unless a **separate** production go-live is explicitly approved.  
- **Endpoint = config only:** `GATEWAY_HOSTB_BASE_URL` + `/api/tour/search`; **never** client-provided Host B URL.  
- **Whitelist-only** query params toward Host B: search/paging allowlist **+ `sno`** only; **never** internal tenant dimensions on the query.  
- **`tenantContext`** (`depID`, `storeNo`, `store_uid`, `provider_id_no`, …) — **Host A internal only** (validation, audit, trace); **not** copied to Host B query for this route.  
- **Keys:** Internal Host B key on **`x-api-key` / `X-API-Key`**, sourced only from **`GATEWAY_HOSTB_API_KEY`** (or equivalent secret); **never** hardcode in source; **never** forward **`X-BBC-API-Key`** to Host B; **never** log or persist the **full** internal API key in access logs, app logs, or audit payloads.
- **Smoke data hygiene:** Do **not** reuse MVP dry-run placeholder values **`D001` / `S001` / `U001` / `P001`** as Host B query inputs — real smoke uses an **approved staging `sno`** and correct internal key provisioning.

---

## 13. Recommended Implementation Step

After documentation approval: implement a **staging-only** code path (separate change, not part of this document) that (1) refuses production when `GATEWAY_HOSTB_HTTP_ENABLED` is false, (2) uses `ServiceRegistry` + `TourSearchRequestBuilder` + real HTTP client only when flag and env allow, and (3) logs a single smoke result. **Ship code only with PR + review**; keep this file as the **runbook** the operator follows step-by-step.

---

## Host B HTTP Client Naming Convention

**MVP Step 10-A (documentation):** This section records the **class vs. file naming** decision for the Host B HTTP client skeleton added in MVP Step 10.

1. **New file (Step 10):** `core\api_gateway\production\HostBHttpClient.php` (full path on Host A: `C:\bbc-ai-bot\core\api_gateway\production\HostBHttpClient.php`).
2. **Actual PHP class name in that file:** `MvpStagingHostBHttpClient` (not `HostBHttpClient`).
3. **Intent:** The name signals **staging-only / MVP-only** use; operators and reviewers should not treat it as the production HTTP stack until explicitly integrated and approved.
4. **Does not replace Phase 6:** The existing Phase 6 file `core\api_gateway\production\http\HostBHttpClient.php` (`C:\bbc-ai-bot\core\api_gateway\production\http\HostBHttpClient.php`) and its class remain the baseline for that layer; Step 10’s file is **additive**, not a drop-in rename.
5. **Future merge:** If the two clients are ever unified, that work **must** be a **separate PR** covering **namespace**, **class rename**, and **integration strategy** so autoloaders and call sites do not hit **class name collision** or **`Cannot redeclare`** errors.
6. **Production flow:** As of this document revision, **production gateway flow is not wired** to `MvpStagingHostBHttpClient`; no assumption should be made that outbound Host B traffic uses this class.
7. **No real HTTP yet:** The Step 10 implementation **still does not send real HTTP requests** to Host B; it validates inputs and returns a documented mock envelope until a later change adds a real transport.
8. **Real HTTP:** Implementing **real outbound HTTP** to Host B requires **separate approval** (security, staging smoke, and production go-live per activation and launch plans) before code ships.

---

## Required Test Checklist

Run or verify the following **before** treating staging HTTP or gateway changes as ready for smoke (extend with project CI as needed):

- [ ] **MVP Host B HTTP client (Step 10) unit test:**  
  `C:\bbc-ai-bot\tests\api_gateway\test_host_b_http_client_mvp.php`  
  Example:  
  `C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\api_gateway\test_host_b_http_client_mvp.php` → **exit code 0** and pass message.

---

## Change Statement

| Item | Status |
|------|--------|
| Files created / updated | `docs/API_GATEWAY_MVP_STAGING_HTTP_SMOKE_TEST_PLAN.md` (documentation; **2026-05-16**: **`GATEWAY_HOSTB_API_KEY`**, smoke mandatory trio, **NO-GO** without key, log/audit key redaction, production flag rule) |
| Code / `.env` / Git / HTTP / SQL | **Not modified or executed** by this document revision |

**Full path:** `C:\bbc-ai-bot\docs\API_GATEWAY_MVP_STAGING_HTTP_SMOKE_TEST_PLAN.md`
