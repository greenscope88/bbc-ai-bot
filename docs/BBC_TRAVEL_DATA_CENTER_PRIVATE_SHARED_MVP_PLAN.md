# BBC 旅業資料中心 — Phase 4 私有 / 共用資料 MVP 實作規劃

**副標：** 旅行社私有資料 / 共用資料 MVP 實作前規劃（Private & Shared Knowledge MVP）

**文件代號：** `BBC_TRAVEL_DATA_CENTER_PRIVATE_SHARED_MVP_PLAN`

**版本：** Phase 4（規劃 only · 實作前）

**狀態：** Planning — 本文件不觸發程式、雲端資源、資料庫或 Git 變更

**規劃主機：** 主機 A `103.1.222.14` · 專案路徑 `C:\bbc-ai-bot`

**關聯文件：**

- [BBC_TRAVEL_DATA_CENTER_GCS_DRIVE_ARCHITECTURE_PLAN.md](./BBC_TRAVEL_DATA_CENTER_GCS_DRIVE_ARCHITECTURE_PLAN.md)（Phase 1）
- [BBC_TRAVEL_DATA_CENTER_DATA_CLASSIFICATION_PLAN.md](./BBC_TRAVEL_DATA_CENTER_DATA_CLASSIFICATION_PLAN.md)（Phase 2）
- [BBC_TRAVEL_DATA_CENTER_JSON_SCHEMA_PLAN.md](./BBC_TRAVEL_DATA_CENTER_JSON_SCHEMA_PLAN.md)（Phase 3）

**規劃日期：** 2026-05-29

**Schema 版本（沿用）：** `bbc_travel_data_center.v1`

---

## 一、文件目的

本文件為 **BBC 旅業資料中心（BBC Travel Data Center）** 之 **Phase 4：旅行社私有資料 / 共用資料 MVP 實作前規劃**。

**目標：** 在進入程式實作前，定義 **租戶私有層（`tenants/{sno}/`）** 與 **平台共用層（`shared/`）** 之最小可行範圍、GCS 路徑、JSON 契約、讀取優先權、主機分工與 MVP 驗收路徑。

**本階段明確排除：**

| 排除項 | 說明 |
|--------|------|
| 行程固定網址上傳 | 不處理 `addTour.php` / `addTour_v2` |
| 行程三件套 | 不處理 JPG / PDF / Excel 之 `package_group_id` 上傳流 |
| 本文件性質 | **僅規劃**；Phase 4 文件交付後才進入 Phase 5+ 程式實作 |

---

## 二、實作範圍

### 2.1 本階段只規劃

| 範圍 | 內容 |
|------|------|
| **租戶私有** | `tenants/{sno}/` 下 QA、服務項目、公司介紹、規則、Sheet 同步 JSON |
| **平台共用** | `shared/` 下 QA、簽證、護照須知、旅遊公告 |
| **同步概念** | Google Sheets / Drive → 標準 JSON → GCS `latest/` |
| **消費端行為** | LINE OA / Gemini：**私有層優先、共用層補充** |
| **銜接** | 與 BATS、Hybrid 之 Data Source 邊界（不重構核心） |

### 2.2 本階段不處理

| # | 不處理項目 |
|---|------------|
| 1 | 行程 `package_group` 上傳與 `packages/` 目錄實作 |
| 2 | JPG / PDF / Excel 三檔行程上傳 |
| 3 | `addTour.php` 改版 |
| 4 | RAG 向量索引實作 |
| 5 | SQL schema 變更（僅文件描述 Host B metadata 邊界） |
| 6 | 正式 GCS bucket 建立 |
| 7 | 正式 Google API 串接 |

---

## 三、GCS 路徑規劃

**Bucket（規劃名稱，本階段不建立）：** `gs://bbc-travel-data-center/`

### 3.1 共用層 `shared/`

```
gs://bbc-travel-data-center/shared/
├── qa/
│   └── latest/
│       └── common_qa.json
├── visa/
│   └── latest/
│       └── visa.json
├── passport/
│   └── latest/
│       └── passport.json
├── travel_notice/
│   └── latest/
│       └── travel_notice.json
└── cache/                          # 選用：共用 Context Cache metadata
    └── context_cache_manifest.json
```

