# Migration 風險分級規則（規劃版）

本文件定義資料庫變更之 **risk level**，供 **migration proposal JSON**（見 **[PROPOSAL_SCHEMA.md](./PROPOSAL_SCHEMA.md)**）與審核流程使用。屬 **SQL Safe Migration 5.0** 第一階段規劃文件，**不**修改 **SQL Migration 4.5** 之正式執行流程。

---

## Low

符合下列**典型**特徵者，可標示為 **Low**（仍須依個案確認）：

- 新增 **nullable** 欄位
- 新增**非破壞性** index（不造成大規模重建風險、或專案定義之安全 index 變更）
- **不影響**既有資料（無強制回填、無破壞性約束）
- **不影響**舊 ASP、API、AI 查詢之行為與效能假設（經評估確認）

---

## Medium

下列情形**通常**至少為 **Medium**：

- 新增 **NOT NULL** 欄位（常伴隨回填、預設值、約束與部署順序）
- 新增 **default constraint**
- 新增 **foreign key**
- **修改** index（重建、鍵序或篩選條件變更等）
- **可能造成** table lock 或長時間鎖定
- **可能影響**既有 insert / update 流程或應用假設

---

## High

下列情形**通常**為 **High**：

- **ALTER COLUMN**
- **DROP COLUMN**
- **資料搬移**（表間或批次搬移、結構重整伴隨資料遷移）
- **會影響**舊 ASP 前台
- **會影響**舊 ASP 後台
- **會影響** API
- **會影響** AI 查詢
- **會影響** tenant / sno 邏輯

---

## Critical

下列情形**通常**為 **Critical**：

- **DROP TABLE**
- **DROP DATABASE**
- **TRUNCATE TABLE**
- **DELETE**
- **UPDATE**
- **MERGE**
- **任何可能大量影響正式資料**的操作
- **任何沒有** `WHERE` **sno / tenant** 條件之**大量資料異動**
- **任何可能跨租戶影響資料**的操作

---

## 自動執行與審核（強制）

- **High / Critical：預設不得自動執行。**
- **Critical：預設禁止**（須專案定義之例外與最高層級審批，且仍須完整產物與稽核；實務上應極少開放）。
- **任何 DROP、DELETE、TRUNCATE、ALTER COLUMN、DROP COLUMN** 都必須經**人工高風險審核**。
- **正式 PROD DB 不允許 AI 自動執行 High / Critical migration**（含產生可執行 SQL 並觸發執行）。
- **沒有** `WHERE` **sno / tenant** 條件之**大量資料異動**視為 **Critical**。

---

## 與 proposal 的關係

- Proposal 上之 `riskLevel` 須與本文件一致；若有歧義，以**較嚴格**等級或人工裁定為準。
- 實際是否可進入 Execute Mode，仍須符合 **[MIGRATION_5_POLICY.md](./MIGRATION_5_POLICY.md)** 之 Plan / Execute、safety gate 與核准要求。
