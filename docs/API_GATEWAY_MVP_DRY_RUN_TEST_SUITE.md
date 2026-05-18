# API Gateway MVP — Dry-Run Test Suite

**Project root:** `C:\bbc-ai-bot`  
**Document version:** 2026-05-14  
**Type:** Pre–go-live checklist and test suite specification (**documentation only**).

**Related:** `docs/API_GATEWAY_MVP_LAUNCH_PLAN.md`, `docs/API_GATEWAY_PHASE6_COMPLETION_REPORT.md`, `docs/API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md`.

---

## 1. Document Purpose

This document defines the **MVP Dry-Run Test Suite** for **`tour.search`**: a **mandatory**, **repeatable** set of CLI tests that must pass before expanding scope or enabling **real Host B HTTP**. It consolidates existing MVP tests into a single **pre–go-live checklist** with execution order, pass criteria, and explicit **non-goals** (what the suite does *not* validate).

---

## 2. MVP Dry-Run Scope

| In scope | Out of scope (this suite) |
|----------|---------------------------|
| `ServiceRegistry` reads `config/api_gateway_services.php` | Real Host B or any outbound HTTP |
| `TourSearchRequestBuilder` allowlist + tenant injection | SQL execution or schema migrations |
| `TourSearchDryRunProxy` dry-run envelope (`dryRun`, `httpSent`) | LINE webhook / production gateway kernel wiring |
| End-to-end **assembly** of client params → tenant → dry-run response | Full browser E2E, load tests, production DB audit writes |

**This suite does not send HTTP requests.**  
**This suite does not execute SQL.**  
**This suite does not modify production flow** (it only runs read-only PHP CLI scripts under `tests/api_gateway/`).

**This suite only verifies:**

- **Service registry** resolution for `tour.search` (method, path, timeout, `enabled`).  
- **Query allowlist** behavior and rejection/filtering of dangerous client keys.  
- **`tenantContext` injection** (`depID`, `storeNo`, `store_uid`, `provider_id_no`) and that **client cannot override** tenant fields.  
- **Dry-run proxy** flags and `traceId` propagation.  
- **E2E dry-run assembly** from sample `clientParams` + `tenantContext` through the full MVP chain.

**Real Host B HTTP integration** must wait for a **feature flag** and **human approval** before enablement. **`GATEWAY_HOSTB_HTTP_ENABLED` should remain `false` by default** until that approval and operational readiness are complete.

---

## 3. Required Test Files

The following files are **required** for this suite (absolute paths on Host A):

1. `C:\bbc-ai-bot\tests\api_gateway\test_service_registry_mvp.php`  
2. `C:\bbc-ai-bot\tests\api_gateway\test_tour_search_request_builder_mvp.php`  
3. `C:\bbc-ai-bot\tests\api_gateway\test_tour_search_dry_run_proxy_mvp.php`  
4. `C:\bbc-ai-bot\tests\api_gateway\test_tour_search_dry_run_e2e_mvp.php`  

---

## 4. Test Execution Order

Run **in order** (fail-fast recommended: stop on first non-zero exit):

1. **Service registry** — `test_service_registry_mvp.php`  
2. **Request builder** — `test_tour_search_request_builder_mvp.php`  
3. **Dry-run proxy** — `test_tour_search_dry_run_proxy_mvp.php`  
4. **E2E dry-run chain** — `test_tour_search_dry_run_e2e_mvp.php`  

**Example commands (adjust PHP path if needed):**

```bat
C:\Web\xampp\php\php.exe -l C:\bbc-ai-bot\tests\api_gateway\test_service_registry_mvp.php
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\api_gateway\test_service_registry_mvp.php

C:\Web\xampp\php\php.exe -l C:\bbc-ai-bot\tests\api_gateway\test_tour_search_request_builder_mvp.php
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\api_gateway\test_tour_search_request_builder_mvp.php

C:\Web\xampp\php\php.exe -l C:\bbc-ai-bot\tests\api_gateway\test_tour_search_dry_run_proxy_mvp.php
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\api_gateway\test_tour_search_dry_run_proxy_mvp.php

C:\Web\xampp\php\php.exe -l C:\bbc-ai-bot\tests\api_gateway\test_tour_search_dry_run_e2e_mvp.php
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\api_gateway\test_tour_search_dry_run_e2e_mvp.php
```

---

## 5. One-Command Execution

**Registered command (Host A):**

```powershell
powershell -ExecutionPolicy Bypass -File C:\bbc-ai-bot\tests\api_gateway\run_mvp_dry_run_suite.ps1
```

This script runs **in order** (fail-fast on first failure):

