# BATS_DRIVE_METADATA_CONTRACT.md

**專案：** BBC AI SaaS / BATS / BDS / Travel Data Center  
**定位：** L3 資料契約層 — Google Drive 非結構化檔案 Metadata Contract（Phase 6 P1）  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_DATA_SOURCE_REGISTRY.md`、`BATS_DATA_SYNC_POLICY.md`、`BATS_TENANT_DATA_CLASSIFICATION.md`、`BATS_DRIVE_GCS_MAPPING.md`、`BATS_DRIVE_CONNECTOR_SCOPE.md`、`BATS_DATA_CONTRACT.md`  
**適用範圍：** 所有租戶（`travel_a`、`travel_b`、`travel_c` 及未來產業租戶）  
**適用對象：** ChatGPT、Cursor、開發者、維運人員  
**衝突處理：** Drive 平台層架構以 `BATS_DATA_SOURCE_REGISTRY.md` §6.5、§6.7 為準；data_category 以 `BATS_TENANT_DATA_CLASSIFICATION.md` 為準；GCS 路徑以 `BATS_DRIVE_GCS_MAPPING.md` 為準；GCS object metadata 建議以 `BATS_DATA_SYNC_POLICY.md` §11.5 為準；**Drive 檔案 Metadata Envelope 以本文件為準**。

> **Status: Adopted（Phase 6C-0）** — 正式 Metadata Schema `bds_drive_metadata.v1`、分類矩陣、checksum／路徑規則、Sync Report 契約。  
> **不修改** Registry JSON Schema；**不新增** `private_drive_folder_id`。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §2 | Scope & Non-Scope |
| §3 | Input Strategy Alignment |
| §4 | Metadata Model（治理方向） |
| §5 | Metadata ≠ Knowledge |
| §6 | Required Metadata Fields |
| §7 | Optional Metadata Fields |
| §8 | Validation & Safety Rules |
| §9 | Multi-Tenant & Multi-Industry |
| §10 | Explicit Exclusions |
| §11 | Cross References |
| §12 | Formal Metadata Schema — `bds_drive_metadata.v1` |
| §13 | File Classification Matrix |
| §14 | `source_path` Derivation Rules |
| §15 | Checksum Rules |
| §16 | Drive Metadata ↔ GCS Object Metadata Mapping |
| §17 | Sync Report Contract — `drive_sync_report.json` |
| §18 | Platform Drive Registry（§6.7）對齊 |

---

## 1. Purpose

### 1.1 文件定位

本文件定義 **Google Drive 非結構化檔案** 在 BDS Drive Connector 管線中應攜帶之 **Metadata 語意契約**。

**目的：**

- 支援多租戶、多產業之 Drive 檔案追溯與隔離
- 與 Structured Sheet Contract（`BATS_DATA_CONTRACT.md`）**分離**
- 為 Phase 6C～6D 實作提供 **正式** Metadata Schema（§12）與分類矩陣（§13）

### 1.2 與 Sheet Contract 分工

| 載體 | 資料型態 | Contract SSOT |
|------|----------|---------------|
| **Google Sheet** | Structured Data | `BATS_DATA_CONTRACT.md` |
| **Google Drive** | Unstructured Data | **本文件** |

---

## 2. Scope & Non-Scope

### 2.1 In Scope

| 項目 | 說明 |
|------|------|
| **檔案類型** | PDF、Image、Excel、Word、PowerPoint、ZIP（§13）；`registrations/` 匯出檔（Category C） |
| **Drive 路徑** | `industries/{industry_code}/tenants/{tenant_key}/01_Private_Layer/`、`industries/{industry_code}/shared/02_Shared_Layer/`、`global/02_Global_Shared_Layer/`、`registrations/` |
| **用途** | Archive 追溯、GCS promote 對照、同步報告、rollback 參照 |

### 2.2 Out of Scope

| 排除 | 說明 |
|------|------|
| **Sheet Tab / Header** | 屬 Structured Contract |
| **Knowledge 五 JSON 欄位** | 屬 `BATS_DATA_CONTRACT.md` |
| **RAG / Vector / Embedding** | Phase 6 **不包含** |
| **Registry Schema 修訂** | 本階段 **不新增** 正式必填欄位 |

---

## 3. Input Strategy Alignment

```text
Google Sheet  →  Structured  →  BATS_DATA_CONTRACT.md
Google Drive  →  Unstructured →  BATS_DRIVE_METADATA_CONTRACT.md（本文件）
```

| 原則 | 說明 |
|------|------|
| **Content First** | Drive 以檔案內容與 Archive 角色為主；無 Tab／Header 固定契約 |
| **Registry Driven** | `tenant_key`、`sno`、`industry_code` 須來自 Registry；禁止 hardcode |
| **Default Private** | Metadata 不得假設檔案或資料夾為 Public |

---

## 4. Metadata Model（治理方向）

### 4.1 概念模型

```text
Google Drive File
        ↓