| 路徑 | 檔案 | 用途 |
|------|------|------|
| `shared/qa/latest/common_qa.json` | 平台共用 FAQ | 跨租戶通用問答 |
| `shared/visa/latest/visa.json` | 各國簽證知識 | 簽證條件、文件、天數 |
| `shared/passport/latest/passport.json` | 護照共通知識 | 效期、辦理流程 |
| `shared/travel_notice/latest/travel_notice.json` | 旅遊公告 / 注意事項 | 禁限帶、海關、警示 |

### 3.2 私有層 `tenants/{sno}/`

> `{sno}` 與 `config/tenant_registry.php` 及 Host B wire authority 一致；**不接受 LINE client 傳入 sno**。

```
gs://bbc-travel-data-center/tenants/{sno}/
├── qa/
│   └── latest/
│       └── qa.json
├── services/
│   └── latest/
│       └── services.json
├── company/
│   └── latest/
│       └── company_profile.json
├── rules/
│   └── latest/
│       └── rules.json
├── sheets_json/
│   └── latest/
│       ├── manifest.json           # 已同步 Sheet 清單（無個資列）
│       └── {sheet_id}/
│           └── snapshot_{timestamp}.json
└── cache/                          # 選用：租戶 Context Cache metadata
    └── context_cache_manifest.json
```

| 路徑 | 檔案 | 用途 |
|------|------|------|
| `tenants/{sno}/qa/latest/qa.json` | 租戶自有 FAQ | LINE / Gemini 優先讀取 |
| `tenants/{sno}/services/latest/services.json` | 服務項目與代辦費用 | 如護照代辦、接送 |
| `tenants/{sno}/company/latest/company_profile.json` | 公司介紹 | 品牌、聯絡、營業時間 |
| `tenants/{sno}/rules/latest/rules.json` | 租戶規則彙總 | 退改、簽證代辦、特殊說明 |
| `tenants/{sno}/sheets_json/latest/` | Sheet 同步快照 | 由 Google Sheet 轉出（**不含旅客個資明文**） |

### 3.3 與 Phase 3 路徑差異說明

Phase 3 部分範例使用 `tenants/{sno}/latest/qa.json` 扁平路徑；**Phase 4 MVP 採分類子目錄 + `latest/`**，利於權限與快取隔離。實作時可透過 **path alias** 相容舊規劃，但 **以本文件路徑為準**。

---

## 四、資料來源規劃

### 4.1 旅行社私有資料

| 項目 | 說明 |
|------|------|
| **維護方式** | **Google Login** 後維護（規劃入口：租戶後台 / 協作門戶） |
| **協作層** | **Google Sheets / Drive** 為人工編輯來源 |
| **正式讀取層** | 同步為 JSON 後存 **`tenants/{sno}/`**；AI **不直讀** Sheet API |
| **分類** | Tenant Private Knowledge（Phase 2） |

**同步流（概念）：**

```
Google Sheets / Drive（人工）
        ↓
   Sync Worker（主機 A，Phase 6 實作）
        ↓
   GCS tenants/{sno}/*/latest/*.json
        ↓
   Host B：sheet_id、sync_status、GCS 路徑（metadata only）
```

### 4.2 travel_b 私有範例來源（真實來源 · 僅標記）

| 項目 | 內容 |
|------|------|
| **tenant（內部 key）** | `travel_b` |
| **tenant_sno（Host B）** | `5f99b8d665e8444d`（見 `config/tenant_registry.php`） |
| **顯示名稱** | 旅行蜜優惠 |
| **性質** | **Tenant private source** |
| **不是** | **Shared source**（不得寫入 `gs://bbc-travel-data-center/shared/`） |
| **Phase 4** | **不連線、不讀取、不修改** 下列 Google 資源 |

**Google Sheet（私有協作來源）：**

```
https://docs.google.com/spreadsheets/d/1al59g7_h_VmeZiL3K0LZpbTf9GdWj5PL92WFSCs1kP8/edit?gid=1538130714#gid=1538130714
```

| 解析欄位（規劃） | 值 |
|------------------|-----|
| `spreadsheet_id` | `1al59g7_h_VmeZiL3K0LZpbTf9GdWj5PL92WFSCs1kP8` |
| `gid` | `1538130714` |
| `tenant_key` | `travel_b` |
| `tenant_sno` | `5f99b8d665e8444d` |

