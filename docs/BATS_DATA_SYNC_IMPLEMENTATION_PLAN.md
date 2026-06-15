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
| **5** | GCS Writer Controlled Mode | Google API + GCS | 是（受控） | **Completed** ✅ |
| **6A** | Manual Sync Command | Sheet 完整管線 | 是（手動觸發） | 規劃中（SSOT 已定義） |
| **6B** | Drive Connector Read-only | Drive API 唯讀 | 否 | 規劃中 |
| **6C** | Metadata Contract & File Classification | Drive + Metadata | 否 | 規劃中 |
| **6D** | Drive → GCS Controlled Promote | Drive + GCS Archive | 是（受控） | 規劃中 |
| **6E** | Verification & Close-out | Shared + read-back | 否（驗證） | 規劃中 |

> **Phase 6 正名：** 不再混用「Manual Sync」與「Drive Connector」雙重語意。詳見 §3.1。

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
                    Phase 6A（Sheet E2E）
                              │
                              ↓
              Phase 6B → 6C → 6D → 6E（Drive Connector）
```

### 3.1 Phase 6A～6E Roadmap（正式正名）

| 子階段 | 正式名稱 | SSOT |
|--------|----------|------|
| **6A** | Manual Sync Command | 本文件 **§Phase 6A SSOT** |
| **6B** | Drive Connector Read-only | `BATS_DRIVE_CONNECTOR_SCOPE.md` §7 |
| **6C** | Metadata Contract & File Classification | `BATS_DRIVE_METADATA_CONTRACT.md` |
| **6D** | Drive → GCS Controlled Promote | `BATS_DRIVE_GCS_MAPPING.md` |
| **6E** | Verification & Close-out | `BATS_DATA_SYNC_TEST_PLAN.md` §2.1 |

**P1 Drive SSOT 文件：**

| 文件 | 職責 |
|------|------|
| `BATS_DRIVE_CONNECTOR_SCOPE.md` | Runtime Source Architecture、Connector 範圍 |
| `BATS_DRIVE_METADATA_CONTRACT.md` | Metadata Contract |
| `BATS_DRIVE_GCS_MAPPING.md` | Drive → GCS、三層分離 |
| `BATS_TENANT_DRIVE_ONBOARDING_POLICY.md` | Tenant Onboarding |

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

**狀態：Completed** ✅（Phase 5A～5D Close-out；Pilot `travel_b` 已寫入 GCS）

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

#### 子階段（Phase 5A～5D）

| 子階段 | 內容 | 狀態 |
|--------|------|------|
| **5A** | Controlled Preview（`BdsGcsWriter` Upload Plan / Payload Preview） | **PASS** ✅ |
| **5B** | GCS Preflight Gate（SA / Bucket / `objects.list`） | **PASS** ✅ |
| **5C** | Controlled Real Write（`BdsGcsUploader` → `bbc-ai-saas-data`） | **PASS** ✅ |
| **5D** | Read-back Verification & Close-out | **PASS** ✅ |

#### 交付物

| 模組 / 測試 | 路徑 |
|-------------|------|
| GCS Upload Plan Writer | `core/bds/BdsGcsWriter.php` |
| Knowledge Document Builder | `core/bds/BdsKnowledgeDocumentBuilder.php` |
| GCS Uploader（受控寫入） | `core/bds/BdsGcsUploader.php` |
| Phase 5A 測試 | `tests/bds/test_bds_gcs_writer.php` |
| Phase 5C 測試 | `tests/bds/test_bds_gcs_real_write.php` |
| Phase 5D 測試 | `tests/bds/test_bds_gcs_readback_verification.php` |

#### 驗證紀錄（Close-out）

| 驗證項 | 結果 | 說明 |
|--------|------|------|
| **Phase 5A — Controlled Preview** | **PASS** ✅ | Upload Plan + Payload Preview；零 GCS 寫入 |
| **Phase 5B — GCS Preflight** | **PASS** ✅ | SA token OK；`objects.list` HTTP 200 |
| **Phase 5C — Controlled Real Write** | **PASS** ✅ | 5 JSON 寫入 `gs://bbc-ai-saas-data/tenants/5f99b8d665e8444d/knowledge/` |
| **Phase 5D — Read-back Verification** | **PASS** ✅ | `objects.list` 5 物件；逐一 read-back + Schema 通過 |

**GCS 正式路徑（Pilot）：**

```text
gs://bbc-ai-saas-data/tenants/5f99b8d665e8444d/knowledge/
├── company_profile.json
├── service_qa.json
├── external_product_links.json
├── service_items.json
└── special_prices.json
```

> **資料備註：** `service_items.json` 之 `items[]` 目前為空（來源 Sheet 無列）；JSON 結構與 GCS 讀寫管線已驗證正確，待租戶補齊 Sheet 後可重跑同步。

#### 退出準則

- [x] `dry_run=true` → 零 GCS 寫入（Phase 5A）
- [x] `dry_run=false` + flag 啟用 + validation pass → 5 JSON 寫入成功（Phase 5C）
- [x] validation fail → GCS 既有 JSON 不變（Safety Rule；Phase 5A / 5C 測試）
- [x] 寫入後 read-back 可驗證（Phase 5D；`phase5_closeout_report.json`）

#### Phase 5 明確不做（已遵守）

