# SQL Safe Migration 5.0 Pre-Phase 4 Mode Separation Design

## 1. 目的

本文件為 **Phase 4 正式啟用前**之設計產出，用於**明確區分**三種語意模式：**MOCK_MODE**、**DRY_RUN_MODE**、**PRODUCTION_PREFLIGHT_MODE**，避免測試放寬、靜態演練與正式變更前檢查互相混用。**不**代表已進入 Phase 4、**不**代表可執行 migration 或啟用 Execute Mode。

## 2. 模式總覽

| Mode | 用途 | 是否可連 SQL Server | 是否可執行 SQL | 是否可讀 .env | 是否可用 mock data | 是否可用 production connection string |
|------|------|---------------------|----------------|---------------|-------------------|---------------------------------------|
| **MOCK_MODE** | 單元／整合層級之 mock proposal、mock schema 文字、checker 與報告演練 | **否** | **否** | **否** | **是** | **否** |
| **DRY_RUN_MODE** | 以「真實命名與契約」進行 plan-only：讀取 proposal、schema-only 快照檔、靜態 checker、diff 與 dry-run 報告 | **否** | **否** | **預設否**（見 §4） | **條件式**（僅限非正式路徑之測試資料；不得冒充 production） | **否** |
| **PRODUCTION_PREFLIGHT_MODE** | 正式變更前之治理檢查：備份／快照／proposal／risk／diff／稽核產物齊備性與 DB guard | **否**（本設計階段；**未**核准之實連線診斷） | **否** | **預設否**（見 §5） | **否** | **否**（僅可驗證「目標環境欄位」等政策語意；**不得**在 preflight 載入密鑰或建立實連線） |

> 註：上表「是否可連 SQL Server／執行 SQL」係依 **SQL Safe Migration 5.0 當前 Plan-only 邊界**填寫。若未來 Phase 4+ 引入**經單獨核准**之最少權限連線診斷，須**另案**文件化，且**不得**與 MOCK_MODE 混用。

## 3. MOCK_MODE 規則

### 允許

- 使用 **mock proposal**（含 `phase3_mock_cases` 等測試專用 JSON）。
- 使用 **mock schema files**（純文字 DDL 素材，標示 *do not execute*）。
- 使用 **`phase3_mock_cases`** 與後續專用 mock 目錄。
- 執行 **proposal checker**、**risk checker**、**underestimation**（由 `risk_checker` 之 `riskUnderestimated` 等靜態結果體現）。
- 產生 **mock report**（Markdown／JSON，路徑可於測試輸出目錄）。

### 禁止

- 連線 **SQL Server**。
- **執行 SQL**（含對 mock `.sql` 檔之實際執行）。
- 讀取 **`.env`**。
- 使用 **production connection string**。
- 使用**正式 DB schema 產物**作為權威來源（僅限測試隔離之副本或純文字 fixture）。
- 使用或依賴 **`db/tenant_service_limits.sql`**。

## 4. DRY_RUN_MODE 規則

### 允許

- 讀取**經版本治理與審核流程認可**之 **proposal**（路徑與版本須可追溯）。
- 讀取 **schema-only snapshot** 檔案（僅檔案層級；不觸發 DB）。
- 執行 **static checker**（如 `proposal_checker`、`risk_checker`、`db_connection_guard`、`tenant_sno_checker` 等，以現有腳本為準）。
- 執行 **risk checker**、**schema diff checker**（後者於補強完成後；現階段仍可能為「設計中」）。
- 產生 **dry-run report**（preflight／plan report 等）。

### 禁止

- **修改正式 DB**。
- **執行 migration SQL** 或任何對資料庫生效之批次。
- **建立 Execute Mode** 或等同自動執行管線。
- **自動 rollback**／無人監督之復原動作。
- 使用**未核准**之 SQL 草稿作為權威輸入。

### 是否可讀 .env

- **預設不可**。
- 若未來組織政策**明確要求**由 CI／祕鑰管理注入連線資訊，必須**另訂安全讀取規則**（白名單鍵名、禁止記錄、禁止寫入日誌明文等），且**不可**以「未宣告之隱性讀取」切換行為。

### mock data

- **條件式允許**：僅限標註為測試路徑之資料，且不得與 production 變更請求混檔。

## 5. PRODUCTION_PREFLIGHT_MODE 規則

### 允許

