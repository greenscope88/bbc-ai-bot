# SQL Safe Migration 5.0 — Production Live Execution Policy

**文件類型：** Phase 5 Governance — Policy（規劃與制度設計）  
**範圍：** `LIVE_EXECUTE` 與正式資料庫變更之啟用條件、流程與禁止事項  
**狀態：** **本文件不啟用** `LIVE_EXECUTE`；**不**構成對 production 的執行授權  

**關聯文件：** `PRODUCTION_ACTIVATION_CHECKLIST.md`、`PHASE5_IMPLEMENTATION_PLAN.md`、`PHASE4_COMPLETION_REPORT.md`  

---

## 1. 目的與適用範圍

本政策定義在 SQL Safe Migration 5.0 架構下，**何時**、**在何種治理條件下**得考慮啟用 **Production Live Execution**（技術上對應 **`LIVE_EXECUTE` 模式**與相關腳本路徑）。在 **`production-live-enabled` 仍為 NO** 且 **`EnableLiveExecution` 技術開關未核准啟用** 前，所有 production 變更仍須依既有離線／模擬流程處理，**不得**將本政策視為自動核准。

**適用對象：** 負責 DB 變更的開發／DBA／運維／核准人員與使用治理包裝工具之自動化（含 AI 輔助流程）。

---

## 2. LIVE_EXECUTE 啟用條件（必要條件，全部滿足）

以下條件為**制度面與技術面**之啟用門檻；實際啟用須另經 **Final Production Sign-Off**（見第 7 節）。

| 編號 | 條件 | 說明 |
|------|------|------|
| L-01 | **Phase 4 完成標記** | `implementation-ready = YES`、`activation-test-passed = YES`。 |
| L-02 | **Governed Migration Wrapper 支援 LIVE_EXECUTE** | Wrapper 與契約須明確實作並通過測試；在實作完成前 **視為不具備** live 能力。 |
| L-03 | **EnableLiveExecution 開關** | 經核准之技術開關（例如環境變數／設定檔／核准旗標，詳見 Phase 5 實作計畫）設為 **允許**；未啟用時 wrapper **必須**拒絕 `LIVE_EXECUTE`。 |
| L-04 | **Human Approval Gate 強化版通過** | 依第 4 節之核准矩陣與證據留存完成；缺任一核准證明即 **FAIL**。 |
| L-05 | **Recovery Readiness Checker PASS** | Plan-only 檢查通過；`.bak`、schema-only 快照、Recovery Mode A 文件與路徑符合契約。 |
| L-06 | **Risk／Proposal／Connection／Tenant 等 checker PASS** | 與該次變更相關之 checker 輸出為可接受狀態；不得以略過參數規避。 |
| L-07 | **Maintenance Window 已確認** | 依第 5 節取得排程與利害關係人確認；緊急變更須依例外流程記錄。 |
| L-08 | **稽核與報告** | Audit Report Generator 產出路徑可用；該次執行可關聯 `requestId`／`changeRequestId` 與時間戳。 |
| L-09 | **Git 與變更可追溯** | 執行所依 proposal／SQL／設定版本可對應至已審核之版本控制狀態（依組織規範）。 |

---

## 3. 必須通過的治理檢查（摘要）

執行 **`LIVE_EXECUTE`** 前，下列治理檢查須在包裝層或等效流程中**全部**取得 PASS 或可稽核之 NEEDS_REVIEW 結案（NEEDS_REVIEW 未結案視同 **不得執行**）：

1. **Proposal／Change Request 一致性** — 識別碼、環境、風險等級與核准欄位與實際變更一致。  
2. **Human Approval Gate** — 見第 4 節；含核准碼／核准檔／核准紀錄之可驗證性。  
3. **Recovery Readiness** — 備份與 schema 證據、Recovery Mode A 聲明與人工責任分界。  
4. **Risk 與 Schema 證據** — risk summary、schema diff／snapshot 證明符合該次變更範圍。  
5. **Audit logging** — 執行前後關鍵事件可寫入既定稽核路徑（實作細節見 Phase 5 計畫）。  
6. **技術開關與模式** — 僅在 `EnableLiveExecution` 允許且模式明確為 `LIVE_EXECUTE` 時進入執行分支；否則 **硬失敗**。

---

## 4. 禁止事項

| 類別 | 禁止行為 |
|------|----------|
| **AI／自動化** | AI **不得**自動對 production 執行 **RESTORE**、**ROLLBACK** 或等效破壞性復原；僅能診斷與提出方案，**須人工確認後**方可執行（Recovery Mode A）。 |
| **繞過治理** | 不得以手動執行 SQL、其他腳本或隱性 `.env` 設定繞過 Approval／Readiness／Report。 |
| **未授權啟用** | 在 **`production-live-enabled`** 與 **`EnableLiveExecution`** 均未依組織流程核准前，**不得**啟用 `LIVE_EXECUTE` 或宣稱 production live 已啟用。 |
| **資料外洩** | 稽核報告、輸入 JSON、log **不得**含未遮罩之連線字串、密碼或秘密。 |
| **未經審核之 SQL** | 不得執行未納入變更提案與審核軌跡之 SQL。 |
| **維護窗口外** | 除已核准之緊急變更程序外，不得在維護窗口外執行高風險 `LIVE_EXECUTE`。 |

