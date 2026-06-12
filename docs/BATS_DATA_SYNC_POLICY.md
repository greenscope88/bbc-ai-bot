# BATS_DATA_SYNC_POLICY.md

**專案：** BBC AI SaaS / BATS / ETT / API Gateway / Travel Data Center  
**定位：** L1 架構政策層 — BDS（BATS Data Sync）正式 SSOT  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_DATA_SOURCE_REGISTRY.md`、`BATS_DATA_CONTRACT.md`、`TENANT_SOURCE_REGISTRY_POLICY.md`、`TENANT_SOURCE_RUNTIME_BRIDGE_V1.md`、`TRAVEL_DATA_CENTER_PHASE9_MVP_IMPLEMENTATION_PLAN.md`  
**適用範圍：** `travel_a`、`travel_b`、`travel_c` 及未來 **20～200 家旅行社**  
**適用對象：** ChatGPT、Cursor、開發者、維運人員、資料維護人員  
**衝突處理：** 若與下層功能文件衝突，**以本文件為準**；若與上層 L0 政策衝突，**以上層為準**。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §2 | Official Naming |
| §3 | Scope |
| §4 | BDS Sync Principle |
| §5 | Architecture |
| §6 | Source Layer |
| §7 | Knowledge Layer (GCS) |
| §8 | Intelligence Layer (BATS) |
| §9 | Response Layer (Gemini) |
| §10 | Tenant Isolation Rules |
| §11 | GCS Structure |
| §12 | BDS Data Category |
| §13 | Sync Trigger |
| §14 | Error Handling |
| §15 | BDS v1 MVP Use Case |
| §16 | Future Roadmap |
| §18 | Google Drive Archive Layer |
| §19 | Update Entry Rule |
| §20 | Google Drive 三層資料分類 |
| §21 | Customer Registration Rule |
| §22 | Tenant Folder Isolation Rule |
| §23 | Anti Hardcode Rule |
| §24 | Guiding Principle |

> §12 **BDS Data Category** 為 v1.2 新增；§18～§23 為 v1.3 新增之 BDS 核心架構決策；§24 為原 Guiding Principle（原 §17）。

---

## 1. Purpose

本文件建立 **BDS（BATS Data Sync）** 第一版正式 SSOT，定義營運資料從人類可編輯來源同步至 BATS 執行環境的唯一正式模式與分層邊界。

### 1.1 背景

目前已完成：

| 項目 | 狀態 |
|------|------|
| travel_b 三商品源 Production | 已完成 |
| Tenant Source Registry Runtime Bridge | Phase 1C 已完成 |
| Google Cloud Project | 已建立 |
| GCS Bucket | 已建立 |
| Google Sheets API | 已啟用 |
| Google Drive API | 已啟用 |
| Service Account | 已建立 |

下一階段將建立正式資料同步管線：

```text
旅行社 → 主機A固定上傳頁（唯一更新入口）
        ↓
      Upload
        ↓
      BDS
        ↓
Google Drive（Archive Layer）
        ↓
      GCS（Knowledge Layer）
        ↓
BATS 使用最新資料
```

> Google Drive 為 **Archive Layer**（原始資料保存）；**不作為** BATS 查詢來源。詳見 §18。

### 1.2 文件目的

- 定義 BDS 唯一正式同步模式（**Mode B**）
- 明確五層架構邊界：Source（上傳頁）→ Archive（Drive）→ Knowledge（GCS）→ Intelligence（BATS）→ Response（Gemini）
- 規範多租戶隔離、GCS 路徑、同步觸發與錯誤處理
- 為後續 BDS 實作、維運 SOP、驗收測試提供唯一依據
- 避免各 Phase 文件對同步策略產生分歧表述

### 1.3 本文件不做

| 不做 | 說明 |
|------|------|
| 實作 BDS 程式 | 本文件僅定義政策與架構 |
| 修改既有 BATS PHP 程式 | 實作階段另開 Phase |
| 定義 Mode A | 不討論、不採用 |
| 實作 Mode C | 僅允許出現於 Future Roadmap |

---

## 2. Official Naming

| 名稱 | 全名 | 說明 |
|------|------|------|
| **BDS** | **BATS Data Sync** | 資料同步服務／管線之正式簡稱 |
| **BATS** | BBC AI Tour Search（語境下指 Host A 智慧層執行環境） | 消費 GCS 知識層、產生搜尋與回覆上下文 |
| **GCS** | Google Cloud Storage | Knowledge Layer 權威儲存 |
| **Mode B** | Upload-Triggered Immediate Sync | BDS 唯一正式同步模式 |
| **Source Layer** | — | 主機A固定上傳頁（唯一資料更新入口） |
| **Archive Layer** | — | Google Drive（原始檔保存；不作 BATS 查詢來源） |
| **Knowledge Layer** | — | GCS |
| **Intelligence Layer** | — | BATS |
| **Response Layer** | — | Gemini |
| **`itinerary_data`** | 行程資料（Category A） | 商品搜尋知識；見 §12 |
| **`tenant_private_knowledge`** | 旅行社私有知識資料（Category B） | 租戶客服知識；見 §12 |
| **`shared_knowledge`** | 共用知識（Category C） | 跨租戶 fallback；見 §12、§12.7 |
| **`industry_code`** | 產業代碼 | `travel`、`hotel`、`restaurant` 等；見 `BATS_DATA_SOURCE_REGISTRY.md` |

**書寫規範：**

- 文件與 commit 訊息優先使用 **BDS**；首次出現可寫「BDS（BATS Data Sync）」
- 不得將 BDS 與 BATS 混稱；BDS 負責同步，BATS 負責消費與推理編排
- GCS bucket 名稱以營運環境設定為準；本文件以 `bbc-travel-data-center` 為概念範例

---

## 3. Scope

### 3.1 適用資料類型

BDS 負責同步至 GCS、供 BATS 消費的 **營運設定與知識資料**，包含但不限於：

| 類型 | data_category | 範例 |
|------|---------------|------|
| 行程資料 | `itinerary_data`（§12 Category A） | `knowledge/itinerary/` |
| 客服知識（租戶） | `tenant_private_knowledge`（§12 Category B） | `tenants/{sno}/knowledge/` 下 5 個 JSON（見 §15、`BATS_DATA_CONTRACT.md`） |
| 客服知識（共用） | `shared_knowledge`（§12 Category C） | `shared/{industry}/knowledge/`、`shared/global/knowledge/` |
| Tenant 設定 | （`config/`，非 §12 三類） | `product_sources.json`、tenant profile |
| 商品源 Registry | （`shared/` / `config/`） | platform catalog、instance 設定 |
| 觸發政策 | （`shared/` / `config/`） | group trigger policy、feature flags |

### 3.2 不適用範圍

| 類型 | 處理方式 |
|------|----------|
| **旅客 PII / 報名交易資料**（`customer_registration`） | **禁止** 進入 BDS Knowledge Layer、GCS Knowledge Layer、Gemini Context、BATS Search；見 §21 |
| **即時庫存 / 即時價格** | 由 Host B / 外部 API 即時查詢；非 BDS 同步範圍 |
| **API Secret / Token / Credential** | 僅存 `.env` / Secret Manager；**禁止** 進入 Sheet 或 GCS |
| **Mode A 相關策略** | 不在本文件範圍 |
| **Mode C 實作** | 不在本文件範圍；僅 Future Roadmap |

### 3.3 與既有系統邊界

| 系統 | 關係 |
|------|------|
| Tenant Source Runtime Bridge | BATS 讀取 GCS mirror 後，Bridge 將 `source_id` 轉為 `source_instance_keys` |
| ProductSourceRegistry | Intelligence Layer 消費者；**不**直接讀 Google Sheet |
| MultiSourceSearchUrlBuilder | Intelligence Layer 消費者；不因 BDS 而修改核心邏輯 |
| GeminiTourContextBuilder | Response Layer 上游；接收 BATS 已組裝之 context |

---

## 4. BDS Sync Principle

### 4.1 正式決議：唯一同步模式 — Mode B

BDS **僅採用 Mode B**（Upload-Triggered Immediate Sync）：

```text
旅行社 → 主機A固定上傳頁（唯一更新入口）
        ↓
    Upload 成功
        ↓
    立即同步（BDS）
        ↓
    寫入 Google Drive（Archive Layer）
        ↓
    寫入 GCS（Knowledge Layer）
        ↓
