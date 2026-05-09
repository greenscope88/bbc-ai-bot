# SQL Safe Migration 5.x（`db/migrations/v5`）

## 沿革

**SQL Safe Migration 5.x** 是由 **4.5** 升級而來。

- **4.5** 是安全 migration 執行核心：負責以受控、可稽核的方式執行資料庫結構與資料變更。
- **5.x** 是防止 AI、Cursor 或人工在未經治理流程下直接改壞正式 DB 的**防爆治理系統**：在 4.5 的執行能力之上，補齊提案、審查、環境隔離與禁止直連正式 schema 變更等規範與機制。

## 環境角色

- **主機 A**：migration 控制中心（腳本、流程、版本庫與治理相關資產所在）。
- **主機 B**：Microsoft SQL Server **正式資料庫**所在位置。

## AI 與自動化邊界

- **AI 只能提出 migration proposal**（提案、草稿、建議變更說明），**不得直接執行正式 DB 的 schema 變更**。
- 任何對正式 DB 的變更須遵循專案內已定義的安全 migration 流程與人為把關，而非由 AI 或工具略過流程直連執行。

## 升級階段

四階段流程與強制規則請見同目錄 **[UPGRADE_PHASES.md](./UPGRADE_PHASES.md)**。
