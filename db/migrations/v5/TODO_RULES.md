# SQL Safe Migration 5.0 — To Do List 規則

本文件定義 **5.0** 升級之階段劃分與各階段允許／禁止事項，作為專案內 To Do 與範圍控管之依據。屬第一階段規劃文件，**不**修改 **SQL Migration 4.5** 之正式執行流程。

---

## 總則

**SQL Safe Migration 5.0 必須分四階段升級，不得跳過第一階段直接進入腳本實作或正式啟用。**

（階段名稱與細則亦見 **[UPGRADE_PHASES.md](./UPGRADE_PHASES.md)**。）

---

## 第一階段：5.0 規劃文件版

- 只做**文件**與**安全規格**
- **不碰**正式 DB
- **不執行** SQL
- **不修改** `.env`
- **不** `push`

---

## 第二階段：5.0 腳本實作版

- 實作 **Plan Mode**
- 實作 **Proposal JSON Checker**
- 實作 **Risk Checker**
- 實作 **Hash Lock**
- 實作 **Approval Code** 檢查
- 實作 **DB Connection Guard**
- 實作 **Tenant / sno Checker**
- **仍不得**直接改正式 DB

---

## 第三階段：5.0 測試版

- **只在**測試 DB 或測試環境驗證
- 測試 **Low / Medium / High / Critical** 規則（見 **[RISK_RULES.md](./RISK_RULES.md)**）
- 測試 **rollback**
- 測試 **schema-only.sql** before / after **diff**
- 測試 **audit log**

---

## 第四階段：5.0 正式啟用版

- **正式 DB** 才允許透過 **5.0 Kernel** 執行（與 4.5 執行核心之銜接依專案實作）
- 禁止 **AI / Cursor / 人工** 繞過 **5.0** 直接改 schema
- 正式 DB **禁止** raw SQL mode
- 所有變更必須有 **DB Change Request**、**proposal**、**backup**、**schema diff**、**audit log**（完整產物見 **[DB_CHANGE_REQUEST_POLICY.md](./DB_CHANGE_REQUEST_POLICY.md)**）

---

## 執行前提醒

進入 Execute Mode 前必須完成 **[PREFLIGHT_CHECKLIST.md](./PREFLIGHT_CHECKLIST.md)**；**任一項不通過不得執行**。
