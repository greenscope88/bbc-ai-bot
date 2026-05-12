# Final Production Sign-Off — SQL Safe Migration 5.0

**文件類型：** Phase 5 — 人工作業模板（**非**程式輸入契約；請將實際值填入治理合約 JSON 與附件）  
**用途：** 支援 `PRODUCTION_ACTIVATION_CHECKLIST.md` 之 **C-17 Final sign-off** 與稽核留存  
**重要：** 簽核完成 **不代表** `LIVE_EXECUTE` 已啟用，亦 **不代表** 已獲授權執行 production SQL（見第 13 節）

---

## 1. Change Request ID

- **欄位（合約）：** `auditMetadata.changeRequestId`  
- **填寫：**  
- **須與下列一致：** Proposal／工單／版本控制標籤  

---

## 2. Migration File

- **欄位（合約）：** `migrationFile`（repo 相對或組織同意之路徑）  
- **填寫：**  
- **已審核版本識別（commit／tag／PR）：**  

---

## 3. Production Owner

- **語意：** production 變更責任窗口（通訊／決策）  
- **填寫（姓名／帳號／角色）：**  
- **聯絡方式：**  

---

## 4. Risk Accepted By

- **語意：** 已知風險之承擔方（業務或技術 owner，依組織定義）  
- **填寫：**  
- **核准證據參照（工單連結／簽核檔 id）：**  

---

## 5. Approved By

- **欄位（合約）：** `finalSignOff.approvedBy`（指定 Sign-Off 人員）  
- **填寫：**  
- **職稱／職能：**  

---

## 6. Approved At

- **欄位（合約）：** `finalSignOff.approvedAt`（**ISO-8601** date-time，建議 UTC）  
- **填寫：**  

---

## 7. Ticket ID

- **欄位（合約）：** `finalSignOff.ticketId`（CAB／變更／簽核紀錄單號）  
- **填寫：**  

---

## 8. Rollback Plan Reviewed

- **語意：** 回滾步驟已與 DBA／運維審閱並可於執行當下取用  
- **填寫（是／否）：**  
- **計畫存放路徑／工單連結：**  
- **審閱人：**  

---

## 9. Recovery Mode Acknowledged

- **語意：** 知悉 **Recovery Mode A**（AI 不執行 production restore／rollback；須人工確認與下達）  
- **填寫（是／否）：**  
- **確認人：**  

---

## 10. Maintenance Window Confirmed

- **欄位（合約）：** `maintenanceWindow`（`approved`、`windowStart`、`windowEnd`、`approvedBy`）  
- **窗口起訖（UTC）：**  
- **核准人：**  
- **利害關係人通知完成（是／否）：**  

---

## 11. Backup Confirmed

- **欄位（合約）：** `backupConfirmation`（`backupFile`、`createdAt`、`verifiedBy`）  
- **備份路徑／代碼：**  
- **建立時間：**  
- **核實人：**  

---

## 12. Final Human Signature

- **欄位（合約）：** `finalSignOff.approved` 須為 **`true`**（與本模板第 5–7 節一致）  
- **簽署聲明（可貼入工單）：**  
  > 本人確認：Change Request、Migration File、維護窗口、備份與回滾計畫與本次變更一致；並知悉簽核不構成自動執行 production SQL。  
- **簽名／日期（紙本或等效電子簽核）：**  

---

## 13. 注意：簽核完成不代表 LIVE_EXECUTE 已啟用

- **本模板與合約欄位**僅用於治理與稽核軌跡；**不得**單憑 `finalSignOff.approved: true` 解讀為已啟用 **`LIVE_EXECUTE`** 或 **`EnableLiveExecution`**。  
- **實際執行授權**須符合 `PRODUCTION_LIVE_EXECUTION_POLICY.md`、`PHASE5_IMPLEMENTATION_PLAN.md` 與組織變更流程；技術開關須另有核准與紀錄。  
- 可使用 **`final_signoff_validator.ps1`** 對合約做 **plan-only** 欄位檢查；該工具 **不** 連線資料庫、**不** 執行 SQL，且輸出中 **`liveExecutionEnabled` 恒為 `false`**。

---

*完成本模板後，請將對應值寫入 `governed_migration_input` 合約 JSON，並依 `PRODUCTION_ACTIVATION_CHECKLIST.md` 留存證據附件。*
