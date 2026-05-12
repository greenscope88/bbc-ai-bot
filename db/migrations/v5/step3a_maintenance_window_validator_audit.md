# SQL Safe Migration 5.0 — Phase 5 Step 3-A  
# Maintenance Window Validator Planning Audit

**類型：** 盤點與設計報告（**僅文件**）  
**產出日期：** 2026-05-12  
**目的：** 為 Step 3-B 安全新增 **Maintenance Window Validator** 提供依據；**本步驟不修改**任何 `.ps1`、`.json`、測試、不執行 SQL、不進行 git 操作。  

---

## 1. Phase 5 文件中對 Maintenance Window 的要求整理

### 1.1 `PRODUCTION_LIVE_EXECUTION_POLICY.md`

| 來源段落 | 要求摘要 |
|----------|----------|
| **L-07（啟用條件）** | **Maintenance Window 已確認** — 須依第 5 節取得排程與利害關係人確認；緊急變更须依例外流程記錄。 |
| **§3 治理檢查摘要** | 執行 `LIVE_EXECUTE` 前須完成含 Human Approval、Recovery 等之檢查鏈；維護窗口屬「執行時刻／變更窗口」治理一環（與技術開關並列）。 |
| **§4 禁止事項** | **維護窗口外**：除已核准之緊急變更程序外，不得在維護窗口外執行高風險 `LIVE_EXECUTE`。 |
| **§5 Human Approval** | **時間盒** — 核准僅在指定時間窗內有效；逾時須重新核准（與 `windowStart`／`windowEnd` 技術驗證對齊）。 |
| **§6 Maintenance Window Policy** | **排程**：`LIVE_EXECUTE` 應僅排於已公告維護窗口；窗口須含預估起訖、影響系統與回滾預留時間。**通知**、**凍結**、**驗證時段**、**緊急例外（事後補齊紀錄）** 等運維要求。 |
| **§8 E-04 緊急停止** | 維護窗口**取消**或**縮短**導致無足夠驗證與回滾時間 → 應中止或不得開始 `LIVE_EXECUTE`（制度上要求「當下窗口仍有效且足夠」之判斷能力）。 |

### 1.2 `PRODUCTION_ACTIVATION_CHECKLIST.md`

| 項目 | 要求摘要 |
|------|----------|
| **C-11** | **Maintenance window confirmed** — 起訖、通知、凍結與驗證時段已確認（與可機讀之 `windowStart`／`windowEnd`／核准欄位一致化之空間）。 |

### 1.3 `PHASE5_IMPLEMENTATION_PLAN.md`

