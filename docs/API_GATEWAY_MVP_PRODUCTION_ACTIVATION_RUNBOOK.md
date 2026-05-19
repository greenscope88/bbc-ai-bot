# API Gateway MVP — Production Activation Runbook (Single Tenant)

## 1. Document Purpose

This runbook is the **operator-facing guide** for enabling tour-search prompt context injection on **one production tenant** (`e1fd133c7e8e45a1`) on Host A. It covers pre-checks, activation, verification, monitoring, and rollback.

**Stage:** 1-B-23  
**Branch:** `feature/api-gateway-mvp`  
**Reference sign-off:** `docs/API_GATEWAY_MVP_STAGE_1B22_STAGING_LINE_E2E_SIGNOFF.md` (Approved with Minor Issues)  
**Do not use this document to change code** — follow steps only.

---

## 2. Current MVP Status

| Capability | Status |
|------------|--------|
| Formal tour search API | Live — `https://bonusmee.com/api/gateway/tour/search.php` |
| `TourQueryIntentDetector` | Implemented & tested |
| `TourSearchApiClient` | Implemented (default timeout **20s**) |
| `GeminiTourContextBuilder` | Implemented |
| `AiPromptBuilder::appendTourContext()` | Implemented |
| `TourPromptContextService` | Wired in `saas_router.php` |
| `TourPromptFeatureGate` | Implemented (default **OFF**, empty allowlist) |
| Host B HTTP | Controlled via `.env` `GATEWAY_HOSTB_HTTP_ENABLED` |
| Staging validation | 1-B-19 ~ 1-B-22 complete |
| Physical LINE log evidence | **Pending operator** (see 1-B-22 sign-off) |

**Latest known commits on `feature/api-gateway-mvp` (verify before activation):**

| Commit | Description |
|--------|-------------|
| `47e7d19` | Stage 1-B-22 staging LINE E2E signoff |
| `69c98fd` | Timeout + keyword extraction hardening |
| `2d31dfb` | Tour prompt feature gate |
| `c1123fb` | saas_router feature-flag wiring |

---

## 3. Production Activation Scope

### In scope (this activation)

- **Single tenant:** `sno = e1fd133c7e8e45a1`
- Enable **Host B HTTP** on Host A server `.env`
- Enable **TourPromptFeatureGate** for allowlisted `sno` only
- LINE replies via existing webhook → `SaaSRouter::handleEvent()` → Gemini with tour context when intent matches

### Out of scope

- Multi-tenant rollout without per-tenant allowlist review
- Changing webhook entry, Apache, or database schema
- Enabling all LINE channels globally without `TenantResolver` mapping
- Modifying Host B search relevance / ranking logic

---

## 4. Target Tenant Information

| Field | Value |
|-------|--------|
| **sno** | `e1fd133c7e8e45a1` |
| Tenant context map | `config/tenant_context_map.php` |
| Staging LINE `destination` (from logs) | `Ufcedee37a93230a802c30b138f6228f8` |
| Public search URL pattern | `https://bonusmee.com/view/cloud/cloud_store_tourdate.php?...&sno=e1fd133c7e8e45a1&keyword=...` |

### Critical prerequisite

`TenantResolver` must map the **LINE channel_id** to `sno = e1fd133c7e8e45a1` in `tenant_line_channels`. If DB resolution fails, `$tenant['sno']` is empty and **the feature gate will not activate** even when code constants are ON.

**Pre-activation SQL check (operator / DBA — not executed by this runbook):**

```sql
-- Example: verify mapping exists
SELECT t.sno, c.channel_id, t.company_name
FROM tenant_profiles t
INNER JOIN tenant_line_channels c ON c.sno = t.sno
WHERE t.sno = 'e1fd133c7e8e45a1';
```

---

## 5. Pre-Activation Checklist

| # | Item | Owner | Done |
|---|------|-------|------|
| 1 | Stakeholder approval for single-tenant prod activation | Product / Ops | ☐ |
| 2 | Stage 1-B-22 sign-off reviewed (minor issues accepted) | Tech lead | ☐ |
| 3 | Branch `feature/api-gateway-mvp` merged or deployed to prod path | DevOps | ☐ |
| 4 | `git status` clean on deployment host | DevOps | ☐ |
| 5 | Record current commit hash: `____________` | DevOps | ☐ |
| 6 | Backup `.env` and `core/tour_prompt_feature_gate.php` | DevOps | ☐ |
| 7 | `tenant_line_channels` maps staging/prod LINE OA → `e1fd133c7e8e45a1` | DBA / Ops | ☐ |
| 8 | Host B service healthy (`103.1.222.11:8080` or configured base URL) | Ops | ☐ |
| 9 | Gemini API key valid in `.env` | Ops | ☐ |
| 10 | Rollback drill understood by on-call | Ops | ☐ |
| 11 | Maintenance window communicated (if required) | Ops | ☐ |
| 12 | CLI tests pass on deployment host (optional) | Dev | ☐ |

