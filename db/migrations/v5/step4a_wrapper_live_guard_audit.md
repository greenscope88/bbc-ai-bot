# SQL Safe Migration 5.0 — Phase 5 Step 4-A  
# Governed Migration Wrapper Live Guard Planning Audit

**類型：** 盤點與設計報告（**僅文件**）  
**產出日期：** 2026-05-12  
**範圍：** `invoke_governed_migration.ps1` 之 `LIVE_EXECUTE` 守門現況，與 Phase 5 整合 **approval_gate**、**maintenance_window_validator**、**recovery_readiness_checker**、稽核報告產出、**EnableLiveExecution** 之規劃；**本步驟不修改**任何程式、測試、schema、不執行 SQL、不進行 git 操作。  

**檔名註記：** 使用者清單中的 **`audit_report_generator.ps1`** 於工作區**不存在**；稽核報告元件為 **`report_generator.ps1`**（與 `PHASE4_COMPLETION_REPORT.md` 之「Audit Report Generator」對應）。以下以 **`report_generator.ps1`** 為準。

---

## 1. `invoke_governed_migration.ps1` 目前支援哪些模式

| 模式 | 是否允許通過（在治理欄位皆為 true 之前提下） |
|------|-----------------------------------------------|
| **MOCK** | **是** — `$mode -eq "MOCK"` 通過第 37–40 行檢查。 |
| **DRY_RUN** | **是** — 同上。 |
| **LIVE_EXECUTE** | **否** — 於第 37–40 行即 **`Fail`**，不進入後續報告產出邏輯。 |

**輸入契約（現況）：** Wrapper 仍要求 **Phase 4** 欄位集合：`migrationId`、`proposalId`、`environment`、`mode`、`operator`、`approval`、`riskSummary`、`recoveryReadiness`（內含 **`ready`**）、`schemaDiffSummary`、`executionPlan`。與 **`governed_migration_input.schema.json`（Phase 5）** 不同；Step 4-B 若導入新契約需一併設計**相容／轉接**或**版本參數**。

---

## 2. 目前 `LIVE_EXECUTE` 被拒絕的位置與原因

| 項目 | 說明 |
|------|------|
| **位置** | 約第 **37–40** 行：`if ($mode -ne "MOCK" -and $mode -ne "DRY_RUN") { Fail "LIVE_EXECUTE is not supported in this phase" }` |
| **原因字串** | **`LIVE_EXECUTE is not supported in this phase`**（硬編碼 Phase 鎖定）。 |
| **行為** | 在通過「必填欄位存在」檢查之後、在任何 **`approval`／`recoveryReadiness`／`riskSummary`／`schemaDiffSummary`** 細節檢查之**前**，即對非 MOCK／DRY_RUN 模式拒絕。 |
| **副作用** | `LIVE_EXECUTE` 永遠不會到達寫入 **`report_generator_input.json`** 或 **`execution_report.*`** 之區塊。 |

---

## 3. 目前 wrapper 是否會呼叫 `approval_gate.ps1`

**否。** `invoke_governed_migration.ps1` 全文無 `approval_gate`、`&` 子程序呼叫至該腳本；核准僅檢查內嵌 JSON 之 **`$input.approval.approved -eq $true`**。

---

## 4. 目前 wrapper 是否會呼叫 `maintenance_window_validator.ps1`

**否。** 無引用、無子程序呼叫。

---

## 5. 目前 wrapper 是否會呼叫 `recovery_readiness_checker.ps1`

**否。** Wrapper 僅讀取輸入內 **`recoveryReadiness.ready`**；**`recovery_readiness_checker.ps1`** 之介面為 **`BackupPath`／`SchemaSnapshotPath`／`RestoreGuidePath`／`RecoveryMode`**，與現行 wrapper 輸入結構**未**在 wrapper 內串接。

---

## 6. 目前 wrapper 是否會產生 audit report

| 產出 | 現況 |
|------|------|
| **`report_generator_input.json`** | **是** — 由 wrapper **直接寫入** `OutputDir`（內嵌組裝，非 JSON Schema Phase 5 契約）。 |
| **`execution_report.json`／`.md`**、**`risk_summary.md`**、**`schema_diff_summary.md`**、**`recovery_readiness_summary.md`** | **是** — 同為 wrapper **內嵌** `Set-Content`／`ConvertTo-Json` 產生。 |
| **`report_generator.ps1`（稽核／報告產生器）** | **否** — Wrapper **未** `Start-Process`／`&` 呼叫 **`report_generator.ps1`**；激活套件另以 **`Invoke-ReportGeneratorDirect`** 獨立驗證 report generator。 |