| 不做 | 狀態 |
|------|------|
| Google Drive API | ✅ 未實作 |
| `shared/` 寫入 | ✅ 未寫入 |
| `private_drive_folder_id` Registry 欄位 | ✅ 未引入 |
| GCS delete / bucket purge | ✅ 未實作 |
| 多租戶批次寫入 | ✅ 僅 pilot `5f99b8d665e8444d` |

#### Phase 6 Pre-Governance — Google Drive Platform Layer Architecture

Phase 6 Drive Connector **尚未啟動**；下列為 **Phase 6 Pre-Governance** 正式文件約束，**不得提前實作於 Registry Schema**。

**SSOT：** `BATS_DATA_SOURCE_REGISTRY.md` §6.5、`BATS_DATA_OWNERSHIP_POLICY.md` §2.7

```text
bbcshops88@gmail.com（Google Drive）
├── tenants/{tenant_key}/01_Private_Layer/    ← 單一租戶 Private
├── shared/{industry}/02_Shared_Layer/        ← 產業層（非 Tenant）
├── shared/global/02_Global_Shared_Layer/     ← 平台層
└── registrations/                            ← Category C 平台層
```

| 原則 | 說明 |
|------|------|
| **一旅行社一專屬 Folder** | `travel_a`、`travel_b`、`travel_c` 各自 `tenants/{tenant_key}/01_Private_Layer/` |
| **Shared 不屬於單一旅行社** | 禁止 `tenants/{tenant_key}/02_Shared_Layer/` |
| **營運帳號** | `bbcshops88@gmail.com` Google Drive |
| **Default Private** | 所有 Drive 資料夾預設 Private |
| **Tenant 不自動使用 Shared** | 須 Registry + Policy 明確啟用 |
| **產業可擴展** | `travel` 首個；`hotel`、`restaurant`、`beauty`、`education`、`medical` 等同架構 |
| **禁止提前新增** | **不得** 新增 `private_drive_folder_id` 至 Registry JSON Schema |
| **GCS / Contract 不變** | 不修改 `BATS_DATA_CONTRACT.md`、GCS 路徑 |
| **Runtime Source** | Google Drive = Archive Source of Truth；GCS = Runtime Knowledge Source；見 `BATS_DRIVE_CONNECTOR_SCOPE.md` §2 |

---

### Phase 6A — Manual Sync Command（SSOT）

> **本節為 Phase 6A 正式 SSOT 與實作規劃。** 實作 `bin/bds-sync.php` 前須與本節對齊。  
> **不含** Google Drive Connector；Drive 相關見 Phase 6B～6E 與 `BATS_DRIVE_*` 文件。

#### 6A.1 定位

| 項目 | 說明 |
|------|------|
| **正式名稱** | Phase 6A — Manual Sync Command |
| **目的** | 將 Phase 1～5 已完成元件整合為 **單一 CLI 手動同步流程** |
| **觸發** | 維運手動執行；**無** Cron、**無** Auto Sync |
| **輸入** | Google Sheet（Structured Knowledge Input）；Registry 驅動 |
| **輸出** | GCS `tenants/{sno}/knowledge/` 五 JSON + 三種 report |
| **SSOT 對齊** | Structured Knowledge = Google Sheet（§18.7）；**6A 僅 Tenant Private** |

**整合元件（已存在，Phase 6A 串接）：**

| 元件 | 來源 Phase | 類別（概念） |
|------|------------|--------------|
| Google Sheet Reader | Phase 4 | `BdsGoogleSheetReader` |
| Parser | Phase 1 | `BdsMockSheetParser`（或等效 Parser） |
| Validator | Phase 2 | `BdsValidator` |
| Knowledge Builder | Phase 5C | `BdsKnowledgeDocumentBuilder` |
| GCS Uploader | Phase 5C | `BdsGcsUploader` |
| Read-back Verification | Phase 5D | read-back 驗證步驟 |

#### 6A.2 Out of Scope（Phase 6A 不處理）

| 排除 | 說明 |
|------|------|
| Google Drive Connector | Phase 6B～6E |
| PDF / Image / OCR | Unstructured；Drive 範疇 |
| Metadata Promote | Phase 6C～6D |
| Shared Layer / Industry Shared / Global Shared **同步** | 未來 Sheet Pipeline A2/A3；**非** 6A 範圍 |
| RAG / Vector / Embedding / AI Ranking / Recommendation | 未來；僅從 GCS |
| Cron / Auto Sync | v1 手動 only |
| Registry Schema 修訂 | **不新增** `private_drive_folder_id` |

**Cross Reference（6A 不讀取、不修改）：** `BATS_DRIVE_CONNECTOR_SCOPE.md`、`BATS_DRIVE_METADATA_CONTRACT.md`、`BATS_DRIVE_GCS_MAPPING.md`

#### 6A.3 正式管線

```text
Tenant Registry
        ↓
private_knowledge_sheet_id（Registry 載入）
        ↓
Google Sheet Reader
        ↓
Parser
        ↓
Validator
        ↓
Knowledge Builder
        ↓
GCS Uploader（受控；雙重門禁）
        ↓
Read-back Verification
        ↓
sync_report.json
validation_report.json
error_report.json（如有錯誤）
```

| 步驟 | Fail 行為 |
|------|-----------|
| Registry 載入失敗 | 拒絕執行；產出 `error_report.json` |
| Sheet Read 失敗 | 中止；不進 Validation |
| Validation Fail | **不寫入 GCS**；保留舊 JSON |
| GCS Upload 失敗 | 同步失敗；不更新 success meta |
| Read-back Fail | **同步失敗**（即使 upload 回傳成功） |