**Optional CLI smoke (on Host A, before activation):**

```text
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\test_tour_prompt_feature_gate.php
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\test_tour_query_intent_detector.php
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\test_tour_search_api_client.php
```

---

## 6. Activation Steps

Execute in order. **Do not skip backups.**

### Step 1 — Confirm branch and commit hash

```powershell
cd C:\bbc-ai-bot
git branch --show-current
git log -1 --oneline
git status -sb
```

**Expected:** branch `feature/api-gateway-mvp` (or merged main containing MVP commits); working tree clean.

Record:

- Branch: `________________`
- Commit: `________________`

### Step 2 — Confirm working tree clean

```powershell
git status -sb
```

**Expected:** no uncommitted changes on production deployment path.

### Step 3 — Backup configuration files

```powershell
$ts = Get-Date -Format "yyyyMMdd_HHmmss"
Copy-Item C:\bbc-ai-bot\.env "C:\bbc-ai-bot\.env.backup_$ts"
Copy-Item C:\bbc-ai-bot\core\tour_prompt_feature_gate.php "C:\bbc-ai-bot\core\tour_prompt_feature_gate.php.backup_$ts"
```

Store backup path: `________________`

### Step 4 — Enable Host B HTTP (`.env`)

Edit `C:\bbc-ai-bot\.env`:

```env
GATEWAY_HOSTB_HTTP_ENABLED=true
```

**Do not** commit `.env` to git. Verify other `GATEWAY_HOSTB_*` values unchanged.

### Step 5 — Enable feature gate (code constants)

Edit `C:\bbc-ai-bot\core\tour_prompt_feature_gate.php`:

```php
private const FEATURE_ENABLED = true;

/** @var list<string> */
private const ALLOWED_SNO = ['e1fd133c7e8e45a1'];

/** @var list<string> */
private const ALLOWED_CHANNEL_IDS = [];  // optional: add LINE channel id to restrict further
```

**Optional channel lock-down** (recommended if multiple tenants share code):

```php
private const ALLOWED_CHANNEL_IDS = ['Ufcedee37a93230a802c30b138f6228f8'];
```

Deploy this file change to the active PHP path (same as webhook).

### Step 6 — Deploy

1. Pull / copy `feature/api-gateway-mvp` (or release tag) to `C:\bbc-ai-bot` on Host A.  
2. Ensure `C:\Web\xampp\htdocs\www\api\gateway\tour\search.php` still points to `BBC_AI_BOT_ROOT = C:\bbc-ai-bot`.  
3. Ensure LINE webhook loads `C:\bbc-ai-bot\webhook\callback.php` (or configured path).

### Step 7 — Restart services (if required)

| Service | Action |
|---------|--------|
| Apache (XAMPP) | Restart Apache if PHP opcache serves stale files |
| PHP opcache | Clear or restart if constants not picked up |
| No Windows service change | Do not modify XAMPP config unless approved |

```text
# Example: restart Apache via XAMPP Control Panel — operator procedure
```

### Step 8 — API smoke test (before LINE)

```powershell
C:\Web\xampp\php\php.exe -r "echo file_get_contents('https://bonusmee.com/api/gateway/tour/search.php?sno=e1fd133c7e8e45a1&keyword='.urlencode('東京').'&pageSize=3');"
```

**Expected:** JSON `"success":true`, `"pagination":{"total":>0}`, non-empty `items`.

### Step 9 — Send LINE verification messages

On **staging or production LINE OA** (per approval), send exactly:

1. `我想找東京行程`
2. `有沒有大阪五日遊`
3. `請推薦歐洲旅遊`
4. `你好`
5. `火星旅遊`

Capture screenshots and timestamps.

### Step 10 — Check logs

```powershell
Get-Content C:\bbc-ai-bot\logs\webhook.log -Tail 50
Get-Content C:\bbc-ai-bot\logs\saas_router.log -Tail 50
```

