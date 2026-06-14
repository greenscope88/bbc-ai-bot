# BATS_DATA_OWNERSHIP_POLICY.md

**專案：** BBC AI SaaS / BATS / BDS / Travel Data Center  
**定位：** L1 架構政策層 — BDS Data Ownership & Override Rule 正式 SSOT  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_DATA_SYNC_POLICY.md`、`BATS_DATA_SOURCE_REGISTRY.md`、`BATS_DATA_CONTRACT.md`  
**適用範圍：** `travel_a`、`travel_b`、`travel_c`、`travel_d` 及未來 **旅遊／飯店／餐廳／美業／零售／教育／醫療** 等產業租戶  
**適用對象：** ChatGPT、Cursor、開發者、維運人員、資料維護人員、BBC Admin  
**衝突處理：** 同步模式與觸發以 `BATS_DATA_SYNC_POLICY.md` 為準；Knowledge Resolution Order 與 Registry 結構以 `BATS_DATA_SOURCE_REGISTRY.md` 為準；資料格式以 `BATS_DATA_CONTRACT.md` 為準；**資料歸屬、維護責任、寫入邊界、覆蓋權限以本文件為準**。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §2 | Ownership Layers |
| §2.5 | Default Access Policy |
| §2.6 | Google Drive Folder Governance |
| §2.7 | Google Drive Platform Layer Architecture |
| §3 | Write Boundary |
| §4 | Override Rule |
| §5 | BDS Sync Ownership Rule |
| §6 | Industry Expansion Rule |
| §7 | Future Roadmap |
| §8 | Cross Reference |

---

## 1. Purpose

### 1.1 文件定位

本文件建立 **BDS Data Ownership Policy** 正式 SSOT，定義多租戶知識資料之 **歸屬（Ownership）**、**維護責任（Stewardship）** 與 **覆蓋邊界（Override Boundary）**。

本文件回答：

| 問題 | 本文件章節 |
|------|------------|
| 誰擁有資料？ | §2 Ownership Layers |
| 誰可以修改？ | §2、§3 Write Boundary |
| 誰可以同步？ | §5 BDS Sync Ownership Rule |
| 誰可以覆蓋？ | §4 Override Rule |
| Tenant / Industry / Global 權責邊界？ | §2～§4 |
| Google Drive 資料夾預設是否共享？ | §2.5 Default Access Policy |

### 1.2 核心術語

| 術語 | 定義 |
|------|------|
| **Data Ownership** | 資料之 **法定／營運歸屬主體**；決定誰對該層資料負最終責任 |
| **Data Stewardship** | 資料之 **日常維護責任**；決定誰可編輯來源、誰可觸發同步 |
| **Override Boundary** | **讀取解析時** 上層知識覆蓋下層之邊界；決定誰可覆蓋誰 |

### 1.3 與其他 BDS SSOT 分工

| 文件 | 職責 | 隱喻 |
|------|------|------|
| **`BATS_DATA_SYNC_POLICY.md`** | 怎麼同步、何時同步、Mode B、Safety Rule | 同步管線 |
| **`BATS_DATA_SOURCE_REGISTRY.md`** | 來源登錄、`industry_code`、Knowledge Resolution Order | 來源地圖 |
| **`BATS_DATA_CONTRACT.md`** | Tab、欄位、JSON 格式、Validation | 資料形狀 |
| **`BATS_DATA_OWNERSHIP_POLICY.md`（本文件）** | 誰擁有、誰可寫、誰可同步、誰可覆蓋 | 權責邊界 |

```text
Ownership Policy  →  誰的資料、誰能改、誰能蓋
Sync Policy       →  Mode B、上傳觸發、不得覆蓋正式 JSON
Source Registry   →  Sheet ID、路徑、三層解析順序
Data Contract     →  5 Tab / 5 JSON 欄位契約
```

### 1.4 本文件不做

| 不做 | 說明 |
|------|------|
| 定義 RBAC / 權限系統實作 | 見 §7 Future Roadmap |
| 定義 Google API / Service Account 細節 | 見 `BATS_DATA_SYNC_POLICY.md` §10、§22 |
| 定義 Sheet 欄位與 JSON schema | 見 `BATS_DATA_CONTRACT.md` |
| 定義 Registry 欄位契約 | 見 `BATS_DATA_SOURCE_REGISTRY.md` §7 |
| 實作 BDS 程式 | 實作階段另開 Phase |

---

## 2. Ownership Layers

BDS Knowledge Layer 分為 **三層**；每層有明確 **Owner** 與 **Steward**。

### 2.1 Tenant Owned（租戶私有層）

| 項目 | 說明 |
|------|------|
| **層級名稱** | Tenant Layer |
| **data_category** | `tenant_private_knowledge` |
| **GCS 路徑** | `tenants/{sno}/knowledge/` |
| **Drive 對應** | `industries/{industry_code}/tenants/{tenant_key}/01_Private_Layer/`（Archive；見 §2.7） |

#### Owner（歸屬主體）

| 角色 | 說明 |
|------|------|
| **Tenant** | 該 `sno` 對應之旅行社／租戶；對其私有知識負內容責任 |
| **BBC Admin** | BBC 平台管理方；負營運監督、隔離稽核、緊急處置責任 |

#### Stewardship（維護責任）

| 角色 | 可執行 | 不可執行 |
|------|--------|----------|
| **Tenant** | 維護自家 Google Sheet（`private_knowledge_sheet_id`）；經上傳頁觸發同步 | 寫入他社 `tenants/{other_sno}/`；寫入 `shared/` |
| **BBC Admin** | 代管、稽核、停用同步、Registry 登錄 | 未經授權修改租戶商業內容 |

#### 典型內容

公司資料、QA、服務項目、特殊價格、外部商品連結等 **該社專屬** 客服知識。

---

### 2.2 Industry Shared Owned（產業共用層）

| 項目 | 說明 |
|------|------|
| **層級名稱** | Industry Shared Layer |
| **data_category** | `shared_knowledge` |
| **GCS 路徑** | `shared/{industry_code}/knowledge/` |
| **Drive 對應（Archive）** | `industries/{industry_code}/shared/02_Shared_Layer/` |
| **`industry_code` 來源** | Tenant Registry（見 `BATS_DATA_SOURCE_REGISTRY.md` §7.3）；Platform Registry 解析 Shared folder ID（§6.7） |

#### Owner（歸屬主體）

| 角色 | 說明 |
|------|------|
| **BBC Industry Maintainer** | 負責該產業共用知識內容維護之 BBC 產業維運角色 |
| **BBC Admin** | 平台管理方；負跨租戶一致性與發布監督 |

#### Stewardship（維護責任）

| 角色 | 可執行 | 不可執行 |
|------|--------|----------|
| **BBC Industry Maintainer** | 維護產業共用知識來源；未來版本發布至 `shared/{industry_code}/knowledge/` | 寫入任一 `tenants/{sno}/knowledge/`；覆寫租戶已存在答案 |
| **BBC Admin** | 核准產業知識發布、Registry `industry_code` 管理 | 將產業知識偽裝為租戶私有資料 |

#### 典型內容

產業通用 QA、標準收費參考、法規說明、跨租戶 fallback 知識。

#### 現行產業路徑範例

| `industry_code` | 路徑 |
|-----------------|------|
| `travel` | `shared/travel/knowledge/` |
| `hotel` | `shared/hotel/knowledge/` |
| `restaurant` | `shared/restaurant/knowledge/` |

---

### 2.3 Global Shared Owned（全域共用層）

| 項目 | 說明 |
|------|------|
| **層級名稱** | Global Shared Layer |
| **data_category** | `shared_knowledge` |
| **GCS 路徑** | `shared/global/knowledge/` |
| **Drive 對應（Archive）** | `global/02_Global_Shared_Layer/` |

> **注意：** `global` 為 Layer 3 專用路徑，**不作** `industry_code` 填入 Layer 2（見 `BATS_DATA_SOURCE_REGISTRY.md` §4.3）。

#### Owner（歸屬主體）

| 角色 | 說明 |
|------|------|
| **BBC Platform** | BBC 平台整體；負跨產業通用知識歸屬 |
| **BBC Admin** | 平台管理方；負全域知識發布與版本監督 |

#### Stewardship（維護責任）

| 角色 | 可執行 | 不可執行 |
|------|--------|----------|
| **BBC Platform / Admin** | 維護跨產業 fallback 知識；未來版本發布至 `shared/global/knowledge/` | 寫入 `tenants/{sno}/knowledge/`；覆寫 Tenant 或 Industry 已命中答案 |

#### 典型內容

跨產業通用政策、平台級客服說明、最終 fallback 知識。

---

### 2.4 三層對照總表

| 層級 | GCS 路徑 | Owner | Steward（主要） | BDS v1 MVP 同步 |
|------|----------|-------|-----------------|-----------------|
| **Tenant** | `tenants/{sno}/knowledge/` | Tenant + BBC Admin | Tenant | **允許** |
| **Industry Shared** | `shared/{industry_code}/knowledge/` | BBC Industry Maintainer + BBC Admin | BBC Industry Maintainer | **不允許** |
| **Global Shared** | `shared/global/knowledge/` | BBC Platform + BBC Admin | BBC Platform / Admin | **不允許** |

---

### 2.5 Default Access Policy

#### 2.5.1 正式原則

**所有 Google Drive 資料夾（含 Shared Layer Archive）預設為 Private（Default Private）。**

| 項目 | 規則 |
|------|------|
| **預設** | **Default = Private** — 未經明確政策前，**不得** 視為對外公開或跨租戶可讀 |
| **適用範圍** | 租戶 `industries/{industry_code}/tenants/{tenant_key}/01_Private_Layer/`、產業 `industries/{industry_code}/shared/02_Shared_Layer/`、全球 `global/02_Global_Shared_Layer/`、BDS 協作用 Sheet 來源資料夾 |
| **≠ Public** | Private 指 **存取控制預設**；與 Knowledge 語意之「Shared Layer」**不同概念**（見 `BATS_TENANT_DATA_CLASSIFICATION.md` §1.6.2） |

#### 2.5.2 Google Drive Folder 預設

```text
Google Drive Folder
        ↓
