# BBC 旅業資料中心

**副標：** 旅遊業 AI 資料處理與雲端同步平台

**文件代號：** `BBC_TRAVEL_DATA_CENTER_GCS_DRIVE_ARCHITECTURE_PLAN`

**版本：** Phase 1（規劃 only）

**狀態：** Planning — 本文件不觸發任何程式、雲端資源或資料庫變更

**規劃主機：** 主機 A `103.1.222.14` · 專案路徑 `C:\bbc-ai-bot`

**規劃日期：** 2026-05-29

---

## 一、文件目的

**BBC 旅業資料中心（BBC Travel Data Center）** 是 **BBC AI SaaS** 的 **資料供應層（Data Supply Layer）**，位於 LINE Webhook / Hybrid Smart Search / Gemini 回覆編排之下、正式業務系統（主機 B SQL Server）與人工協作工具（Google Drive / Sheets）之上。

本層負責規劃與銜接以下能力之 **資料基礎建設**：

| 能力 | 角色 |
|------|------|
| **GCS** | 正式檔案本體、解析後 JSON、版本與快取 |
| **Google Drive** | 人工協作、共編、附件查看 |
| **Google Sheets** | 人工維護 QA、服務項目、出團狀態、LINE 報名明細（協作層） |
| **Gemini Parsing** | Excel / PDF / Word / 圖片 → 標準 JSON |
| **BATS** | 既有旅遊 AI 執行與回覆管線（本文件不重構其核心） |
| **Hybrid Smart Search** | 行程檢索與條件解析（資料由本中心供應，非直接改核心） |
| **未來 RAG** | 私有 / 共用知識索引，以 GCS parsed JSON 為素材 |

**Phase 1 目標：** 僅產出架構與路徑、分工、優先權、關聯 ID 之 **唯讀規劃**；不建立 bucket、不串 API、不改程式。

---

## 二、核心原則

### 2.1 主機 A 不接收檔案本體

- LINE OA、後台、批次上傳的 **檔案本體** 不經主機 A 長期落地。
- 主機 A 僅負責：驗證、sno 授權、**Signed URL** 簽發、upload session、metadata 協調、Parsing 編排。
- **效益：** 降低主機 A 頻寬、磁碟、CPU / RAM 負載，避免與 Webhook 同機競爭資源。

### 2.2 GCS 為正式儲存層

- 所有 AI 與檢索服務 **機器可讀** 的正式內容以 **GCS** 為準（含原始檔副本與 `parsed` / `latest` JSON）。
- JSON 為 **契約層**；Drive / Sheets 為 **輸入或協作來源**，須同步或轉換後才進 GCS `latest`。

### 2.3 Google Drive / Sheets 為人工協作層

- 供旅行社員工編輯、共編、查看 PDF 副本。
- **不作** 正式查詢路徑上的即時讀取層（查詢時讀 GCS `latest` 或 Host B API）。

### 2.4 主機 B：正式資料 + 輕量 metadata

- SQL Server 存 **正式業務資料** 與 **檔案 / 同步 metadata**。
- **不存：** 大檔二進位、長篇 OCR 全文、旅客姓名、出生年月日等個資欄位。
- **效益：** 控制 `.bak` 體積、備份時間、還原風險；個資留在 Sheets 或合規儲存（另案）。

### 2.5 資料分層：私有優先、共用補充

| 層級 | 路徑概念 | 說明 |
|------|----------|------|
| **租戶私有** | `tenants/{sno}/` | 該旅行社專屬 QA、商品、上傳檔、私有 RAG |
| **平台共用** | `shared/` | 簽證、護照須知、旅遊注意事項、共用 QA、共用 RAG |

**衝突規則：** 同一主題若私有與共用皆存在，**以私有層為準**；共用層僅補缺口。

### 2.6 與現有 BATS / Hybrid 的邊界

- 不直接重構 **Hybrid Smart Search** 核心（`HybridSearchConditionBuilder`、`TourSearchApiClient` 等）。
- 未來以 **Data Source Adapter / Retrieval Service** 增量接入，維持已驗收之 travel_a / travel_b 行為穩定。

---

## 三、GCS 共用層 `shared/` 規劃

**Bucket（規劃名稱，Phase 1 不建立）：** `gs://bbc-travel-data-center/`

**根路徑：** `gs://bbc-travel-data-center/shared/`

### 3.1 目錄結構