**未來同步目標（Phase 6+，本階段不實作）：**

```
Google Sheet（travel_b 私有）
        ↓
   Sync Worker（主機 A）
        ↓
   JSON（qa / services / rules 等，依 Sheet 分頁語意）
        ↓
   GCS gs://bbc-travel-data-center/tenants/5f99b8d665e8444d/
        ├── qa/latest/qa.json
        ├── services/latest/services.json
        ├── rules/latest/rules.json
        └── sheets_json/latest/1al59g7…/snapshot_{timestamp}.json
        ↓
   Host B：sheet_id、gid、sync_status、GCS 路徑（metadata only）
```

**讀取優先權（travel_b）：**

- **travel_b 私有層**（`tenants/5f99b8d665e8444d/`）**優先於** **shared 層**
- 若 `tenants/5f99b8d665e8444d/` 與 `shared/` 內容衝突（例如護照說明 vs 代辦費用），**以 travel_b private data 覆蓋 shared data**（文案 / 服務 / QA 域）
- 價格、庫存、可售等 **業務事實** 仍以 **Host B API** 為準（見 4.4、第六章）

---

### 4.3 共用資料來源範例（真實來源 · 僅標記）

| 項目 | 內容 |
|------|------|
| **性質** | **Shared source**（平台共用） |
| **不是** | **Tenant private source**（不得寫入任一 `tenants/{sno}/`） |
| **維護者** | **BBC / 管理者**（Google 登入維護） |
| **Phase 4** | **不連線、不讀取、不修改** 下列 Google 資源 |
| **消費者** | 所有租戶（含 travel_a、travel_b）**唯讀** 引用 |

**旅行社共用 Google Drive 資料夾：**

```
https://drive.google.com/drive/folders/1aHxIxLRqB749TKpeeR_USEoMS12ExgHG?usp=drive_link
```

| 解析欄位（規劃） | 值 |
|------------------|-----|
| `drive_folder_id` | `1aHxIxLRqB749TKpeeR_USEoMS12ExgHG` |
| `scope` | `shared` |

**共用 Google Sheet 範例：**

```
https://docs.google.com/spreadsheets/d/1Nasxl2nKHnI5crEeVDddRslDkS9t0vaUEqok_5Qva8w/edit?usp=sharing
```

| 解析欄位（規劃） | 值 |
|------------------|-----|
| `spreadsheet_id` | `1Nasxl2nKHnI5crEeVDddRslDkS9t0vaUEqok_5Qva8w` |
| `scope` | `shared` |

**未來同步目標（Phase 6+，本階段不實作）：**

```
Google Drive / Google Sheet（BBC 管理者維護）
        ↓
   Sync Worker（主機 A）
        ↓
   JSON（依內容類型分流）
        ↓
   GCS gs://bbc-travel-data-center/shared/
        ├── qa/latest/common_qa.json
        ├── visa/latest/visa.json
        ├── passport/latest/passport.json
        └── travel_notice/latest/travel_notice.json
```

**可提供之共用資料類型（範例）：**

| 類型 | 對應 GCS | 說明 |
|------|----------|------|
| 各國簽證 | `shared/visa/latest/visa.json` | 簽證條件、文件清單 |
| 護照共通知識 | `shared/passport/latest/passport.json` | 效期、辦理流程 |
| 旅遊公告 | `shared/travel_notice/latest/travel_notice.json` | 禁限帶、海關、警示 |
| 共用 QA | `shared/qa/latest/common_qa.json` | 跨租戶 FAQ |

---

### 4.4 共用資料（一般原則）

| 項目 | 說明 |
|------|------|
| **維護者** | **BBC 管理者** |
| **登入** | BBC / 管理者 **Google** 帳號 |
| **協作層** | Google Sheets / Drive（平台級，見 4.3 範例） |
| **正式讀取層** | JSON 存 **`shared/*/latest/`** |
| **租戶權限** | 租戶 **唯讀** 消費，無 shared 寫入權 |

---

### 4.5 資料來源讀取優先權（摘要）

LINE OA / Gemini 組裝回答 context 時，**資料來源** 讀取順序如下：

