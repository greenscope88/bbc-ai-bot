# SQL Safe Migration 5.0 Phase 4 Implementation Plan

> Scope: **planning only**. This document does not enable Phase 4 execution, does not create Execute Mode, does not run SQL, and does not modify any production database.

## 1. Phase 4 目標

- 將 Phase 3/Pre-Phase 4 的治理設計落地為「可審核、可重現、可拒絕」的 **Phase 4 實作計畫**（Implementation Plan）。
- 建立一條正式的 **Governed Execution Wrapper** 路徑（仍為 plan/preflight 為主的治理封裝；本計畫不直接啟用 production migration）。
- 明確化 **Human Approval Gate** 與 **Recovery Mode A** 的強制要求，確保無人核准不可執行、無備份不可執行、無稽核不可執行。
- 導入 **Audit Report Generator** 與 Production Safety Locks，使所有決策與產物可追蹤、可回放、可稽核。
- 建立 **Activation Test Suite**（離線、mock、dry-run），驗證 mode separation、guard、diff、report contract、misuse 防護在 Phase 4 實作前即有效。

## 2. Governed Execution Wrapper 設計

### 2.1 角色與責任

- **Wrapper**：唯一允許串接 checker / diff / report 的入口（拒絕未指定 mode、拒絕危險輸入、拒絕 .env 隱性切換）。
- **Checkers**：`proposal_checker`、`risk_checker`、`db_connection_guard`、`tenant_sno_checker`、`schema_diff_checker`（待實作）。
- **Report**：統一由 report generator 依 input contract 產生，避免暫存檔或非版本化輸入。

### 2.2 核心輸入/輸出（契約優先）

- **Inputs**：proposal、preflight_result、schema_diff_result、recovery_plan（皆需 contractVersion）。
- **Outputs**：preflight report、plan/governance report、audit report（皆需標示 mode、時間、requestId）。

### 2.3 安全預設

- 未指定 mode → **拒絕執行**
- 任何 input path 指向 `.env` → **FAIL**
- 任何 input path 指向 `db/tenant_service_limits.sql` → **FAIL**
- 任何包含 connection string / secret 的內容 → **FAIL**（以 validator 規則定義）

## 3. Human Approval Gate

### 3.1 Gate 定義

- 任何可能影響 production 的下一步（包括建立 Execute Mode 的規劃、或將 migration SQL 納入正式流程）必須具備：
  - DB Change Request 已核准
  - Proposal 已核准
  - 人工核准證據可稽核（approval code / record）

### 3.2 Gate 實作方向（不在本文件中實作）

- 以明確的「核准輸入檔」或「核准紀錄」作為 wrapper 的必要輸入（不從 `.env` 或隱性來源取得）。

## 4. Recovery Readiness Enforcement

### 4.1 Recovery Mode A 強制條件（治理層）

- Recovery plan report 必須存在且可稽核
- `.bak` 備份要求必須確認
- schema-only before/after 與 diff report 必須可產生
- **Human approval required**：AI 只可診斷與產出方案，不可自動 restore/rollback production

### 4.2 Enforcement 點

- 在 wrapper / preflight 報告中加入 recovery readiness 的顯式欄位（PASS/FAIL/NEEDS_REVIEW）。

## 5. Audit Report Generator

### 5.1 目標

- 將「輸入、檢查器結果、diff 結果、核准資訊、最終建議」彙整為固定結構的稽核報告。

### 5.2 最小輸出內容

- requestId / mode / timestamps
- executedChecks 與結果摘要
- highestRisk / finalStatus
- safetyWarnings
- artifacts pointers（proposal/preflight/diff/recovery/report）

## 6. Production Safety Locks

### 6.1 Locks 清單

- 禁止 Execute Mode（除非正式啟用流程核准）
- 禁止 SQL execution（Phase 4 implementation 期間）
- 禁止 SQL Server connection（除非另案核准且文件化）
- 禁止 `.env` access 作為 mode 或 connection 的隱性來源
- 禁止使用 `db/tenant_service_limits.sql` 作為任何流程依賴

### 6.2 Misuse 防護（必測）

- unspecified mode → FAIL
- invalid mode → FAIL
- mock inputs used in production path → FAIL
- do_not_execute fixtures in production preflight → FAIL

## 7. Activation Test Suite

### 7.1 測試分層

- **MOCK_MODE**：mock proposal + mock preflight_result + optional mock diff/recovery
- **DRY_RUN_MODE**：真實命名 proposal + 離線 schema-only 檔案 + schema diff + contract-based report
- **PRODUCTION_PREFLIGHT_MODE**：僅治理/齊備性檢查（不連 DB、不執行 SQL），確保 guard 不可跳過

### 7.2 必要測試案例（摘要）

- mock valid should PASS without db guard whitelist blocking
- mock invalid should FAIL
- mock underestimation should FAIL/BLOCKED
- production preflight must not allow skipping db guard
- schema diff unexpected CRITICAL → FAIL
- contractVersion unsupported → FAIL

## 8. 完整執行流程圖

```mermaid
flowchart TD
  A[Input artifacts folder\n(proposal/preflight/diff/recovery)] --> B{Mode specified?}
  B -- No --> X[FAIL: reject execution]
  B -- Yes --> C[Validate input contract\n(contractVersion, required files)]
  C --> D{Misuse checks\n.env / tenant_service_limits / secrets}
  D -- Fail --> X
  D -- Pass --> E[Run checkers\nproposal/risk/tenant_sno]
  E --> F{Mode}
  F -- MOCK --> G[DB guard: SKIPPED_BY_MODE\n(no connection)]
  F -- DRY_RUN --> H[DB guard: enforce\n(no connection)]
  F -- PRODUCTION_PREFLIGHT --> I[DB guard: enforce\n(no skipping)]
  G --> J[Schema diff (optional/mock)]
  H --> K[Schema diff (required for governance)]
  I --> K
  J --> L[Generate reports\n(plan/governance/audit)]
  K --> L
  L --> M[Final recommendation\nPASS/FAIL/BLOCKED/NEEDS_REVIEW]
```

## 9. 新增檔案清單

Phase 4 implementation plan（本文件）可能導致後續新增的「設計/契約/fixture/腳本」清單（此處僅列預期，不在本次建立）：：

- `db/migrations/v5/scripts/schema_diff_checker.ps1`（待實作）
- `db/migrations/v5/contracts/`（contract 定義與範例）
- `db/migrations/v5/tests/mock_report_inputs/`（可重現 fixtures）
- `db/migrations/v5/tests/phase4_activation_suite/`（activation tests）
- `db/migrations/v5/reports/`（報告輸出約定；是否入 Git 由治理規範決定）

## 10. Activation Checklist

### Phase 4 進入條件（文件面）

- Pre-Phase 4 文件已完成並入庫（hardening plan、mode separation、mock-compatible preflight、schema diff design、report generator input contract、recovery mode A checklist、activation checklist）
- Git working tree clean（除明確排除之本機草稿）
- `db/tenant_service_limits.sql` 未入庫、未成為依賴

### Phase 4 第一個動作（程序面）

- 建立 Phase 4 的 **implementation work breakdown**（可拆成多個小 PR）
- 每個 PR 仍需遵守：不連 DB、不執行 SQL、不讀 `.env`、不引入 Execute Mode

---

**Explicit safety statement**: This is a planning document only. No SQL was executed and no SQL Server connection was made.

