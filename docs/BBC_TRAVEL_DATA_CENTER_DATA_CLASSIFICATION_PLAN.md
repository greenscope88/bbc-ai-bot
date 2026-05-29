# BBC 旅業資料中心 — Phase 2 資料分類規劃

**副標：** 旅遊業 AI 資料處理與雲端同步平台（Data Classification Plan）

**文件代號：** `BBC_TRAVEL_DATA_CENTER_DATA_CLASSIFICATION_PLAN`

**版本：** Phase 2（規劃 only）

**狀態：** Planning — 本文件不觸發任何程式、雲端資源或資料庫變更

**規劃主機：** 主機 A `103.1.222.14` · 專案路徑 `C:\bbc-ai-bot`

**關聯文件：** [BBC_TRAVEL_DATA_CENTER_GCS_DRIVE_ARCHITECTURE_PLAN.md](./BBC_TRAVEL_DATA_CENTER_GCS_DRIVE_ARCHITECTURE_PLAN.md)（Phase 1 架構）

**規劃日期：** 2026-05-29

---

## 一、文件目的

本文件為 **BBC 旅業資料中心（BBC Travel Data Center）** 之 **Phase 2：Data Classification Plan**，在 Phase 1 架構（GCS / Drive / Sheets / 主機分工）之上，明確定義：

1. **三大資料分類** 的邊界、入口、儲存路徑與消費方式  
2. **行程資料** 固定上傳入口（方案 B）與檔案關聯 ID 體系  
3. **租戶私有知識** 與 **平台共用知識** 之分層與優先權  
4. **Google Drive / Sheets**、**Gemini Parsing** 與 **BATS / Hybrid / RAG** 之銜接關係  

**本階段僅產出規劃文件**，不修改程式、不建立雲端資源、不執行 SQL。

---

## 【已確認決策】

| 項目 | 決策內容 |
|------|----------|
| **行程資料上傳方式** | **方案 B：固定網址上傳** |
| **改版基礎頁面** | 既有頁面 `https://kowanbo.com/view/tour/addTour.php` |
| **新版命名（規劃）** | `addTour_v2` 或 **BBC Travel Data Center Upload Version** |
| **開發策略** | **不從零開發新頁面**；以 `addTour.php` 為 UI / 流程基礎改版 |
| **本階段** | 僅文件規劃；不修改 `addTour.php`、不部署新版 |

> 目前行程資料固定網址上傳，將以 `https://kowanbo.com/view/tour/addTour.php` 作為改版基礎。

---

## 二、資料分類總覽

BBC 旅業資料中心將 AI 可用資料分為 **三大類**。每類有獨立 **上傳入口**、**GCS 路徑**、**維護角色** 與 **讀取優先權**。

| 分類 | 代號 | GCS 根路徑 | 維護者 | 上傳 / 維護入口（規劃） |
|------|------|------------|--------|-------------------------|
| **A. 行程資料** | Itinerary Package | `tenants/{sno}/packages/` 及關聯子目錄 | 旅行社（已授權帳號） | 固定網址：`addTour.php` → `addTour_v2` |
| **B. 旅行社私有資料** | Tenant Private Knowledge | `tenants/{sno}/qa/`、`services/`、`sheets_json/` 等 | 旅行社員工 | Google Login + 上傳 / Sheet 協作 |
| **C. 共用資料** | Shared Knowledge | `shared/` | BBC 平台管理者 | BBC / 管理者 Google 登入更新 |

### 分類關係圖

```
                    BBC 旅業資料中心
                           │
         ┌─────────────────┼─────────────────┐
         ▼                 ▼                 ▼
   A 行程資料        B 租戶私有           C 共用
   (Package)      (Tenant Private)    (Shared)
         │                 │                 │
 tenants/{sno}/     tenants/{sno}/      shared/
   packages/          qa, services,      visa, passport,
                      sheets_json…       common_qa, rag…
```

### 入口邊界（摘要）

| 分類 | 是什麼 | 不是什麼 |
|------|--------|----------|
| **A** | 單一商品行程之圖檔 + Excel + PDF/Word | 非行程 QA、非平台簽證庫 |
| **B** | 租戶專屬知識與協作資料 | 非 `addTour` 行程三件套、非 `shared/` |
| **C** | 跨租戶平台知識 | 非單一旅行社 Sheet 或 package |

