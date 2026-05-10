# Step 2-D Pre-Commit Governance Report

## Scope Check

本次允許入庫範圍（Step 2-D）：

- `db/migrations/v5/scripts/risk_checker.ps1`
- `db/migrations/v5/tests/risk_checker_underestimation/`（4 個 `*.proposal.json`）
- `db/migrations/v5/tests/results/step2d_risk_underestimation_report.md`
- `db/migrations/v5/tests/results/step2d_pre_commit_governance_report.md`（本檔）

## Working Tree Note（與 Step 2-D 無關之變更）

治理時 `git status --short` 顯示工作區另有已修改但未列入 Step 2-D 之檔案（例如 `proposal_checker.ps1`、Step 2-B/2-C 相關測試與報告等）。**本次僅將上列 Scope Check 內之路徑加入 staging**；其餘變更維持未 staging，不應與 Step 2-D 混在同一 commit，除非另有明確規劃。

## Excluded Existing Untracked Files

以下檔案**未**納入本次 Step 2-D staging：

- `db/memory.lock.json`
- `db/schema.json`
- `db/schema.sql`
- `db/sync_schema.ps1`
- `db/tables.md`
- `db/tenant_service_limits.sql`

## File Integrity Check

| 路徑 | 存在 | 非空 |
|------|------|------|
| `db/migrations/v5/scripts/risk_checker.ps1` | 是 | 是（約 190+ 行） |
| `db/migrations/v5/tests/risk_checker_underestimation/underestimated_alter_column_as_low.proposal.json` | 是 | 是 |
| `db/migrations/v5/tests/risk_checker_underestimation/underestimated_drop_table_as_low.proposal.json` | 是 | 是 |
| `db/migrations/v5/tests/risk_checker_underestimation/underestimated_update_all_tenants_as_medium.proposal.json` | 是 | 是 |
| `db/migrations/v5/tests/risk_checker_underestimation/underestimated_unclear_tenant_scope_as_low.proposal.json` | 是 | 是 |
| `db/migrations/v5/tests/results/step2d_risk_underestimation_report.md` | 是 | 是 |
| `db/migrations/v5/tests/results/step2d_pre_commit_governance_report.md` | 是 | 是 |

## Risk Checker Safety Review

`risk_checker.ps1` 經檢視符合下列敘述：

- **不執行 SQL**：僅 `Get-Content` 讀取 proposal JSON、`ConvertFrom-Json` 解析，無 `Invoke-Sqlcmd` / 連線字串 / `.sql` 執行。
- **不修改 DB / schema**：無資料庫寫入或 DDL 相關呼叫。
- **不修改 `.env`**：未讀寫環境檔。
- **不新增 bypass 參數**：僅強制參數 `-ProposalPath`；無 `-Skip*` / `-Force*` 等繞過治理之開關。
- **只做風險檢查與 JSON 輸出**：以 `ConvertTo-Json` 輸出結果後 `exit 0`。

## Underestimation Detection Review

腳本具備：

- **Risk level ordering**：`Get-RiskScore` 對應 Low=1、Medium=2、High=3、Critical=4（未知為 0）。
- **declaredRiskLevel**：自 `$proposal.riskLevel` 讀取。
- **calculatedRiskLevel**：依 `action`、tenant、core systems 等規則計算之 `$risk`。
- **riskUnderestimated**：當 `(Get-RiskScore $declaredRiskLevel) -lt (Get-RiskScore $risk)` 時為 `true`（即宣告等級排序值小於計算等級）。
- **riskWarning**：低估時設為 `Declared riskLevel is lower than calculatedRiskLevel.`。
- **上層可讀 JSON**：輸出含 `requestId`、`action`、`declaredRiskLevel`、`calculatedRiskLevel`、`riskUnderestimated`、`riskWarning`、`autoExecutable`、`reason`。

## Test Result Summary

**驗證時間**：2026-05-10（重新執行 `risk_checker.ps1`）

| Proposal | declaredRiskLevel | calculatedRiskLevel | riskUnderestimated | riskWarning（摘要） |
|----------|-------------------|---------------------|--------------------|---------------------|
| `proposals/low_add_nullable_column.proposal.json` | Low | Low | false | （空字串，預期） |
| `underestimated_alter_column_as_low.proposal.json` | Low | High | true | Declared lower than calculated |
| `underestimated_drop_table_as_low.proposal.json` | Low | Critical | true | Declared lower than calculated |
| `underestimated_update_all_tenants_as_medium.proposal.json` | Medium | Critical | true | Declared lower than calculated |
| `underestimated_unclear_tenant_scope_as_low.proposal.json` | Low | High | true | Declared lower than calculated |

**結論**：5 筆測試均輸出所需欄位；1 筆正常案例無低估誤報，4 筆低估案例皆 `riskUnderestimated=true`，與預期一致。

## Git Staging Recommendation

建議僅 staging：

```text
db/migrations/v5/scripts/risk_checker.ps1
db/migrations/v5/tests/risk_checker_underestimation/
db/migrations/v5/tests/results/step2d_risk_underestimation_report.md
db/migrations/v5/tests/results/step2d_pre_commit_governance_report.md
```

**禁止**：`git add .`、`git add -A`。

**重申**：`db/memory.lock.json`、`db/schema.json`、`db/schema.sql`、`db/sync_schema.ps1`、`db/tables.md`、`db/tenant_service_limits.sql` 不得加入本次 staging。

## Sign-off

- 入庫前治理（staging 與報告）已完成依使用者指示執行。
- **未**執行 `git commit` / `git push`。
