# SQL Safe Migration 5.0 - 第二階段（腳本實作版）

本文件定義 SQL Safe Migration 5.0 第二階段的實作邊界與 Step 1-A 範圍。

## 階段定位

- 第二階段是 **5.0 腳本實作版**。
- 第二階段 Step 1 只建立 **Plan Mode / Dry-run** 腳本能力。

## 強制限制

- 本階段不得執行 SQL。
- 本階段不得修改正式 DB。
- 本階段不得連線主機 B 做 SQL schema 變更。
- 本階段不得建立 Execute Mode。

## 允許的腳本能力（plan-only）

- 只允許讀取 proposal JSON。
- 只允許分析風險。
- 只允許產生 Plan Report。
- 只允許計算 hash。

## 後續規劃邊界

- Execute Mode 必須等第三階段測試通過後才可規劃。
- 所有腳本預設為 **plan-only / dry-run**。

---

## Phase 2 Current Status

- Phase 2 is the SQL Safe Migration 5.0 script implementation phase.
- Current mode: Plan Mode / Dry-run only.
- No Execute Mode exists in Phase 2.
- No SQL Server connection is allowed.
- No SQL execution is allowed.
- No production database modification is allowed.
- No .env reading is allowed.
- SQL Migration 4.5 official execution flow remains unchanged.

## Completed Step 2 Items

- Step 2-A: Proposal test cases
- Step 2-B: Checker dry-run validation
- Step 2-C: proposal_checker rule hardening
- Step 2-D: risk_checker underestimation detection
- Step 2-E: DB Connection Guard
- Step 2-F: Tenant / sno Checker
- Step 2-G: Preflight Orchestrator
- Step 2-H: Approval / Hash Guard
- Step 2-I: Plan Report Generator integration
- Step 2-J-1: Phase 2 read-only audit

## Core Scripts

- proposal_checker.ps1
- risk_checker.ps1
- plan_report_generator.ps1
- hash_calculator.ps1
- db_connection_guard.ps1
- tenant_sno_checker.ps1
- preflight_orchestrator.ps1
- approval_hash_guard.ps1

## Safety Boundary

- These scripts must not connect to SQL Server.
- These scripts must not execute SQL.
- These scripts must not modify any production database.
- These scripts must not read .env.
- These scripts must not create Execute Mode.
- These scripts must not generate migration SQL.
- These scripts are for governance, validation, report generation, and hash verification only.

## Git Governance Reminder

- Do not use git add .
- Do not add db/schema.sql.
- Do not add db/schema.json.
- Do not add db/sync_schema.ps1.
- Do not add db/memory.lock.json.
- Do not add db/tables.md.
- Do not add db/tenant_service_limits.sql.
- Use precise git add only.