故：**有「報告相關檔案」**，但**無**透過 **`report_generator.ps1`** 之統一 audit pipeline。

---

## 7. Phase 5 要啟用 `LIVE_EXECUTE` 前，wrapper 必須新增哪些守門條件

（對齊 **`PRODUCTION_LIVE_EXECUTION_POLICY.md`**、**`PHASE5_IMPLEMENTATION_PLAN.md`**、**`governed_migration_input.schema.json`**。）

| # | 守門條件 | 說明 |
|---|----------|------|
| G-01 | **契約載入與版本** | 能載入 Phase 5 **`governed_migration_input`**（或經轉接之等效結構），並辨識 **`contractVersion`**（若有）。 |
| G-02 | **mode／environment** | 與 policy 一致之 enum；**PRODUCTION** + **`LIVE_EXECUTE`** 走最嚴格路徑。 |
| G-03 | **EnableLiveExecution 雙重守門** | 見 §8：參數開關 **與** JSON **`enableLiveExecution == true`** 缺一不可。 |
| G-04 | **approval_gate（Contract）** | 以 **`-ContractInputPath`** 同一（或已驗證一致）輸入呼叫；**exit 0** 且輸出 **`approved`** 語意通過（注意：現行 gate 仍 **預設拒絕** Contract 模式之 `LIVE_EXECUTE`，與「真實啟用」需另案調整 gate 或改由 wrapper 承擔最終 LIVE 拒絕 — 見 §14）。 |
| G-05 | **maintenance_window_validator** | 對同一契約路徑呼叫；**PRODUCTION + LIVE_EXECUTE** 須通過**時間在窗內**；MOCK／DRY_RUN 格式檢查。 |
| G-06 | **recovery_readiness_checker** | 依現有 checker 參數組裝路徑（來自契約或衍生欄位）；**plan-only**、**exit 0**／輸出 **`ready`** 與 wrapper 預期對齊。 |
| G-07 | **finalSignOff** | 契約 **`finalSignOff.approved`** 於 **PRODUCTION** 須為 true（與 policy／gate 一致）。 |
| G-08 | **稽核 pre-report** | 於任何「宣告可進入 live branch」之前，產出可稽核產物（建議呼叫 **`report_generator.ps1`** 或等效，含 gate／validator／checker 結果摘要）。 |
| G-09 | **最終人工確認**（建議） | 與 policy「禁止 AI 自動 restore／rollback」一致：可選 **`Read-Host`**／**`-Confirm:$false` 禁止`** 或外部票證；Step 4-B 若仍不執行 SQL，可以 **「進入 skeleton 前再 Fail」** 模擬。 |
| G-10 | **不執行 SQL（直至組織核准）** | 即使通過上述守門，預設仍 **`executed: false`** 直至明確後續 Phase。 |

---

## 8. `EnableLiveExecution` 技術開關建議設計

| 原則 | 建議 |
|------|------|
| **預設 false** | Wrapper 未傳入允許參數時，視為 **不允許** live branch。 |
| **必須明確參數啟用** | 例如 **`-AllowLiveExecution`**（或 `-EnableLiveExecutionSwitch`）需由**人類操作或 CI 秘密閘門外**之流程顯式傳入；**不得**僅依 `.env` 隱性開啟（符合 env-protection 與 policy）。 |
| **契約 `enableLiveExecution == true`** | 與 **`governed_migration_input.schema.json`** 布林欄位一致；**`$false`** 時立即 **Fail**，與 policy「開關未啟用 wrapper 必須拒絕」一致。 |
| **兩者缺一不可** | 邏輯式：**`$AllowLiveExecutionParam -eq $true`** **且** **`$input.enableLiveExecution -eq $true`** 才通過雙重守門；任一為 false／缺參數 → **Fail**（訊息區分「參數未啟用」與「契約未核准」）。 |
| **審計** | 將兩項結果寫入稽核 JSON／report（不含 secret）。 |

---

## 9. `LIVE_EXECUTE` 建議守門順序

建議以下**線性順序**（失敗即 **Fail**，不進入後續步驟；可將較便宜之檢查置前）：

1. **Contract load** — 路徑、UTF-8、JSON parse、基礎 schema／必填鍵（可選用外部 schema validator 或 PowerShell 斷言）。  
2. **mode／environment 驗證** — enum 與 policy 矩陣。  
3. **EnableLiveExecution 雙重確認** — 參數 + JSON 欄位。  
4. **approval_gate `-ContractInputPath`** — 解析 stdout JSON；**注意**現行 gate 對 `LIVE_EXECUTE` 仍會 Fail，整合時須決定「gate 放寬」或「LIVE 守門僅在 wrapper」— 見 §14。  
5. **maintenance_window_validator** — 同一路徑；capture `pass`／`reasons`。  
6. **recovery_readiness_checker** — 由契約或對照表提供四參數。  
7. **finalSignOff check** — PRODUCTION 時 `approved`；可併入 gate 或 wrapper 重複防禦。  
8. **Audit pre-report** — 呼叫 **`report_generator.ps1`**（或擴充輸入）寫入 `OutputDir`，內容含前述步驟摘要與 **`checkedAt`**。  
9. **Final manual confirmation** — 運維政策要求時才啟用。  
10. **Live execution branch** — 僅在組織明確核准後才允許**真實 SQL**；Step 4-B 以前建議僅 **skeleton** 或 **Fail**。  

---

## 10. Step 4-B 建議採用「只新增 live guard，不真正執行 SQL」或「建立 live branch skeleton」

**建議：兩階段中的第一步以「只新增 live guard + skeleton」為主，仍不執行 SQL。**

| 策略 | 說明 |
|------|------|
| **只新增 live guard（推薦為 Step 4-B 核心）** | 將 **`LIVE_EXECUTE`** 從「立即 Fail」改為：先跑 **§9** 守門鏈；任一步失敗則 **`Fail`** 並附結構化 **`reason`**；**全部通過**後仍 **`executed: false`** 並 **`Fail` 或明確訊息「live branch not enabled until Phase X」** — **不**執行 SQL。可最大化測試覆蓋且符合「不可啟用 production live」之現階段目標。 |
| **live branch skeleton** | 在上述 guard 全通過後進入**單獨程式區塊**（例如 `if ($false) { ... }` 或僅寫 log「would execute」），內容仍無 `Invoke-SqlCmd` 等；便於 Step 5 接線。 |

**不建議 Step 4-B 直接「真實 live SQL」**，除非組織另開核准與隔離環境演練；與 policy 及現有測試安全預設衝突風險高。

---

## 11. Step 4-B 建議修改哪些檔案

| 檔案 | 建議變更方向 |
|------|----------------|
| **`invoke_governed_migration.ps1`** | 新增參數 **`-AllowLiveExecution`**（或同等）、契約分岔（Phase 4 vs Phase 5）、子程序呼叫 gate／validator／checker／report_generator、結構化失敗訊息；**維持預設不執行 SQL**。 |
| **`tests/test_invoke_governed_migration.ps1`** | 延伸案例：LIVE + 缺參數、LIVE + `enableLiveExecution:false`、MOCK／DRY_RUN 迴歸。 |
| **`tests/activation_test_suite.ps1`** | 更新 **`New-InputPayload`** 或並行支援 Phase 5 測試 payload；維持 **`LIVE_EXECUTE` 最終仍失敗或仍 `executed:false`** 之斷言與 policy 對齊。 |
| **（選用）** `contracts/governed_migration_input.schema.json` | 若 wrapper 正式只吃 Phase 5，需版本化與範例更新（**另開變更單**，Step 4-A 不修改）。 |
| **（選用）** `report_generator.ps1` | 若 pre-report 需新欄位（gate／validator JSON 嵌入），擴充輸入契約與測試。 |

---

## 12. Step 4-B 必須新增哪些測試

| # | 測試案例 |
|---|----------|
| T-01 | **`LIVE_EXECUTE`** + **未**傳 `-AllowLiveExecution` → **Fail**（契約即使 `enableLiveExecution:true` 亦失敗）。 |
| T-02 | **`LIVE_EXECUTE`** + `-AllowLiveExecution` + 契約 **`enableLiveExecution:false`** → **Fail**。 |
| T-03 | **`LIVE_EXECUTE`** + 雙開關皆 true + **mock 子程序**使 **approval_gate** 失敗 → wrapper **Fail**、**不**產生「已執行」語意。 |
| T-04 | 同上 + **maintenance_window_validator** 失敗（例如窗外）→ **Fail**。 |
| T-05 | **MOCK／DRY_RUN** 既有激活流 **PASS**（迴歸）。 |
| T-06 | **子程序／mock**：**recovery_readiness_checker** 失敗路徑（若 wrapper 串接）。 |
| T-07 | **report_generator** 若由 wrapper 呼叫：缺輸入或失敗 exit 映射至 wrapper **Fail**。 |
| T-08 | 全守門 mock 通過後：斷言 **`executed -eq $false`** 且（若政策要求）**仍 exit 1 或明確「live not enabled」** — 與 §14 一致。 |

**測試技術：** 與 **`test_approval_gate.ps1`** 類似，以 **`-Command`** 覆寫子命令或指向 **stub .ps1`**（僅測試目錄內）避免真實 gate 對 LIVE 的硬拒絕干擾 guard 鏈測試 — 設計時需文件化「測試 stub vs 生產真實腳本」差異。

