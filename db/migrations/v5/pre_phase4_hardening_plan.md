# SQL Safe Migration 5.0 Pre-Phase 4 Hardening Plan

## 1. 目的

本文件為 **Phase 4 正式啟用前**之**補強規劃**（Pre-Phase 4 Hardening），用於對齊 Phase 3 結論、界定必做項目與執行順序。**不代表**已進入 Phase 4、**不代表**可執行正式 migration 或啟用 Execute Mode。實作與腳本變更應在後續獨立步驟中，依本計畫與治理流程核准後進行。

## 2. Phase 3 結論摘要

Phase 3（測試版）已完成並入庫者包括：

| 產物 | 說明 |
|------|------|
| Phase 3 test plan | `phase3_test_plan.md`：目標、禁止事項、測試範圍、Exit Criteria、Recovery Mode A 原則 |
| Inventory report | `phase3_inventory_report.md`：Phase 2 工具與測試資料盤點 |
| Mock cases | `tests/phase3_mock_cases/`：proposal mock、mock schema 文字、README |
| Static test results | `phase3_static_test_results.md`：非 SQL dry-run／static-check 執行紀錄 |
| Final test report | `phase3_final_test_report.md`：Step 1～5 彙總與 Phase 4 前建議 |
| Git governance review | `phase3_git_governance_review.md`：入庫邊界與排除 `tenant_service_limits.sql` |
| 版本庫狀態 | 上列 Phase 3 產物已 **commit / push** 至 **origin/main**（不含 `db/tenant_service_limits.sql`） |

Phase 3 **全程**遵守測試版邊界：**未**執行 SQL、**未**連線 SQL Server、**未**讀取 `.env`、**未**修改正式 DB；**未**建立 Execute Mode。

## 3. Phase 4 前必須補強項目

| 編號 | 補強項目 | 目的 | 優先級 | 是否阻擋 Phase 4 | 備註 |
|------|----------|------|--------|------------------|------|
| 1 | 建立 mock-compatible preflight mode | 使 mock proposal 可在不連線下得到可預期 preflight 結果，並與 production 分軌 | P0 | **是** | Phase 3 顯示 mock `server` 觸發 `db_connection_guard` FAIL |
| 2 | 建立獨立 schema diff checker script 或明確整合入口 | 滿足 DBCR／Plan Report 對 schema diff 產物之可重現檢查 | P0 | **是** | Phase 2／3 無自動比對兩份 schema-only 之工具 |
| 3 | 明確區分 mock / dry-run / production preflight | 避免誤用測試放寬於正式路徑 | P0 | **是** | 需文件 + 參數／命名契約 |
| 4 | 建立 report generator mock input contract | 穩定 `plan_report_generator` 測試與 CI，避免依賴 `%TEMP%` 或非契約輸入 | P1 | 條件式 | 未定型前易產生「假陰性／假陽性」解讀 |
| 5 | 將 Recovery Mode A 納入正式啟用前檢查 | 確保復原流程符合「人員確認後才可執行」 | P0 | **是** | 與 `phase3_test_plan.md` §7 一致 |
| 6 | 建立 Phase 4 activation checklist |  gate 正式啟用與 Execute Mode 規劃之書面準則 | P0 | **是** | 與 checklist 全部勾選前不宣告 Phase 4 啟用 |

## 4. 補強項目詳細說明

### 4.1 Mock-compatible Preflight Mode

**現況**：Phase 3 中，即使 proposal 與 `proposal_checker` 通過，**preflight** 仍可能因 **`db_connection_guard`** 對 **mock server**（不在允許清單）回報 **FAIL**，導致無法展示「valid mock 端到端 PASS」之演練路徑。

**Phase 4 前目標**：引入**安全的** mock-compatible／no-db-check（僅語意上放寬「伺服器白名單」檢查，**不**放寬 SQL 執行或連線）之模式。

**要求**：

- **僅**適用於 **mock / test** 路徑（例如明確參數 `-PreflightMode Mock` 或專用子命令；實際命名以設計文件為準）。
- **預設**與 **production preflight** 路徑**不可**關閉 DB guard；production 不得使用 mock mode。
- 報告與 log 中**必須標示**所使用之 preflight mode，便於稽核。
- **不可**透過 **`.env`** 隱性切換模式（避免環境漂移與未宣告行為）；若需設定檔，應限於測試目錄內之明示檔案且不入 production 流程。

### 4.2 Schema Diff Checker

**現況**：Phase 2／Phase 3 具 **plan_report_generator** 與政策文件對「before／after schema-only、diff report」之**敘述**，但**無**獨立、可重複執行之 schema diff checker script。

**Phase 4 前目標**：補齊**離線**結構差異分析能力。

**要求**：

- 輸入為**兩份** schema-only 文字檔（例如 `.sql`），**不**連 SQL Server。
- 以**文字／結構**差異分析為主（可採行級 diff、或規則化 parse 後比對，細節由設計文件決定）。
- 可產出 **diff report**（人類可讀、可歸檔）。
- 可標示 **unexpected changes**（例如 DROP、TRUNCATE、敏感物件），與 **RISK_RULES**／proposal 對照之擴充點於設計文件註記。

