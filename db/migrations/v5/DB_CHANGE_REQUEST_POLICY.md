# DB Change Request 政策（主機 A / 主機 B 分工）

本文件定義 **資料庫變更請求（DB Change Request）** 流程與主機角色。本政策為 **SQL Safe Migration 5.0** 第一階段規劃文件，**不**修改 **SQL Migration 4.5** 之正式執行流程；主機 A 上之 4.5 / 5.x 執行方式仍以既有核准管線為準，本文件僅規範變更如何被提出、追蹤與審核。

---

## 角色與分工

1. **主機 B 專案不得直接修改 SQL**  
   在正式環境語境下，主機 B 上之應用或專案**不得**直接對 SQL Server 執行結構變更或繞過治理之資料變更腳本。

2. **主機 B 只提出資料庫變更需求與測試**  
   主機 B 負責業務/功能面向之**變更需求**說明、測試案例與驗收條件；**不**負責對正式庫直接執行 migration。

3. **主機 A 統一執行 SQL Migration 4.5 / 5.x**  
   所有經核准之正式 DB 變更，由 **主機 A**（migration 控制中心）統一透過 **SQL Migration 4.5** 與 **5.x** 治理鏈執行與紀錄。

4. **所有正式 DB schema 變更都要先有 DB Change Request**  
   未取得有效 **DB Change Request** 之項目，不得進入 Plan / Execute 或對 PROD schema 生效。

5. **AI / Cursor 不得跳過 DB Change Request 直接產生正式 DB 變更**  
   AI 僅能協助撰寫 proposal、分析或草稿；**不得**略過變更請求與審核，產出或執行針對正式庫之變更。

6. **所有變更必須可追蹤、可審核、可回復**  
   每一筆變更須能從請求、核准、執行紀錄一路追溯到 Git 與 audit；並具備經核准之 **rollback plan**（或同等回復策略）。

---

## 每次 DB 變更必須包含之產物

下列項目為**完整變更**之必要組成（實際儲存位置、檔名規範與是否進 Git 由專案目錄規範與 `.gitignore` 約束；**大型** `.bak`、log、完整 schema snapshot 通常**不**進版本庫，但變更紀錄中須能指向其存放處與校驗資訊）：

| 產物 | 說明 |
|------|------|
| **DB Change Request** | 變更單／請求編號與核准軌跡 |
| **migration proposal JSON** | 結構化提案，供審查與與 Kernel 對齊 |
| **Plan Report** | Plan Mode 產出之分析與結論（不執行） |
| **risk classification** | 風險分級與對應把關 |
| **migration SQL** | 經核准、與 hash 綁定之實際 migration 腳本 |
| **.bak backup** | 執行前備份（或專案等效備份策略之證明） |
| **before schema-only.sql** | 變更前 schema 快照（僅結構） |
| **after schema-only.sql** | 變更後 schema 快照（僅結構） |
| **schema diff report** | 前後差異報告 |
| **audit log** | 稽核日誌 |
| **approval code** | 執行授權碼／核准憑證 |
| **rollback plan** | 回滾或復原步驟 |
| **Git commit 記錄** | 與該次變更對應之版本庫提交（含審核軌跡） |

---

## 相關文件

- **[MIGRATION_5_POLICY.md](./MIGRATION_5_POLICY.md)** — Migration 5.x Kernel、Plan / Execute、AI 邊界與 PROD 禁令。
