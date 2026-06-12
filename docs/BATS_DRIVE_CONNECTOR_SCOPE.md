# BATS_DRIVE_CONNECTOR_SCOPE.md

**專案：** BBC AI SaaS / BATS / BDS / Travel Data Center  
**定位：** L2 架構規劃層 — Google Drive Source Connector 範圍治理（Phase 6 P1）  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_DATA_SOURCE_REGISTRY.md`、`BATS_DATA_SYNC_POLICY.md`、`BATS_DRIVE_METADATA_CONTRACT.md`、`BATS_DRIVE_GCS_MAPPING.md`、`BATS_TENANT_DATA_CLASSIFICATION.md`、`BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md`  
**適用範圍：** 所有租戶及未來產業  
**適用對象：** ChatGPT、Cursor、開發者、維運人員  
**衝突處理：** Drive 平台層以 `BATS_DATA_SOURCE_REGISTRY.md` §6.5、§6.7、§10.5 為準；data_category 以 `BATS_TENANT_DATA_CLASSIFICATION.md` 為準；**Connector In/Out Scope 以本文件為準**。

> **Status: Planned for Phase 6** — Framework only；**不實作** Drive API 於本文件階段。  
> **不新增** `private_drive_folder_id`；**不修改** Registry Schema。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §2 | Runtime Source Architecture（P1 SSOT） |
| §3 | Connector Positioning |
| §4 | Supported File Types |
| §5 | Drive Path Scope |
| §6 | Per-Type Scope Matrix |
| §7 | Phase 6A～6E Roadmap |
| §8 | One Tenant One Folder Governance |
| §9 | Shared Layer Runtime Boundary |
| §10 | Object Versioning Boundary |
| §11 | Safety & Controlled Mode |
| §12 | Multi-Tenant & Multi-Industry |
| §13 | Explicit Exclusions |
| §14 | Cross References |

---

## 1. Purpose

### 1.1 文件定位

定義 **BDS Google Drive Source Connector** 之 **In Scope / Out of Scope**，供 Phase 6B～6E 實作前對齊。

**設計原則：**

- **治理方向為主** — 不寫死實作類別或 API 細節
- **多租戶、多產業、可擴充**
- **與 Structured Sheet Pipeline 分離**

### 1.2 輸入策略

| 載體 | 型態 | Connector |
|------|------|-----------|
| **Google Sheet** | Structured Knowledge **Input**（全層） | `BdsGoogleSheetReader`（Phase 4 ✅） |
| **Google Drive** | Unstructured Archive **only** | **Drive Source Connector**（Phase 6B+） |

> **Structured Knowledge = Google Sheet**（Tenant Private、Industry Shared、Global Shared）。FAQ／條列型知識 **不走** Drive Connector。見 `BATS_DATA_SYNC_POLICY.md` §18.7。

---

## 2. Runtime Source Architecture（P1 SSOT）

> **本節為 Phase 6 P1 最高優先治理決策。** 衝突時以本節與 `BATS_DATA_SYNC_POLICY.md` §18.5 為準。

### 2.1 正式角色定義

| 載體 | 角色 | 說明 |
|------|------|------|
| **Google Drive** | **Source of Truth（Archive Source）** | 保存原始非結構化檔案 |
| **GCS** | **Runtime Knowledge Source** | BATS／Gemini／Search 之權威讀取層 |

**BATS Runtime 不得依賴即時 Google Drive 搜尋。**

### 2.2 Google Drive 職責（僅 Archive）

Google Drive **只負責保存原始檔**：

| 類型 | 說明 |
|------|------|
| **PDF** | 行程說明、DM、合約 |
| **Image** | JPG、PNG、WebP 等 |
| **Excel** | `.xlsx`、`.xls`（非 Sheet Contract 維護者） |
| **Word** | `.docx`、`.doc` |
| **PowerPoint** | `.pptx`、`.ppt` |

Google Drive **不是**：

| 禁止角色 | 說明 |
|----------|------|
| **Runtime Source** | BATS 執行時 **不** 直讀 Drive |
| **Search Source** | 查詢 **不** 走 Drive API |
| **RAG Source** | 向量／檢索 **不** 直連 Drive |

### 2.3 GCS 職責（Runtime 存取）

GCS 負責 **Runtime 存取**（現行與未來）：

| 用途 | 階段 |
|------|------|
| **BATS Search** | 現行 |
| **Gemini Context** | 現行 |
| **Metadata Layer** | Phase 6+ |
| **Knowledge Layer** | Phase 5 ✅（Structured JSON） |
| **Future RAG** | 未來；**僅能從 GCS 取得資料** |
| **Future AI Recommendation** | 未來 |
| **Future AI Ranking** | 未來 |

> **未來 RAG 只能從 GCS 取得資料；不得直接從 Google Drive 建立 Runtime 流程。**

### 2.3.1 Structured Knowledge 與 Drive 分工

| 知識型態 | Input Source | Connector |
|----------|--------------|-----------|
| FAQ、條列、表格、服務型（含護照、簽證、入境、行李規定） | **Google Sheet** | Sheet Pipeline（Phase 4～6A） |
| PDF、Image、Word、PPT、DM 原件 | **Google Drive** | Drive Connector（Phase 6B+） |

**Industry / Global Shared 之 Structured Knowledge 亦以 Google Sheet 治理**；同步至 `shared/{industry}/knowledge/`、`shared/global/knowledge/` 為 **未來 BDS 管線**（非 Drive Connector）。

### 2.4 正確資料流

```text
Google Drive（Archive Source of Truth）
        ↓
