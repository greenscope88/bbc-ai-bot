# API Gateway MVP — Stage 1-B-20 Live HTTP Test Report

## 1. Document Purpose

Record staging **Host B HTTP activation**, live tour search API validation, and end-to-end prompt-context + Gemini verification for tenant `e1fd133c7e8e45a1`. Includes rollback confirmation.

**Test date:** 2026-05-19  
**Host:** A (`103.1.222.14`)  
**Branch:** `feature/api-gateway-mvp`  
**Baseline commit:** `4e28a49`

## 2. Environment

| Item | Value |
|------|--------|
| Project | `C:\bbc-ai-bot` |
| Public tour search API | `https://bonusmee.com/api/gateway/tour/search.php` |
| API entry loads | `C:\bbc-ai-bot\core\tour_search_service.php` via `BBC_AI_BOT_ROOT` |
| Staging `sno` | `e1fd133c7e8e45a1` |
| Host B base URL | From `.env` → `GATEWAY_HOSTB_BASE_URL` (not printed) |

## 3. Temporary Changes (during test window only)

| Setting | Before | During test | After rollback |
|---------|--------|-------------|----------------|
| `.env` `GATEWAY_HOSTB_HTTP_ENABLED` | `false` | **`true`** | **`false`** |
| `TourPromptFeatureGate::FEATURE_ENABLED` | `false` | **`true`** | **`false`** |
| `TourPromptFeatureGate::ALLOWED_SNO` | `[]` | **`['e1fd133c7e8e45a1']`** | **`[]`** |
| `ALLOWED_CHANNEL_IDS` | `[]` | `[]` | `[]` |

**All temporary values have been restored.** `.env` is git-ignored and was not committed.

## 4. Host B HTTP Activation Method

### 4.1 Control chain

| Layer | File | Mechanism |
|-------|------|-----------|
| Environment | `C:\bbc-ai-bot\.env` | `GATEWAY_HOSTB_HTTP_ENABLED` (boolean string) |
| Config loader | `config/config.php` | Maps to `gateway.host_b.http_enabled` |
| Orchestration | `core/tour_search_service.php` | If not enabled → returns `errorCode: HOSTB_HTTP_DISABLED`, HTTP 502 |
| Outbound HTTP | `core/api_gateway/production/HostBHttpClient.php` (`MvpStagingHostBHttpClient`) | `sendGet()` performs real GET when enabled and not dry-run |

### 4.2 Default value

- **`GATEWAY_HOSTB_HTTP_ENABLED=false`** (safe default)

### 4.3 Safest temporary enable (staging)

1. Set `GATEWAY_HOSTB_HTTP_ENABLED=true` in `C:\bbc-ai-bot\.env` (same file used by `search.php` on this host).
2. No PHP code change required for Host B HTTP itself.
3. Separately enable `TourPromptFeatureGate` for LINE/Gemini path (code constants).
4. Roll back both after testing.

## 5. Test Cases

| # | Message | Intent | Live API | Context | Gemini (CLI) |
|---|---------|--------|----------|---------|--------------|
| 1 | 我想找東京行程 | ✅ 東京 | ✅ 200, total **7070**, real titles/prices | ✅ 5 tours, NT$ prices, search_url | ✅ Real product summary + link |
| 2 | 有沒有大阪五日遊 | ✅ 大阪五日遊 | ✅ 200, **total 0** | ✅ Empty message + search_url | ⚠️ Gemini call failed in batch run |
| 3 | 請推薦歐洲旅遊 | ✅ 請歐洲* | ✅ 200, **total 0** | Failure-safe text | ✅ Polite reply, no fabricated tours |
| 4 | 你好 | ❌ excluded | Skipped | **0 bytes** | Skipped (router uses fixed hello reply) |
| 5 | 火星旅遊 | ✅ keyword empty* | API `火星` → total **0** (direct) | Failure-safe | ✅ No fabricated Mars tours |

\*Follow-up: improve keyword extraction (`請歐洲` → `歐洲`, `火星旅遊` → `火星`).

### 5.1 Tokyo retry (30s client timeout)