**讀取衝突時：** B（Tenant Private）> C（Shared）> Host B 正式業務資料（依欄位語意，見第十二章）。

---

## 三、行程資料（Itinerary Package）

### 3.1 定義

**行程資料** 指單一旅遊 **商品 / 行程（package）** 之結構化與非結構化素材，供商品頁、LINE OA、Gemini、Hybrid 搜尋結果展示與檢索上下文使用。

### 3.2 上傳入口（方案 B · 固定網址）

| 項目 | 內容 |
|------|------|
| **頁面角色** | **BBC 旅業資料中心 — 行程資料上傳入口**（非租戶私有知識入口、非 Shared 入口） |
| **固定網址策略** | **方案 B**：旅行社透過固定 URL 進入，登入後上傳 |
| **基礎頁面** | `https://kowanbo.com/view/tour/addTour.php` |
| **目標新版** | `addTour_v2` / BBC Travel Data Center Upload Version |
| **開發策略** | 不從零開發；以 `addTour.php` 為改版基礎 |

### 3.3 單一行程上傳內容（三類檔案）

以 **`package_group_id`** 關聯下列三類檔案：

#### 3.3.1 圖檔

| 項目 | 內容 |
|------|------|
| **格式** | JPG、PNG、WEBP，及其他常見圖片格式 |
| **用途** | 商品主圖、行程亮點、LINE 縮圖、搜尋結果縮圖 |

#### 3.3.2 Excel

| 欄位（規劃必含） | 說明 |
|------------------|------|
| 出團日期 | Sheet 名稱可承載語意（如 `2026.07.09`） |
| 直售價 | 對客報價 |
| 同業後退 | B2B 後退 |
| 總位數 | 席次上限 |
| 成團人數 | 成團門檻 |
| 已報名人數 | 當前報名數 |

#### 3.3.3 PDF 或 Word

| 項目 | 內容 |
|------|------|
| **格式** | PDF（優先）、Word（`.doc` / `.docx`） |
| **用途** | 正式行程表、每日安排、費用說明 |

```
package_group_id
├── images/     JPG, PNG, WEBP, …
├── excel/      出團日、價格、位數、報名數
└── brochure/   PDF 或 Word 正式行程表
```

### 3.4 關聯 ID 體系

| ID | 層級 | 說明 |
|----|------|------|
| **`package_group_id`** | 行程系列 | **三類檔案之正式關聯 ID**；一組商品貫穿圖檔、Excel、brochure |
| **`file_group_id`** | 單檔版本族 | 同一邏輯檔案（如「2026Q2 價格表」）之多版本集合 |
| **`version`** | 單檔 | 遞增版本號（整數或 semver） |
| **`is_latest`** | 單檔 | 該 `file_group_id` 下是否為生效版本 |
| **`file_hash`** | 單檔 | 內容指紋（如 SHA-256）；去重、idempotent Parsing |
| **`upload_session_id`** | 一次操作 | 同一次表單 / LINE 多檔上傳之臨時關聯（選用） |

**規則：**

- 新建行程 → 產生 **`package_group_id`**
- 每上傳一個實體檔 → 分配 **`file_group_id`** + **`file_hash`** + **`version`**
- 新版本上傳 → 舊版 `is_latest=false`，新版 `is_latest=true`
- Parsing 與對外讀取以 **`is_latest=true`** 且 **`package_group_id`** 下彙總結果為準

### 3.5 GCS 路徑（`tenants/{sno}/packages/`）

行程資料寫入 **租戶私有層**，**不寫入 `shared/`**：

```
gs://bbc-travel-data-center/tenants/{sno}/
├── uploads/                                    # Signed URL 直傳暫存
├── packages/
│   └── {package_group_id}/
│       ├── original/
│       │   ├── images/{file_hash}.{ext}
│       │   ├── excel/{file_hash}.xlsx
│       │   └── brochure/{file_hash}.pdf
│       ├── parsed/
│       │   ├── images/{file_hash}.json
│       │   ├── excel/{file_hash}.json
│       │   └── brochure/{file_hash}.json
│       └── manifest.json                     # 可選：彙總 file_group 清單
├── latest/
│   └── packages/{package_group_id}.json        # 單一行程「生效」標準 JSON
└── cache/                                      # 該 package 高頻快取 metadata（選用）
```