| 順序 | 來源類型 | 路徑 / API | 說明 |
|------|----------|------------|------|
| **1** | **Tenant private source** | `gs://bbc-travel-data-center/tenants/{sno}/` | 含 travel_b Sheet 同步後之 `qa`、`services`、`rules` 等 |
| **2** | **Shared source** | `gs://bbc-travel-data-center/shared/` | 含 4.3 共用 Drive / Sheet 同步後之簽證、護照、公告、共用 QA |
| **3** | **Host B API / SQL** | 依業務情境 | 價格、庫存、可售、正式行程等 **業務事實**；知識題不足時補充 |

**衝突規則（再次明確）：**

| 衝突類型 | 優先方 |
|----------|--------|
| `tenants/{sno}/` vs `shared/`（文案、FAQ、服務說明、代辦費） | **Tenant private 優先** |
| 以 travel_b 為例 | `tenants/5f99b8d665e8444d/` **覆蓋** `shared/` 衝突片段 |
| 知識層 vs Host B（價格、庫存、可售） | **Host B 優先** |

**範例（延續 Phase 2）：**

- `shared/passport/latest/passport.json` → 護照效期共通知識  
- `tenants/5f99b8d665e8444d/services/latest/services.json` → travel_b 護照代辦費用  
- 合併時：**費用與代辦細節以 travel_b 私有為準**；共通知識由 shared 補充

---

## 五、標準 JSON 類型

以下沿用 `bbc_travel_data_center.v1`；欄位可精簡為 MVP 子集。

### 5.1 `qa.json`

| 項目 | 內容 |
|------|------|
| **用途** | 結構化問答，供 LINE / Gemini 直接引用 |
| **Tenant private** | `tenants/{sno}/qa/latest/qa.json` |
| **Shared** | `shared/qa/latest/common_qa.json` |
| **必要欄位** | `schema_version`, `scope`, `items[]`（含 `qa_id`, `question`, `answer`, `is_active`） |
| **租戶專用** | `tenant_sno`（private 必填） |

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "scope": "tenant",
  "tenant_sno": "e1fd133c7e8e45a1",
  "items": [
    {
      "qa_id": "qa_cancel_policy",
      "question": "取消行程如何退費？",
      "answer": "出發前 14 天可全額退費…",
      "tags": ["退改"],
      "is_active": true,
      "priority": 100
    }
  ],
  "updated_at": "2026-05-29T00:00:00Z"
}
```

### 5.2 `services.json`

| 項目 | 內容 |
|------|------|
| **用途** | 服務項目、代辦費用（如護照代辦） |
| **Tenant private** | ✅ `tenants/{sno}/services/latest/services.json` |
| **Shared** | ❌（平台級服務若有，另案 `shared/services/`，MVP 不做） |
| **必要欄位** | `tenant_sno`, `services[]`（`service_id`, `name`, `price`, `is_active`） |

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "tenant_sno": "e1fd133c7e8e45a1",
  "services": [
    {
      "service_id": "svc_passport_agency",
      "name": "護照代辦",
      "category": "document",
      "price": { "amount": 800, "currency": "TWD", "unit": "per_person" },
      "description": "含郵資，約 7 工作天",
      "is_active": true
    }
  ],
  "updated_at": "2026-05-29T00:00:00Z"
}
```

### 5.3 `company_profile.json`

| 項目 | 內容 |
|------|------|
| **用途** | 公司介紹、聯絡方式、營業時間 |
| **Tenant private** | ✅ |
| **Shared** | ❌ |

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "tenant_sno": "e1fd133c7e8e45a1",
  "company_name": "○○旅行社",
  "summary": "專營日本、東南亞團體旅遊…",
  "contact": {
    "phone": "02-1234-5678",
    "line_official": "@xxx",
    "address": "台北市…"
  },
  "business_hours": "週一至週五 09:00–18:00",
  "updated_at": "2026-05-29T00:00:00Z"
}
```

### 5.4 `rules.json`

| 項目 | 內容 |
|------|------|
| **用途** | 租戶退改、代辦、特殊政策（可覆蓋共用文案語意） |
| **Tenant private** | ✅ |
| **Shared** | ❌ |
| **必要欄位** | `rules[]`（`rule_id`, `topic`, `content`, `priority`） |

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "tenant_sno": "e1fd133c7e8e45a1",
  "rules": [
    {
      "rule_id": "rule_passport_agency",
      "topic": "護照代辦",
      "content": "本社代辦費用 NT$800，需備妥…",
      "priority": 10
    }
  ],
  "updated_at": "2026-05-29T00:00:00Z"
}
```