Confirm: `line_event_received`, `router_complete`, no unexpected `db_layer_fallback` with empty `sno` for test messages.

### Step 11 — Observe 24–72 hours

- Monitor checklists below daily for first 3 days.  
- Keep backups until stable period ends.  
- Document incidents in activation log (separate ticket).

---

## 7. Verification Checklist

### Test messages

| # | Message | Pass | Notes |
|---|---------|------|-------|
| 1 | 我想找東京行程 | ☐ | Real titles, prices, dates, search URL |
| 2 | 有沒有大阪五日遊 | ☐ | Empty-result wording + URL if total=0 |
| 3 | 請推薦歐洲旅遊 | ☐ | Reply + URL; note if results not Europe-specific |
| 4 | 你好 | ☐ | Fixed hello; **no** tour product dump |
| 5 | 火星旅遊 | ☐ | No fabricated Mars packages; empty + URL |

### Per-message criteria

| Criterion | 1 | 2 | 3 | 4 | 5 |
|-----------|---|---|---|---|---|
| Real product data (when API has data) | ☐ | ☐ | ☐ | N/A | ☐ |
| Price shown (NT$) | ☐ | ☐ | ☐ | N/A | ☐ |
| Departure date shown | ☐ | ☐ | ☐ | N/A | ☐ |
| Search result URL present | ☐ | ☐ | ☐ | N/A | ☐ |
| No fabricated itineraries | ☐ | ☐ | ☐ | ☐ | ☐ |
| Empty-result message correct | ☐ | ☐ | ☐ | N/A | ☐ |
| Non-tour: no tour context | N/A | N/A | N/A | ☐ | N/A |

### Security (customer-visible text)

| Check | Pass |
|-------|------|
| No `api_key` / `x-api-key` | ☐ |
| No `traceId` / internal IDs | ☐ |
| No `depID` / `storeNo` / `provider_id_no` | ☐ |
| No raw Host B error codes (e.g. `HOSTB_HTTP_DISABLED`) | ☐ |

### Technical

| Check | Pass |
|-------|------|
| `TourPromptFeatureGate::isEnabled` true only for `e1fd133c7e8e45a1` | ☐ |
| Other tenants / empty `sno` do not get tour context | ☐ |
| Tour search API returns 200 (not 502) | ☐ |
| No spike in `CLIENT_HTTP_TIMEOUT` | ☐ |

---

## 8. Monitoring Checklist

### Logs (daily during soak period)

| Log | Path | What to watch |
|-----|------|----------------|
| Webhook | `C:\bbc-ai-bot\logs\webhook.log` | Incoming messages, `line_api_response`, errors |
| SaaS router | `C:\bbc-ai-bot\logs\saas_router.log` | `router_complete`, `db_layer_fallback`, `ai_ok` |
| Gemini errors | `logs/` (gemini error logs if configured) | API failures, quota |
| API Gateway | Apache / PHP error logs for `api/gateway/tour/search.php` | 5xx, JSON errors |
| Host B | Host B application logs on `103.1.222.11` | Upstream latency, errors |

### Metrics / quality (qualitative)

| Area | Threshold / action |
|------|-------------------|
| LINE reply time | Flag if routinely > 30–40s (Gemini + Host B) |
| HTTP timeout | Alert on `CLIENT_HTTP_TIMEOUT` in any user-facing path |
| Gemini quality | Spot-check 5–10 real user queries / day |
| Empty-result rate | Track keywords with `total=0`; adjust messaging if needed |
| 502 on tour API | Immediate rollback if sustained after activation |

### Weekly review (first month)

- [ ] Review allowlist still only `e1fd133c7e8e45a1`  
- [ ] Confirm `.env` `GATEWAY_HOSTB_HTTP_ENABLED` still `true` intentionally  
- [ ] Re-run one LINE verification message set  
- [ ] Update stakeholders  

---

## 9. Rollback Steps

Execute **immediately** if: sustained 502, sensitive data leak, severe wrong recommendations, or on-call decision.

### Step 1 — Disable Host B HTTP

Edit `C:\bbc-ai-bot\.env`:

```env
GATEWAY_HOSTB_HTTP_ENABLED=false
```

### Step 2 — Disable feature gate

Edit `C:\bbc-ai-bot\core\tour_prompt_feature_gate.php`:

```php
private const FEATURE_ENABLED = false;

private const ALLOWED_SNO = [];

private const ALLOWED_CHANNEL_IDS = [];
```

### Step 3 — Redeploy / restore from backup