#### 6A.4 CLI Command 規劃

**入口：** `bin/bds-sync.php`（**規劃中**；本階段僅文件，不建立檔案）

**支援參數：**

| 參數 | 說明 | 範例 |
|------|------|------|
| `--sno={tenant_sno}` | 租戶權威邊界 | `--sno=5f99b8d665e8444d` |
| `--tenant={tenant_key}` | 人類可讀代碼；由 Registry 解析 `sno` | `--tenant=travel_b` |
| `--dry-run` | 預設 `true`；不寫 GCS | `--dry-run=false` |
| `--write-gcs` | 明確請求 GCS 寫入（須配合門禁） | `--write-gcs` |

**參數優先順序與衝突處理：**

| 情境 | 行為 |
|------|------|
| **僅 `--sno`** | 以 `sno` 載入 Registry entry |
| **僅 `--tenant`** | 以 `tenant_key`（Registry `tenant_name`）查詢對應 `sno` |
| **同時 `--sno` 與 `--tenant`** | 兩者須對應 **同一** Registry entry；不一致 → **拒絕執行** |
| **皆未提供** | **拒絕執行**；exit 非 0；`error_report` 標記 `BDS_MISSING_TENANT_PARAM`（概念） |
| **`--write-gcs` 但 dry-run** | 須 `dry-run=false` **且** 環境變數雙重門禁 |

**缺參數行為：** 無 `--sno` 且無 `--tenant` → 不啟動管線；輸出用法說明至 stderr。

**Validation Fail 行為：** 不呼叫 GCS Uploader；`validation_report.json` → `passed=false`；`sync_report.json` → `status=failed`；GCS 既有 JSON **不變**。

**建議命令（Pilot）：**

```bash
# dry-run（預設）
php bin/bds-sync.php --tenant=travel_b
php bin/bds-sync.php --sno=5f99b8d665e8444d

# 受控寫入 GCS（須 dry-run 已 PASS）
php bin/bds-sync.php --sno=5f99b8d665e8444d --dry-run=false --write-gcs
```

#### 6A.5 Registry Integration

| 欄位 | Phase 6A 是否讀取 | 說明 |
|------|-------------------|------|
| **`sno`** | ✅ | 權威邊界；GCS prefix |
| **`tenant_name` / `tenant_key`** | ✅（若 `--tenant`） | 對照 `sno` |
| **`private_knowledge_sheet_id`** | ✅ **必填** | Sheet Reader 輸入 |
| **`gcs_prefix`** | ✅ | 正式值 `tenants/{sno}/` |
| **`enabled`** | ✅ 建議 | `false` → 拒絕同步 |
| **`industry_code`** | 載入但不寫 Shared | v1 不同步 `shared/` |
| **Drive 相關 ID** | ❌ **不讀取** | Phase 6B+ |
| **Shared Layer** | ❌ **不讀取** | Phase 6E |
| **Industry / Global Shared** | ❌ **不讀取** | Phase 6E |

> **Registry JSON Schema 不變**；**不新增** `private_drive_folder_id`。

#### 6A.6 Input / Output Contract

**Input：**

| 項目 | SSOT |
|------|------|
| **載體** | Google Sheet |
| **Sheet ID** | Registry `private_knowledge_sheet_id` |
| **結構** | 五個標準 Required Tabs | `BATS_DATA_CONTRACT.md` |

| Required Tab | 說明 |
|--------------|------|
| `company_profile` | 公司簡介 |
| `qa` | 服務問答 |
| `external_product_links` | 外部商品連結 |
| `service_items` | 服務項目 |
| `special_prices` | 特價 |

**Output：**

| 項目 | 路徑／檔名 |
|------|------------|
| **GCS 目錄** | `tenants/{sno}/knowledge/` |
| `company_profile.json` | Knowledge JSON |
| `service_qa.json` | Knowledge JSON |
| `external_product_links.json` | Knowledge JSON |
| `service_items.json` | Knowledge JSON |
| `special_prices.json` | Knowledge JSON |
| **Reports** | `sync_report.json`、`validation_report.json`、`error_report.json`（可選） |

> **路徑不變** — 與 Phase 5 已完成 GCS Knowledge 路徑一致。

#### 6A.7 Phase 6A Safety Boundary

| 規則 | 說明 |
|------|------|
| **Validation Fail** | **不寫入 GCS** |
| **Read-back Fail** | **同步失敗**；須人工排查 |
| **雙重門禁** | `BDS_DRY_RUN=false` + `BDS_GCS_WRITE_ENABLED=true` 才可寫 GCS |
| **單 tenant** | 單次命令僅處理一個 `sno` |
| **Anti Hardcode** | 禁止寫死 `travel_b`；Pilot 僅驗收用例 |

**禁止：**

| 禁止 | 說明 |
|------|------|
| 寫入 `shared/` | Shared Layer 非 6A 範圍 |
| Global Shared | Phase 6E |
| Google Drive API | Phase 6B+ |
| Delete GCS Object | 同步策略不得刪除 |
| Delete Drive Folder | 同步策略不得刪除 |
| 覆蓋 Structured JSON 於 Validation Fail | Safety Rule |
| 修改 Runtime Source Rule | Drive = Archive；GCS = Runtime（不變） |

#### 6A.8 Pilot Scope

| 項目 | 值 |
|------|-----|
| **Pilot Tenant** | `travel_b` |
| **tenant_sno** | `5f99b8d665e8444d` |
| **驗收基準** | Phase 6A **正式驗收以 `travel_b` 為準** |
| **架構** | Pilot ≠ 特例；程式須 Registry 驅動 |

