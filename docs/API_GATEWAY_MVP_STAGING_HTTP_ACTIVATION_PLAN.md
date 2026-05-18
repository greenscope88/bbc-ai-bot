# API Gateway MVP — Staging Host B HTTP Activation Plan

**Project root:** `C:\bbc-ai-bot`  
**Document version:** 2026-05-16  
**Type:** Operational and security planning (**documentation only**). **No code changes**, **no `.env` edits**, **no HTTP or SQL execution** in authoring this document.

**Revision 2026-05-16:** Formalized **Host B internal API key** for Host A → Host B **`/api/tour/search`**: header **`x-api-key` / `X-API-Key`**, suggested env **`GATEWAY_HOSTB_API_KEY`**, **no** forwarding of **`X-BBC-API-Key`**, **no** hardcoded keys in source, **no** full key in logs/audit, staging smoke **NO-GO** without key, **production** remains **`GATEWAY_HOSTB_HTTP_ENABLED=false`** unless separately approved.

**Related:** `docs/API_GATEWAY_MVP_LAUNCH_PLAN.md`, `docs/API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md`, `docs/API_GATEWAY_MVP_DRY_RUN_TEST_SUITE.md`, `docs/API_GATEWAY_PHASE6_COMPLETION_REPORT.md`, `config/api_gateway_services.php`.

---

## 1. Document Purpose

This plan defines how to **safely enable the first real outbound HTTP** from Host A to **Host B** in a **staging** context only, for the **single** MVP service **`tour.search`**, under **feature flag + manual approval**. It complements the MVP dry-run suite and **does not** authorize production activation by itself.

---

## 2. Current Dry-Run Status

| Item | Status |
|------|--------|
| **MVP dry-run chain** | Implemented: `ServiceRegistry` → `TourSearchRequestBuilder` → `TourSearchDryRunProxy` → E2E dry-run tests. |
| **One-command suite** | `powershell -ExecutionPolicy Bypass -File C:\bbc-ai-bot\tests\api_gateway\run_mvp_dry_run_suite.ps1` (see `docs/API_GATEWAY_MVP_DRY_RUN_TEST_SUITE.md`). |
| **Production Host B HTTP** | **`GATEWAY_HOSTB_HTTP_ENABLED` must remain `false`** — no real outbound Host B from production gateway until a separate production go-live decision. |
| **Staging Host B HTTP** | **Not enabled** until preconditions (Section 3), flag (Section 4), and approval (Section 7) are satisfied. |

---

## 3. Preconditions Before Staging HTTP

- [ ] **MVP Dry-Run Suite passed** — run Section 5 in `docs/API_GATEWAY_MVP_DRY_RUN_TEST_SUITE.md`; all four tests **passed**, PowerShell **exit code 0**, final line **`API Gateway MVP Dry-Run Suite passed.`**  
- [ ] **Contract frozen** — `docs/API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md` reviewed; Host B **OpenAPI** for `GET /api/tour/search` agreed with backend team.  
- [ ] **Network** — Host A → staging Host B path allowed by **firewall / ACL** (no public client access to Host B).  
- [ ] **Secrets** — staging credentials in **secret store / staging `.env` only** (never committed to repo), including **`GATEWAY_HOSTB_API_KEY`** (internal Host B API key for **`x-api-key` / `X-API-Key`**). **If this key is missing or empty, real staging HTTP smoke is NO-GO** until provisioned.  
- [ ] **Rollback owner** — named engineer + runbook snippet (Section 9).  

---

## 4. Feature Flag Requirement

- A **dedicated feature flag** (or equivalent config gate) MUST control **staging-only** Host B HTTP (e.g. `GATEWAY_HOSTB_HTTP_ENABLED` **true only on staging** deployment profile, **never** flipped in production by this plan).  
- **Production** continues to enforce **`GATEWAY_HOSTB_HTTP_ENABLED=false`** until a **separate** production activation document and sign-off exist.  
- If any real HTTP test **fails**, **immediately** disable the flag and **revert to dry-run** behavior for that environment.

