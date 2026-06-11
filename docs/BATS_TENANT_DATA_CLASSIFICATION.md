# BATS_TENANT_DATA_CLASSIFICATION.md

**專案：** BBC AI SaaS / BATS / BDS / Travel Data Center  
**定位：** L1 架構政策層 — Tenant Private Data 三分流分類 SSOT  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_DATA_CONTRACT.md`、`BATS_DATA_SYNC_POLICY.md`、`BATS_DATA_SOURCE_REGISTRY.md`、`BATS_DATA_OWNERSHIP_POLICY.md`、`BATS_SHARED_KNOWLEDGE_CONTRACT.md`  
**適用範圍：** 所有租戶（`travel_a`、`travel_b`、`travel_c` 及未來產業租戶）  
**適用對象：** ChatGPT、Cursor、開發者、維運人員、BBC Admin  
**衝突處理：** BDS 同步原則以 `BATS_DATA_SYNC_POLICY.md` 為準；Knowledge Contract 以 `BATS_DATA_CONTRACT.md` 為準；Shared Knowledge 以 `BATS_SHARED_KNOWLEDGE_CONTRACT.md` 為準；**Tenant Private Data 三分流邊界以本文件為準**。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §1.6 | Knowledge Layer Model（Private + Shared） |
| §1.7 | BDS Data Input Strategy |
| §2 | Category A：Itinerary Data |
| §3 | Category B：Knowledge Data（BDS） |
| §4 | Category C：Traveler Registration Data |
| §5 | PII / Host Boundary Rule |
| §6 | Future Documents |
| §7 | Cross References |

---

## 1. Purpose

### 1.1 文件定位

本文件定義 **Tenant Private Data（租戶私有資料）** 的 **三大資料域（三分流）**，避免下列資料在架構、同步管線、儲存路徑上混在一起：

| 分流 | 名稱 | 核心語意 |
|------|------|----------|
| **Category A** | Itinerary Data | 行程／商品／出團營運資料 |
| **Category B** | Knowledge Data（BDS） | 客服知識、公司資料、服務說明 |
| **Category C** | Traveler Registration Data | 旅客報名與個資（PII） |

### 1.2 本文件回答

| 問題 | 章節 |
|------|------|
| 行程 PDF 屬於哪一類？ | §2 Category A |
| BDS 管理的 Google Sheet 5 Tabs 屬於哪一類？ | §3 Category B |
| LINE OA 蒐集的旅客姓名、護照屬於哪一類？ | §4 Category C |
| Host A / Host B 各能存什麼？ | §5 |
| 與既有 BDS `data_category` 如何對照？ | §1.3 |
| Private Layer 與 Shared Layer 如何分工？ | §1.6 |
| Shared Layer 是否等於公開資料？ | §1.6 |
| Google Sheet 與 Google Drive 輸入策略？ | §1.7 |

### 1.3 與 `BATS_DATA_SYNC_POLICY.md` §12 對照

> **注意：** 本文件 **Category A/B/C** 為 **Tenant Private Data 三分流**；與 `BATS_DATA_SYNC_POLICY.md` §12 之 BDS Data Category 編號 **語意不同**，請依下表對照，避免混淆。

| 本文件（Tenant Private Data） | 典型 `data_category` | Sync Policy §12 對照 |
|------------------------------|----------------------|----------------------|
| **Category A — Itinerary** | `itinerary_data` | §12 Category A |
| **Category B — Knowledge** | `tenant_private_knowledge`、`shared_knowledge` | §12 Category B + Shared Layer |
| **Category C — Registration** | `customer_registration` | 非 Knowledge；見 §21 |

```text
Tenant Private Data（本文件）
├── A  Itinerary        →  itinerary_data
├── B  Knowledge (BDS)  →  tenant_private_knowledge + shared_knowledge
└── C  Registration     →  customer_registration（禁止進 Knowledge）
```

### 1.4 設計原則

| 原則 | 說明 |
|------|------|
| **分流不混流** | A / B / C 不得寫入同一 GCS 路徑或同一 JSON schema |
| **BDS v1 僅 B** | BDS Phase 1～4 僅處理 Category B 之 `tenant_private_knowledge`（Sheet Reader）；GCS 寫入 Phase 5+ |
| **C 禁止進 BDS** | 旅客報名 PII 不得進入 BDS Knowledge 管線 |
| **A 下一階段** | Itinerary 保留給 product / search 管線，非 BDS v1 |

### 1.5 本文件不做

| 不做 | 說明 |
|------|------|
| 定義 Itinerary 欄位 schema | 見未來 `itinerary_data` Contract |
| 定義 Registration 欄位 schema | 見 §6 `BATS_TRAVELER_REGISTRATION_CONTRACT.md` |
| 定義 BDS Sheet 欄位 | 見 `BATS_DATA_CONTRACT.md` |
| 實作 Google API / GCS | 實作 Phase 另開 |

### 1.6 Knowledge Layer Model（Private + Shared）

#### 1.6.1 正式定義

**旅行社 Knowledge 資料（Category B）** 由兩層組成：

```text
旅行社 Knowledge 資料（Category B）
├── Private Layer（私有層）   →  tenant_private_knowledge
└── Shared Layer（共用層）    →  shared_knowledge
```

| Layer | 中文 | `data_category` | GCS 路徑（概念） | 歸屬 |
|-------|------|-----------------|------------------|------|
| **Private Layer** | 私有層 | `tenant_private_knowledge` | `tenants/{sno}/knowledge/` | 單一租戶 |
| **Shared Layer** | 共用層 | `shared_knowledge` | `shared/{industry_code}/knowledge/`、`shared/global/knowledge/` | BBC 平台／產業維護方 |

> **Itinerary（Category A）** 與 **Registration（Category C）** 不屬於本 Layer Model；本節僅規範 **Knowledge（Category B）** 之 Private + Shared 分工。

#### 1.6.2 Shared Layer ≠ Public Layer

| 項目 | Shared Layer | Public Layer（本架構 **不存在** 此層） |
|------|--------------|--------------------------------------|
| **語意** | 跨租戶 **fallback 知識**；由平台／產業維護 | 對外公開、匿名可讀之資料層 |
| **預設可見性** | **Default Private**（見 §1.6.3） | 預設公開 |
| **讀取邊界** | BATS 依 Resolution Order 消費 GCS JSON；**非** 公開 URL | 無限制對外讀取 |
| **PII** | **禁止** | 本架構不採用 |

**正式規則：**

- **Shared Layer 不是 Public Layer**；名稱「Shared」指 **知識解析時的跨租戶 fallback 共用**，**不是** 對網際網路或任意第三方公開。
- 不得將 Shared Knowledge 等同於「公開 FAQ 網頁」或「無權限 Google 連結」。

#### 1.6.3 Shared Layer 預設：Default Private

| 項目 | 規則 |
|------|------|
| **預設存取** | **Default Private** — 未經明確政策啟用共享前，視為非公開 |
| **GCS** | `shared/` 路徑僅供 BDS / BATS 受控讀寫；**非** 公開 bucket 政策 |
| **Google Drive** | Shared Layer 相關 Drive 資料夾 **預設不共享**（見 `BATS_DATA_OWNERSHIP_POLICY.md` §2.5） |
| **啟用共享** | 須透過 **Explicit Share Policy** — Registry 登錄 + Ownership Policy 明確授權（見 `BATS_SHARED_KNOWLEDGE_CONTRACT.md` §3.4） |

#### 1.6.4 Google Drive 與 Shared Layer

| 項目 | 說明 |
|------|------|
| **角色** | Google Drive 為 Shared Layer 之 **Archive／協作儲存載體之一**（與 GCS Knowledge Layer 並存） |
| **非唯一來源** | BATS 執行時 **不** 直接讀 Drive；正式消費層為 **GCS JSON**（見 `BATS_DATA_SYNC_POLICY.md` §18） |
| **租戶 Private** | `tenants/{tenant_key}/01_Private_Layer/` 屬 **Private Layer** Archive（單一租戶） |
| **產業 Shared** | `shared/{industry_code}/02_Shared_Layer/` 屬 **平台層** Industry Shared Archive；**非** 單一 Tenant |
| **全球 Shared** | `shared/global/02_Global_Shared_Layer/` 屬 **平台層** Global Shared Archive |
| **禁止混淆** | 不得將 Shared 置於租戶資料夾下；不得因路徑含「shared」即視為 Public |

#### 1.6.5 與三分流對照

| 本文件三分流 | Knowledge Layer |
|--------------|-----------------|
| **Category B — Tenant Private** | Private Layer（`tenant_private_knowledge`） |
| **Category B — Shared fallback** | Shared Layer（`shared_knowledge`） |
| Category A / C | **不適用** Private + Shared Layer Model |

#### 1.6.6 Google Drive Platform Layer Architecture（Phase 6 Pre-Governance）

**營運帳號：** `bbcshops88@gmail.com` Google Drive

```text
├── tenants/{tenant_key}/01_Private_Layer/     ← Tenant Private（單一租戶）
├── shared/{industry_code}/02_Shared_Layer/    ← Industry Shared（平台層）
├── shared/global/02_Global_Shared_Layer/    ← Global Shared（平台層）
└── registrations/                             ← Category C（平台層）
```

| 原則 | 說明 |
|------|------|
| **Shared 不屬於單一旅行社** | 禁止 `tenants/{tenant_key}/02_Shared_Layer/` |
| **Default Private** | 所有 Drive 資料夾預設 Private |
| **Tenant 不自動使用 Shared** | 須 Registry + Policy 明確啟用 |
| **GCS 不變** | Drive 為 Archive Source of Truth；GCS 為 Runtime Knowledge Source |
| **Shared 預設不進 Runtime** | 須 Registry + Policy 才能進 GCS Metadata / Knowledge / Future RAG |

**Runtime Source：** BATS Runtime **不得** 依賴即時 Google Drive 搜尋。見 `BATS_DRIVE_CONNECTOR_SCOPE.md` §2、`BATS_DATA_SYNC_POLICY.md` §18.5。

**Phase 6 正名：** 6A Manual Sync → 6B～6E Drive Connector。見 `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` §3.1。

詳見 `BATS_DATA_SOURCE_REGISTRY.md` §6.5、`BATS_DATA_OWNERSHIP_POLICY.md` §2.7、`BATS_DRIVE_METADATA_CONTRACT.md`。

### 1.7 BDS Data Input Strategy

本節定義 **Category B（Knowledge）** 與 **Category A（Itinerary）** 之 **輸入載體策略**；與 Private / Shared Layer（§1.6）正交。

#### 1.7.1 總覽

```text
BDS Data Input Strategy
├── Google Sheet     →  Structured Data（結構化）
└── Google Drive     →  Unstructured Data（非結構化）
```

| 載體 | 資料型態 | BDS v1 狀態 | 處理 Phase |
|------|----------|-------------|------------|
| **Google Sheet** | **Structured Data** | **Phase 4 已完成**（Reader + Live Read + Pipeline Dry-run） | Phase 4 |
| **Google Drive** | **Unstructured Data** | **未實作** | **Phase 6B～6E** Drive Connector（6A = Sheet Manual Sync） |

#### 1.7.2 Google Sheet — Structured Data（Contract First）

| 項目 | 規則 |
|------|------|
| **定位** | `tenant_private_knowledge` 之 **結構化** 維護入口 |
| **原則** | **Contract First** — Tab 名稱、Header 名稱須符合 `BATS_DATA_CONTRACT.md` |
| **Tab Name** | **固定** — 5 Required Tabs（§3.2） |
| **Header Name** | **固定** — 英文 snake_case only（`BATS_DATA_CONTRACT.md` §1.4 決策 A） |
| **Data Content** | **可自由填寫** — 在 Contract 約束下由租戶維護內容 |
| **BDS 管線** | Sheet → Reader → Parser → Validator → JSON（dry-run / 未來 GCS） |
| **Registry** | `private_knowledge_sheet_id`（見 `BATS_DATA_SOURCE_REGISTRY.md` §7） |

> **Phase 4 Close-out（2026-06）：** Google Sheet Reader、Live Read、Pipeline Dry-run 均已 **PASS**（見 `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` §3 Phase 4）。

#### 1.7.3 Google Drive — Unstructured Data（Content First）

| 項目 | 規則 |
|------|------|
| **定位** | Archive／協作層；存放 **非結構化** 原始檔 |
| **原則** | **Content First** — 以檔案內容為主；**不受** `BATS_DATA_CONTRACT.md` Tab／欄位約束 |
| **允許類型** | PDF、Image（JPG 等）、Excel、Word、PowerPoint 等 |
| **典型歸類** | Category A Itinerary、Drive Archive；**非** BDS v1 Knowledge JSON 來源 |
| **BDS v1** | **不處理** Drive 檔案解析與同步 |
| **未來** | **Phase 6B～6E** Drive Connector（6A = Manual Sync）；P1 SSOT 見 `BATS_DRIVE_*` 四文件 |

#### 1.7.4 禁止混淆

| 禁止 | 說明 |
|------|------|
| 將 Drive PDF 當 Knowledge Sheet 同步 | Drive 非 Structured Contract 輸入 |
| 將 Sheet 當 Unstructured 跳過 Validation | Sheet 必須走 Contract |
| Phase 4 實作 Drive API | Phase 4 僅 Sheets API 唯讀 |
| 提前引入 `private_drive_folder_id` | 延後至 Phase 6 前 Registry 規劃 |

### 1.8 Structured Knowledge Source SSOT

> **正式原則：** 所有 **Structured Knowledge**（FAQ、表格、條列、服務型知識）之 **輸入來源均為 Google Sheet**；**不是** Google Drive PDF／RAG。

#### 1.8.1 三層 Knowledge 輸入對照

| Knowledge 層級 | 輸入來源（Structured） | GCS Runtime 輸出 | BDS 同步狀態 |
|----------------|------------------------|------------------|--------------|
| **Tenant Private Knowledge** | Google Sheet（`private_knowledge_sheet_id`） | `tenants/{sno}/knowledge/` | Phase 6A（規劃中） |
| **Industry Shared Knowledge** | Google Sheet（`shared/{industry}/` 治理） | `shared/{industry_code}/knowledge/` | **未實作**（Contract 已定） |
| **Global Shared Knowledge** | Google Sheet（平台治理） | `shared/global/knowledge/` | **未實作**（Contract 已定） |

**統一公式：**

```text
Structured Knowledge = Google Sheet（Input Source）
        ↓ BDS Sync