BATS 使用最新資料（Intelligence Layer）
```

> 旅行社 **不得** 直接透過 Google Drive 或 Google Sheet 更新 BDS 資料。詳見 §19。

### 4.2 Mode B 核心特徵

| 特徵 | 說明 |
|------|------|
| **事件驅動** | 以「來源端上傳／儲存成功」為同步觸發點 |
| **立即執行** | 不等待批次排程窗口；上傳成功後即刻啟動 BDS |
| **單向主徑** | Source → GCS；GCS 為 BATS 讀取權威（經 Host A cache mirror） |
| **可稽核** | 每次同步須可追蹤時間、來源版本、目標路徑、結果狀態 |

### 4.3 明確排除

| 模式 | 狀態 |
|------|------|
| **Mode A** | **不討論、不採用、不出現於本文件** |
| **Mode C** | **不實作**；僅 §16 Future Roadmap 提及 |

---

## 5. Architecture

### 5.1 分層總覽

```text
┌─────────────────────────────────────────────────────────────┐
│  Source Layer（Update Entry）                                │
│  主機A固定上傳頁 — 唯一資料更新入口                             │
└───────────────────────────┬─────────────────────────────────┘
                            │ Upload Success
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  BDS（BATS Data Sync）                                       │
│  Mode B：立即同步、驗證、轉換、寫入                            │
└───────────────────────────┬─────────────────────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  Archive Layer (Google Drive)                               │
│  原始 Excel / PDF / 圖片 / Sheet 保存（不作 BATS 查詢來源）     │
└───────────────────────────┬─────────────────────────────────┘
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  Knowledge Layer (GCS)                                      │
│  正式設定與知識資料權威儲存                                    │
└───────────────────────────┬─────────────────────────────────┘
                            │ Read (via cache mirror)
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  Intelligence Layer (BATS)                                  │
│  Registry、Bridge、Hybrid Search、Multi-Source URL           │
└───────────────────────────┬─────────────────────────────────┘
                            │ Context
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  Response Layer (Gemini)                                      │
│  自然語言回覆、LINE 格式化                                     │
└─────────────────────────────────────────────────────────────┘
```

### 5.2 資料流原則

| 原則 | 說明 |
|------|------|
| **單向同步** | Source → GCS；BATS 不寫回 Google Sheet |
| **Registry Driven** | BATS 透過 Registry / GCS 消費設定，不硬編碼 tenant 規則 |
| **Cache 降延遲** | Host A 維護本機 mirror；BATS 執行時讀 mirror，不每請求直連 GCS |
| **Fail-Safe** | 同步失敗時 BATS 使用上一版有效 cache；不產生錯誤商品源連結 |

### 5.3 與 travel_b Production 對齊

travel_b 三商品源（bbctravel、grp、tourcenter）已於 Intelligence Layer 穩定運作。BDS 上線後：

- **不改變** 既有 URL Builder、Bridge fallback 契約
- **替換** 靜態 sample / 本機 config 為 GCS 同步後之最新 `product_sources` 與相關 registry 資料
- travel_b 仍受 Tenant Source Runtime Bridge legacy fallback 保護，直至 registry 完全對齊

---

## 6. Source Layer

### 6.1 定義

**Source Layer = 主機A固定上傳頁（唯一資料更新入口）**

旅行社與營運人員透過 **主機A固定上傳頁** 提交資料；BDS 接收後寫入 Google Drive（Archive Layer）與 GCS（Knowledge Layer）。

| 屬性 | 說明 |
|------|------|
| **唯一更新入口** | 主機A固定上傳頁；旅行社不得直接透過 Google Drive / Google Sheet 更新 BDS 資料（§19） |
| **權威性（對人）** | 對營運人員而言，上傳頁為「我要提交更新的唯一入口」 |
| **非執行環境** | BATS **不得** 在請求路徑中直接呼叫 Sheets API 或 Drive API 讀取營運設定 |

**Google Drive** 定位為 **Archive Layer**（§18），保存原始檔；**不作為** Source Layer 之查詢來源。

### 6.2 允許的來源類型（經上傳頁提交）

| 來源類型 | 用途 | 提交方式 |
|----------|------|----------|
| **Excel** | QA 表、行程表、服務項目 | 主機A固定上傳頁 Upload |
| **PDF** | 行程說明、DM | 主機A固定上傳頁 Upload |
| **圖片** | 行程圖片、DM 圖 | 主機A固定上傳頁 Upload |
| **Google Sheet** | 結構化設定（由 BDS 匯出或經核准流程） | **不得** 由旅行社直接編輯觸發 BDS |

上傳成功後，BDS 將原始檔寫入 Google Drive（Archive Layer），再轉換寫入 GCS。

### 6.3 Source Layer 規範

| 規範 | 說明 |
|------|------|
| **一 tenant 一工作區** | 每家旅行社獨立 Google Drive Folder：`tenants/{sno}/`（§22） |
| **版本可追溯** | Drive 修訂版本或 Sheet 變更須能對應至同步紀錄 |
| **禁止 secret** | API key、token、密碼不得出現在 Sheet 儲存格 |
| **禁止 PII / 報名資料進 Knowledge** | `customer_registration` 不得進入 Knowledge Layer（§21） |
| **Schema 契約** | 匯出至 GCS 前須符合已定義 JSON schema（見 L3 文件） |

### 6.4 上傳成功定義

「上傳成功」指 **主機A固定上傳頁** 完成 Upload，且 BDS 可取得有效檔案內容：

1. 旅行社經上傳頁提交檔案（Excel / PDF / 圖片等），BDS 接收完成
2. BDS 驗證檔案格式與 `tenant_sno` 後，寫入 Google Drive（Archive Layer）
3. BDS 轉換驗證通過後，寫入 GCS（Knowledge Layer）

**注意：**

- 旅行社 **不得** 直接於 Google Drive / Google Sheet 編輯觸發 BDS（§19）
- Drive 檔案直接 `save`、Sheet 儲存格即時編輯 **不等同於**「上傳成功」
- BDS 內部寫入 Drive 為 Archive 步驟，非旅行社直接操作

---

## 7. Knowledge Layer (GCS)

### 7.1 定義

**Knowledge Layer = GCS**

GCS 是 BATS 消費之設定與知識資料的 **執行環境權威儲存**（對機器與系統而言）。

| 屬性 | 說明 |
|------|------|
| **權威性（對機器）** | BATS / Registry 以 GCS 同步結果為準 |
| **不可由 Gemini 直接讀取** | Gemini 只接收 BATS 組裝後之 context |
| **可版本化** | 物件路徑、metadata、`published_at` 支援回溯 |
| **多租戶隔離** | 以 `tenants/{sno}/` 為邊界（見 §10、§11） |

### 7.2 Knowledge Layer 職責

| 職責 | 說明 |
|------|------|
| 儲存 BDS 寫入之正式 JSON / 文件 | 如 `product_sources.json`、catalog mirror |
| 提供 Host A cache 同步來源 | Sync 成功後刷新本機 mirror |
| 保留 `shared/` 全域設定 | 跨 tenant 共用 catalog、policy |
| **不儲存** PII、secret、即時交易資料 | 見 §3.2 |

### 7.3 與 Host A Local Cache 關係

```text
GCS（Knowledge Layer 權威）
        ↓  BDS Sync 成功後
Host A cache mirror（執行時讀取）
        ↓
ProductSourceRegistry / TenantSourceRuntimeBridge
```

| 規則 | 說明 |
|------|------|
| BATS 執行時讀 cache | 降低延遲；避免每 LINE 訊息直連 GCS |
| cache 落後 GCS | 僅允許在 Sync 進行中短暫存在；Sync 完成須刷新 |
| GCS 不可用 | 使用上一版有效 cache；記錄 degraded 狀態 |

---

## 8. Intelligence Layer (BATS)

### 8.1 定義

**Intelligence Layer = BATS**

BATS 負責消費 Knowledge Layer（經 cache mirror）、執行搜尋編排、產生 Gemini 所需結構化上下文。

| 屬性 | 說明 |
|------|------|
| **不直接讀 Source Layer** | 不於 runtime 呼叫 Google Sheets API 取得營運設定 |
| **Registry Driven** | 透過 ProductSourceRegistry、Tenant Source Runtime Bridge 解析商品源 |
| **Hybrid Search** | SearchCondition 解析、日期閘門、關鍵字處理 |
| **Multi-Source URL** | 依 `source_instance_keys` 產生多平台搜尋連結 |

### 8.2 Intelligence Layer 主要元件（現行）

| 元件 | 職責 |
|------|------|
| `TourPromptContextService` | 編排 Hybrid Search → API → Context 建構 |
| `TenantSourceRuntimeBridge` | `tenant_sno` → `source_instance_keys` |
| `TravelBMultiSourceLinkBuilder` | Hybrid Condition → multi-source URLs |
| `MultiSourceSearchUrlBuilder` | Registry-driven URL 產生 |
| `ProductSourceRegistry` | catalog + tenant enabled sources |

### 8.3 BDS 上線後 BATS 行為

| 行為 | 說明 |
|------|------|
| 讀取路徑不變 | 仍透過 Registry / cache；僅資料來源由靜態檔改為 GCS 同步 |
| 不每請求觸發 BDS | BDS 由 Source 上傳事件觸發；BATS 只讀結果 |
| travel_b 保護 | Bridge legacy fallback 持續有效，直至 GCS 資料與 Production 對齊 |

---

## 9. Response Layer (Gemini)

### 9.1 定義

**Response Layer = Gemini**

Gemini 負責將 BATS 組裝之結構化 context 轉為自然語言回覆，並經 LINE formatter 呈現給使用者。

| 屬性 | 說明 |
|------|------|
| **不直接讀 GCS** | 不存取 Knowledge Layer |
| **不直接讀 Google Sheet** | 不存取 Source Layer |
| **只消費 BATS context** | 如行程列表、搜尋 URL、多商品源連結區塊 |

### 9.2 Response Layer 輸入契約（概念）

BATS 傳入 Gemini 的 context 可包含：

| 區塊 | 來源 |
|------|------|
| 行程搜尋結果 | Host B API + BATS 合併 |
| 主搜尋 URL | bonusmee / Host B |
| 多商品源連結 | `TravelBMultiSourceLinkBuilder` → `multi_source_links` |
| 知識補充文本 | 來自 GCS 同步之 FAQ / 說明（經 BATS 篩選注入） |

### 9.3 Response Layer 規範

| 規範 | 說明 |
|------|------|
| **不重新組 URL** | Gemini / formatter 只顯示 BATS 已產出之 URL |
| **不決定商品源啟用** | 啟用清單由 Intelligence Layer + Registry 決定 |
| **私有資料不進 context** | PII、內部成本等不得出現在同步至 GCS 之資料中 |

---

## 10. Tenant Isolation Rules

### 10.1 隔離原則

| 原則 | 說明 |
|------|------|
| **sno 為權威邊界** | 所有 tenant 資料以 `tenant_sno` 隔離 |
| **禁止跨 tenant 讀寫** | BDS 不得將 A 社資料寫入 B 社 GCS 路徑 |
| **Service Account 最小權限** | 僅授權必要 Drive 資料夾與 GCS prefix |
| **Source 工作區隔離** | 每家旅行社獨立 Drive 資料夾 `tenants/{sno}/`；禁止多租戶共用（§22） |

### 10.2 隔離檢查點

| 階段 | 檢查 |
|------|------|
| Source 上傳 | 檔案 metadata 須含正確 `tenant_sno` 或 `tenant_key` |
| BDS 轉換 | 驗證 document 內 `tenant_sno` 與目標路徑一致 |
| GCS 寫入 | 僅允許寫入 `tenants/{sno}/` 下對應路徑 |
| BATS 讀取 | Registry / Bridge 以請求 `sno` 解析，不混用他社 cache |

### 10.3 共用資源

以下可放於 `shared/`，但 **不得** 含 tenant 專屬機密：

| 路徑 | 內容 |
|------|------|
| `shared/config/product_source_catalog.json` | 平台級 catalog |
| `shared/config/group_trigger_policy.json` | 全域觸發政策（若適用） |

tenant 專屬設定 **必須** 在 `tenants/{sno}/` 下。

---

## 11. GCS Structure

### 11.1 BDS v1 正式標準結構

BDS v1 在 GCS 採用以下 **唯一正式頂層結構**：

```text
gs://{bucket}/
├── shared/
│
└── tenants/
    └── {sno}/
        ├── config/
        ├── knowledge/
        └── meta/
