# BATS_TENANT_DRIVE_ONBOARDING_POLICY.md

**專案：** BBC AI SaaS / BATS / BDS / Travel Data Center  
**定位：** L2 維運治理層 — 新租戶 Google Drive Folder Onboarding 政策（Phase 6 P1）  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_DATA_SOURCE_REGISTRY.md`、`BATS_DATA_OWNERSHIP_POLICY.md`、`BATS_TENANT_DATA_CLASSIFICATION.md`、`BATS_DRIVE_CONNECTOR_SCOPE.md`、`BATS_DATA_SYNC_RUNBOOK.md`  
**適用範圍：** 新旅行社上架及未來產業租戶（`travel`、`hotel`、`restaurant` 等）  
**適用對象：** BBC Admin、維運人員、開發者  
**衝突處理：** Drive 平台層架構以 `BATS_DATA_SOURCE_REGISTRY.md` §6.5 為準；Registry 契約以 §7 為準；**Onboarding 流程與檢查以本文件為準**。

> **Status: Planned for Phase 6** — 流程治理；**不修改** Registry JSON Schema；**不新增** `private_drive_folder_id`。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §2 | Onboarding Scope |
| §3 | Prerequisites |
| §4 | Formal Onboarding Flow（正式流程） |
| §5 | Folder Provisioning |
| §6 | Registry Alignment |
| §7 | Access & Permissions |
| §8 | Verification Checklist |
| §9 | Pilot vs Production |
| §10 | Multi-Industry Expansion |
| §11 | One Tenant One Folder Governance |
| §12 | Explicit Exclusions |
| §13 | Cross References |

---

## 1. Purpose

### 1.1 文件定位

定義 **新旅行社（Tenant）上架** 時，Google Drive 資料夾配置、Registry 對照與驗證之 **治理流程**。

**目標：**

- **一旅行社一專屬 Folder**
- 與 GCS、`private_knowledge_sheet_id` 對齊
- 支援 **2～200 Tenant**、多產業擴充
- **BBC Platform Google Drive**（`bbcshops88@gmail.com`）為唯一營運帳號
- **不寫死** 工具或 UI；流程可手動或未來 Wizard 執行

### 1.2 與其他 Onboarding 文件分工

| 文件 | 職責 |
|------|------|
| `TENANT_TRAVEL_B_ONBOARDING_CHECKLIST.md` | 特定租戶 staging 實務 |
| **本文件** | **平台級** Drive Folder Onboarding 政策 |
| `BATS_DATA_SOURCE_REGISTRY.md` §7 | Registry 欄位契約 |

---

## 2. Onboarding Scope

### 2.1 In Scope

| 項目 | 說明 |
|------|------|
| **Tenant Private Drive** | `tenants/{tenant_key}/01_Private_Layer/` |
| **Registry 對照** | `sno`、`tenant_key`、`industry_code`、現行 Registry 欄位 |
| **Service Account 授權** | 最小權限原則 |
| **Pilot 驗證** | 受控環境 read/list 或 sync dry-run |

### 2.2 Out of Scope（本階段）

| 排除 | 說明 |
|------|------|
| **Shared Layer 自動建立** | 產業 Shared 由平台維護；非每租戶 Onboarding 必做 |
| **Onboarding Wizard UI** | 規劃中；本文件僅定流程 |
| **Registry Schema 修訂** | 不新增欄位 |
| **Drive API 實作** | Phase 6B 後 |

---

## 3. Prerequisites

| # | 要件 | 說明 |
|---|------|------|
| 1 | **Tenant 決策完成** | `tenant_key`、`sno`、`industry_code` 已分配 |
| 2 | **營運 Drive 帳號** | `bbcshops88@gmail.com` 可管理 `tenants/` |
| 3 | **Structured Sheet 就緒** | `private_knowledge_sheet_id` 已建立（Pipeline A） |
| 4 | **GCS Prefix 已知** | `tenants/{sno}/` |
| 5 | **Default Private** | 新建 Folder **不得** 預設公開連結 |

---

## 4. Formal Onboarding Flow（正式流程）

> **適用：** 2～200 Tenant；多產業；BBC Platform Google Drive（`bbcshops88@gmail.com`）

| 步驟 | 動作 | 產出／驗證 |
|------|------|------------|
| **1** | **建立 Tenant Folder** | `tenants/{tenant_key}/01_Private_Layer/` 存在；One Tenant One Folder |
| **2** | **建立 Google Sheet** | `private_knowledge_sheet_id` 就緒；符合 `BATS_DATA_CONTRACT.md` 5 Tab |
| **3** | **授權 Service Account** | `bbc-ai-sync@...` 具最小權限讀取 Drive / Sheet |
| **4** | **驗證 Folder** | 路徑、隔離、Default Private；無 `02_Shared_Layer` 於租戶下 |
| **5** | **驗證 Sheet** | Phase 4 Reader PASS（5 Required Tabs） |
| **6** | **啟用 BDS** | Registry `enabled=true`；可執行 Phase 6A Manual Sync（受控） |

```text
1. Tenant Folder  →  2. Google Sheet  →  3. SA 授權
        ↓                    ↓                  ↓
