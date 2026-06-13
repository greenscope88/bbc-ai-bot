# BATS_DRIVE_GCS_MAPPING.md

**專案：** BBC AI SaaS / BATS / BDS / Travel Data Center  
**定位：** L2 架構規劃層 — Google Drive → GCS 對照與 Promote 治理（Phase 6 P1）  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_DATA_SOURCE_REGISTRY.md`、`BATS_DATA_SYNC_POLICY.md`、`BATS_DRIVE_METADATA_CONTRACT.md`、`BATS_TENANT_DATA_CLASSIFICATION.md`、`BATS_DATA_OWNERSHIP_POLICY.md`、`BATS_DATA_CONTRACT.md`  
**適用範圍：** 所有租戶及未來產業（`travel`、`hotel`、`restaurant`、`beauty`、`education`、`medical` 等）  
**適用對象：** ChatGPT、Cursor、開發者、維運人員  
**衝突處理：** GCS 正式結構以 `BATS_DATA_SYNC_POLICY.md` §11 為準；GCS Recovery／Versioning 以 `BDS_RUNTIME_STORAGE_POLICY.md` §9～§10 為準；寫入邊界以 `BATS_DATA_OWNERSHIP_POLICY.md` §3 為準；**Drive → GCS 對照、Archive Promote Gate、6D-1 Pilot 邊界以本文件為準**。

> **Status: Adopted — Phase 6E-1 SSOT Hardening** — Archive Promote 契約已定案；Shared Archive Policy 與物件路徑模板已定案；**不修改** 既有 GCS Knowledge 五 JSON 路徑。  
> **Structured Sheet → GCS** 仍由 Phase 4～5 管線處理；本文件涵蓋 **Unstructured Drive → GCS `archive/`**。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §2 | Runtime Source Architecture |
| §3 | Two Pipelines |
| §4 | Three Layers（Archive / Metadata / Knowledge） |
| §5 | Drive Platform Paths |
| §6 | GCS Target Paths（不變區） |
| §7 | Drive → GCS Archive Mapping |
| §8 | Promote Rules & Archive Promote Gate |
| §8.2 | Google Workspace Native Files（Phase 6D-1） |
| §9 | Archive-Only Rules |
| §10 | One Tenant One Folder |
| §11 | Shared Layer Runtime Boundary |
| §12 | Object Versioning Boundary |
| §13 | Safety Boundary |
| §14 | Multi-Tenant & Multi-Industry |
| §15 | Explicit Exclusions |
| §16 | Cross References |
| §17 | Archive Promote Boundary（Phase 6D-1 / 6E） |
| §18 | Shared Archive Policy Contract（Phase 6E-1） |

---

## 1. Purpose

### 1.1 文件定位

定義 **Google Drive 非結構化檔案** 與 **GCS 物件路徑** 之對照原則，以及 **何時 promote、何時僅留 Archive**。

**不寫死** 實作細節；提供 Phase 6D 實作前之 **可擴充** 治理框架。

### 1.2 核心原則

| 原則 | 說明 |
|------|------|
| **Drive = Archive Source of Truth** | Google Drive 保存原始檔；**不是** Runtime / Search / RAG Source |
| **GCS = Runtime Knowledge Source** | BATS / Gemini / Search 消費 GCS |
| **Sheet 與 Drive 分離** | Structured 走 Contract；Unstructured 走本文件 |
| **Shared ≠ Public** | `shared/` 為受控 fallback 路徑，非公開 bucket 政策 |
| **三層不得混用** | Archive / Metadata / Knowledge **分離**（§4） |

---

## 2. Runtime Source Architecture

> **SSOT 交叉引用：** `BATS_DRIVE_CONNECTOR_SCOPE.md` §2、`BATS_DATA_SYNC_POLICY.md` §18.5

| 載體 | 角色 |
|------|------|
| **Google Drive** | Source of Truth（Archive Source）— 保存 PDF / Image / Excel / Word / PowerPoint |
| **GCS** | Runtime Knowledge Source — BATS Search、Gemini Context、Metadata Layer、Knowledge Layer、Future RAG |

**BATS Runtime 不得依賴即時 Google Drive 搜尋。**

```text
Google Drive → BDS Sync → GCS Archive → Metadata Layer → Knowledge Layer
                                                              ↓
                                    Future RAG / Search / Gemini Runtime → LINE OA
