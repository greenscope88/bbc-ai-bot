# BBC 旅業資料中心 — Phase 5 GCS JSON Source Reader 規劃

**副標：** GCS JSON Source Reader 設計規範（Source Reader Architecture）

**文件代號：** `BBC_TRAVEL_DATA_CENTER_GCS_JSON_SOURCE_READER_PLAN`

**版本：** Phase 5（規劃 only）

**狀態：** Planning — 本文件不觸發程式、雲端資源、資料庫或 Git 變更

**規劃主機：** 主機 A `103.1.222.14` · 專案路徑 `C:\bbc-ai-bot`

**關聯文件：**

- [BBC_TRAVEL_DATA_CENTER_GCS_DRIVE_ARCHITECTURE_PLAN.md](./BBC_TRAVEL_DATA_CENTER_GCS_DRIVE_ARCHITECTURE_PLAN.md)（Phase 1）
- [BBC_TRAVEL_DATA_CENTER_DATA_CLASSIFICATION_PLAN.md](./BBC_TRAVEL_DATA_CENTER_DATA_CLASSIFICATION_PLAN.md)（Phase 2）
- [BBC_TRAVEL_DATA_CENTER_JSON_SCHEMA_PLAN.md](./BBC_TRAVEL_DATA_CENTER_JSON_SCHEMA_PLAN.md)（Phase 3）
- [BBC_TRAVEL_DATA_CENTER_PRIVATE_SHARED_MVP_PLAN.md](./BBC_TRAVEL_DATA_CENTER_PRIVATE_SHARED_MVP_PLAN.md)（Phase 4）

**規劃日期：** 2026-05-29

**Schema 版本（沿用）：** `bbc_travel_data_center.v1`

---

## 一、文件目的

本文件定義 **BBC 旅業資料中心（BBC Travel Data Center）** 之 **GCS JSON Source Reader** 設計規範，作為從 GCS（或 MVP 階段之本地 sample）讀取標準 JSON、依優先權合併、產出統一 **Knowledge Context** 供下游消費的 **單一讀取層**。

**供以下系統共用（規劃）：**

| 消費者 | 用途 |
|--------|------|
| **BATS** | LINE Webhook / SaaSRouter、Gemini 回覆編排 |
| **Hybrid Smart Search** | 行程搜尋結果之知識補充（非取代 Host B 搜尋） |
| **Gemini** | Prompt context、多模態 URL 引用 |
| **未來 RAG** | 檢索前之結構化 manifest 與 chunk 來源 |

**設計目標：**

1. **單一入口**：所有 `tenants/{sno}/` 與 `shared/` JSON 經 Source Reader 讀取，不散落各處 `file_get_contents`。  
2. **可替換後端**：正式 GCS vs 本地 `samples/` 僅差 **Reader 實作**，介面不變。  
3. **優先權可測**：Tenant Private > Shared > Host B > Future RAG。  
4. **Phase 5 僅文件**：不修改 PHP、不建 bucket、不串 Google API。

---

## 二、Source Reader 架構

### 2.1 元件總覽

```
                    ┌─────────────────────┐
                    │   BATS / Hybrid     │
                    │   / Gemini          │
                    └──────────┬──────────┘
                               │ getKnowledgeContext()
                               ▼
                    ┌─────────────────────┐
                    │    SourceReader      │  ← 對外 Facade
                    └──────────┬──────────┘
                               │
         ┌─────────────────────┼─────────────────────┐
         ▼                     ▼                     ▼
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│ SourceRegistry  │  │SourcePriority   │  │  SourceMerger    │
│ (註冊各 Source)  │  │Resolver         │  │ (合併規則)       │
└────────┬────────┘  └────────┬────────┘  └────────┬────────┘
         │                    │                     │
         ▼                    ▼                     ▼
 TenantPrivateSource    讀取順序 1→4          MergedKnowledgeContext
 SharedSource
 HostBSource
 FutureRagSource
         │
         ▼
┌─────────────────┐
│ JsonFileBackend │  ← GcsJsonBackend | LocalSampleBackend
└─────────────────┘
```

### 2.2 元件職責

