# BATS_DATA_SOURCE_REGISTRY.md

**專案：** BBC AI SaaS / BATS / BDS / Travel Data Center  
**定位：** L1 架構政策層 — BDS Source Registry 正式 SSOT  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_DATA_SYNC_POLICY.md`、`BATS_DATA_CONTRACT.md`、`TENANT_SOURCE_REGISTRY_POLICY.md`、`TENANT_SOURCE_RUNTIME_BRIDGE_V1.md`  
**適用範圍：** `travel_a`、`travel_b`、`travel_c`、`travel_d` 及未來 **20～200 家旅行社**  
**適用對象：** ChatGPT、Cursor、開發者、維運人員、資料維護人員  
**衝突處理：** 同步模式、分層邊界、Data Category 基礎規則以 `BATS_DATA_SYNC_POLICY.md` 為準；**Source Registry 結構與讀取優先序以本文件為準**。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §2 | Registry Scope |
| §3 | Knowledge Source Type |
| §4 | Knowledge Layer Architecture（Tenant / Industry / Global 三層） |
| §4.2 | Industry Shared Knowledge Examples |
| §4.4 | Knowledge Resolution Order |
| §4.5 | Industry Expansion Rule |
| §5 | Tenant Override Rule |
| §6 | Tenant Drive Isolation Rule |
| §7 | Tenant Registry Contract |
| §8 | Anti Hardcode Rule |
| §9 | Shared Layer Structure |
| §10 | Future Roadmap |
| §10.5 | Phase 6 Planning — Future Drive Source Registry |
| §6.5 | Google Drive Platform Layer Architecture |

---

## 1. Purpose

### 1.1 文件定位

本文件建立 **BDS Source Registry** 正式 SSOT，定義所有 BDS 資料來源（Google Drive、Google Sheet、GCS Prefix、Knowledge Source、Data Category）之 **Registry 化管理契約**。

**目的：**

- 所有資料來源 **必須** 透過 Registry 管理
- 避免 hardcode tenant（尤其 `travel_b` Pilot）
- 支援 `travel_c`、`travel_d` 及未來租戶 **僅新增 Registry Config**，無需重構同步核心
- 避免 Google Drive / Google Sheet 多租戶混亂

### 1.2 與 BDS Sync Policy 關係

| 文件 | 職責 |
|------|------|
| `BATS_DATA_SYNC_POLICY.md` | 同步模式（Mode B）、分層架構、Data Category 基礎規則、Drive 三層分類 |
| **本文件** | Source Registry 結構、租戶契約欄位、Knowledge Source 類型、讀取優先序 |

```text
BATS_DATA_SYNC_POLICY.md  →  「怎麼同步」
BATS_DATA_SOURCE_REGISTRY.md  →  「同步哪些來源、Registry 長什麼樣」
```

### 1.3 正式規則

| 規則 | 說明 |
|------|------|
| **Registry Driven** | 所有 Drive Folder、Sheet、GCS Prefix、Knowledge Source 須登錄於 Source Registry |
| **禁止 hardcode tenant** | 核心 BDS / BATS 流程不得寫死 `travel_b` 或特定 `sno` |
| **與商品源 Registry 分離** | 本文件管理 **知識／行程資料來源**；商品源 URL（bbctravel / grp / tourcenter）見 `TENANT_SOURCE_REGISTRY_POLICY.md` |

### 1.4 本文件不做

| 不做 | 說明 |
|------|------|
| 實作 Registry 程式 | 實作階段另開 Phase |
| 定義同步觸發與錯誤處理 | 見 `BATS_DATA_SYNC_POLICY.md` §13、§14 |
| 定義商品源 URL 規則 | 見各平台 Golden Reference |

---

## 2. Registry Scope

### 2.1 管理範圍

Source Registry **必須** 管理以下項目：

| 項目 | 說明 | Registry 層級 |
|------|------|---------------|
| **Google Drive Folder** | 平台層 `tenants/`、`shared/`、`registrations/` 資料夾 ID | Tenant + Shared（平台層） |
| **Google Sheet** | 私有知識維護表（若適用） | Tenant |
| **GCS Prefix** | `shared/` 或 `tenants/{sno}/` 對應路徑 | Tenant + Shared |
| **Knowledge Source** | 邏輯知識來源定義（見 §3） | Tenant + Shared |
| **Data Category** | `shared_knowledge`、`tenant_private_knowledge`、`itinerary_data`；另含非知識之 `customer_registration`（僅 Archive） | 每筆 Source 必標 |
| **`industry_code`** | 產業代碼（`travel`、`hotel`、`restaurant` 等） | Tenant entry 必填（§7.3） |

### 2.2 Registry 兩層結構

```text
Source Registry
├── shared/                    ← 共用知識層（§9）
│   └── knowledge sources...
└── tenants/
    └── {sno}/                 ← 租戶層（§7）
        └── tenant registry entry...
