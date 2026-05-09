# Execute Mode 執行前檢查清單（Preflight）

本清單用於 **Migration 5.x** 進入 **Execute Mode** 之前之強制核對。屬 **SQL Safe Migration 5.0** 第一階段規劃文件，**不**修改 **SQL Migration 4.5** 之正式執行流程。

---

## 強制規則

**任何一項不通過，不得進入 Execute Mode。**

---

## 檢查項目（至少）

在核准執行前，須逐項確認（可勾選或於 audit 中記錄結果）：

| # | 檢查項 | 說明（概要） |
|---|--------|----------------|
| 1 | **是否為 PROD？** | 明確標示目標環境；非 PROD 仍須依環境政策執行，但不得混淆環境。 |
| 2 | **是否確認 Server 是主機 B SQL Server？** | 連線目標須與變更請求／proposal 一致，且為既定之主機 B 正式實例（或該次核准所指之實例）。 |
| 3 | **是否確認 Database 名稱正確？** | 與 DB Change Request、proposal JSON 一致。 |
| 4 | **是否確認不是 master / tempdb / model / msdb？** | 不得對系統資料庫執行應用 schema 變更。 |
| 5 | **是否確認不是錯誤 DB？** | 二次確認連線字串／目錄物件所指向之資料庫無誤。 |
| 6 | **是否已建立 .bak？** | 依專案備份策略執行備份程序。 |
| 7 | **是否已確認 .bak 檔案存在？** | 備份產物存在且可驗證（路徑／大小／校驗依專案規範）。 |
| 8 | **是否已匯出 before schema-only.sql？** | 變更前僅結構之快照已產出並歸檔（大檔不強制入 Git，但須可稽核指向）。 |
| 9 | **是否已產生 Plan Report？** | Plan Mode 已完成且報告可用；Plan 階段**不執行**變更。 |
| 10 | **是否已通過 safety gate？** | 所有自動與人工 safety 檢查通過。 |
| 11 | **是否 migration hash 一致？** | 待執行內容與核准時綁定之 hash 相符。 |
| 12 | **是否有 human approval code？** | 具備有效之人類核准碼／憑證。 |
| 13 | **是否已檢查 git status？** | 工作區狀態與本次變更版本一致，無未預期變更。 |
| 14 | **是否沒有修改 .env？** | 執行路徑不得依賴未經審核之環境檔變更（本次流程不應改 `.env`）。 |
| 15 | **是否沒有 raw SQL mode？** | PROD 禁止任意 SQL 即執行模式。 |
| 16 | **是否通過 tenant / sno 檢查？** | 變更與資料影響範圍符合租戶／sno 治理。 |
| 17 | **是否確認沒有 DROP / DELETE / TRUNCATE / ALTER COLUMN / DROP COLUMN？** | 若**有**任一項，須已走**人工高風險審核**且仍未通過 preflight 則**不得**執行；預設情境下執行前須確認本次核准內容**不含**未審批之上述操作。 |
| 18 | **是否確認 proposal JSON 與 migration plan 一致？** | 欄位、物件與動作與核准計畫一致。 |
| 19 | **是否確認 affected systems？** | 與 proposal 中 `affectedSystems` 及影響評估一致。 |
| 20 | **是否已有 rollback plan？** | 已具備經核准之回滾或復原計畫。 |
| 21 | **是否確認本次變更不會繞過 Migration 5.x Kernel？** | 僅能透過 Kernel 允許之路徑執行。 |

---

## 相關文件

- **[MIGRATION_5_POLICY.md](./MIGRATION_5_POLICY.md)** — Plan / Execute、safety gate、hash、approval。
- **[DB_CHANGE_REQUEST_POLICY.md](./DB_CHANGE_REQUEST_POLICY.md)** — DB Change Request 與必要產物。
- **[RISK_RULES.md](./RISK_RULES.md)** — 風險分級與高風險操作審核。
- **[TODO_RULES.md](./TODO_RULES.md)** — 四階段升級與第一階段邊界。
