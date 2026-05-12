# SQL Safe Migration 5.0 — Phase 5 Step 2-A  
# Approval Gate Compatibility Audit

**類型：** 實作前盤點／稽核報告（**僅文件**）  
**產出日期：** 2026-05-12  
**範圍：** `approval_gate.ps1` 與 `governed_migration_input.schema.json`（Phase 5）之差異與風險；**不含**程式或測試修改  

**受檢路徑註記：** 使用者清單中的 `db/migrations/v5/activation_test_suite.ps1` 於工作區**不存在**；實際激活套件為 **`db/migrations/v5/tests/activation_test_suite.ps1`**。本報告以下列實際路徑為準。

---

## 1. `approval_gate.ps1` 目前讀取哪些欄位

**結論：不讀取任何 JSON 或檔案欄位。**

現行 `approval_gate.ps1`（約 24 行）僅：

- 透過 **`Read-Host`** 讀取使用者於互動提示 `"Type YES to continue"` 下輸入的**單一字串**；
- 將該字串與常數 **`"YES"`** 做相等比較。

未使用 `Get-Content`、`ConvertFrom-Json`、參數繫結之 `$InputJsonPath`，亦未讀取 `governed_migration_input`、proposal 或 Phase 4 wrapper 輸入結構。

---

## 2. `approval_gate.ps1` 目前驗證哪些條件

| 條件 | 結果 |
|------|------|
| 使用者輸入 **等於** `YES`（大小寫敏感） | `exit 0`；輸出 JSON：`success=true`、`approved=true`、`timestamp`（ISO 8601 字串） |
| 其餘（含 `NO`、空字串、任意其他值） | `exit 1`；輸出 JSON：`success=false`、`approved=false`、`reason="User did not type YES"` |

無模式（MOCK／DRY_RUN／LIVE_EXECUTE）判斷、無環境判斷、無維護窗口、無備份／recovery／sign-off／稽核 metadata 驗證。

---

## 3. Phase 5 新 schema 新增了哪些 approval／sign-off／maintenance／backup 欄位

以下為 **`contracts/governed_migration_input.schema.json`** 相對於「現行 `approval_gate.ps1` 行為」所代表的**新增治理欄位**（腳本層尚未消費；schema 為單一 governed migration **輸入**契約）。

### 3.1 核准與簽核

| 區塊 | 內容 |
|------|------|
| **humanApprovals** | 陣列，`minItems: 2`；每筆必填 `role`、`approver`、`approvedAt`（date-time）、`signatureRef` |
| **finalSignOff** | `approved`、`approvedBy`、`approvedAt`、`ticketId` |
| **auditMetadata** | `changeRequestId`、`businessReason`、`submittedBy` |

### 3.2 維護窗口與執行開關

| 區塊 | 內容 |
|------|------|
| **maintenanceWindow** | `approved`、`windowStart`、`windowEnd`、`approvedBy` |
| **enableLiveExecution** | 布林（技術開關；schema 層必填） |
| **mode** | 含 `LIVE_EXECUTE` 之 enum |
| **environment** | `DEV` \| `STAGING` \| `PRODUCTION` |
| **migrationFile** | migration SQL 參照字串 |

### 3.3 備份與恢復就緒（與 gate 常一併納入「核准前檢查」語意）

| 區塊 | 內容 |
|------|------|
| **backupConfirmation** | `backupFile`、`createdAt`、`verifiedBy` |
| **recoveryReadiness** | `status`（`PASS` \| `FAIL` \| `NEEDS_REVIEW`）、`reportPath` |

### 3.4 選用

| 欄位 | 說明 |
|------|------|
| **contractVersion** | 選用 semver 字串 |

---

## 4. 目前 `approval_gate.ps1` 與新 schema 的落差

| 維度 | 現行 `approval_gate.ps1` | Phase 5 `governed_migration_input.schema.json` |
|------|---------------------------|--------------------------------------------------|
| **輸入模型** | 互動單行字串 | 結構化 JSON（多欄位、巢狀、強制 `humanApprovals` ≥2） |
| **核准證據** | 僅「當下有人鍵入 YES」 | 兩筆以上可稽核 `signatureRef` + 角色／時間 |
| **Sign-off** | 無 | `finalSignOff` + `auditMetadata` |
| **維護窗口** | 無 | `maintenanceWindow` |
| **備份** | 無 | `backupConfirmation` |
| **Recovery** | 無 | `recoveryReadiness`（與 Phase 4 wrapper 之 `recoveryReadiness.ready` 形狀亦不同） |
| **LIVE 開關** | 無 | `enableLiveExecution` |
| **輸出契約** | 見 `approval_gate_output.schema.json`（success／approved／reason 或 timestamp） | Schema 定義的是 **gate 的輸入側** governed migration，**非**現有 gate 輸出形狀 |