```

### 2.3 不在 Registry 管理範圍

| 項目 | 原因 | 參考 |
|------|------|------|
| **API Secret / Token** | 安全；僅 `.env` / Secret Manager | `BATS_DATA_SYNC_POLICY.md` §3.2 |
| **Host B 報名 SQL** | 交易權威；非 BDS 知識來源 | `BATS_DATA_SYNC_POLICY.md` §21.4 |
| **商品源 platform_id / URL template** | 屬 Product Source Registry | `TENANT_SOURCE_REGISTRY_POLICY.md` |
| **即時庫存 / 即時價格** | Host B 即時查詢 | `BATS_DATA_SYNC_POLICY.md` §3.2 |

### 2.4 禁止事項

| 禁止 | 原因 |
|------|------|
| 在 PHP / BDS 程式 hardcode Drive Folder ID | 違反 Registry Driven |
| 在程式 hardcode `travel_b` 路徑 | 違反 §8 |
| 多租戶共用同一 Drive Folder 而未登錄隔離 | 違反 §6 |
| 未標 `data_category` 即同步至 GCS | 違反 Data Category 分離 |

---

## 3. Knowledge Source Type

### 3.1 正式定義

Knowledge Source 分 **三類**（供 BATS 讀取 GCS Knowledge Layer）；另有一類 **非 Knowledge** 之 Archive 類型（`customer_registration`）僅登錄於 Drive Registry，不進 GCS Knowledge。

### 3.2 Type A — `shared_knowledge`（共用知識）

| 項目 | 說明 |
|------|------|
| **類別 ID** | `shared_knowledge` |
| **中文** | 共用知識 |
| **Registry 層級** | `shared/` |
| **GCS 路徑** | `shared/{industry_code}/knowledge/`、`shared/global/knowledge/`（見 §9.1） |

#### 典型內容

| 範例 | 說明 |
|------|------|
| 護照辦理 | 通用代辦說明、流程 |
| 簽證資訊 | 各國簽證通用資訊 |
| 入境規定 | 通用入境須知 |
| 旅遊須知 | 跨租戶通用提醒 |

#### 角色

- 提供 **所有租戶** 之 fallback 知識
- 當租戶無私有覆寫時，BATS / Gemini 使用 shared 答案

### 3.3 Type B — `tenant_private_knowledge`（旅行社私有知識）

| 項目 | 說明 |
|------|------|
| **類別 ID** | `tenant_private_knowledge` |
| **中文** | 旅行社私有知識 |
| **Registry 層級** | `tenants/{sno}/` |
| **GCS 路徑** | `tenants/{sno}/knowledge/` 下 5 JSON（見 `BATS_DATA_CONTRACT.md` §6） |
| **Drive 對應** | `tenants/{tenant_key}/01_Private_Layer/`（§6.5） |

#### 典型內容

| 範例 | 說明 |
|------|------|
| QA | 問答對照 |
| 服務項目 | 代辦、加購服務 |
| 特殊價格 | 該社專屬報價 |
| 公司資料 | 公司規則、聯絡方式 |
| 其他商品源 | 非 bbctravel/grp/tourcenter 之補充說明 |

#### 角色

- **優先於** `shared_knowledge`（見 §4、§5 Tenant Override）
- BDS v1 MVP 同步範圍：1 Google Sheet / 5 Required Tabs / 5 JSON Outputs（`BATS_DATA_SYNC_POLICY.md` §15、`BATS_DATA_CONTRACT.md`）

### 3.4 Type C — `itinerary_data`（行程資料）

| 項目 | 說明 |
|------|------|
| **類別 ID** | `itinerary_data` |
| **中文** | 行程資料 |
| **Registry 層級** | `tenants/{sno}/` |
| **GCS 路徑** | `tenants/{sno}/knowledge/itinerary/` |
| **Drive 對應** | `tenants/{tenant_key}/01_Private_Layer/`（Category A Archive；§6.5） |

#### 典型內容

| 範例 | 說明 |
|------|------|
| Excel | 商品行程表 |
| PDF | 行程說明 |
| 圖片 | 行程圖、DM 圖 |
| DM | 宣傳 DM 檔 |

#### 角色

- 商品搜尋知識（`BATS_DATA_SYNC_POLICY.md` §12 Category A）
- **下一階段** 同步；不納入 BDS v1 MVP

### 3.5 非 Knowledge 類型 — `customer_registration`（僅 Registry 登錄）

| 項目 | 說明 |
|------|------|
| **類別 ID** | `customer_registration` |
| **性質** | 交易資料；**非** Knowledge Source |
| **Drive 對應** | `registrations/`（平台層；§6.5） |
| **GCS** | **禁止** 寫入 Knowledge Layer |

> 須登錄於 Tenant Registry Contract（§7）以利 Drive 隔離與權限管理，但 **不得** 列入 §4 讀取優先序。

### 3.6 三類 Knowledge Source 對照

| 類別 | Registry 層 | 讀取優先序（客服） | BDS v1 MVP |
|------|-------------|-------------------|------------|
| `tenant_private_knowledge` | `tenants/{sno}/` | **1（最高）** | **實作** |
| `shared_knowledge` | `shared/{industry}/`、`shared/global/` | **2、3（fallback）** | 規劃中 |
| `itinerary_data` | `tenants/{sno}/` | 依商品搜尋意圖 | 下一階段 |
| `customer_registration` | `tenants/{sno}/` | **不讀取** | 僅 Archive |

---

## 4. Knowledge Layer Architecture

### 4.1 正式定義

**Knowledge Layer（客服知識消費層）** 由 **三層** 組成，自高至低優先序為：

```text
Layer 1  Tenant Layer
    ↓
Layer 2  Industry Shared Layer
    ↓
