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
| **BDS v1 僅 B** | BDS Phase 1～3 僅處理 Category B 之 `tenant_private_knowledge` |
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
| **租戶 Private** | 租戶 `02_Private_Knowledge` 屬 **Private Layer** Archive |
| **產業／全球 Shared** | 產業／全球共用知識之 Drive 資料夾屬 **Shared Layer** Archive；**預設 Default Private** |
| **禁止混淆** | 不得因資料夾名稱含「shared」即視為對外公開；須依本節與 Ownership Policy 判斷 |

#### 1.6.5 與三分流對照

| 本文件三分流 | Knowledge Layer |
|--------------|-----------------|
| **Category B — Tenant Private** | Private Layer（`tenant_private_knowledge`） |
| **Category B — Shared fallback** | Shared Layer（`shared_knowledge`） |
| Category A / C | **不適用** Private + Shared Layer Model |

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
| Phase 1～3 | **已完成** | Mock Parser、Validator、JSON Writer Dry-run |
| Phase 4 | 規劃中 | Google Sheet Reader |
| Phase 5～6 | 規劃中 | GCS Writer、Manual Sync |

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
| **v1.1** | 2026-06-09 | 新增 §1.6 Knowledge Layer Model：Private + Shared、Shared ≠ Public、Default Private、Drive 載體 |
| **v1.0** | 2026-06-09 | 第一版：Tenant Private Data 三分流 A/B/C、PII Host Boundary、Future Registration Contract |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | Draft — 待審核 |
| **實作狀態** | 分類治理文件；BDS Phase 4 前置 |
| **P2 文件** | `BATS_TRAVELER_REGISTRATION_CONTRACT.md` 待 Phase 5 後規劃 |