Default = Private（僅 Owner / 明確授權角色可存取）
        ↓
禁止「資料夾在 shared 路徑下即公開」之假設
```

| 資料夾類型 | Layer | 預設存取 |
|------------|-------|----------|
| `industries/{industry_code}/tenants/{tenant_key}/01_Private_Layer/` | Tenant Private Layer | **Private** |
| `industries/{industry_code}/shared/02_Shared_Layer/` | Industry Shared Layer（**產業層**） | **Private** |
| `global/02_Global_Shared_Layer/` | Global Shared Layer（**平台層**） | **Private** |
| `registrations/` | Category C（平台層 Archive） | **Private**（含 PII 管控） |

#### 2.5.3 啟用共享之唯一路徑

**必須透過明確 Registry / Policy 啟用共享**；禁止僅依路徑命名或慣例開放。

| 步驟 | 要件 |
|------|------|
| 1 | **Registry 登錄** — 於 `BATS_DATA_SOURCE_REGISTRY.md` 登錄 Drive Folder ID、`data_category`、`industry_code`（若適用） |
| 2 | **Ownership 對齊** — 確認 Owner / Steward 與 §2.1～§2.3 一致 |
| 3 | **Explicit Share Policy** — 依 `BATS_SHARED_KNOWLEDGE_CONTRACT.md` §3.4.6 定義受控對象（如 Service Account、指定維護角色） |
| 4 | **禁止隱式公開** | 不得將 Drive 連結設為「知道連結的任何人」作為 BDS 預設 |

#### 2.5.4 與 GCS / BATS 邊界

| 層級 | Drive（Archive） | GCS（Knowledge） |
|------|------------------|------------------|
| **預設可見性** | Default Private | 受控 prefix；非公開 bucket |
| **BATS 讀取** | **不** 於 runtime 直接讀 Drive | 讀取 `tenants/{sno}/knowledge/`、`shared/` JSON |
| **BDS 同步** | 可讀取已授權之 Sheet／檔案 | 寫入對應 GCS 路徑 |

#### 2.5.5 禁止行為

| 禁止 | 說明 |
|------|------|
| **預設公開 Shared 資料夾** | 違反 Default Private |
| **未登錄 Registry 即共享** | 違反 Registry Driven |
| **租戶自行開放他社可讀** | 違反 Tenant Isolation（`BATS_DATA_SYNC_POLICY.md` §10） |
| **將 Shared Layer 等同 Public Layer** | 違反 `BATS_TENANT_DATA_CLASSIFICATION.md` §1.6.2 |

---

### 2.6 Google Drive Folder Governance

#### 2.6.1 定位

本節補強 **Google Drive 資料夾治理原則**，供 Phase 6 Drive Connector Framework 對齊；與 §2.5 Default Access Policy **互補**。**Phase 6 前不引入 `private_drive_folder_id`。**

#### 2.6.2 正式原則

| 原則 | 說明 |
|------|------|
| **One Tenant One Folder** | 每個 Tenant **僅對應一個** 專屬 Drive Folder；禁止多租戶共用同一 Folder |
| **Folder Default = Private** | 新建或未明確政策前，Folder **預設 Private** |
| **禁止跨 Tenant 共用 Folder** | 違反 Tenant Isolation；見 `BATS_DATA_SYNC_POLICY.md` §10 |
| **Shared Layer 須明確 Policy 啟用** | 產業／全球 Shared 資料夾 **不得** 僅因命名或路徑慣例視為可跨租戶讀取 |

```text
Tenant A ──→ Drive Folder A（Private）
Tenant B ──→ Drive Folder B（Private）
        ✗