Layer 3  Global Shared Layer
```

| 項目 | 說明 |
|------|------|
| **儲存權威** | GCS Knowledge Layer（經 Host A cache mirror） |
| **讀取觸發** | BATS 客服問答／Gemini Context 注入 |
| **不適用** | `itinerary_data`（商品搜尋）、`customer_registration`（交易資料） |

與 `BATS_DATA_SYNC_POLICY.md` §12.7 **對齊**；完整解析順序見 §4.4。

---

#### Layer 1 — Tenant Layer（租戶層）

| 項目 | 說明 |
|------|------|
| **路徑** | `tenants/{sno}/knowledge/` |
| **data_category** | `tenant_private_knowledge` |
| **用途** | 租戶私有知識 |

**典型內容：**

| 範例 | 說明 |
|------|------|
| QA | 問答對照 |
| 服務項目 | 代辦、加購服務 |
| 特殊價格 | 該社專屬報價 |
| 公司資料 | 公司規則、聯絡方式 |
| 其他商品源 | 補充說明 |

**角色：** **最高優先**；命中即採用，不往下層 fallback。

**BDS v1 Tenant Private Knowledge Sheet：**

| 項目 | 說明 |
|------|------|
| **Registry 欄位** | `private_knowledge_sheet_id`（§7.2） |
| **語意** | 該租戶 **1 份** Google Sheet；**5 Required Tabs** |
| **Contract SSOT** | Tab 名稱、欄位、JSON 輸出 → **`BATS_DATA_CONTRACT.md`** |
| **Registry 職責** | 僅管理來源位置（Sheet ID）、`sno`、`industry_code`、GCS prefix |
| **非 Registry 職責** | 不定義欄位格式、不定義 JSON schema |

---

#### Layer 2 — Industry Shared Layer（產業共用層）

| 項目 | 說明 |
|------|------|
| **路徑** | `shared/{industry_code}/knowledge/` |
| **data_category** | `shared_knowledge` |
| **用途** | 同產業共用知識 |

**路徑範例：**

```text
shared/travel/knowledge/
shared/hotel/knowledge/
shared/restaurant/knowledge/
```

`{industry_code}` 來自 Tenant Registry Contract（§7）；Pilot 範例：`travel`。

**角色：** Tenant 無匹配時之 **產業級 fallback**。

---

#### Layer 3 — Global Shared Layer（跨產業共用層）

| 項目 | 說明 |
|------|------|
| **路徑** | `shared/global/knowledge/` |
| **data_category** | `shared_knowledge` |
| **用途** | 跨產業共用知識 |

**角色：** Industry Shared 仍無匹配時之 **最終 fallback**。

---

### 4.2 Industry Shared Knowledge Examples（產業知識範例）

#### `travel`（旅遊業）

| 主題 | 說明 |
|------|------|
| 護照 | 護照代辦流程、收費參考 |
| 簽證 | 各國簽證通用資訊 |
| 入境規定 | 入境須知、海關提醒 |
| 旅遊須知 | 出團注意事項、保險說明 |

#### `hotel`（飯店民宿）

| 主題 | 說明 |
|------|------|
| 入住規則 | 入住時間、證件要求 |
| 退房規則 | 退房時間、延遲退房 |
| 住宿 FAQ | 取消政策、押金、寵物規範 |

#### `restaurant`（美食餐廳）

| 主題 | 說明 |
|------|------|
| 訂位規則 | 訂位方式、保留時間 |
| 用餐規範 | 低消、服裝、兒童座椅 |
| 餐廳 FAQ | 營業時間、外帶、包廂 |

#### `global`（跨產業 — Global Shared Layer）

| 主題 | 說明 |
|------|------|
| 平台規則 | 平台使用條款、服務範圍 |
| 會員規則 | 會員權益、等級說明 |
| 隱私權政策 | 個資處理、Cookie 政策 |
| AI 客服共通規則 | 回覆格式、禁止事項、轉真人條件 |

> `global` 為 **Layer 3** 專用路徑（`shared/global/knowledge/`），不作 `industry_code` 填入 Layer 2。

---

### 4.3 三層架構總覽

```text
Knowledge Layer
│
├── Layer 1  Tenant Layer
│   └── tenants/{sno}/knowledge/           tenant_private_knowledge
│
├── Layer 2  Industry Shared Layer
│   └── shared/{industry_code}/knowledge/  shared_knowledge
│       ├── travel/knowledge/
│       ├── hotel/knowledge/
│       └── restaurant/knowledge/
│
└── Layer 3  Global Shared Layer
    └── shared/global/knowledge/           shared_knowledge
