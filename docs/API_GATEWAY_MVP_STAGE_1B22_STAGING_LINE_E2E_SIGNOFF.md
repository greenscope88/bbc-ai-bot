# API Gateway MVP — Stage 1-B-22 Staging LINE E2E Sign-Off

## 1. Document Purpose

Final pre-production validation for the **tour search → Gemini prompt context → LINE reply** path on staging tenant `e1fd133c7e8e45a1`, including rollback verification and a go / no-go recommendation for **single-tenant production activation**.

**Test date:** 2026-05-19  
**Host:** A (`103.1.222.14`)  
**Branch:** `feature/api-gateway-mvp` (commit `69c98fd`)  
**Staging `sno`:** `e1fd133c7e8e45a1`

## 2. Environment

| Component | Value |
|-----------|--------|
| Codebase | `C:\bbc-ai-bot` |
| LINE webhook | `webhook/callback.php` → `SaaSRouter::handleEvent()` |
| Tour search API | `https://bonusmee.com/api/gateway/tour/search.php` |
| Staging LINE channel (`destination`) | `Ufcedee37a93230a802c30b138f6228f8` (from historical webhook logs) |
| Gemini | `callGemini()` (live API, staging window only) |

## 3. Temporary Settings (test window)

| Setting | During test | After rollback |
|---------|-------------|----------------|
| `.env` `GATEWAY_HOSTB_HTTP_ENABLED` | `true` | **`false`** |
| `FEATURE_ENABLED` | `true` | **`false`** |
| `ALLOWED_SNO` | `['e1fd133c7e8e45a1']` | **`[]`** |
| `ALLOWED_CHANNEL_IDS` | `[]` | **`[]`** |

All temporary values **restored** after this stage.

## 4. Test Messages

| # | Message | Expected behavior |
|---|---------|-------------------|
| 1 | 我想找東京行程 | Tour context + real products + URL |
| 2 | 有沒有大阪五日遊 | Empty catalog message + URL |
| 3 | 請推薦歐洲旅遊 | Tour context (API may return non-Europe items) + URL |
| 4 | 你好 | Fixed hello reply, **no** tour context |
| 5 | 火星旅遊 | Empty catalog + URL, no fabricated Mars tours |

## 5. Actual LINE Responses Summary

### 5.1 Physical LINE OA (operator messages)

Searched `logs/webhook.log` and `logs/saas_router.log` for the five scripted messages during this session: **no matching entries found**. Physical LINE sends were **not captured** in logs in the automated window.

**Operator action required:** Re-run the five messages on staging LINE OA while gate + `.env` are enabled **and** `tenant_line_channels` maps the staging channel to `e1fd133c7e8e45a1` (see §10).

### 5.2 Router-equivalent simulation (gate ON + staging `sno`)

Validated the same path `saas_router.php` uses when `TourPromptFeatureGate::isEnabled()` is true and `sno` is resolved:

| # | Message | Context | Simulated LINE reply (abridged) |
|---|---------|---------|----------------------------------|
| 1 | 我想找東京行程 | ✅ Real tours, NT$, URL | 7070 筆；列出迪士尼/鎌倉/溫泉行程與 **NT$33,800 / NT$24,888 / NT$58,800**、出團日期、**bonusmee.com** 連結 |
| 2 | 有沒有大阪五日遊 | ✅ Empty + URL | 沒有完全符合大阪五日遊；提供搜尋連結 |
| 3 | 請推薦歐洲旅遊 | ✅ Tours + URL | 5676 筆；註記目前顯示多為九州行程，附歐洲搜尋連結 |
| 4 | 你好 | ❌ No context | **「你好，我是 BBC AI 客服小編」** (router early exit) |
| 5 | 火星旅遊 | ✅ Empty + URL | 沒有火星行程；附搜尋連結，未捏造 |

### 5.3 Critical LINE prerequisite: tenant `sno` resolution

`TourPromptFeatureGate::isEnabled(['sno' => '', ...])` → **`false`**.

When `TenantResolver` fails (DB SSL errors observed in historical `saas_router.log`), `$tenant['sno']` stays empty and **tour context will not inject** even with gate enabled in code.

**Before production activation:** confirm `tenant_line_channels.channel_id` for staging OA maps to `e1fd133c7e8e45a1`.