GCS Knowledge Layer（Runtime Source）→ Gemini / BATS Search
```

#### 1.8.2 與 Google Drive 分工

| 載體 | 角色 | 典型內容 |
|------|------|----------|
| **Google Sheet** | Structured Knowledge **Input** | FAQ、QA 條列、服務項目、入境規定、行李規定 |
| **Google Drive** | Archive Source **only** | PDF、Image、Word、PPT、DM 原件 |

| 知識型態 | 應放置載體 | 說明 |
|----------|------------|------|
| FAQ 型、表格型、條列型、服務型 | **Google Sheet** | 可經 BDS → GCS → Gemini **不必等待** PDF／RAG |
| PDF / Image / Word / PPT / DM | **Google Drive** | Archive；Phase 6B+ promote 至 GCS Archive |

#### 1.8.3 Industry Shared 典型範例（應以 Sheet 治理）

| 範例主題 | `industry_code` 方向 | 未來 GCS |
|----------|----------------------|----------|
| 護照新辦／效期規定 | `travel`（等） | `shared/travel/knowledge/` |
| 台胞證申請規定 | `travel` | 同上 |
| 日本／泰國入境規定 | `travel` | 同上 |
| 航空行李規定 | `travel` | 同上 |
| 國際旅遊常識 | `travel` / `global` | `shared/{industry}/` 或 `shared/global/knowledge/` |

#### 1.8.4 Shared Layer 治理（不變）

| 原則 | 說明 |
|------|------|
| **Shared Layer ≠ Public Layer** | 受控 fallback |
| **Default Private** | 預設不對外 |
| **預設不進 Runtime** | 未 Policy 啟用前不進 GCS 消費路徑 |
| **Registry + Policy 啟用** | 才能同步至 GCS 並進入 Runtime |

**SSOT 交叉引用：** `BATS_DATA_CONTRACT.md` §1.5、`BATS_SHARED_KNOWLEDGE_CONTRACT.md` §3.6、`BATS_DATA_SYNC_POLICY.md` §18.7

### 1.9 Knowledge Priority Rule（P1-7）

> **SSOT：** `BATS_DATA_SOURCE_REGISTRY.md` §4.4、`BATS_DATA_SYNC_POLICY.md` §18.8

#### 1.9.1 Knowledge Retrieval Priority

| Level | 名稱 | 路徑 |
|-------|------|------|
| **1** | Tenant Private Knowledge | `tenants/{sno}/knowledge/` |
| **2** | Industry Shared Knowledge | `shared/{industry_code}/knowledge/` |
| **3** | Global Shared Knowledge | `shared/global/knowledge/` |
| **4** | Human Service | 轉人工 |

**Tenant Private > Industry Shared > Global Shared > Human Service**

#### 1.9.2 Fallback Rule

Level 1 無資料 → 2 → 3 → **4 Human Service**。禁止 AI 自行推測、幻覺補充、編造旅遊規定（`BATS_GEMINI_RENDERER_CONTRACT.md` §9）。

#### 1.9.3 Industry Shared First

產業專屬知識優先 `shared/{industry}/`；`shared/global/` 僅跨產業共通與暫存。**Status:** Reserved For Future Multi-Industry Expansion。

**Sheet Contract：** `BATS_SHARED_KNOWLEDGE_SHEET_CONTRACT.md`

---

## 2. Category A：Itinerary Data

### 2.1 定義

**Category A — Itinerary Data（行程資料）** 為租戶之 **商品／出團／可售行程** 營運資料，供行程搜尋、商品展示、庫存與出發資訊使用。

| 項目 | 說明 |
|------|------|
| **`data_category`** | `itinerary_data` |
| **性質** | 商品／營運資料；**非** 客服知識 |
| **BDS v1** | **不納入** BDS Knowledge 同步 |

### 2.2 包含資料類型

| 類型 | 範例 |
|------|------|
| **PDF** | 行程說明書、DM、出團通知 |
| **JPG / Image** | 行程圖片、飯店圖、DM 圖 |
| **出團動態 Excel** | 每日出團狀態、房表、分房 |
| **行程表** | 每日行程、景點、交通 |
| **價格** | 團費、加床價、單房差 |
| **出發日期** | 出團日、回程日 |
| **可售數量** | 剩餘名額、總席次 |
| **已報名人數** | 當團報名統計（**非** 個別旅客 PII） |

### 2.3 建議路徑（概念）

| 層級 | 路徑 |
|------|------|
| **GCS（租戶）** | `tenants/{sno}/knowledge/itinerary/` |
| **Drive** | `01_Product_Data` 或租戶商品資料夾（見 `BATS_DATA_SYNC_POLICY.md` §20） |

### 2.4 與 BDS / BATS 關係

| 系統 | 關係 |
|------|------|
| **BDS v1** | **不處理** Category A |
| **BATS** | 行程搜尋走 Host B API + Multi-Source URL；**非** Knowledge JSON |
| **Gemini** | 行程意圖由搜尋結果注入；**非** `knowledge/` 五 JSON |

### 2.5 未來方向

Category A 未來可進入 **itinerary / product data flow**（含 PDF Parser、Image Parser、商品 Excel），但 **不屬於 BDS v1 Knowledge Data**，須另開 Contract 與同步 Phase。

---

## 3. Category B：Knowledge Data（BDS）

### 3.1 定義

**Category B — Knowledge Data（BDS）** 為租戶與平台之 **客服／營運知識資料**，供 BATS 客服問答、Gemini context 注入使用。

**正式組成：** Private Layer + Shared Layer（見 §1.6）。

| 項目 | 說明 |
|------|------|
| **Private Layer `data_category`** | `tenant_private_knowledge` |
| **Shared Layer `data_category`** | `shared_knowledge` |
| **管理系統** | **BDS**（BATS Data Sync） |
| **BDS v1 MVP** | 僅 `tenant_private_knowledge`（1 Sheet / 5 Tabs / 5 JSON） |

### 3.2 Tenant Private Knowledge（BDS v1）

| Google Sheet Tab | JSON Output | 說明 |
|------------------|-------------|------|
| `company_profile` | `company_profile.json` | 公司資料 |
| `qa` | `service_qa.json` | 問答 |
| `external_product_links` | `external_product_links.json` | 外部商品連結 |
| `service_items` | `service_items.json` | 服務項目 |
| `special_prices` | `special_prices.json` | 特殊價格 |

**正式 Contract：** `BATS_DATA_CONTRACT.md`

### 3.3 Shared Knowledge Layer（Shared Layer）

Category B 之 **Shared Layer（共用層）** 為跨租戶 fallback 知識；完整 Layer 定義見 **§1.6**。

| 層級 | 路徑 | Contract |
|------|------|----------|
| **Industry Shared Layer** | `shared/{industry_code}/knowledge/` | `BATS_SHARED_KNOWLEDGE_CONTRACT.md` |
| **Global Shared Layer** | `shared/global/knowledge/` | 同上 |

| 原則 | 說明 |
|------|------|
| **Shared ≠ Public** | Shared Layer **不是** Public Layer；見 §1.6.2 |
| **Default Private** | Drive / 存取預設不對外共享；見 §1.6.3 |
| **Governance** | Explicit Share Policy 見 `BATS_SHARED_KNOWLEDGE_CONTRACT.md` §3.4 |

**讀取優先序：** Private Layer（Tenant）> Industry Shared > Global Shared（見 `BATS_DATA_SOURCE_REGISTRY.md` §4.4）

### 3.4 正式 GCS 路徑

```text
tenants/{sno}/knowledge/
├── company_profile.json
├── service_qa.json
├── external_product_links.json
├── service_items.json
└── special_prices.json

