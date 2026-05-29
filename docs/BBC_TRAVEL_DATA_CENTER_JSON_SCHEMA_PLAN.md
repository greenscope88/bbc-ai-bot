# BBC 旅業資料中心 — Phase 3 JSON Schema 規劃

**副標：** 旅遊業 AI 資料處理與雲端同步平台（JSON Schema Plan）

**文件代號：** `BBC_TRAVEL_DATA_CENTER_JSON_SCHEMA_PLAN`

**版本：** Phase 3（規劃 only）

**狀態：** Planning — 本文件不觸發任何程式、雲端資源或資料庫變更

**規劃主機：** 主機 A `103.1.222.14` · 專案路徑 `C:\bbc-ai-bot`

**關聯文件：**

- [BBC_TRAVEL_DATA_CENTER_GCS_DRIVE_ARCHITECTURE_PLAN.md](./BBC_TRAVEL_DATA_CENTER_GCS_DRIVE_ARCHITECTURE_PLAN.md)（Phase 1）
- [BBC_TRAVEL_DATA_CENTER_DATA_CLASSIFICATION_PLAN.md](./BBC_TRAVEL_DATA_CENTER_DATA_CLASSIFICATION_PLAN.md)（Phase 2）

**規劃日期：** 2026-05-29

**Schema 版本（規劃）：** `bbc_travel_data_center.v1`

---

## 一、文件目的

本文件定義 **BBC 旅業資料中心（BBC Travel Data Center）** 之 **統一 JSON Schema**，作為下列系統與流程的 **共同資料格式（Contract Layer）**：

| 消費者 / 來源 | 角色 |
|---------------|------|
| **GCS** | 正式儲存 `latest/`、`parsed/`、`sheets_json/` |
| **Google Sheets** | 人工維護 → 同步為 JSON 快照 |
| **Gemini Parsing** | 非結構化檔 → 標準 JSON |
| **BATS** | LINE / SaaSRouter 回覆編排讀取 context |
| **Hybrid Smart Search** | 行程檢索與條件解析之補充資料 |
| **Future RAG** | 向量索引素材與 manifest |

**設計原則：**

1. **JSON 為機器讀取契約**；Drive / Sheets / 原始檔為輸入層。  
2. **所有對外服務預設讀 `latest/`**；`parsed/` 供除錯與重跑。  
3. **個資不進 Host B**；旅客名單僅存 Google Sheet + GCS 快照（若同步）不含可識別個資欄位寫入 SQL。  
4. **Phase 3 僅文件**；不部署 schema validator、不改程式。

---

## 二、標準命名規則

### 2.1 關聯 ID

| 欄位 | 格式（規劃） | 說明 | 範例 |
|------|--------------|------|------|
| **`package_group_id`** | `pg_{tenant_sno_short}_{yyyyMMdd}_{seq}` 或 UUID v4 | 單一行程系列；關聯圖檔、Excel、PDF/Word | `pg_e1fd_20260709_001` |
| **`file_group_id`** | `fg_{package_group_id}_{type}_{seq}` | 同一邏輯檔案版本族 | `fg_pg_e1fd_20260709_001_excel_01` |
| **`upload_session_id`** | `us_{yyyyMMddHHmmss}_{random8}` | 一次上傳操作（可多檔） | `us_202605291200_a1b2c3d4` |
| **`version`** | 正整數，自 1 遞增 | 同 `file_group_id` 內版本 | `1`, `2`, `3` |
| **`is_latest`** | boolean | 該 `file_group_id` 是否生效 | `true` / `false` |
| **`file_hash`** | `sha256:{hex}` | 內容指紋；去重、冪等 Parsing | `sha256:abc123…` |
| **`tenant_sno`** | 16 字元 hex（與 Host B 一致） | 租戶權威識別；對應 `tenants/{sno}/` | `e1fd133c7e8e45a1` |

### 2.2 通用欄位

| 欄位 | 類型 | 說明 |
|------|------|------|
| `schema_version` | string | 本文件 schema 版本，如 `bbc_travel_data_center.v1` |
| `created_at` | string (ISO 8601) | 建立時間 UTC |
| `updated_at` | string (ISO 8601) | 最後更新 UTC |
| `source` | enum | `line_upload` \| `add_tour_web` \| `sheet_sync` \| `drive_sync` \| `admin` |
| `locale` | string | 預設 `zh-TW` |