```

---

### 4.4 Knowledge Resolution Order（知識解析順序）

#### 正式規則

BATS 解析客服知識時，**讀取順序** 為：

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

#### 比對步驟（概念）

| 步驟 | 說明 |
|------|------|
| 1 | 依請求 `sno` 載入 Tenant Layer |
| 2 | 命中則採用租戶答案；**結束** |
| 3 | 未命中則依 Registry `industry_code` 載入 Industry Shared Layer |
| 4 | 仍未命中則載入 Global Shared Layer |
| 5 | 皆未命中則依 BATS 既定無答案處理（不捏造） |

#### 價格覆寫範例

| 層級 | 護照代辦價格 |
|------|--------------|
| Tenant Layer（travel_b） | **1600** |
| Industry Shared（`shared/travel/knowledge/`） | 1800 |
| Global Shared（`shared/global/knowledge/`） | 2000 |

**AI 必須回答：1600**

#### 不適用本順序之類型

| 類別 | 說明 |
|------|------|
| `itinerary_data` | 依商品搜尋意圖讀取 `tenants/{sno}/knowledge/itinerary/` |
| `customer_registration` | **禁止** 進入 Knowledge Resolution Flow |

---

### 4.5 Industry Expansion Rule（產業擴展規則）

未來新增產業（含但不限於）：

| `industry_code` | 產業 |
|-----------------|------|
| `beauty` | 美業 |
| `auto` | 汽車 |
| `retail` | 零售 |
| `education` | 教育 |
| `medical` | 醫療 |
| （其他） | 依 Registry 登錄 |

**只允許：**

| 允許 | 說明 |
|------|------|
| 新增 `industry_code` | Registry 登錄新產業代碼 |
| 新增 `shared/{industry}/knowledge/` | GCS 與 Source Registry 新路徑 |

**不得修改：**

| 禁止 | 說明 |
|------|------|
| **Knowledge Resolution Flow** | §4.4 三層順序固定 |
| **Source Registry Core** | 核心契約欄位與解析流程不 fork |
| BDS 同步核心流程 | 見 §8 |

```text
新增 beauty 產業：
  1. 新增 industry_code: beauty
  2. 建立 shared/beauty/knowledge/
  3. 新租戶 Registry entry 設 industry_code: beauty
  4. Knowledge Resolution Flow 不變
```

---

### 4.6 與 Shared Knowledge Rule 對齊確認

下列優先序 **正式採用且保持一致**：

```text
Tenant  >  Industry Shared  >  Global Shared
```

| 規則 | 狀態 |
|------|------|
| Tenant Layer 優先 | ✅ §4.4 順序 1 |
| Industry Shared 僅 fallback | ✅ §4.4 順序 2 |
| Global Shared 最終 fallback | ✅ §4.4 順序 3 |
| Shared 不得覆寫 Tenant | ✅ 見 §5 |
| 與 `BATS_DATA_SYNC_POLICY.md` §12.7 | ✅ 一致 |

---

## 5. Tenant Override Rule

### 5.1 正式規則

**Tenant > Industry Shared > Global Shared**

| 優先級 | 層級 | 說明 |
|--------|------|------|
| **最高** | Tenant（`tenants/{sno}/knowledge/`） | 租戶私有知識 |
| **中** | Industry Shared（`shared/{industry_code}/knowledge/`） | 產業共用；僅 fallback |
| **低** | Global Shared（`shared/global/knowledge/`） | 跨產業；最終 fallback |

| 原則 | 說明 |
|------|------|
| **Override** | 同一主題下，上層 **覆寫** 下層 |
| **Fallback** | 下層僅在上層 **無** 對應知識時使用 |
| **禁止反向覆寫** | Industry / Global Shared **不得** 覆寫 Tenant 已存在之答案 |
| **禁止合併未標記** | 不得將三層知識合併為未分類 blob |

### 5.2 三層範例

```text
travel_b  tenant_private_knowledge   護照代辦 1600   ← AI 回覆此值
    ↓（若無匹配才往下）
shared/travel  industry shared       護照代辦 1800
    ↓（若無匹配才往下）
