# SQL Safe Migration 5.0 Phase 3 Test Plan

## 1. Phase 3 目標

Phase 3 為**測試版**，非正式啟用版。本階段僅驗證治理流程、proposal、檢查器、risk checker、schema diff checker、audit report、Git governance 等**文件與靜態流程**；**不執行任何 SQL**、不對正式資料庫做任何變更。目標是在 Phase 4 正式啟用前，以 dry-run / static-check / mock 方式證明工具鏈與規範可重複、可審核。

## 2. Phase 3 禁止事項

- 不建立 Execute Mode
- 不執行 SQL
- 不連 SQL Server
- 不修改正式 DB
- 不讀取或修改 `.env`
- 不使用 `db/tenant_service_limits.sql`
- 不讓 `tenant_service_limits` 草稿成為 Phase 3 測試依賴
- 不使用 production connection string
- 不寫入正式資料庫

## 3. 測試範圍

- Proposal 格式驗證
- Invalid proposal 測試
- Risk checker 測試
- Risk underestimation 測試
- Schema diff checker 測試
- Pre-commit governance report 測試
- Git cleanliness check
- Ignore rule verification
- Dry-run / static-check 測試
- Mock data 測試

## 4. 測試資料原則

- 只允許 mock / sample / invalid test data
- 不使用正式 DB schema 產物
- 不使用 `.env`
- 不使用正式 connection string
- 不使用 `db/tenant_service_limits.sql`
- 測試資料必須可刪除、可重建、不可影響正式資料

## 5. Phase 3 建議步驟

- Step 1：建立測試計畫文件
- Step 2：盤點 Phase 2 既有測試工具與測試資料
- Step 3：建立 Phase 3 測試資料夾與 mock cases
- Step 4：執行非 SQL 類測試
- Step 5：產生 Phase 3 測試報告
- Step 6：Phase 3 Git governance review

## 6. 測試成功標準 Exit Criteria

- 所有測試只在 dry-run / static-check / mock 模式完成
- 無 SQL Server 連線
- 無正式 DB 修改
- 無 `.env` 存取
- 無使用 `db/tenant_service_limits.sql`
- Git working tree 可審核
- 測試報告可供 Phase 4 正式啟用前審查

## 7. Recovery Mode 原則

本專案採用 **SQL Safe Migration Recovery Mode A**：AI 負責診斷、產生復原方案與風險評估；人員確認後，才可執行復原。Phase 3 不執行復原，只驗證文件與流程規劃。