### 2.3 GCS 檔名對照（規劃）

| 邏輯檔 | GCS 路徑（latest） |
|--------|-------------------|
| 行程彙總 | `tenants/{tenant_sno}/latest/packages/{package_group_id}/package.json` |
| 出團日列表 | `tenants/{tenant_sno}/latest/packages/{package_group_id}/departure_dates.json` |
| 服務項目 | `tenants/{tenant_sno}/latest/services.json` |
| QA | `tenants/{tenant_sno}/latest/qa.json` |
| Sheet metadata | Host B 表 + 可選 `tenants/{tenant_sno}/sheets_json/{sheet_id}/metadata.json` |

---

## 三、行程資料 JSON — `package.json`

**路徑：** `tenants/{tenant_sno}/latest/packages/{package_group_id}/package.json`

**用途：** 單一行程之 **對外展示與 AI context 彙總**；出團明細可拆至 `departure_dates.json` 或以嵌入欄位引用。

### 3.1 Schema（規劃）

```json
{
  "$schema": "bbc_travel_data_center.v1/package",
  "schema_version": "bbc_travel_data_center.v1",
  "package_group_id": "pg_e1fd_20260709_001",
  "tenant_sno": "e1fd133c7e8e45a1",

  "title": "東京五日遊",
  "destination": {
    "country": "JP",
    "country_name": "日本",
    "cities": ["東京"],
    "region_tags": ["關東"]
  },

  "images": [
    {
      "file_group_id": "fg_pg_e1fd_20260709_001_img_01",
      "file_hash": "sha256:…",
      "version": 1,
      "is_latest": true,
      "mime_type": "image/jpeg",
      "gcs_path": "tenants/e1fd133c7e8e45a1/packages/pg_e1fd_20260709_001/original/images/sha256….jpg",
      "public_url": "https://…",
      "alt_text": "東京晴空塔",
      "sort_order": 1
    }
  ],

  "brochure": {
    "file_group_id": "fg_pg_e1fd_20260709_001_pdf_01",
    "file_hash": "sha256:…",
    "version": 1,
    "is_latest": true,
    "mime_type": "application/pdf",
    "gcs_path": "tenants/…/brochure/sha256….pdf",
    "public_url": "https://…",
    "summary": "五日行程含迪士尼、晴空塔…"
  },

  "departure_dates_ref": "departure_dates.json",
  "departure_summary": {
    "next_departure_date": "2026-07-09",
    "min_retail_price": 45900,
    "total_available_seats": 24
  },

  "pricing_display": {
    "currency": "TWD",
    "price_from": 45900,
    "price_note": "含稅，不含小費"
  },

  "inventory_summary": {
    "total_seats": 32,
    "booked_count": 8,
    "available_count": 24
  },

  "status": "active",
  "source": "add_tour_web",
  "upload_session_id": "us_202605291200_a1b2c3d4",
  "created_at": "2026-05-29T04:00:00Z",
  "updated_at": "2026-05-29T04:30:00Z"
}
```

### 3.2 欄位說明

| 欄位 | 必填 | 說明 |
|------|------|------|
| `title` | 是 | 行程名稱 |
| `destination` | 是 | 目的地（國家、城市、標籤） |
| `images[]` | 否 | 圖片清單；含 `public_url` |
| `brochure` | 否 | PDF / Word 轉 PDF；含 `public_url` |
| `departure_dates_ref` | 建議 | 指向同目錄 `departure_dates.json` |
| `departure_summary` | 否 | 列表頁快取（最近出團、起價） |
| `inventory_summary` | 否 | 可售 / 已報名彙總（與 Host B 對照時以 Host B 為準） |

---

## 四、出團日期 JSON — `departure_dates.json`

**路徑：** `tenants/{tenant_sno}/latest/packages/{package_group_id}/departure_dates.json`

**用途：** Excel / Sheet 解析後之 **結構化出團日**；Hybrid 與商品頁可讀。

### 4.1 Schema（規劃）

