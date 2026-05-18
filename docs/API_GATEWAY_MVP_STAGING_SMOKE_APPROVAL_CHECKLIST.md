# API Gateway MVP — Staging Smoke Manual Approval Checklist

**Project root:** `C:\bbc-ai-bot`  
**Document version:** 2026-05-14  
**Type:** Manual sign-off and go/no-go (**documentation only**). Authoring this file **does not** modify code, **does not** change `.env`, **does not** send HTTP, and **does not** run SQL.

**Related:** `docs/API_GATEWAY_MVP_STAGING_HTTP_ACTIVATION_PLAN.md`, `docs/API_GATEWAY_MVP_STAGING_HTTP_SMOKE_TEST_PLAN.md`, `docs/API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md`, `docs/API_GATEWAY_MVP_DRY_RUN_TEST_SUITE.md`.

---

## 1. Document Purpose

This checklist captures **human approval** and **environment readiness** before the **first real Host B HTTP** staging smoke for MVP service **`tour.search`**. It complements automated tests (dry-run suite + Host B HTTP client MVP test) and **must be completed** before any operator enables **`GATEWAY_HOSTB_HTTP_ENABLED`** on **staging** or runs a real outbound smoke call.

**Scope:** Staging only for real HTTP; **production must remain off** until a separate production rollout decision exists.

---

## 2. Current Automated Preconditions

The following reflects **project / Host A repo state** recorded for this checklist (re-verify on the day of smoke if anything changed):

| Item | Status |
|------|--------|
| **Dry-Run Suite** | **Passed** — `tests/api_gateway/run_mvp_dry_run_suite.ps1` → exit code **0**, **`API Gateway MVP Dry-Run Suite passed.`** |
| **`test_host_b_http_client_mvp.php`** | **Passed** — `C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\api_gateway\test_host_b_http_client_mvp.php` → **`test_host_b_http_client_mvp passed.`** |
| **Automated preconditions** | **OK** — dry-run chain + MVP Host B HTTP client validation tests green. |
| **`GATEWAY_HOSTB_HTTP_ENABLED`** | **Not enabled** for this smoke window yet — do **not** flip to `true` until all manual items below are signed. |
| **Real HTTP** | **Not sent** yet as part of gateway MVP staging smoke (no live `tour.search` outbound call executed under this checklist’s “current state”). |
| **Host A → Host B reachability** | **Not verified** — network / firewall / DNS to staging Host B still require explicit ops confirmation. |
| **Production** | **Must keep `GATEWAY_HOSTB_HTTP_ENABLED=false`** — no production real Host B HTTP per MVP staging plans. |

**Plain-language summary (與上表一致):**

- Dry-Run Suite 已通過  
- `test_host_b_http_client_mvp.php` 已通過  
- 自動化前置條件 OK  
- 尚未啟用 `GATEWAY_HOSTB_HTTP_ENABLED`  
- 尚未發送真實 HTTP request（此清單所記錄之當前狀態）  
- 尚未驗證 Host A → Host B network reachability  
- production 仍必須維持 `GATEWAY_HOSTB_HTTP_ENABLED=false`  

---

## 3. Manual Approval Required Items

All items below require **named owner + date** (ticket comment or email attachment). **No go** if any item is open.

- [ ] **工程確認 tour.search contract 已對齊** — `docs/API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md` matches Host B **`GET /api/tour/search`** behavior and agreed allowlist + tenant injection.  
- [ ] **工程確認 Host B `/api/tour/search` 可用** — staging Host B returns expected shape for a controlled test (health / contract probe per team process).  
- [ ] **資安確認 Host B URL 不可由 client 傳入** — gateway rejects host / full URL override; base URL from **config only** (`GATEWAY_HOSTB_BASE_URL`).  
- [ ] **資安確認 tenantContext 由 Host A 注入** — `depID`, `storeNo`, `store_uid`, `provider_id_no` (or agreed fields) **not** accepted from client as authoritative.  
- [ ] **維運確認 `GATEWAY_HOSTB_BASE_URL` 設定來源** — secret store / staging deployment profile documented; not committed to repo.  
- [ ] **維運確認 rollback 負責人** — named on-call or engineer who can disable flag and confirm dry-run path.  
- [ ] **維運確認失敗時可立即切回 dry-run** — flag off + config reload verified in runbook rehearsal.  
- [ ] **確認只允許 staging，不允許 production** — written confirmation that real HTTP smoke is **staging-only** and **production `GATEWAY_HOSTB_HTTP_ENABLED=false`** remains enforced.  