BDS Sync（Phase 6B～6D）
        ↓
GCS Archive（+ Metadata Layer）
        ↓
Knowledge Layer（Structured JSON + 未來擴充）
        ↓
Future RAG / Search / Gemini Runtime
        ↓
LINE OA
```

### 2.5 禁止資料流

```text
❌ LINE OA → Google Drive API → PDF → Gemini
❌ Google Drive → RAG → Gemini
❌ BATS Runtime → Google Drive API（即時搜尋／列舉）
```

### 2.6 治理原因

| 原因 | 說明 |
|------|------|
| **效能** | Drive API 延遲高；GCS + cache 適合 Runtime |
| **API Quota** | 多租戶即時 Drive 查詢易觸發配額上限 |
| **多租戶隔離** | GCS prefix 以 `sno` 隔離；Drive 為 Archive 非查詢層 |
| **權限管理** | Runtime SA 讀 GCS；Drive 權限與 BDS 同步分離 |
| **可回滾** | GCS Safety Rule + report；不依賴 Drive 即時狀態 |
| **可驗證** | sync / validation report 可稽核 |
| **可快取** | Host A mirror GCS；Drive 不適合作查詢 cache |
| **可觀測性** | BDS report 集中於 GCS 管線；避免 Drive API 黑盒 |

---

## 3. Connector Positioning

```text
Google Drive（Archive Source of Truth）
        ↓
Drive Source Connector（Phase 6B～6E）
        ↓
Metadata Envelope（BATS_DRIVE_METADATA_CONTRACT.md）
        ↓
GCS Archive Promote（BATS_DRIVE_GCS_MAPPING.md）
        ↓