### 4.3 Preflight Mode Separation

需**文件化**並在實作中分離至少三種語意：

| Mode | 允許（摘要） | 禁止（摘要） |
|------|----------------|----------------|
| **Mock** | 使用 mock proposal／mock server 別名；可啟用 mock-compatible guard 規則；僅產生報告與 exit／狀態供測試 | 連線正式 DB、執行 SQL、使用 production connection string、關閉 production 專用之安全檢查 |
| **Dry-run（plan）** | 讀取真實命名之 proposal（仍不執行 SQL）；完整靜態 checkers；產出 plan／preflight 報告 | Execute、對 DB 寫入、以 dry-run 名義繞過核准 |
| **Production preflight** | 與正式變更掛鉤之靜態檢查；完整 DB guard；結果進入治理與簽核 | Mock-only 放寬、未核准之 Execute、隱藏 mode |

（實作細節與參數名稱應寫入獨立設計文件；本表為契約層級。）

### 4.4 Report Generator Mock Input Contract

**目標**：為 `plan_report_generator`（及相關測試）定義**最小且穩定**的輸入集合，例如：

- Proposal 檔路徑（必填）。
- Preflight Markdown 報告路徑（選填，但契約需定義「有／無」時預期結論欄位）。
- Approval／hash 結果 JSON 路徑（選填；與 preflight 二擇一或併用之規則需固定）。

**要求**：契約應包含**範例檔**或 **fixture 目錄**約定、欄位語意、以及預期 **Final Conclusion** 列舉值（如 `PLAN_INCOMPLETE`／`PLAN_FAIL`／`PLAN_BLOCKED`／`PLAN_PASS`）對應表，避免測試依賴未版本化之暫存路徑。

### 4.5 Recovery Mode A Activation Check

本專案採用 **Recovery Mode A**：

- **AI**（或工具）可進行診斷、整理復原方案與風險說明；
- **人員確認**後，才可執行實際復原或還原作業。

**Phase 4 前要求**：

- Recovery policy 必須**書面文件化**（與 Phase 3 所述一致並可獨立引用）。
- **不允許** AI 或腳本**自動**對 **production DB** 執行 restore／rollback。
- **不允許**無人確認之 rollback／還原。
- 必須能產出 **recovery report**（或同等稽核產物）模板與填寫欄位定義。

### 4.6 Phase 4 Activation Checklist

正式宣告 Phase 4 啟用（含後續 Execute Mode **規劃／實作**之核准）前，建議至少滿足：

- [ ] Phase 3 已完成並已入庫（main 可稽核）。
- [ ] Preflight **mode 分軌**已文件化並通過 review。
- [ ] **Mock-compatible preflight** 行為與限制已定義且可測試。
- [ ] **Schema diff checker**（或明確整合入口）已定義且可離線驗證。
- [ ] **Report generator mock input contract** 已定稿並有 fixture。
- [ ] **Recovery Mode A** 文件與 checklist 已定稿。
- [ ] **Git working tree** 處於可審核狀態（無意外未提交之敏感檔）。
- [ ] **`db/tenant_service_limits.sql` 未入庫**、非 Phase 4 啟用條件之一。
- [ ] 補強與測試流程**未**依賴讀取／修改 **`.env`** 作為隱性開關。
- [ ] **Execute Mode** 尚未建立或尚未啟用，**除非**經正式啟用流程與 checklist 核准。

## 5. 建議執行順序

1. **文件化 mode separation**（mock / dry-run / production）— 先做契約，避免實作分歧。
2. **建立 mock-compatible preflight 設計文件**（參數、預設、稽核欄位、與 DB guard 關係）。
3. **建立 schema diff checker 設計文件**（輸入輸出、報告格式、與 DBCR 對齊）。
4. **建立 report generator input contract**（範例與結論對照表）。
5. **建立 Recovery Mode A checklist**（與 recovery report 模板）。
6. **建立 Phase 4 activation checklist**（可勾選、可簽核）。
7. **再進入實作補強**（腳本與測試程式變更應有獨立 PR／治理步驟）。

## 6. 不在本階段執行的事項

本文件**僅規劃**，下列事項**不在**建立本文件之同一動作中執行：

- 不建立 **Execute Mode**
- 不執行 **SQL**
- 不連線 **SQL Server**
- 不修改正式 DB
- 不使用 **production connection string**
- 不讀取或修改 **`.env`**
- 不處理 **`db/tenant_service_limits.sql`**（不讀寫、不依賴、不入庫）
- 不宣告進入 **Phase 4 正式啟用**

## 7. 結論

- 本文件完成後，**仍不應直接進入 Phase 4 正式啟用**；僅完成「補強前之計畫與優先級」對齊。
- **下一步**應為 §5 所列之**補強設計文件**與 checklist 定稿，**而非**立即啟用正式 migration 或 Execute Mode。
- **`tenant_service_limits`** 若未來要落地，必須**另案**走 **DB Change Request**／**proposal**／**governed migration** 流程，**不得**以本機草稿 SQL 繞過 SQL Safe Migration 5.x 治理。
