# Tenant Service Limits DB Change Request Draft

## 1. Status

- **Status:** Draft / Not approved
- **Source SQL draft:** `db/tenant_service_limits.sql` (untracked; local only)
- This document is a governance draft only.
- **No SQL has been executed** in connection with this change request.
- **No SQL Server connection has been made** for the purpose of applying this draft.
- **No production database has been modified** by this document or the draft SQL file.
- The root-level `db/tenant_service_limits.sql` file **must not be committed directly** as a governed migration or as a substitute for SQL Safe Migration 5.x process.

## 2. Background

- `db/tenant_service_limits.sql` appears to be a **local SQL draft** (root-level, untracked).
- It contains **conditional DDL** (`IF NOT EXISTS` guards before object creation).
- It appears to **create** `dbo.tenant_service_limits` with columns including tenant/service-related fields (e.g. `sno`, `service_name`, `is_supported`).
- It appears to **create** a nonclustered index named `IX_tenant_service_limits_sno_service` on `(sno, service_name, updated_at DESC)`.
- The draft is **related to tenant / sno / service limit** governance (per file comments and object names).
- It currently **does not include** proposal metadata, approval metadata, a written rollback plan, backup record references, schema snapshot references, or audit log entries inside the SQL file.

*(Full SQL text is intentionally not reproduced here.)*

## 3. Proposed Business Purpose To Confirm

The following must be confirmed with product / platform owners before any formal migration:

- What is the business purpose of `tenant_service_limits`?
- Which tenants / `sno` values will use this table?
- What services should be represented by `service_name` (enumerated list, naming convention)?
- What does `is_supported` mean in production (feature flag, SLA, routing, billing)?
- Are limits **global**, **per tenant**, **per service**, or **per tenant–service pair**?
- Who owns updates to this table (operations, engineering, tenant admin)?
- Is this consumed by Host A AI gateway, Host B API, LINE OA flow, SaaS admin tools, or other systems?
- Does this affect billing, service availability, API routing, or permission control?

## 4. Required Inputs Before Formal Migration

Before any governed execution:

- Latest **schema_only** snapshot (or equivalent) for target environment
- **Current database backup policy** confirmation and evidence of backup before change
- **Table / index existence check** (avoid duplicate objects; align with `IF NOT EXISTS` semantics)
- **Tenant / sno isolation rule** documented and reviewed
- **Risk level** assigned (per SQL Safe Migration 5.x)
- **Approval owner** identified
- **Rollback plan** (written, reviewed, approved)
- **Before** schema snapshot
- **After** schema snapshot
- **Diff report**
- **Audit log** entry for the change

## 5. Required SQL Safe Migration 5.x Governance

Future formalization **must** follow:

- **DB Change Request** (this document evolves into an approved request)
- **proposal JSON** (environment, risk, affected systems, approvals as required)
- **risk_checker**
- **db_connection_guard**
- **tenant_sno_checker** (given `sno` / tenant semantics)
- **preflight_orchestrator**
- **approval_hash_guard**
- **plan_report_generator**
- **Governed migration SQL** under an appropriate `db/migrations/v5` governed path (not root-level `db/`)
- **No direct execution** from root-level `db/tenant_service_limits.sql` as the authoritative migration artifact

## 6. Draft Risk Assessment

Preliminary risks (draft — not a signed-off risk register):

- **DDL change** (structural impact)
- **Creates a new table** (`dbo.tenant_service_limits`)
- **Creates a new index** (`IX_tenant_service_limits_sno_service`)
- **Tenant / sno related** — scope and data isolation must be validated
- **No DML** detected in the draft file (INSERT/UPDATE/DELETE/MERGE)
- **No DROP / TRUNCATE** detected in the draft file
- **Requires review** before production
- **Must not** be auto-executed without governance gates

## 7. Rollback Plan Required

- A **rollback plan is not yet defined** in this draft.
- Formal rollback must state whether the **index** may be dropped and under what conditions.
- Formal rollback must state whether the **table** may be dropped and under what conditions.
- Formal rollback must consider **data retention** if production data is inserted after go-live (drop may be unacceptable).

## 8. Current Decision

- **Do not** `git add db/tenant_service_limits.sql`.
- **Do not** treat ignoring `db/tenant_service_limits.sql` in `.gitignore` as the final resolution without an approved change path.
- **Keep** `db/tenant_service_limits.sql` as a **local draft** until this change request is reviewed and a governed migration is prepared.
- **Convert** to governed migration **only after** schema compatibility and business purpose are confirmed.
- **No Phase 3 work should depend** on this table existing in production until the DB Change Request is **approved** and the migration is **executed** under governance.

## 9. Safety Statement

- This file is **documentation only**.
- It **does not execute SQL**.
- It **does not connect** to SQL Server.
- It **does not modify** production databases.
- It **does not read** `.env`.
- It **does not create** Execute Mode.
- **SQL Migration 4.5** official execution flow **remains unchanged** by this draft document.