**與 `PHASE4_COMPLETION_REPORT.md` 敘述之差異：** 文件稱 gate 含「治理欄位檢查」，但**目前程式**僅互動 YES／NO，**未**實作欄位級檢查；與 Phase 5 契約落差為**輸入來源、驗證深度、與 wrapper／契約整合**三方面。

**與 `invoke_governed_migration.ps1` 之關係：** Wrapper **未呼叫** `approval_gate.ps1`；其自行檢查 Phase 4 欄位（`migrationId`、`proposalId`、`approval.approved`、`recoveryReadiness.ready` 等）。故 **gate 與新 schema 的落差** 與 **wrapper 與新 schema 的落差** 為兩條線；Step 2-B／Step 4 需分別設計銜接，避免只改 gate 卻以為已覆蓋 wrapper。

---

## 5. 若直接修改 `approval_gate.ps1`，可能會破壞哪些 Phase 4 測試

以下假設「直接」改為例如：強制參數、改 exit code 規則、改 JSON 欄位名稱、或移除 `Read-Host` 預設路徑而未保留相容行為。

| 測試／套件 | 路徑 | 風險說明 |
|------------|------|----------|
| **test_approval_gate.ps1** | `tests/test_approval_gate.ps1` | 以 **mock `Read-Host`** 呼叫 gate；依賴 **exit code 0／1**、JSON 內 **`success`／`approved`／`timestamp`／`reason` 字面**（如 `reason` 必須為 `"User did not type YES"`）。若改提示文案、改 reason 字串、改成功輸出結構或 exit code，**易立即失敗**。 |
| **activation_test_suite.ps1** | `tests/activation_test_suite.ps1` | 第 1 步執行 `test_approval_gate.ps1`；gate 測試失敗則**整套件失敗**。 |
| **間接** | `tests/test_invoke_governed_migration.ps1` | 若未改此檔但改壞 gate 被 suite 先跑，同上。若未來 wrapper 改為**子程序呼叫** gate 且介面不穩，可能新增失敗點（現況 wrapper **不**呼叫 gate）。 |

**不易因「僅擴充 gate」而壞：** 在**預設無參數**且 **YES／NO／BLANK** 行為與 stdout JSON **完全不變**的前提下，僅**新增**可選參數分支（例如可選的 `-GovernedMigrationInputPath`）理論上可維持 `test_approval_gate.ps1` 通過（仍須 Step 2-B 驗證）。

---

## 6. 建議採用「相容模式」或「一次性切換」

**建議：相容模式（預設保留現行行為）為主，輔以契約版本或參數分岔。**

| 策略 | 評估 |
|------|------|
| **相容模式** | 預設路徑維持 **Read-Host + 既有 JSON 輸出 + 相同 exit code**，與 `test_approval_gate.ps1` 及 `approval_gate_output.schema.json` 對齊；Phase 5 結構化驗證僅在**明確指定輸入檔／契約模式**時啟用。可降低 Phase 4 測試與 CI 瞬斷風險。 |
| **一次性切換** | 若直接改為「僅接受 JSON、無互動」，`test_approval_gate.ps1` 與任何依互動假設之流程**高機率全斷**；且與目前未串接 gate 的 wrapper 無法漸進整合。 |

`PHASE5_IMPLEMENTATION_PLAN.md` 第 9 節亦建議先契約再 gate 再 wrapper；與**漸進相容**一致。

---

## 7. 建議 Step 2-B 要修改哪些檔案

（規劃建議；本 Step 2-A **未**修改任何檔案。）

