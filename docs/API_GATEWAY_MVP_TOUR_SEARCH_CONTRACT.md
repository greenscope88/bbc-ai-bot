# API Gateway MVP — `tour.search` Search Contract

**Project root:** `C:\bbc-ai-bot`  
**Document version:** 2026-05-16  
**Type:** Search contract (documentation only). **No code changes** in this delivery.

**Revision 2026-05-15:** Aligned **Host A → Host B** `tour.search` request rules with **confirmed Host B API behavior** (query allowlist, `sno` + TenantResolver, **no** internal tenant fields in query, **`x-api-key`** authentication). Implementation (PHP) may still lag this contract until a separate change lands.

**Revision 2026-05-16:** Formalized **Host B internal API key** sourcing (**`GATEWAY_HOSTB_API_KEY`**), **logging/audit prohibitions** on full key material, and **NO-GO** for staging smoke when the internal key is missing (documentation only).

**Related:** `config/api_gateway_services.php`, `docs/API_GATEWAY_MVP_LAUNCH_PLAN.md`, `docs/API_GATEWAY_PHASE3_HOST_B_PROXY_DESIGN.md`.

---

## 1. Document Purpose

This document defines the **Search Contract** for the MVP gateway service **`tour.search`**, which maps to Host B **`/api/tour/search`**. It exists to ensure:

1. Search filters and scope **align with the existing public storefront tour-date search page** (see Section 2).  
2. **No AI component** may invent, guess, or emit **SQL**, **table names**, or **column names**.  
3. All client-acceptable inputs are a **strict allowlist**; everything else is rejected or stripped at **Host A**.  
4. **Host A ↔ Host B** use a **frozen** request/response shape for MVP.  
5. **Gemini** is limited to **intent**, **keyword extraction**, and **answer shaping** from Host B results — **not** data access logic.

---

## 2. Existing Frontend Search Page