```
shared/
├── visa/              # 各國簽證資料（JSON + 可選 PDF 參考）
├── passport/          # 護照辦理、效期、共通知識
├── travel_notice/     # 旅遊注意事項、禁限帶、海關提醒
├── common_qa/         # 平台級共用 FAQ（非單一旅行社專屬）
├── rag/               # 未來共用 RAG 索引素材與 manifest
└── cache/             # 共用層快取（manifest、etag、預計算摘要）
```

### 3.2 用途說明

| 路徑 | 內容類型 | 消費者（未來） |
|------|----------|----------------|
| `visa/` | 國別簽證條件、文件清單、處理天數 | LINE 助理、RAG、固定 formatter 補充 |
| `passport/` | 護照規範、加簽、換發流程 | 同上 |
| `travel_notice/` | 目的地警示、季節性提醒 | 同上 |
| `common_qa/` | 跨租戶通用問答 | Gemini Context Cache、RAG |
| `rag/` | 共用向量索引描述檔、chunk manifest | Retrieval Service |
| `cache/` | 高頻讀取 JSON 快照、版本指標 | 主機 A 編排、減少重複解析 |

### 3.3 命名與版本（規劃）

- 建議檔名：`{topic}/{locale}/{version}/latest.json` 或 `latest.json` + 同目錄 `versions/{file_hash}.json`。
- Phase 2 再定義完整 schema；Phase 1 僅保留目錄語意。

---

## 四、GCS 租戶私有層 `tenants/{sno}/` 規劃

**根路徑：** `gs://bbc-travel-data-center/tenants/{sno}/`

> `{sno}` 與現有 **Host B wire authority** 一致（見 `config/tenant_registry.php`），由主機 A 內部 registry 解析，**不接受 LINE client 傳入 sno**。

### 4.1 目錄結構

```
tenants/{sno}/
├── uploads/           # 上傳暫存（Signed URL 直傳目標，可設生命週期）
├── original/          # 正式保存之原始檔（PDF / Excel / Word / 圖片）
├── parsed/            # Gemini Parsing 產出（依版本、file_hash）
├── latest/            # 每邏輯實體之「目前生效」JSON 指標或實體檔
├── qa/                # 旅行社自有 FAQ JSON
├── services/          # 服務項目（接送、保險、加購等）
├── packages/          # 商品 / 行程 package 結構化 JSON
├── sheets_json/       # 自 Google Sheets 同步下來的快照 JSON
├── cache/             # 租戶級 Gemini Context Cache metadata、熱點 QA
└── rag/               # 未來私有 RAG 索引素材
```

### 4.2 用途說明

| 路徑 | 用途 |
|------|------|
| `uploads/` | LINE OA 一次多檔、後台批次上傳的 **臨時區**；完成驗證後移至 `original/` |
| `original/` | 不可變或版本化保存之 **檔案本體** |
| `parsed/` | 每次解析完整輸出（含 OCR、多 Sheet、mapping 前後紀錄） |
| `latest/` | AI 與 Adapter **預設讀取** 的標準 JSON |
| `qa/` | 租戶專屬問答（優先於 `shared/common_qa/`） |
| `services/` | 服務項目清單 |
| `packages/` | 行程商品、出團日、可售狀態之結構化資料 |
| `sheets_json/` | Sheet 同步結果；**查詢不直讀 Google API** |
| `cache/` | Context Cache 設定、命中紀錄 metadata（非取代 RAG） |
| `rag/` | 私有知識庫 chunk / manifest |

### 4.3 與 registry 的對應（規劃）

| internal tenant_key | 規劃 sno 範例 | 備註 |
|---------------------|---------------|------|
| `travel_a` | `e1fd133c7e8e45a1` | 驗收基線 |
| `travel_b` | （registry 內定義） | 已啟用 hybrid + fixed formatter |
| 新租戶 | 新配發 sno | 自動對應 `tenants/{sno}/` 整棵樹 |

---

## 五、Google Drive 規劃

### 5.1 定位

**Google Drive 是人工協作與查看層，不是正式 AI 讀取層。**

### 5.2 可放置內容

- 人工維護之 **Excel**（尚未進 Parsing 管線前）
- 旅行社 **共編資料夾**（業務、產品、客服）
- **Google Sheets** 之捷徑或說明文件
- **PDF 查看副本**、合約掃描、人工附件

### 5.3 正式機器讀取路徑

```
Drive / Sheets（人工編輯）
    → 同步或匯出觸發
    → Gemini Parsing / Sheet Sync Job
    → GCS tenants/{sno}/latest/*.json
    → Data Source Adapter → BATS / Hybrid / RAG
```