1. `test_service_registry_mvp.php`
2. `test_tour_search_request_builder_mvp.php`
3. `test_tour_search_dry_run_proxy_mvp.php`
4. `test_tour_search_dry_run_e2e_mvp.php`

**Implementation:** `C:\bbc-ai-bot\tests\api_gateway\run_mvp_dry_run_suite.ps1` invokes `C:\Web\xampp\php\php.exe` for each file under `C:\bbc-ai-bot\tests\api_gateway\`.

**Pass criteria (one-command run):**

- All **four** underlying PHP tests report **passed** on stdout.  
- The PowerShell process **exit code is `0`**.  
- The final line **`API Gateway MVP Dry-Run Suite passed.`** is printed.

**Safety (this command and the tests it runs):**

- Does **not** send **HTTP requests**.  
- Does **not** execute **SQL**.  
- Does **not** modify **production flow** (no gateway kernel, no webhook, no server config changes).  
- Does **not** enable **real Host B** calls — dry-run only.  
- **`GATEWAY_HOSTB_HTTP_ENABLED` must remain `false` by default** until a separate approved activation enables outbound Host B.

---

## 6. Expected Pass Criteria

| Criterion | Meaning |
|-----------|---------|
| **Exit code 0** | Each script exits with status `0`. |
| **Stdout** | Each script prints a `passed` line (exact wording per script). |
| **No stderr failures** | No `FAIL:` lines from assertions. |
| **Lint (optional but recommended)** | `php -l` reports no syntax errors for each test file before run. |

Scripts validate, among other things: `tour.search` registry row (`GET`, `/api/tour/search`, timeout `30`), allowlisted query fields, tenant injection, forbidden key stripping, `dryRun === true`, `httpSent === false`, and correct `traceId` on the proxy/E2E path.

---

## 7. Safety Guarantees

| Guarantee | Notes |
|-----------|--------|
| **No HTTP** | Tests exercise only in-process PHP; no `curl`, `file_get_contents` to Host B, etc. |
| **No SQL** | No database drivers or queries in these tests. |
| **No production mutation** | Tests do not write audit logs, `.env`, or server configs. |
| **No gateway kernel edits** | Suite is CLI-only; it does not boot the full production HTTP pipeline. |

---

## 8. What This Suite Does Not Test

- **Real Host B** availability, TLS, or response JSON contract.  
- **SQL Server** data correctness or query performance.  
- **Gemini** prompts or LINE reply delivery.  
- **Apache/IIS** routing, `.htaccess`, or firewall rules.  
- **Rate limiting**, **billing**, **full audit dashboards** (explicitly out of MVP per launch plan).  
- **Cross-service** Host B routes beyond `tour.search`.  

These require **separate** staging/integration suites and approvals.

---

## 9. Pre-Go-Live Requirement

Before enabling **real** Host B HTTP or widening customer exposure:

- [ ] All **four** tests in Section 3 pass in the **target deployment environment** (same PHP version class as production), **or** the one-command script in **Section 5** exits `0`.  
- [ ] `GATEWAY_HOSTB_HTTP_ENABLED` remains **`false`** until an approved change enables outbound Host B.  
- [ ] `docs/API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md` allowlist reviewed against `cloud_store_tourdate.php` (field parity).  
- [ ] Rollback and monitoring items from `docs/API_GATEWAY_MVP_LAUNCH_PLAN.md` and `docs/API_GATEWAY_PHASE6_STAGE7_PRODUCTION_ROLLOUT_PREPARATION.md` are acknowledged by owners.

---

## 10. Recommended Next Step

1. **Wire CI / release checklist** to run **Section 5** one-command execution on every relevant build (Windows agent; `php.exe` path must match `run_mvp_dry_run_suite.ps1`).  
2. **Optional:** Add `run_mvp_dry_run_suite.bat` as a wrapper if teams avoid PowerShell — coordinate as a small additional change.  
3. **Product/engineering:** Open a **feature flag + approval ticket** for first **staging** Host B call (still not customer traffic until sign-off), keeping **`GATEWAY_HOSTB_HTTP_ENABLED=false`** in production until go-live.

---

## Change Statement

| Item | Status |
|------|--------|
| Files touched | `docs/API_GATEWAY_MVP_DRY_RUN_TEST_SUITE.md` (documentation update) |
| PHP / PowerShell scripts / `.env` / web server / Git | **Not modified** by this documentation update |
| SQL / HTTP | **Not executed** by this documentation update |

**Full path:** `C:\bbc-ai-bot\docs\API_GATEWAY_MVP_DRY_RUN_TEST_SUITE.md`