```

#### 目錄定義

| 目錄 | 定義 |
|------|------|
| **`shared/`** | 跨 tenant 共用設定與 **共用知識層**（`shared/{industry}/knowledge/`、`shared/global/knowledge/`；見 §11.3、§12.7） |
| **`tenants/{sno}/config/`** | **租戶設定資料** — `product_sources.json`、tenant profile、feature config 等 |
| **`tenants/{sno}/knowledge/`** | **租戶可讀知識資料** — `itinerary_data` 與 `tenant_private_knowledge`；路徑不得混用 |
| **`tenants/{sno}/meta/`** | **同步資訊、版本資訊、同步紀錄** — `sync_status.json`、`last_sync_at.json`、`source_revision.json` 等 |

#### 暫不列入正式結構

以下路徑 **暫不屬於** BDS v1 正式 GCS 結構；**不得** 在實作中自行新增為正式路徑：

| 路徑 | 狀態 |
|------|------|
| `products/` | 暫不列入 |
| `uploads/` | 暫不列入 |
| `parsed/` | 暫不列入 |
| `rag/` | 暫不列入 |

未來如需新增上述或類似路徑，**須先修訂本 SSOT 文件**（`BATS_DATA_SYNC_POLICY.md`），不得由實作端私下擴充。

### 11.2 Bucket（概念）

| 項目 | 值 |
|------|-----|
| 環境 | Google Cloud Project（已建立） |
| Bucket（範例） | `bbc-travel-data-center` |
| 權限 | Service Account 寫入；Host A 讀取 + cache |

### 11.3 目錄結構範例（v1）

```text
gs://{bucket}/
├── shared/
│   ├── config/
│   │   ├── product_source_catalog.json
│   │   ├── group_trigger_policy.json          （可選）
│   │   └── bds_source_registry.json           （可選）
│   ├── travel/
│   │   └── knowledge/                         ← shared_knowledge（旅遊業）
│   ├── hotel/
│   │   └── knowledge/                         ← shared_knowledge（飯店民宿）
│   ├── restaurant/
│   │   └── knowledge/                         ← shared_knowledge（美食餐廳）
│   └── global/
│       └── knowledge/                         ← shared_knowledge（跨產業 fallback）
│
└── tenants/
    └── {sno}/
        ├── config/
        │   ├── product_sources.json           ← 租戶設定：enabled sources
        │   └── tenant_line_profile.json       （可選）
        ├── knowledge/
        │   ├── itinerary/                     ← Category A：行程資料（下一階段）
        │   ├── company_profile.json           ← Category B：MVP（`BATS_DATA_CONTRACT.md`）
        │   ├── service_qa.json                ← Category B：MVP
        │   ├── external_product_links.json    ← Category B：MVP
        │   ├── service_items.json             ← Category B：MVP
        │   ├── special_prices.json            ← Category B：MVP
        │   └── *.md / *.json                  ← 其他知識文件（須標註 data_category；須修訂 SSOT 後新增）
        └── meta/
            ├── last_sync_at.json              ← 同步資訊
            ├── sync_status.json               ← 同步紀錄
            └── source_revision.json           ← 版本資訊
