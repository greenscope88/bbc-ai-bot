# Migration 5.x 治理政策（SQL Safe Migration）

本文件定義 **Migration 5.x Kernel** 與正式環境資料庫變更的強制規則。本政策為規劃文件，不取代既有 **SQL Migration 4.5** 之正式執行流程；5.x 在 4.5 之上疊加治理與防爆邊界，**不得**在未經專案核准下改動 4.5 的既定執行管線。

---

## 核心聲明

**AI 可以提出建議，但不能直接執行正式 DB schema 變更。**

---

## 政策條款

1. **AI / Cursor 不得直接修改正式 SQL Server schema**  
   任何對正式環境結構的變更須經本專案定義之流程與工具鏈，不得由 AI、IDE 或腳本略過治理直連 PROD 執行 DDL/DML。

2. **正式 DB schema 變更只能透過 Migration 5.x Kernel**  
   經核准之變更須由 **Migration 5.x Kernel** 統一編排與執行（與 4.5 執行核心銜接之方式以專案實作為準），不得使用未納入 Kernel 的路徑對正式 schema 生效。

3. **PROD 正式 DB 禁止 raw SQL mode**  
   正式環境不允許以「任意輸入 SQL 即執行」的模式變更結構或資料；僅允許通過審核、雜湊驗證與安全閘門後的受控執行。

4. **AI 只能產生 migration proposal JSON**  
   AI 產出物限定為結構化之 **migration proposal**（例如 JSON 格式），供人類審查與後續納入 Kernel；不得將 AI 輸出視為可直接對 PROD 執行之指令。

5. **AI 不得直接產生正式 DB 可執行 SQL**  
   針對正式庫，AI 不得產出可供直接貼上執行之完整 SQL 腳本作為「執行依據」；實際 migration SQL 須由核准流程產生並與 proposal / 審核紀錄對齊。

6. **所有變更必須先進入 Plan Mode**  
   未經 **Plan Mode** 之變更不得進入執行階段。

7. **Plan Mode 只分析，不執行**  
   Plan Mode 僅進行影響分析、風險與相依評估、產出計畫與報告；**不**對正式 DB 執行任何變更。

8. **Execute Mode 必須通過 approval code、migration hash、backup、schema snapshot、safety gate**  
   執行階段須同時滿足：有效 **approval code**、**migration hash** 驗證、**備份（.bak）**、**schema snapshot**、以及 **safety gate** 檢查；任一未通過則不得執行。

9. **高風險操作預設禁止自動執行**  
   下列類型**預設禁止**自動執行（須額外審批與明確解除，且仍須符合 Kernel 與變更請求政策）：  
   `DROP TABLE`、`DROP COLUMN`、`ALTER COLUMN`、`TRUNCATE`、`DELETE`、`UPDATE`、`MERGE`。

10. **sa 帳號不得作為日常 migration 執行帳號**  
    正式環境 migration 執行應使用最小權限、專用之服務帳號；**不得**例行使用 **sa**。

11. **每次 DB 變更都必須產生 audit log**  
    執行前後須留下可稽核之 **audit log**（誰、何時、何變更、何環境、何核准）。

12. **每次 DB 變更都必須納入 Git 管理與審核**  
    與該次變更相關之產物（proposal、報告、核准紀錄、腳本版本等，依專案規範）須進入版本庫並經審核；**不得**將大量二進位備份、完整 schema dump、或日誌大檔不當納入 Git（參考 `.gitignore` 與專案資產存放規範）。

13. **正式 DB 不接受繞過 Migration 5.x 的人工直改**  
    不得以「緊急」「方便」為由在 PROD 手動執行 DDL 或繞過 Kernel；若屬緊急，仍須事後補齊變更請求、紀錄與版本庫對齊，並依專案程序檢討。

---

## 與 DB Change Request 的關係

資料庫變更之請求、產物清單與主機分工請見 **[DB_CHANGE_REQUEST_POLICY.md](./DB_CHANGE_REQUEST_POLICY.md)**。