```

**禁止：** `LINE OA → Google Drive API → PDF → Gemini`；`Google Drive → RAG → Gemini`

---

## 3. Two Pipelines

```text
Pipeline A — Structured Knowledge（Google Sheet = Input Source）
├── A1 Tenant Private   → tenants/{sno}/knowledge/*.json    （Phase 6A）
├── A2 Industry Shared  → shared/{industry}/knowledge/*.json （未來；須 Policy）
└── A3 Global Shared    → shared/global/knowledge/*.json       （未來；須 Policy）

Pipeline B — Unstructured Archive（Google Drive）
Google Drive → Drive Connector → Metadata → GCS Archive Object
```

| Pipeline | 輸入 | Contract / Mapping | 狀態 |
|----------|------|-------------------|------|
| **A1** | Tenant Private Google Sheet | `BATS_DATA_CONTRACT.md` | Phase 6A |
| **A2** | Industry Shared Google Sheet | `BATS_SHARED_KNOWLEDGE_CONTRACT.md` | 未實作 |
| **A3** | Global Shared Google Sheet | `BATS_SHARED_KNOWLEDGE_CONTRACT.md` | 未實作 |
| **B** | Google Drive（PDF 等） | `BATS_DRIVE_METADATA_CONTRACT.md` + **本文件** | Phase 6B+ |

> **Structured Knowledge = Google Sheet**（三層皆然）。FAQ 型知識 **不走** Pipeline B。見 `BATS_DATA_SYNC_POLICY.md` §18.7。

---

## 4. Three Layers（Archive / Metadata / Knowledge）

> **三者不得混用。**

| 層級 | 載體 | 內容 | 消費者 |
|------|------|------|--------|
| **Archive Layer** | Google Drive + GCS Archive Object | 原始檔（PDF、Image 等） | 稽核、回溯、BDS promote 來源 |
| **Metadata Layer** | GCS object metadata / sync report | 檔案描述、同步狀態（見 `BATS_DRIVE_METADATA_CONTRACT.md`） | BDS 管線、維運報告 |
| **Knowledge Layer** | GCS `tenants/{sno}/knowledge/*.json`、`shared/.../knowledge/*.json` | Structured Runtime 內容（**來源均為 Sheet**） | BATS Search、Gemini、Future RAG |

| 禁止混用 | 說明 |
|----------|------|
| **Archive ≠ Knowledge** | GCS Archive 原件 **不** 直接作 BATS 查詢答案 |
| **Metadata ≠ Knowledge** | Metadata 只描述檔案；**不是** Runtime 內容 |
| **Drive ≠ Runtime** | Drive 檔案須經 BDS → GCS 後才進 Runtime 路徑 |
| **Shared 自動下發** | Shared Archive **不得** 未經 Policy 自動進 Knowledge |

```text
Archive Layer（原件）     Metadata Layer（描述）     Knowledge Layer（內容）
      ↓                        ↓                          ↓
  Drive / GCS archive      sync_report / envelope    knowledge/*.json
```

---

## 5. Drive Platform Paths

**營運帳號：** `bbcshops88@gmail.com`

```text
bbcshops88@gmail.com
├── industries/{industry_code}/tenants/{tenant_key}/01_Private_Layer/
├── industries/{industry_code}/shared/02_Shared_Layer/
├── global/02_Global_Shared_Layer/
└── registrations/
```

| 層級 | Drive 邏輯路徑 | 歸屬 |
|------|----------------|------|
| **Tenant Private** | `industries/{industry_code}/tenants/{tenant_key}/01_Private_Layer/` | 單一租戶 |
| **Industry Shared** | `industries/{industry_code}/shared/02_Shared_Layer/` | 產業層 |
| **Global Shared** | `global/02_Global_Shared_Layer/` | 平台層 |

**禁止：** `tenants/{tenant_key}/02_Shared_Layer/`

---

## 6. GCS Target Paths（不變區）

下列 GCS 路徑為 **現行 SSOT**；本文件 **不修改** 其語意。

| 用途 | GCS 路徑 | 來源 |
|------|----------|------|
| **Tenant Knowledge（Structured）** | `tenants/{sno}/knowledge/*.json` | Sheet Pipeline（Phase 5） |
| **Tenant Itinerary（未來）** | `tenants/{sno}/knowledge/itinerary/` | Category A；下一階段 |
| **Industry Shared Knowledge** | `shared/{industry_code}/knowledge/` | Shared JSON；規劃中 |
| **Global Shared Knowledge** | `shared/global/knowledge/` | Shared JSON；規劃中 |

### 6.1 Drive Archive Object 路徑（Phase 6D-0 正式定案）

非結構化檔案 promote 至 GCS 時，**唯一正式 prefix** 為 **`archive/`**（與 Knowledge JSON **分離**）。完整 GCS 樹見 `BATS_DATA_SYNC_POLICY.md` §11。

| 層級 | 正式 GCS Archive prefix | Phase |
|------|-------------------------|-------|
| **Tenant Private** | `tenants/{sno}/archive/` | **6D-1**（pilot） |
| **Industry Shared** | `shared/{industry_code}/archive/` | **6E**（須 Policy） |
| **Global Shared** | `shared/global/archive/` | **6E**（須 Policy） |

#### 物件路徑模板（Tenant Private — 6D-1）

```text
tenants/{sno}/archive/{data_category}/{drive_file_id}/{file_name}
```

| 片段 | 說明 |
|------|------|
| `{sno}` | Tenant Registry `sno`；**禁止** 使用 `tenant_key` |
| `{data_category}` | `BATS_DRIVE_METADATA_CONTRACT.md` 允許之 category（6D-1 見 §17） |
| `{drive_file_id}` | Google Drive file id（穩定鍵） |
| `{file_name}` | 原始檔名；須通過 Gate 6 Path Safety |

#### 物件路徑模板（Industry Shared — Phase 6E）

```text
shared/{industry_code}/archive/{data_category}/{drive_file_id}/{file_name}
```

| 片段 | 說明 |
|------|------|
| `{industry_code}` | 實際產業代碼（`travel`、`hotel`、`restaurant` 等）；**禁止** 使用 `tenant_key` |
| `{data_category}` | Phase **6E**：**僅** `shared_knowledge`（`owner_scope=industry`） |
| `{drive_file_id}` | Google Drive file id（穩定鍵） |
| `{file_name}` | 原始檔名；須通過 Gate 6 Path Safety |

**Drive 來源（邏輯）：** `industries/{industry_code}/shared/02_Shared_Layer/`（見 `BATS_DATA_SOURCE_REGISTRY.md` §6.5）。

#### 物件路徑模板（Global Shared — Phase 6E）

```text
shared/global/archive/{data_category}/{drive_file_id}/{file_name}
```

| 片段 | 說明 |
|------|------|
| `{data_category}` | Phase **6E**：**僅** `shared_knowledge`（`owner_scope=global`） |
| `{drive_file_id}` | Google Drive file id（穩定鍵） |
| `{file_name}` | 原始檔名；須通過 Gate 6 Path Safety |

**Drive 來源（邏輯）：** `global/02_Global_Shared_Layer/`。

#### Phase 6E `owner_scope` Promote 邊界

| `owner_scope` | GCS Archive prefix | 允許 Phase | 前置條件 |
|---------------|-------------------|------------|----------|
| `tenant` | `tenants/{sno}/archive/` | **6D**（pilot） | `BDS_ARCHIVE_PROMOTE_ENABLED` + tenant scope |
| `industry` | `shared/{industry_code}/archive/` | **6E** | Shared Archive Policy **顯式啟用** + Platform Registry `shared_layer_folder_id` |
| `global` | `shared/global/archive/` | **6E** | Global Shared Archive Policy **顯式啟用** + `global.shared_layer_folder_id` |
| `platform` | 受控 registration prefix | **6E+** defer | 非 6E 範圍 |

> **6D 現行 Runtime：** `owner_scope=industry`／`global` **一律禁止 promote**（Gate 1 `gate=scope`），直至 6E-2+ Runtime 實作並通過 Policy Gate。

#### 禁止之 Archive 別名

| 禁止 | 說明 |
|------|------|
| `uploads/` | **禁止** — 非正式名稱 |
| `uploads_archive/` | **禁止** |
| `raw_uploads/` | **禁止** |
| `tenants/{sno}/uploads/` | **禁止** |

> SSOT：`BATS_DATA_SYNC_POLICY.md` §11.7。

---

## 7. Drive → GCS Archive Mapping

### 7.1 對應原則

| 原則 | 說明 |
|------|------|
| **Drive → GCS Archive** | 非結構化原件經 BDS promote 至 GCS Archive prefix |
| **Registry 對照** | `tenant_key`（Drive）↔ `sno`（GCS）；`industry_code` 決定 Shared prefix |
| **Metadata 隨行** | promote 時攜帶 `BATS_DRIVE_METADATA_CONTRACT.md` 必填欄位 |
| **Knowledge 分離** | Sheet Pipeline 五 JSON **不走** 本 Mapping；維持 `tenants/{sno}/knowledge/` |

### 7.2 Mapping Matrix（治理方向）

| 檔案類型 | data_category（概念） | Drive 來源路徑 | GCS 目標（概念） | Promote 預設 |
|----------|----------------------|----------------|------------------|--------------|
| **PDF** | `itinerary_data` 或 archive | `tenants/.../01_Private_Layer/` | `tenants/{sno}/archive/...` | 受控 promote |
| **Image** | `itinerary_data` 或 archive | 同上 | 同上 | 受控 promote |
| **Excel** | `itinerary_data` | 同上 | `tenants/{sno}/archive/...` 或 `knowledge/itinerary/`（下一階段） | 下一階段細化 |
| **Word** | archive | 同上 | `tenants/{sno}/archive/...` | 受控 promote |
| **PowerPoint** | archive | 同上 | 同上 | 受控 promote |
| **Shared PDF/Image** | `shared_knowledge`（archive） | `industries/{industry_code}/shared/02_Shared_Layer/` | `shared/{industry}/archive/...` | Phase 6E；須 Policy 啟用 |
| **Registration 匯出** | `customer_registration` | `registrations/` | **禁止** `knowledge/` | Archive only |

**注意：** Excel 若為 **Google Sheet 維護之結構化知識**，應走 **Pipeline A**，**不** 走 Drive Unstructured Mapping。

---

## 8. Promote Rules & Archive Promote Gate

### 8.0 總則

| 規則 | 說明 |
|------|------|
| **雙重門禁** | `BDS_DRY_RUN` + `BDS_GCS_WRITE_ENABLED` + 目標 `sno` 一致 |
| **Metadata 必備** | 須符合 `BATS_DRIVE_METADATA_CONTRACT.md` 必填語意 |
| **Safety Rule** | 任一 Gate 失敗 → **零 GCS 寫入**；既有 GCS 物件 **不變** |
| **單次受控** | Phase 6D-1：單 tenant pilot；不批次多租戶 |
| **冪等方向** | 相同 `content_hash` + 相同目標路徑 → 可 SKIP（實作細節於 6D-1） |

**Recovery SSOT：** GCS Object Versioning 政策以 `BDS_RUNTIME_STORAGE_POLICY.md` §9～§10 為準。Archive Promote **不得假設** Versioning OFF；亦 **不得依賴** noncurrent 版做 read-back。

### 8.1 Archive Promote Gate（正式 SSOT）

每一筆 Drive 檔案進入 GCS `archive/` 前，**依序** 通過下列 Gate。任一失敗 → **不 promote**；記錄於 `drive_sync_report.json`（見 Metadata Contract §17）。

#### Gate 0 — Environment

| 檢查 | 通過條件 | 失敗處理 |
|------|----------|----------|
| Runtime flags | `BDS_GCS_WRITE_ENABLED=true` 且目標 `sno` 與 CLI／env 一致 | SKIP；`gate=environment` |
| Dry run | `BDS_DRY_RUN=false`（實際寫入時） | PLAN only；零 GCS 寫入 |
| Credentials | GCS SA 可寫目標 bucket | FAIL；`gate=environment` |
| Bucket | 與 `BDS_RUNTIME_STORAGE_POLICY.md` §10.2 一致 | FAIL |

#### Gate 1 — Scope

| 檢查 | 通過條件 | 失敗處理 |
|------|----------|----------|
| `owner_scope` | Phase **6D-1**：**僅** `tenant`；Phase **6E**：`industry`／`global` **僅** 在 Shared Archive Policy 啟用時允許 | SKIP；`gate=scope` |
| Pilot tenant | Phase **6D-1**：**僅** `travel_b`（`sno=5f99b8d665e8444d`） | SKIP；`gate=scope` |
| Drive root | Tenant：`01_Private_Layer`；Industry：`shared_layer_folder_id`；Global：`global.shared_layer_folder_id` | FAIL |
| Shared Policy | `owner_scope=industry`／`global` 時須通過 §18 Policy Gate | SKIP；`gate=policy` |
| Shared／Platform（未啟用） | Policy **OFF** 或 Registry folder ID **null** | **零 promote** |

#### Gate 2 — Metadata Validation

| 檢查 | 通過條件 | 失敗處理 |
|------|----------|----------|
| Envelope | `BdsDriveMetadataValidator` 通過 | FAIL；`gate=metadata` |
| 必填欄位 | `tenant_sno`、`drive_file_id`、`source_path`、`content_hash`、`mime_type`、`owner_scope` 等 | FAIL |
| Registry | `tenant_key` ↔ `sno` 可解析 | FAIL |

#### Gate 3 — Category Allow List

| 檢查 | 通過條件 | 失敗處理 |
|------|----------|----------|
| `data_category` | 屬 6D-1 allow list（見 §17） | SKIP；`gate=category` |
| Knowledge 路徑 | **禁止** 寫入 `knowledge/` | FAIL（Safety） |
| `customer_registration` | **禁止** promote 至 GCS（Drive only） | SKIP |

#### Gate 4 — Content Type

| 檢查 | 通過條件 | 失敗處理 |
|------|----------|----------|
| MIME | 已登錄之 binary MIME（PDF、Image、Office binary…） | SKIP；`gate=content_type` |
| Google Workspace 原生 | `google-apps.spreadsheet`、`google-apps.document`、`google-apps.presentation` | **SKIP + Warning**（見 §8.2） |
| 未知 MIME | 未登錄 | SKIP；`gate=content_type` |

#### Gate 5 — Checksum

| 檢查 | 通過條件 | 失敗處理 |
|------|----------|----------|
| `content_hash` | 非空；算法與 Metadata Contract §15 一致 | FAIL |
| 冪等 | 目標路徑已存在且 hash 相同 | SKIP（idempotent） |
| 衝突 | 同 `drive_file_id` 路徑但 hash 不同 | FAIL 或需人工決策（6D-1 預設 FAIL） |

#### Gate 6 — Path Safety

| 檢查 | 通過條件 | 失敗處理 |
|------|----------|----------|
| Prefix | 僅 `tenants/{sno}/archive/`（6D-1） | FAIL |
| Traversal | 檔名不含 `..`、`/`、`\`、控制字元 | FAIL |
| Cross-tenant | 目標 `sno` 與 envelope 一致 | FAIL |
| 禁止別名 | 不得使用 `uploads*` prefix | FAIL |

#### Gate 7 — Failure Handling

| 情境 | 行為 |
|------|------|
| Gate 失敗 | **不寫入** GCS；**不刪除** Drive；**不覆蓋** 既有 GCS current 物件 |
| Report | 每檔一筆：`status`、`gate`、`reason`、`drive_file_id` |
| Partial batch | 單檔失敗 **不** 中止整批掃描（6D-1）；彙總於 report |
| Read-back | 僅對 **成功寫入** 之物件驗證 **current** 版（見 §12） |
| Rollback | 不以 delete 為預設；必要時依 `BDS_RUNTIME_STORAGE_POLICY.md` Recovery |

### 8.2 Google Workspace Native Files（Phase 6D-1）

下列 Google Workspace **原生** MIME（非 binary export）於 Phase **6D-1** 正式定案：

| MIME | 6D-1 行為 |
|------|-----------|
| `google-apps.spreadsheet` | **SKIP + Warning** |
| `google-apps.document` | **SKIP + Warning** |
| `google-apps.presentation` | **SKIP + Warning** |

| 原則 | 說明 |
|------|------|
| **不做自動 Export** | 6D-1 **不** 呼叫 Drive Export API |
| **Structured 走 Pipeline A** | 若為 Structured Knowledge，應走 **Google Sheet** 管線至 `knowledge/` |
| **Export 留後續** | Workspace → binary export promote 流程留 **Phase 6D-2 或後續** |
| **Report** | `status=skipped`、`reason=google_apps_native`、`gate=content_type` |

---


## 9. Archive-Only Rules

下列情況 **僅留 Google Drive Archive**，**不** promote 至 GCS Knowledge：

| 情況 | 說明 |
|------|------|
| **`customer_registration`** | Category C；禁止進 `knowledge/` |
| **Validation Fail** | Safety Rule |
| **未啟用 Shared Policy** | Shared 檔案不得 promote 至他 tenant 可讀路徑 |
| **dry_run / flag 關閉** | 零 GCS 寫入 |
| **未知 MIME / 未登錄類型** | SKIP；`gate=content_type` |
| **Google Workspace 原生** | `google-apps.spreadsheet` / `document` / `presentation` → **SKIP + Warning**（§8.2） |

---

## 10. One Tenant One Folder

| 原則 | 說明 |
|------|------|
| **One Tenant One Folder** | 每 Tenant **唯一** `industries/{industry_code}/tenants/{tenant_key}/01_Private_Layer/` |
| **唯一 Tenant Archive Source** | 該路徑為該租戶私有非結構化 Archive 來源 |
| **GCS 對照** | promote 目標 **僅** `tenants/{sno}/archive/`（概念）；**禁止** 跨 tenant prefix |
| **禁止** | 跨 Tenant 共用 Folder；跨 Tenant 存放私有資料 |

---

## 11. Shared Layer Runtime Boundary

| 原則 | 說明 |
|------|------|
| **Shared Layer ≠ Public Layer** | 受控 fallback；非公開 |
| **Default Private** | Shared Drive 資料夾預設 Private |
| **預設不進入 Runtime** | 未啟用前 **不** promote 至 GCS Knowledge / Metadata 消費路徑 |
| **Registry + Policy 啟用** | 才能進入 GCS、Metadata Layer、Knowledge Layer、Future RAG |
| **不得自動同步至所有 Tenant** | Phase 6E 須逐項 Policy 驗證 |

---

## 12. Object Versioning Boundary

> **Recovery SSOT：** `BDS_RUNTIME_STORAGE_POLICY.md` §9～§10。本節為 Archive Promote **相容性** 邊界。

| 項目 | 規則 |
|------|------|
| **GCS Object Versioning** | **Primary Recovery Architecture**（bucket-level；見 Runtime Storage Policy） |
| **Archive Promote** | **不得假設** Versioning OFF；overwrite 時舊版成為 noncurrent |
| **Read-back Verification** | **僅驗證 current object** — 不比對 noncurrent generation |
| **Rollback** | 不以 delete current 為預設；必要時還原至指定 generation |
| **Lifecycle** | noncurrent 保留 30 天；不影響 current read-back |

| 禁止 | 說明 |
|------|------|
| 以 Versioning 取代 Validation Gate | Safety Rule 仍須遵守 |
| Read-back 讀取 noncurrent 版作為成功判準 | 僅 current |
| 假設「無 versioning 故可覆寫無虞」 | 與 Recovery SSOT 衝突 |

---

## 13. Safety Boundary

| 允許 | 禁止 |
|------|------|
| `tenants/{sno}/archive/`（概念） | `shared/` 寫入（未授權時） |
| `tenants/{sno}/knowledge/*.json`（Sheet Pipeline） | 以 Drive 覆蓋 Structured JSON |
| 單一 pilot `sno` 受控 promote | 跨 tenant 路徑 |
| 讀取已授權 Drive 路徑 | Delete GCS / Drive 物件作為同步策略 |

---

## 14. Multi-Tenant & Multi-Industry

| 原則 | 說明 |
|------|------|
| **`tenant_key` vs `sno`** | Drive 路徑用 `tenant_key`；GCS 用 `sno`；Registry 對照 |
| **產業擴展** | 新增 `industry_code` → 新增 `industries/{industry_code}/shared/02_Shared_Layer/`（Drive）與對應 GCS prefix |
| **不寫死租戶** | 禁止 hardcode `travel_b` 路徑規則 |
| **可擴充矩陣** | 新檔案類型透過 Mapping Matrix **增列**；不改核心管線 |

---

## 15. Explicit Exclusions

| 排除 | 說明 |
|------|------|
| **RAG / Vector / Embedding** | Phase 6 不做 |
| **AI Ranking / Recommendation** | Phase 6 不做 |
| **`private_drive_folder_id`** | 不引入 Registry |
| **修改五 JSON Contract 路徑** | `BATS_DATA_CONTRACT.md` 不變 |
| **Drive 直接作 BATS 查詢來源** | 違反 Archive Layer 原則 |

---

## 16. Cross References

| 文件 | 關係 |
|------|------|
| `BATS_DRIVE_CONNECTOR_SCOPE.md` §2 | Runtime Source Architecture |
| `BATS_DATA_SOURCE_REGISTRY.md` §6.5 | Drive 平台層 SSOT |
| `BATS_DRIVE_METADATA_CONTRACT.md` §5 | Metadata ≠ Knowledge |
| `BATS_DRIVE_CONNECTOR_SCOPE.md` | 檔案類型範圍 |
| `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` §3.1 | Phase 6A～6E |
| `BATS_DATA_SYNC_POLICY.md` §11、§11.6～§11.7、§18.5 | GCS 結構、archive prefix、Runtime Source |
| `BDS_RUNTIME_STORAGE_POLICY.md` §9～§10 | Recovery、Versioning、read-back current |
| `BATS_DRIVE_METADATA_CONTRACT.md` §15～§17 | checksum、GCS mapping、report |

---


## 17. Archive Promote Boundary（Phase 6D-1）

### 17.1 允許範圍（6D-1 Runtime）

| 維度 | 6D-1 正式邊界 |
|------|---------------|
| **`owner_scope`** | **僅** `tenant` |
| **Pilot** | **僅** `travel_b`（`tenant_key=travel_b`，`sno=5f99b8d665e8444d`） |
| **Drive 來源** | `industries/travel/tenants/travel_b/01_Private_Layer/` |
| **GCS 目標** | `tenants/5f99b8d665e8444d/archive/{data_category}/{drive_file_id}/{file_name}` |
| **管線** | Drive scan → Metadata envelope → Archive Promote Gate → GCS write → read-back（current） |
| **報告** | `var/bds/tenants/{sno}/meta/drive_sync_report.json` |

#### 6D-1 `data_category` Allow List（Tenant Private binary）

| `data_category` | 6D-1 |
|-----------------|------|
| `itinerary_data` | ✅ 允許（binary 原件） |
| `tenant_private_knowledge` | ✅ 允許（binary 附件；非 Sheet JSON） |
| `shared_knowledge` | ❌ 禁止（6E） |
| `customer_registration` | ❌ 禁止（Drive only） |
| `archive`（泛用） | ✅ 允許（若 Metadata Contract 登錄） |

### 17.2 禁止範圍（6D 現行；6E 前）

| 維度 | 6D 現行狀態 | 6E 目標 |
|------|-------------|---------|
| **`owner_scope = industry`** | **禁止** promote | **6E** 允許（須 §18 Policy ON） |
| **`owner_scope = global`** | **禁止** promote | **6E** 允許（須 §18 Policy ON） |
| **`owner_scope = platform`** | **禁止** | **6E+** defer |
| **`shared/{industry_code}/archive/`** | **禁止** | **6E** + Policy |
| **`shared/global/archive/`** | **禁止** | **6E** + Policy |
| **多租戶批次 promote** | **禁止** | 6D-2+ |
| **Google Workspace Export** | **禁止** | **6D-2+** |
| **寫入 `knowledge/*.json`** | **禁止** | 永遠 — Sheet 管線 |
| **tenant-specific shared** | **禁止** | 永遠 |

### 17.3 6D-1 實作類別邊界（文件化）

| 元件 | 職責 |
|------|------|
| `BdsDriveFolderScanner` | Drive 樹掃描（已有） |
| `BdsDriveMetadataEnvelopeBuilder` / `Validator` | Gate 2（已有） |
| `BdsDriveMetadataReportWriter` | 本地 report（已有） |
| **`BdsDriveArchivePromoter`**（6D-1 新增） | Gate 0～7 + GCS archive 寫入 + read-back |
| `BdsGcsUploader` | **僅** `knowledge/` JSON — **不** 用於 archive |

---

## 18. Shared Archive Policy Contract（Phase 6E-1）

### 18.1 定位

定義 **Industry／Global Shared Archive** promote 之 **顯式啟用契約**。與 Tenant Registry **分離**；與 Platform Drive Registry **搭配** 使用。

| 議題 | SSOT |
|------|------|
| Policy 契約（本節） | **本文件** §18 |
| Platform Folder ID | `BATS_DATA_SOURCE_REGISTRY.md` §6.7 |
| Archive 寫入邊界 | `BATS_DATA_OWNERSHIP_POLICY.md` §3.6 |
| GCS 物件路徑 | 本文件 §6.1 |

**儲存（規劃）：** `config/bds_shared_archive_policy.php`（Phase 6E-2；**本階段僅文件定案**）。

### 18.2 核心原則

| # | 原則 |
|---|------|
| 1 | **Policy default = OFF** — 所有 Shared Archive promote **預設禁止** |
| 2 | **顯式啟用** — Industry／Global 各須 **獨立** `archive_promote_enabled: true` |
| 3 | **per-industry allow list** — 僅 Policy 中啟用之 `industry_code` 可 promote |
| 4 | **禁止 tenant-specific shared** — 不得於 Tenant Registry 或租戶資料夾下啟用 Shared promote |
| 5 | **未啟用 = zero promote** — Policy OFF 或 Registry folder ID 為 null → **零 GCS 寫入** |
| 6 | **不得自動下發** — Shared Archive promote **不** 代表自動對所有 tenant 開放讀取 |
| 7 | **不得混入 knowledge/** | Archive promote **僅** 目標 `shared/.../archive/` |

### 18.3 正式 Schema（概念）

```yaml
schema_version: bds_shared_archive_policy.v1

defaults:
  promote_requires_explicit_enable: true

industries:
  travel:
    archive_promote_enabled: false
    maintainer_role: industry_maintainer
  hotel:
    archive_promote_enabled: false
    maintainer_role: industry_maintainer
  restaurant:
    archive_promote_enabled: false
    maintainer_role: industry_maintainer

global:
  archive_promote_enabled: false
  maintainer_role: platform_admin
```

| 欄位 | 說明 |
|------|------|
| `promote_requires_explicit_enable` | 固定 `true`；禁止隱式啟用 |
| `industries.{code}.archive_promote_enabled` | `false` = **零 promote**；`true` = 允許進入 Gate 評估（仍須通過 Gate 0～7） |
| `industries.{code}.maintainer_role` | 治理角色標記（`industry_maintainer`）；**不** 寫入 Tenant Registry |
| `global.archive_promote_enabled` | Global 層獨立開關 |
| `global.maintainer_role` | `platform_admin` |

### 18.4 Policy Gate（6E Runtime — 疊加於 §8.1）

| 檢查 | `owner_scope=industry` | `owner_scope=global` |
|------|------------------------|----------------------|
| Policy entry exists | `industries.{industry_code}` 存在 | `global` 存在 |
| `archive_promote_enabled` | **必須** `true` | **必須** `true` |
| Registry folder ID | `industries.{code}.shared_layer_folder_id` **非 null** | `global.shared_layer_folder_id` **非 null** |
| Scan folder match | 掃描起點 ID = Registry 欄位 | 同上 |
| Allow list | `industry_code` 在 Policy `industries` map 內 | N/A（global 單一項） |
| 失敗處理 | SKIP；`gate=policy`；**零 GCS 寫入** | 同上 |

### 18.5 建議環境變數（6E-2 對照）

| 環境變數 | 用途 |
|----------|------|
| `BDS_ARCHIVE_PROMOTE_ENABLED` | 總開關（6D 已有） |
| `BDS_INDUSTRY_SHARED_ARCHIVE_ENABLED` | Industry 層總開關（預設 `false`） |
| `BDS_GLOBAL_SHARED_ARCHIVE_ENABLED` | Global 層總開關（預設 `false`） |
| `BDS_SHARED_ARCHIVE_INDUSTRIES` | Allow list，例 `travel,hotel` |

> Env 與 config **不得** 繞過 `promote_requires_explicit_enable`；未列於 allow list 之產業 **一律 zero promote**。

### 18.6 明確禁止

| 禁止 | 說明 |
|------|------|
| Policy OFF 時 promote | 違反 default OFF |
| 將 `shared_layer_folder_id` 寫入 Tenant Entry | 違反雙 Registry 分離 |
| 租戶資料夾下 Shared promote | tenant-specific shared |
| promote 至 `shared/.../knowledge/` | 違反 Archive ≠ Knowledge |
| 單次 job 混合 tenant + shared promote | 違反 Ownership §3.5 |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.5** | 2026-06-12 | Phase 6E-1：§6.1 Shared Archive 物件路徑模板；§8.1 Gate 1 Policy；§17.2 6E 邊界；§18 Shared Archive Policy Contract |
| **v1.4** | 2026-06-12 | Phase 6D-0：§6.1 archive 定案；§8.1 Promote Gate；§8.2 Google Workspace SKIP；§12 Versioning 對齊；§17 6D-1 boundary |
| **v1.3** | 2026-06-06 | Phase 6B-1D：§5 Drive Path 對齊 Industry First（`industries/{industry_code}/...`） |
| **v1.2** | 2026-06-10 | Pipeline A1/A2/A3 Structured Sheet 三層；Structured Knowledge = Google Sheet |
| **v1.1** | 2026-06-10 | P1 Final：三層分離、Drive→GCS Mapping、Runtime Source、One Tenant One Folder、Shared Boundary、Object Versioning |
| **v1.0** | 2026-06-10 | Phase 6 P1：Drive → GCS Mapping 治理方向（Planned） |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | **Adopted — Phase 6E-1** |
| **GCS Knowledge 五 JSON** | **不變** |
| **實作狀態** | 6D-1 **Complete**（pilot）；6E-2 Runtime **可開始**（依 §6.1、§18） |