```

### 11.4 與現有程式路徑對齊

| 概念路徑 | 現有程式參考 |
|----------|--------------|
| `tenants/{sno}/config/product_sources.json` | `TenantProductSourcesGcsPathConfig::DEFAULT_OBJECT_PATH_TEMPLATE` |
| Host A mirror | `cache/tenants/{sno}/product_sources.json`（規劃） |

### 11.5 物件 Metadata 建議

每次 BDS 寫入應附：

| 欄位 | 說明 |
|------|------|
| `tenant_sno` | 租戶識別 |
| `sync_id` | 本次同步唯一 ID |
| `source_revision` | Drive / Sheet 修訂版本 |
| `published_at` | ISO 8601 發布時間 |
| `schema_version` | JSON schema 版本 |
| `bds_mode` | 固定 `B` |
| `data_category` | `itinerary_data`、`tenant_private_knowledge` 或 `shared_knowledge`（見 §12） |
| `industry_code` | `travel`、`hotel`、`restaurant`、`global`（`shared_knowledge` 時；見 `BATS_DATA_SOURCE_REGISTRY.md`） |

---

## 12. BDS Data Category

### 12.1 目的

正式區分 BDS 同步與 BATS 消費之 Knowledge 資料類別，避免 **行程資料**、**租戶私有知識** 與 **共用知識** 在未來資料流中混用、共用 schema 或寫入錯誤路徑。

| 區分目的 | 說明 |
|----------|------|
| **職責分離** | 商品搜尋知識 vs 客服知識，消費場景不同 |
| **契約分離** | 兩類資料使用不同 Data Contract |
| **驗證分離** | 兩類資料使用不同 Validation Rule |
| **路徑分離** | GCS 建議路徑不同，禁止混放 |

### 12.2 Category A — `itinerary_data`（行程資料）

| 項目 | 說明 |
|------|------|
| **類別 ID** | `itinerary_data` |
| **中文** | 行程資料 |
| **資料性質** | **商品搜尋知識** |

#### 用途

| 用途 | 說明 |
|------|------|
| BATS 商品搜尋 | 支援關鍵字、目的地、日期等搜尋語意 |
| 商品推薦 | 行程列表、推薦排序（規劃） |
| LINE OA 行程回覆 | 行程名稱、出團日、價格等結構化回覆 |

#### 資料來源

| 來源 | 說明 |
|------|------|
| Excel | 商品行程表 |
| PDF | 行程說明 PDF（下一階段解析） |
| 圖片 | 行程圖片（下一階段解析） |
| 商品資料 | 結構化商品匯出 |

#### 內容可能包含

行程名稱、出團日期、價格、機位、可售數量、圖片、行程內容等 **商品／行程屬性**。

#### 建議 GCS Path

```text
tenants/{sno}/knowledge/itinerary/
```

| 說明 |
|------|
| 置於 `knowledge/` 下之子目錄 `itinerary/`；屬 **商品搜尋知識**，非 `config/`、非 `products/` 頂層 |

### 12.3 Category B — `tenant_private_knowledge`（旅行社私有知識資料）

| 項目 | 說明 |
|------|------|
| **類別 ID** | `tenant_private_knowledge` |
| **中文** | 旅行社私有知識資料 |
| **資料性質** | **客服知識** |

#### 用途

| 用途 | 說明 |
|------|------|
| Gemini 客服知識 | 一般服務問答、政策說明 |
| LINE OA 一般問答 | 非行程搜尋類問題（如代辦、收費） |
| 租戶私有知識 | 該社專屬規則，不與他社共用 |

#### 資料來源

| 來源 | 說明 |
|------|------|
| Google Sheet | 租戶私有知識維護表（1 Sheet / 5 Tabs；`BATS_DATA_CONTRACT.md`） |
| 服務項目維護資料 | 結構化服務清單 |

#### 內容可能包含

QA、服務項目、護照代辦、台胞證代辦、公司規則、收費標準等 **客服／營運知識**。

#### 建議 GCS Path

```text
tenants/{sno}/knowledge/company_profile.json
tenants/{sno}/knowledge/service_qa.json
tenants/{sno}/knowledge/external_product_links.json
tenants/{sno}/knowledge/service_items.json
tenants/{sno}/knowledge/special_prices.json
```

| 說明 |
|------|
| 屬 **客服知識**；與 `itinerary_data` 路徑分離；完整 Contract 見 `BATS_DATA_CONTRACT.md` |

### 12.3.1 Category C — `shared_knowledge`（共用知識）

| 項目 | 說明 |
|------|------|
| **類別 ID** | `shared_knowledge` |
| **中文** | 共用知識 |
| **資料性質** | **跨租戶／跨產業 fallback 客服知識** |

#### 用途

| 用途 | 說明 |
|------|------|
| 產業級共用知識 | 旅遊、飯店、餐廳等產業通用 QA |
| 跨產業 fallback | `global` 層通用須知 |
| Gemini 客服 fallback | 租戶無私有答案時使用 |

#### 建議 GCS Path

```text
shared/{industry_code}/knowledge/     # 例：shared/travel/knowledge/
shared/global/knowledge/              # 跨產業 fallback
```

| `industry_code` | 產業 |
|-----------------|------|
| `travel` | 旅遊業 |
| `hotel` | 飯店民宿 |
| `restaurant` | 美食餐廳 |
| `global` | 跨產業共用知識 |

#### 角色

- **僅作 fallback**；`tenant_private_knowledge` **優先**（§12.7）
- **不得** 覆寫租戶已存在之私有答案
- Source Registry 結構與讀取優先序見 **`BATS_DATA_SOURCE_REGISTRY.md`**

### 12.4 三類 Knowledge 資料對照

| 對照項 | A `itinerary_data` | B `tenant_private_knowledge` | C `shared_knowledge` |
|--------|-------------------|------------------------------|----------------------|
| 性質 | 商品搜尋知識 | 租戶客服知識 | 共用客服知識（fallback） |
| 典型問題 | 「北海道有什麼團？」 | 「護照代辦怎麼收費？」 | 產業通用護照／簽證須知 |
| 建議路徑 | `tenants/{sno}/knowledge/itinerary/` | `tenants/{sno}/knowledge/` 下 5 JSON | `shared/{industry}/knowledge/`、`shared/global/knowledge/` |
| 讀取優先序 | 依商品搜尋意圖 | **1（最高）** | **2、3（fallback）** |
| BDS v1 MVP | **不實作**（Rule 6） | **實作**（Rule 5） | 規劃中 |
| Data Contract | 專用 schema（L3 待定） | 專用 schema（L3 待定） | 專用 schema（L3 待定） |

### 12.5 正式規則（Data Category Rules）

#### Rule 1 — 不得混用

**`itinerary_data`、`tenant_private_knowledge` 與 `shared_knowledge` 不得混用。**

| 禁止 | 說明 |
|------|------|
| 同一 JSON 檔同時含行程欄位與 QA 欄位 | 須拆檔或拆 category |
| 將 QA 寫入 `knowledge/itinerary/` | 路徑與 category 不符 |
| 將行程商品寫入 `service_qa.json` | 路徑與 category 不符 |
| BATS 以單一 loader 不區分 category 混讀 | 須依 category 分開載入 |

#### Rule 2 — 不同 Data Contract

兩類資料 **必須** 使用 **不同 Data Contract**（JSON schema / 欄位契約）。

| 類別 | Contract 要點（概念） |
|------|---------------------|
| `itinerary_data` | 行程 ID、名稱、日期、價格、庫存等商品欄位 |
| `tenant_private_knowledge` | 問答對、服務項目 ID、規則文本、收費說明等 |

契約細節由 **`BATS_DATA_CONTRACT.md`（L3 SSOT）** 定義；**禁止** 各類別共用同一 schema 檔案。

#### Rule 3 — 不同 Validation Rule

兩類資料 **必須** 使用 **不同 Validation Rule**。

| 類別 | 驗證重點（概念） |
|------|------------------|
| `itinerary_data` | 日期格式、價格數值、庫存非負、行程必填欄位 |
| `tenant_private_knowledge` | QA 成對、服務代碼唯一、禁止 PII 欄位 |

BDS Safety Rule（§14.1）之 Validation 步驟 **須依 `data_category` 選用對應規則**。

#### Rule 4 — BATS Query Flow 依需求讀取

**BATS Query Flow 只能依需求讀取對應資料類型。**

| 查詢類型 | 允許讀取 | 禁止讀取 |
|----------|----------|----------|
| 行程／商品搜尋 | `itinerary_data`、Host B API、Multi-Source URL | 不必要時載入完整 `service_qa.json` |
| 一般客服問答 | `tenant_private_knowledge` → `shared_knowledge`（依 §12.7 優先序） | 不必要時載入 `itinerary/` 全量 |
| 混合意圖 | 可分別讀取各類，但 **不得** 合併為單一未分類 blob | — |

與 §13.3 搭配：Query Flow **只讀 GCS**，且 **只讀與意圖相符之 data_category**。

#### Rule 5 — BDS v1 MVP 範圍

**BDS v1 MVP 僅實作 `tenant_private_knowledge`。**

採用 **1 Google Sheet / 5 Required Tabs / 5 JSON Outputs**（見 §15、`BATS_DATA_CONTRACT.md`）：

| Required Tabs | JSON Outputs |
|---------------|--------------|
| `company_profile` | `company_profile.json` |
| `qa` | `service_qa.json` |
| `external_product_links` | `external_product_links.json` |
| `service_items` | `service_items.json` |
| `special_prices` | `special_prices.json` |

正式欄位與 JSON 格式以 **`BATS_DATA_CONTRACT.md`** 為 L3 SSOT。

#### Rule 6 — `itinerary_data` 下一階段

**`itinerary_data` 保留於下一階段；不納入 BDS v1 MVP。**

包含但不限於：商品 Excel、PDF Parser、圖片 Parser、AI Parser、`knowledge/itinerary/` 同步管線。

#### Rule 7 — `shared_knowledge` 讀取優先序

**`tenant_private_knowledge` 優先於 `shared_knowledge`；`shared_knowledge` 僅作 fallback。**

| 規則 | 說明 |
|------|------|
| **優先** | 租戶私有知識先讀；命中則使用租戶答案 |
| **Fallback** | 租戶無匹配時，依 `industry_code` 讀 `shared/{industry}/knowledge/`，再讀 `shared/global/knowledge/` |
| **禁止反向覆寫** | `shared_knowledge` **不得** 覆寫 `tenant_private_knowledge` |
| **Registry SSOT** | 路徑、產業分層、完整優先序見 **`BATS_DATA_SOURCE_REGISTRY.md`** |

#### Rule 8 — `customer_registration` 排除

**`customer_registration`（旅客報名資料）不得進入 Knowledge Layer。**

屬 **交易資料**，非知識資料。完整規則見 §21。

### 12.7 Shared Knowledge & Source Registry Cross-Reference

本節與 **`BATS_DATA_SOURCE_REGISTRY.md`** 對齊；衝突時 Source Registry 結構與優先序 **以該文件為準**。

#### 12.7.1 `shared_knowledge` 定位

| 項目 | 說明 |
|------|------|
| **定義** | `shared_knowledge` 為 **共用知識層**（Category C） |
| **正式路徑** | `shared/{industry_code}/knowledge/`、`shared/global/knowledge/` |
| **非租戶路徑** | 不得寫入 `tenants/{sno}/knowledge/` |

#### 12.7.2 讀取優先序（客服知識）

```text
1. tenants/{sno}/knowledge/          ← tenant_private_knowledge（優先）
        ↓（若無匹配）
2. shared/{industry_code}/knowledge/ ← shared_knowledge（產業 fallback）
        ↓（若無匹配）
3. shared/global/knowledge/            ← shared_knowledge（跨產業 fallback）
        ↓（若無匹配）