`TourSearchApiClient` default timeout is **5 seconds**; first Tokyo call in batch run hit `CLIENT_HTTP_TIMEOUT`. Retry with **30s** timeout succeeded:

- First item: `高雄出發│東京輕鬆遊~迪士尼樂園、河口湖音樂之森6日 直售33,800起 💎`
- Price: `33800` → context `NT$33,800`
- Gemini reply cited **7070** results, real titles, dates, prices, and `bonusmee.com` search link.

### 5.2 LINE staging messages

**Not automated in this session.** Staging LINE OA requires an operator to send the five messages while gate + `.env` are enabled **and** the active webhook loads this codebase. CLI validation covers the same chain as `saas_router.php` (Intent → `TourSearchApiClient` → `GeminiTourContextBuilder` → `appendTourContext` → `callGemini`).

## 6. Actual AI Responses Summary

### Tokyo (successful live data)

Gemini (abridged): Listed Disney/河口湖 6-day and 鎌倉 5-day products with **NT$33,800 / NT$24,888 / NT$58,800**, departure dates **2026/06/01**, etc., total **7070** trips, and included the **full search URL** for 東京.

### Osaka / Europe (empty catalog)

- API returned `success: true`, `pagination.total: 0`.
- Context builder: **「目前沒有找到符合條件的行程」** (Osaka case confirmed).
- Gemini: polite guidance without inventing specific products.

### 你好

- Intent excluded; `context_len = 0` (no tour injection).
- Production router: fixed reply **「你好，我是 BBC AI 客服小編」** before Gemini path.

### 火星

- Direct API `keyword=火星`: `total: 0`, empty `items`, valid `search_url`.
- Intent keyword extraction returned empty string (known issue); failure-safe Gemini reply, no fake Mars packages.

## 7. Security Validation

| Check | Result |
|-------|--------|
| `api_key` in customer-facing text | Not observed |
| `traceId` in Gemini replies | Not observed |
| `depID` / `storeNo` / `provider_id_no` | Not observed |
| `HOSTB_HTTP_DISABLED` echoed to user | Not observed |
| Host B API key in responses | Not observed |

## 8. Performance Notes

| Operation | Approx. time |
|-----------|----------------|
| Live API (東京, pageSize=3) | ~10 s |
| Live API (火星, empty) | ~15 s |
| Full pipeline + Gemini (東京, 30s timeout) | ~37 s |
| Batch run Tokyo (5s timeout) | Timeout (`CLIENT_HTTP_TIMEOUT`) |

**Recommendation:** Increase `TourSearchApiClient` default timeout (e.g. 15–30s) for production staging.

## 9. Rollback Steps (executed)

1. ✅ `GATEWAY_HOSTB_HTTP_ENABLED=false` in `.env`
2. ✅ `FEATURE_ENABLED = false`
3. ✅ `ALLOWED_SNO = []`
4. ✅ `ALLOWED_CHANNEL_IDS = []`
5. ✅ `test_tour_prompt_feature_gate.php` passed after rollback
6. ✅ Temporary runner/result files removed from `docs/`

## 10. Final Conclusion

| Goal | Status |
|------|--------|
| Locate `HOSTB_HTTP_DISABLED` control | ✅ `.env` + `tour_search_service.php` |
| Enable Host B HTTP on staging | ✅ Via `.env` (temporary) |
| Live product data | ✅ Tokyo: 7070 tours, real titles/prices/dates in context & Gemini |
| Empty result handling | ✅ Osaka keyword / 火星 API: total 0 |
| Non-tour skip | ✅ 你好: no context |
| Sensitive data leak | ✅ None observed |
| Rollback | ✅ Complete |
| LINE OA five-message E2E | ⏳ **Pending operator** during enabled window on deployed router |

**Overall:** Stage 1-B-20 **successful** for live HTTP + Gemini grounding. Primary blocker from 1-B-19 (`HOSTB_HTTP_DISABLED`) is resolved by `GATEWAY_HOSTB_HTTP_ENABLED=true`. Secondary items: client timeout tuning, keyword extraction, optional LINE E2E on staging OA.

---

**Report path:** `docs/API_GATEWAY_MVP_STAGE_1B20_LIVE_HTTP_TEST_REPORT.md`
