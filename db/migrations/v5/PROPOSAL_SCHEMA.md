# Migration Proposal JSON Schema（規劃版）

本文件定義 **migration proposal JSON** 之欄位與語意，屬 **SQL Safe Migration 5.0** 第一階段規劃文件。

## 原則

- **AI 只能產生 JSON proposal，不直接產生正式 DB 可執行 SQL。**
- Proposal 經審核通過後，**未來**由專案內之**安全 Builder**（或同等元件）產生實際 **migration SQL**；proposal 本身**不是**可對 PROD 直接執行之腳本。
- **Proposal JSON 不等於 migration SQL。**
- **AI 不得繞過 proposal JSON 直接輸出正式 DB 可執行 SQL。**

本文件**不**修改 **SQL Migration 4.5** 之正式執行流程；proposal 與後續 Builder 之銜接方式以第二階段以後之實作為準。

---

## 欄位定義（至少須包含）

| 欄位 | 型別（建議） | 說明 |
|------|----------------|------|
| `requestId` | string | 對應 DB Change Request 或內部編號（如 `DBCR-YYYYMMDD-序號`）。 |
| `environment` | string | 目標環境代碼，例如 `DEV` / `STG` / `PROD`。 |
| `server` | string | 目標 SQL Server 識別名稱（與專案命名一致）。 |
| `database` | string | 目標資料庫名稱。 |
| `table` | string | 主要影響之資料表。 |
| `action` | string | 變更動作代碼，例如 `ADD_COLUMN`、`CREATE_INDEX`（實際列舉以 Kernel 為準）。 |
| `column` | string \| null | 欄位名稱；非欄位層級變更可為 `null`。 |
| `dataType` | string \| null | SQL Server 型別字串，例如 `nvarchar(100)`。 |
| `nullable` | boolean \| null | 是否允許 NULL。 |
| `defaultValue` | string \| number \| boolean \| null | 預設值描述或字面量；無則 `null`。 |
| `reason` | string | 變更原因／業務說明。 |
| `tenantScope` | string | 租戶範圍說明，例如 `single_or_multi_tenant`。 |
| `snoRequired` | boolean | 是否涉及或必須符合 `sno` 治理／條件。 |
| `affectedSystems` | array of string | 受影響系統清單（如舊 ASP 前後台、API、AI Query）。 |
| `riskLevel` | string | 風險分級，須與 **[RISK_RULES.md](./RISK_RULES.md)** 一致。 |
| `generatedBy` | string | 產生來源，例如 `Cursor AI`、工具名稱或人員 id。 |
| `createdAt` | string | ISO 8601 本地或 UTC 時間戳，例如 `YYYY-MM-DDTHH:mm:ss`。 |
| `requiresApproval` | boolean | 是否須經核准方可進入 Execute。 |
| `approvalCode` | string \| null | 核准碼；未核准前為 `null`。 |
| `rollbackPlanRequired` | boolean | 是否強制要求附帶 rollback 計畫。 |

---

## 風險與動作（說明）

- **ADD_COLUMN** 且 **`nullable: true`**：通常對既有資料破壞性低，**通常視為 Low risk**（仍須依實際影響與 **[RISK_RULES.md](./RISK_RULES.md)** 複核）。
- **NOT NULL** 欄位（含新增 NOT NULL 或後續改為 NOT NULL）：常需回填、約束與鎖定考量，**通常至少是 Medium risk**。
- **`ALTER COLUMN` / `DROP COLUMN`**：**不得自動執行**；須人工高風險審核與專案程序（見 RISK_RULES）。

---

## 範例：`ADD_COLUMN`

```json
{
  "requestId": "DBCR-20260509-001",
  "environment": "PROD",
  "server": "HostB-SQLServer",
  "database": "Buysmart",
  "table": "Member",
  "action": "ADD_COLUMN",
  "column": "line_user_id",
  "dataType": "nvarchar(100)",
  "nullable": true,
  "defaultValue": null,
  "reason": "Support LINE Login binding",
  "tenantScope": "single_or_multi_tenant",
  "snoRequired": true,
  "affectedSystems": ["Old ASP Frontend", "Old ASP Backend", "API", "AI Query"],
  "riskLevel": "Low",
  "generatedBy": "Cursor AI",
  "createdAt": "YYYY-MM-DDTHH:mm:ss",
  "requiresApproval": true,
  "approvalCode": null,
  "rollbackPlanRequired": true
}
```

> **注意**：`createdAt` 實務上應替換為真實時間戳；上列 `YYYY-MM-DDTHH:mm:ss` 僅示占位格式。

---

## 相關文件

- **[RISK_RULES.md](./RISK_RULES.md)** — 風險分級與自動執行禁令。
- **[MIGRATION_5_POLICY.md](./MIGRATION_5_POLICY.md)** — Kernel、Plan / Execute、AI 邊界。
- **[DB_CHANGE_REQUEST_POLICY.md](./DB_CHANGE_REQUEST_POLICY.md)** — DB Change Request 與產物清單。