禁止 Tenant A / B 共用同一 Folder
```

#### 2.6.3 與 Registry / Phase 邊界

| 項目 | 規則 |
|------|------|
| **Registry 登錄** | Drive Folder 正式 ID **待 Phase 6**；現行不新增 `private_drive_folder_id` |
| **Structured Sheet** | `private_knowledge_sheet_id` 語意 **不變** |
| **BDS Phase 5** | **不** 存取 Google Drive |
| **Framework SSOT** | `BATS_DATA_SOURCE_REGISTRY.md` §10.5 |

#### 2.6.4 禁止行為

| 禁止 | 說明 |
|------|------|
| **跨 Tenant 共用 Drive Folder** | 資料隔離違規 |
| **未經 Policy 啟用 Shared 可見性** | 違反 Explicit Share 路徑（§2.5.3） |
| **將 Drive Folder 預設設為公開連結** | 違反 Default Private |

---

### 2.7 Google Drive Platform Layer Architecture

#### 2.7.1 定位

本節為 **Phase 6 Pre-Governance** 正式 SSOT，定義營運 Google Drive 之平台層資料夾樹。**Shared Layer 不屬於單一旅行社。**

**營運帳號：** `bbcshops88@gmail.com`  
**交叉引用：** `BATS_DATA_SOURCE_REGISTRY.md` §6.5

#### 2.7.2 正式平台層樹狀結構（Industry First）

```text
bbcshops88@gmail.com（Google Drive）
├── industries/
│   ├── travel/
│   │   ├── tenants/
│   │   │   ├── travel_a/01_Private_Layer/
│   │   │   ├── travel_b/01_Private_Layer/
│   │   │   └── travel_c/01_Private_Layer/
│   │   └── shared/02_Shared_Layer/
│   ├── hotel/
│   │   ├── tenants/...
│   │   └── shared/02_Shared_Layer/
│   └── restaurant/
│       ├── tenants/...
│       └── shared/02_Shared_Layer/
├── global/
│   └── 02_Global_Shared_Layer/
└── registrations/
```

> **廢止路徑：** 根目錄下扁平 `tenants/{tenant_key}/`、`shared/{industry_code}/02_Shared_Layer/` **不作** 長期 SSOT。見 `BATS_DATA_SOURCE_REGISTRY.md` §6.5.6 Legacy Migration Note。

#### 2.7.3 治理原則摘要

| # | 原則 |
|---|------|
| 1 | Tenant Private = `industries/{industry_code}/tenants/{tenant_key}/01_Private_Layer/`（單一租戶） |
| 2 | Industry Shared = `industries/{industry_code}/shared/02_Shared_Layer/`（產業層；**非** Tenant） |
| 3 | Global Shared = `global/02_Global_Shared_Layer/`（平台層） |
| 4 | Shared Layer ≠ Public Layer |
| 5 | Shared Layer Default Private |
| 6 | Tenant **不會自動** 使用 Shared；須 Registry + Policy 啟用 |
| 7 | `travel` 為首個產業；`hotel`、`restaurant`、`beauty`、`education`、`medical` 等適用同架構 |
| 8 | **Shared 預設不進入 Runtime** | 未 Policy 啟用前 **不** 進 GCS Metadata / Knowledge / Future RAG |
| 9 | **不得自動同步至所有 Tenant** | Shared 須逐項 Policy 啟用；Archive promote ≠ 自動下發 |

#### 2.7.4 與 GCS 邊界（Knowledge + Archive）

| Drive（Archive 邏輯路徑） | GCS Knowledge（Structured） | GCS Archive（原件） |
|---------------------------|----------------------------|---------------------|
| `industries/{code}/tenants/{key}/01_Private_Layer/` | `tenants/{sno}/knowledge/` | `tenants/{sno}/archive/` |
| `industries/{code}/shared/02_Shared_Layer/` | `shared/{industry_code}/knowledge/` | `shared/{industry_code}/archive/` |
| `global/02_Global_Shared_Layer/` | `shared/global/knowledge/` | `shared/global/archive/` |

**禁止修改 GCS Path；** Drive 與 GCS 透過 Registry `sno` ↔ `tenant_key` 對照。

#### 2.7.5 Phase 6 限制

| 限制 | 說明 |
|------|------|
| **不新增** `private_drive_folder_id` | Registry JSON Schema 不變 |
| **不修改** `BATS_DATA_CONTRACT.md` | Sheet / JSON Contract 不變 |
| **不實作** Drive API | 本節僅文件治理 |
| **GCS Object Versioning** | MVP **OFF**；Phase 6 **不得依賴**（見 `BATS_DRIVE_GCS_MAPPING.md` §12） |
| **Runtime Source** | Drive = Archive SoT；GCS = Runtime Knowledge Source（`BATS_DRIVE_CONNECTOR_SCOPE.md` §2） |

---

## 3. Write Boundary

### 3.1 正式規則

**各層資料只能寫入其對應 GCS 路徑；禁止跨層直接寫入。**

| 層級 | 允許寫入 | 禁止寫入 |
|------|----------|----------|
| **Tenant Layer** | `tenants/{sno}/knowledge/` | `shared/{industry_code}/knowledge/`、`shared/global/knowledge/`、他社 `tenants/{other_sno}/` |
| **Industry Shared Layer** | `shared/{industry_code}/knowledge/` | `tenants/{sno}/knowledge/`、`shared/global/knowledge/`（除非為 Global 層專用發布流程）、`shared/{other_industry}/knowledge/` |
| **Global Shared Layer** | `shared/global/knowledge/` | `tenants/{sno}/knowledge/`、`shared/{industry_code}/knowledge/` |

### 3.2 Tenant Layer 寫入邊界

```text
BDS（v1 MVP）
        ↓
