# SQL Safe Migration 5.0 Phase 3 Inventory Report

## 1. 盤點目的

本文件供 **Phase 3 測試版**使用，以**唯讀**方式盤點 Phase 2 既有腳本、測試資料與報告，讓後續 Step 3 建立 mock test cases 時，能對應到已知工具與資料夾結構，並遵守「不連線、不執行 SQL、不依賴 `tenant_service_limits.sql`」等邊界。

盤點範圍主要為 `db/migrations/v5/` 下之 `scripts/`、`tests/`、政策與 Phase 2 說明文件；**未**開啟或引用 `db/tenant_service_limits.sql`。

## 2. 安全限制確認

- 本次只做唯讀盤點（目錄掃描與既有文件內容之引用），**未**修改任何既有檔案。
- 未執行 SQL。
- 未連 SQL Server。
- 未讀取或修改 `.env`。
- 未碰 `db/tenant_service_limits.sql`。
- 未修改正式 DB。
- 未建立 Execute Mode。

## 3. 可重用工具清單

| 類別 | 檔案路徑 | 用途 | Phase 3 是否可重用 | 備註 |
|------|----------|------|-------------------|------|
| Proposal Checker | `db/migrations/v5/scripts/proposal_checker.ps1` | 驗證 proposal JSON 格式、必填欄位與允許值 | 是 | Plan-only；`preflight_orchestrator.ps1` 會呼叫 |
| Risk Checker | `db/migrations/v5/scripts/risk_checker.ps1` | 風險層級計算、`riskUnderestimated` 等 JSON 輸出 | 是 | 與 Phase 2 Step 2-B～2-D 報告對應 |
| Risk Underestimation | `db/migrations/v5/tests/risk_checker_underestimation/*.proposal.json`（4 個檔案） | 刻意低估風險之 proposal 測試資料 | 是 | 與 `step2d_risk_underestimation_report.md` 搭配 |
| Schema Diff Checker | `db/migrations/v5/scripts/plan_report_generator.ps1` | Plan 報告中列出「before/after schema-only、schema diff report」等檢查敘述 | 部分 | **Phase 2 無獨立 schema diff 執行腳本**；僅報告/清單層級，無兩份 schema 檔之自動比對引擎 |
| Schema Diff Checker | `db/migrations/v5/DB_CHANGE_REQUEST_POLICY.md` | 定義變更必要產物含 **schema diff report** | 是（規範） | 供靜態治理與文件測試，非可執行 checker |
| Schema Diff Checker | `db/migrations/v5/TODO_RULES.md` | 提及 schema-only before/after diff 之規則方向 | 是（規範） | 與實作腳本分離 |
| Governance Report | `db/migrations/v5/tests/results/step2d_pre_commit_governance_report.md` | Pre-commit 範圍、排除清單、檔案完整性與 checker 安全審視紀錄 | 是 | 可作 Git / staging 治理流程範本 |
| Governance Report | `db/migrations/v5/tests/results/step2k_push_readiness_report.md` | Push 前 readiness（含 `git diff`、禁止入庫路徑提醒） | 是 | 與 cleanliness / ignore 驗證呼應 |
| Governance Report | `db/migrations/v5/tests/results/step2b_checker_dry_run_report.md`、`step2c_*`、`step2e_*`、`step2f_*`、`step2g_*`、`step2h_*`、`step2i_*`、`step2j_phase2_final_validation_report.md` | 各子步驟 dry-run / checker 結果 | 是 | 稽核與回歸對照用 |
| Invalid Proposal Tests | `db/migrations/v5/tests/proposals/invalid_missing_required_fields.proposal.json` | 缺必填欄位 | 是 | `tests/README.md` 明示用途 |
| Invalid Proposal Tests | `db/migrations/v5/tests/proposal_checker_invalid_values/*.proposal.json` | 無效列舉、型別、環境、risk、action 等 | 是 | 多案例 |
| Invalid Proposal Tests | `db/migrations/v5/tests/approval_hash_guard_invalid_cases/*.proposal.json` | 核准碼格式、`requiresApproval`、rollback 等無效情境 | 是 | 搭配 `approval_hash_guard.ps1` |
| Invalid Proposal Tests | `db/migrations/v5/tests/tenant_sno_checker_invalid_cases/*.proposal.json` | tenant 範圍、`snoRequired` 等無效情境 | 是 | 搭配 `tenant_sno_checker.ps1` |
| Invalid Proposal Tests | `db/migrations/v5/tests/db_connection_guard_invalid_targets/*.proposal.json` | 系統庫、空資料庫名、無效 server 等 | 是 | 搭配 `db_connection_guard.ps1`（仍為 plan-only，不連線） |
| Mock / Sample Data | `db/migrations/v5/tests/proposals/low_add_nullable_column.proposal.json` | 低風險範例 | 是 | 與 `tests/README.md` 風險分級說明一致 |
| Mock / Sample Data | `db/migrations/v5/tests/proposals/medium_add_not_null_column.proposal.json` | 中風險範例 | 是 | 同上 |
| Mock / Sample Data | `db/migrations/v5/tests/proposals/high_*.proposal.json`、`critical_*.proposal.json` | 高/重大風險分類測試 | 是 | 僅供檢查器，不可當正式 migration |
| Phase 2 Reports | `db/migrations/v5/PHASE2_SUMMARY.md` | Phase 2 總結與 Git 資產提醒 | 是 | 高階盤點 |
| Phase 2 Reports | `db/migrations/v5/README_PHASE2.md` | Phase 2 邊界、已完成 Step、核心腳本清單 | 是 | 與本盤點互相印證 |
| Phase 2 Reports | `db/migrations/v5/tests/results/*.md`（step2a～step2k 系列） | 各步驟測試與治理書面產出 | 是 | 作為 Phase 3 報告格式參考 |
| Dry-run / Static-check 腳本 | `db/migrations/v5/scripts/preflight_orchestrator.ps1` | 串接 proposal / risk / db_connection / tenant_sno checkers，輸出整合結果 | 是 | 明確標註不得連線與執行 SQL |
| Dry-run / Static-check 腳本 | `db/migrations/v5/scripts/approval_hash_guard.ps1` | 核准與 hash 相關靜態檢查 | 是 | Step 2-H |
| Dry-run / Static-check 腳本 | `db/migrations/v5/scripts/db_connection_guard.ps1` | 依 proposal 檢查連線目標合理性（不實際連線） | 是 | Step 2-E |
| Dry-run / Static-check 腳本 | `db/migrations/v5/scripts/tenant_sno_checker.ps1` | Tenant / sno 治理檢查 | 是 | Step 2-F |
| Dry-run / Static-check 腳本 | `db/migrations/v5/scripts/hash_calculator.ps1` | Hash 計算（治理用） | 是 | README_PHASE2 核心清單 |
| Proposal 規格（非執行檔） | `db/migrations/v5/PROPOSAL_SCHEMA.md` | Proposal JSON 欄位說明 | 是 | 格式驗證對照 |