> 與 Phase 1 目錄語意相容：`original/`、`parsed/`、`latest/packages/` 可視實作合併或 alias；**分類語意以 `packages/{package_group_id}/` 為準**。

### 3.6 上傳後流程（方案 B）

```
固定網址頁面（addTour.php → addTour_v2）
        ↓
    登入驗證（kowanbo 帳號；sno 由 registry 判定，不信任 client 傳入）
        ↓
    建立或選取 package_group_id
        ↓
    主機 A 產生 Signed URL
        ↓
    瀏覽器直傳 GCS（uploads/ → packages/{package_group_id}/original/）
        ↓
    建立 metadata（Host B：hash、version、路徑、sync_status）
        ↓
    背景 Parsing → parsed/
        ↓
    合併寫入 latest/packages/{package_group_id}.json
        ↓
    （可選）產生 PDF / JPG 公開網址
```

### 3.7 公開網址策略

| 檔案類型 | 公開 URL | 消費場景 |
|----------|----------|----------|
| **PDF** | 是 | LINE OA 連結、商品頁下載行程表 |
| **JPG / PNG / WEBP** | 是 | LINE 縮圖、Gemini 多模態、商品列表、Hybrid 搜尋結果 |
| **Excel** | 否（預設） | 僅 Parsing；不直接暴露原始檔 |
| **Word** | 視政策 | 建議轉 PDF 或僅提供 JSON |

**安全（規劃）：** URL 可撤銷 / 輪替；綁定 `sno`；路徑不可預測或採短期 Signed URL；不公開含個資檔案。

### 3.8 解析 JSON 策略

| 階段 | 路徑 | 內容 |
|------|------|------|
| **逐檔 parsed** | `packages/{package_group_id}/parsed/{type}/{file_hash}.json` | 單檔完整解析輸出（含 OCR、多 Sheet） |
| **生效 latest** | `latest/packages/{package_group_id}.json` | **BBC 標準 JSON**；AI / Adapter **唯一預設讀取** |

**`latest/packages/{package_group_id}.json` 規劃欄位（摘要）：**

```json
{
  "package_group_id": "pg_…",
  "sno": "e1fd133c7e8e45a1",
  "title": "東京五日",
  "departures": [
    {
      "departure_date": "2026-07-09",
      "retail_price": 45900,
      "trade_rebate": 1200,
      "total_seats": 32,
      "min_group_size": 10,
      "booked_count": 8,
      "source_file_hash": "sha256:…"
    }
  ],
  "images": [
    { "file_hash": "…", "public_url": "https://…", "is_latest": true }
  ],
  "brochure": {
    "file_hash": "…",
    "public_url": "https://…",
    "summary": "…"
  },
  "parsed_at": "2026-05-29T12:00:00Z",
  "schema_version": "1.0"
}
```

- Excel 列 → `departures[]`；PDF/Word → `brochure.summary` + 全文可放 parsed 子檔  
- 圖片 → `images[]` + `image_metadata`（尺寸、alt、OCR 摘要）  
- **對外服務只讀 `latest/`**；`parsed/` 供除錯與重跑

### 3.9 本類別 — Phase 2 不實作

不修改 `addTour.php`、不部署 `addTour_v2`、不建立 GCS、不實作 Signed URL / Parsing / 新增 SQL。

---

## 四、旅行社私有資料（Tenant Private Knowledge）

### 4.1 定義

**旅行社私有資料** 指 **非行程 package 三件套** 之租戶專屬知識與營運資料，用於 LINE 客服、Gemini 回答、未來私有 RAG。

### 4.2 特性

| 項目 | 說明 |
|------|------|
| **與行程資料區隔** | **非** `addTour` 上傳之 JPG / Excel / PDF·Word 組合 |
| **認證方式** | **Google Login** 上傳 / 維護（規劃） |
| **儲存層** | GCS **`tenants/{sno}/`** 私有層 |
| **讀取優先** | 同主題下 **優先於 Shared** |

