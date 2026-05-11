# SQL Safe Migration 5.0 Phase 3 Static Test Results

## 1. 測試目的

本次 Step 4 僅執行 **dry-run / static-check**：呼叫 Phase 2 既有 PowerShell checkers 讀取 `phase3_mock_cases` 內之 **mock proposal JSON**，並產生 orchestrator / plan report 之中間產物於**本機暫存目錄**（測試結束後未保留於 repo）。**未**對任何檔案執行 `.sql`、**未**連線 SQL Server、**未**修改正式資料庫。

## 2. 安全限制確認

- 未執行 SQL（未對 SQL Server 送出批次，亦未以工具執行 `mock_schema_*.sql`）。
- 未連 SQL Server。
- 未修改正式 DB。
- 未讀取或修改 `.env`。
- 未使用 `db/tenant_service_limits.sql`。
- 未建立 Execute Mode。
- 未修改既有測試工具（`db/migrations/v5/scripts/*.ps1` 未變更）。

## 3. 測試環境

| 項目 | 值 |
|------|-----|
| 專案路徑 | `C:\bbc-ai-bot` |
| Mock cases 路徑 | `C:\bbc-ai-bot\db\migrations\v5\tests\phase3_mock_cases` |
| 使用之腳本 | `proposal_checker.ps1`、`risk_checker.ps1`、`preflight_orchestrator.ps1`、`plan_report_generator.ps1`（皆位於 `db/migrations/v5/scripts`） |
| 暫存輸出 | `%TEMP%\phase3_step4_preflight_valid.md`、`%TEMP%\phase3_step4_preflight_under.md`、`%TEMP%\phase3_step4_plan_report.md`（僅供本次驗證） |

**PowerShell 指令摘要**（於 `C:\bbc-ai-bot` 執行；路徑已展開）：

```powershell
$scripts = 'C:\bbc-ai-bot\db\migrations\v5\scripts'
$mock = 'C:\bbc-ai-bot\db\migrations\v5\tests\phase3_mock_cases'
& "$scripts\proposal_checker.ps1" -ProposalPath "$mock\valid_add_nullable_column.proposal.json"
& "$scripts\proposal_checker.ps1" -ProposalPath "$mock\invalid_missing_change_request_id.proposal.json"
& "$scripts\risk_checker.ps1" -ProposalPath "$mock\dangerous_drop_column.proposal.json"
& "$scripts\risk_checker.ps1" -ProposalPath "$mock\dangerous_delete_without_where.proposal.json"
& "$scripts\risk_checker.ps1" -ProposalPath "$mock\risk_underestimation_case.proposal.json"
& "$scripts\preflight_orchestrator.ps1" -ProposalPath "$mock\valid_add_nullable_column.proposal.json" -OutputPath "$env:TEMP\phase3_step4_preflight_valid.md"
& "$scripts\preflight_orchestrator.ps1" -ProposalPath "$mock\risk_underestimation_case.proposal.json" -OutputPath "$env:TEMP\phase3_step4_preflight_under.md"
& "$scripts\plan_report_generator.ps1" -ProposalPath "$mock\valid_add_nullable_column.proposal.json" -OutputPath "$env:TEMP\phase3_step4_plan_report.md" -PreflightReportPath "$env:TEMP\phase3_step4_preflight_valid.md"
```

**測試模式確認**：僅使用 `phase3_mock_cases` 內檔案；`mock_schema_*.sql` 未執行，僅列於報告之 schema diff 項目；dangerous / `do_not_execute` 僅作為 proposal 文字與 JSON 靜態輸入。

## 4. 測試結果總表

