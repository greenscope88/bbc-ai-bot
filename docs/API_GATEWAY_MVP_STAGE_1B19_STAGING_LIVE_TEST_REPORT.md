# API Gateway MVP — Stage 1-B-19 Staging Controlled Live Test Report

## 1. Document Purpose

Record Controlled Live validation for **tour search prompt context** on staging tenant `sno = e1fd133c7e8e45a1`, including temporary feature gate activation, automated pipeline verification, Gemini reply sampling, security checks, and rollback confirmation.

**Test date:** 2026-05-19 (Host A)  
**Branch:** `feature/api-gateway-mvp`  
**Baseline commit:** `2d31dfb` (feature gate) / local gate temporarily enabled for this test only

## 2. Test Environment

| Item | Value |
|------|--------|
| Host | A — `103.1.222.14` |
| Project path | `C:\bbc-ai-bot` |
| Staging `sno` | `e1fd133c7e8e45a1` |
| Tour search API | `https://bonusmee.com/api/gateway/tour/search.php` |
| Gate class | `core/tour_prompt_feature_gate.php` |
| Test method | CLI pipeline runner on Host A (UTF-8); Gemini Live API for Tokyo sample only |
| LINE E2E | **Not executed in this session** — no new webhook log entries for the five scripted messages; staging LINE send requires deploy of gate-enabled code to the active webhook path |

**Note:** Formal public API currently returns `502` / `HOSTB_HTTP_DISABLED` when Host B HTTP is disabled on the gateway server (consistent with prior Stage 1-B-8 report). This test did **not** modify `.env`.

## 3. Temporary Gate Settings (during test)

```php
private const FEATURE_ENABLED = true;
private const ALLOWED_SNO = ['e1fd133c7e8e45a1'];
private const ALLOWED_CHANNEL_IDS = [];
```

**Post-test status:** All values **restored** to production-safe defaults (`false`, `[]`, `[]`).

## 4. Test Cases

| # | User message | Intent (`is_tour_query`) | Keyword | Gate | API HTTP | API result |
|---|--------------|--------------------------|---------|------|----------|------------|
| 1 | 我想找東京行程 | true | 東京 | true | 502 | `HOSTB_HTTP_DISABLED` |
| 2 | 有沒有大阪五日遊 | true | 大阪五日遊 | true | 502 | `HOSTB_HTTP_DISABLED` |
| 3 | 請推薦歐洲旅遊 | true | 請歐洲 | true | 502 | `HOSTB_HTTP_DISABLED` |
| 4 | 你好 | false | — | true | — | Skipped (excluded) |
| 5 | 火星旅遊 | true | *(empty)* | true | 500 | `JSON_ENCODE_ERROR` |

## 5. Actual AI Responses Summary

### Gemini sample (Case 1 — CLI with merged prompt)

**Input:** `我想找東京行程` with tour context block (API failure-safe message + `search_url`).

**Gemini reply (abridged):**

> 您好，很高興為您服務！  
> 關於您想找東京行程的需求，很抱歉，目前系統未能即時取得可推薦的行程資料。  
> 建議您可以稍後再試，或直接點選以下連結查看完整的搜尋結果…  
> [東京行程完整搜尋結果](https://bonusmee.com/view/cloud/cloud_store_tourdate.php?…&keyword=%E6%9D%B1%E4%BA%AC)

**Observations:**

- Reply is **Traditional Chinese**, polite, no fabricated tour titles/prices.
- Includes **customer-facing search link** from API payload.
- Does **not** expose `traceId`, `depID`, `api_key`, etc.

### LINE behavior (Case 4 — 你好)

Per `saas_router.php`, `你好` uses **early fixed reply** (`你好，我是 BBC AI 客服小編`) before tour gate / Gemini path — tour context is **not** injected. Historical webhook logs confirm this pattern (`safe_gateway_direct_hello`).

## 6. Verification Results

| Check | Result | Notes |
|-------|--------|-------|
| A1 Intent — tour queries | **PASS** | Cases 1–3 detected as tour search |
| A2 Intent — 你好 | **PASS** | Excluded (`excluded_non_tour_topic`) |
| A3 TourSearchApiClient live call | **PARTIAL** | HTTP 502 — Host B disabled on server |
| A4 GeminiTourContextBuilder | **PASS** | Context generated; failure-safe text when API not successful |
| A5 appendTourContext | **PASS** | Separator + rules block present in merged prompt |
| A6 Gemini reply quality (Tokyo) | **PASS** | Grounded messaging + link; no fake products |
| A7 Empty-result message | **NOT OBSERVED** | API did not return `success=true` with `total=0` |
| A8 Error tolerance | **PASS** | No fatal; pipeline continues; Gemini returns user-safe text |
| B Non-tour skip | **PASS** | `context_len = 0` for 你好 |
| C Empty catalog | **BLOCKED** | Requires live API with data or `total=0` response |
| D Rollback | **PASS** | Gate constants restored after test |

## 7. Security Validation

| Item | Status |
|------|--------|
| `api_key` / `x-api-key` in Gemini reply | Not observed |
| `traceId` / `trace_id` in Gemini reply | Not observed |
| `depID` / `storeNo` / `provider_id_no` | Not observed |
| `internal_url` / `upstream_url` | Not observed |
| API error codes in customer text | Not exposed (`HOSTB_HTTP_DISABLED` not echoed) |

## 8. Performance Notes

| Step | Approx. duration |
|------|------------------|
| Full CLI pipeline (5 cases, no Gemini) | ~3–4 s |
| Single Gemini call (Tokyo) | ~4–7 s |
| Live API per keyword | ~0.5–1 s (502 response) |

No timeout or PHP fatal errors observed.

## 9. Rollback Steps (executed)

1. Set `FEATURE_ENABLED = false`
2. Set `ALLOWED_SNO = []`
3. Set `ALLOWED_CHANNEL_IDS = []`
4. Saved `core/tour_prompt_feature_gate.php`
5. Verified `TourPromptFeatureGate::isEnabled(['sno' => 'e1fd133c7e8e45a1'])` → `false` (via unit tests on committed gate behavior)

## 10. Final Conclusion

| Overall | Assessment |
|---------|------------|
| **Controlled Live (code path)** | **Partially successful** — intent → API client → context builder → prompt merge → Gemini works end-to-end with graceful degradation when Host B HTTP is disabled |
| **Live product data** | **Blocked** — gateway returns `HOSTB_HTTP_DISABLED`; cannot validate real `pagination.total` / item titles until Host B HTTP is enabled on the **server** (outside this stage’s `.env` constraint) |
| **LINE staging E2E** | **Pending** — requires deploying gate-enabled `saas_router.php` + `tour_prompt_feature_gate.php` to the active webhook runtime and sending the five messages on the staging LINE channel |
| **Production safety** | **Maintained** — gate rolled back to OFF / empty allowlists |

### Follow-up items (Stage 1-B-20)

1. On gateway server (approved window): temporarily enable Host B HTTP → re-run live API cases 1–3 and empty-keyword case 5.
2. Deploy gate-enabled branch to staging webhook path; run five LINE messages; capture replies in this report addendum.
3. Fix intent keyword extraction for `火星旅遊` (currently may yield empty keyword while `is_tour_query=true`).
4. Improve keyword cleanup for `請推薦歐洲旅遊` (`請歐洲` → prefer `歐洲`).

---

**Report path:** `docs/API_GATEWAY_MVP_STAGE_1B19_STAGING_LIVE_TEST_REPORT.md`