```json
{
  "$schema": "bbc_travel_data_center.v1/departure_dates",
  "schema_version": "bbc_travel_data_center.v1",
  "package_group_id": "pg_e1fd_20260709_001",
  "tenant_sno": "e1fd133c7e8e45a1",

  "departures": [
    {
      "departure_id": "dep_20260709_001",
      "departure_date": "2026-07-09",
      "departure_date_display": "2026.07.09",
      "retail_price": 45900,
      "trade_rebate": 1200,
      "currency": "TWD",
      "total_seats": 32,
      "min_group_size": 10,
      "booked_count": 8,
      "available_count": 24,
      "group_status": "open",
      "source_sheet_name": "2026.07.09",
      "source_file_hash": "sha256:…",
      "host_b_package_id": null,
      "updated_at": "2026-05-29T04:30:00Z"
    }
  ],

  "parsed_from": {
    "file_group_id": "fg_pg_e1fd_20260709_001_excel_01",
    "file_hash": "sha256:…",
    "parsed_at": "2026-05-29T04:25:00Z"
  },

  "updated_at": "2026-05-29T04:30:00Z"
}
```

### 4.2 欄位對照（Excel → JSON）

| Excel 欄位（規劃） | JSON 欄位 |
|--------------------|-----------|
| 出團日期 | `departure_date` |
| 直售價 | `retail_price` |
| 同業後退 | `trade_rebate` |
| 總位數 | `total_seats` |
| 成團人數 | `min_group_size` |
| 已報名人數 | `booked_count` |
| （衍生） | `available_count` = `total_seats` - `booked_count` |

---

## 五、服務項目 JSON — `services.json`

**路徑：** `tenants/{tenant_sno}/latest/services.json`

**分類：** Tenant Private Knowledge（Phase 2）

### 5.1 Schema（規劃）

```json
{
  "$schema": "bbc_travel_data_center.v1/services",
  "schema_version": "bbc_travel_data_center.v1",
  "tenant_sno": "e1fd133c7e8e45a1",

  "services": [
    {
      "service_id": "svc_airport_transfer",
      "name": "機場接送",
      "category": "transport",
      "description": "單程桃園機場接送",
      "price": {
        "amount": 1200,
        "currency": "TWD",
        "unit": "per_person"
      },
      "applicable_packages": ["pg_e1fd_20260709_001"],
      "is_active": true,
      "sort_order": 10,
      "source": "sheet_sync",
      "updated_at": "2026-05-29T03:00:00Z"
    }
  ],

  "updated_at": "2026-05-29T03:00:00Z"
}
```

---

## 六、QA JSON — `qa.json`

**路徑：** `tenants/{tenant_sno}/latest/qa.json`（租戶）或 `shared/latest/common_qa.json`（共用）

### 6.1 租戶 QA Schema（規劃）

```json
{
  "$schema": "bbc_travel_data_center.v1/qa",
  "schema_version": "bbc_travel_data_center.v1",
  "tenant_sno": "e1fd133c7e8e45a1",
  "scope": "tenant",

  "items": [
    {
      "qa_id": "qa_cancel_policy",
      "question": "取消行程如何退費？",
      "answer": "出發前 14 天可全額退費…",
      "tags": ["退改", "政策"],
      "priority": 100,
      "is_active": true,
      "source": "sheet_sync",
      "source_row": 12,
      "updated_at": "2026-05-29T02:00:00Z"
    }
  ],

  "updated_at": "2026-05-29T02:00:00Z"
}
```

### 6.2 共用 QA

- `scope`: `shared`
- 路徑：`shared/latest/common_qa.json`
- 結構與租戶 QA 相同，無 `tenant_sno` 或 `tenant_sno: null`

---

## 七、Google Sheet Metadata JSON

**重點：** 主機 B **只存 metadata 與 reference**，**不存旅客個資**。

### 7.1 Host B 可存欄位（規劃）

```json
{
  "$schema": "bbc_travel_data_center.v1/sheet_metadata",
  "schema_version": "bbc_travel_data_center.v1",
  "tenant_sno": "e1fd133c7e8e45a1",
  "package_group_id": "pg_e1fd_20260709_001",

  "sheet_id": "1al59g7_h_VmeZiL3K0LZpbTf9GdWj5PL92WFSCs1kP8",
  "sheet_url": "https://docs.google.com/spreadsheets/d/1al59g7_h_VmeZiL3K0LZpbTf9GdWj5PL92WFSCs1kP8/edit",
  "sheet_name": "2026-07-05 東京五日遊 旅客名單",
  "sheet_type": "passenger_list",

  "row_start": 2,
  "row_end": null,
  "header_row": 1,

  "sync_status": "pending",
  "last_sync_at": null,
  "last_sync_error": null,
  "gcs_snapshot_path": "tenants/e1fd133c7e8e45a1/sheets_json/1al59g7…/snapshot_20260529T040000Z.json",

  "created_at": "2026-05-29T04:00:00Z",
  "updated_at": "2026-05-29T04:00:00Z"
}
```

