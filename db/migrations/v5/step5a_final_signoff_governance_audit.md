# SQL Safe Migration 5.0 — Phase 5 Step 5-A  
## Final Sign-Off Governance Planning Audit

**Date:** 2026-05-12  
**Nature:** Inventory and design report only. No edits to `.ps1`, tests, or `.json`. No SQL. No `git add` / `git commit` / `git push`.

**Sources reviewed:** `PRODUCTION_LIVE_EXECUTION_POLICY.md`, `PRODUCTION_ACTIVATION_CHECKLIST.md`, `PHASE5_IMPLEMENTATION_PLAN.md`, `contracts/governed_migration_input.schema.json`, `approval_gate.ps1`, `invoke_governed_migration.ps1`, `step4c_live_guard_safety_regression_audit.md`.

---

## 1. Phase 5 文件中 Final Sign-Off 的要求整理

| 來源 | Final Sign-Off 相關要點 |
|------|-------------------------|
| **PRODUCTION_LIVE_EXECUTION_POLICY.md §7** | Sign-Off 是啟用 `LIVE_EXECUTE` 與（若適用）`production-live-enabled = YES` 的**最終人工作業**；須同時滿足：(1) `PRODUCTION_ACTIVATION_CHECKLIST.md` 全數完成並留存證明；(2) 政策 §2–3 條件與檢查滿足且有稽核產物；(3) **指定 Sign-Off 人員**書面或等效系統確認；(4) **EnableLiveExecution** 與 Sign-Off **同一變更單**或可追溯關聯；(5) **回滾與通訊計畫**已確認。未完成不得視為已核准 production live。 |
| **PRODUCTION_ACTIVATION_CHECKLIST.md §2.7–2.8** | **C-17** Final sign-off completed（指定 Sign-Off 人員）；**C-18** 啟用後驗證責任人；§4 建議附件含核准與 **Sign-Off 紀錄**；明述 **AI 不得**代勾 Human approvals 或 Final sign-off。 |
| **PHASE5_IMPLEMENTATION_PLAN.md §3、§5、§7** | **Sign-Off 關聯**：獨立 `signoff_record` 或欄位群，由 **wrapper 驗證**存在性與未過期（規劃）；Sign-Off **ID** 寫入 audit report 與 governed migration 輸出；DoD 要求 audit report 可證明 **Sign-Off 參照**。 |
| **step4c_live_guard_safety_regression_audit.md §12** | Step 5 應以**政策、證據、角色簽核**為主；**不應**將 `approval_gate` 的 `approved: true` 等同「已核准上線執行」；真實執行模組應獨立子階段與 CAB。 |

**收斂：** 現行制度要求「清單 + 證據 + 指定人員 + 與開關／變更單綁定 + 稽核可追溯」。技術契約已有 `finalSignOff` 物件，但與政策中的「Sign-Off 紀錄 ID 入報告」「時間盒」「獨立 signoff_record」等仍有**規劃缺口**，適合在 Step 5-B 起分階段補齊。

---

## 2. `governed_migration_input.schema.json` 中 `finalSignOff` 欄位目前結構

`finalSignOff` 為 **object**，`additionalProperties: false`。

| JSON Schema | 說明 |
|-------------|------|
| **required（子欄位）** | `approved`（boolean）、`approvedBy`（string, minLength 1）、`approvedAt`（string, **format: date-time**）、`ticketId`（string, minLength 1；描述為 Change / CAB / sign-off record id）。 |
| **無其他 properties** | 目前**未**在 schema 的 `finalSignOff` 內定義 `riskAcceptedBy`、`productionOwner`、`rollbackPlanReviewed`、`recoveryModeAcknowledged` 等；這些若納入正式 Sign-Off，需 schema 版本化擴充（例如 `contractVersion` 與 `additionalProperties` 策略）。 |

**相關但不在 `finalSignOff` 內的欄位：** `changeRequestId` 位於 **`auditMetadata.required`**；`migrationFile` 為合約**頂層** required。

---

## 3. `approval_gate.ps1` 目前是否驗證 `finalSignOff`

**有，但範圍有限。**