Drive Metadata Envelope（本文件）
        ↓
Archive / Promote Decision（BATS_DRIVE_GCS_MAPPING.md）
        ↓
GCS Object（含 metadata 對照）
```

### 4.2 分層語意

| 層級 | Metadata 歸屬 | 說明 |
|------|---------------|------|
| **Tenant Private** | 單一 `sno` / `tenant_key` | `industries/{industry_code}/tenants/{tenant_key}/01_Private_Layer/` |
| **Industry Shared** | `industry_code` | `industries/{industry_code}/shared/02_Shared_Layer/` |
| **Global Shared** | `global` | `global/02_Global_Shared_Layer/` |
| **Platform（Category C）** | `platform` | `registrations/`（`customer_registration`） |

**Shared Layer ≠ Public Layer** — Metadata 描述 **歸屬與追溯**，**不是** 公開可見性宣告。

**命名澄清：** SSOT **不** 使用 `industry_shared_knowledge`／`global_shared_knowledge` 作為獨立 `data_category`。Industry／Global 共用 Archive 使用 **`shared_knowledge` + `owner_scope`**（`industry` 或 `global`）；Structured Knowledge JSON 見 `BATS_SHARED_KNOWLEDGE_CONTRACT.md`。

### 4.3 Runtime Source 對齊

| 載體 | 產出 | 消費者 |
|------|------|--------|
| **Google Drive** | 原始檔（Archive Source of Truth） | BDS Sync **僅** |
| **Metadata Envelope（本文件）** | 檔案描述 + 同步狀態 | BDS report、GCS object metadata |
| **GCS Knowledge** | Structured JSON（`BATS_DATA_CONTRACT.md`） | BATS Runtime |

> BATS Runtime **不得** 依賴即時 Google Drive 搜尋取得 Metadata。詳見 `BATS_DRIVE_CONNECTOR_SCOPE.md` §2。

---

## 5. Metadata ≠ Knowledge

| 概念 | 定義 | 用途 |
|------|------|------|
| **Metadata** | **只描述檔案與同步狀態** | 追溯、驗證、promote 決策、報告 |
| **Knowledge** | **Runtime 使用之內容** | BATS Search、Gemini Context、Future RAG |

| 規則 | 說明 |
|------|------|
| **不得混用** | Metadata envelope **不是** Knowledge JSON |
| **不得替代** | Drive Metadata **不能** 取代 `tenants/{sno}/knowledge/*.json` |
| **分層消費** | Runtime 讀 **GCS Knowledge Layer**；Metadata 供同步管線與稽核 |
| **未來 RAG** | 僅能從 **GCS** 取得資料；Metadata 可作索引參照，**不是** 向量來源 |

```text
Drive File → Metadata Envelope（描述）     ≠  Knowledge JSON（內容）
                    ↓
            GCS Archive Object（原件）
                    ↓
            Knowledge Layer（Structured / 未來擴充）→ BATS Runtime
```

---

## 6. Required Metadata Fields

下列為 **語意摘要**；**正式 JSON Schema** 見 **§12 `bds_drive_metadata.v1`**。

| 欄位（語意） | 說明 | 別名（相容） |
|--------------|------|--------------|
| **`tenant_sno`** | 租戶權威邊界（`owner_scope=tenant` 時必填） | — |
| **`industry_code`** | 產業代碼（`owner_scope=industry` 時為實際 code；`global` 時固定 `global`） | — |
| **`file_id`** | Google Drive 檔案 ID | `drive_file_id` |
| **`file_name`** | 原始檔名（Drive API） | `original_filename` |
| **`mime_type`** | MIME 類型 | — |
| **`checksum`** | 內容指紋（見 §15） | `content_hash` |
| **`source_path`** | **邏輯**路徑（見 §14）；**非** Drive 顯示名稱 | `drive_folder_path` |
| **`uploaded_at`** | 上傳／建立時間（UTC） | — |
| **`modified_at`** | Drive 最後修改時間（UTC） | — |
| **`data_category`** | 對照 `BATS_TENANT_DATA_CLASSIFICATION.md` §1.3 | — |
| **`owner_scope`** | `tenant`／`industry`／`global`／`platform` | — |

---

## 7. Optional Metadata Fields

除 §12 正式欄位外，下列為 **擴充語意**（v1 可選）：

| 欄位（語意） | 說明 |
|--------------|------|
| **`uploaded_by`** | 上傳頁或維運操作者（非 PII 識別碼） |
| **`parser_profile`** | 未來解析器類型（Phase 6+ 擴充） |
| **`source_revision`** | Drive `version` 或 `modifiedTime` 字串（除 `modified_at` 外） |

---

## 8. Validation & Safety Rules

| 規則 | 說明 |
|------|------|
| **Schema** | 須符合 §12 `bds_drive_metadata.v1` 必填與條件必填 |
| **Tenant 邊界** | `owner_scope=tenant` 時 `tenant_sno` 須與 Tenant Registry 一致 |
| **路徑邊界** | `source_path` 須為 §14 邏輯路徑；不得含他社 `tenant_key` |
| **分類一致** | `owner_scope` ↔ `data_category` 須符合 §13 矩陣 |
| **Category C 隔離** | `customer_registration` **不得** promote 至 `knowledge/` |
| **Fail 不 promote** | 驗證失敗 → `promote_status=failed`；**不** 寫入 GCS Archive（6D） |
| **禁止 PII 擴散** | Metadata 本身不得含旅客個資明文 |
| **Structured 捷徑** | Drive Excel **不得** 觸發五 JSON Validator（Phase 7 Upload 主徑） |

---

## 9. Multi-Tenant & Multi-Industry

| 原則 | 說明 |
|------|------|
| **多租戶** | 每家旅行社獨立 `industries/{industry_code}/tenants/{tenant_key}/01_Private_Layer/`；Metadata 須可區分 `sno` |
| **多產業** | `industry_code` 決定 Shared fallback 路徑；新增產業 **僅擴充** code + 路徑 |
| **可擴充** | 新檔案類型、新 parser profile 透過 **schema_version** 演進；不破壞 v1 語意 |
| **Pilot ≠ 特例** | `travel_b` 僅測試用例；Metadata 規則 **不得** hardcode |

---

## 10. Explicit Exclusions

| 禁止 | 說明 |
|------|------|
| **`private_drive_folder_id`** | 未引入 Registry Schema |
| **Embedding / chunk metadata** | RAG 範疇；Phase 6 不做 |
| **Vector DB 欄位** | Phase 6 不做 |
| **AI Ranking / Recommendation 欄位** | Phase 6 不做 |
| **將 Drive Metadata 等同 Sheet Contract** | 兩者分離 |
| **Drive Excel → 五 JSON 捷徑** | Structured 主徑為 Upload Portal（Phase 7）；見 §13 |

---

## 12. Formal Metadata Schema — `bds_drive_metadata.v1`

> **Status: Adopted** — Phase 6C-1 起，每個 Drive 檔案 **必須** 可序列化為下列 JSON 物件（單檔 envelope）。

### 12.1 頂層物件

| 欄位 | 型別 | 必填 | 說明 |
|------|------|------|------|
| **`schema_version`** | string | ✅ | 固定 `bds_drive_metadata.v1` |
| **`source_type`** | string | ✅ | 固定 `google_drive` |
| **`file_id`** | string | ✅ | Google Drive 檔案 ID |
| **`file_name`** | string | ✅ | Drive API 原始檔名 |
| **`mime_type`** | string | ✅ | MIME 類型 |
| **`file_size_bytes`** | integer \| null | ✅ | 位元組；未知時 `null` + warning |
| **`checksum`** | string \| null | ✅ | 指紋字串；見 §15 |
| **`checksum_method`** | string | ✅ | `drive_md5` \| `sha256_content` \| `unavailable` |
| **`source_path`** | string | ✅ | **邏輯**路徑（§14） |
| **`source_path_kind`** | string | ✅ | 固定 `logical` |
| **`owner_scope`** | string | ✅ | `tenant` \| `industry` \| `global` \| `platform` |
| **`tenant_key`** | string \| null | 條件 | `owner_scope=tenant` 時必填 |
| **`tenant_sno`** | string \| null | 條件 | `owner_scope=tenant` 時必填 |
| **`industry_code`** | string | ✅ | `travel` 等；`global` 層固定 `global`；`platform` 層固定 `platform` |
| **`data_category`** | string | ✅ | 見 §13；須符合 `BATS_DATA_SYNC_POLICY.md` §12 |
| **`uploaded_at`** | string | ✅ | ISO 8601 UTC（Drive `createdTime` 或等價） |
| **`modified_at`** | string | ✅ | ISO 8601 UTC（Drive `modifiedTime`） |
| **`discovered_at`** | string | ✅ | BDS 首次掃描時間（ISO 8601 UTC） |
| **`sync_job_id`** | string | ✅ | 單次 Drive 掃描／同步作業 ID |
| **`promote_status`** | string | ✅ | `pending` \| `validated` \| `promoted` \| `skipped` \| `failed` |
| **`gcs_object_path`** | string \| null | ✅ | promote 前 `null`；6D 成功後填入 GCS Archive 路徑 |
| **`warnings`** | string[] | ✅ | 非阻斷警告碼列表；無則 `[]` |

### 12.2 條件必填規則

| `owner_scope` | `tenant_key` / `tenant_sno` | `industry_code` |
|---------------|----------------------------|-----------------|
| **`tenant`** | **必填**（須與 Tenant Registry 一致） | 必填（來自 Tenant entry） |
| **`industry`** | **必須為 `null`** | 必填（實際產業 code） |
| **`global`** | **必須為 `null`** | 固定 **`global`** |
| **`platform`** | **必須為 `null`** | 固定 **`platform`** |

### 12.3 JSON 範例 — Tenant Private PDF

```json
{
  "schema_version": "bds_drive_metadata.v1",
  "source_type": "google_drive",
  "file_id": "1abcExampleDriveFileId",
  "file_name": "summer_tour.pdf",
  "mime_type": "application/pdf",
  "file_size_bytes": 204800,
  "checksum": "d41d8cd98f00b204e9800998ecf8427e",
  "checksum_method": "drive_md5",
  "source_path": "industries/travel/tenants/travel_b/01_Private_Layer/summer_tour.pdf",
  "source_path_kind": "logical",
  "owner_scope": "tenant",
  "tenant_key": "travel_b",
  "tenant_sno": "5f99b8d665e8444d",
  "industry_code": "travel",
  "data_category": "itinerary_data",
  "uploaded_at": "2026-01-10T08:00:00Z",
  "modified_at": "2026-06-01T12:30:00Z",
  "discovered_at": "2026-06-12T08:00:00Z",
  "sync_job_id": "bds-drive-20260612-travel_b-001",
  "promote_status": "pending",
  "gcs_object_path": null,
  "warnings": []
}
```

### 12.4 版本演進

| 規則 | 說明 |
|------|------|
| **v1 凍結語意** | 上表欄位名與 `owner_scope` 枚舉在 v1 內 **不得** 重新定義 |
| **v2+** | 僅允許 **新增** 可選欄位或擴充 `warnings` 碼；須 bump `schema_version` |
| **禁止** | 未 bump schema 即變更必填規則 |

---

## 13. File Classification Matrix

### 13.1 `data_category` 正式枚舉（Drive Archive）

| `data_category` | 說明 | 禁止 |
|-----------------|------|------|
| **`itinerary_data`** | Category A 行程／商品 Archive | 寫入 `knowledge/*.json` 五檔 |
| **`tenant_private_knowledge`** | **僅** 當檔案為 Knowledge **非結構化原件備份**（非 Structured 主徑） | 暗示已完成 Sheet Contract 同步 |
| **`shared_knowledge`** | Industry／Global Shared **Archive** 語意 | 與 Shared **JSON** Contract 混淆 |
| **`customer_registration`** | Category C；`registrations/` | promote 至 `knowledge/` |
| **`archive`** | 通用非結構化 Archive（Word／PPT／ZIP 等預設） | 作為 Knowledge JSON 來源 |

> **禁止** 新增 `industry_shared_knowledge`、`global_shared_knowledge` 為獨立 `data_category`。Industry／Global 以 **`shared_knowledge` + `owner_scope`** 區分。

### 13.2 檔案類型 × `owner_scope` → `data_category`

| 檔案類型 | MIME（代表） | `tenant` | `industry` | `global` | `platform` |
|----------|-------------|----------|------------|----------|------------|
| **PDF** | `application/pdf` | `itinerary_data` | `shared_knowledge` | `shared_knowledge` | `customer_registration` |
| **Image** | `image/jpeg`、`image/png`、`image/webp` | `itinerary_data` | `shared_knowledge` | `shared_knowledge` | `customer_registration` |
| **Excel** | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` 等 | `itinerary_data`¹ | `shared_knowledge` | `shared_knowledge` | `customer_registration` |
| **Word** | `application/vnd.openxmlformats-officedocument.wordprocessingml.document` 等 | `archive` | `shared_knowledge` | `shared_knowledge` | `customer_registration` |
| **PowerPoint** | `application/vnd.openxmlformats-officedocument.presentationml.presentation` 等 | `archive` | `shared_knowledge` | `shared_knowledge` | `customer_registration` |
| **ZIP** | `application/zip` | `archive` | `shared_knowledge` | `shared_knowledge` | `customer_registration` |
| **Customer Registration** | 匯出 CSV／XLSX／PDF 等 | — | — | — | **`customer_registration`**（僅 `platform`） |

¹ **Excel（Tenant）：** Drive 上 Excel **不得** 觸發 `BATS_DATA_CONTRACT.md` 五 JSON 管線；Structured 主徑為 **Phase 7 Upload Portal**。若檔名／路徑語意明確為行程商品表，標 `itinerary_data`；否則 `archive` + warning `W_CLS_EXCEL_AMBIGUOUS`。

### 13.3 Promote 預設（Phase 6D 對照）

| `owner_scope` | `data_category` | GCS Archive（概念） | Knowledge JSON |
|---------------|-----------------|---------------------|----------------|
| `tenant` | `itinerary_data`／`archive` | `tenants/{sno}/archive/` | **禁止** |
| `tenant` | `tenant_private_knowledge` | `tenants/{sno}/archive/` | **禁止**（Sheet／Upload 專用） |
| `industry` | `shared_knowledge` | `shared/{industry_code}/archive/` | **須 Policy**（6E） |
| `global` | `shared_knowledge` | `shared/global/archive/` | **須 Policy**（6E） |
| `platform` | `customer_registration` | Archive only／受控 prefix | **禁止** `knowledge/` |

### 13.4 分類決策順序

```text
1. 由 Registry + 掃描起點 folder 判定 owner_scope（§14、§18）
2. 若 owner_scope=platform → data_category=customer_registration（結束）
3. 依 mime_type 查 §13.2 矩陣
4. 若矩陣允許多解（Excel）→ 檔名副檔名 + 可選 warning；不猜測內容
5. 寫入 envelope；Validator 驗證 owner_scope ↔ data_category 一致性
```

---

## 14. `source_path` Derivation Rules

### 14.1 核心原則

| 原則 | 說明 |
|------|------|
| **Drive 實體名稱 ≠ Metadata 邏輯路徑** | Folder API `name`（如 `旅行蜜優惠`）**不得** 寫入 `source_path` |
| **`source_path_kind`** | 固定 **`logical`** |
| **SSOT 樹** | 路徑段來自 `BATS_DATA_SOURCE_REGISTRY.md` §6.5（Industry First） |
| **Registry Driven** | `industry_code`、`tenant_key` 來自 Registry；禁止 hardcode |

### 14.2 邏輯路徑模板

| `owner_scope` | `source_path` 模板 |
|---------------|-------------------|
| **`tenant`** | `industries/{industry_code}/tenants/{tenant_key}/01_Private_Layer/{file_name}` |
| **`industry`** | `industries/{industry_code}/shared/02_Shared_Layer/{file_name}` |
| **`global`** | `global/02_Global_Shared_Layer/{file_name}` |
| **`platform`** | `registrations/{file_name}`（或受控子路徑段，須與 Onboarding 一致） |

**子資料夾：** 若檔案位於邏輯層下之子目錄，於固定層段之後附加 **相對** 路徑（仍用 `/`，不用 Drive ID）：

```text
industries/travel/tenants/travel_b/01_Private_Layer/2026/summer_tour.pdf
```

### 14.3 推導演算法（規範）

```text
INPUT:  file_id, file_name, scan_context (registry entry + anchor folder role)
OUTPUT: source_path (logical)

1. 自 scan_context 判定 owner_scope 與 industry_code／tenant_key（§18）
2. 選用 §14.2 對應模板之前綴（固定段：01_Private_Layer、02_Shared_Layer 等）
3. 若 FolderScanner 提供相對子路徑（相對於 anchor 邏輯層），插入前綴與 file_name 之間
4. 拼接 file_name（URL-safe；保留 Unicode 檔名原文）
5. 禁止將 Drive parent folder 的 display name 代入路徑段
6. Legacy folder（§6.5.6 Migration Debt）仍用 **邏輯** 模板；warnings 加 W_PATH_LEGACY_FOLDER
```

### 14.4 與實體 Folder 的對照

| 項目 | 用途 |
|------|------|
| **`file_id`** | Drive API 追溯 |
| **`source_path`** | 人類可讀邏輯位置、稽核、GCS 路徑對照 |
| **Drive folder `name`** | 僅供 Scanner 報告；**不** 進 envelope 路徑 |

---

## 15. Checksum Rules

### 15.1 決策摘要

| 階段 | `checksum_method` | 說明 |
|------|-------------------|------|
| **Phase 6C（Metadata）** | **`drive_md5`**（優先） | 使用 Drive API `md5Checksum`；**不下載** 全文 |
| **Phase 6D（Promote）** | **`sha256_content`**（必填） | 下載即將寫入 GCS 的 bytes，計算 SHA-256 |
| **無法取得** | **`unavailable`** | `checksum=null` + warning `W_CHK_UNAVAILABLE` |

### 15.2 原因

| 方案 | 採用／不採用 | 原因 |
|------|-------------|------|
| **`drive_md5`（6C）** | ✅ 6C 預設 | 無需下載大檔即可分類與比對變更；符合唯讀 Scanner |
| **`sha256_content`（6D）** | ✅ 6D 權威 | GCS promote 需內容級完整性；與物件儲存最佳實務一致 |
| **僅 `md5` 作為 GCS 最終指紋** | ❌ | MD5 非 promote 最終權威；6D 必須 SHA-256 |
| **6C 即下載算 SHA-256** | ❌ 預設不做 | 違反 6C 輕量掃描邊界；延後至 6D |

### 15.3 格式

| `checksum_method` | `checksum` 格式 |
|-------------------|-----------------|
| `drive_md5` | 小寫 hex 32 字元（Drive `md5Checksum`） |
| `sha256_content` | `sha256:` + 小寫 hex 64 字元 |
| `unavailable` | `null` |

### 15.4 Google Workspace 原生檔

| 類型 | 行為 |
|------|------|
| Google Docs／Sheets／Slides 等 | 常無 `md5Checksum` → `checksum_method=unavailable` |
| 二進位上傳檔（PDF、Office、ZIP） | 優先 `drive_md5` |

### 15.5 Promote 時更新

6D promote 成功後，**必須** 將同一 envelope 的 `checksum`／`checksum_method` 更新為 `sha256_content`，並寫入 sync report 與 GCS object metadata（§16）。

---

## 16. Drive Metadata ↔ GCS Object Metadata Mapping

> GCS 建議欄位 SSOT：`BATS_DATA_SYNC_POLICY.md` §11.5。下列為 **6D promote** 時之對照。

| Drive Envelope（§12） | GCS Object Metadata key | 備註 |
|----------------------|-------------------------|------|
| `tenant_sno` | `tenant_sno` | 1:1 |
| `industry_code` | `industry_code` | Shared／Global 時必填 |
| `data_category` | `data_category` | 1:1 |
| `schema_version` | `schema_version` | Drive=`bds_drive_metadata.v1`；勿與 JSON Contract 版本混淆 |
| `modified_at` | `source_revision` | 語意對齊；GCS 沿用 Sync Policy 命名 |
| `sync_job_id` | `sync_id` | 作業級 ID |
| — | `published_at` | promote 完成時刻（6D 寫入） |
| — | `bds_mode` | 固定 `B` |
| `file_id` | `drive_file_id`（custom metadata） | 若 bucket 政策允許 |
| `checksum`（`sha256_content`） | `content_checksum`（custom） | promote 後權威指紋 |
| `source_path` | `drive_logical_path`（custom） | 邏輯路徑追溯 |
| `gcs_object_path` | — | 為物件路徑本身，非 metadata |

**禁止：** 將完整 Drive envelope 嵌入 GCS **Knowledge** JSON。Knowledge 層僅 Structured Contract 產出。

---

## 17. Sync Report Contract — `drive_sync_report.json`

### 17.1 定位

單次 **Drive 掃描／Metadata 作業** 之彙總報告；**不是** Knowledge JSON；**不是** Sheet 管線之 `sync_report.json`（同名不同檔，見 §17.4）。

### 17.2 儲存位置（正式）

| 環境 | 路徑 |
|------|------|
| **GCS 權威（建議）** | `tenants/{sno}/meta/drive_sync_report.json` |
| **Host A 本機／測試** | `tests/bds/output/tenants/{sno}/meta/drive_sync_report.json` 或 `cache/tenants/{sno}/meta/` |

對齊 `BATS_DATA_SYNC_POLICY.md` §11.3 `tenants/{sno}/meta/` 為同步資訊目錄。

**Shared／Global 掃描（6E）：** 建議 `shared/{industry_code}/meta/drive_sync_report.json` 或 `shared/global/meta/drive_sync_report.json`（6E 實作前不得寫入租戶 prefix）。

### 17.3 頂層結構

```json
{
  "schema_version": "bds_drive_sync_report.v1",
  "sync_job_id": "bds-drive-20260612-travel_b-001",
  "generated_at": "2026-06-12T08:05:00Z",
  "scan_scope": "tenant_private",
  "tenant_key": "travel_b",
  "tenant_sno": "5f99b8d665e8444d",
  "industry_code": "travel",
  "status": "success",
  "summary": {
    "files_discovered": 2,
    "files_validated": 2,
    "files_failed": 0,
    "warnings_count": 1
  },
  "files": [
    { }
  ]
}
```

| 欄位 | 說明 |
|------|------|
| **`files[]`** | 元素為完整 **`bds_drive_metadata.v1`** envelope（§12） |
| **`status`** | `success` \| `partial` \| `failed` |
| **`scan_scope`** | `tenant_private` \| `industry_shared` \| `global_shared` \| `platform_roots` |

### 17.4 與其他報告之分離

| 檔案 | 管線 | SSOT |
|------|------|------|
| **`drive_sync_report.json`** | Drive Metadata（6C） | **本文件 §17** |
| **`sync_report.json`** | Sheet → Knowledge JSON（6A） | `BATS_DATA_SYNC_RUNBOOK.md` §7 |
| **`validation_report.json`** | Validator | Runbook |
| **`error_report.json`** | 錯誤明細 | Runbook |

### 17.5 Phase 6C 邊界

| 允許 | 禁止 |
|------|------|
| 寫入本機或 GCS **`meta/drive_sync_report.json`** | promote Archive bytes（6D） |
| `promote_status=pending` | 寫入 `knowledge/*.json` |

---

## 18. Platform Drive Registry（§6.7）對齊

> SSOT：`BATS_DATA_SOURCE_REGISTRY.md` §6.7 — Platform Registry 與 Tenant Registry **完全分離**。

### 18.1 Metadata 解析邊界

| Registry | 提供予 Metadata | 不提供 |
|----------|----------------|--------|
| **Tenant Registry（§7）** | `tenant_key`、`sno`、`industry_code`、`private_knowledge_folder_id` | Platform root ID |
| **Platform Drive Registry（§6.7）** | `industries_root_folder_id`、`global_root_folder_id`、`registrations_root_folder_id`；未來 `industries.{code}.shared_layer_folder_id` | `tenant_sno` |

### 18.2 `owner_scope` 判定（掃描錨點）

| 掃描錨點 Folder ID 來源 | `owner_scope` |
|------------------------|---------------|
| Tenant `private_knowledge_folder_id` 或其子樹 | `tenant` |
| Platform `industries.{code}.shared_layer_folder_id` | `industry` |
| Platform `global.shared_layer_folder_id`（規劃） | `global` |
| Platform `registrations_root_folder_id` | `platform` |

**6C-1 Pilot：** 僅 **Tenant Private**（`travel_b` `private_knowledge_folder_id`）為必達；Industry／Global 待 Platform industry map（6B-2D+）與 Policy（6E）。

### 18.3 禁止

| 禁止 | 說明 |
|------|------|
| Platform root ID 寫入 Tenant envelope 必填欄位 | 違反 §6.7 分離 |
| 由 Drive 父資料夾猜測 `tenant_sno` | 須 Registry |
| 未登錄之 folder ID 產出 `validated` promote_status | 須 `failed` + error |

---

## 11. Cross References

| 文件 | 關係 |
|------|------|
| `BATS_DRIVE_CONNECTOR_SCOPE.md` §2、§7 | Runtime Source；6C～6E Roadmap |
| `BATS_DATA_SOURCE_REGISTRY.md` §6.5、**§6.7** | Drive 邏輯樹；Platform／Tenant Registry |
| `BATS_DRIVE_GCS_MAPPING.md` §4、§7、§8 | Archive／Metadata／Knowledge；Promote |
| `BATS_DATA_SYNC_POLICY.md` §11.3、§11.5、§12、§17 | `meta/` 目錄；GCS metadata；`data_category`；Upload 邊界 |
| `BATS_TENANT_DATA_CLASSIFICATION.md` §1.3、§3 | Category A／B／C |
| `BATS_DATA_CONTRACT.md` §1.6 | Structured 與 Drive 分工 |
| `BATS_SHARED_KNOWLEDGE_CONTRACT.md` §6 | Shared **Knowledge** JSON（非本 envelope） |
| `BATS_TENANT_DRIVE_ONBOARDING_POLICY.md` | Onboarding 與邏輯路徑 |
| `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` §Phase 6C | 退出準則 |
| `CO_WORK_POLICY.md` §4.4 | Architecture Before Runtime |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v2.0** | 2026-06-12 | Phase 6C-0：**Adopted** — §12 Schema、§13 分類矩陣、§14 路徑、§15 checksum、§16 GCS mapping、§17 Sync Report、§18 §6.7 |
| **v1.2** | 2026-06-06 | Phase 6B-1D：Drive 路徑對齊 Industry First（`industries/{industry_code}/...`） |
| **v1.1** | 2026-06-10 | P1 Final：Metadata ≠ Knowledge、P1 必填欄位正名、Runtime Source 對齊 |
| **v1.0** | 2026-06-10 | Phase 6 P1：Drive Metadata Contract 治理方向（Planned） |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | **Adopted** — Phase 6C-0 Metadata Contract Hardening |
| **Registry Schema** | **不變** |
| **實作狀態** | 6B-3 Scanner ✅；**6C-1 Runtime 待實作** |