只能寫入
        ↓
tenants/{sno}/knowledge/
  ├── company_profile.json
  ├── service_qa.json
  ├── external_product_links.json
  ├── service_items.json
  └── special_prices.json
```

| 規則 | 說明 |
|------|------|
| **sno 邊界** | 寫入路徑之 `{sno}` 須與 Registry / 上傳 metadata 一致 |
| **禁止捷徑** | 不得將租戶 Sheet 同步結果寫入 `shared/` |
| **格式契約** | 寫入內容須符合 `BATS_DATA_CONTRACT.md` |

### 3.3 Industry Shared Layer 寫入邊界

```text
（未來版本）Industry Maintainer / Admin
        ↓
只能寫入
        ↓
shared/{industry_code}/knowledge/
```

| 規則 | 說明 |
|------|------|
| **產業隔離** | `travel` 知識不得寫入 `shared/hotel/knowledge/` |
| **非租戶資料** | Industry Shared **不得** 含特定 `sno` 之專屬機密或 PII |
| **v1 狀態** | BDS v1 MVP **不執行** 此層寫入（見 §5） |

### 3.4 Global Shared Layer 寫入邊界

```text
（未來版本）BBC Platform / Admin
        ↓
只能寫入
        ↓
shared/global/knowledge/
```

| 規則 | 說明 |
|------|------|
| **跨產業** | 僅放跨產業通用知識 |
| **非租戶資料** | 不得含租戶專屬覆寫內容 |
| **v1 狀態** | BDS v1 MVP **不執行** 此層寫入（見 §5） |

### 3.5 禁止行為

| 禁止 | 說明 |
|------|------|
| **跨層直接寫入** | 單次同步不得同時 promote 至 Tenant 與 Shared 路徑 |
| **反向注入** | Shared 內容不得寫回 `tenants/{sno}/knowledge/` |
| **未授權跨 tenant** | A 社資料不得寫入 B 社路徑（見 `BATS_DATA_SYNC_POLICY.md` §10） |
| **customer_registration 混入** | 報名資料不得寫入任何 Knowledge 路徑（§21） |
| **Archive 寫入 `knowledge/`** | 任何層級之非結構化原件 **不得** promote 至 `knowledge/` |
| **Knowledge JSON 寫入 `archive/`** | Structured JSON **不得** 寫入 `archive/` |

### 3.6 Archive Layer 寫入邊界（Phase 6E-1）

> **SSOT 交叉引用：** 物件路徑模板見 `BATS_DRIVE_GCS_MAPPING.md` §6.1；Policy Gate 見 §18；Platform Registry 見 `BATS_DATA_SOURCE_REGISTRY.md` §6.7。

#### 3.6.1 Tenant Private Archive

```text
Tenant / BBC Admin（維護 Drive 原件）
        ↓ BDS Promote（6D；須 Gate 0～7）
