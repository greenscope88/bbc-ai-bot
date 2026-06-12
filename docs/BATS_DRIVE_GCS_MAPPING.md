# BATS_DRIVE_GCS_MAPPING.md

**專案：** BBC AI SaaS / BATS / BDS / Travel Data Center  
**定位：** L2 架構規劃層 — Google Drive → GCS 對照與 Promote 治理（Phase 6 P1）  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_DATA_SOURCE_REGISTRY.md`、`BATS_DATA_SYNC_POLICY.md`、`BATS_DRIVE_METADATA_CONTRACT.md`、`BATS_TENANT_DATA_CLASSIFICATION.md`、`BATS_DATA_OWNERSHIP_POLICY.md`、`BATS_DATA_CONTRACT.md`  
**適用範圍：** 所有租戶及未來產業（`travel`、`hotel`、`restaurant`、`beauty`、`education`、`medical` 等）  
**適用對象：** ChatGPT、Cursor、開發者、維運人員  
**衝突處理：** GCS 正式結構以 `BATS_DATA_SYNC_POLICY.md` §11 為準；寫入邊界以 `BATS_DATA_OWNERSHIP_POLICY.md` §3 為準；**Drive → GCS 對照與 promote 方向以本文件為準**。

> **Status: Planned for Phase 6** — 治理方向；**不修改** 既有 GCS Knowledge 五 JSON 路徑。  
> **Structured Sheet → GCS** 仍由 Phase 4～5 管線處理；本文件僅涵蓋 **Unstructured Drive**。

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
| §8 | Promote Rules |
| §9 | Archive-Only Rules |
| §10 | One Tenant One Folder |
| §11 | Shared Layer Runtime Boundary |
| §12 | Object Versioning Boundary |
| §13 | Safety Boundary |
| §14 | Multi-Tenant & Multi-Industry |
| §15 | Explicit Exclusions |
| §16 | Cross References |

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

### 6.1 Drive Archive Object 路徑（Phase 6 建議方向）

非結構化檔案 promote 至 GCS 時，建議採 **Archive 專用 prefix**（與 Knowledge JSON **分離**）：

| 層級 | 建議 GCS Archive 路徑（概念） |
|------|------------------------------|
| **Tenant Private** | `tenants/{sno}/archive/` 或 `tenants/{sno}/uploads/`（Phase 6 實作前定案） |
| **Industry Shared** | `shared/{industry_code}/archive/` |
| **Global Shared** | `shared/global/archive/` |

> 正式 prefix 於 Phase 6D 實作前須修訂 `BATS_DATA_SYNC_POLICY.md` §11；**本文件僅標示治理方向**。

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

## 8. Promote Rules

| 規則 | 說明 |
|------|------|
| **雙重門禁** | 參照 Phase 5：`BDS_DRY_RUN` + `BDS_GCS_WRITE_ENABLED` + 目標 `sno` 一致 |
| **Metadata 必備** | 須符合 `BATS_DRIVE_METADATA_CONTRACT.md` 必填語意 |
| **Validation Gate** | Metadata / 路徑 / category 驗證失敗 → **不 promote** |
| **單次受控** | Phase 6 初版建議單 tenant、單次作業；不批次多租戶 |
| **冪等方向** | 相同 `content_hash` 可視為已同步；細節於實作 Phase 定案 |

---

## 9. Archive-Only Rules

下列情況 **僅留 Google Drive Archive**，**不** promote 至 GCS Knowledge：

| 情況 | 說明 |
|------|------|
| **`customer_registration`** | Category C；禁止進 `knowledge/` |
| **Validation Fail** | Safety Rule |
| **未啟用 Shared Policy** | Shared 檔案不得 promote 至他 tenant 可讀路徑 |
| **dry_run / flag 關閉** | 零 GCS 寫入 |
| **未知 MIME / 未登錄類型** | 拒絕或僅記錄於 report（實作 Phase 定案） |

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

| 項目 | 規則 |
|------|------|
| **GCS Object Versioning** | MVP **維持 OFF** |
| **Phase 6** | promote / rollback **不得依賴** Object Versioning |
| **再評估時機** | 自動同步、排程同步、AI JSON 更新、正式 SaaS 多租戶營運 |

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
| `BATS_DATA_SYNC_POLICY.md` §11、§18.5 | GCS 結構、Runtime Source |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.3** | 2026-06-06 | Phase 6B-1D：§5 Drive Path 對齊 Industry First（`industries/{industry_code}/...`） |
| **v1.2** | 2026-06-10 | Pipeline A1/A2/A3 Structured Sheet 三層；Structured Knowledge = Google Sheet |
| **v1.1** | 2026-06-10 | P1 Final：三層分離、Drive→GCS Mapping、Runtime Source、One Tenant One Folder、Shared Boundary、Object Versioning |
| **v1.0** | 2026-06-10 | Phase 6 P1：Drive → GCS Mapping 治理方向（Planned） |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | Draft — Planned for Phase 6 |
| **GCS Knowledge 五 JSON** | **不變** |
| **實作狀態** | 未開始 |