### 5.4 禁止事項（架構層）

- Hybrid Smart Search **查詢當下** 不直接呼叫 Drive API 讀大檔。
- 主機 A **不** 長期快取 Drive 檔案本體於本地磁碟。

---

## 六、Google Sheets 規劃

### 6.1 定位

**Google Sheets 是人工協作層**，供非工程人員維護結構化資料。

### 6.2 功能範圍

| 用途 | 說明 |
|------|------|
| QA 維護 | 旅行社員工直接編輯問答列 |
| 服務項目 | 加購、保險、接送等 |
| 出團日 / 可售 / 已報名 | 庫存與團態（業務真相可與 Host B 對照） |
| LINE OA 報名旅客明細 | 姓名、生日等 **寫入 Sheet**，不寫入 Host B SQL |

### 6.3 同步方向（規劃）

```
Google Sheets（編輯）
    → 排程或事件觸發 Sync Job（主機 A 編排）
    → GCS tenants/{sno}/sheets_json/{sheet_id}/{snapshot_at}.json
    → 可選：解析後寫入 latest/qa 或 latest/packages
    → Host B 僅存 row_id、sync_status、last_snapshot_path（metadata）
```

### 6.4 已知旅行社私有 Google Sheet（僅標記，Phase 1 不連線）

| 項目 | 內容 |
|------|------|
| **URL** | `https://docs.google.com/spreadsheets/d/1al59g7_h_VmeZiL3K0LZpbTf9GdWj5PL92WFSCs1kP8/edit` |
| **分類** | **Tenant private source**（非 `shared/`） |
| **Phase 1** | **不連線、不讀取、不修改**；僅於架構圖標記將來歸屬 `tenants/{sno}/sheets_json/` |
| **綁定方式（Phase 2）** | 由 registry 新增 `google_sheet_sources[]`（含 sheet_id、sno、sync 政策） |

---

## 七、Gemini Parsing 規劃

### 7.1 輸入與輸出

| 輸入類型 | 處理方式 | 輸出位置（規劃） |
|----------|----------|------------------|
| Excel / Google Sheets | 欄位 mapping → BBC 標準 JSON | `parsed/` + `latest/` |
| PDF / Word | 文字抽取 / OCR / 章節摘要 | `parsed/` + `latest/` |
| JPG / PNG | OCR + image metadata | `parsed/` + `latest/` |

### 7.2 Tenant mapping

- 各旅行社 Excel **欄位名、順序、Sheet 結構可不同**。
- 透過 **`tenant mapping config`**（Phase 2 設計）對應至 **BBC 標準 JSON schema**。
- mapping 檔建議路徑（規劃）：`tenants/{sno}/config/field_mapping.json`（或 Host B metadata 表記錄 GCS 路徑）。

### 7.3 多 Sheet 語意

- 單一 Excel  workbook 含多 Sheet 時，**逐 Sheet 解析**。
- Sheet 名稱可承載業務語意，例如 `2026.07.09` 代表 **出發日**。
- 解析結果寫入 `packages/` 或 `parsed/{package_group_id}/sheets/{sheet_name}.json`。

### 7.4 編排（主機 A）

1. 收到「新檔已至 GCS `uploads/`」事件（或 upload session 完成）。
2. 建立 Parsing job（背景佇列）。
3. 呼叫 Gemini（Phase 2+ 才實作；Phase 1 不呼叫）。
4. 寫入 `parsed/{file_hash}.json`，更新 `latest` 指標與 Host B metadata。

---

## 八、BATS 與 Hybrid Smart Search 關係

### 8.1 層級關係

```
┌─────────────────────────────────────────┐
│  LINE / Webhook / SaaSRouter (BATS)      │
├─────────────────────────────────────────┤
│  Hybrid Smart Search (既有核心，不動)     │
│  TourSearchApiClient → Host B API        │
├─────────────────────────────────────────┤
│  BBC 旅業資料中心（本文件規劃層）          │
│  GCS JSON · Sheet Sync · Parsing         │
├─────────────────────────────────────────┤
│  Host B SQL · 正式行程 / 訂單 metadata    │
└─────────────────────────────────────────┘
```

### 8.2 資料供應方式（未來）

**BBC 旅業資料中心** 作為 **BATS / Hybrid** 的 **資料供應層**，透過 **Retrieval Service** 與 **Data Source Adapter** 注入 context，而非改寫 Hybrid 條件解析核心。

### 8.3 規劃中之 Adapter 類型