| 元件 | 職責 |
|------|------|
| **SourceReader** | 對外 Facade：`readForTenant(sno, options)` → `MergedKnowledgeContext` |
| **SourceRegistry** | 註冊、啟用/停用各 Source 實作；依 tenant feature flag 決定可用列表 |
| **SourcePriorityResolver** | 依 `data_precedence` 決定讀取順序與衝突時 winner |
| **SourceMerger** | 將多 Source 回傳之片段依 Merge Rules 合成單一 context |
| **JsonFileBackend** | 抽象：依路徑讀取 JSON 字串並 decode（GCS 或本地） |

### 2.3 建議 PHP 命名空間（規劃，Phase 5 不實作）

```
BBC\TravelDataCenter\Reader\
  SourceReader.php
  SourceRegistry.php
  SourcePriorityResolver.php
  SourceMerger.php
  Source\TenantPrivateSource.php
  Source\SharedSource.php
  Source\HostBSource.php
  Source\FutureRagSource.php
  Backend\GcsJsonBackend.php
  Backend\LocalSampleJsonBackend.php
  Dto\MergedKnowledgeContext.php
```

---

## 三、Source 類型

| Source | 代號 | 資料來源 | MVP | 正式 |
|--------|------|----------|-----|------|
| **TenantPrivateSource** | `tenant` | `tenants/{sno}/*/latest/*.json` | ✅ sample | GCS |
| **SharedSource** | `shared` | `shared/*/latest/*.json` | ✅ sample | GCS |
| **HostBSource** | `host_b` | Host B REST API | ✅ 既有 client | 既有 |
| **FutureRagSource** | `rag` | `tenants/{sno}/rag/`、`shared/rag/` | ❌ stub | Phase 9+ |

**介面契約（規劃）：**

```php
interface KnowledgeSourceInterface
{
    public function getSourceId(): string;

    /**
     * @param array{tenant_sno: string, locale?: string, topics?: string[]} $context
     * @return SourceFragment[]  零或多個片段
     */
    public function fetch(array $context): array;
}
```

---

## 四、Tenant Private Source

### 4.1 來源根路徑

```
gs://bbc-travel-data-center/tenants/{sno}/
```

> `{sno}` 由 `tenant_registry` 解析，**不接受 LINE client 傳入**。

### 4.2 可能讀取之 JSON

| 檔案 | GCS 路徑（Phase 4） | 用途 |
|------|---------------------|------|
| `qa.json` | `tenants/{sno}/qa/latest/qa.json` | 租戶 FAQ |
| `services.json` | `tenants/{sno}/services/latest/services.json` | 服務項目、代辦費用 |
| `company_profile.json` | `tenants/{sno}/company/latest/company_profile.json` | 公司介紹 |
| `rules.json` | `tenants/{sno}/rules/latest/rules.json` | 退改、代辦規則 |

**Reader 行為：**

- 依 `topics` 參數選讀（如只讀 `qa` + `services`）
- 檔案不存在 → 回傳空 fragment（**不拋錯**），由 Merger fallback
- `schema_version` 不符 → log warning，嘗試 best-effort 解析

### 4.3 已知範例：travel_b

| 項目 | 值 |
|------|-----|
| **tenant_key** | `travel_b` |
| **tenant_sno** | `5f99b8d665e8444d` |
| **協作來源（Phase 5 不連線）** | Google Sheet：`1al59g7_h_VmeZiL3K0LZpbTf9GdWj5PL92WFSCs1kP8`（gid `1538130714`） |
| **未來同步目標** | Sheet → JSON → `tenants/5f99b8d665e8444d/` |
| **MVP sample 路徑** | `samples/bbc_travel_data_center/tenants/5f99b8d665e8444d/` |

**TenantPrivateSource 讀取範例（規劃）：**

```
read(sno=5f99b8d665e8444d, topics=[qa, services, rules])
  → fragments: [
      { source: tenant, topic: qa, path: …/qa/latest/qa.json, data: {...} },
      { source: tenant, topic: services, path: …/services/latest/services.json, data: {...} },
    ]
```

---

## 五、Shared Source

### 5.1 來源根路徑

```
gs://bbc-travel-data-center/shared/
```

### 5.2 可能讀取之 JSON

