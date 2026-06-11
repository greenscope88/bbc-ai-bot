# BATS_DATA_SYNC_TEST_PLAN.md

**專案：** BBC AI SaaS / BATS / BDS / Travel Data Center  
**定位：** L2 測試規劃層 — BDS v1 Test Plan  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_DATA_SYNC_POLICY.md`、`BATS_DATA_SOURCE_REGISTRY.md`、`BATS_DATA_CONTRACT.md`、`BATS_DATA_OWNERSHIP_POLICY.md`、`BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md`  
**適用範圍：** BDS v1 MVP — `tenant_private_knowledge`（Pilot：`travel_b`）  
**適用對象：** ChatGPT、Cursor、開發者、維運人員、QA  
**衝突處理：** 實作 Phase 以 `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` 為準；欄位與 Validation 以 `BATS_DATA_CONTRACT.md` 為準；Safety Rule 以 `BATS_DATA_SYNC_POLICY.md` §14 為準；**測試範圍與必要測試清單以本文件為準**。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §2 | Test Scope |
| §3 | Required Tests |
| §4 | Minimal Test Principle |
| §5 | Acceptance Criteria |
| §6 | Cross References |

---

## 1. Purpose

### 1.1 文件定位

本文件定義 **BDS v1** 之 **測試範圍**、**必要測試清單** 與 **各 Phase 驗收條件**，目標是：

- **避免漏測**：MVP 關鍵路徑（5 Tabs、整檔 Fail、不覆蓋、Registry driven）必有測試
- **避免過度測試**：不測 RAG、PDF、shared_knowledge、Cron 等 Out of Scope 項目
- **對齊實作 Phase**：測試隨 `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` Phase 1～6 漸進補齊

### 1.2 本文件回答

| 問題 | 章節 |
|------|------|
| 哪些 Phase 需要測？測什麼？ | §2 |
| 哪些測試為 **必做**？ | §3 |
| 哪些測試 **不做**？ | §4 |
| 各 Phase 何時算通過？ | §5 |

### 1.3 測試策略總覽

```text
單元測試（Phase 1～2）     mock fixtures、Validator 規則
        ↓
整合測試（Phase 3）        dry-run 輸出 + reports
        ↓
介面測試（Phase 4）        Sheet Reader（可 mock API）
        ↓
受控測試（Phase 5）        GCS Writer + feature flag gate
        ↓
