# SQL Safe Migration 5.0 — Phase 5 Implementation Plan

**文件類型：** Phase 5 — Production Activation Governance（**規劃與實作步驟設計**）  
**範圍：** 建立 production live 治理制度與技術開關設計；**本文件不修改程式碼**、**不啟用** `LIVE_EXECUTE`、**不**變更 `production-live-enabled`  

**前置狀態（Phase 4 結束時）：**

| 標記 | 值 |
|------|-----|
| implementation-ready | YES |
| activation-test-passed | YES |
| production-live-enabled | NO |

**已存在模組（Phase 4）：** Human Approval Gate、Recovery Readiness Checker、Audit Report Generator、Governed Migration Wrapper（**僅** MOCK／DRY_RUN）、Activation Test Suite。

---

## 1. Phase 5 設計目標

1. **制度完整化** — 以 `PRODUCTION_LIVE_EXECUTION_POLICY.md` 與 `PRODUCTION_ACTIVATION_CHECKLIST.md` 固定啟用條件、禁止事項與 Sign-Off。  
2. **技術開關分離** — 將「程式具備 live 能力」與「組織允許 live」分離：**`EnableLiveExecution`**（或同等名稱）為顯式、可稽核之第二道開關。  
3. **Human Approval 強化** — 延伸核准輸入契約、時間盒、雙重核准建議與 Sign-Off 綁定。  
4. **Maintenance Window** — 與 wrapper／稽核產物對齊之窗口欄位與驗證（實作階段定義於腳本與契約）。  
5. **Final Production Sign-Off** — 可操作的檢查與產物列表，與啟用決策一一對應。  
6. **安全預設** — 在開關未啟用時，`LIVE_EXECUTE` **必須**維持硬失敗；**不**導入 AI 自動 production restore／rollback。

---

## 2. 預計新增之技術開關與配套

| 項目 | 說明 | 備註 |
|------|------|------|
| **`EnableLiveExecution`** | 全域或每環境之布林／旗標；**僅**在組織流程核准後由人員設定 | 實作載體可為環境變數、專用設定檔或核准 JSON 內欄位；**不得**僅依 `.env` 隱性切換而不留稽核 |
| **Wrapper 行為** | `LIVE_EXECUTE` 須同時滿足：合法輸入、治理檢查 PASS、**開關允許** | Phase 5 實作階段修改 `invoke_governed_migration.ps1` 等（**不在本計畫文件撰寫階段執行**） |
| **契約延伸** | `governed_migration_input`／output 或獨立 `activation_manifest` 增加 `maintenanceWindow`、`enableLiveExecutionAck` 等 | 須版本化 `contractVersion` |
| **稽核** | 記錄開關狀態（僅顯示 allowed／denied，不記 secret）、Sign-Off 參照 | 與 Audit Report 對齊 |
| **測試** | `activation_test_suite.ps1` 增加：開關 OFF 時 LIVE 拒絕、開關 ON 且輸入完整時之正路徑（若組織允許在 CI 使用 mock secret） | 實作階段執行 |

---

## 3. Human Approval 強化機制（實作對應）

| 機制 | 規劃要點 |
|------|----------|
| 核准輸入版本化 | `approval_gate` 輸入增加核准時間窗、核准範圍雜湊或 proposal 指紋 |
| 雙重核准 | 高風險 proposal 模板中要求第二核准人欄位；gate 檢查缺失則 FAIL |
| Sign-Off 關聯 | 獨立 `signoff_record` 或欄位群，由 wrapper 驗證存在性與未過期 |
| 禁止 AI 填寫 | 核准與 Sign-Off 僅接受人類產出之檔案或系統匯出格式 |

---

## 4. Maintenance Window Policy（實作對應）

