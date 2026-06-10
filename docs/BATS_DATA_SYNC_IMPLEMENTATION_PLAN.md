# BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md

**專案：** BBC AI SaaS / BATS / BDS / Travel Data Center  
**定位：** L2 實作規劃層 — BDS v1 Implementation Plan  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_DATA_SYNC_POLICY.md`、`BATS_DATA_SOURCE_REGISTRY.md`、`BATS_DATA_CONTRACT.md`、`BATS_DATA_OWNERSHIP_POLICY.md`  
**適用範圍：** BDS v1 MVP — `tenant_private_knowledge`（Pilot：`travel_b`）  
**適用對象：** ChatGPT、Cursor、開發者、維運人員  
**衝突處理：** 同步原則以 `BATS_DATA_SYNC_POLICY.md` 為準；Registry 以 `BATS_DATA_SOURCE_REGISTRY.md` 為準；資料格式以 `BATS_DATA_CONTRACT.md` 為準；歸屬與覆蓋以 `BATS_DATA_OWNERSHIP_POLICY.md` 為準；**實作階段與交付順序以本文件為準**。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §2 | Scope |
| §3 | Implementation Phases |
| §4 | Safety Rules |
| §5 | Required Outputs |
| §6 | Commit Strategy |
| §7 | Cross References |

---

## 1. Purpose

### 1.1 文件定位

本文件定義 **BDS v1** 從 **Google Sheet → JSON → GCS** 之 **分階段實作計畫**、**安全邊界** 與 **交付順序**。

本文件為 **正式寫程式前** 之必要規劃文件，承接下列 SSOT：

| 前置 SSOT | 狀態 |
|-----------|------|
| `BATS_DATA_SYNC_POLICY.md` | ✅ 已建立 |
| `BATS_DATA_SOURCE_REGISTRY.md` | ✅ 已建立 |
| `BATS_DATA_CONTRACT.md` | ✅ 已建立 |
| `BATS_DATA_OWNERSHIP_POLICY.md` | ✅ 已建立 |

### 1.2 本文件回答

| 問題 | 章節 |
|------|------|
| BDS v1 分幾個 Phase 實作？ | §3 |
| 每個 Phase 做什麼、不做什麼？ | §3 |
| 何時可連 Google API？何時可寫 GCS？ | §3 Phase 4～5 |
| 實作須遵守哪些安全規則？ | §4 |
| 每次同步須產出哪些檔案？ | §5 |
| 建議如何分批 commit？ | §6 |

### 1.3 目標管線（v1 終態）

```text
Registry（private_knowledge_sheet_id、sno、gcs_prefix）
        ↓
Google Sheet Reader（Phase 4）
        ↓
Parser + Validator（Phase 1～2）
        ↓
JSON Writer（Phase 3 dry-run → Phase 5 GCS）
        ↓