#### 6A.9 Acceptance Criteria（退出準則）

Phase 6A **PASS** 須同時滿足：

```text
travel_b
    ↓
Google Sheet（private_knowledge_sheet_id）
    ↓
5 個 Knowledge JSON（Validation Pass）
    ↓
GCS tenants/{sno}/knowledge/
    ↓
Read-back PASS（5 物件）
    ↓
sync_report PASS（status=success）
```

| # | 條件 |
|---|------|
| 1 | `bin/bds-sync.php` 可手動觸發完整管線 |
| 2 | Registry 載入 `private_knowledge_sheet_id` |
| 3 | 5 JSON 寫入 `tenants/{sno}/knowledge/` |
| 4 | Read-back Verification PASS |
| 5 | `sync_report.json`、`validation_report.json` 產出正確 |
| 6 | Validation Fail 不覆蓋 GCS |
| 7 | Test Matrix 6A-01～6A-08 全 PASS（見 Test Plan） |
| 8 | Runbook §13 操作可重現 |

#### 6A.10 Rollback 概念

| 情境 | 行為 |
|------|------|
| Validation Fail | 自動保留 GCS 舊版（Safety Rule） |
| Read-back Fail | 視為失敗；維運檢查 GCS 與 report |
| GCS 寫入後發現問題 | 手動從備份 / `source_revision` 還原；v1 不依賴 Object Versioning |

---

### Phase 6B — Drive Connector Read-only

#### 目標

Drive API **唯讀** preflight；驗證 SA 可 list `tenants/{tenant_key}/01_Private_Layer/`。

#### 退出準則

- [ ] Drive list / metadata 唯讀 PASS（pilot tenant）
- [ ] 零 GCS 寫入；零 Knowledge 覆蓋

---

### Phase 6C — Metadata Contract & File Classification

#### 目標

產出符合 `BATS_DRIVE_METADATA_CONTRACT.md` 之 Metadata envelope；`data_category` 分類。

#### 退出準則

- [ ] P1 必填欄位語意齊全（`tenant_sno`、`file_id`、`checksum` 等）
- [ ] Metadata ≠ Knowledge 邊界驗證通過

---

### Phase 6D — Drive → GCS Controlled Promote

#### 目標

受控 promote Tenant Private Drive 檔案 → GCS Archive（見 `BATS_DRIVE_GCS_MAPPING.md`）。

#### 退出準則

- [ ] 雙重門禁 + Validation Gate
- [ ] 僅 `tenants/{sno}/archive/`（概念）；**不** 覆蓋 `knowledge/*.json`

---

### Phase 6E — Verification & Close-out

#### 目標

Shared 路徑讀取框架（須 Registry + Policy）；read-back；close-out report。

#### 退出準則

- [ ] Shared **未** Policy 啟用時零 promote
- [ ] Phase 6 close-out report 產出

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
| `sync_id` | 唯一同步識別；格式 `SYNC-YYYYMMDD-HHMMSS`（Upload Portal 顯示為「目前 AI 使用版本」；見 `BATS_DATA_CONTRACT.md` §1.6.5） |
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

L2 規劃（本文件體系）
├── BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md   分階段實作、交付順序（Phase 1～6、§8 Phase 7）
└── BATS_UPLOAD_PORTAL_PHASE7_MVP_PLAN.md   Phase 7-1 URL、Auth、Pilot、安全約束

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
| `BATS_DRIVE_CONNECTOR_SCOPE.md` | Phase 6B～6E、Runtime Source Architecture |
| `BATS_DRIVE_METADATA_CONTRACT.md` | Phase 6C Metadata Contract |
| `BATS_DRIVE_GCS_MAPPING.md` | Phase 6D Drive → GCS Mapping |
| `BATS_TENANT_DRIVE_ONBOARDING_POLICY.md` | Tenant Onboarding |
| `BATS_SHARED_KNOWLEDGE_SHEET_CONTRACT.md` | Shared Sheet Contract（P2-1） |
| `BATS_DATA_SOURCE_REGISTRY.md` §4.4 | P1-7 Knowledge Priority Rule |

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

## 8. Phase 7 — Upload Portal（7-1 / 7-2 / 7-3）

> **Status:** SSOT 定案（Phase 7-1c-1b）；7-1a／7-1b／7-1c-1 **Runtime Done**（legacy www）；**7-1c-2a 待實作**（bbc-ai-bot）。  
> **詳細規劃：** `BATS_UPLOAD_PORTAL_PHASE7_MVP_PLAN.md` §12（Sync Trigger）；本節為交付順序。

### 8.1 目標總覽

| Phase | 名稱 | Canonical URL | 寫入層級 |
|-------|------|---------------|----------|
| **7-1** | Tenant Upload Portal MVP | `kowanbo.com/bds/upload.php` | `tenants/{sno}/knowledge/` |
| **7-2** | Shared Upload Portal MVP | `kowanbo.com/bds/shared_upload.php` | `shared/travel/knowledge/` |
| **7-3** | Production Hardening | （沿用 7-1／7-2 URL） | Audit、recovery、runbook |

**共同：** Upload-Triggered Sync（Mode B）；Legacy `brandlogin.php` + `TourBus*` session；bbcshops redirect-only。

### 8.2 子階段