| 檔案 | GCS 路徑 | 用途 |
|------|----------|------|
| `visa.json` | `shared/visa/latest/visa.json` | 各國簽證 |
| `passport.json` | `shared/passport/latest/passport.json` | 護照共通知識 |
| `travel_notice.json` | `shared/travel_notice/latest/travel_notice.json` | 旅遊公告 |
| `common_qa.json` | `shared/qa/latest/common_qa.json` | 平台共用 FAQ |

### 5.3 已知來源（Phase 5 不連線）

| 類型 | URL（僅標記） |
|------|----------------|
| **共用 Drive 資料夾** | `https://drive.google.com/drive/folders/1aHxIxLRqB749TKpeeR_USEoMS12ExgHG?usp=drive_link` |
| **共用 Sheet** | `https://docs.google.com/spreadsheets/d/1Nasxl2nKHnI5crEeVDddRslDkS9t0vaUEqok_5Qva8w/edit?usp=sharing` |

| 項目 | 說明 |
|------|------|
| **性質** | Shared source |
| **維護者** | BBC / 管理者 |
| **未來同步** | Drive / Sheet → JSON → `shared/*/latest/` |

**SharedSource 讀取範例（規劃）：**

```
read(topics=[passport, visa])
  → fragments: [
      { source: shared, topic: passport, path: shared/passport/latest/passport.json, data: {...} },
    ]
```

---

## 六、Source Priority

### 6.1 讀取順序（預設）

| 順序 | Source | 說明 |
|------|--------|------|
| **1** | **Tenant Private** | `tenants/{sno}/` |
| **2** | **Shared** | `shared/` |
| **3** | **Host B** | 正式 API / SQL 對外接口 |
| **4** | **Future RAG** | 向量檢索（未實作前 stub 跳過） |

### 6.2 衝突域（field_domain）

| field_domain | winner | 說明 |
|--------------|--------|------|
| `faq_copy` | tenant | QA 文案 |
| `service_copy` | tenant | 服務、代辦費說明 |
| `passport_visa_knowledge` | tenant > shared | 租戶規則可覆蓋共用敘述 |
| `pricing_inventory` | host_b | 價格、庫存、可售 |
| `package_media_url` | tenant | 圖片、PDF URL（行程類，Phase 5 MVP 可不載入） |
| `rag_chunk` | rag > tenant > shared | 未來 RAG 啟用後調整 |

### 6.3 範例：護照共通知識 + 代辦費用

**使用者問：**「護照怎麼辦？代辦多少錢？」

| 層級 | 提供內容 | topic / key |
|------|----------|-------------|
| **Shared** | 護照效期、辦理流程共通知識 | `passport` → `topics[].content` |
| **Tenant（travel_b）** | 護照代辦 NT$800、所需文件 | `services` → `service_id=svc_passport_agency` |

**SourcePriorityResolver 決策：**

1. 先載入 **TenantPrivateSource**（`services`、`rules`）  
2. 再載入 **SharedSource**（`passport`）  
3. **不衝突**：合併兩者（共通知識 + 費用）  
4. 若 tenant `rules` 與 shared `passport` 對同一斷言矛盾（如效期說明）→ **tenant 覆蓋 shared**

### 6.4 最終回答組合（MergedKnowledgeContext）