shared/global  global shared          護照代辦 2000
```

### 5.3 實作邊界

| 層級 | 職責 |
|------|------|
| **Source Registry** | 登錄 `industry_code`、各層 GCS 路徑與 `data_category` |
| **BDS** | 分別同步至 `tenants/{sno}/knowledge/` 與 `shared/{industry}/knowledge/`、`shared/global/knowledge/` |
| **BATS** | 依 §4.4 Knowledge Resolution Order 讀取；不得 hardcode travel_b 路徑 |

### 5.4 與 `itinerary_data` 關係

`itinerary_data` **不參與** Tenant vs Shared 客服知識覆寫；行程資料僅屬租戶層 `tenants/{sno}/`，無 `shared/itinerary_data` 覆寫模型（v1）。

---

## 6. Tenant Drive Isolation Rule

### 6.1 正式規則

**每個租戶必須擁有獨立 Google Drive Folder。**

| 項目 | 規則 |
|------|------|
| **格式** | `tenants/{sno}/` |
| **禁止** | 多個租戶共用同一 Folder |
| **對應** | Registry 須記錄 `drive_root_folder_id` 與子資料夾 ID（§7）；Drive 平台層見 §6.5 |

### 6.2 租戶 Drive 子資料夾（Tenant Private Only）

> **架構修正（Phase 6 Pre-Governance）：** **Shared Layer 不屬於單一旅行社**；產業／全球 Shared 位於平台層 `shared/`（見 §6.5）。租戶 Drive 僅含 **Private Layer**。

```text
tenants/{tenant_key}/
└── 01_Private_Layer/          → Tenant Private Archive（單一租戶）
```

| 子資料夾 | 歸屬 | 說明 |
|----------|------|------|
| `01_Private_Layer` | **單一 Tenant** | 租戶私有 Archive；含 `tenant_private_knowledge` 等非結構化原始檔 |

**禁止：** 於租戶資料夾下建立 `02_Shared_Layer` 或任何產業／全球共用層（違反平台層架構）。

**現行 Pilot 範例：** `travel_a`、`travel_b`、`travel_c` 各自擁有 `tenants/{tenant_key}/01_Private_Layer/`。

### 6.3 Registry 與實體對應

> **Registry JSON Schema 不變**；下列為 Drive **邏輯路徑** 與現行 Registry 欄位之對照（Phase 6 前規劃）。

| Registry 欄位 | Drive 邏輯路徑（概念） | 說明 |
|---------------|------------------------|------|
| `drive_root_folder_id` | `tenants/{tenant_key}/` | 租戶根資料夾（如 `travel_b`） |
| `private_knowledge_folder_id` | `tenants/{tenant_key}/01_Private_Layer/` | Tenant Private Archive |
| `itinerary_folder_id` | `tenants/{tenant_key}/01_Private_Layer/`（或子路徑） | Category A Archive；**不** 獨立 Shared |
| `customer_registration_folder_id` | `registrations/`（平台層）或受控子路徑 | Category C；見 `BATS_DATA_SYNC_POLICY.md` §21 |

**不新增** `private_drive_folder_id` 為正式必填欄位。

### 6.4 與 GCS / Sheet 對齊

| 邊界 | 必須可對應 |
|------|------------|
| `sno` | Registry entry、`gcs_prefix`、Drive `tenant_key` 對照 |
| `gcs_prefix` | `tenants/{sno}/`（**GCS 不變**） |
| `private_knowledge_sheet_id` | 該租戶專屬 Sheet（若有）；**禁止** 多租戶共用 |

> **Drive 路徑** 使用 `tenant_key`（如 `travel_b`）；**GCS 路徑** 使用 `sno`（如 `5f99b8d665e8444d`）。兩者透過 Registry 對照，**不得** hardcode。

### 6.5 Google Drive Platform Layer Architecture

#### 6.5.1 定位

定義 **營運 Google Drive** 之正式平台層資料夾樹；與 GCS Knowledge Layer **分離**。本節為 Phase 6 Pre-Governance SSOT。

**營運帳號：** `bbcshops88@gmail.com` Google Drive  
**Default：** **Private**（所有層級）

#### 6.5.2 正式平台層樹狀結構

```text
bbcshops88@gmail.com（Google Drive）
├── tenants/
│   ├── travel_a/
│   │   └── 01_Private_Layer/          ← Tenant Private（單一租戶）
│   ├── travel_b/
│   │   └── 01_Private_Layer/
│   └── travel_c/
│       └── 01_Private_Layer/
├── shared/                             ← 平台層；非單一 Tenant
│   ├── travel/
│   │   └── 02_Shared_Layer/           ← Industry Shared
│   ├── hotel/
│   │   └── 02_Shared_Layer/
│   ├── restaurant/
│   │   └── 02_Shared_Layer/
│   └── global/
│       └── 02_Global_Shared_Layer/    ← Global Shared
└── registrations/                      ← 平台層報名／交易 Archive（Category C）
```

#### 6.5.3 三層治理對照

| 層級 | Drive 邏輯路徑 | 歸屬 | GCS 對應（不變） |
|------|----------------|------|------------------|
| **Tenant Private** | `tenants/{tenant_key}/01_Private_Layer/` | 單一 Tenant | `tenants/{sno}/knowledge/` |
| **Industry Shared** | `shared/{industry_code}/02_Shared_Layer/` | 產業層；**非** 單一 Tenant | `shared/{industry_code}/knowledge/` |
| **Global Shared** | `shared/global/02_Global_Shared_Layer/` | 平台層 | `shared/global/knowledge/` |

#### 6.5.4 正式治理原則

| # | 原則 | 說明 |
|---|------|------|
| 1 | **Tenant Private = 單一租戶** | `tenants/{tenant_key}/01_Private_Layer/` 僅屬該 Tenant |
| 2 | **Industry Shared = 產業層** | `shared/{industry_code}/02_Shared_Layer/` **不** 置於租戶資料夾下 |
| 3 | **Global Shared = 平台層** | `shared/global/02_Global_Shared_Layer/` |
| 4 | **Shared ≠ Public** | Shared 指 fallback 知識語意，**不是** 對外公開層 |
| 5 | **Default Private** | 所有 Drive 資料夾預設 Private |
| 6 | **Tenant 不自動使用 Shared** | 須 **Registry + Policy** 明確啟用 |
| 7 | **產業可擴展** | `travel` 為首個產業；`hotel`、`restaurant`、`beauty`、`education`、`medical` 等適用同架構 |

#### 6.5.5 明確禁止

| 禁止 | 說明 |
|------|------|
| `tenants/{tenant_key}/02_Shared_Layer/` | Shared 不得置於租戶資料夾下 |
| 多租戶共用同一 `01_Private_Layer` | 違反 Tenant Isolation |
| 未登錄 Registry 即啟用 Shared 可見性 | 違反 Explicit Share Policy |
| 新增 `private_drive_folder_id` 至 Registry Schema | Phase 6 前 **禁止** |

---

## 7. Tenant Registry Contract

### 7.1 契約目的

每個租戶於 Source Registry 至少須有一筆 **Tenant Registry Entry**；BDS 與維運工具 **僅** 透過此契約解析路徑，不得 hardcode。

### 7.2 必填欄位

| 欄位 | 類型 | 說明 |
|------|------|------|
| **`sno`** | string | 租戶唯一識別；權威邊界 |
| **`tenant_name`** | string | 人類可讀名稱（如 `travel_b`） |
| **`industry_code`** | string | 產業代碼；決定 `shared/{industry_code}/knowledge/` fallback 路徑 |
| **`enabled`** | boolean | 是否啟用 BDS 同步與知識讀取 |
| **`drive_root_folder_id`** | string | Google Drive 根資料夾 ID（`tenants/{sno}/`） |
| **`itinerary_folder_id`** | string | `01_Itinerary_Data` 資料夾 ID |
| **`private_knowledge_folder_id`** | string | `02_Private_Knowledge` 資料夾 ID |
| **`customer_registration_folder_id`** | string | `03_Customer_Registration` 資料夾 ID |
| **`private_knowledge_sheet_id`** | string | BDS v1 Tenant Private Knowledge Google Sheet ID；須符合 `BATS_DATA_CONTRACT.md` 5 Tab Contract |
| **`gcs_prefix`** | string | GCS 路徑前綴；正式值 `tenants/{sno}/` |

> **Drive 邏輯路徑對照（Schema 欄位名不變）：** 見 §6.3。`private_knowledge_folder_id` 對應 `01_Private_Layer/`；Shared 資料夾 ID 登錄於平台層 `shared/`（Phase 6 前規劃）。**不新增** `private_drive_folder_id`。

### 7.3 `industry_code` 正式定義

| `industry_code` | 產業 | `shared` 路徑 |
|-----------------|------|---------------|
| **`travel`** | 旅遊業 | `shared/travel/knowledge/` |
| **`hotel`** | 飯店民宿 | `shared/hotel/knowledge/` |
| **`restaurant`** | 美食餐廳 | `shared/restaurant/knowledge/` |
| （其他） | 未來產業 | `shared/{industry_code}/knowledge/` |

| 規則 | 說明 |
|------|------|
| **必填** | 每個 Tenant Registry Entry 須有 `industry_code` |
| **fallback 路徑** | BATS 依此欄位解析 §4 順序第 2 層 |
| **global 共用** | `shared/global/knowledge/` 對所有 `industry_code` 皆為最終 fallback |
| **擴展** | 新增產業僅新增 code + `shared/{industry}/`；見 §4.5 |

### 7.4 建議擴充欄位（非 v1 必填）

| 欄位 | 說明 |
|------|------|
| `tenant_key` | 邏輯代碼（如 `dayitravel`）；與 Product Source 對照用 |
| `created_at` / `updated_at` | 稽核 |
| `schema_version` | Registry entry schema 版本 |
| `knowledge_sources` | 各 Knowledge Source 明細（路徑、檔名、enabled） |
| `meta_sync_folder_id` | 同步紀錄附件存放（可選） |

### 7.5 契約範例（YAML 概念）

```yaml
sno: "5f99b8d665e8444d"
tenant_name: travel_b
industry_code: travel
enabled: true
drive_root_folder_id: "1AbCdEfGhIjKlMnOpQr"
itinerary_folder_id: "1ItineraryFolderId"
private_knowledge_folder_id: "1PrivateKnowledgeFolderId"
customer_registration_folder_id: "1CustomerRegFolderId"
private_knowledge_sheet_id: "1SheetIdOptional"
gcs_prefix: "tenants/5f99b8d665e8444d/"
```

> **注意：** 上例 `travel_b` 僅為 Pilot 範例；實作須從 Registry 載入，**不得** hardcode 於程式。

### 7.6 儲存位置（規劃）

| 選項 | 路徑（概念） |
|------|--------------|
| GCS 權威 | `shared/config/bds_source_registry.json` 或 `tenants/{sno}/meta/source_registry.json` |
| Host A cache | `cache/shared/bds_source_registry.json` |

正式路徑由 BDS 實作 Phase 定案；**須** 符合 Registry Driven，不寫死於 PHP。

### 7.7 `private_knowledge_sheet_id` 與 Data Contract 分工

| 項目 | 管理文件 |
|------|----------|
| **Sheet ID 登錄** | 本文件（Source Registry） |
| **5 Tab 名稱與欄位** | **`BATS_DATA_CONTRACT.md`** |
| **5 JSON 輸出路徑** | `BATS_DATA_CONTRACT.md` §6、§9；GCS 原則見 `BATS_DATA_SYNC_POLICY.md` §11 |

**正式規則：**

- `private_knowledge_sheet_id` 對應 **BDS v1 Tenant Private Knowledge Sheet**
- 此 Sheet **必須** 符合 `BATS_DATA_CONTRACT.md` 之 5 Tab Contract
- Registry **僅** 管理來源位置與 tenant / `industry_code` mapping
- **不得** 在 Registry 定義欄位格式或 JSON 結構

### 7.8 `customer_registration` 欄位說明

`customer_registration_folder_id` **必須** 登錄以利 Drive 隔離，但：

- **不** 定義 Knowledge Source 讀取
- **不** 對應 GCS `knowledge/` 路徑
- 見 `BATS_DATA_SYNC_POLICY.md` §21

---

## 8. Anti Hardcode Rule

### 8.1 正式規則

**`travel_b` 僅為 Pilot Tenant，不得作為架構特例。**

| 禁止 | 說明 |
|------|------|
| hardcode `travel_b` | 程式、BDS 管線不得寫死 tenant 名稱 |
| hardcode `5f99b8d665e8444d` | 程式不得寫死 Pilot `sno` |
| 為 travel_b fork 同步核心 | 不得複製 BDS 管線為單一 tenant 特例 |

### 8.2 新增租戶之正確方式

| 動作 | 允許 |
|------|------|
| 新增 **Registry Config** | ✅ 新增 Tenant Registry Entry + Drive 資料夾 + GCS prefix |
| 修改 **同步核心流程** | ❌ 禁止 |
| 複製 `travel_x_*.php` | ❌ 禁止 |

### 8.3 範例

| 租戶 | 知識來源 | 正確擴展 |
|------|----------|----------|
| `travel_b` | 私有 QA + shared fallback | Pilot；Registry entry |
| `travel_c` | 僅 bbctravel 商品源；知識依 Registry | 新增 entry；不修改 BDS 核心 |
| `travel_d` | grp + tourcenter；知識依 Registry | 新增 entry；不修改 BDS 核心 |

### 8.4 與 Sync Policy 對齊

本節與 `BATS_DATA_SYNC_POLICY.md` §23 **同等效力**；衝突時兩文件表述一致，均以 Registry Driven 為準。

---

## 9. Shared Layer Structure

### 9.1 Industry Shared Knowledge（正式架構）

**`shared/`** 下依 **產業** 分層；另設 **`global`** 跨產業 fallback。

```text
shared/
├── travel/
│   └── knowledge/              ← 旅遊業共用知識（shared_knowledge）
├── hotel/
│   └── knowledge/              ← 飯店民宿共用知識
├── restaurant/
│   └── knowledge/              ← 美食餐廳共用知識
├── global/
│   └── knowledge/              ← 跨產業共用知識（最終 fallback）
└── config/
    ├── product_source_catalog.json
    └── bds_source_registry.json