---

## 5. Human Approval 流程

### 5.1 原則

- **預設拒絕：** 無有效核准輸入 → **不得**進入 live 執行。  
- **分離職責：** 建議至少區分 **變更申請核准**、**技術審查核准**、**執行時刻核准**（實際角色名稱依組織定義）。  
- **可驗證：** 核准必須能以檔案雜湊、核准碼、簽核系統連結或組織規定之方式**事後稽核**。

### 5.2 Phase 5 強化方向（制度面）

| 項目 | 說明 |
|------|------|
| **核准輸入契約** | 核准資料須符合版本化 schema（如 `approval_gate` 輸入／輸出契約延伸），含核准人、時間、範圍、變更識別。 |
| **雙重核准（建議）** | 高風險變更（例如 DROP、大量資料變更）須兩名不同職能之核准。 |
| **時間盒** | 核准僅在指定時間窗內有效；逾時須重新核准。 |
| **與 EnableLiveExecution 綁定** | 技術開關啟用須登記核准單號／紀錄 ID，避免「開關已開但無人知道」。 |

### 5.3 Recovery Mode A（重申）

- **AI：** 診斷、方案、檢查清單與報告產出。  
- **人員：** 確認備份、確認還原步驟、下達執行與承擔後果。  
- **自動化：** 不得將 production restore／rollback 設為無人監督之預設路徑。

---

## 6. Maintenance Window Policy（維護窗口）

| 規則 | 內容 |
|------|------|
| **排程** | `LIVE_EXECUTE` 應僅排於已公告之維護窗口；窗口須含預估起訖、影響系統與回滾預留時間。 |
| **通知** | 依組織規範完成利害關係人通知（業務、運維、客服等）。 |
| **凍結** | 窗口內避免與該次變更無關之並行 production 變更；若無法避免須記錄風險加總評估。 |
| **驗證時段** | 執行後須保留驗證時段；驗證未通過依預案停止或進入 Recovery Mode A，**不得**由 AI 單方決定 restore。 |
| **緊急例外** | 生產事故須緊急修補時，仍須**事後補齊**核准與稽核紀錄，並於事後檢討中說明與標準流程之差異。 |

---

## 7. Final Production Sign-Off 規則

**Sign-Off** 為啟用 **`LIVE_EXECUTE`** 與（若適用）**`production-live-enabled = YES`** 之最終人工作業，須同時滿足：

1. **`PRODUCTION_ACTIVATION_CHECKLIST.md`** 全數勾選完成並留存證明。  
2. **本政策第 2、3 節** 所列條件與檢查已滿足且有稽核產物。  
3. **指定 Sign-Off 人員**（例如 DBA 負責人／資安或變更委員會代表，依組織定義）書面或等效系統確認。  
4. **EnableLiveExecution** 啟用與 Sign-Off **同一變更單**或可追溯關聯。  
5. **回滾與通訊計畫** 已確認並可於執行當下取用。

未完成 Sign-Off，**不得**將環境視為「已核准 production live」。

---

## 8. 緊急停止條件（執行中／執行前）

任一出現即應**中止或不得開始** `LIVE_EXECUTE`（並記錄原因）：

| 編號 | 條件 |
|------|------|
| E-01 | Recovery Readiness 任一必要項目 **FAIL** 或證明文件遺失／過期。 |
| E-02 | 核准逾時、核准範圍與實際 SQL／proposal **不一致**。 |
| E-03 | 監控或前置健康檢查顯示 production **異常**（連線、磁碟、複寫延遲等，門檻依運維規範）。 |
| E-04 | 維護窗口**取消**或**縮短**導致無足夠驗證與回滾時間。 |
| E-05 | 發現未預期之高風險陳述（例如非預期 DROP／TRUNCATE／全表 UPDATE）與核准範圍不符。 |
| E-06 | **EnableLiveExecution** 被撤回或環境設定與核准紀錄不符。 |
| E-07 | 人工下達「停止變更」指令（優先於任何自動化繼續執行）。 |

---

## 9. 文件修訂與生效

- 本文件為 Phase 5 **規劃產出**；修訂應經變更管理。  
- **生效日**與 **`production-live-enabled`** 狀態變更須與組織變更委員會或等效流程一致，**不由本文件自動觸發**。

---

*本政策不授權任何未經 Final Sign-Off 之 production 執行；技術實作須另依 `PHASE5_IMPLEMENTATION_PLAN.md` 分階段完成與驗證。*