- Proposal 或 activation manifest 帶有 **`windowStart`／`windowEnd`／timezone`**。  
- Wrapper 在 `LIVE_EXECUTE` 前比對「當前時間是否在窗口內」（實作細節於實作階段決定，可允許 CI 注入凍結時間）。  
- 緊急例外須 **`emergencyOverride`** 類旗標 + 核准證明；預設為 false。

---

## 5. Final Production Sign-Off（實作對應）

- 以 `PRODUCTION_ACTIVATION_CHECKLIST.md` 為權威勾選列表。  
- Sign-Off 紀錄（檔案或票證）之 ID 寫入 audit report 與 governed migration 輸出。  
- **`production-live-enabled`** 狀態變更僅在文件與組織流程中標記，並與首次核准之 `LIVE_EXECUTE` 關聯（技術標記存放位置於實作階段定義，例如 README 區塊或獨立 `activation_state.json`）。

---

## 6. 實作步驟 Step 1 ~ Step N

以下步驟須於**後續實作階段**執行；**當前 Phase 5 僅完成文件者不執行下列程式變更**。

### Step 1 — 契約與文件對齊

| 內容 | 驗證方式 |
|------|----------|
| 更新 `contracts/governed_migration_input.schema.json`（及相關）草案：`EnableLiveExecution` 識別、`maintenanceWindow`、`signOffRef` 等 | JSON Schema 校驗通過；與 `PRODUCTION_LIVE_EXECUTION_POLICY.md` 用語一致 |
| 更新 `README` 或 v5 索引說明 Phase 5 開關與流程 | 同儕 review |

**完成條件：** Schema 草案 merged；無 secret 範例。

---

### Step 2 — `approval_gate.ps1` 強化

| 內容 | 驗證方式 |
|------|----------|
| 支援時間盒、第二核准人（可選欄位）、proposal 指紋比對 | `tests/test_approval_gate.ps1` 新增案例 PASS／FAIL |

**完成條件：** 既有測試全 PASS；新負向案例覆蓋偽造核准。

---

### Step 3 — `recovery_readiness_checker.ps1` 與窗口欄位

| 內容 | 驗證方式 |
|------|----------|
| 可選驗證 maintenance window 與緊急 override 欄位格式 | `tests/test_recovery_readiness_checker.ps1` 延伸 |

**完成條件：** Plan-only 不連線 DB；FAIL 路徑有明確訊息。

---

### Step 4 — `invoke_governed_migration.ps1`：開關 + LIVE_EXECUTE 分支設計

| 內容 | 驗證方式 |
|------|----------|
| `LIVE_EXECUTE` 僅在 `EnableLiveExecution` 允許且 Sign-Off／approval 通過時進入「可執行」分支；其餘維持拒絕 | `activation_test_suite.ps1`：開關 OFF → 拒絕；MOCK／DRY_RUN 行為不迴歸 |

**完成條件：** 預設仍不可 live；log 不洩漏 secret。

---

### Step 5 — 實際 SQL 執行層（若組織核准於後續子階段）

| 內容 | 驗證方式 |
|------|----------|
| 僅於隔離環境或明確 staging 驗證連線與執行；production 首跑須人在場 | 手動測試計畫 + 稽核報告封存 |

**完成條件：** 與本計畫「不自動 restore／rollback」一致；由人員執行還原決策。

---

### Step 6 — `report_generator.ps1` 與稽核產物

| 內容 | 驗證方式 |
|------|----------|
| 報告含開關狀態、窗口、Sign-Off ref、核准摘要 | `tests/test_report_generator.ps1` |

**完成條件：** 產出檔可對應 checklist 附件列表。

---

### Step 7 — 文件與組織流程上線

| 內容 | 驗證方式 |
|------|----------|
| Runbook：誰可開 `EnableLiveExecution`、如何關閉、如何事後稽核 | 桌面演練或讀審通過 |

**完成條件：** 變更委員會或等效單位認可（依組織）。

---

## 7. Phase 5 完成條件（Definition of Done）

以下**全部**滿足時，得宣告 Phase 5 **實作與啟用治理**完成（**仍未必**代表日常允許 live，由組織政策決定）：

1. **`PRODUCTION_LIVE_EXECUTION_POLICY.md`** 與 **`PRODUCTION_ACTIVATION_CHECKLIST.md`** 為組織認可之現行版。  
2. **`EnableLiveExecution`** 已實作於 wrapper（或等效唯一入口），且預設 **deny**。  
3. **`LIVE_EXECUTE`** 路徑已實作並由測試覆蓋「開關 OFF 拒絕」「核准缺失拒絕」「窗口外拒絕（若啟用）」。  
4. Human Approval 強化已實作並有測試。  
5. Audit report 可證明一次 live 嘗試之輸入、檢查結果、時間、核准與 Sign-Off 參照。  
6. **Recovery Mode A** 維持：無 AI 自動 production restore／rollback。  
7. **首次** production live（若執行）具完整 checklist 附件與 Sign-Off，並由負責人設定 **`production-live-enabled`** 之宣告（依專案慣例文件位置）。

---

## 8. 與本階段（僅文件）的界線

| 本階段已完成 | 本階段刻意不執行 |
|--------------|------------------|
| 三份 Phase 5 治理／計畫文件 | 修改任何 `.ps1` 核心腳本 |
| 開關與流程之**設計敘述** | 啟用 `LIVE_EXECUTE` 或 `LIVE_EXECUTE` 實際執行 SQL |
| | 變更 `production-live-enabled` 狀態 |
| | 對正式資料庫執行 migration |

---

## 9. 建議實作順序（供實作階段參考）

優先序：**契約與資料模型（Step 1）** → **Approval Gate 強化（Step 2）** → **Wrapper 開關與 LIVE 分支守門（Step 4 核心）** → **Recovery／窗口欄位（Step 3）** → **Report（Step 6）** → **Staging／手動驗證（Step 5）** → **Runbook／Sign-Off（Step 7）**。

---

*本計畫文件僅定義 Phase 5 目標與步驟；實作須另開工作項並遵守組織變更與資安規範。*