**建議輸出結構（供 Gemini / Formatter）：**

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "tenant_sno": "5f99b8d665e8444d",
  "knowledge_blocks": [
    {
      "topic": "passport",
      "priority_layer": "shared",
      "summary": "護照效期建議餘 6 個月以上…",
      "source_path": "shared/passport/latest/passport.json"
    },
    {
      "topic": "passport_agency",
      "priority_layer": "tenant",
      "summary": "本社護照代辦費用 NT$800…",
      "source_path": "tenants/5f99b8d665e8444d/services/latest/services.json",
      "overrides": []
    }
  ],
  "precedence_applied": ["tenant", "shared"],
  "host_b_attached": false
}
```

**組裝給 Gemini 的建議順序：**

1. **Tenant 專屬**（費用、代辦、退改）— 放前，權重高  
2. **Shared 共通**（效期、法規）— 補充  
3. **Host B** — 僅在問價格/庫存時附加  

---

## 七、Source Merge

### 7.1 Merge Rules

| 規則 ID | 條件 | 動作 |
|---------|------|------|
| **M1** | 相同 `topic` + 相同 `item_key`（如 `qa_id`） | **高優先 Source 覆蓋低優先** |
| **M2** | 相同 `topic`、不同 `item_key` | **合併陣列**（union） |
| **M3** | tenant 有值、shared 有值、語意衝突 | **tenant wins**（`field_domain=faq_copy|service_copy`） |
| **M4** | tenant 無該 topic | **fallback 至 shared** |
| **M5** | tenant + shared 皆無 | **fallback 至 Host B**（若 `options.include_host_b`） |
| **M6** | 皆無 | 回傳空 block + log `knowledge_miss` |

### 7.2 同 key 衝突覆蓋範例

**情境：** `qa_id=qa_visa_jp` 在 shared 與 tenant 皆存在。

| Source | answer 摘要 |
|--------|-------------|
| shared `common_qa` | 「日本免簽 90 天…」 |
| tenant `qa` | 「本社代辦簽證服務，費用…」 |

**結果：** 僅保留 **tenant** 之 `qa_visa_jp`（M1 + M3）。

### 7.3 不存在時 fallback

```
try tenant.qa.json          → 有則用
else try shared.common_qa   → 有則用
else if include_host_b      → HostBSource
else                        → 空 + 標記 fallback_exhausted
```

### 7.4 SourceMerger 輸出

| 欄位 | 說明 |
|------|------|
| `knowledge_blocks[]` | 合併後區塊 |
| `merge_audit[]` | 紀錄每 key 來自哪個 Source、是否覆蓋 |
| `warnings[]` | 缺檔、schema 不符 |

---

## 八、Cache 規劃

### 8.1 三層 Cache

| 層級 | 名稱 | 存放 | 用途 |
|------|------|------|------|
| **L1** | JSON Cache（進程內） | 主機 A 記憶體 | 單次 request 內重複讀同一路徑 |
| **L2** | GCS Cache（檔案級） | `tenants/{sno}/cache/`、`shared/cache/` | etag / `updated_at` manifest，減少重複下載 |
| **L3** | Gemini Context Cache | Google API + manifest JSON | 高頻 QA；**不取代** GCS JSON |

### 8.2 Cache Key（規劃）

```
tdc:json:{tenant_sno|shared}:{relative_path}:{file_hash_or_updated_at}
```

### 8.3 Cache Miss / Rebuild

| 事件 | 行為 |
|------|------|
| **cache miss** | 從 Backend 讀取 JSON → 填入 L1/L2 → 回傳 |
| **GCS `updated_at` 變更** | L2 失效；下次 miss 時重建 |
| **Gemini cache 過期** | 讀 GCS `qa.json` 重新建立 Context Cache（Phase 8） |
| **手動 purge** | 管理 API 清除 `tenants/{sno}/cache/*` manifest |

**原則：**

- **GCS JSON 為權威**；任何 Cache 皆可從 GCS 重建  
- Context Cache **不存** 業務全文於 Host B，僅 `cache_id` metadata  

---

## 九、與 Hybrid Smart Search 關係

### 9.1 邊界

| 系統 | 職責 |
|------|------|
| **Hybrid Smart Search** | 自然語言 → 條件 → **Host B 行程搜尋** |
| **Source Reader** | **知識 JSON** 讀取與合併（QA、簽證、服務） |

### 9.2 整合方式（規劃）

```
使用者：「六月底東京團，護照要辦多久？」
        │
        ├─► Hybrid Smart Search → HostBSource → 行程列表
        │
        └─► SourceReader → TenantPrivate + Shared → 護照知識
                │
                ▼
        TourPromptContextService 合併兩路結果（不修改 Hybrid 核心）
```

### 9.3 未來增量

| 增量 | 說明 |
|------|------|
| **TenantPrivateSource** | 注入 prompt 前段 |
| **SharedSource** | 補充簽證/護照 |
| **不重構** | `HybridSearchConditionBuilder`、`TourSearchApiClient` **不變** |

---

## 十、主機 A / 主機 B 分工

### 10.1 主機 A

| 職責 | 元件 |
|------|------|
| 讀 JSON | `JsonFileBackend` / `SourceReader` |
| 合併 Source | `SourceMerger` + `SourcePriorityResolver` |
| 組 Gemini Context | BATS → `MergedKnowledgeContext` 轉 prompt 區塊 |
| 未來 Sheet sync | Phase 6 worker（寫 GCS，非 Reader 職責） |

### 10.2 主機 B

| 允許 | 禁止 |
|------|------|
| metadata（GCS 路徑、`file_hash`） | 長篇 QA 全文 |
| sync_status | 旅客個資 |
| sheet_id、sheet_url、row_start/end | 大檔二進位 |
| host_b_package_id 參照 | |

**Reader 不直連 SQL**；Host B 知識僅經 **HostBSource API**。

---

## 十一、MVP 設計

### 11.1 原則：不串 GCS

| 項目 | MVP 做法 |
|------|----------|
| Backend | `LocalSampleJsonBackend` |
| 根目錄 | `samples/bbc_travel_data_center/` |
| GCS | **不建立 bucket**、不呼叫 Storage API |

### 11.2 目錄結構（模擬 GCS）

```
samples/bbc_travel_data_center/
├── shared/
│   ├── passport/latest/passport.json
│   ├── visa/latest/visa.json
│   ├── travel_notice/latest/travel_notice.json
│   └── qa/latest/common_qa.json
└── tenants/
    └── 5f99b8d665e8444d/          # travel_b
        ├── qa/latest/qa.json
        ├── services/latest/services.json
        ├── company/latest/company_profile.json
        └── rules/latest/rules.json
```

### 11.3 驗證項目

| # | 測試 | 預期 |
|---|------|------|
| 1 | **priority** | tenant `services` 覆蓋 shared 同 topic 衝突 |
| 2 | **merge** | passport 共通 + 代辦費用並存於 `knowledge_blocks` |
| 3 | **fallback** | 刪除 tenant `qa.json` 時改讀 `shared/common_qa` |
| 4 | **missing** | 兩層皆無時 `fallback_exhausted`、不 fatal |
| 5 | **sno 隔離** | travel_a / travel_b 讀不同子目錄 |

### 11.4 MVP 不做的項目

- 正式 `GcsJsonBackend`  
- Google Sheet Sync  
- Gemini Context Cache 建立  
- Hybrid 核心修改  
- Host B metadata 表新增  

### 11.5 設定開關（規劃）

```php
// config 規劃鍵（Phase 5 不寫入 config.php）
'travel_data_center.reader_backend' => 'local_sample', // local_sample | gcs
'travel_data_center.sample_root' => 'samples/bbc_travel_data_center',
```

---

## 十二、未來 Phase

| Phase | 主題 | 交付重點 |
|-------|------|----------|
| **Phase 6** | Google Sheet Sync Flow | OAuth、增量同步、`sheets_json/latest/`、寫入 GCS |
| **Phase 7** | Source Adapter | `KnowledgeSourceInterface` 實作、注入 `TourPromptContextService` |
| **Phase 8** | Gemini Context Cache | 高頻 QA、`cache_id` manifest、失效重建 |
| **Phase 9** | Hybrid Smart Search Integration | 搜尋結果 + Knowledge context 並列輸出 |
| **Phase 10+** | GCS 正式 bucket、RAG、`GcsJsonBackend` | 生產環境切換 backend |

---

## 附錄 A：MergedKnowledgeContext 完整範例

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "tenant_sno": "5f99b8d665e8444d",
  "tenant_key": "travel_b",
  "locale": "zh-TW",
  "knowledge_blocks": [
    {
      "topic": "passport",
      "layer": "shared",
      "items": [{ "topic_id": "passport_validity", "content": "…" }]
    },
    {
      "topic": "services",
      "layer": "tenant",
      "items": [{ "service_id": "svc_passport_agency", "name": "護照代辦", "price": { "amount": 800 } }]
    }
  ],
  "merge_audit": [
    { "key": "passport", "winner": "shared", "reason": "tenant_no_override" },
    { "key": "services.svc_passport_agency", "winner": "tenant", "reason": "tenant_only" }
  ],
  "cache_hits": { "l1": 2, "l2": 0 },
  "generated_at": "2026-05-29T12:00:00Z"
}
```

## 附錄 B：文件修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-29 | Phase 5 初版：GCS JSON Source Reader 規劃 |

---

*本文件為設計規範，不構成實作承諾。程式變更自 Phase 7 起另開實作計畫與 code review。*