tenants/{sno}/knowledge/*.json
        ↓
Manual Sync Command + Reports（Phase 6）
```

### 1.4 本文件不做

| 不做 | 說明 |
|------|------|
| 撰寫程式 | 實作階段依 §3 另開 |
| 定義欄位 schema 細節 | 見 `BATS_DATA_CONTRACT.md` |
| 定義 Ownership / Override | 見 `BATS_DATA_OWNERSHIP_POLICY.md` |
| 啟用 Cron / Batch | v1 明確排除 |
| 建立 `config/bds/schemas/*.json` | v1 不建立機器 schema 檔 |

---

## 2. Scope

### 2.1 MVP 處理範圍（In Scope）

| 項目 | 說明 |
|------|------|
| **data_category** | `tenant_private_knowledge` only |
| **來源** | 1 Google Sheet / 租戶 |
| **Required Tabs** | `company_profile`、`qa`、`external_product_links`、`service_items`、`special_prices` |
| **JSON Outputs** | `company_profile.json`、`service_qa.json`、`external_product_links.json`、`service_items.json`、`special_prices.json` |
| **GCS 目標** | `tenants/{sno}/knowledge/` |
| **同步模式** | Mode B 概念；v1 實作以 **手動命令** 觸發（Phase 6） |
| **Validation** | 英文欄位 only、整檔 Fail（`BATS_DATA_CONTRACT.md` §1.4） |
| **Registry** | `private_knowledge_sheet_id`、`sno`、`gcs_prefix` 從 Registry 讀取 |

#### Tab → JSON 對照

| Required Tab | JSON Output |
|--------------|-------------|
| `company_profile` | `company_profile.json` |
| `qa` | `service_qa.json` |
| `external_product_links` | `external_product_links.json` |
| `service_items` | `service_items.json` |
| `special_prices` | `special_prices.json` |

### 2.2 MVP 不處理（Out of Scope）

| 排除項目 | 說明 |
|----------|------|
| **`shared_knowledge` 實作** | 不寫 `shared/{industry}/`、`shared/global/` |
| **`itinerary_data`** | 行程 Excel、PDF、商品同步 |
| **PDF Parser** | 不實作 |
| **Image Parser** | 不實作 |
| **RAG** | 不實作 |
| **Vector DB** | 不實作 |
| **自動排程（Cron）** | 不實作 |
| **GCS Object Versioning** | 不啟用；依 Safety Rule 保留舊 JSON |
| **中文欄位別名** | Future Roadmap |
| **單列錯誤跳過** | Future Roadmap |
| **全租戶批次同步** | v1 僅 controlled single-tenant test |

### 2.3 Pilot 參考（非 hardcode）

| 項目 | 值 |
|------|-----|
| `tenant_key` | `travel_b` |
| `tenant_sno` | `5f99b8d665e8444d` |
| `industry_code` | `travel`（v1 同步不使用；僅讀取 fallback 預留） |

> Pilot 值僅供測試資料與 Registry 範例；**程式不得 hardcode**（§4）。

---

## 3. Implementation Phases

### 3.0 Phase 總覽

| Phase | 名稱 | 連線 | 寫入 GCS | 狀態 |
|-------|------|------|----------|------|
| **1** | Local Mock Parser | 無 | 否 | **Completed** ✅ |
| **2** | Validator | 無 | 否 | **Completed** ✅ |
| **3** | JSON Writer Dry-run | 無 | 否（本機 preview） | **Completed** ✅ |
| **4** | Google Sheet Reader | Google API（唯讀） | 否 | **Completed** ✅ |
| **5** | GCS Writer Controlled Mode | Google API + GCS | 是（受控） | 規劃中 |
| **6** | Manual Sync Command | 完整管線 | 是（手動觸發） | 規劃中 |

```text
Phase 1 ──→ Phase 2 ──→ Phase 3
                              │
                              ↓
                         Phase 4
                              │
                              ↓
                         Phase 5
                              │
                              ↓
                         Phase 6
```

---

### Phase 1 — Local Mock Parser

#### 目標

建立 **可測試、可重現** 之 Sheet → JSON 轉換核心，**不依賴** 外部 API。

#### 做什麼

| 項目 | 說明 |
|------|------|
| **Mock 輸入** | 本機 mock CSV / PHP array 模擬 5 Required Tabs |
| **Parser** | 依 `BATS_DATA_CONTRACT.md` 將 mock 資料轉為 5 JSON 結構 |
| **輸出** | 本機 `preview/` 下 5 JSON preview 檔 |
| **測試** | 單元測試覆蓋各 Tab 基本轉換 |

#### 不做什麼

| 禁止 | 說明 |
|------|------|
| 連 Google API | 本 Phase 零網路依賴 |
| 寫 GCS | 不碰雲端 |
| 寫正式 JSON 路徑 | 僅 preview |
| hardcode `travel_b` | mock 須參數化 `sno` |

#### 交付物

- Parser 模組（本機可跑）
- mock fixtures（5 tabs）
- 5 JSON preview 範例
- 基本單元測試

#### 退出準則（Exit Criteria）

- [ ] mock 5 tabs 可穩定產出 5 JSON preview
- [ ] JSON 結構符合 `BATS_DATA_CONTRACT.md` §6
- [ ] 測試通過；無 Google / GCS 依賴

---

### Phase 2 — Validator

#### 目標

在 Parser 之上加入 **整檔 Fail** 驗證層。

#### 做什麼

| 項目 | 說明 |
|------|------|
| **Required Tabs** | 缺任一 Tab → 整檔 Fail |
| **Required Fields** | 缺必填欄位 → 整檔 Fail |
| **英文欄位** | 非英文 snake_case 欄位名 → 整檔 Fail（§1.4 決策 A） |
| **型別 / 格式** | 依 `BATS_DATA_CONTRACT.md` §8 |
| **tenant_sno** | 與 Registry / 參數一致檢查 |
| **Fail 行為** | 不產出可 promote 之正式 JSON |

#### 不做什麼

| 禁止 | 說明 |
|------|------|
| 單列跳過 | Future Roadmap |
| 中文別名映射 | Future Roadmap |
| 覆蓋既有 JSON | Fail 時零覆蓋 |

#### 交付物

- Validator 模組
- 錯誤碼對齊 `BATS_DATA_CONTRACT.md` §8.7（概念）
- validation 失敗測試案例

#### 退出準則

- [ ] 缺 Tab / 缺欄位 / 非英文欄位 → 整檔 Fail
- [ ] Fail 時不寫入任何正式或 preview promote 路徑
- [ ] 錯誤訊息可供 `validation_report.json` 使用（Phase 3）

---

### Phase 3 — JSON Writer Dry-run

#### 目標

模擬完整 **tmp → Validation → 正式 JSON** 流程，但 **只寫本機**。

#### 做什麼

| 項目 | 說明 |
|------|------|
| **本機輸出** | `preview/output/tenants/{sno}/knowledge/` 下 5 JSON |
| **tmp 流程** | 模擬 `BATS_DATA_SYNC_POLICY.md` §14.1 Safety Rule |
| **報告** | 產生 `sync_report.json`、`validation_report.json` |
| **錯誤報告** | 有錯誤時產生 `error_report.json` |
| **dry-run flag** | 預設 `dry_run=true`；明確標記非 production |

#### 不做什麼

| 禁止 | 說明 |
|------|------|
| 寫 GCS | 零雲端寫入 |
| 寫 Host A production cache | 不碰 |
| 覆蓋本機既有 production mirror（若有） | dry-run 目錄隔離 |

#### 建議本機目錄結構

```text
preview/
├── output/
│   └── tenants/{sno}/knowledge/
│       ├── company_profile.json
│       ├── service_qa.json
│       ├── external_product_links.json
│       ├── service_items.json
│       └── special_prices.json
└── reports/
    ├── sync_report.json
    ├── validation_report.json
    └── error_report.json          （如有錯誤）
```

#### 退出準則

- [ ] 驗證通過 → 本機 5 JSON + 報告齊全
- [ ] 驗證失敗 → 無 5 JSON promote；`error_report.json` 有內容
- [ ] 報告含 `sno`、`data_category`、`phase`、`dry_run`、`timestamp`

---

### Phase 4 — Google Sheet Reader

**狀態：Completed** ✅（2026-06-09 Close-out）

#### 目標

以 **唯讀** 方式連接 Google Sheet，取代 mock 輸入。

#### 做什麼

| 項目 | 說明 |
|------|------|
| **讀取來源** | `private_knowledge_sheet_id`（從 Registry 載入） |
| **讀取範圍** | 5 Required Tabs |
| **輸出** | 解析後結構 → 交給 Phase 2 Validator |
| **本機驗證** | 可輸出至 Phase 3 dry-run 路徑 |
| **憑證** | Service Account / OAuth 依環境設定（`.env`） |

#### 不做什麼

| 禁止 | 說明 |
|------|------|
| 寫入 Google Sheet | 唯讀 |
| 寫 GCS | 本 Phase 仍不寫雲端 |
| hardcode Sheet ID | 必須 Registry driven |
| 讀取 Shared Layer Sheet | v1 不處理 |

#### 交付物

- Sheet Reader 模組 — `core/bds/BdsGoogleSheetReader.php` ✅
- 連線與讀取測試 — `tests/bds/test_bds_google_sheet_reader.php` ✅
- Pipeline Dry-run 整合測試 — `tests/bds/test_bds_google_sheet_pipeline_dry_run.php` ✅
- Registry 整合（讀 `private_knowledge_sheet_id`）— **Phase 6** 手動同步命令一併交付；Phase 4 以環境變數／測試參數驗證

#### 驗證紀錄（Close-out）

| 驗證項 | 結果 | 說明 |
|--------|------|------|
| **Phase 4 — Google Sheet Reader** | **PASS** ✅ | Service Account + Sheets API 唯讀；5 Required Tabs |
| **Phase 4.1 — Live Read** | **PASS** ✅ | Pilot `travel_b` private knowledge sheet；5 tabs 可讀 |
| **Phase 4.2 — Pipeline Dry-run** | **PASS** ✅ | Reader → Parser → Validator → JsonWriter；本機 5 JSON preview |

```text
Google Sheet
    ↓
BdsGoogleSheetReader（Phase 4）
    ↓
BdsMockSheetParser → BdsValidator → BdsJsonWriter dry-run（Phase 1～3）
    ↓
tests/bds/output/tenants/{sno}/knowledge/*.json（本機 preview；非 GCS）
```

#### 退出準則

- [x] 從指定 Sheet 讀取 5 tabs 成功（Phase 4.1 Live Read）
- [x] 讀取結果經 Validator 可產出 5 JSON preview（Phase 4.2 Pipeline Dry-run）
- [x] 讀取失敗有明確錯誤分類（Validator `BDC_*`；Safety Rule 不 promote JSON）

#### Phase 4 明確不做（已遵守）

| 不做 | 狀態 |
|------|------|
| GCS 寫入 | ✅ 未實作 |
| Google Drive API | ✅ 未實作 |
| Shared Layer Sheet | ✅ 未處理 |
| `private_drive_folder_id` | ✅ 未引入（延後 Phase 6） |

---

### Phase 5 — GCS Writer Controlled Mode

**狀態：Planned**（文件已就緒；實作待 Phase 5 啟動）

#### 目標

在 **受控條件** 下，將 **驗證通過** 之 `tenant_private_knowledge` JSON 寫入 GCS Knowledge Layer。

#### 端到端管線（Phase 5 終態）

```text
Google Sheet（private_knowledge_sheet_id）
        ↓
BdsGoogleSheetReader（Phase 4）
        ↓
BdsMockSheetParser
        ↓
BdsValidator
        ↓
JSON（5 檔）
        ↓
GCS Writer Controlled Mode（Phase 5）
        ↓
gs://{bucket}/tenants/{sno}/knowledge/
```

#### Phase 5 範圍（In Scope）

| 項目 | 說明 |
|------|------|
| **data_category** | `tenant_private_knowledge` only |
| **GCS 路徑** | `tenants/{sno}/knowledge/` **only** |
| **輸出 JSON** | `company_profile.json`、`service_qa.json`、`external_product_links.json`、`service_items.json`、`special_prices.json` |
| **前置條件** | Phase 4 Reader + Validator **PASS** |
| **雙重門禁** | `BDS_DRY_RUN` + `BDS_GCS_WRITE_ENABLED` + `BDS_TARGET_SNO` |
| **Safety Rule** | Validation Fail → **不寫 GCS**；不覆蓋既有正式 JSON |

#### Phase 5 不做（Out of Scope）

| 排除 | 說明 |
|------|------|
| **Google Drive** | 非 Phase 5；見 Phase 6 Drive Connector Framework（`BATS_DATA_SOURCE_REGISTRY.md` §10.5） |
| **Shared Layer** | 禁止寫入 `shared/` |
| **PDF / Image** | 非結構化；Drive Connector 範疇 |
| **RAG / Vector DB** | 未納入 |
| **`private_drive_folder_id`** | **未引入**；延後 Phase 6 前 Registry 規劃 |
| **Cron / 排程** | v1 手動觸發（Phase 6 Manual Sync） |
| **LINE OA** | 非 BDS 同步範圍 |
| **Delete GCS Object** | Phase 5 僅 promote；禁止刪除物件作為同步策略 |

#### 做什麼

| 項目 | 說明 |
|------|------|
| **寫入路徑** | `tenants/{sno}/knowledge/` 下 5 JSON |
| **單一 tenant** | 先支援 **controlled test**（單一 `sno`） |
| **feature flag** | 須 `BDS_GCS_WRITE_ENABLED=true` 才允許寫入 |
| **dry-run gate** | 預設 `BDS_DRY_RUN=true`；雙重門禁 |
| **Safety Rule** | tmp → Validation → promote；Fail 不覆蓋 |
| **meta** | 更新 `tenants/{sno}/meta/sync_status.json`（可選 Phase 5 末期） |

#### 不做什麼

| 禁止 | 說明 |
|------|------|
| 未開 flag 寫 GCS | 預設拒絕 |
| 覆蓋 production JSON（驗證未通過） | Safety Rule |
| 寫入 `shared/` | v1 禁止 |
| 啟用 Object Versioning | 不啟用 |
| 多租戶批次寫入 | v1 僅 controlled single-tenant |

#### 建議環境變數（概念）

| 變數 | 預設 | 說明 |
|------|------|------|
| `BDS_DRY_RUN` | `true` | `true` 時絕不寫 GCS |
| `BDS_GCS_WRITE_ENABLED` | `false` | 須明確啟用 |
| `BDS_TARGET_SNO` | （空） | controlled test 目標；空則拒絕寫入 |

#### 退出準則

- [ ] `dry_run=true` → 零 GCS 寫入
- [ ] `dry_run=false` + flag 啟用 + validation pass → 5 JSON 寫入成功
- [ ] validation fail → GCS 既有 JSON 不變
- [ ] 寫入前後可從 report 比對 checksum / revision

---

### Phase 6 — Manual Sync Command

#### 目標

提供 **手動執行** 之完整同步命令，串接 Phase 4～5，產出完整報告。

#### 做什麼

| 項目 | 說明 |
|------|------|
| **觸發方式** | CLI 命令（如 `php bin/bds-sync.php --sno=...`）；**不做 cron** |
| **管線** | Registry → Sheet Reader → Validator → GCS Writer |
| **報告** | `sync_report.json`、`validation_report.json`、`error_report.json` |
| **rollback** | 保留上一版 JSON 參照；失敗自動不覆蓋；可选手動還原程序 |
| **日誌** | 可觀測；對齊 `BATS_DATA_SYNC_POLICY.md` §14.3 |

#### 不做什麼

| 禁止 | 說明 |
|------|------|
| Cron / 排程 | v1 不實作 |
| 上傳頁自動觸發 | 可列 Phase 6+ 整合；v1 以手動為主 |
| 全租戶 sync | 單次命令僅處理一個 `sno` |

#### 建議命令介面（概念）

```bash
# dry-run（預設）
php bin/bds-sync.php --sno=5f99b8d665e8444d

# 受控寫入 GCS
php bin/bds-sync.php --sno=5f99b8d665e8444d --dry-run=false --write-gcs
```

#### Rollback 概念

| 情境 | 行為 |
|------|------|
| Validation Fail | 自動保留 GCS 舊版（Safety Rule） |
| GCS 寫入後發現問題 | 手動從備份 / 上一版 `source_revision` 還原；v1 不依賴 Object Versioning |
| 需要還原 | 維運依 `meta/source_revision.json` 與報告執行手動 rollback |

#### 退出準則

- [ ] 手動命令可完成 end-to-end sync（controlled tenant）
- [ ] 成功 / 失敗皆有完整 report
- [ ] 失敗不覆蓋 production JSON
- [ ] 文件化操作手冊（維運可重現）

---

## 4. Safety Rules

下列規則 **全程適用**；任何 Phase 不得違反。

### 4.1 Anti Hardcode

| 規則 | 說明 |
|------|------|
| **不 hardcode `travel_b`** | `sno`、`tenant_key` 從 Registry 或 CLI 參數載入 |
| **不 hardcode Sheet ID** | 使用 `private_knowledge_sheet_id` |
| **不 hardcode GCS 路徑** | 使用 Registry `gcs_prefix` + Contract 檔名 |
| **Pilot ≠ 架構特例** | `travel_b` 僅測試用例，不得 fork 管線 |

### 4.2 Registry Driven

| 規則 | 說明 |
|------|------|
| **tenant 從 Registry 讀** | 無 Registry entry → 拒絕同步 |
| **`industry_code` 只可新增** | 新產業僅新增代碼與路徑；不改核心流程（`BATS_DATA_OWNERSHIP_POLICY.md` §6） |
| **Registry 不定義欄位** | 欄位格式見 `BATS_DATA_CONTRACT.md` |

### 4.3 Validation & Fail-Safe

| 規則 | 說明 |
|------|------|
| **整檔 Fail** | 任一 Required Tab / Field 失敗 → 本次同步 Fail |
| **validation fail 不覆蓋** | 禁止覆蓋 `tenants/{sno}/knowledge/` 既有 JSON |
| **保留舊 JSON** | Fail 時 GCS 維持上一版有效資料 |
| **tmp → validate → promote** | 禁止先寫正式路徑再驗證 |
| **英文欄位 only** | 不接受中文欄位別名（v1） |

### 4.4 Infrastructure Guardrails

| 規則 | 說明 |
|------|------|
| **不啟用 Object Versioning** | 不以 GCS versioning 取代 Safety Rule |
| **不做自動排程** | 無 Cron；僅手動命令 |
| **feature flag / dry-run gate** | Phase 5 起須雙重門禁才可寫 GCS |
| **單 tenant controlled test** | v1 寫 GCS 先限單一 `sno` |
| **不寫 `shared/`** | Shared Layer 同步留待未來版本 |

### 4.5 Ownership 對齊

| 規則 | 說明 |
|------|------|
| **僅 Tenant Layer 寫入** | `tenants/{sno}/knowledge/` only |
| **禁止跨層寫入** | 見 `BATS_DATA_OWNERSHIP_POLICY.md` §3 |
| **禁止跨 tenant 寫入** | 見 `BATS_DATA_SYNC_POLICY.md` §10 |

---

## 5. Required Outputs

每次 sync（含 dry-run）**至少** 產出下列項目。

### 5.1 成功時

| 輸出 | 路徑（概念） | 說明 |
|------|--------------|------|
| **5 JSON files** | `tenants/{sno}/knowledge/*.json` | 正式或 preview 路徑 |
| **`sync_report.json`** | `meta/` 或 `reports/` | 同步摘要 |
| **`validation_report.json`** | `meta/` 或 `reports/` | 驗證結果 |

### 5.2 失敗時

| 輸出 | 說明 |
|------|------|
| **`error_report.json`** | 錯誤碼、欄位、Tab、訊息 |
| **不產出 5 JSON promote** | 或僅留 tmp / preview 供除錯 |
| **保留舊 JSON** | GCS 正式路徑不變 |

### 5.3 `sync_report.json` 建議欄位

| 欄位 | 說明 |
|------|------|
| `sync_id` | 唯一同步識別 |
| `tenant_sno` | 租戶 sno |
| `data_category` | `tenant_private_knowledge` |
| `status` | `success` / `failed` / `dry_run` |
| `started_at` / `finished_at` | ISO 8601 |
| `source_sheet_id` | `private_knowledge_sheet_id` |
| `output_files` | 5 JSON 檔名清單 |
| `dry_run` | boolean |
| `phase` | 實作 Phase 標記（除錯用） |

### 5.4 `validation_report.json` 建議欄位

| 欄位 | 說明 |
|------|------|
| `passed` | boolean |
| `tabs_checked` | 5 tabs 狀態 |
| `field_errors` | 缺欄位 / 型別錯誤清單 |
| `error_codes` | 對齊 `BDC_*` 概念碼 |

### 5.5 `error_report.json` 建議欄位

| 欄位 | 說明 |
|------|------|
| `error_level` | E1～E5 概念分類 |
| `error_code` | 如 `BDC_REQUIRED_FIELD_MISSING` |
| `message` | 人類可讀訊息 |
| `tab` / `field` / `row` | 定位資訊（如有） |

---

## 6. Commit Strategy

### 6.1 建議分批 Commit

| Commit | 範圍 | 說明 |
|--------|------|------|
| **Commit A** | Phase 1～3 | Mock Parser + Validator + Dry-run Writer + 測試 + preview 目錄 |
| **Commit B** | Phase 4 | Google Sheet Reader + Registry 整合 |
| **Commit C** | Phase 5 | GCS Writer Controlled Mode + feature flag |
| **Commit D** | Phase 6 | Manual Sync Command + 報告 + 維運文件 |

### 6.2 本規劃文件 Commit

| 項目 | 說明 |
|------|------|
| **時機** | 本文件完成後 **可先 commit** |
| **建議訊息** | `docs(bds): 建立 BDS v1 Implementation Plan` |
| **白名單** | 僅 `docs/BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` |

### 6.3 目前分支狀態與 Push 評估

| 項目 | 值 |
|------|-----|
| **分支** | `feature/api-gateway-mvp` |
| **目前 ahead** | 3 commits（BDS SSOT 文件） |
| **本文件 commit 後** | ahead 4 |
| **push 評估** | 文件齊備後可評估 push；**本階段不 push** |

### 6.4 Commit 原則

| 原則 | 說明 |
|------|------|
| **白名單 stage** | 每 commit 僅含該 Phase 相關檔案 |
| **不混入 unrelated** | 不帶 `travel_b_search_registry.php` 等 |
| **測試與程式同 commit** | Phase 1～3 測試隨程式一起提交 |
| **文件先行** | 本 Implementation Plan 先於 Phase 1 程式 commit |

---

## 7. Cross References

### 7.1 BDS 文件體系

```text
L1 SSOT（政策）
├── BATS_DATA_SYNC_POLICY.md        同步原則、Mode B、Safety Rule
├── BATS_DATA_SOURCE_REGISTRY.md    Registry、industry_code、Resolution Order
├── BATS_DATA_CONTRACT.md           5 Tab / 5 JSON 欄位契約
└── BATS_DATA_OWNERSHIP_POLICY.md   歸屬、寫入邊界、Override

L2 規劃（本文件）
└── BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md   分階段實作、交付順序

L3 實作（未來）
└── core/bds/、bin/bds-sync.php 等
```

### 7.2 引用對照

| 文件 | 本文件引用之內容 |
|------|------------------|
| `BATS_DATA_SYNC_POLICY.md` | Mode B、§14 Safety Rule、§15 MVP、§23 Anti Hardcode |
| `BATS_DATA_SOURCE_REGISTRY.md` | `private_knowledge_sheet_id`、Registry driven、§7 Tenant Contract |
| `BATS_DATA_CONTRACT.md` | 5 Tab / 5 JSON、§1.4 MVP 決策、§8 Validation |
| `BATS_DATA_OWNERSHIP_POLICY.md` | §3 Write Boundary、§5 Sync Ownership、v1 不寫 shared |

### 7.3 相關文件

| 文件 | 關係 |
|------|------|
| `TENANT_SOURCE_REGISTRY_POLICY.md` | 商品源 Registry；與 BDS **互補** |
| `TENANT_SOURCE_RUNTIME_BRIDGE_V1.md` | BATS 消費層；BDS 完成後接入 |
| `DOCUMENTATION_GOVERNANCE_POLICY.md` | 本文件為 BDS 實作規劃 L2 文件 |

### 7.4 衝突處理

| 議題 | SSOT |
|------|------|
| 實作 Phase 順序與交付 | **本文件** |
| 欄位與 JSON 格式 | `BATS_DATA_CONTRACT.md` |
| 同步原則與 Safety Rule | `BATS_DATA_SYNC_POLICY.md` |
| 誰可寫哪一層 | `BATS_DATA_OWNERSHIP_POLICY.md` |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.2** | 2026-06-09 | Phase 5 GCS Writer Controlled Mode 文件補強；Phase 6 Drive Framework cross-ref |
| **v1.1** | 2026-06-09 | Phase 4 Close-out：Reader / Live Read / Pipeline Dry-run 標記 Completed + 驗證紀錄 |
| **v1.0** | 2026-06-08 | 第一版：BDS v1 六 Phase 實作計畫、Safety Rules、Required Outputs、Commit Strategy |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | Draft — 待審核 |
| **實作狀態** | Phase 1～4 **Completed**；Phase 5 **Planned**（文件已就緒） |
| **前置 SSOT** | Sync / Registry / Contract / Ownership 已 commit |
| **Pilot** | `travel_b`（`5f99b8d665e8444d`） |