```

#### 產業定義

| `industry_code` | 產業 | 典型內容 |
|-----------------|------|----------|
| **`travel`** | 旅遊業 | 護照代辦、簽證、入境須知、旅遊須知 |
| **`hotel`** | 飯店民宿 | 訂房須知、入住規則、取消政策 |
| **`restaurant`** | 美食餐廳 | 訂位須知、菜單說明、營業時間規則 |
| **`global`** | 跨產業 | 所有產業通用之 fallback 知識 |

#### GCS 正式路徑

```text
shared/travel/knowledge/
shared/hotel/knowledge/
shared/restaurant/knowledge/
shared/global/knowledge/
```

與 §4 Knowledge Layer Architecture、`BATS_DATA_SYNC_POLICY.md` §11.3、§12.7 **對齊**。

### 9.2 兩層 Registry 總覽

**`shared/` = 共用層（含產業知識 + config）**  
**`tenants/{sno}/` = 租戶層**

```text
Source Registry
│
├── shared/                           ← 共用層
│   ├── travel/knowledge/             ← industry shared
│   ├── hotel/knowledge/
│   ├── restaurant/knowledge/
│   ├── global/knowledge/             ← global fallback
│   └── config/
│
└── tenants/
    └── {sno}/                        ← 租戶層
        ├── config/
        ├── knowledge/                ← tenant_private_knowledge + itinerary_data
        └── meta/