4. Human Service                       ← 轉人工；禁止 AI 幻覺（§18.8）
```

| 順序 | 層級 | 說明 |
|------|------|------|
| **1** | `tenant_private_knowledge` | **優先**；租戶私有答案 |
| **2** | `shared_knowledge`（產業） | 依 Registry `industry_code`；**僅 fallback** |
| **3** | `shared_knowledge`（global） | 跨產業最終 fallback |
| **4** | Human Service | 三層皆無 → 轉人工；**禁止** AI 編造 |

**`shared_knowledge` 不得覆寫 `tenant_private_knowledge`。**

完整 P1-7 定義見 **§18.8**、`BATS_DATA_SOURCE_REGISTRY.md` §4.4。

#### 12.7.3 範例

| 來源 | 護照代辦價格 |
|------|--------------|
| `travel_b` `tenant_private_knowledge` | **1600** |
| `shared/travel/knowledge/` | **1800** |
| `shared/global/knowledge/` | **2000** |

**AI 必須回答：1600**

#### 12.7.4 Source Registry 管理

下列項目之 **Registry 契約、產業分層、擴展規則** 由 **`BATS_DATA_SOURCE_REGISTRY.md`** 定義：

| 項目 | 管理文件 |
|------|----------|
| Google Drive Folder ID | `BATS_DATA_SOURCE_REGISTRY.md` §6、§7 |
| Google Sheet ID | `BATS_DATA_SOURCE_REGISTRY.md` §7 |
| GCS Prefix | `BATS_DATA_SOURCE_REGISTRY.md` §7、§9 |
| `industry_code` | `BATS_DATA_SOURCE_REGISTRY.md` §7、§9 |
| Knowledge Source 類型 | `BATS_DATA_SOURCE_REGISTRY.md` §3 |
| 讀取優先序細節 | `BATS_DATA_SOURCE_REGISTRY.md` §4、§5 |

本文件定義 **同步模式與 Data Category**；Source Registry **不** 於本文件重複定義。

#### 12.7.5 產業擴展原則

新增 `hotel`、`restaurant` 或其他產業時：

| 允許 | 禁止 |
|------|------|
| 新增 `industry_code` | 修改 BDS 同步核心流程 |
| 新增 `shared/{industry}/knowledge/` | hardcode 單一產業或 tenant |

見 `BATS_DATA_SOURCE_REGISTRY.md` §10。

---

## 13. Sync Trigger

### 13.1 Mode B 觸發條件

同步 **僅** 在以下流程後觸發：

```text
1. 旅行社於主機A固定上傳頁完成 Upload（§19）
2. 上傳成功（§6.4）
3. BDS 立即同步（Mode B）
4. BDS 驗證 → 寫入 Google Drive（Archive）→ 轉換 → 寫入 GCS
5. 通知 Host A 刷新 cache（或下次載入時偵測新版本）
```

### 13.2 觸發方式（實作 Phase 待定）

| 方式 | 說明 | 優先級 |
|------|------|--------|
| **手動觸發** | 營運按「同步至 GCS」 | Phase 1 建議 |
| **Webhook** | Drive / Apps Script 通知 BDS | Phase 2 |
| **CI 觸發** | 設定檔 PR merge 後觸發 | 僅限 Git-managed config，非 Sheet 主徑 |

### 13.3 BATS Query Flow 不得觸發同步（正式規則）

**BATS Query Flow 不得觸發同步。**

客戶查詢路徑與 BDS 同步路徑 **必須分離**；原則：**同步與查詢必須分離。**

#### 範例：客戶詢問北海道

```text
客戶詢問「北海道」
        ↓
    LINE OA
        ↓
      BATS
        ↓
只允許讀取 GCS（Knowledge Layer / cache mirror）
```

#### 查詢路徑禁止事項

| 禁止 | 說明 |
|------|------|
| **Google Sheet Sync** | 不得在 LINE 請求處理中讀取或同步 Sheet |
| **Google Drive Sync** | 不得在 LINE 請求處理中讀取或同步 Drive |
| **BDS Trigger** | 不得因使用者查詢而觸發 BDS |

BDS **僅** 由 Source Layer 上傳成功事件觸發（§13.1）；BATS 在 Query Flow 中 **只讀** 已同步至 GCS 之資料。

### 13.4 禁止的觸發方式

| 禁止 | 原因 |
|------|------|
| BATS 每請求觸發 BDS | 延遲、成本、競態；違反 §13.3 |
| BATS Query Flow 觸發 Sheet / Drive / BDS | 同步與查詢未分離 |
| 無上傳成功信號即同步 | 可能同步半成品 |
| Mode A 類批次覆蓋 | 非正式模式 |

### 13.5 同步完成定義

一次 Mode B 同步視為 **成功** 當且僅當：

1. 來源檔案通過 schema 驗證
2. 目標 GCS 物件寫入成功
3. `meta/sync_status.json` 更新為 `success`
4. Host A cache 刷新成功 **或** 已排入下一個安全載入點

---

## 14. Error Handling

### 14.1 BDS Safety Rule（正式規則）

**同步失敗不得覆蓋正式資料。**

BDS 寫入 GCS 須遵循以下 **標準流程**；此規則列為 **BDS Safety Rule**，與 §14.3 Fail-Safe 原則同等效力：

```text
Source（Sheet / Drive / Upload）
        ↓
    tmp JSON（暫存、未發布）
        ↓
    Validation（schema / tenant / 欄位驗證）
        ↓
    正式 JSON（驗證通過後才產生）
        ↓
    GCS（寫入正式路徑）
```

#### Validation 失敗時

| 動作 | 說明 |
|------|------|
| **保留原正式 JSON** | GCS 既有正式物件不得被覆蓋 |
| **不得覆蓋** | tmp / 失敗結果不得 promote 為正式版 |
| **記錄錯誤資訊** | 寫入 `meta/sync_status.json`、`meta/` 錯誤紀錄；可選保留 tmp 供除錯（非正式路徑） |

> **禁止：** 先寫入正式路徑再驗證；或驗證失敗仍覆蓋 `knowledge/`、`config/` 下既有正式檔案。

### 14.2 錯誤分類

| 等級 | 範例 | 處理 |
|------|------|------|
| **E1 來源錯誤** | Sheet schema 不符、缺少 `tenant_sno` | 拒絕同步；保留 GCS 舊版；回報營運 |
| **E2 轉換錯誤** | JSON 解析失敗、欄位型別錯誤 | 拒絕同步；寫入 `meta/sync_status.json` = `failed` |
| **E3 GCS 寫入錯誤** | 權限、quota、網路 | 重試（有限次數）；失敗則保留舊版 |
| **E4 Cache 刷新錯誤** | Host A 無法寫入 mirror | GCS 已成功；BATS 暫用舊 cache；告警 |
| **E5 執行時錯誤** | Registry 讀取失敗 | Bridge fallback；`enabled=false`；不產錯誤連結 |

### 14.3 Fail-Safe 原則

| 原則 | 說明 |
|------|------|
| **BDS Safety Rule** | 見 §14.1；tmp → Validation → 正式 JSON → GCS |
| **Data Category Validation** | 依 §12 Rule 3 選用對應 Validation Rule |
| **永不 partial publish** | 驗證未通過不寫入正式路徑 |
| **舊版優先於錯誤版** | 同步失敗不覆蓋上一版有效資料 |
| **未知 tenant 不產連結** | 與 Tenant Source Runtime Bridge 契約一致 |
| **可觀測** | 每次同步須有 log / `sync_status` 可查 |

### 14.4 營運可見錯誤訊息

BDS 應回饋營運可理解之錯誤（非 stack trace）：

| 錯誤碼（概念） | 訊息方向 |
|----------------|----------|
| `BDS_SCHEMA_INVALID` | 欄位缺失或格式錯誤，請檢查 Sheet 第 N 列 |
| `BDS_TENANT_MISMATCH` | 檔案 tenant 與目標路徑不符 |
| `BDS_GCS_WRITE_FAILED` | 雲端寫入失敗，請稍後重試或聯絡維運 |
| `BDS_SOURCE_UNREACHABLE` | 無法讀取 Google Drive 檔案，請確認權限 |

### 14.5 與 Recovery Policy 對齊

同步失敗之 rollback：

1. GCS 保留上一版正式 JSON（如 `knowledge/service_qa.json`、`config/product_sources.json`；versioning 或雙物件策略）
2. 必要時手動 revert Drive 檔案至上一修訂版
3. 詳見 `RECOVERY_AND_ROLLBACK_POLICY.md`

---

## 15. BDS v1 MVP Use Case

### 15.1 第一個實作租戶

| 項目 | 值 |
|------|-----|
| **tenant_key** | `travel_b` |
| **tenant_sno** | `5f99b8d665e8444d` |

### 15.2 第一個實作目標

**Tenant Private Knowledge Sheet Sync**（`tenant_private_knowledge` / Category B，見 §12 Rule 5）

將營運維護之 **1 份 Google Sheet（5 Required Tabs）**，經核准上傳流程後立即同步為 **5 個標準 JSON**，供 BATS 讀取並注入 Gemini context。

| Required Tabs | JSON Outputs |
|---------------|--------------|
| `company_profile` | `company_profile.json` |
| `qa` | `service_qa.json` |
| `external_product_links` | `external_product_links.json` |
| `service_items` | `service_items.json` |
| `special_prices` | `special_prices.json` |

### 15.3 MVP 正式流程

```text
主機 A 固定網址（Upload 入口）
        ↓
    上傳 / 核准 Tenant Private Knowledge Google Sheet
        ↓
    立即同步（Mode B / BDS）
        ↓
    產生 5 個標準 JSON（Validation 通過後）
        ↓
tenants/5f99b8d665e8444d/knowledge/
  ├── company_profile.json
  ├── service_qa.json
  ├── external_product_links.json
  ├── service_items.json
  └── special_prices.json
        ↓
      GCS
        ↓
      BATS（讀取 knowledge / cache mirror）
        ↓
    Gemini（Response Layer）
        ↓
    LINE OA