| Adapter | 來源 | 查詢建議 |
|---------|------|----------|
| `HostBSource` | Host B REST / SQL 對外 API | 行程、可售、正式價格 |
| `TenantGcsJsonSource` | `tenants/{sno}/latest/` | 租戶 QA、packages、services |
| `SharedGcsJsonSource` | `shared/` | 簽證、旅遊注意事項 |
| `GoogleSheetSource` | **僅同步管道** | 排程寫入 `sheets_json/`；**查詢時讀 GCS，不直讀 Sheet API** |

### 8.4 明確不做（Phase 1）

- 不重構 `HybridSearchConditionBuilder`。
- 不修改 `TourPromptContextService` 既有 Host B 路徑。
- 不變更 travel_a / travel_b 已驗收之 formatter 與 gate 行為。

---

## 九、未來 RAG 規劃

### 9.1 素材來源

- **GCS** 保存原始檔與 **parsed JSON** 為 RAG 建索引之唯一素材來源（避免 Drive 直連）。
- 索引目錄分離：
  - `shared/rag/` — 平台共用知識
  - `tenants/{sno}/rag/` — 租戶私有知識

### 9.2 檢索優先權（RAG）

1. `tenants/{sno}/rag/` 命中
2. `shared/rag/` 補充
3. Host B 正式資料（業務事實查核）

### 9.3 Gemini Context Cache

- 定位：**高頻 QA 快取層**（`tenants/{sno}/cache/` metadata）。
- **不取代 RAG**：長尾、多模態、法規類知識仍走 RAG + GCS `latest`。
- Cache 失效與 `latest` 版本、`file_hash` 掛鉤（Phase 2 細化）。

---

## 十、資料讀取優先權

查詢或組裝 AI context 時，建議順序如下：

| 順序 | 來源 | 說明 |
|------|------|------|
| **1** | `gs://bbc-travel-data-center/tenants/{sno}/` | 租戶私有 QA、packages、services、latest JSON |
| **2** | `gs://bbc-travel-data-center/shared/` | 共用簽證、注意事項、common_qa |
| **3** | Host B API / SQL（正式資料） | 依業務情境：即時可售、價格、庫存以 Host B 為準 |
| **4** | 衝突處理 | **私有層覆蓋共用層**；若 GCS 與 Host B 業務事實衝突，**以 Host B 正式資料為準**（價格、庫存），GCS 側為知識與文案補充 |

**注意：** 第 3 點與第 4 點並存——「文案 / FAQ」私有優先；「交易事實」Host B 優先。

---

## 十一、檔案關聯設計

### 11.1 關聯 ID 定義

| ID | 用途 | 範例情境 |
|----|------|----------|
| `package_group_id` | 同一行程系列關聯 Excel、PDF、JPG | 「東京五日」 brochure + 價格表 + 圖片 |
| `upload_session_id` | LINE OA **一次上傳多檔** 的臨時關聯 | 使用者一次傳 3 張報名表 |
| `file_group_id` | 同一邏輯文件之 **版本族** | 2026Q2 價格表 v1、v2、v3 |
| `version` | 單檔遞增版本號 | 整數或 semver |
| `is_latest` | 是否為該 `file_group_id` 下生效版本 | boolean |
| `file_hash` | SHA-256（或類似）內容指紋 | 去重、idempotent 解析 |

### 11.2 建議 metadata 欄位（Host B 規劃，Phase 1 不建表）

```json
{
  "sno": "e1fd133c7e8e45a1",
  "package_group_id": "pg_2026_tokyo_5d",
  "file_group_id": "fg_price_sheet",
  "upload_session_id": "us_line_20260529_abc123",
  "gcs_original_path": "tenants/{sno}/original/{file_hash}.xlsx",
  "gcs_latest_path": "tenants/{sno}/latest/packages/pg_2026_tokyo_5d.json",
  "version": 3,
  "is_latest": true,
  "file_hash": "sha256:…",
  "sync_status": "parsed_ok",
  "source": "line_upload | drive_sync | sheet_sync"
}
```

### 11.3 生命週期（規劃）

```
upload_session_id 建立
  → 多檔進 uploads/
  → 各檔分配 file_group_id / file_hash
  → Parsing 完成 → parsed/
  → 提升 is_latest → latest/
  → Host B 寫入 metadata（無大檔）
  → upload_session 結案歸檔
```

---

## 十二、主機 A / 主機 B 分工

### 12.1 主機 A（`103.1.222.14` · `C:\bbc-ai-bot`）

