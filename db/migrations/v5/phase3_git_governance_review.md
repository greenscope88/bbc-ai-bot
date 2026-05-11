# SQL Safe Migration 5.0 Phase 3 Git Governance Review

## 1. Review Purpose

本次為 **Phase 3 測試版**之 **Git governance review**：在工作樹僅含預期未追蹤產物之前提下，界定**建議入庫**與**明確排除**項目，並給出 commit 邊界與建議訊息。**未**執行 SQL、**未**連線 SQL Server、**未**讀取或修改 `.env`；**未**對 `db/tenant_service_limits.sql` 做任何操作。

## 2. Safety Confirmation

- 未建立 Execute Mode
- 未執行 SQL
- 未連 SQL Server
- 未修改正式 DB
- 未讀取或修改 `.env`
- 未碰 `db/tenant_service_limits.sql`
- 未修改既有測試工具（`db/migrations/v5/scripts/*.ps1` 等）
- 未 `git add` / `commit` / `push`（本 Step 6 僅撰寫本報告）

## 3. Current Git Status Summary

以下為 **2026-05-11** 於 `C:\bbc-ai-bot` 執行指令之實際輸出摘要（**含**建立本檔 `phase3_git_governance_review.md` 之後之狀態）。

### git status -sb

```
## main...origin/main
?? db/migrations/v5/phase3_final_test_report.md
?? db/migrations/v5/phase3_git_governance_review.md
?? db/migrations/v5/phase3_inventory_report.md
?? db/migrations/v5/phase3_static_test_results.md
?? db/migrations/v5/phase3_test_plan.md
?? db/migrations/v5/tests/phase3_mock_cases/
?? db/tenant_service_limits.sql
```

（若終端機重複顯示同一行，以**不重複路徑**為準；實質上為 **7 個未追蹤路徑**：6 個 Phase 3 產物／目錄 + `db/tenant_service_limits.sql`（後者**排除入庫**）。）

### git status --short

與上列相同：`??` 僅出現上述 **7** 路徑；**無** ` M` / `M ` / `A ` / `MM` 等已追蹤檔變更。

### git diff --stat

**無輸出**（工作區中**無**已追蹤檔案之內容變更，僅有未追蹤檔案）。

### git diff --name-only

**無輸出**（同上）。

### git diff --cached --name-only

**無輸出**（staging 區為空，**無**已暫存變更）。

## 4. Recommended Files to Commit

| 路徑 | 類別 | 是否建議入庫 | 理由 |
|------|------|--------------|------|
| `db/migrations/v5/phase3_test_plan.md` | Phase 3 測試計畫 | **是** | 定義 Phase 3 目標、禁止事項、Exit Criteria、Recovery Mode A |
| `db/migrations/v5/phase3_inventory_report.md` | 盤點報告 | **是** | Phase 2 工具／資料盤點，供 mock 與回歸對照 |
| `db/migrations/v5/phase3_static_test_results.md` | 靜態測試結果 | **是** | Step 4 dry-run 執行紀錄與觀察 |
| `db/migrations/v5/phase3_final_test_report.md` | 總報告 | **是** | Step 1～5 彙總與 Phase 4 前建議 |
| `db/migrations/v5/phase3_git_governance_review.md` | Git 治理審查 | **是** | 本 Step 6 入庫邊界與排除清單（本檔） |
| `db/migrations/v5/tests/phase3_mock_cases/README.md` | Mock 說明 | **是** | Mock 用途與 Safety Rules |
| `db/migrations/v5/tests/phase3_mock_cases/valid_add_nullable_column.proposal.json` | Mock proposal | **是** | 低風險有效案例 |
| `db/migrations/v5/tests/phase3_mock_cases/invalid_missing_change_request_id.proposal.json` | Mock proposal | **是** | 缺 `requestId` 無效案例 |
| `db/migrations/v5/tests/phase3_mock_cases/dangerous_drop_column.proposal.json` | Mock proposal | **是** | 高風險結構變更（標示 do_not_execute） |
| `db/migrations/v5/tests/phase3_mock_cases/dangerous_delete_without_where.proposal.json` | Mock proposal | **是** | Critical 資料變更情境 mock |
| `db/migrations/v5/tests/phase3_mock_cases/risk_underestimation_case.proposal.json` | Mock proposal | **是** | 風險低估測試 |
| `db/migrations/v5/tests/phase3_mock_cases/mock_schema_before.sql` | Mock DDL 文字 | **是** | 僅供 diff／文件演練之**不可執行**範本 |
| `db/migrations/v5/tests/phase3_mock_cases/mock_schema_after.sql` | Mock DDL 文字 | **是** | 同上 |

**入庫方式建議**：可一次 `git add` 上表所有路徑；或使用 `git add db/migrations/v5/phase3_*.md db/migrations/v5/tests/phase3_mock_cases/`，但**仍須人工確認**未誤加入 `db/tenant_service_limits.sql`。

## 5. Files Explicitly Excluded from Commit

| 路徑 | 是否排除 | 理由 |
|------|----------|------|
| `db/tenant_service_limits.sql` | **是，必須排除** | 本機 SQL **草稿**；**不可**當作可執行 migration、**不可**入庫、**不可**讓 Phase 3 依賴。若未來落地 tenant_service_limits，須走 **SQL Safe Migration 5.x** 之 DB Change Request / proposal / governed migration。 |

## 6. Commit Boundary

**本次 Phase 3 commit boundary**：

- **只允許** Phase 3 測試版**文件**、**mock cases**（proposal JSON + mock schema 文字 + README）、**測試與總報告**、**本 Git governance review** 入庫。
- **不得**納入：任意其他 SQL 草稿、`db/tenant_service_limits.sql`、正式 DB schema 產物（如政策禁止入庫之 `db/schema.sql` / `db/schema.json` 等）、`.env`、connection string、Execute Mode 實作、production migration 執行腳本。
- Mock 目錄內之 `.sql` 為 **Phase 3 契約下之純文字測試資料**，與「可對 DB 執行之腳本」不同；入庫後仍須遵守 README 之 *Do not execute*。

## 7. Suggested Commit Message

```
feat(db-migration): add phase 3 static test governance reports
```

（若需涵蓋 mock cases，可於 body 註明：`phase3_mock_cases` proposals and mock schema stubs for dry-run only。）

## 8. Final Recommendation

- **是否可進入「人工確認後 git add 指定檔案」**：**可以**。建議由人員逐路徑核對 §4 與 §5 後，**精確** `git add`（避免 `git add .`），並確認 `git status` 中**不出現** `db/tenant_service_limits.sql` 於 staged。
- **是否仍不得進入 Phase 4 正式啟用**：**是**——與 `phase3_final_test_report.md` 一致；尚需 mock-compatible preflight、schema diff 補強、activation checklist 等。
- **是否需要先補強 Phase 4 前項目**：**是**——見 `phase3_final_test_report.md` §6（safe mock preflight、schema diff 入口、mock／production 分軌、report contract、Recovery Mode A、Phase 4 checklist）。

---

*Review 產出：`phase3_git_governance_review.md`；未執行 git add / commit / push。*