### 4.3 內容範圍

| 類型 | GCS 路徑（規劃） | 說明 |
|------|------------------|------|
| 服務項目 | `tenants/{sno}/services/` | 接送、保險、加購 |
| QA | `tenants/{sno}/qa/` | 旅行社自有 FAQ |
| 公司介紹 | `tenants/{sno}/latest/company_profile.json` | 品牌、聯絡方式 |
| 護照代辦規則 | `tenants/{sno}/latest/passport_rules.json` | 租戶自訂流程 |
| 簽證規則 | `tenants/{sno}/latest/visa_rules.json` | 租戶自訂（可覆蓋 shared 文案語意，業務事實仍以官方為準） |
| 特殊說明 | `tenants/{sno}/latest/notes.json` | 內部政策、退改規則 |
| 內部知識 | `tenants/{sno}/rag/`（未來） | 私有 RAG 素材 |
| Sheet 同步 | `tenants/{sno}/sheets_json/` | Google Sheets 快照 JSON |

### 4.4 已知私有 Google Sheet（僅標記）

| 項目 | 內容 |
|------|------|
| **URL** | `https://docs.google.com/spreadsheets/d/1al59g7_h_VmeZiL3K0LZpbTf9GdWj5PL92WFSCs1kP8/edit` |
| **分類** | **Tenant private source** |
| **不是** | **Shared source**（不可寫入 `shared/`） |
| **Phase 2** | **不可讀取、不可修改、不連線**；僅架構標記 |
| **未來綁定** | registry `google_sheet_sources[]` → 同步至 `sheets_json/` |

### 4.5 入口（規劃）

- 租戶後台或 Google Login 門戶（**非** `addTour.php`）
- 維護 QA、服務項目、公司介紹等
- LINE OA 報名旅客明細可寫入 **Google Sheets**（個資不進 Host B SQL）

---

## 五、共用資料（Shared Knowledge）

### 5.1 定義

**共用資料** 由 **BBC 平台管理者** 維護，服務 **所有租戶**，存於 GCS **`shared/`**。

### 5.2 維護方式

| 項目 | 說明 |
|------|------|
| **維護者** | BBC 管理者 |
| **登入** | BBC / 管理者 Google 帳號 |
| **儲存** | `gs://bbc-travel-data-center/shared/` |
| **不作** | 單一旅行社私有 Sheet 或 package 上傳入口 |

### 5.3 內容範圍

| 路徑 | 內容 |
|------|------|
| `shared/visa/` | 各國簽證 |
| `shared/passport/` | 護照共通知識 |
| `shared/travel_notice/` | 旅遊公告、注意事項 |
| `shared/common_qa/` | 平台共用 QA |
| `shared/rag/` | 共用 RAG 索引素材（未來） |
| `shared/cache/` | 共用層快取 manifest |

### 5.4 與租戶私有之關係

- 租戶無 **shared 寫入權**（規劃）
- 查詢時：租戶私有無答案 → 再讀 `shared/` 補充
- 租戶私有與 shared **文案衝突** → **Tenant Private 優先**（見第十二章）

---

## 六、Google Drive 規劃

### 6.1 定位

**Google Drive 是人工協作與查看層，不是正式 AI 查詢層。**

### 6.2 可放置內容

- 人工維護 Excel（Parsing 前）
- 旅行社共編資料夾
- Google Sheets 捷徑
- PDF 查看副本、合約掃描

### 6.3 正式資料流

```
Google Drive（人工編輯 / 查看）
        ↓
   同步或匯出觸發（主機 A 編排）
        ↓
   Gemini Parsing / 轉檔
        ↓
   GCS tenants/{sno}/latest/ 或 shared/latest/
        ↓
   BATS / Retrieval Adapter 讀取 JSON
```

**禁止：** Hybrid / LINE 查詢當下 **直接呼叫 Drive API** 讀大檔。

---

## 七、Google Sheets 規劃

### 7.1 定位

**Google Sheets 是人工維護資料層；不是正式資料庫。**

### 7.2 功能

| 用途 | 說明 |
|------|------|
| QA / 服務項目 | 員工直接編輯 |
| 出團日 / 可售 / 已報名 | 業務協作（可與 Host B 對照） |
| LINE 報名旅客明細 | 姓名、生日等寫入 Sheet，**不寫 Host B** |