只能寫入
        ↓
tenants/{sno}/archive/{data_category}/{drive_file_id}/{file_name}
```

| 項目 | 規則 |
|------|------|
| **誰可維護 Drive 原件** | Tenant（自家 `01_Private_Layer`）、BBC Admin（代管） |
| **誰可 promote** | BDS `BdsDriveArchivePromoter`（`owner_scope=tenant`；須 env 開關） |
| **允許 `data_category`** | `itinerary_data`、`archive`（6D-1b 現行）；見 Mapping §17 |
| **禁止** | 寫入 `shared/`；寫入 `knowledge/`；跨 `sno` |

#### 3.6.2 Industry Shared Archive

```text
BBC Industry Maintainer / BBC Admin（維護 Drive 原件）
        ↓ BDS Promote（6E；須 Policy ON + Platform Registry）
只能寫入
        ↓
shared/{industry_code}/archive/{data_category}/{drive_file_id}/{file_name}
```

| 項目 | 規則 |
|------|------|
| **誰可維護 Drive 原件** | BBC Industry Maintainer、BBC Admin |
| **誰可 promote** | BDS Promoter（`owner_scope=industry`；**僅** Policy `archive_promote_enabled: true`） |
| **允許 `data_category`** | **僅** `shared_knowledge` |
| **Registry** | `industries.{code}.shared_layer_folder_id`（Platform Registry） |
| **禁止** | 含特定 `sno` PII；寫入 `tenants/`；寫入 `knowledge/`；**tenant-specific shared** |
| **不得自動下發** | promote 至 GCS **不** 代表所有同產業 tenant 自動獲得檔案存取 |

#### 3.6.3 Global Shared Archive

```text
BBC Platform Admin（維護 Drive 原件）
        ↓ BDS Promote（6E；須 Global Policy ON）
只能寫入
        ↓