| 職責 | 說明 |
|------|------|
| 登入驗證 | 後台、內部 API |
| sno 權限判斷 | 依 `tenant_registry`，不信任 client 傳入 |
| Signed URL 產生 | 直傳 GCS `uploads/` |
| upload session | 多檔關聯、`upload_session_id` |
| metadata 協調 | 與 Host B 同步狀態，不持大檔 |
| 背景任務 | Sheet sync、Parsing queue |
| Gemini Parsing orchestration | 觸發、重試、寫回 GCS（Phase 2+） |

**不負責：** 長期保存檔案本體、SQL 正式訂單寫入（除 metadata API）。

### 12.2 主機 B（SQL Server · 正式業務）

| 儲存 | 不儲存 |
|------|--------|
| 正式行程、可售、訂單業務欄位 | 檔案二進位、OCR 全文 |
| 檔案 metadata（路徑、hash、version） | 旅客姓名、出生年月日 |
| package metadata | 大型 JSON blob（改存 GCS 路徑參照） |
| Google Sheet row / reference ID | — |
| sync_status、last_parsed_at | — |

### 12.3 資料流總覽

```
使用者 / 員工
  ├─ LINE 上傳 → Signed URL → GCS uploads/     [主機 A 簽發]
  ├─ Drive / Sheets 編輯                       [人工層]
  └─ 查詢行程                                   [Hybrid → Host B]

主機 A 背景
  └─ Parsing / Sheet Sync → GCS parsed & latest

主機 B
  └─ metadata + 正式業務 API ← Hybrid / 未來 Adapter
```

---

## 十三、Phase 1 不實作清單

本階段 **明確不做** 以下事項：

| # | 項目 |
|---|------|
| 1 | 不建立 GCS bucket `bbc-travel-data-center` |
| 2 | 不串接 Google API（Drive / Sheets / Storage） |
| 3 | 不修改任何 PHP / JS / .NET 程式 |
| 4 | 不新增 SQL table / 不執行 SQL |
| 5 | 不修改 `.env` |
| 6 | 不接 LINE webhook 上傳流程 |
| 7 | 不修改 Hybrid Smart Search 核心與現有 gate |
| 8 | 不讀取、不修改已知私有 Google Sheet（見第六章） |
| 9 | 不部署 Gemini Parsing job |
| 10 | 不建立 RAG 索引 |

**Phase 1 唯一交付物：** 本規劃文件。

---

## 十四、Phase 2 建議

| 順序 | 工作項 | 說明 |
|------|--------|------|
| 1 | **標準 JSON schema 設計** | `packages`、`qa`、`services` 欄位契約 |
| 2 | **tenant mapping config** | Excel 欄位 → schema 對照檔格式與存放位置 |
| 3 | **GCS path naming convention** | `latest` 指標檔、版本目錄、lifecycle 規則 |
| 4 | **Google Sheet sync flow** | 排程頻率、增量、錯誤重試、`sheets_json/` 快照命名 |
| 5 | **Excel parser dry-run plan** | 離線樣本、不呼叫正式 API 的驗證腳本規劃 |
| 6 | **package_group metadata plan** | Host B 表結構或 API 契約（僅 metadata） |
| 7 | **Data Source Adapter 介面草案** | `HostBSource`、`TenantGcsJsonSource` 等介面與注入點 |
| 8 | **Signed URL + upload_session API 規格** | 主機 A 端點設計（仍不實作則可僅文件） |
| 9 | **registry 擴充** | `google_sheet_sources`、`gcs_prefix` 等規劃欄位 |
| 10 | **資安與個資** | Sheet 含 PII 之存取稽核、保留週期 |

---

## 附錄 A：名詞對照

| 名詞 | 說明 |
|------|------|
| **BBC AI SaaS** | 主機 A `bbc-ai-bot` 所承載之 LINE 旅遊 AI 服務 |
| **BATS** | 既有 Bot / AI 旅遊服務執行管線（含 SaaSRouter、formatter、prompt） |
| **sno** | 租戶在 Host B 之權威識別碼，對應 GCS `tenants/{sno}/` |
| **Hybrid Smart Search** | 自然語言條件 + Host B 行程搜尋（已於 travel_a / travel_b 驗收） |

## 附錄 B：文件修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 0.1 | 2026-05-29 | Phase 1 初版規劃（僅文件） |

---

*本文件為架構規劃，不構成實作承諾。實作前須另開 Phase 2 實作計畫並通過變更審查。*