- **檢查 production connection readiness**（於本設計語意下指：**政策與欄位層級**之齊備性，例如 server／database 命名是否與 DBCR 一致；**不含**未核准之實連線）。
- **驗證 backup policy**、**schema snapshot policy**（文件與產物清單對照）。
- 驗證 **proposal／risk／diff／audit** 是否齊全（與 `DB_CHANGE_REQUEST_POLICY` 等對齊）。
- 產生 **production preflight report**（報告須標示本 mode，見 §6）。

### 禁止

- **執行 migration SQL**。
- **修改正式 DB**。
- **自動 rollback**。
- **跳過 DB guard**（含 mock-compatible 放寬）。
- **跳過人工確認**（核准、簽核、Recovery Mode A）。
- **跳過 Recovery Mode A**（復原須人員確認後始可執行，見 §8）。

### 是否可讀 .env

- **預設否**；與 §4 相同，若未來需讀取須**明示規則**與核准，**禁止**隱性依賴 `.env` 切換模式。

## 6. Mode 切換規則

- **Mode 必須由明確參數／旗標指定**（例如 orchestrator／CLI 之 `-PreflightMode`；實際名稱於實作規格書訂定）。
- **不得**由 **`.env`** 或環境變數**隱性**切換 mode。
- **不得**自動從 **MOCK_MODE**「升級」到 **PRODUCTION_PREFLIGHT_MODE**；轉換須**新程序**與**人工確認**。
- **PRODUCTION_PREFLIGHT_MODE**（及任何將影響正式變更決策之輸出）須經**人工確認**與治理流程。
- **不同 mode 產出之報告**須於顯眼處**標示 mode**（與時間、proposal id）。
- **dangerous／do_not_execute** 類測試案例**永遠不得**視為 production 輸入，**不得**進入 production preflight 之「核准通過」路徑。

## 7. 安全預設值

最保守預設：

- **未指定 mode**：視為**不允許執行**任何需 mode 之腳本行為（應回傳錯誤或使用說明）。
- **未指定 mode**：**不得**連線 DB、**不得**讀 `.env`、**不得**執行 SQL。
- 實作上宜採 **opt-in**（必須傳入合法 mode 才繼續），避免遺漏參數時誤用正式語意。

## 8. 與 Recovery Mode A 的關係

本專案採用 **Recovery Mode A**：

- **AI** 或工具可做診斷、整理復原步驟與風險；
- **人員確認**後，才可執行實際復原／還原。

**Mode Separation** 與之一致要求：

- **不得**允許 AI 或腳本**自動 restore production DB**。
- **不得**允許 AI 或腳本**自動 rollback production migration**。
- **不得**無人確認即執行復原；**recovery report** 須可產出並留存稽核。

## 9. Phase 4 前實作建議

1. 在 **`preflight_orchestrator.ps1`**（後續實作步驟）增加**明確 mode 參數**與說明文字，與本文件表格對齊。
2. 建立 **mock-compatible preflight** 行為（僅 MOCK_MODE 下放寬之 guard 規則；見後續 Step 2 設計）。
3. **PRODUCTION_PREFLIGHT_MODE** 必須**完整保留** DB guard；禁止與 mock 放寬共用預設路徑。
4. **Report generator** 必須在報告 metadata 區**顯示 mode**（及 generator 版本／時間）。
5. **測試 case** 须覆蓋三種 mode 之**允許／禁止**矩陣（至少靜態契約測試）。
6. 建立 **mode misuse** 測試（例如缺 mode、非法 mode 字串、從 mock 參數混入 production 路徑等）。

## 10. 不在本階段執行事項

本文件僅為設計；**不在**撰寫本文件之同一步驟中：

- **不修改** `preflight_orchestrator.ps1` 或其他既有工具。
- **不建立** Execute Mode。
- **不執行** SQL。
- **不連線** SQL Server。
- **不讀取或修改** `.env`。
- **不處理** `db/tenant_service_limits.sql`。
- **不進入** Phase 4 正式啟用。

## 11. 結論

- 本文件完成後，**可進入 Step 2：Mock-compatible Preflight 設計文件**（於該文件中細化 MOCK_MODE 下放寬項目與稽核欄位）。
- **仍不應**進入 Phase 4 正式啟用；需完成 `pre_phase4_hardening_plan.md` 所列補強與後續設計／實作步驟。
- **`tenant_service_limits.sql`** 仍**不可**作為依賴或入庫對象；若功能落地须走 **DB Change Request**／**proposal**／**governed migration**。
