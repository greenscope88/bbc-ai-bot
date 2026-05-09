# SQL Safe Migration 5.0 Tests (Plan Mode / Dry-run)

本資料夾只存放 Plan Mode / Dry-run 測試案例。

- 測試案例不得直接轉成 SQL 執行。
- 測試案例不得連線正式 DB。
- 測試案例用來驗證 `proposal_checker`、`risk_checker`、`plan_report_generator`。
- 測試案例涵蓋 Low / Medium / High / Critical 各類風險用途。
- `invalid_missing_required_fields` 用於測試欄位缺漏檢查。
- `UPDATE` / `DROP_TABLE` 等案例僅為風險分類測試，不得執行。
- 所有測試案例都不得視為正式 migration。