### 7.2 `sync_status` 枚舉

| 值 | 說明 |
|----|------|
| `pending` | 待同步 |
| `syncing` | 同步中 |
| `synced` | 成功 |
| `failed` | 失敗（見 `last_sync_error`） |
| `disabled` | 停用 |

### 7.3 GCS 快照（`sheets_json/`）

- 同步 Job 可將 **非個資欄位** 或 **脫敏統計** 寫入 GCS。
- **禁止** 在 GCS 快照中明文存放旅客姓名、身分證、生日、手機（見第八章）；若業務必須保留，僅限 Google Sheet 本體 + 嚴格 ACL（實作 Phase 4）。

### 7.4 已知私有 Sheet（僅標記）

| 項目 | 內容 |
|------|------|
| URL | `https://docs.google.com/spreadsheets/d/1al59g7_h_VmeZiL3K0LZpbTf9GdWj5PL92WFSCs1kP8/edit` |
| 分類 | Tenant private source |
| Phase 3 | 不連線、不讀取、不修改 |

---

## 八、旅客名單設計

### 8.1 個資邊界（強制）

| 欄位 | Google Sheet | Host B SQL | GCS `sheets_json` 快照 |
|------|--------------|------------|-------------------------|
| 旅客姓名 | ✅ 允許 | ❌ **禁止** | ❌ **禁止明文** |
| 身分證 | ✅ 允許 | ❌ **禁止** | ❌ **禁止** |
| 出生年月日 | ✅ 允許 | ❌ **禁止** | ❌ **禁止** |
| 手機 | ✅ 允許 | ❌ **禁止** | ❌ **禁止** |
| 護照號碼 | ✅ 允許 | ❌ **禁止** | ❌ **禁止** |

**原則：** 旅客名單 **只存 Google Sheet**；主機 B 僅存 **第七章 metadata**；BATS / Hybrid **不讀** 旅客個資欄位。

### 8.2 Google Sheet 命名規則

**格式：**

```
{YYYY-MM-DD} {行程名稱} 旅客名單
```

**範例：**

```
2026-07-05 東京五日遊 旅客名單
2026-08-12 塞班自由行 旅客名單
```

**規則：**

| 規則 | 說明 |
|------|------|
| 日期 | ISO `YYYY-MM-DD`，對應 `departure_date` |
| 行程名稱 | 與 `package.json` 之 `title` 一致或簡稱 |
| 後綴 | 固定 `旅客名單` |
| 同 package 多團 | 一團一 Sheet（或一 Sheet 多 tab，tab 名 = 日期） |

### 8.3 Sheet 欄位（僅存在 Google Sheet，不進 Host B）

| 欄位（規劃表頭） | 說明 |
|------------------|------|
| 序號 | 報名順序 |
| 旅客姓名 | 個資 |
| 身分證 | 個資 |
| 出生年月日 | 個資 |
| 手機 | 個資 |
| 護照號碼 | 選填 |
| 備註 | 客服備註 |
| 報名時間 | LINE 寫入時間 |
| 報名來源 | 如 `line_oa` |

### 8.4 自動建立流程（規劃）

**觸發：** LINE OA 報名成功 / 後台「建立名單」/ 出團日建立時。

```
1. 解析 package_group_id + departure_date
2. 查 Host B sheet_metadata：是否已有 (tenant_sno, package_group_id, departure_date) 對應 sheet_id
3. 若無：
   a. 主機 A 以 Service Account 呼叫 Google Sheets API（Phase 4 實作）
   b. 建立 Spreadsheet 或使用既有 Spreadsheet 新增工作表
   c. sheet_name = "{YYYY-MM-DD} {title} 旅客名單"
   d. 寫入表頭列（第八章 8.3）
   e. 寫入 Host B metadata（sheet_id, sheet_url, row_start=2, sync_status=pending）
4. 若有：追加列至 row_end+1（僅寫 Sheet，不寫 Host B 個資欄位）
5. 回傳 sheet_url 給客服 / LINE（選用）
```