| 子階段 | 內容 | 狀態 |
|--------|------|------|
| **7-0b** | Login Reuse Audit（唯讀） | **Done** ✅ |
| **7-0c** | Canonical Domain SSOT（Tenant Portal） | **Done** ✅ |
| **7-0d** | Shared Upload Portal SSOT | **Done** ✅ |
| **7-0e** | Upload Portal UI／Field Spec SSOT | **Done** ✅ |
| **7-0e.1** | Upload Portal UI Enhancement（欄位順序、狀態、UX 文案） | **Done** ✅ |
| **7-0e.2** | Upload Portal UI Finalization（Sync Version、訊息定案、Metadata Priority） | **Done** ✅ |
| **7-1a** | `www/bds/upload.php` + travel_b gate + retUrl allowlist | **Done** ✅ |
| **7-1b** | Upload receive + staging（`var/bds/uploads/...`） | **Done** ✅ |
| **7-1c-1** | CLI Command Wrapper（dry-run build only） | **Done** ✅ |
| **7-1c-1b** | Upload CLI Input Contract SSOT | **Done** ✅ |
| **7-1c-2a** | bbc-ai-bot：`BdsUploadStagingResolver`、`BdsXlsxReader`、upload mode | **Next** |
| **7-1c-2b** | legacy www：CLI execution + `--upload-session-id` | 未開始 |
| **7-1c** | Upload → BDS Sync Trigger（端到端） | **進行中** |
| **7-1d** | `bbcshops.com/bds/upload.php` redirect-only（可選） | 未開始 |
| **7-2a** | `www/bds/shared_upload.php` + management center sno gate | 未開始 |
| **7-2b** | Shared UI（§13.3 欄位、§13.4 industry select）+ Shared Contract → `shared/travel/knowledge/` | 未開始 |
| **7-2c** | `bbcshops.com/bds/shared_upload.php` redirect-only（可選） | 未開始 |
| **7-3** | Audit、recovery、retUrl 強化、營運 runbook | 未開始 |
| **7-x** | Login Bridge Token（跨域） | **Deferred** |

### 8.3 Phase 7-1 退出準則（Tenant）

- [ ] 未登入 → `brandlogin.php?retUrl=/bds/upload.php` → 登入後回到 upload
- [ ] `TourBusstoreNo=6180` 可上傳；其他 storeNo 拒絕
- [ ] 上傳成功 → BDS → GCS `tenants/5f99b8d665e8444d/knowledge/` + Read-back
- [ ] Tenant upload **不得** 寫入 `shared/`
- [ ] 非法 `retUrl` 不造成 open redirect
- [ ] UI 欄位順序（Final）：旅行社名稱 → 上一次成功同步時間 → **目前 AI 使用版本** → 目前 AI 使用資料狀態 → Excel → 上傳並同步 → 同步結果（§13.2）
- [ ] **不** 顯示 `tenant_key`／`sno`／`gcs_prefix`／`bucket`（同步結果範圍可顯示 `tenant_key` 如 `travel_b`）
- [ ] 目前 AI 使用版本：格式 `SYNC-YYYYMMDD-HHMMSS`；來源 GCS `sync_report`／knowledge meta（§13.1.2）
- [ ] AI 資料狀態：僅 `已同步版本`／`仍為上一次成功同步版本`（§13.1.4）
- [ ] Upload Hint、同步中狀態（§13.1.5～§13.1.6）
- [ ] 成功訊息：同步範圍 `{tenant_key}` + 同步版本 `{sync_id}` + `AI 知識庫已更新。`（§13.1.7）
- [ ] 失敗訊息：統一模板含 `本次未更新 GCS，` 換行（§13.1.7）
- [ ] Metadata 來自 GCS／`var/bds/`（**非** Host B SQL）（§13.1.1）

### 8.4 Phase 7-2 退出準則（Shared）

- [ ] 未登入 → `brandlogin.php?retUrl=/bds/shared_upload.php`
- [ ] 僅 management center `sno` `cff796a33d94ea31` 可上傳；其他帳號拒絕
- [ ] MVP 僅 `industry_code=travel` → GCS `shared/travel/knowledge/` + Read-back
- [ ] Shared upload **不得** 寫入 `tenants/`
- [ ] **無** 密碼／手機密碼 hardcode gate
- [ ] UI 欄位順序（Final）：資料層級 → Industry Code → 上一次成功同步時間 → **目前 AI 使用版本** → 目前 AI 使用資料狀態 → Excel → 上傳並同步 → 同步結果（§13.3）
- [ ] Industry Code 六項：`travel` enabled；其餘 disabled（§13.4）
- [ ] 成功訊息：同步範圍 `shared/{industry_code}` + 同步版本 + `公有知識庫已更新。`（§13.1.7）
- [ ] Upload Hint、同步中、失敗模板、Metadata 來源同 7-1
- [ ] **不** 顯示 `owner_scope`／`bucket`／`gcs_prefix`／`folder_id`／policy flags

### 8.5 Phase 7-3（Hardening）

| 項目 | 說明 |
|------|------|
| Audit trail | 上傳者、層級、路徑、sync_id |
| Recovery | 對齊 `RECOVERY_AND_ROLLBACK_POLICY.md` |
| retUrl | Server-side allowlist 強化 |
| Global Shared | `shared/global/knowledge/` upload — **仍 Deferred** |

### 8.6 明確不做