- 合約必須**包含**屬性 `finalSignOff`（欄位存在檢查）。
- 當 **`environment -eq "PRODUCTION"`** 時：檢查 `finalSignOff` 非 null，且 **`finalSignOff.approved -eq $true`**；否則 `Write-GateFailure`（PRODUCTION requires finalSignOff.approved true）。
- **未**對 `approvedBy`、`approvedAt`、`ticketId` 做逐欄非空或格式驗證（該等約束主要依賴 **JSON Schema** 若上游有執行 schema 校驗；**gate 腳本本身**未重複驗證子欄位）。
- 非 PRODUCTION 環境：僅要求合約存在 `finalSignOff` 鍵，**不**強制 `approved` 為 true（與現有測試設計一致）。

---

## 4. `invoke_governed_migration.ps1` 目前是否驗證 `finalSignOff`

**有，且僅驗證核准旗標。**

- 在 **`LIVE_EXECUTE`** 分支中（合約已載入後）：取得 `$contract.finalSignOff`，若為 null 或 **`approved -ne $true`** → `FailLive`（訊息：`contract.finalSignOff.approved must be true`）。
- **未**在 wrapper 內再次驗證 `approvedBy` / `approvedAt` / `ticketId` 等（與 gate 類似，假設合約＋／或 schema 已把關）。
- **MOCK／DRY_RUN** 路徑使用 Phase 4 payload，**不**讀取合約檔，故**無**對 `finalSignOff` 的直接檢查（與政策中「Sign-Off 主要綁在 production live 路徑」一致）。

---

## 5. `FinalManualConfirm` 與 `finalSignOff` 的差異

| 維度 | `finalSignOff`（合約 JSON） | `FinalManualConfirm`（wrapper 參數） |
|------|------------------------------|--------------------------------------|
| **載體** | 版本化 governed migration **合約**的一部分，可存檔、比對、納入稽核 bundle。 | **命令列** `-FinalManualConfirm` 字串；與執行當下操作者綁定。 |
| **語意** | 組織流程上的 **CAB／變更單／簽核紀錄** 的結構化聲明（who / when / ticket）。 | **執行者當下**對「此乃 production live 演練／行為」之**顯式型斷言**（固定魔術字串）。 |
| **驗證位置** | `approval_gate`（PRODUCTION 時 `approved`）、`invoke_governed_migration` LIVE 鏈（`approved`）。 | 僅在 **`invoke_governed_migration.ps1`** LIVE 分支與固定字串比對。 |
| **是否等同「已核准執行 SQL」** | **否**；僅為資料與流程證明之一部。 | **否**；僅降低誤操作機率，**不**啟用目前 skeleton 後之 SQL 執行（見 Step 4-C 稽核）。 |

兩者互補：**finalSignOff** 偏「組織簽核軌跡」，**FinalManualConfirm** 偏「執行入口人為防呆」。

---

## 6. 建議正式 Final Sign-Off 必須包含的欄位（設計建議）

以下為 **Step 5-A 規劃建議**，供 Step 5-B 與 schema／validator 對齊時採用；**現有 schema 未必已全部涵蓋**。

| 欄位 | 建議用途 |
|------|----------|
| **approved** | 布林：最終核准是否成立（與現制一致）。 |
| **approvedBy** | 指定 Sign-Off 責任人 principal（與政策「指定 Sign-Off 人員」對齊）。 |
| **approvedAt** | ISO-8601：Sign-Off 時間（支援時間盒與稽核）。 |
| **ticketId** | CAB／變更／簽核紀錄單號（與 EnableLiveExecution 綁定追溯）。 |
| **changeRequestId** | 建議**維持於 `auditMetadata`**（schema 已 required），Sign-Off 報告與 policy 敘述須**交叉引用**同一 ID，避免重複定義不一致。 |
| **migrationFile** | 建議**維持合約頂層**（schema 已 required）；Sign-Off 文件應明示「本次簽核涵蓋之 migration 路徑」與其一致。 |
| **riskAcceptedBy** | （建議新增）風險承擔方／業務或技術 owner，與 checklist C-04～C-06 呼應。 |
| **productionOwner** | （建議新增）production 變更 owner，利於通訊鏈（checklist C-12、C-18）。 |
| **rollbackPlanReviewed** | （建議新增）布林或 `{ reviewed: bool, reviewedBy, reviewedAt }`，對齊 policy §7(5) 與 checklist C-12。 |
| **recoveryModeAcknowledged** | （建議新增）布林或簽名參照，對齊 Recovery Mode A 與 checklist C-10。 |

