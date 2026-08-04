# BBC Tenant Update — Admin Apply Contract

## Apply chain

`bds/admin.php` → `BbcAdminAuthority` → `TenantAdminPreviewService` → `TenantAdminApplyService` → `TenantAdminAuthorityWriter`

- Single Apply Owner: `TenantAdminApplyService`
- Single Writer: `TenantAdminAuthorityWriter`
- No Admin CLI, shell executor, or second writer

## Operations

- `create_tenant` — Disabled Bundle (`status=disabled`, activation features false, BDS `enabled=false`)
- `update_line_oa` — mutable LINE fields only (`line_channel_id`, `display_name`, `profile.company_name`)
- `enable_bds_upload` — BDS `enabled=true` only
- `enter_line_validation` — `status=staging` + activation features
- `finalize_tenant_production` — `status=enabled` only after Evidence PASS
- `deactivate_tenant_production` — `status=disabled` + activation features false

## Activation Authority

Production membership for Phase9C1 / AIU / Grounding:

`status in {staging, enabled} AND features.{bats_runtime|aiu_authoritative|grounding_authoritative}`

Equivalence initials: travel_a OFF/OFF/OFF, travel_b ON/ON/ON, travel_d ON/ON/OFF, travel_c OFF/OFF/OFF.

## Security

- Admin: Session `storeNo` → `encryptStr` → wire sno allowlist in `config/bbc_admin_authority.php`
- CSRF: independent `bds_admin_csrf`
- Preview plan server-side only; Apply accepts preview id + CSRF
- Secrets/tokens never in form, preview, or audit; status only: configured / missing / rotation_required
- Immutable Identity rejected on update payloads

## Create Identity Authority (Composite Store + Host B Contract)

Single server-side entry: `TenantAdminStoreAuthorityLookup` (web adapter: `bds/BbcTenantStoreIdentityAuthorityAdapter.php`).

Owner Create inputs only: **Tenant sno** (lookup key) + **LINE Webhook Destination（U...）**.

| Field | Authority | Form input |
|-------|-----------|------------|
| lookup key | Owner sno → server reverse decrypt and/or Management-Scope `Store_List` + `encryptStr` exact unique match | sno (lookup only; never trusted write) |
| `depID` | Management Scope `constant('_depID')` | Forbidden |
| `storeNo` / `store_uid` | Matched Store row | Forbidden |
| `sno` | Re-derived wire sno (must equal Owner lookup sno) | Forbidden as write authority |
| `provider_id_no` | **SYSTEM DERIVED — EXISTING HOST B CONTRACT PROVEN** (`0`) | Forbidden |
| `tenant_key` / `credential_env_prefix` | `travel_` + lowercase(full sno); new tenants only; existing `travel_a/b/c/d` unchanged | Forbidden |
| `display_name` / `company_name` | Store `storeName` | Forbidden |

Resolve issues server selection token + Identity fingerprint. Preview submits only `selection_token` + `line_channel_id` + CSRF. Preview/Apply re-run sno Lookup and reject fingerprint drift (zero write). Credential status uses BBC `bootstrap.php` + `LineCredentialResolver` (status only).