| 不做 | 說明 |
|------|------|
| bbcshops 登入域 | §17.8 Sync Policy |
| Login Bridge Token | Phase 7-x Deferred |
| Global Shared upload（7-2 MVP） | Deferred；可 7-2+ 或 7-3 後 |
| Registry Schema 變更 | 沿用既有 `sno`／`tenant_key`／management center wire id |
| 密碼 in code/docs | §17.9／Ownership §3.7 |
| Host B SQL 新欄位／Schema／同步版本表 | §17.10.3、§17.10.6；使用 GCS meta／sync_report |

### 8.7 UI Field Spec（Phase 7-0e.2 Final 摘要）

| 項目 | SSOT |
|------|------|
| Tenant／Shared 最終欄位順序 | `BATS_UPLOAD_PORTAL_PHASE7_MVP_PLAN.md` §13.2～§13.3 |
| 目前 AI 使用版本 | `sync_id` = `SYNC-YYYYMMDD-HHMMSS`（§13.1.2、§13.6） |
| Metadata 來源優先序 | GCS sync_report → knowledge meta → `var/bds/` → Drive（輔助） |
| AI 資料狀態 | `已同步版本`／`仍為上一次成功同步版本` |
| 成功訊息 | Tenant：`{tenant_key}` + `{sync_id}`；Shared：`shared/{industry_code}` + `{sync_id}` |
| Industry 六項 | travel enabled；其餘 disabled；未來 Registry／Policy 控制 |

### 8.8 Phase 7-1c — Upload → BDS Sync Trigger（Phase 7-1c-0 SSOT）

> **Status: SSOT 定案（2026-06-05）** — L1：`BATS_DATA_SYNC_POLICY.md` §17.11；L2：`BATS_UPLOAD_PORTAL_PHASE7_MVP_PLAN.md` §12。

#### 8.8.1 架構決策（CLI Trigger MVP）

```text
upload.php（staging 完成）
    ↓
CLI Trigger（exec / proc_open）
    ↓
bin/bds-sync.php（Phase 6A）
    ↓
Validation → Build → GCS → Read-back → sync_report.json
```

| 項目 | 規格 |
|------|------|
| **輸入（upload mode）** | `--upload-session-id` + `--tenant`／`--sno` → `var/bds/uploads/tenants/{sno}/staging/{upload_session_id}/` |
| **輸入（sheet mode）** | 無 `--upload-session-id` → Registry → Google Sheet（Phase 6A legacy） |
| **Pilot sno** | `5f99b8d665e8444d`（`travel_b`） |
| **輸出** | `tenants/{sno}/knowledge/` + `tenants/{sno}/meta/sync_report.json` |
| **Safety** | §14.1.1 Last Successful Version — 失敗不覆蓋正式 JSON |
| **CLI 契約 SSOT** | `BATS_DATA_CONTRACT.md` §1.6.7；`BATS_DATA_SYNC_POLICY.md` §17.11.2.1 |

#### 8.8.2 Phase 7-1c 退出準則

- [ ] 7-1b staging 成功後 Portal 觸發 `bin/bds-sync.php`
- [ ] 成功：GCS knowledge 更新 + read-back PASS + `sync_report` `status=success` + UI `本次同步成功`
- [ ] 失敗：GCS 正式 JSON **不變** + UI 失敗模板（§14.1.1）
- [ ] `sync_id` 僅於 **成功** promote 後更新；失敗不得回退或部分覆蓋
- [ ] **不** 實作 Cron／Drive Watch／Auto Sync
- [ ] **不** 實作 Core Orchestrator（Deferred）

#### 8.8.3 Out of Scope（Not Planned）

Auto Sync、Scheduled Sync、Cron Sync、Drive Watch、Google Drive Change Trigger、Background Scheduled Sync — **均不納入 Phase 7 主線**。

#### 8.8.4 Deferred

| 項目 | 觸發再評估 |
|------|------------|
| **Core Orchestrator** | 多租戶商品化；Portal 大量使用；Shared Upload 穩定 |
| **bbcshops redirect** | 7-1d（可選） |

#### 8.8.5 共用 BDS Sync Core

7-2 Shared Portal、未來 API Trigger **必須** 重用同一 `bin/bds-sync.php`／BDS core；禁止各入口獨立 sync 邏輯。

#### 8.8.6 CLI Staging Input Contract（Phase 7-1c-1b SSOT）

> **Status: SSOT 定案（2026-06-05）** — Upload Portal staging → BDS CLI 正式輸入契約；L3 契約：`BATS_DATA_CONTRACT.md` §1.6.7。

##### 8.8.6.1 架構決策

| 項目 | 決策 |
|------|------|
| **Upload Mode 正式參數** | `--upload-session-id={id}` |
| **不採用（Portal 正式）** | `--input-xlsx` |
| **模式切換** | 有 `--upload-session-id` → upload mode；無 → sheet mode（6A legacy） |

##### 8.8.6.2 Phase 7-1c-2a — bbc-ai-bot（阻塞項）

| # | 交付項 | 說明 |
|---|--------|------|
| 1 | `parse_cli_args()` 擴充 | 新增 `--upload-session-id`；可選 `--scope=tenant\|shared`（Shared 7-2） |
| 2 | **`BdsUploadStagingResolver`** | 解析 `var/bds/uploads/.../staging/{id}/`；載入 `upload_session.json`；tenant／sno 雙重驗證 |
| 3 | **`BdsXlsxReader`** | staging `.xlsx` → 五 Required Canonical Tabs（Contract §4～§8、§4.4 Mapping Layer） |
| 4 | **`bds-sync.php` upload mode** | 有 session id 時跳過 `BdsGoogleSheetReader`；接入 XlsxReader → 既有 Parser 鏈 |
| 5 | **Source metadata** | `source_type=upload_portal`；記錄 `upload_session_id` 於 sync_report |
| 6 | **Safety** | Validation／Sync／Read-back Fail → 不寫 GCS 正式 JSON（§14.1.1） |