```

### 15.4 MVP 路徑與契約

| 項目 | 值 |
|------|-----|
| **data_category** | `tenant_private_knowledge`（Category B） |
| GCS 正式路徑 | `tenants/5f99b8d665e8444d/knowledge/` 下 5 個 JSON（見上表） |
| 目錄歸屬 | `knowledge/`（客服知識，見 §12.3、§11.1） |
| 同步模式 | Mode B only（上傳成功 → 立即同步） |
| 安全規則 | BDS Safety Rule（§14.1）；Validation Fail 不得覆蓋既有 JSON |
| **Data Contract（L3 SSOT）** | **`BATS_DATA_CONTRACT.md`** — Tab 名稱、欄位、JSON 格式、Validation |
| Source Registry | `BATS_DATA_SOURCE_REGISTRY.md` — `private_knowledge_sheet_id`、路徑登錄 |

### 15.5 MVP 明確排除

以下 **不納入** BDS v1 MVP 範圍（與 §12 Rule 6 一致）：

| 排除項目 | 說明 |
|----------|------|
| **`itinerary_data`（Category A）** | 行程資料全類；含 `knowledge/itinerary/` |
| **PDF Parser** | 不實作 |
| **圖片 Parser** | 不實作 |
| **商品 Excel** | 不實作（屬 `itinerary_data`） |
| **AI Parser** | 不實作 |
| **RAG** | 不實作；`rag/` 非正式 GCS 結構（§11.1） |
| **Cron** | 不採用排程觸發 |
| **Batch Sync** | 不採用批次同步（非 Mode B） |
| **全租戶同步** | MVP 僅 travel_b（Pilot）；不得一次同步所有 tenant；travel_b 不得 hardcode 為架構特例（§23） |

### 15.6 MVP 與既有 Production 關係

| 項目 | 說明 |
|------|------|
| travel_b 三商品源 | 維持現行 Production；MVP 不改 Multi-Source URL 管線 |
| 租戶知識注入 | 新增 `knowledge/` 下 5 個 JSON（Category B）消費路徑；格式見 `BATS_DATA_CONTRACT.md` |
| Query Flow | 客服問答讀 `tenant_private_knowledge`；不觸發 BDS（§13.3、§12 Rule 4） |
| 行程搜尋 | 仍走 Host B API + Multi-Source URL；**不** 以 MVP 取代 `itinerary_data` |

---

## 16. Future Roadmap

### 16.1 近期 Phase（建議順序）

| Phase | 內容 | 狀態 |
|-------|------|------|
| **BDS v1 MVP** | travel_b `tenant_private_knowledge`：1 Sheet / 5 Tabs → 5 JSON；Mode B；`BATS_DATA_CONTRACT.md` | 規劃中 |
| **BDS Phase 2** | `itinerary_data` 規劃啟動；`config/product_sources.json`；`shared_knowledge` Contract；Webhook | 規劃中 |
| **Tenant Bridge Phase 1D** | 任意 tenant path 解析；travel_c pilot | 規劃中 |
| **Tenant Bridge Phase 1E** | GCS Provider 真實下載 + cache | 規劃中 |

### 16.2 Mode C（僅限本節 — Future，不實作）

**Mode C** 指 **排程批次同步**（Scheduled Batch Sync）：不以單次上傳為觸發，而依固定時間窗口從 Source Layer 拉取變更。

| 項目 | 說明 |
|------|------|
| **狀態** | **不實作**；不納入第一版 BDS |
| **可能用途** | 離峰大量知識文件同步、歷史資料遷移 |
| **啟用條件** | 須另開 SSOT 修訂；不得與 Mode B 並存為「雙正式模式」 |

> **正式決議不變：** 第一版 BDS 僅 **Mode B**。Mode C 僅作為未來可能方向記錄於本節，**不得** 在實作 Phase 中提前引入。

### 16.3 長期願景（20～200 家旅行社）

| 目標 | 說明 |
|------|------|
| 每社獨立 Source 工作區 | Drive 資料夾 + Sheet 範本 |
| 共用 BDS 管線 | 單一 Mode B 同步服務，tenant 參數化 |
| 無 per-tenant PHP config fork | 不建立 `travel_x_multi_source_links.php` |
| GCS + Bridge 驅動 | 新增 tenant = Registry Config；不得修改核心同步流程（§23） |

---

## 18. Google Drive Archive Layer

### 18.1 正式定義

**Google Drive = Archive Layer（原始資料保存層）**

| 項目 | 說明 |
|------|------|
| **角色** | 保存上傳之原始檔，供稽核、回溯、營運檢視 |
| **不作為** | BATS 查詢來源、Gemini Context 來源、Knowledge Layer |

### 18.2 用途

| 類型 | 說明 |
|------|------|
| 原始 Excel | QA 表、行程表、服務項目表等 |
| 原始 PDF | 行程說明、DM |
| 原始圖片 | 行程圖、DM 圖 |
| 原始 Google Sheet | BDS 匯出或經核准流程產生之 Sheet 副本 |

### 18.3 正式資料流

**寫入路徑（Update Entry，§19）：**

```text
主機A固定上傳頁 → BDS → Google Drive（Archive）→ GCS（Knowledge）
```

**BATS 消費路徑（BATS 只讀 Knowledge Layer）：**

```text
GCS（Knowledge Layer）→ BATS
```

| 層級 | BATS 是否直接讀取 |
|------|-------------------|
| Google Drive（Archive） | **否** |
| GCS（Knowledge） | **是**（經 Host A cache mirror） |

> Google Drive 經 BDS 寫入後保存原件；BDS 轉換結果寫入 GCS；BATS **僅** 消費 GCS。

### 18.4 與 Source Layer 區分

| 層級 | 職責 |
|------|------|
| **Source Layer** | 主機A固定上傳頁 — 人類提交資料之唯一入口 |
| **Archive Layer** | Google Drive — 機器保存原始檔，供回溯與分享權限管理 |
| **Knowledge Layer** | GCS — BATS 執行環境讀取之權威知識儲存 |

### 18.5 Runtime Source Architecture（P1 SSOT）

> **Phase 6 P1 最高優先治理決策。** 詳見 `BATS_DRIVE_CONNECTOR_SCOPE.md` §2。

| 載體 | 正式角色 |
|------|----------|
| **Google Drive** | **Source of Truth（Archive Source）** — 保存原始非結構化檔案 |
| **GCS** | **Runtime Knowledge Source** — BATS Search、Gemini Context、Metadata Layer、Knowledge Layer、Future RAG |

**BATS Runtime 不得依賴即時 Google Drive 搜尋。**

Google Drive **不是** Runtime Source、Search Source、RAG Source。

| 正確流程 | 禁止流程 |
|----------|----------|
| Drive → BDS Sync → GCS Archive → Metadata → Knowledge → Runtime → LINE OA | LINE OA → Drive API → PDF → Gemini |
| Future RAG **僅** 從 GCS 取得資料 | Drive → RAG → Gemini |

**治理原因：** 效能、API Quota、多租戶隔離、權限管理、可回滾、可驗證、可快取、可觀測性。

**交叉引用：** `BATS_DRIVE_GCS_MAPPING.md` §2、§4；`BATS_DRIVE_METADATA_CONTRACT.md` §5

### 18.6 Phase 6A Manual Sync Command

| 項目 | 說明 |
|------|------|
| **定位** | 將 Phase 1～5 元件整合為 `bin/bds-sync.php` 手動 CLI |
| **輸入** | Registry → `private_knowledge_sheet_id` → Google Sheet（5 Tabs） |
| **輸出** | `tenants/{sno}/knowledge/` 五 JSON + reports + Read-back |
| **不處理** | Google Drive、Shared、RAG、Cron |
| **驗收** | Pilot `travel_b`（`5f99b8d665e8444d`） |

**SSOT：** `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` §Phase 6A；Runbook §13；Test Plan §3.3

### 18.7 Structured Knowledge Source SSOT

> **核心目的：** 護照、簽證、台胞證、入境規定、行李規定、國際旅遊常識等 **FAQ／條列型共同知識**，可經 **Google Sheet → GCS Knowledge → Gemini** 回答，**不必等待** PDF／RAG。

#### 18.7.1 正式原則

| # | 原則 |
|---|------|
| 1 | **Google Sheet** = Structured Knowledge **Input Source** |
| 2 | **Google Drive** = Archive Source / Original File Repository |
| 3 | **GCS** = Runtime Knowledge Source |
| 4 | FAQ 型、表格型、條列型、服務型知識 → **優先 Google Sheet** |
| 5 | PDF / Image / Word / PPT / DM → **Google Drive Archive** |

#### 18.7.2 三層 Knowledge 輸入／輸出

| 層級 | Input（Sheet） | Output（GCS） | BDS 狀態 |
|------|----------------|---------------|----------|
| **Tenant Private** | `private_knowledge_sheet_id` | `tenants/{sno}/knowledge/` | Phase 6A |
| **Industry Shared** | `shared/{industry}/` Google Sheet | `shared/{industry_code}/knowledge/` | 未實作 |
| **Global Shared** | 平台 Google Sheet | `shared/global/knowledge/` | 未實作 |

#### 18.7.3 Shared Layer 治理（不變）

Shared Layer ≠ Public；Default Private；預設不進 Runtime；須 **Registry + Policy** 明確啟用。

**交叉引用：** `BATS_TENANT_DATA_CLASSIFICATION.md` §1.8、`BATS_DATA_CONTRACT.md` §1.5、`BATS_SHARED_KNOWLEDGE_CONTRACT.md` §3.6

### 18.8 Knowledge Priority Rule（P1-7 SSOT）

> **SSOT 主文件：** `BATS_DATA_SOURCE_REGISTRY.md` §4.4

#### 18.8.1 Knowledge Retrieval Priority

| Level | 名稱 | GCS 路徑 |
|-------|------|----------|
| **1** | Tenant Private Knowledge | `tenants/{sno}/knowledge/` |
| **2** | Industry Shared Knowledge | `shared/{industry_code}/knowledge/` |
| **3** | Global Shared Knowledge | `shared/global/knowledge/` |
| **4** | Human Service | 非 GCS；轉人工 |

**正式原則：** Tenant Private **>** Industry Shared **>** Global Shared **>** Human Service

#### 18.8.2 Fallback Rule

```text
Level 1 無資料 → Level 2
Level 2 無資料 → Level 3
Level 3 無資料 → Level 4 Human Service
```

| 禁止 | 說明 |
|------|------|
| AI 自行推測 | 無 grounding 資料不得回答 |
| AI 幻覺補充 | 與 `BATS_GEMINI_RENDERER_CONTRACT.md` §9 一致 |
| AI 編造旅遊規定 | 護照、簽證、入境等須有 GCS 依據 |

#### 18.8.3 Industry Shared First Principle

產業專屬知識（護照、入境、行李等）→ **`shared/{industry}/`**，**非** `shared/global/`。Global 僅跨產業共通與暫存。

**Status:** Reserved For Future Multi-Industry Expansion

**Sheet Contract：** `BATS_SHARED_KNOWLEDGE_SHEET_CONTRACT.md`

---

## 19. Update Entry Rule

### 19.1 正式規則

**主機A固定上傳頁為唯一資料更新入口。**

旅行社 **不得** 直接透過以下方式進行 BDS 資料更新：

| 禁止方式 | 說明 |
|----------|------|
| **Google Drive** | 不得直接上傳／編輯 Drive 檔案觸發 BDS |
| **Google Sheet** | 不得直接編輯 Sheet 觸發 BDS |

### 19.2 正式更新流程

```text
旅行社
        ↓