---

## 5. Required Environment Variables

| Variable | Staging (when approved) | Production (this plan) |
|----------|-------------------------|-------------------------|
| `GATEWAY_HOSTB_HTTP_ENABLED` | `true` **only** after manual approval + flag | **`false`** (must stay false per this plan unless a **separate** production go-live explicitly approves otherwise) |
| `GATEWAY_HOSTB_BASE_URL` | Staging base URL (e.g. `https://…` or approved internal URL) | Unset or irrelevant while HTTP disabled |
| **`GATEWAY_HOSTB_API_KEY`** | **Required** for real Host B HTTP: **dedicated internal** API key for Host A → Host B; value MUST come from **secret store or env** — **never** a literal in application source | Unset or irrelevant while HTTP disabled; **never** substitute **`X-BBC-API-Key`** |
| `GATEWAY_HOSTB_CONNECT_TIMEOUT_SEC` / `GATEWAY_HOSTB_READ_TIMEOUT_SEC` | Set per SLO | Defaults until production activation |

**Host B endpoint and internal key must come from configuration only** — **never hardcoded in application source**; **clients must never pass a Host B URL** (only `tour.search` service id and allowlisted query params per contract).

### 5.1 Host A → Host B internal API key (normative)

| Rule | Detail |
|------|--------|
| **Credential** | Host A uses a **dedicated internal Host B API key** when calling **`GET /api/tour/search`** on Host B. |
| **Header** | Send the key in **`x-api-key`**; implementations MAY use **`X-API-Key`** (HTTP header names are case-insensitive). |
| **Not gateway client key** | Host A MUST **not** forward **`X-BBC-API-Key`** (client → Host A gateway admission) to Host B. |
| **Suggested env name** | **`GATEWAY_HOSTB_API_KEY`** — load at runtime from secret manager or environment; **do not** commit real values. |
| **Logging / audit** | **Must not** record the **full** internal API key in access logs, application logs, or audit payloads; use redaction, omission, or approved prefix-only identifiers. |
| **NO-GO** | Staging real HTTP activation + smoke are **NO-GO** if **`GATEWAY_HOSTB_API_KEY`** is missing or empty for the target staging profile. |
| **Production** | **`GATEWAY_HOSTB_HTTP_ENABLED=false`** remains the default; enabling production outbound Host B HTTP requires **outside-this-document** approval. |

---

## 6. Staging Host B Endpoint

| Item | Value / rule |
|------|----------------|
| **MVP service** | **`tour.search` only** — the **first** real HTTP is **only** this service. |
| **Path** | `/api/tour/search` (from `config/api_gateway_services.php` + contract). |
| **Base URL** | Loaded from **`GATEWAY_HOSTB_BASE_URL`** (staging deployment); **not** embedded in PHP as a literal production/staging host string. |
| **Reference staging URL (planning)** | `http://103.1.222.11:8080` — use **HTTPS** and approved DNS when staging hardens. |

---

## 7. Manual Approval Gate

Before the first real HTTP call on staging:

| Role | Sign-off |
|------|----------|
| **Engineering** | Schema + timeout + error mapping + rollback tested. |
| **Security** | ACL, no client-supplied upstream URL, **no secrets in logs** (including **no full** `GATEWAY_HOSTB_API_KEY` / **`x-api-key`** value in access or audit trails), **no** forwarding of **`X-BBC-API-Key`** to Host B. |
| **Operations** | Window, monitoring, on-call. |

**Written approval** (ticket/email) must record: date, approvers, flag name, and **scope = tour.search only**.

---

## 8. First Real HTTP Test Scope