**7-1c-2a 退出準則：**

- [ ] `bin/bds-sync.php --tenant=travel_b --upload-session-id={id} --dry-run` 可讀 staging xlsx 並完成 validation dry-run
- [ ] session／sno 不一致 → exit 非 0；GCS **不變**
- [ ] 無 `--upload-session-id` 時 sheet mode 行為 **不變**（6A 向後相容）
- [ ] **不** 實作 Core Orchestrator（P2-TD-7C0 Deferred）
- [ ] **不** 引入 Cron／Auto／Drive Watch

##### 8.8.6.3 Phase 7-1c-2b — legacy www

| # | 交付項 | 說明 |
|---|--------|------|
| 1 | 更新 `bdsBuildCliSyncCommand()` | 指令含 `--upload-session-id={upload_session_id}` |
| 2 | CLI 執行 | `proc_open`／`exec` 執行 `bin/bds-sync.php`（先 `--dry-run` 驗證 exit code） |
| 3 | UI | 解析 stdout／exit code；失敗模板 §14.1.1 |
| 4 | **禁止** | Portal 觸發 sheet mode；**禁止** `--input-xlsx` |

**7-1c-2b 退出準則：**

- [ ] staging 成功後 Portal 觸發 upload mode CLI
- [ ] 7-1c-2a upload mode **Done** 為前置條件
- [ ] 失敗時 GCS 正式 JSON 不變；`sync_triggered` 僅 success 後更新

##### 8.8.6.4 Staging Path Contract（摘要）

| Scope | 路徑 |
|-------|------|
| **Tenant** | `var/bds/uploads/tenants/{sno}/staging/{upload_session_id}/` |
| **Shared（7-2）** | `var/bds/uploads/shared/{industry_code}/staging/{upload_session_id}/` |

**檔案：** `knowledge_{upload_session_id}.xlsx`（或 `stored_filename`）、`upload_session.json`。

**交叉引用：** `BATS_DATA_SYNC_POLICY.md` §17.11.2.1；`BATS_UPLOAD_PORTAL_PHASE7_MVP_PLAN.md` §12.4

##### 8.8.6.5 Worksheet Mapping Layer — Runtime Design（Phase 7-1e.6）

> **Status: SSOT 定案（2026-06-14）** — upload mode **Worksheet 名稱映射** 之 Runtime 設計；**本節為文件定案，程式待實作**。  
> **L3 契約：** `BATS_DATA_CONTRACT.md` §4.4；**L1 驗收：** `BATS_DATA_SYNC_POLICY.md` §17.12。

###### 8.8.6.5.1 現況與缺口

| 元件 | 現況（2026-06-14） | 缺口 |
|------|-------------------|------|
| `BdsXlsxReader` | 以 **英文 Canonical Key 精確匹配** 工作表名 | **未** 實作 §4.4 中／英別名映射 |
| `upload.php` | 失敗 UI 可顯示 CLI stdout 片段 | **未** 保證中文化（§13.1.9） |
| Sheet mode | `BdsGoogleSheetReader` 英文 Tab 精確匹配 | **不變** |

###### 8.8.6.5.2 目標架構

```text
staging knowledge_{upload_session_id}.xlsx
        ↓
BdsWorksheetMappingLayer::resolveWorkbook($xlsxPath)
  → 掃描全部 worksheet 名稱（順序無關）
  → 別名表 → 5 Canonical Keys
  → 缺 key / 重複 key → 結構化錯誤
        ↓
BdsXlsxReader::readMappedWorkbook($xlsxPath, $mappingResult)
  → 與現有 Parser 相同之 tab payload
        ↓
既有 BdsMockSheetParser → BdsValidator → GCS
```

###### 8.8.6.5.3 新增元件（bbc-ai-bot）

| # | 元件 | 職責 |
|---|------|------|
| 1 | **`BdsWorksheetMappingLayer`** | 別名表 SSOT（程式內常數或 `config/bds_worksheet_aliases.php`）；`normalizeSheetName()`；`resolveAliases(array $sheetNames): MappingResult` |
| 2 | **`BdsWorksheetMappingResult`**（或 array shape） | `mapped: array<canonical, sheetName>`、`missing: list<canonical>`、`duplicates: list<{canonical, sheets[]}>` |
| 3 | **`BdsUploadPortalErrorTranslator`**（建議） | 將 Mapping／Validation 結構化錯誤 → 中文顯示名（供 CLI `--portal-errors=zh` 或 JSON stderr）；Portal 亦可本地映射 |
| 4 | **`BdsXlsxReader` 擴充** | upload mode 路徑：**先** Mapping Layer，**再** 依映射讀取 grid；sheet mode **不** 經 Mapping |

**別名表：** 與 Contract §4.4.2 **逐字一致**；新增別名 **須** 修訂 SSOT 後再改程式。

###### 8.8.6.5.4 映射演算法（Must）