| 檔案 | 建議理由 |
|------|----------|
| **`approval_gate.ps1`** | 新增可選之 Phase 5 輸入驗證路徑；預設保留互動 YES 流程。 |
| **`tests/test_approval_gate.ps1`** | 為 JSON／schema 驗證路徑新增 PASS／FAIL 案例；**保留**既有 YES／NO／BLANK 三案。 |
| **`contracts/approval_gate_output.schema.json`**（視需要） | 若新增欄位（例如 `contractVersion`、`validationMode`）需同步契約與測試。 |
| **`invoke_governed_migration.ps1`**（建議列在 Step 2-B 之**相依**或延至 Step 4） | 最終需決定是否由 wrapper 呼叫 gate 或先由 orchestrator 合併結果；僅改 gate 不會自動讓 governed input 走 gate。 |
| **`CONTRACT_DESIGN_NOTES.md` 或 v5 README`** | 記錄「互動 gate」與「結構化 governed input」兩路並存期間的責任分界。 |

**可選替代：** 新增 `approval_gate_phase5.ps1` 專責 schema 驗證、舊檔保持不動——亦可達相容，但會增加維護面；組織若偏好單一入口則參數分岔較常見。

---

## 8. Step 2-B 的安全修改範圍

| 允許／建議 | 說明 |
|------------|------|
| **預設行為不變** | 無參數時：仍 `Read-Host`、`YES`／非 `YES`、相同 exit code 與 JSON 形狀。 |
| **擴充而非替換** | 新邏輯放在 **明確 opt-in**（例如 `-InputJsonPath` + `-SchemaPhase5` 或類似），失敗時仍輸出符合 `approval_gate_output` 之錯誤 JSON（若設計為 JSON 模式）。 |
| **不變更既有三測案例之契約** | `test_approval_gate.ps1` 之 `reason` 字串與欄位存在性。 |
| **不在 gate 內執行 SQL 或連線** | 與 Phase 4／5 安全邊界一致；僅做欄位／時間格式／enum 等驗證（實作細節屬 Step 2-B）。 |
| **LIVE_EXECUTE 語意** | Gate 可讀 `mode`；**最終 LIVE 拒絕**仍應與 `enableLiveExecution` 及 **wrapper** 一致（見第 10 節）。 |

---

## 9. 是否需要保留 MOCK／DRY_RUN 相容行為

**需要。**

- `PHASE5_IMPLEMENTATION_PLAN.md` 與現行 **`invoke_governed_migration.ps1`** 仍以 MOCK／DRY_RUN 為主路徑；激活套件對 wrapper 之 **MOCK／DRY_RUN 成功流與 LIVE_EXECUTE 失敗** 有明確斷言。  
- `approval_gate` 若導入契約驗證，建議：**MOCK／DRY_RUN** 可不強制等同 production 之 `finalSignOff.approved=true`／`enableLiveExecution=true`（與 `CONTRACT_DESIGN_NOTES.md` 之語意分層一致），或要求完整 JSON 但放寬語意規則—**須在 Step 2-B 寫死規則並以測試覆蓋**，避免激活套件與手動流程行為漂移。

---

## 10. 是否仍需確保 LIVE_EXECUTE 預設拒絕

**是。**

- 現行 **`invoke_governed_migration.ps1`** 對非 `MOCK`／`DRY_RUN` 即失敗（含 `LIVE_EXECUTE`），為 Phase 4 安全鎖。  
- Phase 5 契約要求 **`enableLiveExecution`**；即使 JSON 結構完整，**開關為 false 時不得執行 live**（見 `governed_migration_input.example.json` 示範）。  
- **建議責任分界：**  
  - **Wrapper** 繼續擔任「模式 + enableLiveExecution + 最終是否進入執行分支」之**硬守門**；  
  - **Gate** 可負責「人類核准證據與 sign-off 欄位是否齊備」之結構／語意檢查。  
  即使 gate 某日誤判，wrapper 仍應**預設拒絕** `LIVE_EXECUTE`，直到組織明確啟用與測試通過。

---

## 11. 稽核結論摘要

| 項目 | 結論 |
|------|------|
| Gate 與 Phase 5 schema | **無直接對應**；gate 不讀 governed input；schema 未定義現有 gate 輸出延伸。 |
| Wrapper 與 Phase 5 schema | **仍為 Phase 4 輸入**；與新 schema **欄位集合不同**；整合需另步驟。 |
| 測試風險 | 直接改 gate 內建行為 → **`test_approval_gate` + activation suite** 高風險。 |
| 建議步調 | **相容模式** + Step 2-B 明列檔案與測試擴充；wrapper 與契約對齊宜配合 **PHASE5_IMPLEMENTATION_PLAN Step 4**。 |

---

*本報告僅供 Phase 5 Step 2-B 實作規劃使用，不構成 production 執行授權。*