---

## 13. 風險與注意事項

| 風險 | 說明 |
|------|------|
| **雙契約** | Phase 4 `migrationId`／`recoveryReadiness.ready` 與 Phase 5 `migrationFile`／`recoveryReadiness.status` 並存期易混；需 **`contractVersion` 或 `-ContractPhase`** 明確分岔。 |
| **approval_gate 與 LIVE** | 現行 Contract 模式對 **`LIVE_EXECUTE`** 一律 **Fail**（default policy）；wrapper 若先呼叫 gate，**真實** gate 將阻擋 guard 鏈「全綠」測試 — 須調整 gate 或測試 stub（§12）。 |
| **recovery_readiness_checker 參數來源** | 契約未必含四路徑欄位；需對照表或由 orchestrator 組裝，避免硬編假路徑通過。 |
| **report_generator 契約** | 現以 Phase 4 欄位為主；pre-report 嵌入 Phase 5 子結果可能需新 input schema。 |
| **時間 flaky** | **maintenance_window_validator** 之 PRODUCTION+LIVE 測試應使用寬窗或 **Step 3-B** 已驗證之模式；wrapper 整合測試宜 mock validator。 |
| **`.env` 與 secret** | 開關與連線字串不得自 `.env` 隱性注入 live 允許（workspace 規則）。 |
| **誤以為「全綠 = 可執行」** | 文件與 stdout 應明確 **`executed:false`** 直至組織 Sign-Off 與 SQL 執行層啟用。 |