**實作策略建議：** 若避免過度膨脹單一物件，可採 **`finalSignOff`（核心四欄）** + **`finalSignOffExtensions` 或 `signoffRecordRef`（外部檔案／票證 ID）** 模式，與 `PHASE5_IMPLEMENTATION_PLAN.md` 之 `signoff_record` 方向一致。

---

## 7. 哪些欄位應由人工提供

| 類別 | 欄位／內容 | 理由 |
|------|------------|------|
| **必須人工** | `approved`、`approvedBy`、`approvedAt`、`ticketId`（或等效票證）；`humanApprovals` 各筆；`backupConfirmation.verifiedBy`；`FinalManualConfirm` 魔術字串之「決定是否輸入」 | 政策與 checklist 明定 AI 不得代勾／代簽；需可事後稽核之身分與時間。 |
| **必須人工（流程）** | `riskAcceptedBy`、`productionOwner`、回滾計畫已審閱、Recovery Mode A 已溝通 | 制度面責任歸屬，不應由工具自動填「true」。 |
| **人工確認／選取** | `migrationFile` 指向之審核版本、維護窗口核准 | 與實際將執行之 artifact 一致。 |

---

## 8. 哪些欄位可由系統產生

| 類別 | 範例 | 條件 |
|------|------|------|
| **系統時間戳** | 報告 `generatedAt`、gate `timestamp` | 由腳本寫入，**不作為**取代 `approvedAt` 之 Sign-Off 時間，除非組織明定「以系統收單時間為準」。 |
| **執行嘗試紀錄** | audit report 路徑、檢查結果彙總、開關狀態（allowed／denied，不含 secret） | Phase 5 計畫 §6。 |
| **衍生／檢查產物** | recovery readiness 報告路徑、`recoveryReadiness.status` 之前置檢查輸出 | 可由 checker 產出；**PASS 與否**仍須符合人工作業與證據。 |
| **proposal 指紋（規劃中）** | `PHASE5_IMPLEMENTATION_PLAN.md` 提及之核准範圍雜湊 | 實作階段由系統計算，用於偵測合約與 SQL 漂移。 |

---

## 9. 是否需要新增 `final_signoff_validator.ps1`

**建議：是（在 Step 5-B 或之後子步驟）。**

**理由：**

- 與 `maintenance_window_validator.ps1` 對稱：專責 **Sign-Off 結構、時間盒、與 `auditMetadata.changeRequestId`／`ticketId` 一致性** 等，**plan-only、無 SQL**。  
- `approval_gate.ps1` 已承擔廣泛合約檢查；獨立 validator 可讓 **Final Sign-Off 規則演進**時少改 gate、測試邊界更清楚。  
- 可選擇由 **`invoke_governed_migration.ps1` 在 LIVE 鏈中於 gate 之後或之前** 呼叫（順序於設計書明定）。

**若不新增：** 則須強化 `approval_gate.ps1` 或嚴格上游 **JSON Schema 校驗** + 測試，避免 `finalSignOff` 子欄位在 gate 中成為「暗門」。

---

## 10. 是否需要新增 `FINAL_PRODUCTION_SIGNOFF_TEMPLATE.md`

**建議：是。**

**理由：**

- `PRODUCTION_ACTIVATION_CHECKLIST.md` 為勾選表；**模板**可補上「每次 Sign-Off 必抄寫／貼上之欄位清單、票號、證據路徑、與 checklist 列號對照」。  
- 降低營運解讀落差（與 Step 4-C 建議之「避免誤解 skeleton pass」一致）。  
- 模板應重申：**簽核完成 ≠ 已執行 SQL**；並指向 policy §7 與 wrapper 行為說明。

---

