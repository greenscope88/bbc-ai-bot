# Governed Migration Input — Contract Design Notes (Phase 5 Step 1)

**範圍：** `governed_migration_input.schema.json` 欄位語意、資料來源分工、與 Phase 4 模組對應  
**狀態：** 契約與範例文件；**不**構成執行授權；wrapper 仍須依政策與開關守門  

---

## 1. 各欄位用途

| 欄位 | 用途 |
|------|------|
| **contractVersion**（選用） | 標示本文件所依之契約版本，供工具與測試選擇 validator／相容層。 |
| **mode** | 執行模式：`MOCK`（離線／假資料）、`DRY_RUN`（不寫入 production 之預演路徑）、`LIVE_EXECUTE`（正式執行意圖；仍受 `enableLiveExecution` 與 wrapper 守門）。 |
| **migrationFile** | 本次受治理的 migration SQL 參照（路徑或版本庫相對路徑），供 wrapper／稽核報告引用。 |
| **environment** | 目標環境：`DEV`／`STAGING`／`PRODUCTION`；與連線守門、風險政策一致化。 |
| **enableLiveExecution** | **技術開關**：`false` 時 **必須**拒絕 `LIVE_EXECUTE`（即使其他欄位看似完整）。 |
| **maintenanceWindow** | 已核准之維護窗口宣告：`approved`、`windowStart`／`windowEnd`（ISO 8601）、`approvedBy`（核准責任人）。 |
| **humanApprovals** | 至少兩筆人工核准紀錄；每筆含 **role**、**approver**、**approvedAt**、**signatureRef**（可稽核之票證、連結或核准檔指紋）。 |
| **backupConfirmation** | 備份佐證：`.bak`（或組織認可之備份句柄）、建立時間、由誰確認與本次變更標的相符。 |
| **recoveryReadiness** | 恢復就緒摘要：**status**（`PASS`／`FAIL`／`NEEDS_REVIEW`）與 **reportPath**（對應 readiness 報告或等效產物路徑）。 |
| **finalSignOff** | 最終放行：**approved**、**approvedBy**、**approvedAt**、**ticketId**（CAB／變更單／Sign-Off 紀錄）。 |
| **auditMetadata** | 稽核脈絡：**changeRequestId**、**businessReason**、**submittedBy**（送件人／服務主體，非 AI 自證）。 |

---

## 2. 哪些欄位是 LIVE_EXECUTE 必要條件

Schema 層級：**所有 `required` 根屬性**（見 schema 內 `required` 陣列）在 **任何 mode** 下皆須存在且型別正確，以便單一契約驅動 MOCK／DRY_RUN／LIVE_EXECUTE。

**語意／守門層級（`LIVE_EXECUTE` 且 `environment === PRODUCTION` 時，wrapper 預期應額外檢查）：**