**Reference URL (storefront tour / date search):**  
[https://bonusmee.com/view/cloud/cloud_store_tourdate.php?openExternalBrowser=1&clearParam=Y&sno=e1fd133c7e8e45a1](https://bonusmee.com/view/cloud/cloud_store_tourdate.php?openExternalBrowser=1&clearParam=Y&sno=e1fd133c7e8e45a1)

**MVP alignment rule:** The effective search **scope, sort order, date/tour filters, and pagination semantics** exposed through **`tour.search`** MUST match what a user can achieve on **`cloud_store_tourdate.php`** for the same tenant, **after** tenant resolution.

**Repository note:** `cloud_store_tourdate.php` is **not** in this repo. Engineering MUST perform a **parameter-by-parameter diff** against the deployed PHP source (or a frozen export) and update this contract’s allowlist (Section 5) accordingly before marking MVP “contract complete.”

---

## 3. MVP API Endpoint

| Role | Value |
|------|--------|
| **Gateway service id** | `tour.search` |
| **Host B route (MVP)** | `GET /api/tour/search` |
| **Host B base URL (staging / current)** | `http://103.1.222.11:8080` |
| **Full URL (MVP reference)** | `http://103.1.222.11:8080/api/tour/search` |

**Production note:** Move to **HTTPS** and approved **network ACL** before broad production use; URL here documents MVP alignment only.

---

## 4. Search Scope Alignment

| Topic | Rule |
|-------|------|
| **Parity target** | Same **business tour/date catalog** and **filtering intent** as `cloud_store_tourdate.php` (not a superset of arbitrary SQL power). |
| **Tenant boundary** | On **Host A**, tenant scope is enforced using **internal tenant context** after mapping (Section 6). On **Host B**, tenant scope for `GET /api/tour/search` is driven by **`sno` in the upstream query** (Host B **TenantResolver**); Host A MUST NOT broaden scope via spoofed internal IDs in the Host B query. |
| **Sorting** | Default sort MUST match storefront default unless a **whitelist** sort option is explicitly added to this contract. |
| **Pagination** | Use **whitelist** paging fields only (e.g. `page`, `pageSize`); caps enforced server-side on Host A and/or Host B. |

---

## 5. Allowed Query Parameters (Client → Host A)

Only the following **may** be accepted from the external client (LINE / gateway consumer). Names and types MUST be validated; unknown query keys are **dropped** or the request is **rejected** (product decision — default **reject** for MVP security).

| Parameter | Type | Description | Notes |
|-----------|------|-------------|--------|
| `sno` | string | Public tenant / store identifier | Used on Host A for **Tenant Mapping**; forwarded to Host B **query** as the authoritative tenant key for **TenantResolver** (see Section 6). |
| `keyword` | string | Free-text search keyword | Max length TBD; normalize Unicode spaces |
| `page` | int | 1-based page index | Min 1; max TBD |
| `pageSize` | int | Page size | Min 1; max TBD |
| *(optional)* `sort` | string enum | Only if storefront exposes equivalent | **Must** be mapped to a fixed enum on Host B; **no raw** sort strings |

**Until storefront diff is done:** Treat **`sno`**, **`keyword`, `page`, `pageSize`** as the **provisional** MVP set (consistent with `docs/API_GATEWAY_PHASE3_HOST_B_PROXY_DESIGN.md` where applicable). Add/remove fields **only** after verifying `cloud_store_tourdate.php` (Section 14).

---

## 6. Tenant Resolution and `tenantContext` (Host A vs Host B)

### 6.1 Client → Host A

**Public tenant key (client-visible):**

| Parameter | Source | Description |
|-----------|--------|-------------|
| `sno` | Client (or LINE context mapped by Host A) | **Public** tenant identifier used for **Tenant Mapping** and authorization on Host A |

### 6.2 Internal `tenantContext` (Host A only — MUST NOT appear in Host B query)

After **Tenant Mapping**, Host A holds **internal tenant context** (for example `depID`, `storeNo`, `store_uid`, `provider_id_no`, and related fields). Per **confirmed Host B rules**:

| Field | Host A usage | Host B `GET /api/tour/search` query |
|-------|----------------|-------------------------------------|
| `depID` | Validation, audit, trace, rate limits, permission checks | **MUST NOT** be sent. If present in query, Host B returns **400**. |
| `storeNo` | Same as above | **MUST NOT** be sent. |
| `store_uid` | Same as above | **MUST NOT** be sent. |
| `provider_id_no` | Same as above | **MUST NOT** be sent. |

**Normative rule:** `tenantContext` (internal tenant fields) is **only** for Host A **validation**, **audit**, **trace**, and related gateway logic. It **must not** be injected into the **Host B query string** for `/api/tour/search`.

### 6.3 Host A → Host B tenant scoping

| Parameter | In Host B query? | Description |
|-----------|------------------|-------------|
| **`sno`** | **Yes** (when calling Host B) | Host B accepts **`sno` in the query** and resolves the tenant via **TenantResolver**. Host A MUST inject the **authoritative** `sno` (derived from authenticated / mapped client context), not trust parallel client overrides that violate gateway policy. |

**Dry-run placeholders:** Values such as **`D001` / `S001` / `U001` / `P001`** used in **MVP dry-run tests** are **not** valid Host B tenant probes. A **real staging HTTP smoke** MUST use a **real staging `sno`** agreed with operations and MUST **not** place those placeholder internal IDs (nor any internal tenant fields) on the Host B query.

---

## 7. Field Whitelist Strategy

1. **Gateway allowlist** — Host A accepts **only** Section 5 parameters from the client (plus **`sno`** for tenant identification at the gateway boundary per product rules).  
2. **Upstream allowlist (Host B query)** — Host A forwards **only**: **(a)** allowlisted search/paging params (`keyword`, `page`, `pageSize`, optional `sort` when contract-complete), **(b)** **`sno`** for Host B **TenantResolver**, and **(c)** any **explicitly agreed** non-sensitive trace parameters. Host A **must not** add `depID`, `storeNo`, `store_uid`, or `provider_id_no` to the Host B query.  
3. **Internal context** — `tenantContext` remains on Host A for **validation**, **audit**, and **trace**; it is **not** copied into Host B query params for `/api/tour/search`.  
4. **Host B allowlist** — Host B binds **only** known DTO/query properties; **`depID` in query → 400** per Host B implementation.  
5. **No dynamic SQL fragments** from any string that originated on the client or from Gemini.  
6. **Single mapping** — `tour.search` maps **exactly** to `GET /api/tour/search` per `config/api_gateway_services.php` (MVP).

---

## 8. Parameters That Must Not Be Accepted From Client

The client MUST **NOT** be able to set (even if spoofed):

- `depID`, `storeNo`, `store_uid`, `provider_id_no`  
- Any **SQL** or **filter expression**: e.g. `where`, `orderBy` with raw SQL, `sql`, `query`, `table`, `column`, `join`, `select`, `from`  
- Arbitrary **Host B path** or **host** override  
- **Opaque identifiers** that would broaden tenant scope (unless explicitly allowlisted and validated)  
- **File paths**, **template names**, or **debug** flags not in the allowlist  

---

## 9. SQL Safety Rules

1. **No AI-generated SQL.** Gemini (or any LLM) MUST NOT output SQL strings, table names, or column lists for execution.  
2. **No client-influenced SQL structure.** Clients cannot send predicates beyond the allowlist; those predicates map to **parameterized** queries inside Host B **only** through fixed code paths.  
3. **Parameterized queries only** on Host B for any user-derived values (`keyword`, dates if added, etc.).  
4. **Defense in depth:** Host A strips/forbids dangerous patterns; Host B repeats validation on DTO binding.  
5. **Schema knowledge** for implementation lives in **Host B code + DBA-reviewed queries**, not in prompts or gateway logs.

---

## 10. Host A to Host B Request Contract

**Transport:** `GET /api/tour/search` (per MVP config).

### 10.1 Authentication (Host A → Host B)

| Requirement | Rule |
|-------------|------|
| **Dedicated internal credential** | Host A → Host B **`GET /api/tour/search`** MUST use a **dedicated internal Host B API key** (per environment: staging vs production). This key is **only** for server-to-server trust toward Host B and is **distinct** from the **client → Host A** gateway admission key (`X-BBC-API-Key`). |
| **Header name** | Host B expects the key in HTTP header **`x-api-key`**. Implementations MAY send **`X-API-Key`**; HTTP header field names are **case-insensitive**. |
| **Configuration source (normative)** | The internal Host B API key value MUST be loaded from **staging/production secret store** or **environment variable** at deploy/runtime. It MUST **NOT** be hardcoded as a literal in application source, committed samples, or documentation examples beyond placeholders such as `<internal-host-b-api-key>`. |
| **Suggested environment variable** | **`GATEWAY_HOSTB_API_KEY`** — recommended name for the internal Host B API key on Host A (align `config/config.php` / deployment templates in a **separate code change** when implementing). |
| **Do not forward gateway client key** | Host A MUST **not** forward **`X-BBC-API-Key`** (the **client → Host A** gateway API key) to Host B. That key is for **gateway admission only** (see `docs/API_GATEWAY_PHASE3_API_KEY_VERIFICATION_DESIGN.md` and `docs/API_GATEWAY_PHASE3_HOST_B_PROXY_DESIGN.md`). |
| **Logging and audit** | **Access logs, application logs, audit payloads, and support dumps MUST NOT contain the full internal API key.** Use redaction, omit the header value, or store only a **non-reversible** prefix/fingerprint if an identifier for operations is required — follow gateway audit field whitelist / redactor policies. |

### 10.2 Staging smoke GO / NO-GO (internal key)

| Gate | Rule |
|------|------|
| **NO-GO** | If **`GATEWAY_HOSTB_API_KEY`** is **missing, empty, or placeholder-only** in the staging profile intended for real HTTP, the **staging HTTP smoke is NO-GO** until the secret is provisioned and verified out-of-band. A missing key MUST NOT be “worked around” by reusing **`X-BBC-API-Key`** or hardcoding. |
| **GO (prerequisites)** | Real staging smoke requires, at minimum: **`GATEWAY_HOSTB_BASE_URL`**, **`GATEWAY_HOSTB_API_KEY`**, and **`GATEWAY_HOSTB_HTTP_ENABLED=true`** on **staging only**, plus contract-correct query (`sno` + allowlist) — see `docs/API_GATEWAY_MVP_STAGING_HTTP_SMOKE_TEST_PLAN.md`. |
| **Production** | **`GATEWAY_HOSTB_HTTP_ENABLED=false`** remains the default on **production** under this MVP contract **unless** a **separate** production go-live decision and document explicitly approve outbound Host B HTTP and internal key operations. |

### 10.3 Query string (Host B `/api/tour/search`)

| Allowed on Host B query (MVP) | Forbidden on Host B query (confirmed) |
|-------------------------------|----------------------------------------|
| **`sno`** — Host B **TenantResolver** resolves tenant from this value. | **`depID`** — if present, Host B returns **400**. |
| **`keyword`**, **`page`**, **`pageSize`** (and optional **`sort`** once storefront-aligned). | **`storeNo`**, **`store_uid`**, **`provider_id_no`** — MUST NOT be sent by Host A on this route’s query. |

**Normative shape:**

| Part | Content |
|------|---------|
| **Query** | **`sno`** (authoritative, server-chosen after Host A tenant mapping / auth) + allowlisted search/paging fields (**`keyword`**, **`page`**, **`pageSize`**, optional **`sort`**). |
| **Headers** | **`x-api-key`** (or **`X-API-Key`**): value from **`GATEWAY_HOSTB_API_KEY`** (or equivalent secret injection); **`traceId`** / correlation — **prefer header** for trace if agreed with Host B (exact header name per Host B OpenAPI when frozen). |
| **Forbidden in query** | **`depID`**, **`storeNo`**, **`store_uid`**, **`provider_id_no`**, and any internal-only tenant fields. |

**Example (illustrative):**

```http
GET /api/tour/search?sno=<authoritative-staging-sno>&keyword=東京&page=1&pageSize=20
x-api-key: <internal-host-b-api-key>
X-Trace-Id: <uuid>
```

**Host A responsibilities:** Tenant Mapping, **gateway** API Key verification, service permission (`tour.search` only), **whitelist filtering**, **service routing**, attach **internal** `x-api-key` for Host B, inject **`sno`** for Host B TenantResolver, trace propagation, **no** forwarding of LINE secrets or **`X-BBC-API-Key`** to Host B.

---

## 11. Host B Response Contract

**Goals:** Stable JSON for Gateway + Gemini; no internal stack/SQL/connection strings.

**Suggested MVP envelope (to be frozen with Host B team):**

| Field | Type | Description |
|-------|------|-------------|
| `success` | bool | Operation succeeded |
| `traceId` | string | Echo gateway trace id |
| `items` | array | Tour search hits (each object from a **fixed DTO**; no arbitrary keys) |
| `page` | int | Current page |
| `pageSize` | int | Page size |
| `totalCount` | int | Total hits (if supported) |
| `errorCode` | string? | Present on failure |
| `message` | string? | Safe user-facing or operator-facing message |

**Item object:** Only whitelisted display fields (e.g. tour code, title, date range, price, URL slug) — **exact list** is an **Open Question** until aligned with storefront cards (Section 14).

---

## 12. Gemini Usage Boundary

### Gemini MAY produce

- **User intent** classification (e.g. “tour search” vs small talk)  
- **Normalized `keyword`** candidate(s) within length limits (Host A still validates allowlist)  
- **Natural language summary** of **Host B JSON results** for LINE reply  
- **Clarifying questions** when results empty (without inventing inventory)

### Gemini MUST NOT

- Emit **SQL**, **table**, **column**, **JOIN**, or **store procedure** names  
- Invent **inventory** or **dates** not present in Host B payload  
- Request or set **internal tenant IDs**  
- Expand scope beyond what Host B returned for the **authenticated** tenant

**Orchestration:** Host A calls Host B **first** (or in a fixed pipeline); Gemini consumes **structured** results — not the database.

---

## 13. Test Cases

| ID | Scenario | Expectation |
|----|-----------|-------------|
| T1 | Valid **`sno`** on Host B query + internal **`x-api-key`** + `keyword` | 200; results tenant-scoped per Host B TenantResolver; trace correlatable |
| T2 | Client attempts `depID` / `storeNo` / `store_uid` / `provider_id_no` toward Host B | Host A MUST NOT forward these in Host B query; if wrongly sent, Host B errors (**400** for `depID` per Host B spec) |
| T3 | Client sends `table=users` or `where=1=1` | Rejected |
| T4 | Oversized `keyword` / `pageSize` | Rejected or clamped per policy |
| T5 | Unknown query key `foo=bar` | Rejected (recommended) or stripped with audit log |
| T6 | Host B returns 500 with SQL detail | Gateway normalizer removes internals; safe `errorCode` |
| T7 | Gemini receives only Host B JSON | Output contains no SQL; references only returned fields |
| T8 | Cross-tenant: wrong key for `sno` | 401/403; no data leak |

---

## 14. Open Questions

1. **Exact query parameter set** for `cloud_store_tourdate.php` (GET/POST names, date range fields, category filters) — requires source review.  
2. **Maximum** `keyword` length and allowed character set (CJK, symbols).  
3. **Sort options** exposed on storefront and their safe enum mapping on Host B.  
4. **Item DTO fields** for `items[]` — parity with storefront card layout.  
5. **Defense in depth beyond `x-api-key`** — optional **mTLS**, **IP allowlist**, key rotation cadence, and header-only trace names — finalize with Host B OpenAPI + security review.  
6. **POST vs GET** for `/api/tour/search` if keyword length exceeds URL limits (MVP currently `GET` in `config/api_gateway_services.php` — revisit if needed).  
7. **Rate limiting** policy when MVP enables public traffic (see MVP launch plan).

---

## 15. Final Recommendation

1. **Freeze** the Host B **OpenAPI/Swagger** for `GET /api/tour/search` and the **response DTO** before enabling production traffic.  
2. Complete the **storefront diff** (Section 2 + 14) and **update Section 5** in this document as the single allowlist source of truth.  
3. Implement Host B with **DTO binding + parameterized SQL only**; pair with Host A **strict allowlist** proxy.  
4. Keep **Gemini** strictly in the **post-JSON** summarization role; log and test for **SQL injection in model output** (should never execute — still monitor).  
5. Proceed to **MVP Implementation Step 3+**: wire `config/api_gateway_services.php` + this contract into the gateway request builder and add **contract tests** against a **mock Host B** before hitting staging IP.

---

## Change Statement

| Item | Status |
|------|--------|
| Files updated (2026-05-16) | `docs/API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md` — internal Host B key via **`GATEWAY_HOSTB_API_KEY`**, **`x-api-key` / `X-API-Key`**, no **`X-BBC-API-Key`** to Host B, log/audit ban on full key, staging smoke **NO-GO** without key |
| PHP / ASP.NET / GatewayKernel / ServiceRegistry / HostBServiceEndpointMap / `.env` / servers | **Not modified** by this revision |
| SQL / Git | **Not executed** |

**File path:** `C:\bbc-ai-bot\docs\API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md`