## 11. 是否需要補強 `PRODUCTION_ACTIVATION_CHECKLIST.md`

**建議：是（文件層級，非本步驟執行）。**

**可補強方向：**

| 項目 | 說明 |
|------|------|
| **與合約欄位對照表** | 增加「C-xx ↔ `governed_migration_input` 欄位」一欄，便於附件檢核。 |
| **Sign-Off 證據格式** | 明列可接受形式（工單 URL、簽核 PDF 路徑、`signatureRef` 慣例）。 |
| **與 `EnableLiveExecution` 關聯** | C-16 與 policy L-03、§7(4) 用語對齊；註明 skeleton 階段與未來真執行階段差異。 |
| **執行後驗證** | C-18 與 `production-live-enabled` 宣告責任（見計畫 §5、§7）。 |

---

## 12. Step 5-B 建議修改哪些檔案（僅規劃）

| 優先序 | 檔案 | 建議方向 |
|--------|------|----------|
| 高 | `contracts/governed_migration_input.schema.json` | 版本化擴充 `finalSignOff` 或新增 `signOffRecord`／extensions；與範例 JSON 同步。 |
| 高 | `FINAL_PRODUCTION_SIGNOFF_TEMPLATE.md`（新建） | 人類 Sign-Off 與證據列表模板。 |
| 中 | `PRODUCTION_ACTIVATION_CHECKLIST.md` | 對照表與 Sign-Off 證據小節。 |
| 中 | `final_signoff_validator.ps1`（新建） | plan-only 驗證；對應 `tests/test_final_signoff_validator.ps1`（新測試，於 5-B 一併規劃）。 |
| 中 | `approval_gate.ps1` | 選項 A：委託給 validator；選項 B：在 gate 內補齊 `finalSignOff` 子欄位與時間盒（與現有測試相容性須評估）。 |
| 中 | `invoke_governed_migration.ps1` | 在 LIVE 鏈插入 `final_signoff_validator`（若採用）；報告輸出帶入 `ticketId`／`signOffRef`（與計畫 §5–6）。 |
| 中 | `report_generator.ps1` | 稽核輸出含 Sign-Off 摘要欄位（計畫 Step 6）。 |
| 低 | `PHASE5_IMPLEMENTATION_PLAN.md` / `PRODUCTION_LIVE_EXECUTION_POLICY.md` | 用語與 DoD 與實際欄位同步。 |

---

## 13. Step 5-B 安全修改範圍

| 允許／建議 | 禁止或需 CAB |
|------------|----------------|
| **文件、schema 草案、plan-only validator、測試** | 在組織未核准前，**不**新增實際 SQL 執行、**不**將 `LIVE_EXECUTE` 改為 `executed: true`、**不**寫入含 secret 的 `.env`。 |
| **維持預設 deny**：開關 OFF、wrapper 拒絕不完整 LIVE | **不**以單一 PR 同時「開 live」與「改文件」混雜；真連線應獨立子階段。 |
| **測試**：負向為主 + mock 合約 | Production 連線測試僅手動、有人在場（計畫 Step 5）。 |

---

## 14. 是否仍應維持 `LIVE_EXECUTE` 下 `executed=false`

**建議：在組織正式核准「真實執行子階段」之前，應維持 `executed=false`。**

- **現狀**（Step 4-C 稽核）：skeleton 路徑終點為 `FailLiveSkeletonPassed`，`executed` 與 `liveExecutionEnabled` 皆為 false。  
- **未來**若啟用真執行：應以**獨立旗標／獨立模組／獨立測試矩陣**引入，並在 policy 與 checklist 中重新定義「何時得為 true」，避免與現有 CI／營運解讀衝突。

---

## 附錄：本步驟執行聲明

- **本 Step 5-A** 僅新增本檔案 `step5a_final_signoff_governance_audit.md`。  
- 未修改任何 `.ps1`、既有測試、或 `governed_migration_input.schema.json`／example JSON。  
- 未執行 SQL；未執行 `git add` / `commit` / `push`。  
- 本報告**未**強制執行測試指令（5-A 為規劃稽核）；迴歸測試留待 Step 5-B 或 CI 排程。