Reports / Preflight
```

| 角色 | 說明 |
|------|------|
| **讀取** | BDS 同步時列舉、取得 metadata、下載內容（受控）；**非** BATS Runtime |
| **轉換** | Phase 6 初版以 **Archive promote** 為主；深度解析 **下一階段** |
| **消費** | BATS **不** 直讀 Drive；讀 **GCS** |

---

## 4. Supported File Types

| 類型 | 說明 | Phase 6 方向 |
|------|------|--------------|
| **PDF** | 行程說明、DM、合約 | 支援列舉／Archive promote |
| **Image** | JPG、PNG、WebP 等 | 同上 |
| **Excel** | `.xlsx`、`.xls` | 支援；若為 **Sheet 維護之結構化知識** 走 Pipeline A |
| **Word** | `.docx`、`.doc` | 支援 Archive promote |
| **PowerPoint** | `.pptx`、`.ppt` | 支援 Archive promote |

**可擴充：** 新 MIME 類型透過本表 **增列** + `schema_version` 演進。

---

## 5. Drive Path Scope

**營運帳號：** `bbcshops88@gmail.com`

> **Platform 錨點：** 平台根節點 Folder ID 以 `BATS_DATA_SOURCE_REGISTRY.md` **§6.7 Platform Drive Registry** 為準（與 Tenant Registry **分離**）。Tenant Private 葉節點 ID 仍來自 Tenant Registry（§7）。

### 4.1 允許讀取路徑

| 層級 | 路徑 | Phase |
|------|------|-------|
| **Tenant Private** | `industries/{industry_code}/tenants/{tenant_key}/01_Private_Layer/` | 6B～6D |
| **Industry Shared** | `industries/{industry_code}/shared/02_Shared_Layer/` | 6E（須 Policy） |
| **Global Shared** | `global/02_Global_Shared_Layer/` | 6E（須 Policy） |

### 4.2 禁止路徑

| 禁止 | 說明 |
|------|------|
| `tenants/{tenant_key}/02_Shared_Layer/` | Shared 不得置於租戶下 |
| 他社 `tenants/{other_tenant_key}/` | 跨 tenant 隔離 |
| `registrations/` 之 Knowledge promote | Category C 邊界 |
| 未登錄 Registry 之路徑 | Registry Driven（Tenant §7 或 Platform §6.7） |

### 4.3 存取原則

| 原則 | 說明 |
|------|------|
| **Default Private** | 連線不意味公開 |
| **Shared ≠ Public** | 須 Explicit Share Policy |
| **最小權限** | Service Account 僅授權必要 prefix |

---

## 6. Per-Type Scope Matrix

| 類型 | In Scope（Phase 6） | Out of Scope（Phase 6） |
|------|----------------------|-------------------------|
| **PDF** | list、metadata、受控 promote 至 GCS archive | PDF 文字解析、RAG chunk |
| **Image** | list、metadata、受控 promote | OCR、視覺向量 |
| **Excel** | list、metadata、archive promote | 自動轉 Knowledge 五 JSON（屬 Sheet Contract） |
| **Word** | list、metadata、archive promote | 自動摘要入 Knowledge JSON |
| **PowerPoint** | list、metadata、archive promote | 投影片語意解析 |

**Category 對照（概念）：**

| 類型 | 常見 data_category |
|------|-------------------|
| PDF / Image / Excel（行程） | `itinerary_data` |
| Word / PPT（私有文件） | archive（Tenant Private） |
| Shared 層檔案 | `shared_knowledge`（archive） |

詳見 `BATS_TENANT_DATA_CLASSIFICATION.md` §2、§3。

---

## 7. Phase 6A～6E Roadmap

> **正式正名：** Phase 6 不再混用「Manual Sync」與「Drive Connector」雙重語意。  
> **SSOT：** `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` §3.1、`BATS_DATA_SYNC_RUNBOOK.md` §12.8、`BATS_DATA_SYNC_TEST_PLAN.md` §2.1

| 子階段 | 正式名稱 | 範圍 | Drive Connector |
|--------|----------|------|-----------------|
| **6A** | **Manual Sync Command** | Registry → Sheet → Validator → GCS Writer；CLI + Reports | **不含** |
| **6B** | **Drive Connector Read-only** | Drive API 唯讀 preflight；list `01_Private_Layer/` | ✅ 讀取 |
| **6C** | **Metadata Contract & File Classification** | Metadata envelope；`data_category` 分類 | ✅ 產出 Metadata |
| **6D** | **Drive → GCS Controlled Promote** | 受控 promote Tenant Private → GCS Archive | ✅ 寫入 GCS Archive |
| **6E** | **Verification & Close-out** | Shared 路徑框架；read-back；close-out report | ✅ 須 Policy 啟用 |

```text
Phase 6A（Sheet E2E）──→ Phase 6B（Drive Read）──→ Phase 6C（Metadata）
                                                          │
                                                          ↓
                                              Phase 6D（Promote）──→ Phase 6E（Close-out）