主機A固定上傳頁
        ↓
    Upload
        ↓
      BDS
        ↓
Google Drive（Archive Layer）
        ↓
      GCS（Knowledge Layer）
        ↓
      BATS
```

### 19.3 與 Mode B 對齊

| 項目 | 說明 |
|------|------|
| 觸發點 | 上傳頁 Upload 成功（§6.4、§13.1） |
| 非觸發點 | Drive 檔案直接修改、Sheet 儲存格即時編輯 |
| BDS 職責 | 接收 Upload → 寫入 Archive → 驗證轉換 → 寫入 GCS |

---

## 20. Google Drive 三層資料分類

> **Phase 6B-1D 修正：** 平台層 Drive 樹狀結構以 `BATS_DATA_SOURCE_REGISTRY.md` §6.5 為準（**Industry First, Tenant Second**）。**Shared Layer 不屬於單一旅行社**；租戶 Drive 僅含 `01_Private_Layer/`。下列 §20.2～§20.4 為 **data_category 語意**；GCS 路徑（§20.5）**不變**。

### 20.1 平台層 Drive 結構（正式）

**營運帳號：** `bbcshops88@gmail.com`

```text
bbcshops88@gmail.com
├── industries/{industry_code}/
│   ├── tenants/{tenant_key}/01_Private_Layer/   ← Tenant Private（單一租戶）
│   └── shared/02_Shared_Layer/                  ← Industry Shared（產業層）
├── global/02_Global_Shared_Layer/               ← Global Shared（平台層）
└── registrations/                               ← customer_registration（平台層）
```

### 20.1.1 租戶資料夾（僅 Private）

每個租戶 Google Drive Folder：

```text
industries/{industry_code}/tenants/{tenant_key}/
└── 01_Private_Layer/
```

**禁止：** `tenants/{tenant_key}/02_Shared_Layer/`、租戶下任何 Shared 子資料夾，或 **tenant-specific shared folder**。

### 20.2 `01_Itinerary_Data`

| 項目 | 說明 |
|------|------|
| **用途** | DM、PDF、圖片、行程 Excel |
| **角色** | Archive Layer |
| **data_category** | `itinerary_data`（§12 Category A） |
| **旅行社權限** | **不分享**給旅行社 |
| **BDS 同步** | 下一階段；不納入 v1 MVP（§12 Rule 6） |

### 20.3 `02_Private_Knowledge`

| 項目 | 說明 |
|------|------|
| **用途** | QA、服務項目、特殊價格、公司資料、其他商品源 |
| **角色** | Archive Layer |
| **data_category** | `tenant_private_knowledge`（§12 Category B） |
| **旅行社權限** | 可檢視、可下載；**不可**編輯、**不可**刪除 |
| **BDS 同步** | v1 MVP（1 Sheet / 5 Tabs → `knowledge/` 下 5 JSON；`BATS_DATA_CONTRACT.md`） |

### 20.4 `03_Customer_Registration`

| 項目 | 說明 |
|------|------|
| **用途** | 旅客報名資料 |
| **角色** | **交易資料層**（非 Knowledge Layer） |
| **data_category** | `customer_registration`（§21） |
| **旅行社權限** | 可檢視、可下載；**不可**編輯、**不可**刪除 |
| **BDS 同步** | **不得** 進入 GCS Knowledge Layer |

### 20.5 與 GCS 路徑對照

> **GCS 路徑不變。** 下列為 data_category 語意與 GCS 對照；Drive 平台層路徑見 §20.1。

| data_category 語意 | Drive 平台層路徑（概念） | GCS 對應（若適用） |
|--------------------|--------------------------|-------------------|
| `itinerary_data` | `industries/{industry_code}/tenants/{tenant_key}/01_Private_Layer/` | `tenants/{sno}/knowledge/itinerary/`（下一階段） |
| `tenant_private_knowledge` | `industries/{industry_code}/tenants/{tenant_key}/01_Private_Layer/` | `tenants/{sno}/knowledge/service_qa.json` 等 |
| `shared_knowledge` | `industries/{industry_code}/shared/02_Shared_Layer/`、`global/02_Global_Shared_Layer/` | `shared/.../knowledge/` |
| `customer_registration` | `registrations/` | **無** — 不得寫入 GCS Knowledge |

---

## 21. Customer Registration Rule

### 21.1 正式規則

**`customer_registration`（旅客報名資料）不得進入：**

| 禁止進入 | 說明 |
|----------|------|
| BDS Knowledge Layer | BDS 不得將報名資料轉換為知識 JSON 寫入 GCS |
| GCS Knowledge Layer | `tenants/{sno}/knowledge/` 下不得含報名交易資料 |
| Gemini Context | Response Layer 不得注入旅客報名 PII |
| BATS Search | 行程搜尋、客服問答流程不得讀取報名資料 |

### 21.2 原因

| 項目 | 說明 |
|------|------|
| **資料性質** | 交易資料（旅客報名、付款、個資） |
| **非知識資料** | 不屬 `itinerary_data` 或 `tenant_private_knowledge` |
| **合規** | PII 隔離；與 §3.2 不適用範圍一致 |

### 21.3 儲存位置

| 層級 | 允許 |
|------|------|
| Google Drive `registrations/`（平台層） | ✅ Archive / 交易資料層 |
| GCS `knowledge/` | ❌ 禁止 |
| BATS runtime 讀取 | ❌ 禁止 |

### 21.4 與 Host B 既有報名系統之邊界

`03_Customer_Registration` 與 Host B 既有報名系統 **職責分離**；下列為正式邊界：

| # | 規則 | 說明 |
|---|------|------|
| 1 | **Drive 用途限定** | `03_Customer_Registration` **僅**作為報名資料之匯出、備份、檢視用途（Archive Layer） |
| 2 | **Host B 為交易權威** | 正式訂單、會員資料、庫存、報名狀態，仍以 **Host B 既有報名系統與 SQL** 為準 |
| 3 | **BDS 不得轉入 Knowledge** | BDS **不得**將 `customer_registration` 轉入 GCS Knowledge Layer |
| 4 | **不得進 Gemini Context** | BDS **不得**將 `customer_registration` 提供給 Gemini Context |
| 5 | **BATS 不得消費** | BATS **不得**使用 `customer_registration` 作為商品搜尋或客服知識來源 |

```text
Host B 報名系統 + SQL     ← 正式訂單／會員／庫存／報名狀態（權威）
        │
        │ 匯出（備份／檢視）
        ▼
Drive 03_Customer_Registration   ← Archive only；非 BATS／Gemini 來源
        ✕