### 7.3 同步流

```
Google Sheets（編輯）
        ↓
   排程 / 事件 Sync Job
        ↓
   GCS …/sheets_json/{sheet_id}/{snapshot_at}.json
        ↓
   （可選）Parsing → latest/qa 或 latest/packages
        ↓
   Host B 僅存 row_id、sync_status、GCS 路徑指標
```

**查詢路徑：** 讀 **GCS `latest` JSON**，不直讀 Sheets API。

---

## 八、Gemini Parsing 規劃

### 8.1 輸入輸出對照

| 輸入 | 處理 | 輸出 |
|------|------|------|
| Excel / Google Sheets | tenant mapping → 標準欄位 | `parsed/` + `latest/*.json` |
| PDF / Word | 文字抽取 / OCR / 摘要 | `brochure` JSON + summary |
| JPG / PNG / WEBP | OCR + image metadata | `images[]` JSON |

### 8.2 Tenant mapping

- 各旅行社 Excel **欄位名、順序、Sheet 結構可不同**
- 透過 **`tenant mapping config`**（Phase 3 設計）轉 **BBC 標準 JSON**
- 建議路徑：`tenants/{sno}/config/field_mapping.json`

### 8.3 多 Sheet

- 單一 workbook **逐 Sheet 解析**
- Sheet 名稱可代表 **出發日**（如 `2026.07.09`）
- 結果併入 `departures[]` 或 `parsed/.../sheets/{name}.json`

### 8.4 編排

由 **主機 A** 觸發背景 Job；完成後更新 Host B `sync_status`；**Phase 2 不實作**。

---

## 九、BATS 關係

### 9.1 定位

**BBC 旅業資料中心是 BATS 的資料供應層，不是搜尋層。**

```
使用者訊息 → LINE Webhook → SaaSRouter (BATS)
                              │
              ┌───────────────┼───────────────┐
              ▼               ▼               ▼
        Tour Prompt      Formatter      Gemini 編排
              │
              ▼
   Retrieval / Data Adapter ← BBC 旅業資料中心 (GCS JSON)
              │
              ▼
        Hybrid Smart Search（行程搜尋，見第十章）
```

### 9.2 原則

- **不** 將 BATS 核心改為資料儲存系統
- **不** 在 BATS 內直接讀 Drive / Sheets
- 透過 **標準 JSON + Adapter** 注入 context

---

## 十、Hybrid Smart Search 關係

### 10.1 定位

**Hybrid Smart Search** 維持既有核心（`HybridSearchConditionBuilder`、`TourSearchApiClient` 等），**本計畫不重構**。

BBC 旅業資料中心 **不取代** Hybrid；僅在需要時 **補充非 Host B 結構化知識**（如 package 文案、圖片 URL）。

### 10.2 未來接入之 Data Source（規劃）

| Adapter | 來源 | 用途 |
|---------|------|------|
| **HostBSource** | Host B API | 即時可售、價格、庫存（正式業務） |
| **TenantPrivateSource** | `tenants/{sno}/latest/`、`qa/`、`services/` | 租戶知識、package JSON |
| **SharedSource** | `shared/latest/` | 簽證、共用 QA |
| **FutureRagSource** | `tenants/{sno}/rag/`、`shared/rag/` | 向量檢索（未來） |

**注意：** `GoogleSheetSource` 僅作 **同步管道**，查詢時讀 GCS，不列為即時查詢 Adapter。

---

## 十一、未來 RAG 規劃

### 11.1 索引分層

| 層級 | GCS 路徑 | 說明 |
|------|----------|------|
| **Shared RAG** | `shared/rag/` | 平台共用知識向量素材 |
| **Tenant RAG** | `tenants/{sno}/rag/` | 租戶私有知識 |

### 11.2 檢索順序

1. `tenants/{sno}/rag/`（私有 RAG）
2. `shared/rag/`（共用 RAG）
3. Host B 正式資料（事實查核）

### 11.3 Gemini Context Cache