| 條件 | 說明 |
|------|------|
| **enableLiveExecution === true** | 未允許則 **不得**進入 live 執行分支。 |
| **maintenanceWindow.approved === true** | 且當前時間落在 `windowStart`～`windowEnd`（實作於後續 Step）。 |
| **humanApprovals** | `minItems: 2` 已於 schema 強制；各筆 **signatureRef** 須可驗證。 |
| **recoveryReadiness.status === PASS`** | `FAIL`／`NEEDS_REVIEW` 未依流程結案前不得 live。 |
| **finalSignOff.approved === true** | 與 `PRODUCTION_LIVE_EXECUTION_POLICY.md` 之 Final Sign-Off 一致。 |
| **backupConfirmation** | 已確認之備份與變更標的一致。 |

`MOCK`／`DRY_RUN` 仍須通過 schema，以便 activation test 與報告產生使用**同一套**結構；是否放寬語意檢查由 wrapper 實作決定（建議：非 `LIVE_EXECUTE` 不要求 `enableLiveExecution === true`）。

---

## 3. 哪些欄位由人工提供

| 欄位 / 區塊 | 說明 |
|-------------|------|
| **maintenanceWindow**（含 approvedBy、核准意義） | 由變更／運維流程核准並填寫。 |
| **humanApprovals**（全欄） | **僅能**由人類核准流程產生；AI 不得代填為有效核准。 |
| **backupConfirmation.verifiedBy** | 人類對備份之確認。 |
| **finalSignOff**（除系統代填之時間外） | **approved**、**approvedBy**、**ticketId** 由 Sign-Off 權責人員與票證流程提供。 |
| **auditMetadata.businessReason**、**submittedBy** | 業務與送件責任歸屬，由人類或已授權之送件帳號提供。 |
| **migrationFile** | 通常由變更負責人指定已審核路徑。 |

---

## 4. 哪些欄位由系統自動產生

| 欄位 / 區塊 | 說明 |
|-------------|------|
| **recoveryReadiness.status**、**reportPath** | 由 **Recovery Readiness Checker**（或 orchestrator）依檢查結果寫入輸出檔後，再組入本輸入；人類不應手改 status 規避檢查。 |
| **contractVersion** | 可由產生輸入之工具插入。 |
| **enableLiveExecution** | 值應來自已核准之部署設定／旗標注入，**不是**由單次 prompt 推斷；文件層級仍屬「系統／流程注入」。 |
| **部分 approvedAt**（若與票證系統同步） | 可由票證 API 寫入；若僅人工填寫則屬第 3 節。 |

**混合：** `changeRequestId` 常來自票證系統 API（系統帶入）但仍屬變更流程資產，非 AI 臆測。

---

## 5. 與 Phase 4 模組的關聯

### 5.1 approval_gate（`approval_gate.ps1`）

- **現況（Phase 4）：** 使用較精簡之核准物件（如 `approval.approved`）與 proposal 路徑等。  
- **Phase 5 對齊：** Gate 應驗證 **humanApprovals**（筆數、欄位、時間盒與 proposal／migration 指紋一致），並與 **finalSignOff**、**auditMetadata.changeRequestId** 交叉檢查。  
- **本契約角色：** 作為 wrapper 匯入 gate 前之**標準化輸入**或 gate 輸出合併後之中間表示（實作於後續 Step，本 Step 不修改腳本）。

### 5.2 recovery_readiness_checker（`recovery_readiness_checker.ps1`）

- **產出：** `recoveryReadiness.status` 與報告路徑應與 checker 輸出契約（如 `recovery_readiness_output.schema.json`）一致後，映射為本輸入之 **recoveryReadiness**。  
- **本契約角色：** 明確要求 **reportPath**，使 audit 與 wrapper 能載入同一就緒證據。

### 5.3 audit_report_generator（`report_generator.ps1`）

- **輸入：** 後續可由此契約或由此契約展開之 `report_generator_input` 產生完整稽核報告。  
- **本契約角色：** **auditMetadata**、**humanApprovals**、**maintenanceWindow**、**finalSignOff** 提供報告「人／時／因」可追溯段落。

### 5.4 governed_migration_wrapper（`invoke_governed_migration.ps1`）

- **現況（Phase 4）：** 僅允許 MOCK／DRY_RUN；輸入結構與本 Phase 5 schema **不同**。  
- **Phase 5 對齊：** Wrapper 應以本 schema（或經版本協商後之衍生）為準，並實作：`mode === LIVE_EXECUTE` 且 **enableLiveExecution**／**finalSignOff**／readiness／窗口等守門。  
- **相容策略（建議）：** 過渡期可由「契約轉接層」將舊欄位映射為新欄位，直至測試與腳本全數切換（實作於後續 Step）。

---

## 6. 範例檔 `governed_migration_input.example.json` 說明

- 示範 **PRODUCTION** + **LIVE_EXECUTE** 之欄位編排。  
- **enableLiveExecution** 刻意為 **false**；**finalSignOff.approved** 為 **false** — 代表「格式合法但未授權執行」，供文件與測試負向案例參考。

---

## 7. 與 Phase 4 schema 之差異（摘要）

| Phase 4（舊） | Phase 5（本契約） |
|----------------|-------------------|
| `migrationId` / `proposalId` / `operator` 等 | 以 **auditMetadata**、**migrationFile**、**humanApprovals** 承載身分與追溯 |
| `approval.approved` 單一布林 | **humanApprovals** 陣列（≥2）+ **finalSignOff** |
| `recoveryReadiness.ready` | **recoveryReadiness.status** + **reportPath** |
| 無 `LIVE_EXECUTE`／`enableLiveExecution`／維護窗口 | 明確納入 governance 欄位 |

後續 Step 應更新 wrapper／gate／測試資料以符合本契約，並保留版本號以利演進。