端對端（Phase 6）          Manual Sync Command（controlled tenant）
```

### 1.4 本文件不做

| 不做 | 說明 |
|------|------|
| 撰寫測試程式 | 實作 Phase 依本文件清單開發 |
| 定義欄位 schema | 見 `BATS_DATA_CONTRACT.md` |
| 定義 CI pipeline 細節 | 實作時對齊專案既有測試慣例 |
| 效能 / 壓力測試規格 | v1 不納入 |

---

## 2. Test Scope

測試範圍 **嚴格對齊** `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` §3 六個 Phase。

### 2.0 Phase 與測試類型對照

| Phase | 名稱 | 測試類型 | 外部依賴 |
|-------|------|----------|----------|
| **1** | Local Mock Parser | 單元 | 無 |
| **2** | Validator | 單元 | 無 |
| **3** | JSON Writer Dry-run | 整合 | 本機檔案系統 |
| **4** | Google Sheet Reader | 整合 | Google API（可 mock） |
| **5** | GCS Writer Controlled Mode | 整合 / 受控 | GCS（受控環境） |
| **6A** | Manual Sync Command | 端對端 | Sheet 完整管線（受控） |
| **6B** | Drive Connector Read-only | 整合 | Drive API 唯讀 |
| **6C** | Metadata Contract & File Classification | 整合 | Metadata envelope |
| **6D** | Drive → GCS Controlled Promote | 受控 | GCS Archive |
| **6E** | Verification & Close-out | 驗證 | Shared + read-back |

### 2.1 Phase 6A～6E 測試對照

| 子階段 | 測什麼 | SSOT |
|--------|--------|------|
| **6A** | T-01～T-10 端對端；CLI；reports | 本文件 §Phase 6A |
| **6B** | Drive list 唯讀；零 GCS 寫入 | `BATS_DRIVE_CONNECTOR_SCOPE.md` |
| **6C** | P1 Metadata 必填欄位；Metadata ≠ Knowledge | `BATS_DRIVE_METADATA_CONTRACT.md` |
| **6D** | 受控 promote；不覆蓋 `knowledge/*.json` | `BATS_DRIVE_GCS_MAPPING.md` |
| **6E** | Shared 未 Policy 時零 promote；close-out | `BATS_DRIVE_CONNECTOR_SCOPE.md` §9 |

---

### Phase 1 — Local Mock Parser

#### 測什麼

| 項目 | 說明 |
|------|------|
| 5 Tabs mock 輸入 | CSV / array fixtures |
| Tab → JSON 轉換 | 結構符合 `BATS_DATA_CONTRACT.md` §6 |
| 參數化 `sno` | 不以固定 `travel_b` 寫死 |

#### 不測什麼

| 排除 | 說明 |
|------|------|
| Google API | Phase 1 零網路 |
| GCS | 不寫雲端 |
| Validation 完整規則 | Phase 2 負責 |

---

### Phase 2 — Validator

#### 測什麼

| 項目 | 說明 |
|------|------|
| Required Tabs 檢查 | 缺 Tab → Fail |
| Required Fields 檢查 | 缺欄位 → Fail |
| 英文欄位名稱 | 中文欄位 → Fail |
| 整檔 Fail | 任一錯誤 → 全檔 Fail |
| 錯誤碼 | 對齊 `BDC_*` 概念（`BATS_DATA_CONTRACT.md` §8.7） |

#### 不測什麼

| 排除 | 說明 |
|------|------|
| 單列跳過 | Future Roadmap |
| 中文別名映射 | Future Roadmap |
| GCS 覆蓋行為 | Phase 3 / 5 負責 |

---

### Phase 3 — JSON Writer Dry-run

#### 測什麼

| 項目 | 說明 |
|------|------|
| 本機 5 JSON 輸出 | `preview/output/tenants/{sno}/knowledge/` |
| tmp → validate → promote 流程 | 模擬 Safety Rule |
| `sync_report.json` | 成功 / dry_run 狀態 |
| `validation_report.json` | passed / failed |
| `error_report.json` | 失敗時必有 |

#### 不測什麼

| 排除 | 說明 |
|------|------|
| GCS 寫入 | Phase 5 |
| Google Sheet 真實讀取 | Phase 4 |

---

### Phase 4 — Google Sheet Reader

#### 測什麼

| 項目 | 說明 |
|------|------|
| Registry 載入 `private_knowledge_sheet_id` | 無 hardcode Sheet ID |
| 讀取 5 Required Tabs | 結構交給 Validator |
| 讀取失敗錯誤分類 | E1 來源錯誤（概念） |
| CI 友善 | 可 mock Google API 回應 |

#### 不測什麼

| 排除 | 說明 |
|------|------|
| GCS 寫入 | Phase 5 |
| Shared Layer Sheet | v1 Out of Scope |
| OAuth 互動流程 UI | 僅測 Reader 模組契約 |

---

### Phase 4.1 — Live Read ✅

| 項目 | 說明 |
|------|------|
| **目標** | Service Account 實際讀取 Pilot private knowledge Google Sheet |
| **測試** | `tests/bds/test_bds_google_sheet_reader.php`（live 分支） |
| **環境** | `BDS_TEST_PRIVATE_KNOWLEDGE_SHEET_ID`、`BDS_GOOGLE_APPLICATION_CREDENTIALS` |
| **結果** | **PASS** ✅ — 5 Required Tabs 可讀；缺 tab 不 fatal |

---

### Phase 4.2 — Integration Dry-run ✅

| 項目 | 說明 |
|------|------|
| **目標** | 真實 Google Sheet 串接完整本機 dry-run 管線 |
| **管線** | Google Sheet → Reader → Parser → Validator → JSON Writer |
| **測試** | `tests/bds/test_bds_google_sheet_pipeline_dry_run.php` |
| **環境** | `BDS_TEST_PRIVATE_KNOWLEDGE_SHEET_ID`、`BDS_TEST_TENANT_SNO`、`BDS_GOOGLE_APPLICATION_CREDENTIALS` |
| **成功產物** | `tests/bds/output/tenants/{sno}/knowledge/*.json`（5 檔）+ reports |
| **失敗產物** | `error_report.json`；**不** promote knowledge JSON |
| **結果** | **PASS** ✅ |

```text
Google Sheet
    ↓
BdsGoogleSheetReader
    ↓
BdsMockSheetParser
    ↓
BdsValidator
    ↓
BdsJsonWriter（dry_run=true）
```

#### Phase 4.2 不測什麼

| 排除 | 說明 |
|------|------|
| GCS 寫入 | Phase 5 |
| Google Drive API | Phase 6 |
| Shared Layer | v1 Out of Scope |
| `private_drive_folder_id` | 延後 Phase 6 |

---

### Phase 5 — GCS Writer Controlled Mode

#### 測什麼

| 項目 | 說明 |
|------|------|
| `BDS_DRY_RUN=true` | 零 GCS 寫入 |
| `BDS_GCS_WRITE_ENABLED=false` | 拒絕寫入 |
| 雙重門禁通過 + validation pass | 5 JSON 寫入 `tenants/{sno}/knowledge/` |
| validation fail | **不覆蓋** GCS 既有 JSON |
| 單一 `sno` controlled test | 不批次多租戶 |

#### 不測什麼

| 排除 | 說明 |
|------|------|
| `shared/` 路徑寫入 | v1 禁止 |
| Object Versioning | 不啟用、不測 |
| 全租戶批次 | v1 排除 |

#### Phase 5 Test Matrix

| Test ID | 名稱 | 驗證項 | 預期結果 |
|---------|------|--------|----------|
| **T-Phase5-01** | Path Mapping | GCS 寫入路徑僅 `tenants/{sno}/knowledge/` | 5 JSON 路徑正確；無 `shared/` |
| **T-Phase5-02** | Validation Fail Block | Validator `ok=false` | **零** GCS 寫入；既有 JSON 不變 |
| **T-Phase5-03** | Controlled Write | `dry_run=false` + flag 啟用 + validation pass | 5 JSON 寫入成功 |
| **T-Phase5-04** | No Shared Write | 嘗試或 mock 寫入 `shared/` | **拒絕** |
| **T-Phase5-05** | No Drive Access | GCS Writer 執行期 | **零** Drive API 呼叫 |

```text
T-Phase5-01 ──→ 路徑對照
T-Phase5-02 ──→ Fail 不寫（Safety Rule）
T-Phase5-03 ──→ 受控成功寫入
T-Phase5-04 ──→ shared/ 邊界
T-Phase5-05 ──→ Drive 隔離
```

> Phase 5 測試須在 **controlled test** 環境執行；預設 `BDS_DRY_RUN=true`。

#### Phase 5D — Read-back Verification

| Test ID | 名稱 | 驗證項 | 預期結果 |
|---------|------|--------|----------|
| **T-Phase5D-01** | objects.list | prefix `tenants/{sno}/knowledge/` | HTTP 200；5 物件 |
| **T-Phase5D-02** | Read-back | 逐一 `objects.get` | 5 JSON 可讀回 |
| **T-Phase5D-03** | Schema | `tenant_sno` / `data_category` / `schema_version` | 全部通過 |
| **T-Phase5D-04** | Path Boundary | 無 `shared/` / `shared_knowledge/` | 全部通過 |
| **T-Phase5D-05** | Safety | 無 delete / Drive / `private_drive_folder_id` | 全部通過 |

**測試指令：** `tests/bds/test_bds_gcs_readback_verification.php`（**唯讀**）

**Close-out 產物：** `tests/bds/output/phase5_closeout_report.json`

#### Phase 6 前提醒（Google Drive Folder Governance）

| 原則 | 說明 |
|------|------|
| **一旅行社一專屬 Folder** | 每 Tenant 一個 Google Drive Folder |
| **營運帳號** | `bbcshops88@gmail.com` Google Drive |
| **Default Private** | Folder 預設 Private |
| **禁止提前新增** | **不得** 於 Registry Schema 新增 `private_drive_folder_id` |

---

### Phase 6A — Manual Sync Command

> **SSOT：** `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` §Phase 6A  
> **Pilot 驗收：** `travel_b`（`5f99b8d665e8444d`）

#### 測什麼

| 項目 | 說明 |
|------|------|
| CLI 手動觸發 | `bin/bds-sync.php` end-to-end 管線 |
| Registry 驅動 | `private_knowledge_sheet_id` 從 Registry 載入 |
| 成功路徑 | 5 JSON + Read-back + `sync_report` success |
| 失敗路徑 | Validation Fail 不寫 GCS；Read-back Fail = 同步失敗 |
| `--tenant` / `--sno` | 參數解析與衝突處理 |
| 無 Cron | 確認無排程觸發 |

#### 不測什麼

| 排除 | 說明 |
|------|------|
| Google Drive / Shared Sheet 同步 / RAG | Phase 6B+ / 未來 A2/A3 |
| 上傳頁 UI 自動觸發 | v1 以手動為主 |
| 多租戶單次命令 | 單 `sno` only |
| Metadata Promote | Phase 6C～6D |

---

### Phase 6B — Drive Connector Read-only

| 項目 | 說明 |
|------|------|
| Drive list | `01_Private_Layer/` 唯讀 PASS |
| 零寫入 | 無 GCS promote；無 Knowledge 覆蓋 |
| BATS Runtime | **不** 直讀 Drive |

---

### Phase 6C — Metadata Contract & File Classification

| 項目 | 說明 |
|------|------|
| P1 必填欄位 | `tenant_sno`、`file_id`、`checksum`、`data_category` 等 |
| Metadata ≠ Knowledge | envelope **不** 取代 `knowledge/*.json` |

---

### Phase 6D — Drive → GCS Controlled Promote

| 項目 | 說明 |
|------|------|
| 雙重門禁 | `BDS_DRY_RUN` + `BDS_GCS_WRITE_ENABLED` |
| Archive only | promote 至 `archive/`；**不** 覆蓋五 JSON |
| Validation Fail | 不 promote |

---

### Phase 6E — Verification & Close-out

| 項目 | 說明 |
|------|------|
| Shared Policy | 未啟用時零 Shared promote |
| Read-back | GCS Archive 物件可驗證 |
| Close-out | Phase 6 report 產出 |

---

## 3. Required Tests

下列為 **BDS v1 必做測試**；缺少任一項不得視為 Phase 通過。

### 3.1 測試清單總表

| ID | 測試項目 | 預期結果 | 對應 Phase | 對應 SSOT |
|----|----------|----------|------------|-----------|
| **T-01** | 5 Tabs 正常解析 | 產出 5 JSON，結構符合 Contract | 1, 3 | `BATS_DATA_CONTRACT.md` §5、§6 |
| **T-02** | 缺 Required Tab | 整檔 Fail；錯誤碼含 `BDC_TAB_MISSING` | 2 | Contract §8 |
| **T-03** | 缺 Required Field | 整檔 Fail；錯誤碼含 `BDC_REQUIRED_FIELD_MISSING` | 2 | Contract §8 |
| **T-04** | 中文欄位名稱（v1） | 整檔 Fail；不接受「問題」「答案」等 | 2 | Contract §1.4 決策 A |
| **T-05** | Validation Fail 不覆蓋既有 JSON | GCS / 正式路徑舊檔不變 | 3, 5, 6 | Sync Policy §14.1 |
| **T-06** | 產生 `sync_report.json` | 每次 sync 必有 | 3, 6 | Implementation Plan §5 |
| **T-07** | 產生 `validation_report.json` | 每次 sync 必有 | 3, 6 | Implementation Plan §5 |
| **T-08** | 產生 `error_report.json`（如有錯誤） | Fail 時必有 | 3, 6 | Implementation Plan §5 |
| **T-09** | 不 hardcode `travel_b` | 程式以參數 / Registry 載入 `sno` | 1～6 | Sync Policy §23 |
| **T-10** | tenant 從 Registry 讀取 | 無 Registry entry → 拒絕同步 | 4, 6A | Source Registry §7 |

---

### 3.3 Phase 6A Test Matrix

> Phase 6A 專用測試矩陣；與 T-01～T-10 互補。全部 PASS 方視為 Phase 6A 通過。

| ID | 測試項目 | 輸入／步驟 | 預期結果 | SSOT |
|----|----------|------------|----------|------|
| **6A-01** | Registry Load | `--sno` 或 `--tenant` 載入 Registry | 取得 `private_knowledge_sheet_id`、`gcs_prefix`；缺 entry → Fail | Implementation Plan §6A.5 |
| **6A-02** | Google Sheet Read | Reader 讀取 5 Required Tabs | 5 tabs 資料可解析；缺 tab → 中止 | Phase 4 / Contract §5 |
| **6A-03** | Validation Success | 合法 Sheet 完整管線 dry-run | `validation_report` → `passed=true` | Contract §8 |
| **6A-04** | Validation Fail | 故意缺 Required Field / Tab | **不寫 GCS**；`passed=false`；舊 JSON 不變 | Safety §6A.7 |
| **6A-05** | Knowledge JSON Build | Validation Pass 後 Builder | 5 JSON 結構符合 Contract §6 | `BdsKnowledgeDocumentBuilder` |
| **6A-06** | GCS Upload | `--dry-run=false --write-gcs` + 雙重門禁 | 5 物件寫入 `tenants/{sno}/knowledge/` | Phase 5C |
| **6A-07** | Read-back Verification | upload 後 read-back | 5 物件存在且可讀；Fail → **同步失敗** | Phase 5D |
| **6A-08** | sync_report 驗證 | 成功路徑完整執行 | `sync_report.json` → `status=success`；含 `sno`、時間戳 | Runbook §7 |

**Pilot 驗收命令（概念）：**

```bash
php bin/bds-sync.php --tenant=travel_b
php bin/bds-sync.php --sno=5f99b8d665e8444d --dry-run=false --write-gcs
```

**參數衝突測試（建議納入 6A-01 擴展）：**

| 情境 | 預期 |
|------|------|
| `--sno` + `--tenant` 不一致 | 拒絕執行 |
| 無參數 | 拒絕執行 |

---

### 3.2 T-01 — 5 Tabs 正常解析

| 項目 | 說明 |
|------|------|
| **輸入** | mock 或真實 Sheet 含 5 Required Tabs，英文欄位齊全 |
| **步驟** | Parser → Validator → Writer（dry-run 或 GCS） |
| **預期** | 產出 `company_profile.json`、`service_qa.json`、`external_product_links.json`、`service_items.json`、`special_prices.json` |
| **斷言** | JSON 頂層欄位、陣列結構符合 Contract §6、§7 |

---

### 3.3 T-02 — 缺 Required Tab 必須 Fail

| 項目 | 說明 |
|------|------|
| **輸入** | 僅 4 tabs（例如缺 `special_prices`） |
| **預期** | `passed=false`；不產出可 promote 之 5 JSON |
| **報告** | `validation_report.json` 標記缺 tab；`error_report.json` 含 `BDC_TAB_MISSING` |

---

### 3.4 T-03 — 缺 Required Field 必須 Fail

| 項目 | 說明 |
|------|------|
| **輸入** | `qa` tab 存在但缺 `question` 或 `answer` |
| **預期** | 整檔 Fail |
| **報告** | `BDC_REQUIRED_FIELD_MISSING`；指出 tab / field |

---

### 3.5 T-04 — 中文欄位名稱 v1 必須 Fail

| 項目 | 說明 |
|------|------|
| **輸入** | 欄位名為「問題」「答案」而非 `question`、`answer` |
| **預期** | 整檔 Fail；**不** 嘗試別名映射 |
| **依據** | `BATS_DATA_CONTRACT.md` §1.4 決策 A |

---

### 3.6 T-05 — Validation Fail 不得覆蓋既有 JSON

| 項目 | 說明 |
|------|------|
| **前置** | GCS 或本機正式路徑已有上一版 5 JSON |
| **輸入** | 觸發 validation fail 之同步 |
| **預期** | 舊 5 JSON **內容不變**；checksum / mtime 不變（GCS 測試） |
| **依據** | `BATS_DATA_SYNC_POLICY.md` §14.1 BDS Safety Rule |

---

### 3.7 T-06～T-08 — Reports

| ID | 檔案 | 成功時 | 失敗時 |
|----|------|--------|--------|
| T-06 | `sync_report.json` | `status=success` 或 `dry_run` | `status=failed` |
| T-07 | `validation_report.json` | `passed=true` | `passed=false` + 錯誤清單 |
| T-08 | `error_report.json` | 可省略 | **必須存在** |

---

### 3.8 T-09 — 不 hardcode travel_b

| 項目 | 說明 |
|------|------|
| **靜態檢查** | grep / 程式審查：BDS 核心不得寫死 `travel_b`、`5f99b8d665e8444d` |
| **動態測試** | 以另一組 mock `sno` 執行 Parser / Sync；路徑隨 `sno` 變化 |
| **允許** | 測試 fixtures、文件範例可含 Pilot 值 |

---

### 3.9 T-10 — tenant 必須從 Registry 讀取

| 項目 | 說明 |
|------|------|
| **輸入** | 不存在之 `sno` 或缺 Registry entry |
| **預期** | 拒絕同步；明確錯誤（E5 執行時錯誤概念） |
| **輸入** | 有效 Registry entry 含 `private_knowledge_sheet_id`、`gcs_prefix` |
| **預期** | 正常載入 Sheet ID 與路徑 |

---

### 3.10 建議測試資料

| 類型 | 用途 |
|------|------|
| **golden mock** | 5 tabs 完整合法資料（T-01） |
| **missing-tab fixture** | 缺 `qa`（T-02） |
| **missing-field fixture** | `qa` 缺 `answer`（T-03） |
| **chinese-header fixture** | 中文欄位名（T-04） |
| **registry-fixture.json** | 含 `sno`、`private_knowledge_sheet_id`、`gcs_prefix`（T-10） |

> Pilot `travel_b` 僅作 golden mock **範例之一**；測試須可改用任意 `sno`。

---

## 4. Minimal Test Principle

### 4.1 原則

**只測 MVP 必要行為；不為未實作或未納入範圍之功能撰寫測試。**

| 原則 | 說明 |
|------|------|
| **對齊 Phase** | Phase N 未實作 → 不提前寫 Phase N+1 端對端測試 |
| **對齊 Contract** | 只測 Contract 已定義之 Tab / 欄位 / Validation |
| **Safety 優先** | T-05 不覆蓋為最高優先級整合測試 |
| **可 mock 外部** | Google / GCS 在 CI 可 mock；受控環境做真實寫入 |

### 4.2 明確不測（Out of Test Scope）

| 不測 | 原因 |
|------|------|
| **RAG** | v1 未實作 |
| **PDF Parser** | v1 未實作 |
| **Image Parser** | v1 未實作 |
| **`shared_knowledge` 同步** | v1 不寫 `shared/` |
| **`itinerary_data`** | 下一階段 |
| **Cron / 排程觸發** | v1 手動命令 only |
| **GCS Object Versioning** | 不啟用 |
| **Vector DB** | v1 未實作 |
| **中文欄位別名映射** | Future Roadmap |
| **單列錯誤跳過** | Future Roadmap |
| **全租戶批次同步** | v1 排除 |
| **效能 / 壓力 / 混沌測試** | v1 不納入 |
| **Gemini / LINE OA 端對端** | BATS 消費層；非 BDS 同步範圍 |
| **上傳頁 UI E2E** | Phase 6+ 可評估；v1 以 CLI 為主 |

### 4.3 不建議的過度測試

| 避免 | 說明 |
|------|------|
| 每欄位組合窮舉 | 測 required + 代表性 optional 即可 |
| 多租戶並行壓測 | v1 單 tenant controlled |
| 完整 Google OAuth UI 流程 | Reader 模組 mock 即可 |
| 還原 Object Version 歷史 | 不依賴 versioning |

---

## 5. Acceptance Criteria

各 Phase **通過** 之必要條件（對齊 Implementation Plan 退出準則 + Required Tests）。

### Phase 1 — Local Mock Parser ✅

| # | 條件 |
|---|------|
| 1 | T-01 通過（mock 5 tabs → 5 JSON preview） |
| 2 | T-09 通過（參數化 `sno`） |
| 3 | 單元測試可離線執行 |
| 4 | 無 Google / GCS 依賴 |

---

### Phase 2 — Validator ✅

| # | 條件 |
|---|------|
| 1 | T-02、T-03、T-04 通過 |
| 2 | 整檔 Fail；無 partial promote |
| 3 | 錯誤輸出可供 report 使用 |
| 4 | 單元測試覆蓋各 Fail 類型至少一案例 |

---

### Phase 3 — JSON Writer Dry-run ✅

| # | 條件 |
|---|------|
| 1 | T-01、T-05（本機正式路徑模擬）、T-06、T-07、T-08 通過 |
| 2 | 成功：本機 5 JSON + sync_report + validation_report |
| 3 | 失敗：無 promote + error_report |
| 4 | 預設 `dry_run=true` |

---

### Phase 4 — Google Sheet Reader ✅

| # | 條件 | 狀態 |
|---|------|------|
| 1 | Reader 模組 + mock 測試通過 | ✅ |
| 2 | 讀取 5 tabs 後可接 Phase 2 Validator | ✅ |
| 3 | 讀取失敗有明確錯誤分類 | ✅ |
| 4 | Phase 4.1 Live Read **PASS** | ✅ |
| 5 | Phase 4.2 Pipeline Dry-run **PASS** | ✅ |

> Registry 載入 `private_knowledge_sheet_id`（T-10）於 **Phase 6** Manual Sync 一併驗收；Phase 4 以測試環境變數驗證 Sheet 讀取。

---

### Phase 5 — GCS Writer Controlled Mode ✅

| # | 條件 | 狀態 |
|---|------|------|
| 1 | T-Phase5-01～05 通過 | ✅ |
| 2 | `dry_run=true` → 零寫入（5A） | ✅ |
| 3 | 雙重門禁關閉 → 拒絕寫入 | ✅ |
| 4 | 雙重門禁開啟 + validation pass → 5 JSON 寫入 GCS（5C） | ✅ |
| 5 | 不寫入 `shared/` | ✅ |
| 6 | GCS Preflight `objects.list` 200（5B） | ✅ |
| 7 | Read-back Verification 5 物件（5D） | ✅ |

**Phase 5 狀態：** **Completed** ✅

---

### Phase 6A — Manual Sync Command ✅

Phase 6A **PASS** 須滿足：

```text
travel_b → Google Sheet → 5 Knowledge JSON → GCS tenants/{sno}/knowledge/
         → Read-back PASS → sync_report PASS
```

| # | 條件 |
|---|------|
| 1 | Test Matrix **6A-01～6A-08** 全 PASS |
| 2 | T-01～T-10 在 **6A 端對端** 場景可重現 |
| 3 | `bin/bds-sync.php` 支援 `--tenant` 與 `--sno` |
| 4 | Validation Fail **不寫 GCS**；Read-back Fail = 同步失敗 |
| 5 | 成功 / 失敗皆有完整 report |
| 6 | 無 Cron；Runbook §13 可重現 |
| 7 | Pilot **`travel_b`**（`5f99b8d665e8444d`）受控環境跑通 **且不 hardcode** |
| 8 | **不** 觸及 Google Drive、Shared、RAG |

---

### BDS v1 整體驗收（Release Gate）

| # | 條件 |
|---|------|
| 1 | Phase 1～5 + Phase 6A～6E Acceptance Criteria 全數通過 |
| 2 | Required Tests T-01～T-10 全數通過 |
| 3 | Minimal Test Principle §4.2 未引入 Out of Scope 測試 |
| 4 | 文件齊備：Sync / Registry / Contract / Ownership / Implementation / **Test Plan** |
| 5 | 受控環境寫入 GCS 成功；Production 寫入須 explicit 批准

---

## 6. Cross References

### 6.1 BDS 文件體系

```text
L1 SSOT（政策）
├── BATS_DATA_SYNC_POLICY.md
├── BATS_DATA_SOURCE_REGISTRY.md
├── BATS_DATA_CONTRACT.md
└── BATS_DATA_OWNERSHIP_POLICY.md

L2 規劃
├── BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md   實作 Phase
└── BATS_DATA_SYNC_TEST_PLAN.md（本文件）   測試 Phase
```

### 6.2 引用對照

| 文件 | 本文件引用之內容 |
|------|------------------|
| `BATS_DATA_SYNC_POLICY.md` | §14 Safety Rule、§15 MVP、§23 Anti Hardcode |
| `BATS_DATA_SOURCE_REGISTRY.md` | §7 Registry Contract、`private_knowledge_sheet_id` |
| `BATS_DATA_CONTRACT.md` | 5 Tab / 5 JSON、§1.4 決策、§8 Validation、`BDC_*` 錯誤碼 |
| `BATS_DATA_OWNERSHIP_POLICY.md` | Tenant Layer only 寫入、v1 不測 shared |
| `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` | Phase 1～5 + 6A～6E、Required Outputs、Safety Rules |
| `BATS_DRIVE_CONNECTOR_SCOPE.md` | Runtime Source、Phase 6B～6E |
| `BATS_DRIVE_METADATA_CONTRACT.md` | Phase 6C Metadata |
| `BATS_DRIVE_GCS_MAPPING.md` | Phase 6D Mapping |
| `BATS_TENANT_DRIVE_ONBOARDING_POLICY.md` | Onboarding 驗證 |
| `BATS_DATA_SOURCE_REGISTRY.md` §4.4 | P1-7 Knowledge Priority Rule |
| `BATS_SHARED_KNOWLEDGE_SHEET_CONTRACT.md` | P2-1 Shared Sheet Tabs（未來測試） |

### 6.3 測試與實作對照

| Implementation Phase | 建議測試提交時機 |
|----------------------|------------------|
| Phase 1～3 | 與 Commit A 一併提交單元 / 整合測試 |
| Phase 4 | Commit B 提交 Reader 測試（含 mock） |
| Phase 5 | Commit C 提交 GCS 受控測試 |
| Phase 6A | Commit D 提交 Sheet E2E + 本 Test Plan 驗收勾選 |
| Phase 6B～6E | 後續 Commit；Drive Connector 測試矩陣 |

### 6.4 衝突處理

| 議題 | SSOT |
|------|------|
| 測什麼 / 不測什麼 | **本文件** |
| 實作 Phase 順序 | `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` |
| 欄位與 Validation 規則 | `BATS_DATA_CONTRACT.md` |
| Fail 不覆蓋 | `BATS_DATA_SYNC_POLICY.md` §14.1 |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.7** | 2026-06-10 | P1-7 Priority、P2-1 Shared Sheet Contract cross-ref |
| **v1.6** | 2026-06-10 | Phase 6A Out of Scope 補充 Shared Sheet 為未來 A2/A3 |
| **v1.5** | 2026-06-10 | §3.3 Phase 6A Test Matrix（6A-01～6A-08）；Acceptance Criteria 補強 |
| **v1.4** | 2026-06-10 | Phase 6A～6E 正名；6B～6E 測試對照；P1 Drive SSOT cross-ref |
| **v1.3** | 2026-06-10 | Phase 5 Close-out：5D Read-back Verification + Completed 標記 |
| **v1.2** | 2026-06-09 | 新增 Phase 5 Test Matrix（T-Phase5-01～05） |
| **v1.1** | 2026-06-09 | Phase 4 Close-out：§4.1 Live Read、§4.2 Integration Dry-run PASS |
| **v1.0** | 2026-06-08 | 第一版：BDS v1 測試範圍、Required Tests T-01～T-10、Minimal Test Principle、Phase Acceptance Criteria |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | Draft — 待審核 |
| **測試實作狀態** | Phase 1～5 **PASS**（含 5A～5D）；Phase 6 未開始 |
| **前置文件** | Implementation Plan 已 commit |
| **Pilot** | `travel_b`（`5f99b8d665e8444d`） |
