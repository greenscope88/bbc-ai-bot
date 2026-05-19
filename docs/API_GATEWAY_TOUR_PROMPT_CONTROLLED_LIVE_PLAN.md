# API Gateway Tour Prompt — Controlled Live Plan

## 1. Document Purpose

This document defines how to safely enable **tour search prompt context injection** for Gemini in the LINE SaaS router, using a code-level feature gate with tenant and optional channel allowlists. It supports **Controlled Live** rollout and a clear **rollback** path without changing `.env` or database schema in Stage 1-B-18.

## 2. Current Status

| Item | Status |
|------|--------|
| `TourQueryIntentDetector` | Implemented |
| `TourSearchApiClient` | Implemented |
| `GeminiTourContextBuilder` | Implemented |
| `AiPromptBuilder::appendTourContext()` | Implemented |
| `TourPromptContextService` | Implemented |
| `saas_router.php` wiring | Implemented (gated) |
| `TourPromptFeatureGate` | Implemented (Stage 1-B-18) |
| Production enablement | **OFF** — `FEATURE_ENABLED = false`, empty allowlists |

Latest related commit (before 1-B-18 commit): `c1123fb` — feature flag wiring in `saas_router.php`.

## 3. Feature Gate Design

**Class:** `core/tour_prompt_feature_gate.php` — `TourPromptFeatureGate`

**Entry point:** `TourPromptFeatureGate::isEnabled(array $context): bool`

**Context keys:**

| Key | Source in router |
|-----|------------------|
| `sno` | `$tenant['sno']` from `TenantResolver` |
| `channelId` | `$tenant['channel_id']` or LINE `destination` |
| `userId` | Reserved for future use |

**Constants (edit in code only — no `.env` in this stage):**

```php
private const FEATURE_ENABLED = false;
private const ALLOWED_SNO = [];
private const ALLOWED_CHANNEL_IDS = [];
```

All checks are fail-closed: any mismatch returns `false`. Exceptions are caught and treated as `false`.

## 4. Tenant Allowlist Design

- `ALLOWED_SNO` is a **strict allowlist** of tenant `sno` strings.
- If `ALLOWED_SNO` is **empty**, the feature is **never** enabled (even when `FEATURE_ENABLED = true`).
- Only listed tenants may receive tour search context in the Gemini prompt.

## 5. Optional Channel Allowlist

- If `ALLOWED_CHANNEL_IDS` is **empty**, channel is **not** checked (sno-only gate).
- If `ALLOWED_CHANNEL_IDS` is **non-empty**, `channelId` must be present and listed.
- Use this to limit activation to a **staging LINE channel** while testing a tenant `sno`.

## 6. Controlled Live Activation Steps

1. Obtain explicit approval for Controlled Live on staging.
2. Edit `core/tour_prompt_feature_gate.php`:
   - Set `FEATURE_ENABLED = true` (use `true` literal, not string).
   - Add staging `sno` to `ALLOWED_SNO`, e.g. `'e1fd133c7e8e45a1'`.
   - Optionally add staging LINE channel id to `ALLOWED_CHANNEL_IDS`.
3. Deploy / redeploy application code to Host A (no `.env` change required for gate).
4. Send test messages on the **staging LINE channel** only (tour queries and non-tour queries).
5. Verify AI replies reference real search results and include `search_url` when applicable.
6. Review logs for errors; confirm no sensitive fields in customer-visible replies.
7. If stable, expand `ALLOWED_SNO` incrementally — never enable globally without allowlist.

## 7. Verification Checklist

- [ ] `FEATURE_ENABLED` is `true` only on approved environments
- [ ] `ALLOWED_SNO` contains only approved tenant(s)
- [ ] Non-allowlisted `sno` does not receive tour context
- [ ] Non-tour messages (e.g. greeting) behave as before
- [ ] Tour queries return grounded recommendations (no fabricated tours)
- [ ] `search_url` present when API returns it
- [ ] No `api_key`, `traceId`, `depID`, `storeNo`, `provider_id_no` in LINE replies
- [ ] CLI tests pass: `test_tour_prompt_feature_gate.php`, `test_tour_prompt_context_service.php`
- [ ] Rollback drill completed once in staging

## 8. Rollback Plan

1. Set `FEATURE_ENABLED = false` in `tour_prompt_feature_gate.php`.
2. Optionally clear `ALLOWED_SNO` and `ALLOWED_CHANNEL_IDS`.
3. Redeploy.
4. Confirm `TourPromptFeatureGate::isEnabled()` returns `false` for all contexts.
5. Send smoke messages on LINE — behavior must match pre-enablement (no tour context block in prompt).
6. No database rollback required for gate-only changes.

## 9. Production Safety Principles

- **Default OFF** — shipped code must not enable the feature without code edits and review.
- **Allowlist required** — empty `ALLOWED_SNO` means zero tenants enabled.
- **Fail closed** — errors, missing `sno`, or missing `channelId` (when channel gate active) → disabled.
- **No secrets in code** — do not hardcode API keys; use existing config for Host B when live HTTP is used.
- **No full traffic** until staging Controlled Live sign-off.
- **Do not modify** `.env`, webhook entry, or Apache for gate rollout unless separately approved.

## 10. Recommended First Staging Sno Test

| Field | Recommended value |
|-------|-------------------|
| Staging `sno` | `e1fd133c7e8e45a1` (see `config/tenant_context_map.php`) |
| Gateway tour search API | `https://bonusmee.com/api/gateway/tour/search.php` |
| Sample user messages | `我想找東京行程`, `有沒有富國島行程`, `你好` (negative) |

**Suggested first gate config (staging only, after approval):**

```php
private const FEATURE_ENABLED = true;
private const ALLOWED_SNO = ['e1fd133c7e8e45a1'];
private const ALLOWED_CHANNEL_IDS = []; // or ['<staging-line-channel-id>']
```

Run CLI dry-run tests before LINE tests:

```text
php tests/test_tour_prompt_feature_gate.php
php tests/test_tour_gemini_context_dry_run.php
php tests/test_tour_prompt_context_integration_dry_run.php
```