## 6. Verification Checklist

| Item | Status |
|------|--------|
| A1 Real product data (東京) | ✅ Pass (simulation) |
| A2 Price in reply | ✅ Pass |
| A3 Departure dates | ✅ Pass |
| A4 Search URL | ✅ Pass |
| A5 No fabricated tours (火星) | ✅ Pass |
| B1 你好 — no tour context | ✅ Pass |
| B2 你好 — fixed greeting | ✅ Pass |
| C1 Empty result message (大阪/火星) | ✅ Pass |
| C2 URL on empty result | ✅ Pass |
| D Security — no api_key/traceId/depID | ✅ Pass |
| E Physical LINE log evidence | ⏳ **Pending operator** |
| F DB → staging `sno` mapping | ⏳ **Verify before prod** |
| G Rollback | ✅ Pass |

## 7. Security Validation

Reviewed simulated LINE reply previews and context blocks:

| Field | Found in customer text? |
|-------|-------------------------|
| api_key | No |
| traceId | No |
| depID | No |
| storeNo | No |
| provider_id_no | No |

Instruction text may mention generic terms (e.g. “traceId”) as AI guidance only — not API leakage.

## 8. Performance Notes

| Step | Approx. time |
|------|----------------|
| Full 5-message simulation (4× Gemini + 1 hello) | ~36 s |
| Single 東京 tour search (live API) | ~10–15 s |
| Default client timeout | 20 s (`TourSearchApiClient::DEFAULT_TIMEOUT_SECONDS`) |

Acceptable for staging; monitor P95 under concurrent LINE traffic before broad rollout.

## 9. Rollback Steps (executed)

1. ✅ `GATEWAY_HOSTB_HTTP_ENABLED=false` in `.env`
2. ✅ `FEATURE_ENABLED=false`
3. ✅ `ALLOWED_SNO=[]`
4. ✅ `ALLOWED_CHANNEL_IDS=[]`
5. ✅ Public tour API returns `502 HOSTB_HTTP_DISABLED` after `.env` rollback
6. ✅ `test_tour_prompt_feature_gate.php` — OK

## 10. Final Sign-Off Recommendation

### **Approved with Minor Issues**

**Rationale**

| Strength | Detail |
|----------|--------|
| ✅ Core pipeline | Intent → API → context → prompt merge → Gemini works on live Host B data |
| ✅ Quality hardening | Timeout 20s, keyword extraction, UTF-8 safe (1-B-21) |
| ✅ Safety | Feature gate + allowlist + rollback verified |
| ✅ Customer-facing content | Real prices/dates/URLs; empty-result handling; no sensitive leak in tests |

| Minor issues (before single-tenant prod) | Action |
|----------------------------------------|--------|
| ⏳ Physical LINE OA | Operator must send 5 messages and attach screenshots / log excerpts |
| ⏳ Tenant DB mapping | Ensure LINE `channel_id` → `sno=e1fd133c7e8e45a1` when DB is up |
| ℹ️ Search relevance | Broad keywords (e.g. 歐洲) may return non-Europe items — Host B / search tuning |
| ℹ️ Gate in code | Production enable = commit/deploy `FEATURE_ENABLED=true` + allowlist (not `.env` alone) |

**Not approved for full multi-tenant production** until allowlist and operational controls are defined per tenant.

**Conditionally approved for single-tenant production activation** (`e1fd133c7e8e45a1`) after:

1. Operator completes physical LINE 5-message sign-off on staging OA.  
2. DB channel mapping confirmed.  
3. Controlled deploy: gate ON + `GATEWAY_HOSTB_HTTP_ENABLED=true` on server `.env` with rollback drill.

---

## Suggested Stage 1-B-23

1. Operator physical LINE sign-off (fill §5.1 in addendum).  
2. Verify / fix `tenant_line_channels` for staging OA.  
3. Merge `feature/api-gateway-mvp` → main with MVP release notes.  
4. Production activation runbook: enable gate + Host B HTTP for one allowlisted `sno` only.  
5. Post-activation monitoring (502 rate, timeout, Gemini errors, 7-day rollback drill).

---

**Document path:** `docs/API_GATEWAY_MVP_STAGE_1B22_STAGING_LINE_E2E_SIGNOFF.md`