### 5.5 `visa.json`

| 項目 | 內容 |
|------|------|
| **用途** | 各國簽證條件、文件、處理天數 |
| **Tenant private** | ❌（租戶自訂簽證說明放 `rules.json`） |
| **Shared** | ✅ `shared/visa/latest/visa.json` |

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "scope": "shared",
  "countries": [
    {
      "country_code": "JP",
      "country_name": "日本",
      "visa_free_tw": true,
      "summary": "台灣護照免簽證停留 90 天…",
      "documents": [],
      "processing_days": null
    }
  ],
  "updated_at": "2026-05-29T00:00:00Z"
}
```

### 5.6 `passport.json`

| 項目 | 內容 |
|------|------|
| **用途** | 護照效期、辦理、共通知識 |
| **Tenant private** | ❌ |
| **Shared** | ✅ `shared/passport/latest/passport.json` |

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "scope": "shared",
  "topics": [
    {
      "topic_id": "passport_validity",
      "title": "護照效期",
      "content": "多數國家要求入境時護照效期餘 6 個月以上…"
    }
  ],
  "updated_at": "2026-05-29T00:00:00Z"
}
```

### 5.7 `travel_notice.json`

| 項目 | 內容 |
|------|------|
| **用途** | 旅遊公告、禁限帶、海關提醒 |
| **Tenant private** | ❌ |
| **Shared** | ✅ `shared/travel_notice/latest/travel_notice.json` |

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "scope": "shared",
  "notices": [
    {
      "notice_id": "notice_lithium_battery",
      "title": "鋰電池攜帶規定",
      "content": "隨身行李鋰電池容量限制…",
      "effective_from": "2026-01-01",
      "tags": ["航空", "安全"]
    }
  ],
  "updated_at": "2026-05-29T00:00:00Z"
}
```

### 5.8 JSON 類型總表

| 檔案 | Tenant private | Shared | GCS 路徑 |
|------|----------------|--------|----------|
| `qa.json` | ✅ | ✅ `common_qa.json` | 見 3.1 / 3.2 |
| `services.json` | ✅ | ❌ MVP | `tenants/{sno}/services/latest/` |
| `company_profile.json` | ✅ | ❌ | `tenants/{sno}/company/latest/` |
| `rules.json` | ✅ | ❌ | `tenants/{sno}/rules/latest/` |
| `visa.json` | ❌ | ✅ | `shared/visa/latest/` |
| `passport.json` | ❌ | ✅ | `shared/passport/latest/` |
| `travel_notice.json` | ❌ | ✅ | `shared/travel_notice/latest/` |

---

## 六、讀取優先權

### 6.1 LINE OA / Gemini 回答時讀取順序

| 順序 | 來源 | 路徑 / API |
|------|------|------------|
| **1** | 租戶私有 | `tenants/{sno}/qa/`、`services/`、`company/`、`rules/` |
| **2** | 平台共用 | `shared/qa/`、`visa/`、`passport/`、`travel_notice/` |
| **3** | Host B | 正式 API / SQL（價格、庫存、可售等 **業務事實**） |

### 6.2 衝突規則

| 情境 | 規則 |
|------|------|
| 私有 vs 共用（文案 / FAQ / 服務說明） | **私有層優先** |
| 私有 vs Host B（價格、庫存） | **Host B 優先** |
| 共用 vs Host B（業務事實） | **Host B 優先** |

### 6.3 範例

**使用者問：**「護照要怎麼辦？代辦多少錢？」

| 層級 | 提供內容 |
|------|----------|
| `shared/passport/latest/passport.json` | 護照效期、辦理流程等 **共通知識** |
| `tenants/{sno}/services/latest/services.json` | 該社 **護照代辦費用 NT$800** |
| `tenants/{sno}/rules/latest/rules.json` | 代辦所需文件、工作天 |
| Host B | （若問即時庫存則查 API；本題可不觸發） |

**合併結果：** 共通知識來自 shared；**費用與代辦細節以 tenants/{sno}/ 為準**，覆蓋任何與共用層矛盾的租戶專屬敘述。

### 6.4 機器可讀設定（沿用 Phase 3）

實作時讀取 `data_precedence.json` 或程式內等價設定：

```json
{
  "read_order": ["tenant", "shared", "host_b"],
  "conflict_rules": [
    { "field_domain": "faq_copy", "winner": "tenant" },
    { "field_domain": "pricing_inventory", "winner": "host_b" }
  ]
}
```

---

## 七、Gemini Context Cache 規劃

### 7.1 定位

| 項目 | 說明 |
|------|------|
| **適用** | 高頻、重複率高之 **QA**（租戶或共用） |
| **不取代 GCS JSON** | GCS `latest/*.json` 仍為 **權威契約與重建來源** |
| **不取代 RAG** | 長尾、多模態、法規細節仍走未來 RAG |
| **metadata 存放** | `cache_id` / `gemini_cache_id` 僅存 **metadata**（Host B 或 `tenants/{sno}/cache/`） |

### 7.2 流程

```
GCS qa.json（權威）
        ↓
   選取高頻 qa_id 列表
        ↓
   建立 Gemini Context Cache（Phase 8 實作）
        ↓
   寫入 cache manifest（cache_id, source_qa_ids, expires_at）
        ↓
   過期 / 失效 → 從 GCS JSON 重建 Cache
```

### 7.3 Manifest 範例（規劃）

**路徑：** `tenants/{sno}/cache/context_cache_manifest.json`

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "tenant_sno": "e1fd133c7e8e45a1",
  "entries": [
    {
      "cache_key": "qa_high_freq_v1",
      "gemini_cache_id": "cachedContents/…",
      "source_gcs_path": "tenants/e1fd133c7e8e45a1/qa/latest/qa.json",
      "source_qa_ids": ["qa_cancel_policy", "qa_visa_jp"],
      "expires_at": "2026-06-29T00:00:00Z",
      "invalidated": false
    }
  ]
}
```

---

## 八、與 Hybrid Smart Search / BATS 的關係

### 8.1 層級

```
LINE 使用者
    → BATS（SaaSRouter、Formatter、Gemini 編排）  ← 回覆層
    → Hybrid Smart Search                          ← 搜尋 / 條件層（行程）
    → BBC 旅業資料中心                              ← 資料供應層（本 MVP）
         ├── tenants/{sno}/*  (Tenant Private)
         └── shared/*        (Shared)
    → Host B API                                   ← 正式業務資料
```

### 8.2 原則

| 原則 | 說明 |
|------|------|
| 資料供應 vs 搜尋 | 本中心 **供應知識 JSON**；Hybrid **負責行程搜尋** |
| 不重構 Hybrid | `HybridSearchConditionBuilder`、`TourSearchApiClient` **不變** |
| MVP 範圍 | 先讓 BATS / Prompt 路徑能 **合併 tenant + shared JSON** |
| 未來 Adapter | **TenantPrivateSource**、**SharedSource**（Phase 7） |

### 8.3 MVP 與 Hybrid 邊界

- **Hybrid 查詢「東京團」**：仍走 Host B + 既有 Hybrid 路徑。  
- **使用者問「護照代辦費用」**：走 **TenantPrivateSource + SharedSource**，**不經 Hybrid**（除非產品明確要關聯行程）。

---

## 九、主機 A / 主機 B 分工

### 9.1 主機 A（`C:\bbc-ai-bot`）

| 職責 | MVP 階段 |
|------|----------|
| 讀取 GCS JSON（或本地 sample 模擬） | ✅ 規劃實作 |
| 呼叫 Gemini | ✅ 沿用既有 BATS 路徑 |
| 管理 tenant source priority | ✅ 合併邏輯 |
| Google Sheet sync worker | ⏳ Phase 6，MVP 不做 |
| Signed URL / 檔案上傳 | ❌ 本 MVP 不含 |

### 9.2 主機 B（SQL Server）

| 允許 | 禁止 |
|------|------|
| metadata（`sheet_id`, `sync_status`, GCS path） | 大檔二進位 |
| source reference | 長篇 QA 全文 |
| `tenant_sno`、檔案 hash、版本指標 | 旅客姓名、生日、身分證、手機 |

### 9.3 資料流（MVP）

```
[Phase 5] Local sample JSON 或 GCS
        ↓
主機 A：TenantPrivateSource + SharedSource 合併
        ↓
主機 A：Gemini / Formatter（BATS）
        ↓
LINE 回覆

Host B：僅在需要時查業務 API（與知識題分離）
```

---

## 十、MVP 階段建議

### 10.1 最小可行 MVP 定義

| # | 項目 | 說明 |
|---|------|------|
| 1 | **本地 sample JSON** | 目錄模擬 GCS 路徑，如 `samples/travel_data_center/tenants/{sno}/`、`samples/.../shared/` |
| 2 | **合併邏輯** | 實作 `mergeTenantAndShared(sno, topic)` → 統一 context 物件 |
| 3 | **私有覆蓋共用** | 同 `topic`（如 `passport`）有租戶 `rules` / `services` 時，**不採用 shared 中衝突片段** |
| 4 | **不接 Google API** | Sheet 以 hand-crafted JSON 代替 |
| 5 | **不啟用 RAG** | 僅 JSON 注入 prompt |
| 6 | **不建 bucket** | 路徑與讀取介面先抽象（`GcsJsonReaderInterface`） |
| 7 | **驗收場景** | 固定 3～5 個 LINE 問答（護照、簽證、退改、代辦費）比對預期引用層級 |

### 10.2 建議本地目錄結構（模擬）

```
samples/bbc_travel_data_center/
├── shared/
│   ├── qa/latest/common_qa.json
│   ├── visa/latest/visa.json
│   ├── passport/latest/passport.json
│   └── travel_notice/latest/travel_notice.json
└── tenants/
    └── e1fd133c7e8e45a1/
        ├── qa/latest/qa.json
        ├── services/latest/services.json
        ├── company/latest/company_profile.json
        └── rules/latest/rules.json
```

### 10.3 MVP 驗收標準（規劃）

| # | 情境 | 預期 |
|---|------|------|
| 1 | 問退改政策 | 回答引用 `tenants/{sno}/qa/latest/qa.json` |
| 2 | 問護照效期 | 引用 `shared/passport/` |
| 3 | 問代辦費用 | 引用 `tenants/{sno}/services/`，優先於 shared |
| 4 | 問日本簽證 | 引用 `shared/visa/`，租戶 rules 可補充 |
| 5 | travel_b / travel_a | `sno` 不同則讀不同 `tenants/{sno}/` |

### 10.4 MVP 明確不做

- `addTour.php`、行程上傳、Parsing 管線  
- 正式 GCS、正式 Google OAuth / Sheets API  
- Context Cache 實際建立（可只留 manifest 假資料）  
- Hybrid 核心修改  

---

## 十一、後續 Phase 建議

| Phase | 主題 | 交付重點 |
|-------|------|----------|
| **Phase 5** | GCS JSON Source Reader 設計 | `GcsJsonReader`、`LocalSampleReader`、路徑解析、`latest/` 快取 |
| **Phase 6** | Google Sheet Sync Flow 設計 | OAuth、增量同步、`sheets_json/latest/`、Host B metadata |
| **Phase 7** | TenantPrivateSource / SharedSource Adapter | 注入 `TourPromptContextService` / BATS，單元測試 |
| **Phase 8** | Gemini Context Cache 接入 | 高頻 QA 選取、建立 / 失效、manifest |
| **Phase 9** | Hybrid Smart Search 整合 | Hybrid 結果 + 知識層並列；不改條件解析核心 |
| **Phase 10+** | 行程上傳、RAG、正式 bucket | 另案銜接 Phase 2 行程入口 |

---

## 附錄 A：MVP 與已推送文件對照

| 已推送 commit | 文件 |
|---------------|------|
| `2cae92a` | Phase 1～3 架構 / 分類 / Schema |
| 本文件 | Phase 4 MVP 實作前規劃（**未 commit**） |

## 附錄 B：文件修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-29 | Phase 4 初版：私有 / 共用 MVP 實作前規劃 |
| 1.1 | 2026-05-29 | 第四章補充 travel_b / 共用真實範例來源與讀取優先權（4.2～4.5） |

---

*本文件為實作前規劃，不構成上線承諾。程式變更須自 Phase 5 起另開實作計畫與 code review。*