shared/{industry_code}/knowledge/    ← shared_knowledge（fallback）
shared/global/knowledge/             ← shared_knowledge（最終 fallback）
```

### 3.5 與 Category A / C 邊界

| 禁止 | 說明 |
|------|------|
| **Itinerary 混入** | PDF 行程表、商品 Excel **不得** 寫入 `tenants/{sno}/knowledge/` 五 JSON |
| **Registration 混入** | 旅客姓名、護照、身分證 **不得** 寫入 Knowledge JSON |
| **PII 混入** | Knowledge 不得含可識別個人之報名資料 |

### 3.6 BDS 實作現況

| Phase | 狀態 | 範圍 |
|-------|------|------|
| Phase 1 | **已完成** ✅ | Mock Parser |
| Phase 2 | **已完成** ✅ | Validator |
| Phase 3 | **已完成** ✅ | JSON Writer Dry-run |
| Phase 4 | **已完成** ✅ | Google Sheet Reader（含 4.1 Live Read、4.2 Pipeline Dry-run） |
| Phase 5 | 規劃中 | GCS Writer Controlled Mode |
| Phase 6 | 規劃中 | Manual Sync、Google Drive Source Connector |

---

## 4. Category C：Traveler Registration Data

### 4.1 定義

**Category C — Traveler Registration Data（旅客報名資料）** 為 **交易／個資資料**，記錄旅客報名、證件與聯絡資訊。

| 項目 | 說明 |
|------|------|
| **`data_category`** | `customer_registration` |
| **性質** | 交易資料 + PII；**非** 知識資料 |
| **BDS** | **禁止** 納入 BDS Knowledge 管線 |

### 4.2 包含資料類型

| 欄位類型 | 範例 |
|----------|------|
| **姓名** | 中文姓名 |
| **英文姓名** | Passport name |
| **生日** | Date of birth |
| **身分證** | 身分證字號 |
| **護照號碼** | Passport number |
| **電話** | 手機、市話 |
| **Email** | 聯絡信箱 |
| **緊急聯絡人** | 姓名、電話、關係 |

### 4.3 蒐集與寫入流程（概念）

| 步驟 | 說明 |
|------|------|
| 1 | 旅客透過 **LINE OA** 填寫報名資料 |
| 2 | **Host A** 接收並 **短暫暫存**（見 §5） |
| 3 | 確認後寫入 **Google Drive / Google Sheet** |
| 4 | 以 **出發日期（departure_date）** 為單位組織資料 |
| 5 | 同一旅客可 **新增或更新**（upsert） |

```text
LINE OA（旅客）
        ↓