**補充：** `db/migrations/v5/change_requests/tenant_service_limits_change_request.md` 存在於 repo，但與 **tenant_service_limits** 變更脈絡相關；Phase 3 測試**不應**將 `db/tenant_service_limits.sql` 或該草稿 SQL 列為依賴，變更請求文件僅能作為「曾存在之文件」認知，不作為測試輸入。

## 4. Phase 3 建議測試對應

| Phase 3 測試項目 | 可使用的 Phase 2 工具或資料 | 是否需要新增 mock case | 備註 |
|------------------|----------------------------|------------------------|------|
| Proposal 格式驗證 | `proposal_checker.ps1`、`PROPOSAL_SCHEMA.md`、`tests/proposals/*.proposal.json` | 可選 | 可擴充邊界欄位，但非必須 |
| Invalid proposal 測試 | `proposal_checker_invalid_values/`、`proposals/invalid_missing_required_fields.proposal.json`、其他 invalid 子資料夾 | 可選 | 已有多類無效樣本 |
| Risk checker 測試 | `risk_checker.ps1`、`tests/proposals/` 各風險層級案例、`step2b_risk_checker_results.md` | 可選 | 以既有 JSON 重跑 dry-run 即可 |
| Risk underestimation 測試 | `risk_checker_underestimation/*.proposal.json`、`risk_checker.ps1` | 否（資料已齊） | 對照 `step2d_risk_underestimation_report.md` |
| Schema diff checker 測試 | `plan_report_generator.ps1`（清單文字）、`DB_CHANGE_REQUEST_POLICY.md` | **是** | Phase 2 **無** schema 檔 diff 工具；Phase 3 宜以 **mock before/after 文字檔或純文件** 測試流程，不連線、不產正式 schema dump |
| Pre-commit governance report 測試 | `step2d_pre_commit_governance_report.md`、`step2k_push_readiness_report.md` 範本 | 可選 | 可複製結構產生 Phase 3 專用報告 |
| Git cleanliness check | Step 2-D / 2-K 報告中的 `git status`、`git diff --cached` 敘述與排除清單 | 可選 | 實際指令由人員或 CI 在無 DB 前提下執行 |
| Ignore rule verification | `README_PHASE2.md`、`step2d`/`step2k` 中列舉之**不得加入 Git** 路徑 | 可選 | 對照 `.gitignore`（若 Phase 3 要測，僅限讀取規則與 dry-run） |

（`phase3_test_plan.md` 另列之 **Dry-run / static-check**、**Mock data** 測試可直接對應上表「工具腳本」與 `tests/proposals`、`tests/*_invalid_*` 資料夾。）

## 5. 不可使用或需隔離項目

- `db/tenant_service_limits.sql`（未追蹤草稿；**不得**作為 Phase 3 測試依賴）
- `.env` 與任何 **production connection string**
- 正式 DB、正式 schema 匯出物（例如政策與報告中提醒避免入庫之 `db/schema.sql`、`db/schema.json`、`db/sync_schema.ps1` 等——Phase 3 仍應視為隔離）
- 任何設計為**實際連線 SQL Server** 或**執行 SQL** 之腳本（Phase 2 `v5/scripts` 所列工具依 README 為 plan-only；若 repo 他處另有執行型腳本，Phase 3 測試版不應啟用）
- 將 **tenant_service_limits** 草稿 SQL 或需該檔才能完成之變更路徑納入測試關鍵路徑

## 6. 下一步建議（Step 3）

建議新增 **Phase 3 專用**目錄（例如 `db/migrations/v5/tests/phase3_mock/` 或專案慣用命名），僅放入：

- 純 mock / invalid / sample 之 `*.proposal.json` 或小型 **假** schema 片段（若需測「diff 流程」敘述，用可刪除之範例文字即可）；

並持續遵守：**不執行 SQL**、**不連 SQL Server**、**不讀 `.env`**、**不使用** `db/tenant_service_limits.sql`。