4. 驗證 Folder  →  5. 驗證 Sheet  →  6. 啟用 BDS
```

| 原則 | 說明 |
|------|------|
| **順序不可跳步** | 未驗證 Folder / Sheet 前 **不得** 啟用 GCS 寫入 |
| **Registry Driven** | 步驟 6 前須完成 Registry 對照（§6） |
| **Drive Connector 非必要** | 步驟 1～6 **不含** Phase 6B+ Drive API；Drive Onboarding 與 Connector 可分階段 |
| **可擴充** | 未來 Wizard 實作本流程；核心規則不變 |

---

## 5. Folder Provisioning

### 5.1 正式路徑

```text
bbcshops88@gmail.com
└── tenants/
    └── {tenant_key}/
        └── 01_Private_Layer/
```

| 原則 | 說明 |
|------|------|
| **One Tenant One Folder** | 每個 `tenant_key` 獨立根資料夾 |
| **僅 Private Layer** | 租戶下 **不建立** `02_Shared_Layer` |
| **命名** | `tenant_key` 與 Registry `tenant_name` 對齊（概念）；**不** 使用 `sno` 作 Drive 資料夾名（建議） |

### 5.2 現行 Pilot 範例

| tenant_key | 說明 |
|------------|------|
| `travel_a` | Pilot 租戶 A |
| `travel_b` | Pilot 租戶 B |
| `travel_c` | Pilot 租戶 C |

### 5.3 禁止事項

| 禁止 | 說明 |
|------|------|
| 多租戶共用同一 Folder | Tenant Isolation 違規 |
| 在租戶下建立 Shared 子資料夾 | 違反平台層架構 |
| 以公開連結作為預設 | 違反 Default Private |

---

## 6. Registry Alignment

### 6.1 對照原則

Onboarding 完成後，Registry Entry 須能對照：

| Registry 語意 | 對照目標 |
|---------------|----------|
| `sno` | GCS `tenants/{sno}/` |
| `tenant_name` / `tenant_key` | Drive `tenants/{tenant_key}/` |
| `industry_code` | Shared fallback `shared/{industry_code}/`（GCS） |
| `drive_root_folder_id` | `tenants/{tenant_key}/` 根 ID |
| `private_knowledge_folder_id` | `01_Private_Layer/` ID（邏輯對照） |
| `private_knowledge_sheet_id` | Structured Pipeline A |

> **Registry JSON Schema 不變**；**不新增** `private_drive_folder_id`。

### 6.2 建議登錄順序

對齊 §4 正式流程步驟 1～6：

```text
1. 建立 Drive Folder → drive_root_folder_id / private_knowledge_folder_id
2. 建立 Sheet → private_knowledge_sheet_id
3. SA 授權
4. 驗證 Folder
5. 驗證 Sheet
6. enabled=true；sno ↔ tenant_key ↔ gcs_prefix 一致
```

---

## 7. Access & Permissions

| 角色 | 建議權限 |
|------|----------|
| **Service Account**（`bbc-ai-sync@...`） | 受控讀取／寫入 `tenants/{tenant_key}/`；最小權限 |
| **BBC Admin** | 平台 `tenants/` 管理 |
| **租戶人員** | 可檢視／下載（政策另定）；**不可** 直接觸發 BDS 同步 |
| **Shared 資料夾** | 須 **Explicit Share Policy**；非 Onboarding 預設步驟 |

---

## 8. Verification Checklist

Onboarding 完成前建議確認（對照 §4 步驟 4～6）：

| # | 檢查項 | 預期 |
|---|--------|------|
| 1 | Drive 路徑存在 | `tenants/{tenant_key}/01_Private_Layer/` |
| 2 | 無 Shared 在租戶下 | 無 `02_Shared_Layer` |
| 3 | Registry `sno` 一致 | 與 GCS prefix 對照 |
| 4 | Sheet ID 可讀 | Phase 4 Reader PASS（可選） |
| 5 | SA 可 list 該 prefix | Phase 6B Preflight（未來） |
| 6 | Default Private | 非公開連結 |
| 7 | 無 PII 測試檔於 Knowledge 路徑 | Category 邊界 |

---

## 9. Pilot vs Production

| 項目 | Pilot | Production |
|------|-------|------------|
| **租戶範例** | `travel_b`（`5f99b8d665e8444d`） | 新 Registry entry |
| **GCS 寫入** | 須雙重門禁 | 同上 + 維運批准 |
| **Drive promote** | Phase 6D 後啟用 | 受控 rollout |
| **hardcode** | **禁止** | **禁止** |

---

## 10. Multi-Industry Expansion

| 原則 | 說明 |
|------|------|
| **產業代碼** | 每 Tenant 必填 `industry_code`（如 `travel`、`hotel`） |
| **Shared 資料夾** | 產業層 `shared/{industry_code}/02_Shared_Layer/` 由 **平台** 維護；**非** 每租戶 Onboarding 建立 |
| **同架構** | 新產業僅擴充 `industry_code` + Shared 路徑；Tenant Onboarding **流程不變** |
| **可擴充** | 未來 Onboarding Wizard 可實作本流程；**不改** 核心規則 |

---

## 11. One Tenant One Folder Governance

| 原則 | 說明 |
|------|------|
| **One Tenant One Folder** | 每 Tenant **必須** 擁有 `tenants/{tenant_key}/01_Private_Layer/` |
| **唯一 Tenant Archive Source** | 作為該租戶非結構化 Archive 唯一來源 |
| **禁止跨 Tenant 共用 Folder** | Onboarding 驗證項（§8 #1） |
| **禁止跨 Tenant 存放私有資料** | 違反 Tenant Isolation |

---

## 12. Explicit Exclusions

| 排除 | 說明 |
|------|------|
| **`private_drive_folder_id`** | 不引入 |
| **RAG / Vector 索引設置** | Phase 6 不做 |
| **修改 GCS Knowledge 路徑** | 不變 |
| **Cron 自動 Onboarding** | v1 手動 |

---

## 13. Cross References

| 文件 | 關係 |
|------|------|
| `BATS_DATA_SOURCE_REGISTRY.md` §6.5、§7 | 路徑與 Registry |
| `BATS_DATA_OWNERSHIP_POLICY.md` §2.6、§2.7 | Folder 治理 |
| `BATS_DRIVE_CONNECTOR_SCOPE.md` §7～§8 | Phase 6A～6E、One Tenant One Folder |
| `BATS_DATA_SYNC_RUNBOOK.md` §12.8 | 維運 Onboarding 對照 |
| `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` §3.1 | Phase 6A Manual Sync |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.1** | 2026-06-10 | P1 Final：6 步驟正式流程、2～200 Tenant、One Tenant One Folder |
| **v1.0** | 2026-06-10 | Phase 6 P1：Tenant Drive Onboarding 政策（Planned） |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | Draft — Planned for Phase 6 |
| **Registry Schema** | **不變** |
| **實作狀態** | 未開始 |
