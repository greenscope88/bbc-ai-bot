# API Gateway MVP — Stage 1-B-21 Quality Hardening Report

## 1. Document Purpose

Record quality improvements for the tour-search → Gemini prompt pipeline:

- `TourSearchApiClient` timeout increase
- `TourQueryIntentDetector` keyword extraction fixes
- Staging live validation with Host B HTTP enabled
- CLI end-to-end (E2E) verification equivalent to LINE router path

**Test date:** 2026-05-19  
**Branch:** `feature/api-gateway-mvp`  
**Staging `sno`:** `e1fd133c7e8e45a1`

## 2. Timeout Optimization

| Item | Before | After |
|------|--------|-------|
| Default timeout | `5` seconds | **`20` seconds** (`TourSearchApiClient::DEFAULT_TIMEOUT_SECONDS`) |
| Constructor | `int $timeoutSeconds = 5` | `int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS` |
| Max cap | none | **60** seconds (`max(1, min(60, $timeoutSeconds))`) |
| Override | Supported via constructor | Unchanged |

**Reason:** Stage 1-B-20 observed `CLIENT_HTTP_TIMEOUT` on live Host B calls (~10s). Default 20s removes false timeouts while keeping injectable override for tests.

**Test:** `tests/test_tour_search_api_client.php` case 0 validates constant and default instance timeout.

## 3. Keyword Extraction Improvements

### Code changes (`core/tour_query_intent_detector.php`)

1. **Prefix noise (longest-first):** added `請推薦`, `推薦一下`; iterative strip at message start.
2. **Suffix noise (trailing only):** `行程`, `旅遊` — e.g. `日本旅遊` → `日本`, `請推薦歐洲旅遊` → `歐洲`.
3. **Removed `自由行` from inline noise** — preserves `東京自由行` in keywords.
4. **UTF-8 safe trim** — replaced byte-oriented `trim($s, charlist)` (corrupted multibyte strings) with Unicode-aware `preg_replace` + `trim()`.
5. **Safe `mb_substr` for 團 / suffix** — positive character lengths instead of negative byte-truncating forms.

### Keyword results (verified)

| User message | Keyword |
|--------------|---------|
| 我想找東京行程 | 東京 |
| 有沒有大阪五日遊 | 大阪五日遊 |
| 請推薦歐洲旅遊 | 歐洲 |
| 火星旅遊 | 火星 |
| 東京自由行推薦 | 東京自由行 |
| 日本旅遊 | 日本 |

## 4. Updated Test Cases

| Test file | Changes |
|-----------|---------|
| `tests/test_tour_search_api_client.php` | Default timeout = 20s |
| `tests/test_tour_query_intent_detector.php` | +6 keyword cases; `想找沖繩自由行` → `沖繩自由行` |

**Results (all pass):**

- `test_tour_query_intent_detector.php` — OK  
- `test_tour_search_api_client.php` — OK  
- `test_tour_prompt_context_service.php` — OK  
- `test_tour_gemini_context_dry_run.php` — OK  
- `test_prompt_context_merger.php` — OK  

## 5. Staging LINE E2E Results

### Temporary settings (rolled back)

- `.env`: `GATEWAY_HOSTB_HTTP_ENABLED=true`
- `TourPromptFeatureGate`: `FEATURE_ENABLED=true`, `ALLOWED_SNO=['e1fd133c7e8e45a1']`

### CLI live E2E (same chain as `saas_router.php`)

| # | Message | Keyword | API | Context | Gemini |
|---|---------|---------|-----|---------|--------|
| 1 | 我想找東京行程 | 東京 | 200, total **7070** | Real tours, NT$ prices | Lists real titles/prices/dates + search URL |
| 2 | 有沒有大阪五日遊 | 大阪五日遊 | 200, total **0** | Empty message + URL | States no matches; provides link |
| 3 | 請推薦歐洲旅遊 | 歐洲 | 200, total **5676** | Real tours (API returned JP items) | Notes results may not match Europe; includes link |
| 4 | 你好 | — | Skipped | **0 bytes** | Skipped (router hello shortcut) |
| 5 | 火星旅遊 | 火星 | 200, total **0** | Empty message + URL | No fabricated Mars packages |

### LINE OA physical test

No new webhook log entries for the five scripted messages during this automated window. **Operator action:** repeat the five messages on staging LINE OA while gate + `.env` are enabled on the **deployed** webhook path to complete physical LINE E2E sign-off.

**Note:** `saas_router.php` on disk still has `TourPromptFeatureGate` **OFF** after rollback; LINE will not inject tour context until gate is enabled in a future controlled rollout commit.

## 6. Security Validation

| Check | Result |
|-------|--------|
| API keys in replies | Not observed |
| traceId / depID / storeNo | Not observed |
| Error codes in customer text | Not observed |
| Host B secrets in Gemini output | Not observed |

## 7. Rollback Steps (executed)

1. ✅ `GATEWAY_HOSTB_HTTP_ENABLED=false` in `.env`
2. ✅ `FEATURE_ENABLED=false`
3. ✅ `ALLOWED_SNO=[]`
4. ✅ `ALLOWED_CHANNEL_IDS=[]`
5. ✅ Public API returns `502 HOSTB_HTTP_DISABLED` after `.env` rollback (verified)
6. ✅ `test_tour_prompt_feature_gate.php` — OK

## 8. Final Conclusion

| Goal | Status |
|------|--------|
| Timeout hardening | ✅ Default 20s |
| Keyword extraction | ✅ All target cases pass |
| Host B live HTTP | ✅ Real product data (東京) |
| Empty / non-tour handling | ✅ |
| Sensitive leak | ✅ None observed |
| Rollback | ✅ Complete |
| LINE OA E2E (physical) | ⏳ Pending operator on staging OA with gate deployed |

**Overall:** Stage 1-B-21 **successful** for code quality and live CLI E2E. Recommend **Stage 1-B-22:** commit code changes + optional LINE sign-off checklist; consider Host B search relevance tuning for broad keywords (e.g. 歐洲).

---

**Report path:** `docs/API_GATEWAY_MVP_STAGE_1B21_QUALITY_HARDENING_REPORT.md`