```

### 9.3 GCS 對照（與 Sync Policy §11 對齊）

| Registry 層 | GCS 路徑 |
|-------------|----------|
| `shared/{industry}/knowledge/` | `gs://{bucket}/shared/{industry}/knowledge/` |
| `shared/global/knowledge/` | `gs://{bucket}/shared/global/knowledge/` |
| `shared/config/` | `gs://{bucket}/shared/config/` |
| `tenants/{sno}/` | `gs://{bucket}/tenants/{sno}/` |

### 9.4 Shared Knowledge 內容規範

| 規範 | 說明 |
|------|------|
| **不得含 tenant 專屬機密** | 單一社報價、PII |
| **不得含租戶覆寫邏輯** | 覆寫由 §4.4、§5 Tenant Override 在讀取層處理 |
| **須標 `data_category`** | `shared_knowledge` |
| **產業隔離** | 各產業知識存於對應 `shared/{industry}/knowledge/` |
| **同步方式** | 由維運經核准流程更新；非旅行社上傳頁 |

### 9.5 租戶層內容規範

| 路徑 | data_category |
|------|---------------|
| `tenants/{sno}/knowledge/*.json`（5 檔） | `tenant_private_knowledge`（見 `BATS_DATA_CONTRACT.md` §6） |
| `tenants/{sno}/knowledge/itinerary/` | `itinerary_data` |
| `tenants/{sno}/config/` | tenant 設定（非 Knowledge Source 三類） |

---

## 10. Future Roadmap

### 10.1 Future Industry Expansion（產業擴展規則）

產業擴展之 **正式規則** 見 **§4.5 Industry Expansion Rule**（含 `beauty`、`auto`、`retail`、`education`、`medical` 等）。

| 允許 | 禁止 |
|------|------|
| 新增 `industry_code` | 修改 Knowledge Resolution Flow（§4.4） |
| 新增 `shared/{industry}/knowledge/` | 修改 Source Registry Core |
| 新增 Tenant Registry Entry | 修改 BDS 同步核心（§8） |

### 10.2 建議 Phase 順序

| Phase | 內容 |
|-------|------|
| **Registry v1** | JSON Registry + `industry_code`；travel_b Pilot（`travel`） |
| **Registry v2** | `shared/travel/knowledge/` + §4.4 Knowledge Resolution Order 實作 |
| **Industry v3** | `hotel`、`restaurant` 產業層上線 |
| **Admin UI** | 維運可視化 |
| **Onboarding Wizard** | 標準化多產業 tenant 上架 |
| **Auto Validation** | 與 BDS Safety Rule 整合 |

### 10.3 規劃項目（保留）

| 項目 | 說明 | 狀態 |
|------|------|------|
| **Source Registry Admin UI** | 視覺化管理 Drive ID、Sheet ID、`industry_code`、GCS 對照 | 規劃中 |
| **Tenant Onboarding Wizard** | 新租戶：Drive 三層 → Registry（含 `industry_code`）→ 驗證 | 規劃中 |
| **Auto Validation** | 檢查 Registry、`industry_code`、Folder 隔離 | 規劃中 |