---

## 4. Engineering Approval

| Checkpoint | Owner | Date | Sign-off (initials) |
|------------|-------|------|----------------------|
| Contract aligned (`tour.search`) | 王一祥|2026-05-14 | YH|
| Staging Host B endpoint reachable from app (non-client path) | 王一祥|2026-05-14 | YH|
| Timeouts / error mapping / traceId agreed | 王一祥|2026-05-14 | YH|
| Rollback tested on paper or in non-prod drill | 王一祥|2026-05-14 | YH|

---

## 5. Security Approval

| Checkpoint | Owner | Date | Sign-off (initials) |
|------------|-------|------|----------------------|
| No client-supplied Host B URL / host override | 王一祥|2026-05-14 | YH|
| Allowlisted query only; injection paths reviewed | 王一祥|2026-05-14 | YH|
| Tenant context server-side only | 王一祥|2026-05-14 | YH|
| Logs free of secrets / connection strings | 王一祥|2026-05-14 | YH|

---

## 6. Operations Approval

| Checkpoint | Owner | Date | Sign-off (initials) |
|------------|-------|------|----------------------|
| Smoke window + monitoring |王一祥|2026-05-14 | YH|
| `GATEWAY_HOSTB_BASE_URL` source documented |王一祥|2026-05-14 | YH|
| Flag ownership (`GATEWAY_HOSTB_HTTP_ENABLED`) | 王一祥|2026-05-14 | YH|
| Post-smoke verification (metrics / logs) | 王一祥|2026-05-14 | YH|
---

## 7. Rollback Owner Confirmation

| Field | Value |
|-------|--------|
| **Primary rollback owner** | *王一祥* |
| **Backup** | *王一祥* |
| **Rollback steps** | Disable `GATEWAY_HOSTB_HTTP_ENABLED` on staging → reload/redeploy → re-run MVP Dry-Run Suite (exit 0) — see `docs/API_GATEWAY_MVP_STAGING_HTTP_ACTIVATION_PLAN.md` Section 9. |
| **Owner acknowledges** | [X] I can execute rollback within the agreed SLO. |

---

## 8. Staging Environment Checklist

- [ ] Staging gateway deployment matches **approved** commit / build.  
- [ ] **`GATEWAY_HOSTB_BASE_URL`** set to **staging** Host B only.  
- [ ] **`GATEWAY_HOSTB_HTTP_ENABLED`** remains **`false`** until **after** this checklist is complete and smoke window starts.  
- [ ] Firewall / ACL allows **Host A (staging)** → **Host B (staging)** for the smoke (document ticket ID).  
- [ ] Secrets present in staging secret store (not repo).  

---

## 9. Production Safety Confirmation

- [ ] **Production `GATEWAY_HOSTB_HTTP_ENABLED=false`** verified in production config / deployment profile at sign-off time.  
- [ ] No production job or LINE fan-out depends on real Host B HTTP for MVP (until separate production approval).  
- [ ] Change record notes: **staging smoke only**; production unchanged for outbound Host B HTTP.  

---

## 10. Go / No-Go Decision

| Decision | Criteria |
|----------|----------|
| **GO** | All Sections 3–9 satisfied; automated preconditions (Section 2) re-run **same day** if code changed; written scope = **`tour.search` only**. |
| **NO-GO** | Any open security item, missing rollback owner, unverified network, or production flag uncertainty. |

| Role | GO / NO-GO | Date | Signature |
|------|------------|------|-----------|
| Engineering |GO | 2026-05-14|王一祥 |
| Security | GO | 2026-05-14|王一祥 |
| Operations |GO  |2026-05-14 |王一祥 |

---

## 11. Final Recommendation

Treat **dry-run as the default safe path**. Use **staging real HTTP only as a short, flagged experiment** for **`tour.search`**, with **one** controlled smoke, full trace capture, and **immediate rollback** on any policy or stability issue. **Do not** interpret passing automated tests as permission to enable production Host B HTTP — **production remains `GATEWAY_HOSTB_HTTP_ENABLED=false`** until a **separate** production launch approval exists.

---

## Change Statement

| Item | Status |
|------|--------|
| Files created | `docs/API_GATEWAY_MVP_STAGING_SMOKE_APPROVAL_CHECKLIST.md` only |
| PHP / PowerShell / `.env` / Git / HTTP / SQL | **Not modified or executed** |

**Full path:** `C:\bbc-ai-bot\docs\API_GATEWAY_MVP_STAGING_SMOKE_APPROVAL_CHECKLIST.md`