```

---

## 8. One Tenant One Folder Governance

| 原則 | 說明 |
|------|------|
| **One Tenant One Folder** | 每個 Tenant **必須** 擁有獨立 `industries/{industry_code}/tenants/{tenant_key}/01_Private_Layer/` |
| **唯一 Tenant Archive Source** | 該路徑為該租戶 **唯一** 私有非結構化 Archive 來源 |
| **禁止跨 Tenant 共用 Folder** | 多租戶共用同一 Folder → Tenant Isolation 違規 |
| **禁止跨 Tenant 存放私有資料** | 他社檔案 **不得** 置於其他 tenant 的 `01_Private_Layer/` |

**SSOT 交叉引用：** `BATS_DATA_SOURCE_REGISTRY.md` §6.5、§6.7、`BATS_TENANT_DRIVE_ONBOARDING_POLICY.md` §4、`BATS_DATA_OWNERSHIP_POLICY.md` §2.6

---

## 9. Shared Layer Runtime Boundary

| 原則 | 說明 |
|------|------|
| **Shared Layer ≠ Public Layer** | Shared 為受控 fallback 語意，**不是** 對外公開層 |
| **Shared Layer Default Private** | 預設不對外、不對所有 Tenant 開放 |
| **Shared Layer 預設不進入 Runtime** | 未經啟用前 **不** 寫入 GCS Knowledge／Metadata |
| **須 Registry + Policy 明確啟用** | 才能進入 GCS、Metadata Layer、Knowledge Layer、Future RAG |
| **不得自動同步至所有 Tenant** | 禁止「全平台 Shared 自動下發」 |

---

## 10. Object Versioning Boundary

| 項目 | 規則 |
|------|------|
| **GCS Object Versioning** | MVP **維持 OFF** |
| **Phase 6** | **不得依賴** Object Versioning |
| **Rollback** | 依 Safety Rule + `source_revision` + 手動還原（見 Implementation Plan） |
| **再評估啟用時機** | 自動同步、排程同步、AI JSON 更新、正式 SaaS 多租戶營運 |

---

## 11. Safety & Controlled Mode

| 規則 | 說明 |
|------|------|
| **雙重門禁** | 參照 Phase 5 GCS 寫入門禁（promote 時） |
| **Validation Fail** | 不 promote、不覆蓋 |
| **單一 tenant** | 初版受控單 `sno` |
| **禁止 delete** | 不以刪除 Drive／GCS 物件作為同步策略 |
| **禁止覆蓋 Structured JSON** | Drive Connector **不得** 寫入 `tenants/{sno}/knowledge/*.json`（Sheet 專用） |

---

## 12. Multi-Tenant & Multi-Industry

| 原則 | 說明 |
|------|------|
| **Registry Driven** | 路徑與 ID 來自 Registry；禁止 hardcode `travel_b` |
| **產業擴展** | 新 `industry_code` → 新 `industries/{industry_code}/shared/02_Shared_Layer/` |
| **可擴充 Connector** | 新檔案類型增列 §5；核心隔離規則不變 |
| **Pilot** | `travel_b` 為驗證用例，非架構特例 |

---

## 13. Explicit Exclusions

| 排除 | 說明 |
|------|------|
| **RAG / Embedding / Vector DB** | Phase 6 **不做** |
| **AI Ranking / Recommendation** | Phase 6 **不做** |
| **`private_drive_folder_id`** | 不引入 Registry |
| **Registry Schema 修改** | 本階段不做 |
| **Cron / LINE OA** | 非 Connector 範圍 |
| **Shared Layer 自動啟用** | 須 Registry + Policy |
| **BATS runtime 直讀 Drive** | 違反 Archive Layer |

---

## 14. Cross References

| 文件 | 關係 |
|------|------|
| `BATS_DATA_SYNC_POLICY.md` §18.5 | Runtime Source Architecture SSOT |
| `BATS_DATA_SOURCE_REGISTRY.md` §6.5、§6.7、§10.5 | Drive 平台層、Platform Registry、Framework |
| `BATS_DRIVE_METADATA_CONTRACT.md` | 輸出 Metadata |
| `BATS_DRIVE_GCS_MAPPING.md` | Archive / Metadata / Knowledge 三層對照 |
| `BATS_TENANT_DRIVE_ONBOARDING_POLICY.md` | 新租戶 Folder |
| `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` §3.1 | Phase 6A～6E Roadmap |
| `BATS_DATA_SYNC_RUNBOOK.md` §12.8 | 維運對照 |
| `BATS_DATA_SYNC_TEST_PLAN.md` §2.1 | Phase 6A～6E 測試 |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.4** | 2026-06-06 | Phase 6B-2C-1：§5 Platform 錨點 cross-ref §6.7 |
| **v1.3** | 2026-06-06 | Phase 6B-1D：§5 Drive Path 對齊 Industry First（`industries/{industry_code}/...`） |
| **v1.2** | 2026-06-10 | §2.3.1 Structured Knowledge vs Drive；Sheet = 全層 Structured Input |
| **v1.1** | 2026-06-10 | P1 Final：Runtime Source Architecture、6A～6E 正名、One Tenant One Folder、Shared Runtime Boundary、Object Versioning |
| **v1.0** | 2026-06-10 | Phase 6 P1：Drive Connector Scope 治理（Planned） |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | Draft — Planned for Phase 6 |
| **實作狀態** | 未開始 |
| **Drive API** | 未實作 |