shared/global/archive/{data_category}/{drive_file_id}/{file_name}
```

| 項目 | 規則 |
|------|------|
| **誰可維護 Drive 原件** | BBC Platform Admin、BBC Admin |
| **誰可 promote** | BDS Promoter（`owner_scope=global`；**僅** `global.archive_promote_enabled: true`） |
| **允許 `data_category`** | **僅** `shared_knowledge` |
| **Registry** | `global.shared_layer_folder_id`（Platform Registry） |
| **禁止** | 產業專屬內容（應放 Industry Shared）；寫入 `tenants/`；寫入 `knowledge/` |
| **不得自動下發** | Global Archive promote **不** 自動對所有 tenant／產業開放 |

#### 3.6.4 Archive 三層一致性

| 原則 | 說明 |
|------|------|
| **prefix 唯一** | 各層 **僅** 使用 `archive/`；禁止 `uploads*` 別名 |
| **與 knowledge 分離** | 三層皆 **不得** 將非結構化原件寫入 `knowledge/` |
| **單次 job 單層** | 一次 promote job **不得** 混合 tenant + industry + global 寫入 |
| **Policy default OFF** | Shared 兩層預設 **零 promote** |
| **BATS 不消費 archive** | 三層 `archive/` 皆 **不是** Runtime 答案來源 |

### 3.7 Upload Portal Write Gates（Phase 7-0d SSOT）

> **L2 細節：** `BATS_UPLOAD_PORTAL_PHASE7_MVP_PLAN.md`；Sync §17.8～§17.9；Registry §10.6.5～§10.6.6。

#### 3.7.1 Tenant Upload Portal（Phase 7-1）

| 項目 | 規則 |
|------|------|
| **誰可使用** | 租戶品牌管理員（Legacy brand login）；Pilot：`TourBusstoreNo=6180` |
| **允許寫入** | `tenants/{sno}/knowledge/`（Pilot：`tenants/5f99b8d665e8444d/knowledge/`） |
| **禁止** | 寫入 `shared/`；寫入他社 `tenants/{other_sno}/` |
| **Tenant Admin 對 Shared** | 租戶管理員 **不可** 透過任何 Upload Portal 寫入 `shared/` |

#### 3.7.2 Shared Upload Portal（Phase 7-2）

| 項目 | 規則 |
|------|------|
| **誰可使用** | **僅** BBC 管理中心／Industry Maintainer（Management Center `sno` `cff796a33d94ea31`） |
| **允許寫入** | `shared/{industry_code}/knowledge/`；MVP：`shared/travel/knowledge/` |
| **禁止** | 寫入 `tenants/`；Tenant Admin 使用 Shared Portal；密碼／手機密碼 hardcode gate |
| **Deferred** | `shared/global/knowledge/` — Global Shared upload 另階段 |

#### 3.7.3 雙 Portal 隔離

| 原則 | 說明 |
|------|------|
| **獨立 URL** | `upload.php` ≠ `shared_upload.php` |
| **獨立 gate** | Tenant 用 `storeNo`／tenant `sno`；Shared 用 management center `sno` |
| **單次 job 單層** | 一次上傳觸發之 BDS job **不得** 同時 promote Tenant + Shared 路徑 |

### 3.8 共用 BDS Sync Core（Phase 7-1c-0 SSOT）

> **長期架構原則：** 所有 Structured Knowledge 同步入口 **必須** 共用同一 BDS Sync Core。

| 入口 | 規則 |
|------|------|
| Tenant Upload Portal（7-1） | 觸發 `bin/bds-sync.php`／BDS core；**不得** Portal 內嵌獨立 sync |
| Shared Upload Portal（7-2） | 同上 |
| 未來 API Trigger（若存在） | 同上 |

**Safety：** 須遵守 `BATS_DATA_SYNC_POLICY.md` §14.1.1 Last Successful Version Rule。

**交叉引用：** Sync Policy §17.11；MVP Plan §12.7；Implementation Plan §8.8.5

---

## 4. Override Rule

### 4.1 Knowledge Resolution Order

BATS 解析客服知識時，**讀取順序** 為（完整步驟見 `BATS_DATA_SOURCE_REGISTRY.md` §4.4）：

```text
Tenant Layer
        ↓（若無匹配）
Industry Shared Layer
        ↓（若無匹配）
