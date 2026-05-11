# SQL Safe Migration 5.0 Pre-Phase 4 Recovery Mode A Activation Checklist

## 1. 目的

本文件定義 SQL Safe Migration 5.0 在**正式啟用前**，Recovery Mode A 必須滿足的條件與檢查清單，用於確保復原行為可稽核、可控、且符合「人員確認後才可執行」之治理原則。  
本文件**不是** Phase 4 正式啟用；本步驟僅新增文件，不建立 Execute Mode、不執行 SQL、不連線 SQL Server。

## 2. Recovery Mode A 定義

Recovery Mode A（建議模式）：

- AI 負責診斷問題
- AI 產生復原方案
- AI 提供風險評估
- AI 產生 repair migration 或 rollback plan
- 人員確認後才可執行
- AI 不可自行對 production DB 執行 restore / rollback

## 3. 基本原則

- Human approval required
- Backup before recovery
- Schema comparison before repair
- Recovery plan report required
- No autonomous production restore
- No autonomous rollback
- Audit trail required

## 4. Recovery 前檢查清單

- [ ] 停止所有寫入服務
- [ ] 建立目前狀態 `.bak`
- [ ] 匯出目前 `schema-only.sql`
- [ ] 找出最近正常 `schema-only.sql`
- [ ] 比對 good vs broken schema
- [ ] 產生 repair migration
- [ ] 進行風險評估
- [ ] 產生 recovery plan report
- [ ] 人工審核與核准
- [ ] 再次確認 `.bak` 存在

## 5. Recovery 執行清單

- [ ] 執行 repair migration 或 rollback
- [ ] 匯出修復後 `schema-only.sql`
- [ ] 再次 diff 比對
- [ ] 測試 API / Web / AI 功能
- [ ] 建立 recovery execution report

## 6. Recovery 禁止事項

- AI 不可自行 restore production DB
- AI 不可自行 rollback production migration
- 無 `.bak` 時不得執行
- 無人工核准不得執行
- 無 recovery plan report 不得執行

## 7. Recovery Plan Report 建議欄位

至少包含：

- `issueSummary`
- `rootCauseAnalysis`
- `affectedObjects`
- `riskAssessment`
- `backupRequirements`
- `repairStrategy`
- `rollbackStrategy`
- `validationSteps`
- `manualApprovalRequired`
- `finalRecommendation`

## 8. 與其他元件的關係

- `proposal_checker`：驗證提案/治理輸入格式（避免無契約的復原輸入）
- `risk_checker`：提供復原方案的風險分級與 underestimation 警示
- `schema_diff_checker`：用於 good vs broken、broken vs repaired 的離線差異比對與風險提示
- `report_generator`：彙整 preflight / diff / recovery plan / execution 報告，形成完整稽核鏈
- `preflight_orchestrator`：在 DRY_RUN / production preflight 階段做靜態治理 gate，避免未核准進入復原/執行
- `recovery_plan_report`：Recovery Mode A 的核心產物（必須先產生、先審核）

## 9. 啟用前必要條件

- Phase 3 完成並入庫
- Pre-Phase 4 補強文件完成
- Recovery Mode A checklist 完成
- 人工確認政策明確（誰可核准、核准證據、留存方式）
- Execute Mode 尚未啟用
- production DB 不允許自動復原

## 10. Misuse 防護

- auto restore without approval → FAIL
- rollback without backup → FAIL
- recovery without report → FAIL
- recovery without schema diff → FAIL

## 11. 不在本階段執行事項

- 不建立 Execute Mode
- 不執行 SQL
- 不連 SQL Server
- 不修改正式 DB
- 不讀取或修改 `.env`
- 不處理 `db/tenant_service_limits.sql`
- 不進入 Phase 4

## 12. 結論

- 本文件完成後，**可進入 Step 6：Phase 4 Activation Checklist**（仍屬文件/治理設計層級）。
- **仍不應**進入 Phase 4（需完成補強設計、實作、測試、治理審查）。
- **仍不可**處理 `tenant_service_limits.sql`（若落地須走 DB Change Request / proposal / governed migration）。
- Recovery Mode A 是正式啟用前必要治理機制（確保復原「可控、可審核、需人審批」）。