| 測試項目 | Mock Case | 預期結果 | 實際結果 | 狀態 | 備註 |
|----------|-----------|----------|----------|------|------|
| Proposal 格式驗證 | `valid_add_nullable_column.proposal.json` | PASS | Exit code **0**，訊息 PASS | PASS | 符合 `proposal_checker` |
| Proposal 格式驗證 | `invalid_missing_change_request_id.proposal.json` | FAIL（缺 `requestId`） | Exit code **1**，列缺 `requestId` | FAIL_EXPECTED | 欄位名稱依 `PROPOSAL_SCHEMA` 為 `requestId` |
| Risk checker | `dangerous_drop_column.proposal.json` | HIGH 或 CRITICAL | `calculatedRiskLevel` = **High** | PASS | `declaredRiskLevel` 亦為 High |
| Risk checker | `dangerous_delete_without_where.proposal.json` | CRITICAL | `calculatedRiskLevel` = **Critical** | PASS | |
| Risk underestimation | `risk_underestimation_case.proposal.json` | FAIL / BLOCKED | `riskUnderestimated` = **true**；`preflight` **finalStatus = FAIL**（含 `risk underestimated`） | BLOCKED_EXPECTED | `preflight_orchestrator.ps1` 仍 **exit 0**，以報告內 `finalStatus` 為準 |
| Schema diff checker | `mock_schema_before.sql` / `mock_schema_after.sql` | （無 Phase 2 獨立腳本） | 未執行任何 diff 工具 | NOT_EXECUTED | **No executable script available**（與 Phase 3 盤點一致） |
| Governance / Plan report | `valid_add_nullable_column` + 上述 preflight 暫存檔 | 可 dry-run 產生報告 | Generator **exit 0**；**Final Conclusion = PLAN_FAIL** | NEEDS_REVIEW | 因 `db_connection_guard` 對 mock `server` 回報 **FAIL**（不在允許清單），preflight 為 FAIL，進而 **PLAN_FAIL**——屬靜態治理鏈正常聯動，非腳本故障 |

## 5. 詳細輸出摘要

- **proposal_checker（valid）**：`PASS: Proposal validation passed.`
- **proposal_checker（invalid）**：`FAIL`，Missing fields 含 `requestId`。
- **risk_checker**：`DROP_COLUMN` 案例計算為 **High**；`DELETE` 案例為 **Critical**；underestimation 案例宣告 Low、計算 **High**、`riskUnderestimated: true`。
- **preflight（valid mock）**：`proposal_checker` PASS；`db_connection_guard` **status: FAIL**（`server is not in allowed server list`）；**finalStatus: FAIL**。
- **preflight（underestimation）**：除 **db_connection_guard failed** 外，blocking 含 **risk underestimated**、**high or critical risk requires manual governance**、**autoExecutable is false**；**finalStatus: FAIL**。
- **plan_report_generator**：成功寫入暫存 Plan Report；結論區為 **PLAN_FAIL**（preflight 非 PASS + approval 整合規則之組合結果）。

## 6. 問題與觀察

- **Mock server 與 db_connection_guard**：Phase 3 mock 使用之 `MOCK-NOCONNECT-PHASE3` 不在 `db_connection_guard` 允許清單，導致**所有 preflight 對這批 mock 皆先 FAIL**。若要於 Phase 3 得到 **preflight PASS** 的端到端示範，需另備「僅用於測試、仍不連線」之**允許 server 別名** mock proposal，或於 Step 5 / Phase 4 前決定是否增加測試專用 allowlist（**不**在本次修改腳本）。
- **Schema diff checker 缺口**：仍無獨立 schema diff 執行檔；`mock_schema_*.sql` 僅能作為未來工具或手動 diff 之**文字素材**。
- **Plan report**：有安全 mock entrypoint（proposal + 選填 preflight 路徑）；在 preflight FAIL 時結論為 **PLAN_FAIL**，行為合理。
- **underestimation 與 preflight**：`risk_checker` 單獨即可驗證低估旗標；**preflight** 同時夾帶 **db_guard FAIL** 與 **risk underestimated**，解讀報告時宜分項看待。

## 7. 結論

- Step 4 **已完成**：已實際執行可取得之非 SQL 測試，並記錄無腳本項目與 governance 聯動結果。
- **可進入 Step 5**（產出 Phase 3 彙總測試報告）：本檔已作為靜態執行紀錄；Step 5 可整合 `phase3_test_plan.md`、`phase3_inventory_report.md`、本結果與 mock cases 說明，並標註 schema diff 與 mock server allowlist 之後續議題。