Host A（短暫暫存 → 確認 → 刪除暫存）
        ↓
Google Drive / Google Sheet（03_Customer_Registration）
        ↓
（不進入 BDS / GCS knowledge / Gemini）
```

### 4.4 儲存位置

| 層級 | 允許 | 說明 |
|------|------|------|
| **Google Drive** | ✅ | `03_Customer_Registration`（Archive / 檢視） |
| **Google Sheet** | ✅ | 依出發日期分 sheet / 分檔（未來 Contract 定義） |
| **GCS `knowledge/`** | ❌ | 禁止 |
| **BDS 同步** | ❌ | 禁止 |
| **Gemini Context** | ❌ | 禁止 |
| **BATS Search / QA** | ❌ | 禁止 |

### 4.5 與 Category A / B 邊界

| 資料 | 歸類 | 原因 |
|------|------|------|
| 當團 **已報名人數**（統計） | Category A | 商品／庫存 metadata；非個別 PII |
| 個別旅客 **護照號碼** | Category C | PII |
| 客服 **QA「如何報名」** | Category B | 知識說明；非實際報名資料 |
| 行程 **PDF** | Category A | 商品資料；非報名交易 |

---

## 5. PII / Host Boundary Rule

### 5.1 正式規則

下列規則適用 **Category C（Traveler Registration）** 及所有旅客 PII；與 `BATS_DATA_SYNC_POLICY.md` §21 一致。

### 5.2 Host B 邊界

**Host B 不得儲存下列敏感個資：**

| 禁止儲存 | 範例 |
|----------|------|
| 旅客姓名 | 中文名、英文名 |
| 生日 | Date of birth |
| 身分證字號 | National ID |
| 護照號碼 | Passport number |
| 完整電話 / Email | 可識別個人之聯絡方式 |
| 緊急聯絡人 PII | 姓名 + 電話 |

**Host B 僅可儲存非敏感 metadata，例如：**

| 允許 metadata | 說明 |
|---------------|------|
| `registration_id` | 報名紀錄識別 |
| `sno` | 租戶識別 |
| `tour_id` | 行程／商品識別 |
| `departure_date` | 出發日期 |
| `traveler_count` | 報名人數（統計） |
| `sheet_row_id` | Google Sheet 列參照 |
| `status` | 報名狀態（pending / confirmed 等） |
| `created_at` / `updated_at` | 時間戳記 |

### 5.3 Host A 邊界

| 規則 | 說明 |
|------|------|
| **短暫暫存** | Host A 可於 LINE OA 流程中 **短暫** 暫存旅客填寫內容 |
| **確認後寫入** | 使用者確認後寫入 Google Drive / Google Sheet |
| **刪除暫存** | 寫入完成後 **必須刪除** Host A 暫存之 PII |
| **禁止長期保存** | Host A 不得作為 PII 權威儲存庫 |
| **禁止進 Knowledge** | 暫存與寫入流程 **不得** 觸發 BDS Knowledge 同步 |

### 5.4 系統邊界總覽

```text
                    ┌─────────────────────────────────────┐
                    │  Category C — Traveler Registration │
                    │  （PII：姓名、證件、聯絡方式）        │
                    └─────────────────────────────────────┘
                                      │
              Host A 短暫暫存 ────────┼──────── Google Sheet / Drive
              （寫入後刪除）         │
                                      ✕
                    ┌─────────────────┴─────────────────┐
                    │  BDS / GCS knowledge / Gemini      │
                    └───────────────────────────────────┘