```powershell
# If needed:
Copy-Item C:\bbc-ai-bot\.env.backup_YYYYMMDD_HHMMSS C:\bbc-ai-bot\.env -Force
Copy-Item C:\bbc-ai-bot\core\tour_prompt_feature_gate.php.backup_YYYYMMDD_HHMMSS C:\bbc-ai-bot\core\tour_prompt_feature_gate.php -Force
```

Restart Apache if opcache caches old constants.

### Step 4 — Verify API returns disabled state

```powershell
C:\Web\xampp\php\php.exe -r "$u='https://bonusmee.com/api/gateway/tour/search.php?sno=e1fd133c7e8e45a1&keyword='.urlencode('東京'); $j=json_decode(file_get_contents($u),true); echo ($j['success']??'?').' '.($j['error']['code']??'').PHP_EOL;"
```

**Expected after rollback:** `success` false or HTTP 502, error code `HOSTB_HTTP_DISABLED`.

### Step 5 — Verify LINE legacy behavior

1. Send `你好` → expect fixed hello only.  
2. Send `我想找東京行程` → expect **previous** behavior (no tour-search context block from gateway; generic Gemini / DB path only).  
3. Confirm logs show no tour context injection path for allowlisted flow.

### Step 6 — Post-rollback

- [ ] Notify stakeholders  
- [ ] File incident summary  
- [ ] Keep backups for forensics  
- [ ] Plan fix before re-activation  

---

## 10. Success Criteria

Activation is **successful** when all are true for **72 hours**:

1. Only `e1fd133c7e8e45a1` receives tour prompt context (gate + resolver).  
2. Verification table (§7) signed off with physical LINE evidence.  
3. Tokyo-style queries return real product data with URL.  
4. Empty keywords return appropriate empty messaging + URL.  
5. `你好` unchanged (no tour dump).  
6. No sensitive field leakage in customer replies.  
7. No sustained 502 / timeout regression on tour API or LINE path.  
8. Rollback drill validated once in maintenance window.

---

## 11. Known Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| DB failure → empty `sno` | Feature silently OFF for LINE | Fix DB SSL / mapping before activation |
| Host B slow (>20s) | Timeout, generic AI reply | Monitor; tune timeout only via code release |
| Broad keywords (歐洲) | Irrelevant items in context | Gemini disclaimer; future search tuning |
| Gemini hallucination | Wrong tour details | Context rules + “未提供” fields; human review |
| `.env` not on git | Drift between hosts | Document server-specific `.env`; backup before change |
| Gate constants require deploy | Forgetting redeploy | Checklist + Apache restart |
| Multi-tenant leakage | Wrong tenant data | Allowlist **only** one `sno`; optional channel allowlist |
| opcache stale constants | Gate appears OFF after edit | Restart Apache |

---

## 12. Final Recommendation

Based on **Stage 1-B-22** outcome (**Approved with Minor Issues**) and completed MVP chain (1-B-1 through 1-B-21):

### **Approved for Single-Tenant Production Activation**

**Conditions:**

1. Complete **physical LINE** verification (§7) and attach evidence to ticket.  
2. Confirm **`tenant_line_channels`** maps LINE OA → `e1fd133c7e8e45a1`.  
3. Follow **§6 Activation Steps** with backups and recorded commit hash.  
4. Run **§8 Monitoring** for 24–72 hours minimum.  
5. Keep **§9 Rollback** ready and tested once.

**Not approved:** enabling all tenants or enabling gate without Host B HTTP and allowlist alignment.

---

## Related documents

| Document | Purpose |
|----------|---------|
| `docs/API_GATEWAY_TOUR_PROMPT_CONTROLLED_LIVE_PLAN.md` | Gate design |
| `docs/API_GATEWAY_MVP_STAGE_1B20_LIVE_HTTP_TEST_REPORT.md` | Live HTTP proof |
| `docs/API_GATEWAY_MVP_STAGE_1B21_QUALITY_HARDENING_REPORT.md` | Timeout / keywords |
| `docs/API_GATEWAY_MVP_STAGE_1B22_STAGING_LINE_E2E_SIGNOFF.md` | E2E sign-off |

---

**Document path:** `docs/API_GATEWAY_MVP_PRODUCTION_ACTIVATION_RUNBOOK.md`  
**Version:** 1.0 (Stage 1-B-23)  
**Status:** Draft for operator use — not yet committed until Stage 1-B-24 (if applicable)