| 內容 | 要求摘要 |
|------|----------|
| **§1 設計目標** | Maintenance Window 與 **wrapper／稽核產物**對齊之窗口欄位與**驗證**（實作於腳本與契約）。 |
| **§4 Maintenance Window Policy（實作對應）** | 輸入帶 **`windowStart`／`windowEnd`／timezone`**（schema 現況為 ISO date-time，**尚未**獨立 `timezone` 欄位，屬後續契約延伸議題）。**Wrapper** 在 `LIVE_EXECUTE` 前比對「當前時間是否在窗口內」；**CI 可注入凍結時間**。**緊急例外**：**`emergencyOverride`** 類旗標 + 核准證明；預設 false。 |
| **§6 Step 3** | 原計畫列於 **`recovery_readiness_checker.ps1` 與窗口欄位** — Step 3-B 若採**獨立** maintenance validator，應與本列項**對齊職責**，避免兩處規則矛盾（見第 12 節）。 |

### 1.4 與 Step 2-B 報告之關係

`step2b_approval_gate_enhancement_report.md` 已將 **`maintenanceWindow.approved`** 納入 **approval_gate** Contract 模式之布林檢查；**未**實作「現在時間是否在窗口內」、**未**實作 `windowEnd > windowStart` 之語意驗證（僅依賴 JSON Schema 之 `format: date-time` 若由外部 validator／工具檢驗則另論；gate 內未解析日期）。

---

## 2. `governed_migration_input` schema 中 `maintenanceWindow` 欄位目前結構

依 **`contracts/governed_migration_input.schema.json`**（節錄語意）：

| 屬性 | JSON Schema 定義 |
|------|------------------|
| **型別** | `object`，`additionalProperties: false` |
| **必填** | `approved`、`windowStart`、`windowEnd`、`approvedBy` |
| **approved** | `boolean` |
| **windowStart** | `string`，`format: date-time` |
| **windowEnd** | `string`，`format: date-time` |
| **approvedBy** | `string`，`minLength: 1` |

**尚未存在於 schema 之欄位（計畫文件已預告）：** 獨立 **`timezone`**、**`emergencyOverride`** 及其核准證明欄位 — Step 3-B 或後續契約版本可追加；本稽核以**現有結構**為準設計 validator。

**範例：** `governed_migration_input.example.json` 中 `maintenanceWindow` 為已核准之起訖與 `approvedBy` 字串範例。

---

## 3. `approval_gate.ps1` 目前已驗證哪些 `maintenanceWindow` 條件

（僅 **Contract 模式**；Legacy 互動模式不讀取 `maintenanceWindow`。）

| 條件 | 實作位置（語意） |
|------|------------------|
| 根層須存在 **`maintenanceWindow`** 鍵 | `Test-ContractInput`：`-contains "maintenanceWindow"` |
| 物件非 **`$null`** | `if ($null -eq $mw)` → 失敗 |
| **`approved -eq $true`**（嚴格布林） | `if ($mw.approved -ne $true)` → 失敗 |

**未在 gate 內驗證：** `windowStart`／`windowEnd` 是否可解析、`windowEnd > windowStart`、目前時間是否落於區間、`approvedBy` 非空白（schema 層有 `minLength: 1`，gate **未**重複檢查字串）、`PRODUCTION`+`LIVE_EXECUTE` 之組合式窗口語意、MOCK／DRY_RUN 之時間略過策略。

---

## 4. 尚未驗證的 `maintenanceWindow` 條件

| # | 條件 | 說明 |
|---|------|------|
| M-01 | **合法 date-time** | 字串須可解析為 `DateTimeOffset`（或明確 UTC 規則）；失敗時 **fail** 並列入 **reasons**。 |
| M-02 | **windowEnd > windowStart** | 嚴格晚於；相等或倒序 **fail**。 |
| M-03 | **現在時間 ∈ [windowStart, windowEnd]** | 對需「執行當下在窗內」之路徑（見 §6）為核心；須定義**右開或右閉**區間與邊界秒級行為。 |
| M-04 | **approvedBy 非空白** | 與 schema 對齊；gate 未驗證時由 validator 補強。 |
| M-05 | **PRODUCTION + LIVE_EXECUTE** | 須同時滿足「已核准窗口」+「時間在窗內」+ M-01～M-04（與 policy「窗口外不得高風險 LIVE」一致）。 |
| M-06 | **MOCK／DRY_RUN** | 依需求可做**僅格式／序關係**檢查，**不**強制「現在時間在窗內」（利於 CI 與離線測試）。 |
| M-07 | **emergencyOverride**（未來） | `PHASE5_IMPLEMENTATION_PLAN.md` 預留：有核准證明時略過或放寬 M-03；預設 false — **待契約與 policy 細節後**再實作。 |
| M-08 | **時區／UTC 一致性** | 若未新增 `timezone` 欄位，建議規定 **僅接受** `Z` 或完整 offset 之 ISO 8601，並於 reasons 註記解析所用假設。 |

---

## 5. 建議新增 validator 的檔名與位置

| 建議 | 說明 |
|------|------|
| **檔名** | **`maintenance_window_validator.ps1`** |
| **目錄** | **`C:\bbc-ai-bot\db\migrations\v5\`**（與 **`recovery_readiness_checker.ps1`**、`approval_gate.ps1` 同層，便於 wrapper／測試以相對路徑呼叫） |
| **選用契約** | 新增 **`contracts/maintenance_window_validator_output.schema.json`**（或等效命名）描述輸出 JSON，利於稽核與測試斷言。 |

---

## 6. 建議 validator 應驗證的項目（對齊使用者清單）

| 項目 | 建議行為 |
|------|----------|
| **maintenanceWindow.approved 必須為 true** | 與 gate 一致；validator 可為**單一真相來源**供 wrapper 呼叫，gate 未來可改為委派或重複檢查擇一（見 §8）。 |
| **windowStart／windowEnd 合法 date-time** | 使用 `[DateTimeOffset]::TryParse` 或 .NET 可接受之 ISO 格式；失敗列入 **reasons**。 |
| **現在時間落在 windowStart～windowEnd** | **僅**在 **`environment -eq "PRODUCTION"` 且 `mode -eq "LIVE_EXECUTE"`**（或組織定義之「須強制時間窗」矩陣）時執行；其餘依下項放寬。 |
| **windowEnd 晚於 windowStart** | 一律檢查（MOCK／DRY_RUN 亦應檢查，避免垃圾資料靜默通過）。 |
| **approvedBy 不可空白** | Trim 後長度 > 0。 |
| **PRODUCTION + LIVE_EXECUTE 必須有效 maintenance window** | `approved` + 序關係 + 時間窗內（若未啟用 emergency override）。 |
| **MOCK／DRY_RUN** | **格式與序關係**（M-01、M-02、approved、approvedBy）；**不**強制 M-03（現在時間），與 Step 3-A 需求及 CI 可測性一致。 |

**參數建議（Step 3-B）：** 可選 **`-ReferenceUtc`**（或 `-NowOverride`**）供測試注入「凍結時間」；未指定時使用 **`[DateTimeOffset]::UtcNow`**，與 `PHASE5_IMPLEMENTATION_PLAN.md` §4 一致。

---

## 7. 建議輸出格式（JSON）

**目標：** 單一 stdout JSON（與其他 checker 風格一致），便於 wrapper 合併與稽核。

```json
{
  "pass": false,
  "reasons": [
    "maintenanceWindow.windowEnd must be after windowStart",
    "current UTC time is outside the declared maintenance window"
  ],
  "checkedAt": "2026-05-12T12:34:56.7890123+00:00"
}
```

| 欄位 | 型別 | 說明 |
|------|------|------|
| **pass** | `boolean` | 全部規則通過為 `true`。 |
| **reasons** | `array` of `string` | 失敗原因清單；**pass 為 true 時建議空陣列 `[]`** 以利機器解析。 |
| **checkedAt** | `string`（ISO 8601） | 驗證完成當下時間戳（建議 UTC 帶 offset）。 |

**Exit code 建議：** `0` = pass，`1` = fail（與既有測試慣例對齊）；或維持 **一律 0** 僅看 JSON **`pass`** — Step 3-B 須**擇一並文件化**；本稽核建議 **`pass==false` 時 exit 1** 以利 `activation_test_suite` 與 `Run-TestScript` 模式。

---

## 8. 是否需要與 `approval_gate.ps1` 整合

| 選項 | 評估 |
|------|------|
| **A. 保持分離（建議預設）** | **approval_gate** 專注「人類核准／契約欄位存在與布林」；**maintenance_window_validator** 專注「時間幾何與執行窗」。職責清晰；Step 3-B 可先獨立交付與測試，**不**觸動 Step 2-B 已穩定之 gate 測試。 |
| **B. gate 內呼叫 validator** | 減少呼叫點，但 **Contract 模式** 將依賴子程序／函式庫；任一 validator 更易影響 gate 之 **exit code／JSON** 契約，需同步更新 **`test_approval_gate_phase5_contract.ps1`**。 |
| **C. 僅 wrapper 串接** | 與 `PHASE5_IMPLEMENTATION_PLAN.md`「wrapper 在 LIVE_EXECUTE 前比對時間」一致；gate 可維持較薄。 |

**建議：** Step 3-B **先實作獨立** `maintenance_window_validator.ps1` + 單元測試；**不強制**在 Step 3-B 修改 `approval_gate.ps1`。後續 Phase 5 Step 4（wrapper）再統一 orchestrate；若組織要求「單一入口必過 gate」，可再以 **B** 作為 Refactor 子步驟並重跑全套測試。

---

## 9. 是否需要與 `activation_test_suite.ps1` 整合

**建議：需要。**

| 理由 |
|------|
| `PHASE5_IMPLEMENTATION_PLAN.md` 已預期激活套件涵蓋維護窗口相關行為。 |
| 與 **`test_approval_gate_phase5_contract.ps1`** 掛載方式一致：在 **`tests/activation_test_suite.ps1`** 單元區段新增 **`Run-TestScript (Join-Path $testDir "test_maintenance_window_validator.ps1")`**。 |
| 可確保 MOCK／DRY_RUN 放寬與 PRODUCTION／LIVE 嚴格路徑不因日後 wrapper 變更而迴歸。 |

**注意：** Step 3-A **不**修改 activation suite；Step 3-B 執行時再改。

---

## 10. Step 3-B 建議修改／新增哪些檔案

| 動作 | 檔案 |
|------|------|
| **新增** | `db/migrations/v5/maintenance_window_validator.ps1` |
| **新增** | `db/migrations/v5/tests/test_maintenance_window_validator.ps1` |
| **新增（建議）** | `db/migrations/v5/contracts/maintenance_window_validator_output.schema.json` |
| **修改** | `db/migrations/v5/tests/activation_test_suite.ps1`（新增一行 `Run-TestScript`） |
| **選用修改** | `db/migrations/v5/approval_gate.ps1` — 若採 §8-B，於 Contract 模式呼叫 validator 並合併失敗理由（**高耦合**，建議延後） |
| **選用修改** | `db/migrations/v5/recovery_readiness_checker.ps1` — 若與 Phase 5 Step 3 原文「窗口欄位放此」合併，需**明確分工**避免重複 fail 訊息不一致（建議以 **validator 為主**、recovery checker 不驗時間窗，或反之，擇一文件化） |
| **文件** | `step3b_maintenance_window_validator_report.md`（完成報告，慣例對齊 Step 2-B） |

**不建議在 Step 3-B 修改：** `invoke_governed_migration.ps1`（使用者限制：不變更 wrapper 之 LIVE 行為）；若需 wrapper 呼叫 validator，建議獨立 **Step 4** 工作項。

---

## 11. Step 3-B 安全修改範圍

| 允許 | 禁止／需另開工作項 |
|------|---------------------|
| 新增 **plan-only**、**無 SQL**、**無 DB 連線** 之 validator 與測試 | 啟用 production **`LIVE_EXECUTE`** 或變更 **`production-live-enabled`** |
| 使用輸入 JSON 路徑參數（與既有 checker 風格對齊） | 修改 `.env` 或從 `.env` 隱性讀取時間／開關 |
| **可選** `-ReferenceUtc` 僅供測試 | 在 validator 內執行 migration 或任意 SQL |
| 啟動失敗時 **reasons** 不含 secret | 未經測試即改動 `approval_gate` Contract 輸出契約 |

---

## 12. 風險與注意事項

| 風險 | 緩解 |
|------|------|
| **與 `recovery_readiness_checker.ps1` 職責重疊** | 於 Step 3-B PR／報告中**白紙黑字**劃分：僅其一驗證「時間在窗內」，另一僅檢文件／路徑存在性。 |
| **CI 時間漂移導致 PRODUCTION+LIVE 測試 flaky** | 測試**一律**使用 `-ReferenceUtc`；正式執行使用真實現在時間。 |
| **夏令時與時區** | 在沒有 `timezone` 欄位前，強制 **UTC（Z）** 或完整 offset，並在 **reasons** 中註明解析假設。 |
| **與 approval_gate 重複檢查 `approved`** | 可接受之重複（防禦深度）；若要避免雙重維護，後續讓 gate **委派** validator 並刪 gate 內重複邏輯（需迴歸全套測試）。 |
| **emergencyOverride 未入契約** | 實作前勿硬編「略過時間窗」分支；待 schema 與 policy 補齊後再開 **Step 3-C** 或契約 **5.1.0**。 |
| **LIVE_EXECUTE 仍由 wrapper／gate 預設拒絕** | Validator **不**應單獨暗示「可執行 live」；僅輸出窗口是否有效之技術判斷。 |

---

## 13. 稽核結論（給 Step 3-B 的執行摘要）

- **政策與清單**已要求：維護窗口须**確認**、**起訖**、**窗口外禁高風險 LIVE**、**時間盒／緊急停止** 與計畫書之 **wrapper 時間比對 + CI 凍結時間**。  
- **契約**已具 `maintenanceWindow` 四欄位；**缺** timezone／emergencyOverride。  
- **gate** 僅驗 **`approved==true`** 與物件存在。  
- **Step 3-B** 宜新增 **獨立** **`maintenance_window_validator.ps1`**、專用測試、可選 output schema，並掛入 **activation_test_suite**；**先不**強制修改 **approval_gate** 與 **wrapper** 以降低 Phase 4／Step 2-B 迴歸風險。

---

*本文件為 Step 3-A 規劃產出；不構成 production 變更授權。*
