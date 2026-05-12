# SQL Safe Migration 5.0 — Production Activation Checklist

**文件類型：** Phase 5 — 正式啟用前逐項確認清單  
**用途：** 支援 **Final Production Sign-Off** 與未來 **`LIVE_EXECUTE`／`production-live-enabled`** 啟用決策  
**狀態：** 本清單為**核對用**；勾選完成不代表已啟用 live，須搭配 `PRODUCTION_LIVE_EXECUTION_POLICY.md` 與核准紀錄  

---

## 1. 使用方式

- 每次擬進行 **production live migration** 前，應複製本清單或建立子任務單，逐項勾選並**附件留存**（路徑、螢幕擷取、報告檔名、核准連結等）。  
- 任一必要項 **未完成** → **不得**執行 `LIVE_EXECUTE`。  
- **AI 不得**代為勾選「Human approvals」或「Final sign-off」相關項目。

---

## 2. 正式上線前確認清單

### 2.1 版本庫與工作區

| # | 項目 | 確認 | 證據／備註（填寫） |
|---|------|------|-------------------|
| C-01 | **Git clean** — 工作區無未預期之未提交變更；執行所依 commit／tag 已指明 | [ ] | |
| C-02 | **Branch／PR** — 變更已依流程 review／merge（若組織要求） | [ ] | |
| C-03 | **Proposal／SQL 版本一致** — 實際執行檔與已審核版本一致 | [ ] | |

### 2.2 變更提案與風險

| # | 項目 | 確認 | 證據／備註 |
|---|------|------|------------|
| C-04 | **Migration proposal reviewed** — 技術與業務影響已審閱 | [ ] | |
| C-05 | **Risk checker PASS** — 輸出已存檔且風險等級可接受 | [ ] | |
| C-06 | **Proposal／tenant／connection 等 checker** — 與該次變更相關者皆 PASS | [ ] | |

### 2.3 備份與結構證據

| # | 項目 | 確認 | 證據／備註 |
|---|------|------|------------|
| C-07 | **`.bak` backup confirmed** — 路徑、時間、伺服器／資料庫名稱與變更標的相符 | [ ] | |
| C-08 | **Schema-only snapshot confirmed** — before（及若適用 after 計畫）可取得且與契約一致 | [ ] | |

### 2.4 恢復與模式

| # | 項目 | 確認 | 證據／備註 |
|---|------|------|------------|
| C-09 | **Recovery readiness PASS** — `recovery_readiness_checker` 或等效檢查通過 | [ ] | |
| C-10 | **Recovery Mode A 已溝通** — 人員知悉 AI 不執行 production restore／rollback | [ ] | |

### 2.5 排程與溝通

| # | 項目 | 確認 | 證據／備註 |
|---|------|------|------------|
| C-11 | **Maintenance window confirmed** — 起訖、通知、凍結與驗證時段已確認 | [ ] | |
| C-12 | **Rollback／通訊計畫** — 聯絡人與決策鏈已列出 | [ ] | |

### 2.6 人為核准

| # | 項目 | 確認 | 證據／備註 |
|---|------|------|------------|
| C-13 | **Human approvals completed** — 含變更核准與執行時刻核准（依組織矩陣） | [ ] | |
| C-14 | **高風險雙重核准**（若適用）— 兩名不同職能已核准 | [ ] | N/A 或已完成 |

### 2.7 稽核與技術開關

| # | 項目 | 確認 | 證據／備註 |
|---|------|------|------------|
| C-15 | **Audit logging enabled** — 報告／log 路徑可寫入且不含秘密明文 | [ ] | |
| C-16 | **EnableLiveExecution** — 僅在已核准變更單關聯下啟用（Phase 5 實作後適用） | [ ] | 未實作前填 N/A |

### 2.8 最終簽核

| # | 項目 | 確認 | 證據／備註 |
|---|------|------|------------|
| C-17 | **Final sign-off completed** — 指定 Sign-Off 人員已確認 | [ ] | |
| C-18 | **啟用後驗證責任人** — 已指派並可聯絡 | [ ] | |

---

## 3. Phase 4 狀態對照（執行前必讀）

| 標記 | 執行 `LIVE_EXECUTE` 前應為 |
|------|---------------------------|
| implementation-ready | YES |
| activation-test-passed | YES |
| production-live-enabled | 僅在組織核准且 Sign-Off 後得為 YES |

---

## 4. 完成後產物清單（建議附件）

- 本次 `requestId`／`changeRequestId`  
- Proposal JSON、preflight／risk／schema diff 報告路徑  
- Recovery readiness 輸出與 recovery plan 參照  
- Audit report 產出路徑  
- 核准紀錄與 Sign-Off 紀錄  

---

*未完成本清單之必要項目者，依 `PRODUCTION_LIVE_EXECUTION_POLICY.md` 不得進入 production live 執行。*
