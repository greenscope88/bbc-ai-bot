# SQL Safe Migration 5.0 Pre-Phase 4 Activation Checklist

## 1. 目的

本文件定義 SQL Safe Migration 5.0 進入 **Phase 4 正式啟用前**必須完成的檢查清單（治理 gate）。本文件本身不代表已啟用 Phase 4，也不代表可執行 production migration 或建立 Execute Mode。

## 2. Phase 4 定義

Phase 4 是正式啟用前最後治理關卡：用於確認前置設計、稽核產物、流程與安全機制已齊備。Phase 4 **不等於**可立即執行 production migration、也**不等於**可直接建立或開啟 Execute Mode。

## 3. 必要前置條件 Checklist

- [ ] Phase 3 測試版已完成並入庫
- [ ] Pre-Phase 4 Hardening Plan 已完成並入庫
- [ ] Mode Separation 設計文件已完成並入庫
- [ ] Mock-compatible Preflight 設計文件已完成並入庫
- [ ] Schema Diff Checker 設計文件已完成並入庫
- [ ] Report Generator Mock Input Contract 已完成並入庫
- [ ] Recovery Mode A Activation Checklist 已完成並入庫
- [ ] Git working tree clean
- [ ] `main` 與 `origin/main` 同步
- [ ] `db/tenant_service_limits.sql` 未入庫

## 4. Phase 4 前仍需實作項目

- `preflight_orchestrator.ps1` 增加明確 Mode 參數
- `MOCK_MODE` 支援 `skipped_by_mode` DB guard
- `DRY_RUN_MODE` 支援 schema snapshot input
- `PRODUCTION_PREFLIGHT_MODE` 保留 DB guard
- `schema_diff_checker.ps1` 實作
- report generator input contract validation
- recovery plan report 格式
- mode misuse 測試

## 5. Phase 4 禁止事項

- 不得直接建立 Execute Mode
- 不得直接執行 production SQL
- 不得直接連 production SQL Server 執行 migration
- 不得跳過 DB Change Request
- 不得跳過 proposal
- 不得跳過 risk checker
- 不得跳過 schema diff checker
- 不得跳過 Recovery Mode A
- 不得讓 AI 自動 rollback / restore production DB

## 6. Production Activation Gate

正式啟用前應至少滿足下列 gate（具體保存位置依專案治理規範）：：

- DB Change Request 已核准
- Proposal 已核准
- Migration SQL 已審核
- `.bak` 備份策略已確認
- schema-only snapshot 已確認
- Risk report 已通過
- Schema diff report 已通過
- Recovery plan report 已建立
- 人工核准已完成
- Audit log path 已確認

## 7. tenant_service_limits 特別規則

`db/tenant_service_limits.sql` 目前仍是**本機 SQL 草稿**：**不可** `git add`、**不可** commit、**不可**執行、**不可**作為 Phase 4 依賴。

若未來要落地 `tenant_service_limits`，必須另走：

DB Change Request  
→ proposal  
→ risk checker  
→ schema diff checker  
→ governed migration  
→ backup  
→ recovery readiness  
→ human approval

## 8. Go / No-Go 判斷

### GO 條件

- 所有 Pre-Phase 4 文件已完成並入庫
- Git clean
- 無未審核 SQL 草稿入庫
- 所有 Phase 4 前補強項目已有實作計畫

### NO-GO 條件

- 有 SQL 草稿未治理
- 有未審核 migration
- 缺少 backup / recovery / diff / risk 任一項
- Git working tree 不乾淨
- `.env` 被讀取或修改
- `tenant_service_limits.sql` 被加入流程

## 9. Phase 4 建議啟動方式

Phase 4 應另開獨立流程，不可直接接續本文件自動開始。

Phase 4 第一個動作應是：建立 **Phase 4 Implementation Plan**，而不是直接修改工具或建立 Execute Mode。

## 10. 不在本階段執行事項

- 不建立 Execute Mode
- 不執行 SQL
- 不連 SQL Server
- 不修改正式 DB
- 不讀取或修改 `.env`
- 不處理 `db/tenant_service_limits.sql`
- 不修改既有工具
- 不正式啟用 Phase 4

## 11. 結論

- 本文件完成後，Pre-Phase 4 補強設計文件可視為**文件面**完成（仍需 Phase 4 Implementation Plan 與後續實作/測試/治理審查）。
- **仍不應**直接進入 Phase 4 實作；下一步應是**先入庫本文件**並建立 Phase 4 Implementation Plan。
- Phase 4 應從 **Implementation Plan** 開始，而非直接改動工具或進入 Execute Mode。