GCS Knowledge / Gemini / BATS Search
```

| 系統 | 角色 |
|------|------|
| **Host B + SQL** | 交易執行與狀態權威 |
| **Drive `03_Customer_Registration`** | 匯出備份、旅行社可檢視／下載 |
| **BDS / GCS / BATS / Gemini** | **不參與** 報名交易處理與知識注入 |

---

## 22. Tenant Folder Isolation Rule

### 22.1 正式規則

**所有租戶必須擁有獨立 Google Drive Folder。**

| 項目 | 規則 |
|------|------|
| **格式** | `industries/{industry_code}/tenants/{tenant_key}/` |
| **禁止** | 多個租戶共用同一資料夾 |
| **對應關係** | Drive Folder 必須可對應 `sno`、`industry_code`、`tenant_key`、Google Sheet（若適用）、GCS Prefix `tenants/{sno}/` |

### 22.2 租戶子資料夾

每個 `industries/{industry_code}/tenants/{tenant_key}/` 下 **僅** 含 Tenant Private Layer：

```text
industries/{industry_code}/tenants/{tenant_key}/
└── 01_Private_Layer/
```

產業 Shared 位於 `industries/{industry_code}/shared/`（§20.1）；Global Shared 位於 `global/`；`customer_registration` 位於 `registrations/`（§21.3）。

> **Legacy flat folders：** 根層 `tenants/{tenant_key}/` 或 pre-governance folder ID 僅能作 **Migration Debt** 暫時映射；正式 SSOT 見 `BATS_DATA_SOURCE_REGISTRY.md` §6.5.6。

### 22.3 與 GCS 隔離對齊

| 邊界 | Drive | GCS |
|------|-------|-----|
| 租戶識別 | `industries/{industry_code}/tenants/{tenant_key}/`（邏輯） | `tenants/{sno}/` |
| 跨 tenant 讀寫 | **禁止** | **禁止**（§10.1） |

### 22.4 Service Account 權限

Service Account 僅授權必要之 `tenants/{sno}/` prefix；不得授予跨 tenant 之共用資料夾寫入權限。

---

## 23. Anti Hardcode Rule

### 23.1 正式規則

**`travel_b` 僅為 Pilot Tenant，不得作為架構特例。**

| 禁止 | 說明 |
|------|------|
| **hardcode travel_b** | 核心 BDS 同步流程、BATS 讀取邏輯不得寫死 `travel_b` 或 `5f99b8d665e8444d` |
| **架構特例** | Pilot 實作須可參數化，供 `travel_c`、`travel_d` 及未來 20～200 家旅行社複用 |

### 23.2 新增租戶之正確方式

| 動作 | 允許 |
|------|------|
| 新增 Registry Config | ✅ `tenants/{sno}/` Drive 資料夾、GCS prefix、`product_sources` 等 |
| 修改核心同步流程 | ❌ 禁止為單一 tenant fork BDS 管線 |
| 複製 `travel_x_*.php` | ❌ 禁止（§24.3） |

### 23.3 範例

| 租戶 | 商品源 | 正確擴展方式 |
|------|--------|--------------|
| `travel_b` | bbctravel + grp + tourcenter | Pilot；Registry `source_instance_keys` |
| `travel_c` | bbctravel only | 新增 Registry Config；不修改 BDS 核心 |
| `travel_d` | grp + tourcenter | 新增 Registry Config；不修改 BDS 核心 |

### 23.4 與 Registry Driven 對齊

新增 tenant **只能** 新增 Registry Config（Drive 資料夾、GCS 路徑、catalog、instances）；**不得** 修改 BDS 核心同步流程或 BATS Intelligence Layer 核心邏輯。

---

## 24. Guiding Principle

### 24.1 最高指導原則

> **人走上傳頁，機器讀 GCS；Drive 存原件，BDS 是唯一橋；BATS 只信 Knowledge Layer。**

### 24.2 必須遵守

| 原則 | 說明 |
|------|------|
| **Mode B Only** | 上傳成功 → 立即同步；不引入 Mode A |
| **分層分離** | Source（上傳頁）/ Archive（Drive）/ Knowledge（GCS）/ Intelligence / Response 邊界清晰 |
| **Data Category 分離** | `itinerary_data`、`tenant_private_knowledge`、`shared_knowledge` 不得混用（§12） |
| **Shared Knowledge Fallback** | `tenant_private_knowledge` 優先；`shared_knowledge` 僅 fallback（§12.7） |
| **Query / Sync 分離** | BATS Query Flow 不得觸發 BDS（§13.3） |
| **BDS Safety Rule** | 同步失敗不得覆蓋正式資料（§14.1） |
| **Tenant Isolation** | `sno` 為不可跨越之邊界 |
| **Registry Driven** | BATS 擴展靠 registry，不靠 hardcode（§23） |
| **Update Entry 唯一** | 主機A固定上傳頁為唯一更新入口（§19） |
| **Drive = Archive** | Google Drive 不作 BATS 查詢來源（§18） |
| **Fail-Safe** | 同步失敗不產生錯誤資料、不產生錯誤商品源連結 |
| **Document First** | BDS 實作前須以本文件為 SSOT |

### 24.3 禁止事項

| 禁止 | 原因 |
|------|------|
| BATS runtime 直讀 Google Sheet / Drive 營運設定 | 延遲、權限、不可 cache；Drive 為 Archive 非查詢來源（§18） |
| 旅行社直接透過 Drive / Sheet 更新 BDS 資料 | 違反 Update Entry Rule（§19） |
| BATS Query Flow 觸發 BDS / Sheet / Drive Sync | 違反同步與查詢分離（§13.3） |
| `customer_registration` 進入 Knowledge / Gemini / BATS Search | 違反 §21 |
| hardcode travel_b 為架構特例 | 違反 §23 |
| 多租戶共用 Drive 資料夾 | 違反 §22 |
| 混用 `itinerary_data` 與 `tenant_private_knowledge` | 違反 §12 Rule 1 |
| 兩類資料共用同一 Data Contract / Validation | 違反 §12 Rule 2、Rule 3 |
| 將 PII / secret 寫入 GCS | 安全與合規 |
| 為每家旅行社複製 `travel_x_multi_source_links.php` | 違反 Registry Driven |
| 在未修訂 SSOT 前實作 Mode C | 破壞唯一模式決議 |
| 在未修訂 SSOT 前新增 `products/`、`uploads/`、`parsed/`、`rag/` | 違反 §11.1 正式結構 |
| Gemini 直接讀 GCS | 破壞 Response Layer 邊界 |

### 24.4 與其他 SSOT 關係

| 文件 | 關係 |
|------|------|
| `BATS_DATA_SOURCE_REGISTRY.md` | Source Registry 結構、`industry_code`、Shared Knowledge 優先序 |
| `BATS_DATA_CONTRACT.md` | Data Contract L3 SSOT — Sheet Tab、欄位、JSON 格式、Validation |
| `TENANT_SOURCE_REGISTRY_POLICY.md` | Tenant / Source Instance 治理；BDS 同步目標資料結構 |
| `TENANT_SOURCE_RUNTIME_BRIDGE_V1.md` | Intelligence Layer 橋接；消費 GCS 同步後之 `product_sources` |
| `DOCUMENTATION_GOVERNANCE_POLICY.md` | 本文件為 BDS 領域 L1 SSOT |
| `RECOVERY_AND_ROLLBACK_POLICY.md` | 同步失敗與回滾程序 |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.9** | 2026-06-06 | Phase 6B-1D：§20／§22 Drive Tree 遷移為 Industry First（`industries/{industry_code}/...`）；GCS 不變 |
| **v1.5** | 2026-06-08 | MVP 範圍校正：1 Sheet / 5 Tabs / 5 JSON；cross-ref `BATS_DATA_CONTRACT.md`（L3 SSOT） |
| **v1.4** | 2026-06-08 | 新增 `shared_knowledge`（Category C）、`shared/{industry}/knowledge/` GCS 結構、§12.7 Cross-Reference、`BATS_DATA_SOURCE_REGISTRY.md` 對齊 |
| **v1.8** | 2026-06-10 | §18.8 P1-7 Knowledge Priority Rule；Industry Shared First；Human Service Level 4 |
| **v1.7** | 2026-06-10 | §18.7 Structured Knowledge Source SSOT（Sheet = Structured Knowledge） |
| **v1.6** | 2026-06-10 | §18.6 Phase 6A Manual Sync Command cross-ref |
| **v1.5** | 2026-06-10 | §18.5 Runtime Source Architecture（P1 SSOT）：Drive = Archive SoT、GCS = Runtime Knowledge Source |
| **v1.4** | 2026-06-10 | §20／§22 Drive 平台層架構修正：Shared 移出租戶資料夾；GCS 不變 |
| **v1.3** | 2026-06-08 | 新增 §18～§23：Google Drive Archive Layer、Update Entry Rule、Drive 三層分類、Customer Registration Rule、Tenant Folder Isolation、Anti Hardcode Rule |
| **v1.2** | 2026-06-08 | 新增 BDS Data Category（itinerary_data / tenant_private_knowledge）與六條正式規則 |
| **v1.1** | 2026-06-08 | 補強 GCS 正式結構、Query/Sync 分離、BDS Safety Rule、MVP Use Case |
| **v1.0** | 2026-06-08 | 第一版正式 SSOT；確立 Mode B 為唯一同步模式 |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| 文件狀態 | **Draft — 待審核** |
| 程式實作 | **未開始**（依本文件後續 Phase 執行） |
| Git commit | **尚未提交** |