**Phase 3：** 僅文件；不呼叫 Google API。

### 8.5 LINE 報名寫入（規劃）

```
LINE 使用者報名
    → Webhook 解析（不含長期存個資於 Host B）
    → 主機 A 編排
    → Google Sheets API 追加一列
    → 更新 sheet_metadata.row_end、sync_status
```

---

## 九、Gemini Parsing JSON

**路徑：** `tenants/{tenant_sno}/packages/{package_group_id}/parsed/{type}/{file_hash}.json`

### 9.1 共通外層

```json
{
  "$schema": "bbc_travel_data_center.v1/parsed",
  "schema_version": "bbc_travel_data_center.v1",
  "package_group_id": "pg_e1fd_20260709_001",
  "tenant_sno": "e1fd133c7e8e45a1",
  "file_group_id": "fg_pg_e1fd_20260709_001_excel_01",
  "file_hash": "sha256:…",
  "version": 1,
  "is_latest": true,
  "mime_type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  "parser": "gemini",
  "parser_version": "1.0.0",
  "parsed_at": "2026-05-29T04:25:00Z",
  "status": "success",
  "errors": []
}
```

### 9.2 Excel 解析結果 — `parsed/excel/{file_hash}.json`

```json
{
  "...共通外層...",
  "content_type": "excel",
  "sheets": [
    {
      "sheet_name": "2026.07.09",
      "inferred_departure_date": "2026-07-09",
      "rows": [
        {
          "retail_price": 45900,
          "trade_rebate": 1200,
          "total_seats": 32,
          "min_group_size": 10,
          "booked_count": 8
        }
      ],
      "mapping_profile_id": "tenant_e1fd_default_v1"
    }
  ],
  "normalized_ref": "departure_dates.json"
}
```

### 9.3 PDF 解析結果 — `parsed/brochure/{file_hash}.json`

```json
{
  "...共通外層...",
  "content_type": "pdf",
  "page_count": 12,
  "full_text": "…",
  "summary": "五日行程：D1 抵達東京…",
  "sections": [
    { "title": "每日安排", "content": "…" }
  ],
  "ocr_applied": false
}
```

### 9.4 Word 解析結果 — `parsed/brochure/{file_hash}.json`

- 與 PDF 相同結構；`content_type`: `word`
- 建議實作時先轉 PDF 再解析

### 9.5 圖片解析結果 — `parsed/images/{file_hash}.json`

```json
{
  "...共通外層...",
  "content_type": "image",
  "mime_type": "image/jpeg",
  "width": 1920,
  "height": 1080,
  "ocr_text": "東京迪士尼樂園",
  "labels": ["東京", "迪士尼", "親子"],
  "alt_text_suggestion": "東京迪士尼樂園入口"
}
```

### 9.6 與 `latest` 的關係

| 階段 | 說明 |
|------|------|
| `parsed/*` | 單檔完整輸出，可含 `full_text` |
| `latest/packages/.../package.json` | 對外精簡版；`brochure.summary` 取自 parsed |
| `latest/.../departure_dates.json` | 由 Excel parsed `sheets[]` 正規化 |

---

## 十、Hybrid Smart Search Source JSON

**用途：** Data Source Adapter 讀取時之 **請求 / 回應契約**（規劃）；不重構 Hybrid 核心。

### 10.1 共通請求外層

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "tenant_sno": "e1fd133c7e8e45a1",
  "query_text": "六月底東京團",
  "reference_date": "2026-05-29",
  "sources_requested": ["host_b", "tenant_private", "shared"]
}
```

### 10.2 HostBSource

```json
{
  "source": "host_b",
  "status": "ok",
  "items": [
    {
      "package_id": "host_b_pkg_12345",
      "title": "東京五日",
      "departure_date": "2026-06-28",
      "retail_price": 45900,
      "available_seats": 12,
      "detail_url": "https://…"
    }
  ],
  "authority": "business_truth"
}
```

- **權威：** 價格、庫存、可售之 **業務事實** 以 Host B 為準。

### 10.3 TenantPrivateSource

```json
{
  "source": "tenant_private",
  "status": "ok",
  "package_context": [
    {
      "package_group_id": "pg_e1fd_20260709_001",
      "title": "東京五日遊",
      "public_image_url": "https://…",
      "brochure_public_url": "https://…",
      "departure_dates_ref": "departure_dates.json"
    }
  ],
  "qa_hits": [
    { "qa_id": "qa_cancel_policy", "question": "…", "answer": "…" }
  ],
  "authority": "tenant_knowledge"
}
```

### 10.4 SharedSource

```json
{
  "source": "shared",
  "status": "ok",
  "visa": [{ "country": "JP", "summary": "…" }],
  "travel_notice": [{ "topic": "禁帶物品", "summary": "…" }],
  "qa_hits": [],
  "authority": "platform_knowledge"
}
```

### 10.5 FutureRagSource

```json
{
  "source": "future_rag",
  "status": "not_implemented",
  "tenant_chunks": [],
  "shared_chunks": [],
  "authority": "retrieval"
}
```

### 10.6 合併回應（Retrieval Service 輸出）

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "tenant_sno": "e1fd133c7e8e45a1",
  "merged_context": {
    "packages": [],
    "qa": [],
    "visa": [],
    "host_b_items": []
  },
  "precedence_applied": ["tenant_private", "shared", "host_b"],
  "conflicts_resolved": []
}
```

