# BATS_DRIVE_METADATA_CONTRACT.md

**專案：** BBC AI SaaS / BATS / BDS / Travel Data Center  
**定位：** L3 資料契約層 — Google Drive 非結構化檔案 Metadata Contract（Phase 6 P1）  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_DATA_SOURCE_REGISTRY.md`、`BATS_DATA_SYNC_POLICY.md`、`BATS_TENANT_DATA_CLASSIFICATION.md`、`BATS_DRIVE_GCS_MAPPING.md`、`BATS_DRIVE_CONNECTOR_SCOPE.md`、`BATS_DATA_CONTRACT.md`  
**適用範圍：** 所有租戶（`travel_a`、`travel_b`、`travel_c` 及未來產業租戶）  
**適用對象：** ChatGPT、Cursor、開發者、維運人員  
**衝突處理：** Drive 平台層架構以 `BATS_DATA_SOURCE_REGISTRY.md` §6.5 為準；data_category 以 `BATS_TENANT_DATA_CLASSIFICATION.md` 為準；GCS 路徑以 `BATS_DRIVE_GCS_MAPPING.md` 為準；**Drive 檔案 Metadata 語意以本文件為準**。

> **Status: Planned for Phase 6** — 本文件為治理方向；實作前須與 Phase 6B～6D 對齊。  
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

---

## 1. Purpose

### 1.1 文件定位

本文件定義 **Google Drive 非結構化檔案** 在 BDS Drive Connector 管線中應攜帶之 **Metadata 語意契約**。

**目的：**

- 支援多租戶、多產業之 Drive 檔案追溯與隔離
- 與 Structured Sheet Contract（`BATS_DATA_CONTRACT.md`）**分離**
- 為 Phase 6B～6D 實作提供 **可擴充** 之 Metadata 方向，**不寫死** 實作格式

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
| **檔案類型** | PDF、Image、Excel、Word、PowerPoint（見 `BATS_DRIVE_CONNECTOR_SCOPE.md`） |
| **Drive 路徑** | `tenants/{tenant_key}/01_Private_Layer/`、`shared/{industry_code}/02_Shared_Layer/`、`shared/global/02_Global_Shared_Layer/` |
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
| **Tenant Private** | 單一 `sno` / `tenant_key` | `tenants/{tenant_key}/01_Private_Layer/` |
| **Industry Shared** | `industry_code` | `shared/{industry_code}/02_Shared_Layer/` |
| **Global Shared** | `global` | `shared/global/02_Global_Shared_Layer/` |

**Shared Layer ≠ Public Layer** — Metadata 描述 **歸屬與追溯**，**不是** 公開可見性宣告。

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

下列為 **P1 治理必備** 語意欄位；實作可採 JSON envelope、sync report 或 GCS object metadata，**格式不寫死**。

| 欄位（語意） | 說明 | 別名（相容） | 範例方向 |
|--------------|------|--------------|----------|
| **`tenant_sno`** | 租戶權威邊界（Tenant Private 時必填） | — | Registry 載入 |
| **`industry_code`** | 產業代碼（Shared 時必填） | — | `travel`、`hotel` |
| **`file_id`** | Google Drive 檔案 ID | `drive_file_id` | API 回傳 |
| **`file_name`** | 原始檔名 | `original_filename` | `itinerary_v2.pdf` |
| **`mime_type`** | MIME 類型 | — | `application/pdf` |
| **`checksum`** | 內容指紋 | `content_hash` | SHA-256 |
| **`source_path`** | Drive 邏輯路徑 | `drive_folder_path` | `tenants/travel_b/01_Private_Layer/...` |
| **`uploaded_at`** | 上傳／首次寫入時間（UTC） | — | ISO 8601 |
| **`modified_at`** | Drive 最後修改時間（UTC） | `source_revision` | ISO 8601 |
| **`data_category`** | 對照 `BATS_TENANT_DATA_CLASSIFICATION.md` | — | `itinerary_data`、`shared_knowledge`（archive） |

**建議一併攜帶（治理輔助，非 P1 最小集）：**

| 欄位 | 說明 |
|------|------|
| **`schema_version`** | Metadata contract 版本（如 `bds_drive_metadata.v1`） |
| **`source_type`** | 固定 `google_drive` |
| **`tenant_key`** | 人類可讀代碼（可選對照） |
| **`file_size_bytes`** | 檔案大小 |
| **`owner_scope`** | `tenant` / `industry` / `global` |
| **`discovered_at`** | BDS 首次發現時間 |

---

## 7. Optional Metadata Fields

| 欄位（語意） | 說明 |
|--------------|------|
| **`source_revision`** | Drive 修訂序號或 `modifiedTime` |
| **`uploaded_by`** | 上傳頁或維運操作者（非 PII 識別碼） |
| **`parser_profile`** | 未來解析器類型（Phase 6+ 擴充） |
| **`gcs_object_path`** | promote 後 GCS 路徑（見 Mapping 文件） |
| **`sync_job_id`** | 單次同步作業 ID |
| **`warnings`** | 非阻斷性警告 |

---

## 8. Validation & Safety Rules

| 規則 | 說明 |
|------|------|
| **Tenant 邊界** | Tenant Private 檔案之 `tenant_sno` 須與 Registry 一致 |
| **路徑邊界** | `drive_folder_path` 不得含他社 `tenant_key` 或錯誤 `shared/` 歸屬 |
| **Category C 隔離** | `customer_registration` 檔案 **不得** 標記為 Knowledge promote 目標 |
| **Fail 不 promote** | Metadata 驗證失敗 → **不** 寫入 GCS（對齊 Safety Rule） |
| **禁止 PII 擴散** | Metadata 本身不得含旅客個資明文 |

---

## 9. Multi-Tenant & Multi-Industry

| 原則 | 說明 |
|------|------|
| **多租戶** | 每家旅行社獨立 `tenants/{tenant_key}/01_Private_Layer/`；Metadata 須可區分 `sno` |
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

---

## 11. Cross References

| 文件 | 關係 |
|------|------|
| `BATS_DRIVE_CONNECTOR_SCOPE.md` §2 | Runtime Source Architecture |
| `BATS_DATA_SOURCE_REGISTRY.md` §6.5 | Drive 平台層路徑 |
| `BATS_DRIVE_GCS_MAPPING.md` §5 | Archive / Metadata / Knowledge 三層 |
| `BATS_DRIVE_CONNECTOR_SCOPE.md` | 檔案類型與 Connector 範圍 |
| `BATS_TENANT_DRIVE_ONBOARDING_POLICY.md` | 新租戶 Folder 與 Registry 對照 |
| `BATS_DATA_OWNERSHIP_POLICY.md` §2.7 | 平台層治理 |
| `BATS_DATA_SYNC_POLICY.md` §18.5 | Archive / Runtime 角色 |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.1** | 2026-06-10 | P1 Final：Metadata ≠ Knowledge、P1 必填欄位正名、Runtime Source 對齊 |
| **v1.0** | 2026-06-10 | Phase 6 P1：Drive Metadata Contract 治理方向（Planned） |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | Draft — Planned for Phase 6 |
| **Registry Schema** | **不變** |
| **實作狀態** | 未開始 |