Global Shared Layer
```

| 順序 | 層級 | 路徑 |
|------|------|------|
| **1** | Tenant Layer | `tenants/{sno}/knowledge/` |
| **2** | Industry Shared Layer | `shared/{industry_code}/knowledge/` |
| **3** | Global Shared Layer | `shared/global/knowledge/` |

### 4.2 覆蓋原則

| 原則 | 說明 |
|------|------|
| **Override 方向** | 解析順序 **較前** 之層級，覆蓋（優先於）較後層級 |
| **Fallback** | 較後層級 **僅在** 較前層級 **無匹配** 時使用 |
| **同一主題** | 同一問答主題／服務項目，以最先命中之層級答案為準 |

### 4.3 允許之覆蓋

| 允許 | 說明 |
|------|------|
| **Tenant Override Shared** | 租戶私有知識 **優先於** Industry / Global Shared |

**範例：**

| 層級 | 護照代辦價格 | AI 回覆 |
|------|--------------|---------|
| Tenant（travel_b） | **1600** | **1600** ← 採用 |
| Industry Shared（travel） | 1800 | （不採用） |
| Global Shared | 2000 | （不採用） |

### 4.4 禁止之覆蓋

| 禁止 | 說明 |
|------|------|
| **Industry Override Tenant** | Industry Shared **不得** 覆寫 Tenant 已存在之答案 |
| **Global Override Tenant** | Global Shared **不得** 覆寫 Tenant 已存在之答案 |
| **Global Override Industry（在 Industry 已命中時）** | Industry 已命中時，Global 不得覆寫 |
| **合併未標記 blob** | 不得將三層知識合併為未分類單一知識體 |

```text
允許：  Tenant  →  覆蓋  →  Industry / Global（讀取層）
禁止：  Industry / Global  →  覆蓋  →  Tenant
```

### 4.5 Override 與 Ownership 對齊

| 層級 | 擁有寫入權 | 擁有讀取優先權 |
|------|------------|----------------|
| **Tenant** | Tenant（經 BDS v1） | **最高** |
| **Industry Shared** | BBC Industry Maintainer（未來） | 中（fallback） |
| **Global Shared** | BBC Platform（未來） | 低（最終 fallback） |

> **讀取優先權 ≠ 寫入權限。** Tenant 讀取優先最高，但 Industry / Global 之 **寫入** 由各自 Owner 於未來版本管理。

### 4.6 不適用 Override 之類型

| 類別 | 說明 |
|------|------|
| `itinerary_data` | 行程資料不參與 Tenant vs Shared 客服覆寫模型 |
| `customer_registration` | 禁止進入 Knowledge Resolution Flow |

---

## 5. BDS Sync Ownership Rule

### 5.1 同步權責定義

| 角色 | 同步權限（概念） |
|------|------------------|
| **Tenant** | 經主機A上傳頁，觸發 **自家** `tenant_private_knowledge` 同步 |
| **BBC Admin** | 代管、稽核、停用／恢復同步；Registry 維護 |
| **BBC Industry Maintainer** | **v1 無** Shared Layer 同步權（見 §5.2） |
| **BBC Platform** | **v1 無** Global Layer 同步權（見 §5.2） |

### 5.2 BDS v1 MVP 同步範圍

**BDS v1 MVP 僅允許同步：**

| 項目 | 值 |
|------|-----|
| **data_category** | `tenant_private_knowledge` |
| **來源** | `private_knowledge_sheet_id`（1 Google Sheet / 5 Tabs） |
| **目標** | `tenants/{sno}/knowledge/` 下 5 JSON |
| **契約** | `BATS_DATA_CONTRACT.md` |
| **模式** | Mode B only（見 `BATS_DATA_SYNC_POLICY.md` §15） |

### 5.3 BDS v1 MVP 不得同步

下列 Shared Layer 路徑 **不得** 由 BDS v1 MVP 寫入或覆蓋：

| 禁止路徑 | 說明 |
|----------|------|
| `shared/travel/knowledge/` | 旅遊業共用 |
| `shared/hotel/knowledge/` | 飯店民宿共用 |
| `shared/restaurant/knowledge/` | 餐飲共用 |
| `shared/global/knowledge/` | 全域共用 |
| `shared/{industry_code}/knowledge/`（其他） | 含 §6 未來產業 |

**Shared Layer 由未來版本管理**；v1 僅定義 Ownership 與讀取優先序，**不實作** Shared 同步管線。

### 5.4 同步失敗與 Ownership

| 規則 | 說明 |
|------|------|
| **不得覆蓋正式資料** | Validation Fail 時禁止覆蓋 `tenants/{sno}/knowledge/` 既有 JSON |
| **所有權不轉移** | 同步失敗 **不** 改變資料歸屬；Tenant 仍擁有該路徑 |
| **Safety Rule** | 見 `BATS_DATA_SYNC_POLICY.md` §14.1 |

### 5.5 Pilot 範例（travel_b）

| 項目 | 值 |
|------|-----|
| `tenant_key` | `travel_b` |
| `tenant_sno` | `5f99b8d665e8444d` |
| `industry_code` | `travel`（決定 **讀取** fallback 路徑，**非** v1 同步目標） |
| v1 同步目標 | `tenants/5f99b8d665e8444d/knowledge/` 下 5 JSON |

> `travel_b` 僅為 Pilot；不得 hardcode 為架構特例（見 `BATS_DATA_SYNC_POLICY.md` §23）。

---

## 6. Industry Expansion Rule

### 6.1 新增產業

未來新增產業（含但不限於）：

| `industry_code` | 產業 |
|-----------------|------|
| `beauty` | 美業 |
| `auto` | 汽車 |
| `retail` | 零售 |
| `education` | 教育 |
| `medical` | 醫療 |
| （其他） | 依 Registry 登錄 |

### 6.2 只允許

| 允許 | 說明 |
|------|------|
| **新增 `industry_code`** | Registry 登錄新產業代碼 |
| **新增 `shared/{industry_code}/knowledge/`** | 新產業共用知識路徑 |
| **新租戶 Registry entry** | 設定對應 `industry_code` |

```text
新增 beauty 產業：
  1. 新增 industry_code: beauty
  2. 建立 shared/beauty/knowledge/
  3. 新租戶 Registry entry 設 industry_code: beauty
  4. Ownership Rule 不變
  5. Override Rule 不變
  6. Sync Flow 不變（v1 仍僅 Tenant Layer）
