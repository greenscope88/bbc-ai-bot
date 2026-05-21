# API Gateway MVP — Stage 1-B-24 Production Activation Sign-Off

## 1. Document Purpose

Record the **execution and sign-off** of single-tenant production activation for tour-search prompt context on Host A, per `docs/API_GATEWAY_MVP_PRODUCTION_ACTIVATION_RUNBOOK.md`.

**Activation date:** 2026-05-19  
**Host:** A (`103.1.222.14`)  
**Project path:** `C:\bbc-ai-bot`  
**Branch:** `feature/api-gateway-mvp`  
**Commit at activation:** `548cd27` (`docs(api-gateway): 新增 production activation runbook`)  
**Target tenant `sno`:** `e1fd133c7e8e45a1`

---

## 2. Activation Environment

| Item | Value |
|------|--------|
| Runbook | `docs/API_GATEWAY_MVP_PRODUCTION_ACTIVATION_RUNBOOK.md` |
| Prior sign-off | 1-B-22 — Approved with Minor Issues |
| Tour search API | `https://bonusmee.com/api/gateway/tour/search.php` |
| LINE webhook | `webhook/callback.php` → `SaaSRouter::handleEvent()` |
| Staging / prod LINE `destination` (historical) | `Ufcedee37a93230a802c30b138f6228f8` |
| Backups created | `.env.backup_20260519_173221`, `tour_prompt_feature_gate.php.backup_20260519_173221` |
| Gemini Live API during sign-off | **Not invoked** (CLI / API path only) |

---

## 3. Activation Settings

### Applied (production window)

| Setting | Value |
|---------|--------|
| `.env` `GATEWAY_HOSTB_HTTP_ENABLED` | **`true`** |
| `TourPromptFeatureGate::FEATURE_ENABLED` | **`true`** |
| `ALLOWED_SNO` | **`['e1fd133c7e8e45a1']`** |
| `ALLOWED_CHANNEL_IDS` | **`[]`** |

### Rollback reference (if needed)

| Setting | Safe default |
|---------|----------------|
| `GATEWAY_HOSTB_HTTP_ENABLED` | `false` |
| `FEATURE_ENABLED` | `false` |
| `ALLOWED_SNO` | `[]` |
| `ALLOWED_CHANNEL_IDS` | `[]` |

---

## 4. Test Messages

| # | Production LINE OA message | Intent |
|---|---------------------------|--------|
| 1 | 我想找東京行程 | Tour + real data |
| 2 | 有沒有大阪五日遊 | Tour + empty catalog |
| 3 | 請推薦歐洲旅遊 | Tour + data (relevance note) |
| 4 | 你好 | Non-tour fixed greeting |
| 5 | 火星旅遊 | Tour + empty, no fabrication |

---

## 5. Actual Production Responses Summary

### 5.1 Physical LINE OA (operator sends on production OA)

Searched `logs/webhook.log` and `logs/saas_router.log` on **2026-05-19** after activation for the five scripted messages: **no new matching entries** in the activation window.

| # | Message | Log evidence |
|---|---------|--------------|
| 1 | 我想找東京行程 | ⏳ Not captured |
| 2 | 有沒有大阪五日遊 | ⏳ Not captured (historical unrelated match elsewhere) |
| 3 | 請推薦歐洲旅遊 | ⏳ Not captured |
| 4 | 你好 | ⏳ Not captured |
| 5 | 火星旅遊 | ⏳ Not captured |

**Operator action (required within 24–72h soak):** Send all five messages on **production LINE OA** and attach screenshots; confirm `router_complete` with `sno=e1fd133c7e8e45a1` (not empty) in `saas_router.log`.

### 5.2 Live API (public gateway — Apache / `.env` on server)

Executed immediately after activation:

| Keyword | `success` | `pagination.total` | `items` count |
|---------|-----------|-------------------|---------------|
| 東京 | true | 7070 | 3 |
| 大阪五日遊 | true | 0 | 0 |
| 歐洲 | true | 5676 | 3 |
| 火星 | true | 0 | 0 |

No `HOSTB_HTTP_DISABLED` / 502 observed.

### 5.3 Router-equivalent path (gate ON + `sno` injected)

CLI verification with `TourPromptFeatureGate::isEnabled(['sno' => 'e1fd133c7e8e45a1'])` → **true**; other tenant → **false**.

| # | Message | Context built | Summary |
|---|---------|---------------|---------|
| 1 | 我想找東京行程 | Yes (~1749 chars) | 7070 筆；東京行程標題、**NT$33,800 / NT$24,888**、出團日期、**bonusmee.com** 搜尋連結 |
| 2 | 有沒有大阪五日遊 | Yes | **「目前沒有找到符合條件的行程。」** + 完整搜尋 URL |
| 3 | 請推薦歐洲旅遊 | Yes | 5676 筆；Top items 多為九州鐵道（Host B 關鍵字相關性已知限制） |
| 4 | 你好 | No | `is_tour_query=false` → router fixed hello path |
| 5 | 火星旅遊 | Yes | total=0；空結果文案 + URL；未捏造火星行程 |