---

## 十一、Future RAG JSON

### 11.1 共通 Manifest — `rag_manifest.json`

**路徑：**

- `shared/rag/manifest.json`
- `tenants/{tenant_sno}/rag/manifest.json`

```json
{
  "$schema": "bbc_travel_data_center.v1/rag_manifest",
  "schema_version": "bbc_travel_data_center.v1",
  "scope": "tenant",
  "tenant_sno": "e1fd133c7e8e45a1",

  "index_version": "20260529_001",
  "embedding_model": "text-embedding-004",
  "chunk_count": 1280,
  "source_gcs_prefix": "tenants/e1fd133c7e8e45a1/latest/",
  "built_at": "2026-05-29T06:00:00Z",
  "status": "ready"
}
```

### 11.2 Chunk 條目 — `rag/chunks/{chunk_id}.json`

```json
{
  "chunk_id": "chk_qa_cancel_policy_001",
  "scope": "tenant",
  "tenant_sno": "e1fd133c7e8e45a1",
  "source_type": "qa",
  "source_id": "qa_cancel_policy",
  "text": "取消行程如何退費？出發前 14 天…",
  "metadata": {
    "tags": ["退改"],
    "package_group_id": null
  },
  "embedding_ref": "vector_index/chk_qa_cancel_policy_001"
}
```

### 11.3 Shared RAG vs Tenant RAG

| 項目 | Shared RAG | Tenant RAG |
|------|------------|------------|
| 路徑 | `shared/rag/` | `tenants/{sno}/rag/` |
| 素材 | `shared/latest/*` | `tenants/{sno}/latest/*` |
| 檢索順序 | 第二優先 | **第一優先** |

### 11.4 Context Cache（不取代 RAG）

```json
{
  "$schema": "bbc_travel_data_center.v1/context_cache",
  "tenant_sno": "e1fd133c7e8e45a1",
  "cache_key": "qa_high_freq_v1",
  "gemini_cache_id": "cachedContents/…",
  "source_qa_ids": ["qa_cancel_policy"],
  "expires_at": "2026-06-29T00:00:00Z",
  "invalidated_by_file_hash": []
}
```

---

## 十二、資料讀取優先權 JSON

**用途：** Retrieval / BATS 合併 context 時之 **機器可讀優先權設定**。

**建議路徑：** `config/data_precedence.json`（Phase 4 實作；Phase 3 僅定義 schema）

```json
{
  "$schema": "bbc_travel_data_center.v1/data_precedence",
  "schema_version": "bbc_travel_data_center.v1",

  "read_order": [
    { "layer": "tenant", "gcs_prefix": "tenants/{tenant_sno}/latest/" },
    { "layer": "shared", "gcs_prefix": "shared/latest/" },
    { "layer": "host_b", "api": "gateway.host_b" }
  ],

  "conflict_rules": [
    {
      "field_domain": "faq_copy",
      "winner": "tenant",
      "description": "文案與 FAQ：租戶私有優先於共用"
    },
    {
      "field_domain": "pricing_inventory",
      "winner": "host_b",
      "description": "價格、庫存、可售：Host B 正式資料優先"
    },
    {
      "field_domain": "package_media_url",
      "winner": "tenant",
      "description": "圖片、PDF 公開 URL：以 GCS latest 為準"
    }
  ],

  "pii_policy": {
    "read_passenger_pii": false,
    "allowed_pii_store": ["google_sheet_only"]
  }
}
```