```

### 6.3 不得修改

| 禁止 | 說明 |
|------|------|
| **Ownership Rule** | §2 三層 Owner / Steward 模型不變 |
| **Override Rule** | §4 Tenant > Industry > Global 不變 |
| **Sync Flow** | Mode B、v1 僅 `tenant_private_knowledge` 不變 |
| **Write Boundary** | §3 跨層禁止寫入不變 |
| **Knowledge Resolution Order** | 見 `BATS_DATA_SOURCE_REGISTRY.md` §4.4 |

---

## 7. Future Roadmap

下列項目 **保留於 Roadmap**；本版 **不定義實作**：

| 項目 | 說明 | 狀態 |
|------|------|------|
| **RBAC** | 角色型存取控制（Tenant / Industry Maintainer / Admin） | 規劃中 |
| **Approval Workflow** | Shared 知識發布核准流程 | 規劃中 |
| **Shared Knowledge Editor** | 產業／全域知識編輯介面 | 規劃中 |
| **Admin UI** | Ownership / Registry 視覺化管理 | 規劃中 |
| **Ownership Audit Log** | 記錄誰在何時修改／同步哪一層資料 | 規劃中 |
| **Shared Layer BDS Sync** | Industry / Global 知識同步管線 | 規劃中 |
| **Delegated Stewardship** | 租戶授權第三方代管 | 規劃中 |

### 7.1 建議 Phase 順序

| Phase | 內容 |
|-------|------|
| **Ownership v1** | 本文件；三層 Owner、Write Boundary、Override、v1 Sync 範圍 |
| **BDS v1 實作** | `tenant_private_knowledge` 同步（travel_b Pilot） |
| **Ownership v2** | RBAC + Approval Workflow + Audit Log |
| **BDS v2** | Shared Layer 同步 + Shared Knowledge Editor |

---

## 8. Cross Reference

### 8.1 BDS 四份 SSOT 關係

```text
BATS_DATA_OWNERSHIP_POLICY.md   ← 本文件：歸屬、寫入、覆蓋、同步權責
        │
        ├── BATS_DATA_SYNC_POLICY.md      同步原則、Mode B、Safety Rule、MVP 範圍
        ├── BATS_DATA_SOURCE_REGISTRY.md  Registry、industry_code、Resolution Order
        └── BATS_DATA_CONTRACT.md         5 Tab / 5 JSON 欄位契約
```

### 8.2 引用對照

| 文件 | 引用本文件之章節 | 本文件引用之章節 |
|------|------------------|------------------|
| `BATS_DATA_SYNC_POLICY.md` | §10 Tenant Isolation、§12 Rule 5/7、§15 MVP | §5 Sync Ownership、§3 Write Boundary |
| `BATS_DATA_SOURCE_REGISTRY.md` | §4 Knowledge Layer、§5 Tenant Override | §2 Ownership Layers、§4 Override Rule |
| `BATS_DATA_CONTRACT.md` | §9 Tenant Isolation | §3.2 Tenant Write Boundary、§5.2 v1 範圍 |

### 8.3 相關文件

| 文件 | 關係 |
|------|------|
| `BATS_DATA_SYNC_POLICY.md` | 同步模式、GCS 結構、Drive 三層、BDS Safety Rule |
| `BATS_DATA_SOURCE_REGISTRY.md` | `private_knowledge_sheet_id`、`industry_code`、Knowledge Resolution Order |
| `BATS_DATA_CONTRACT.md` | Tenant Private Knowledge 5 Tab / 5 JSON Data Contract |
| `BATS_TENANT_DATA_CLASSIFICATION.md` | Private + Shared Layer；Shared ≠ Public |
| `BATS_SHARED_KNOWLEDGE_CONTRACT.md` | Shared Layer Governance、Explicit Share Policy |
| `TENANT_SOURCE_REGISTRY_POLICY.md` | 商品源 Registry；與本文件 **互補** |
| `DOCUMENTATION_GOVERNANCE_POLICY.md` | 本文件為 BDS Ownership 領域 L1 SSOT |

### 8.4 衝突處理優先序

```text
L0  CO_WORK_POLICY / DOCUMENTATION_GOVERNANCE
        ↓
L1  BATS_DATA_OWNERSHIP_POLICY（歸屬／覆蓋／寫入邊界）
    BATS_DATA_SYNC_POLICY（同步原則）
    BATS_DATA_SOURCE_REGISTRY（來源／解析順序）
    BATS_DATA_CONTRACT（資料格式）
        ↓
L2+ 實作文件、程式
```

| 議題 | SSOT |
|------|------|
| 誰擁有、誰可寫、誰可同步、誰可覆蓋 | **本文件** |
| 怎麼同步、何時觸發 | `BATS_DATA_SYNC_POLICY.md` |
| 路徑登錄、解析順序細節 | `BATS_DATA_SOURCE_REGISTRY.md` |
| 欄位與 JSON 格式 | `BATS_DATA_CONTRACT.md` |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.6** | 2026-06-05 | Phase 7-1c-0：§3.8 共用 BDS Sync Core；Last Successful Version cross-ref |
| **v1.5** | 2026-06-05 | Phase 7-0d：§3.7 Upload Portal Write Gates（Tenant／Shared 隔離） |
| **v1.4** | 2026-06-12 | Phase 6E-1：§2.7 Industry First Drive 樹；§3.6 Archive Layer 寫入邊界（三層） |
| **v1.3** | 2026-06-10 | 新增 §2.7 Google Drive Platform Layer Architecture；Shared 移出租戶資料夾 |
| **v1.3** | 2026-06-10 | §2.7 Shared Runtime Boundary、Object Versioning、Runtime Source cross-ref |
| **v1.2** | 2026-06-09 | 新增 §2.6 Google Drive Folder Governance（One Tenant One Folder；Phase 6 對齊） |
| **v1.1** | 2026-06-09 | 新增 §2.5 Default Access Policy：Drive Folder Default Private、Explicit Share 啟用路徑 |
| **v1.0** | 2026-06-08 | 第一版：三層 Ownership、Write Boundary、Override Rule、BDS v1 Sync 範圍、Industry Expansion |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | Draft — 待審核 |
| **實作狀態** | 未實作 |
| **Pilot** | `travel_b`（`5f99b8d665e8444d`） |