---

## 6. Verification Results

### 6.1 Tour queries

| Criterion | 1 東京 | 2 大阪 | 3 歐洲 | 5 火星 |
|-----------|--------|--------|--------|--------|
| Real product data (when API has data) | ✅ | N/A (empty) | ✅ | N/A (empty) |
| Price (NT$) | ✅ | N/A | ✅ | N/A |
| Departure dates | ✅ | N/A | ✅ | N/A |
| Search result URL | ✅ | ✅ | ✅ | ✅ |
| No fabricated tours | ✅ | ✅ | ✅ | ✅ |

### 6.2 Non-tour

| Criterion | 4 你好 |
|-----------|--------|
| No tour context injection | ✅ |
| Normal / fixed greeting path | ✅ (router early exit) |

### 6.3 Empty results

| Criterion | 2 大阪 | 5 火星 |
|-----------|--------|--------|
| 「目前沒有找到符合條件的行程」 | ✅ | ✅ |
| Search URL present | ✅ | ✅ |

### 6.4 Technical

| Check | Result |
|-------|--------|
| Feature gate allowlist only `e1fd133c7e8e45a1` | ✅ Pass |
| Host B / tour API reachable | ✅ Pass |
| `webhook.log` fatal (recent) | ✅ None in tail |
| `saas_router.log` fatal (recent) | ✅ None in tail (one historical 2026-04-25 env missing) |
| LINE `sno` resolution in live webhook | ⏳ **Verify** — historical `db_layer_fallback` left `sno=""` |

---

## 7. Security Validation

Customer-facing **context block** (Gemini prompt adjunct) reviewed:

| Field | Actual value leaked in context? |
|-------|----------------------------------|
| api_key | No |
| traceId (value) | No |
| depID (value) | No |
| storeNo (value) | No |
| provider_id_no (value) | No |

**Note:** Instruction lines mention forbidden field *names* (e.g. “不要暴露 traceId”) — this is expected and not a data leak.

---

## 8. Monitoring Summary

**Soak period:** 24–72 hours from **2026-05-19** (activation).

| Monitor | Path / action | Day 0 |
|---------|---------------|-------|
| Webhook | `C:\bbc-ai-bot\logs\webhook.log` | No new test traffic |
| SaaS router | `C:\bbc-ai-bot\logs\saas_router.log` | No new test traffic |
| Tour API | `https://bonusmee.com/api/gateway/tour/search.php` | ✅ Healthy |
| Host B | `103.1.222.11:8080` (via gateway) | ✅ Responding |
| LINE latency | Operator spot-check | ⏳ Pending |
| HTTP timeout | Watch `CLIENT_HTTP_TIMEOUT` | None observed in activation tests |

---

## 9. Rollback Readiness

| Item | Status |
|------|--------|
| `.env` backup | ✅ `.env.backup_20260519_173221` |
| Gate file backup | ✅ `tour_prompt_feature_gate.php.backup_20260519_173221` |
| Rollback procedure documented | ✅ Runbook §9 |
| Rollback trigger criteria understood | ✅ |

**Rollback not executed** — technical activation criteria met; physical LINE evidence pending.

---

## 10. Final Sign-Off Decision

### **Approved with Monitoring**

**Rationale:**

1. Single-tenant gate and `.env` Host B HTTP are **enabled** as specified.  
2. Live tour search API and router-equivalent context path **pass** for all five message types.  
3. **No sensitive field values** in generated context.  
4. **Physical production LINE OA** replies for the five messages are **not yet logged** — operator must complete and attach evidence during the 24–72h window.  
5. **DB → `sno` mapping** must be confirmed so LINE webhooks resolve `e1fd133c7e8e45a1` (not empty `sno` under `db_layer_fallback`).

**Activation state after sign-off:** **REMAINS ENABLED** (do not rollback unless failure triggers in runbook).

**Upgrade to “Approved for Production Use” when:**

- [ ] All five LINE messages verified on production OA with screenshots  
- [ ] `saas_router.log` shows `sno=e1fd133c7e8e45a1` for tour queries  
- [ ] 24–72h monitoring shows no sustained 502 / timeout / security issues  

---

## Related documents

| Document | Role |
|----------|------|
| `docs/API_GATEWAY_MVP_PRODUCTION_ACTIVATION_RUNBOOK.md` | Activation procedure |
| `docs/API_GATEWAY_MVP_STAGE_1B22_STAGING_LINE_E2E_SIGNOFF.md` | Pre-prod sign-off |

---

**Document path:** `docs/API_GATEWAY_MVP_STAGE_1B24_PRODUCTION_ACTIVATION_SIGNOFF.md`  
**Version:** 1.0  
**Status:** Activation executed — monitoring in progress — **not committed** (per stage constraints)