| 順序 | 層級 | 代號 |
|------|------|------|
| 1 | Tenant Private | `tenant` |
| 2 | Shared | `shared` |
| 3 | Host B | `host_b` |

---

## 十三、主機 B Metadata 設計

### 13.1 允許儲存（僅 metadata）

| 類別 | 欄位範例 |
|------|----------|
| 租戶 / 行程 | `tenant_sno`, `package_group_id`, `host_b_package_id` |
| 檔案 metadata | `file_hash`, `file_group_id`, `version`, `is_latest`, `gcs_path` |
| Sheet reference | `sheet_id`, `sheet_url`, `sheet_name`, `row_start`, `row_end` |
| 同步 | `sync_status`, `last_sync_at`, `last_sync_error` |
| 時間 | `created_at`, `updated_at` |

### 13.2 禁止儲存（明確清單）

主機 B SQL **不得** 出現以下欄位之 **明文或可逆加密值**：

| # | 禁止欄位 |
|---|----------|
| 1 | 旅客姓名 |
| 2 | 出生年月日 |
| 3 | 身分證字號 |
| 4 | 護照號碼 |
| 5 | 手機號碼 |

**亦禁止：** 檔案二進位、OCR 全文、大型 JSON blob（改存 GCS 路徑指標）。

### 13.3 規劃表結構（概念，Phase 3 不建表）

**`tdc_package_metadata`**

| 欄位 | 類型 | 說明 |
|------|------|------|
| id | bigint PK | |
| tenant_sno | varchar(32) | |
| package_group_id | varchar(64) | |
| gcs_package_json_path | varchar(512) | |
| sync_status | varchar(32) | |
| updated_at | datetime | |

**`tdc_sheet_metadata`**

| 欄位 | 類型 | 說明 |
|------|------|------|
| id | bigint PK | |
| tenant_sno | varchar(32) | |
| package_group_id | varchar(64) | |
| sheet_id | varchar(128) | |
| sheet_url | varchar(512) | |
| sheet_name | varchar(256) | |
| row_start | int | |
| row_end | int | |
| sync_status | varchar(32) | |
| gcs_snapshot_path | varchar(512) | |

**無** `passenger_name`、`id_number`、`birth_date`、`phone` 等欄位。

---

## 十四、Phase 4 建議

| 順序 | 工作項 | 說明 |
|------|--------|------|
| 1 | **Upload Flow Design** | `addTour_v2`、Signed URL、三類檔直傳 GCS；寫入 `package.json` |
| 2 | **Google Sheet Sync Flow** | metadata 寫 Host B；快照至 `sheets_json/`；旅客個資僅留 Sheet |
| 3 | **Package Metadata Flow** | Host B `tdc_*` 表與 GCS `latest` 雙向同步狀態機 |
| 4 | JSON Schema Validator | CI 驗證 `bbc_travel_data_center.v1` |
| 5 | Tenant Mapping 實作 | Excel 欄位 → `departure_dates.json` |
| 6 | Data Source Adapter | `HostBSource`、`TenantPrivateSource` 等實作 |
| 7 | 旅客名單 Sheet 自動建立 | Google API + 命名規則實作 |
| 8 | 公開 URL 服務 | PDF / 圖片與 LINE、商品頁整合 |

---

## 附錄 A：Schema 檔案清單

| 檔案 | 路徑（latest） |
|------|----------------|
| `package.json` | `tenants/{sno}/latest/packages/{package_group_id}/` |
| `departure_dates.json` | 同上 |
| `services.json` | `tenants/{sno}/latest/` |
| `qa.json` | `tenants/{sno}/latest/` 或 `shared/latest/common_qa.json` |
| `sheet_metadata` | Host B + 可選 GCS |
| `parsed/*.json` | `tenants/{sno}/packages/{package_group_id}/parsed/` |
| `rag_manifest.json` | `tenants/{sno}/rag/` 或 `shared/rag/` |
| `data_precedence.json` | 設定層（Phase 4） |

## 附錄 B：文件修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-29 | Phase 3 初版：JSON Schema Plan（一至十四章） |

---

*本文件為架構規劃，不構成實作承諾。實作前須另開 Phase 4 實作計畫並通過變更審查。*