| 項目 | 說明 |
|------|------|
| **定位** | 高頻 QA **快取層**（`tenants/{sno}/cache/`） |
| **不取代 RAG** | 長尾、法規、多模態仍以 RAG + `latest` JSON 為主 |
| **失效** | 與 `file_hash`、`latest` 版本掛鉤 |

---

## 十二、資料讀取優先權

查詢或組裝 AI context 時：

| 順序 | 來源 | 代號 |
|------|------|------|
| **1** | `tenants/{sno}/` 私有資料 | Tenant Private |
| **2** | `shared/` 共用資料 | Shared |
| **3** | Host B API / SQL | 正式業務資料 |

### 衝突規則

| 情境 | 規則 |
|------|------|
| 私有 vs 共用（文案 / FAQ） | **Tenant Private 優先** |
| 私有 / 共用 vs Host B（價格、庫存、可售） | **Host B 正式資料優先** |
| 同一 `package_group_id` 多版本 | **`is_latest=true` 優先** |

---

## 十三、主機 A / 主機 B 分工

### 13.1 主機 A（`103.1.222.14` · `C:\bbc-ai-bot`）

| 職責 | 說明 |
|------|------|
| 登入驗證 | kowanbo 帳號、後台 API |
| Google Login | 租戶私有 / 管理者協作入口（規劃） |
| Signed URL | GCS 直傳簽發 |
| Parsing Orchestration | 觸發、重試、寫回 GCS |
| Metadata 協調 | 呼叫 Host B 寫入輕量 metadata |
| sno 權限 | 依 `tenant_registry`，不信任 client |
| 背景任務 | Sheet sync、Parsing queue |

**不負責：** 長期保存檔案本體於本地磁碟。

### 13.2 主機 B（SQL Server）

| 儲存 | 不儲存 |
|------|--------|
| 正式行程、訂單、可售業務欄位 | 檔案二進位、OCR 全文 |
| 檔案 metadata（路徑、hash、version） | **旅客姓名、出生年月日** |
| package metadata、`package_group_id` | 大型 JSON blob |
| Google Sheet row / reference ID | |
| sync_status、last_parsed_at | |

### 13.3 分工示意

```
主機 A                          主機 B
───────                         ───────
Signed URL ──→ GCS 本體          metadata 指標
Parsing Job ──→ GCS JSON        正式業務 API ← Hybrid
Google Login 編排               Sheet row_id / sync_status
```

---

## 十四、Phase 3 建議

| 順序 | 工作項 | 說明 |
|------|--------|------|
| 1 | **JSON Schema Design** | `packages`、`qa`、`services`、`departures` 標準契約 |
| 2 | **Upload Flow Design** | `addTour_v2`、Signed URL、三類檔前端直傳 |
| 3 | **Google Sheet Sync Design** | 增量、錯誤重試、`sheets_json/` 命名 |
| 4 | **Package Metadata Design** | Host B 表 / API：`package_group_id`、file 關聯 |
| 5 | **Tenant Mapping Design** | Excel 欄位對照檔、多 Sheet 規則 |
| 6 | Data Source Adapter 介面 | `TenantPrivateSource`、`SharedSource` 注入點 |
| 7 | 公開 URL 與 CDN 政策 | PDF / 圖片與 LINE、商品頁契約 |
| 8 | RAG index pipeline | shared vs tenant 分索引 |
| 9 | 個資與稽核 | Sheet PII、保留週期、存取 log |

---

## 附錄 A：三大分類對照表

| 維度 | A 行程資料 | B 租戶私有 | C 共用 |
|------|------------|------------|--------|
| GCS | `tenants/{sno}/packages/` | `tenants/{sno}/qa/` 等 | `shared/` |
| 入口 | addTour 固定網址 | Google Login | BBC 管理者 |
| 關聯 ID | `package_group_id` | `file_group_id` 等 | 平台 topic id |
| 維護者 | 旅行社 | 旅行社 | BBC |

## 附錄 B：文件修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 0.1 | 2026-05-29 | Phase 2 初版；【行程資料固定上傳入口】、【已確認決策】 |
| 1.0 | 2026-05-29 | 補齊完整 Phase 2 Data Classification Plan（一至十四章） |

---

*本文件為架構規劃，不構成實作承諾。實作前須另開 Phase 3 實作計畫並通過變更審查。*