Host B：僅 metadata（registration_id、sno、tour_id、departure_date、status…）
```

### 5.5 與 BDS Safety Rule

| 規則 | 說明 |
|------|------|
| **PII 不得進 Validator 通過之 Knowledge JSON** | 見 `BATS_DATA_CONTRACT.md` §8（`BDC_PII_DETECTED`） |
| **Registration 不得進 BDS** | 見 `BATS_DATA_SYNC_POLICY.md` §21 |
| **整檔 Fail** | Knowledge 含 PII → 同步 Fail；不得覆蓋正式 JSON |

---

## 6. Future Documents

### 6.1 P2 規劃文件

| 文件 | 優先級 | 說明 |
|------|--------|------|
| **`BATS_TRAVELER_REGISTRATION_CONTRACT.md`** | **P2** | Category C 正式欄位、Sheet 結構、upsert 規則、departure_date 分組 |

### 6.2 建立時機

| 條件 | 說明 |
|------|------|
| **前置完成** | **BDS Phase 5 GCS Writer Controlled Mode** 完成後 |
| **原因** | 先穩固 Category B Knowledge 同步；再規劃 Category C Registration Contract |
| **不阻塞 Phase 4** | Phase 4 Google Sheet Reader **僅** 讀取 `tenant_private_knowledge` Sheet |

### 6.3 其他 Future 文件（參考）

| 文件 | 類別 | 狀態 |
|------|------|------|
| `itinerary_data` Contract | Category A | 規劃中（`BATS_DATA_CONTRACT.md` §12） |
| `BATS_SHARED_KNOWLEDGE_CONTRACT.md` | Category B（Shared） | ✅ 已建立 |
| Registration Admin UI | Category C | 規劃中 |

---

## 7. Cross References

### 7.1 文件體系

```text
L1 SSOT
├── BATS_TENANT_DATA_CLASSIFICATION.md  ← 本文件：A/B/C 三分流
├── BATS_DATA_SYNC_POLICY.md              同步原則、§12 Data Category、§21 Registration
├── BATS_DATA_CONTRACT.md                 Category B Tenant Knowledge Contract
├── BATS_SHARED_KNOWLEDGE_CONTRACT.md     Category B Shared Layer Contract
├── BATS_DATA_SOURCE_REGISTRY.md          Registry、路徑、Knowledge Layer
└── BATS_DATA_OWNERSHIP_POLICY.md         歸屬、寫入邊界
```

### 7.2 引用對照

| 文件 | 與本文件關係 |
|------|--------------|
| `BATS_DATA_CONTRACT.md` | Category B — 5 Tab / 5 JSON；禁止 PII |
| `BATS_DATA_SYNC_POLICY.md` | §12 三類 Knowledge、`itinerary_data`；§21 `customer_registration` 排除 |
| `BATS_DATA_SOURCE_REGISTRY.md` | Drive 三層、`private_knowledge_sheet_id`、Knowledge Layer |
| `BATS_DATA_OWNERSHIP_POLICY.md` | Tenant / Shared 寫入邊界；Registration 不進 Knowledge |
| `BATS_SHARED_KNOWLEDGE_CONTRACT.md` | Category B 之 Shared 層 JSON Contract |

### 7.3 三分流快速對照表

| 項目 | Category A | Category B | Category C |
|------|------------|------------|------------|
| **名稱** | Itinerary Data | Knowledge Data（BDS） | Traveler Registration |
| **`data_category`** | `itinerary_data` | `tenant_private_knowledge` / `shared_knowledge` | `customer_registration` |
| **BDS v1** | ❌ | ✅（Tenant only） | ❌ |
| **GCS 路徑** | `knowledge/itinerary/` | `knowledge/*.json`、`shared/` | ❌ 禁止 `knowledge/` |
| **含 PII** | 通常否 | 禁止 | 是 |
| **Gemini** | 搜尋結果注入 | 客服知識注入 | ❌ 禁止 |
| **Host B 儲存** | 商品 metadata | 不存 PII | 僅非敏感 metadata |

### 7.4 衝突處理

| 議題 | SSOT |
|------|------|
| Tenant Private Data 三分流 A/B/C | **本文件** |
| BDS 同步範圍與排除 | `BATS_DATA_SYNC_POLICY.md` |
| Knowledge 欄位格式 | `BATS_DATA_CONTRACT.md` |
| Registration 禁止進 Knowledge | `BATS_DATA_SYNC_POLICY.md` §21 + **本文件 §4、§5** |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.6** | 2026-06-10 | §1.9 P1-7 Knowledge Priority Rule；Industry Shared First |
| **v1.5** | 2026-06-10 | §1.8 Structured Knowledge Source SSOT（三層 Knowledge = Google Sheet） |
| **v1.4** | 2026-06-10 | P1 Final：Runtime Source、Phase 6A～6E 正名、Shared Runtime Boundary cross-ref |
| **v1.3** | 2026-06-10 | §1.6.6 Google Drive Platform Layer Architecture；Shared 移出租戶資料夾 |
| **v1.2** | 2026-06-09 | 新增 §1.7 BDS Data Input Strategy（Sheet Structured / Drive Unstructured）；Phase 4 Close-out |
| **v1.1** | 2026-06-09 | 新增 §1.6 Knowledge Layer Model：Private + Shared、Shared ≠ Public、Default Private、Drive 載體 |
| **v1.0** | 2026-06-09 | 第一版：Tenant Private Data 三分流 A/B/C、PII Host Boundary、Future Registration Contract |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | Draft — 待審核 |
| **實作狀態** | 分類治理文件；BDS Phase 4 Close-out 已納入 §1.7 |
| **P2 文件** | `BATS_TRAVELER_REGISTRATION_CONTRACT.md` 待 Phase 5 後規劃 |