- **Single request class:** `tour.search` → `GET /api/tour/search` with **allowlisted** query (**`sno`** for Host B TenantResolver + search/paging per contract) and **`x-api-key` / `X-API-Key`** from **`GATEWAY_HOSTB_API_KEY`** — **not** internal `depID` / `storeNo` / `store_uid` / `provider_id_no` on the Host B query (see `docs/API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md`).  
- **Low volume:** scripted smoke or single operator-driven test; capture `traceId`, status, latency, response body shape.  
- **No broad traffic** — no LINE production fan-out until a later phase.  
- **If failure:** disable flag, confirm **dry-run** path still passes MVP suite, document incident.

---

## 9. Rollback Plan

1. Set **`GATEWAY_HOSTB_HTTP_ENABLED=false`** on staging (or remove staging override).  
2. Redeploy or reload config so gateway **does not** open sockets to Host B.  
3. Re-run **MVP Dry-Run Suite** to confirm **exit code 0**.  
4. Verify no residual cron/job still calling Host B.  

---

## 10. Production Safety Rule

- **Production MUST keep `GATEWAY_HOSTB_HTTP_ENABLED=false`** until an **explicit production rollout** (outside this document) is approved.  
- **Staging only** may temporarily enable HTTP **after** manual approval and **only** for **`tour.search`**.  
- **Host B URL** = **config only**; **forbid** client-controlled upstream URL in all layers.

---

## 11. Required Test Checklist

- [ ] **`GATEWAY_HOSTB_API_KEY`** provisioned and non-empty for staging (**otherwise NO-GO**).  
- [ ] **MVP Dry-Run Suite** passed immediately before enabling staging HTTP.  
- [ ] **MVP Step 10 — Host B HTTP Client safety test** (`MvpStagingHostBHttpClient` / `core\api_gateway\production\HostBHttpClient.php`) **must pass before any staging real HTTP smoke test** for Host B.  
  - **Command:**  
    `C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\api_gateway\test_host_b_http_client_mvp.php`  
  - **Test file:** `C:\bbc-ai-bot\tests\api_gateway\test_host_b_http_client_mvp.php`  
  - **No real HTTP:** This test **does not** send real HTTP requests; it exercises validation and the documented mock envelope only.  
  - **What it verifies (MVP guardrails):**  
    - `baseUrl` must not be empty (caller-supplied base URL contract).  
    - `path` must start with `/`.  
    - A **full URL must not** be accepted as `path`.  
    - `timeout` must be **1–30 seconds** (inclusive).  
    - The MVP HTTP client is **not** wired into production gateway flow (staging/MVP artifact until a separate integration PR).  
  - **Production unchanged:** **Production MUST keep `GATEWAY_HOSTB_HTTP_ENABLED=false`** per this plan; passing this test does **not** authorize production HTTP.  
- [ ] **Single** `tour.search` real call succeeds (2xx, valid JSON, tenant-scoped data).  
- [ ] **Negative:** invalid API key / tenant → expected 4xx, no leak.  
- [ ] **Negative:** client attempts `Host` / full URL override → **rejected**.  
- [ ] **Rollback drill:** flag off → dry-run suite green again.

---

## 12. Final Recommendation

Treat **dry-run as default**, **staging HTTP as a short, flagged experiment**, and **production as dry-run/off** until contract, monitoring, and **production-specific** approval exist. Never skip the **MVP Dry-Run Suite** before touching real HTTP; on any anomaly, **cut to dry-run first**, then investigate.

---

## Change Statement

| Item | Status |
|------|--------|
| Files created / updated | `docs/API_GATEWAY_MVP_STAGING_HTTP_ACTIVATION_PLAN.md` (documentation; **2026-05-16**: **`GATEWAY_HOSTB_API_KEY`**, **`x-api-key` / `X-API-Key`**, no **`X-BBC-API-Key`** to Host B, no hardcoded keys, no full key in logs, **NO-GO** without key, production flag rule) |
| PHP / `.env` / Git / servers | **Not modified** by this document revision |
| HTTP / SQL | **Not executed** by this document revision |

**Full path:** `C:\bbc-ai-bot\docs\API_GATEWAY_MVP_STAGING_HTTP_ACTIVATION_PLAN.md`