| 步驟 | 行為 |
|------|------|
| 1 | 列舉 workbook 內所有 worksheet 名稱 |
| 2 | 對每個名稱 `trim()` |
| 3 | 英文別名：case-insensitive 比對 Canonical 或 §4.4.2 英文欄 |
| 4 | 中文別名：精確比對 §4.4.2 中文欄 |
| 5 | 未匹配 → 忽略 |
| 6 | 同一 Canonical 被 ≥2 個 sheet 匹配 → `duplicate mapping` Fail |
| 7 | 五 Canonical 任一缺失 → `missing worksheets` Fail |
| 8 | **不** 檢查 sheet 索引順序；**不** 讀取 `original_filename` |

###### 8.8.6.5.5 Portal 中文化（legacy www）

| # | 交付項 | 說明 |
|---|--------|------|
| 1 | **`bdsTranslateUploadError()`**（建議函式名） | 解析 CLI stdout／結構化錯誤 → §13.1.9.4 中文模板 |
| 2 | **失敗 UI** | `{reason}` **僅** 中文；禁止裸顯 `company_profile missing` 等 |
| 3 | **成功 UI** | 維持 §13.1.7；不受 Mapping 變更 |

**優先序：** BDS 輸出結構化錯誤（含 `missing_canonical_keys[]`）→ Portal 映射；過渡期可 regex 映射 §13.1.9.5 表格。

###### 8.8.6.5.6 測試（7-1e.7 建議）

| # | 案例 |
|---|------|
| A | 全中文工作表名、順序 B（§4.4.2 範例）→ exit 0 |
| B | 全英文 Canonical 名、順序 A → exit 0 |
| C | 混用中文＋英文別名 → exit 0 |
| D | 缺少「公司基本資料」→ Fail；Portal reason 含 `缺少「公司基本資料」工作表` |
| E | 重複映射（兩個「QA」語意 sheet）→ Fail |
| F | 未知額外 sheet「備註」→ 忽略；仍 success |
| G | 原始檔名 `最新版.xlsx` → 不影響結果 |
| H | Sheet mode regression → 行為不變 |

###### 8.8.6.5.7 退出準則

- [ ] `BdsWorksheetMappingLayer` + 別名表與 Contract §4.4.2 一致
- [ ] upload mode 接受中／英工作表名；順序／檔名不影響
- [ ] sheet mode **無** regression
- [ ] Portal 失敗訊息符合 §13.1.9（至少 worksheet 結構類錯誤）
- [ ] 新增 `tests/bds/test_bds_worksheet_mapping_layer.php`（或擴充既有 upload mode test）

**Phase 標記：** Runtime 實作建議列 **Phase 7-1e.7**（本文件定案 **7-1e.6** 僅 Docs）。

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v2.6** | 2026-06-14 | Phase 7-1e.6：§8.8.6.5 Worksheet Mapping Layer Runtime Design；Portal 中文化；7-1e.7 測試退出準則 |
| **v2.5** | 2026-06-05 | Phase 7-1c-1b：§8.8.6 CLI Staging Input Contract；7-1c-2a／7-1c-2b 分解 |
| **v2.4** | 2026-06-05 | Phase 7-1c-0：§8.8 Upload→BDS CLI Trigger；7-1a／7-1b Done；7-1c 重新定義 |
| **v2.3** | 2026-06-05 | Phase 7-0e.2 Final：§8.7 Sync Version；7-0e.2 done；7-1a 前退出準則閉合 |
| **v2.2** | 2026-06-05 | Phase 7-0e.1：§8.7 UI Enhancement；7-0e.1 done；7-1／7-2 退出準則增補狀態欄位與 UX |
| **v2.1** | 2026-06-05 | Phase 7-0e：§8.7 UI Field Spec；7-0e done；7-1／7-2 退出準則 UI 項 |
| **v2.0** | 2026-06-05 | Phase 7-0d：§8 分解為 7-1 Tenant／7-2 Shared／7-3 Hardening |
| **v1.9** | 2026-06-05 | Phase 7-0c：§8 Upload Portal MVP 子階段；cross-ref MVP Plan |
| **v1.8** | 2026-06-10 | P1-7 Knowledge Priority、P2-1 Shared Sheet Contract cross-ref |
| **v1.7** | 2026-06-10 | Phase 6A cross-ref §18.7 Structured Knowledge = Google Sheet |
| **v1.6** | 2026-06-10 | Phase 6A SSOT：管線、CLI、Registry、Safety、Acceptance Criteria |
| **v1.5** | 2026-06-10 | Phase 6A～6E 正名；P1 Drive SSOT cross-ref；6B～6E 子階段定義 |
| **v1.4** | 2026-06-10 | Phase 6 Pre-Governance：Google Drive Platform Layer Architecture SSOT 修正 |
| **v1.3** | 2026-06-10 | Phase 5 Close-out：5A～5D Completed；GCS read-back 驗證紀錄；Phase 6 Drive 前提醒 |
| **v1.2** | 2026-06-09 | Phase 5 GCS Writer Controlled Mode 文件補強；Phase 6 Drive Framework cross-ref |
| **v1.1** | 2026-06-09 | Phase 4 Close-out：Reader / Live Read / Pipeline Dry-run 標記 Completed + 驗證紀錄 |
| **v1.0** | 2026-06-08 | 第一版：BDS v1 六 Phase 實作計畫、Safety Rules、Required Outputs、Commit Strategy |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | Draft — 待審核 |
| **實作狀態** | Phase 1～5 **Completed**；Phase 6 未開始 |
| **前置 SSOT** | Sync / Registry / Contract / Ownership 已 commit |
| **Pilot** | `travel_b`（`5f99b8d665e8444d`） |