### 10.4 明確排除（v1）

| 排除 | 說明 |
|------|------|
| per-tenant PHP config fork | 違反 §8 |
| per-industry BDS 管線 fork | 違反 §4.5、§10.1 |
| 未修訂 SSOT 前新增 Registry 欄位為正式必填 | 須先修訂本文件 |

### 10.5 Phase 6 Planning — Future Drive Source Registry

> **Status: Planned for Phase 6**  
> **本節為 Planning；尚未生效。**  
> **不修改現有 Registry JSON Schema；不新增 `private_drive_folder_id` 為正式必填欄位。**

#### 10.5.1 定位

定義 **Google Drive Connector Framework** 之概念模型，供 Phase 6 實作前對齊；與 Phase 5 **Structured Sheet → GCS** 管線 **分離**。

#### 10.5.2 Drive Source Model — Google Drive（Unstructured Data）

| 項目 | 說明 |
|------|------|
| **資料型態** | **Unstructured Data**（Content First） |
| **允許檔案類型** | PDF、Image、Excel、Word、PowerPoint |
| **與 Sheet 分工** | Sheet = Structured（`tenant_private_knowledge`）；Drive = Unstructured（Phase 6+） |
| **BDS v1 MVP** | **不實作** Drive API；本節僅框架 |

```text
Google Drive（Unstructured）
        ↓
Drive Connector（Phase 6 — Planned）
        ↓
Archive / 轉換管線（待 Phase 6 細化）
        ↓
GCS Knowledge Layer（受控路徑；非 Phase 5 範圍）
```

#### 10.5.3 Tenant Folder Model

| 原則 | 說明 |
|------|------|
| **One Tenant One Folder** | 每個 Tenant 對應 **一個** 專屬 Drive Folder：`tenants/{tenant_key}/` |
| **Tenant Private Only** | 租戶資料夾下 **僅** `01_Private_Layer/`；**不含** Shared Layer |
| **Folder Default = Private** | 預設 Private；見 `BATS_DATA_OWNERSHIP_POLICY.md` §2.5、§2.7 |
| **禁止跨 Tenant 共用 Folder** | 違反 Tenant Isolation |
| **Shared Layer** | 位於平台層 `shared/{industry_code}/`；須 **Registry + Policy** 啟用 |

**Pilot：** `travel_a`、`travel_b`、`travel_c` 各自擁有 `01_Private_Layer/`（見 §6.5.2）。

#### 10.5.4 與現有 Registry 邊界

| 項目 | Phase 6 Planning | 現行 Registry v1 |
|------|------------------|------------------|
| **`private_knowledge_sheet_id`** | 不變 | **維持** Structured Sheet SSOT |
| **`private_drive_folder_id`** | **未引入** | 本節 **不新增** 正式欄位 |
| **Registry JSON Schema** | **不修改** | 維持現行結構 |
| **生效時機** | Phase 6 實作前另開 SSOT 修訂 | — |

#### 10.5.5 明確不做（Planning 階段）

| 不做 | 說明 |
|------|------|
| Google Drive API 實作 | 僅文件框架 |
| RAG / Vector 索引 | 未納入 |
| Cron / LINE OA | 非本框架範圍 |
| 修改 `private_knowledge_sheet_id` 語意 | Sheet 仍為 Structured SSOT |

---

## 相關文件

| 文件 | 關係 |
|------|------|
| `BATS_DATA_SYNC_POLICY.md` | 同步模式、Knowledge Layer 三層（§12.7）、Data Category、Drive 三層、Anti Hardcode |
| `BATS_DATA_CONTRACT.md` | Data Contract L3 SSOT — `private_knowledge_sheet_id` 對應之 5 Tab / 5 JSON |
| `TENANT_SOURCE_REGISTRY_POLICY.md` | 商品源（bbctravel / grp / tourcenter）Registry；與本文件 **互補** |
| `TENANT_SOURCE_RUNTIME_BRIDGE_V1.md` | Intelligence Layer 消費 `product_sources` |
| `DOCUMENTATION_GOVERNANCE_POLICY.md` | 本文件為 BDS Source Registry 領域 L1 SSOT |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.5** | 2026-06-10 | Phase 6 Pre-Governance：§6.5 Google Drive Platform Layer Architecture；Shared 移出租戶資料夾 |
| **v1.4** | 2026-06-09 | 新增 §10.5 Phase 6 Planning — Future Drive Source Registry（Framework only；Schema 不變） |
| **v1.3** | 2026-06-08 | `private_knowledge_sheet_id` cross-ref `BATS_DATA_CONTRACT.md`；Registry / Contract 職責分工 |
| **v1.2** | 2026-06-08 | 新增 §4 Knowledge Layer Architecture（Tenant / Industry / Global 三層）、§4.2 產業範例、§4.4 Resolution Order、§4.5 產業擴展 |
| **v1.1** | 2026-06-08 | Industry Shared Knowledge（travel/hotel/restaurant/global）、`industry_code`、三層 Priority / Override、產業擴展規則 |
| **v1.0** | 2026-06-08 | 第一版：Source Registry SSOT、三類 Knowledge Source、Priority / Override、Tenant Contract、Shared Layer |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| 文件狀態 | **Draft — 待審核** |
| 程式實作 | **未開始** |
| Git commit | **尚未提交** |