---

## 14. 是否建議此階段仍維持 `LIVE_EXECUTE` 最終拒絕

**建議：是（Step 4-B 範圍內）。**

| 理由 |
|------|
| 與 **`PRODUCTION_LIVE_EXECUTION_POLICY.md`**、**`PHASE5_IMPLEMENTATION_PLAN.md`** 之「預設不啟用 live」「`production-live-enabled` 仍為組織決策」一致。 |
| **`approval_gate.ps1`** 目前仍對 Contract **`LIVE_EXECUTE`** **預設拒絕**；若 Step 4-B 放開 wrapper 最後一擋而 gate 未同步，會造成**行為分裂**。 |
| **建議做法：** Step 4-B 實作完整 **guard 鏈**與 **skeleton**，但**最後一步**仍以 **`Fail("LIVE_EXECUTE execution not enabled")`** 或同等結束，且 **`executed` 永為 false**；待 Sign-Off 與 gate／policy 對齊後再於後續 Phase 移除「最終拒絕」。 |

---

## 15. 稽核摘要表

| 問題 | 結論 |
|------|------|
| Wrapper 支援模式 | **MOCK**、**DRY_RUN**（**LIVE_EXECUTE** 於第 37–40 行拒絕）。 |
| 是否呼叫 gate／validator／recovery checker | **皆否**。 |
| 是否透過 `report_generator.ps1` 產 audit | **否**（僅內嵌寫檔）。 |
| `audit_report_generator.ps1` | **不存在**；請使用 **`report_generator.ps1`**。 |

---

*本文件僅供 Step 4-B 設計與審查；不構成 production live 授權。*
