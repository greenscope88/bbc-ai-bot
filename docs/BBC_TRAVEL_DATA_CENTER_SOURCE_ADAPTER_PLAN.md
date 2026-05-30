# BBC 旅業資料中心 — Phase 7 Source Adapter 規劃

**副標：** Source Adapter 設計規範（BATS / Hybrid / Gemini 銜接層）

**文件代號：** `BBC_TRAVEL_DATA_CENTER_SOURCE_ADAPTER_PLAN`

**版本：** Phase 7（規劃 only）

**狀態：** Planning — 本文件不觸發程式、雲端資源、資料庫或 Git 變更

**規劃主機：** 主機 A `103.1.222.14` · 專案路徑 `C:\bbc-ai-bot`

**關聯文件：**

- [BBC_TRAVEL_DATA_CENTER_GCS_DRIVE_ARCHITECTURE_PLAN.md](./BBC_TRAVEL_DATA_CENTER_GCS_DRIVE_ARCHITECTURE_PLAN.md)（Phase 1）
- [BBC_TRAVEL_DATA_CENTER_DATA_CLASSIFICATION_PLAN.md](./BBC_TRAVEL_DATA_CENTER_DATA_CLASSIFICATION_PLAN.md)（Phase 2）
- [BBC_TRAVEL_DATA_CENTER_JSON_SCHEMA_PLAN.md](./BBC_TRAVEL_DATA_CENTER_JSON_SCHEMA_PLAN.md)（Phase 3）
- [BBC_TRAVEL_DATA_CENTER_PRIVATE_SHARED_MVP_PLAN.md](./BBC_TRAVEL_DATA_CENTER_PRIVATE_SHARED_MVP_PLAN.md)（Phase 4）
- [BBC_TRAVEL_DATA_CENTER_GCS_JSON_SOURCE_READER_PLAN.md](./BBC_TRAVEL_DATA_CENTER_GCS_JSON_SOURCE_READER_PLAN.md)（Phase 5）
- [BBC_TRAVEL_DATA_CENTER_GOOGLE_SHEET_SYNC_FLOW_PLAN.md](./BBC_TRAVEL_DATA_CENTER_GOOGLE_SHEET_SYNC_FLOW_PLAN.md)（Phase 6）

**規劃日期：** 2026-05-29

**Schema 版本（沿用）：** `bbc_travel_data_center.v1`

---

## 一、文件目的

本文件定義 **BBC 旅業資料中心（BBC Travel Data Center）** 之 **Source Adapter** 設計規範，作為 **執行時資料供應適配層**，銜接上游同步/儲存與下游 AI 回覆管線。

| 上游（已規劃） | 角色 |
|----------------|------|
| **Phase 5 GCS JSON Source Reader** | 讀取 `tenants/{sno}/`、`shared/` 之 JSON 檔案 |
| **Phase 6 Google Sheet Sync Flow** | Sheet → JSON → GCS；Adapter **不讀 Sheet** |

| 下游（消費者） | 角色 |
|----------------|------|
| **BATS** | `SaaSRouter`、LINE 回覆編排 |
| **Hybrid Smart Search** | 行程搜尋 + 知識補充（**不重構核心**） |
| **Gemini 回覆流程** | Prompt context 注入 |

**Phase 7 定位：**

- Phase 5 定義 **如何讀 JSON**（Reader + Backend）  
- Phase 6 定義 **如何寫 JSON**（Sync Flow）  
- **Phase 7 定義如何以統一介面包裝各來源、回傳 `SourceAdapterResult`、合併後交給 BATS / Gemini**

**本階段僅文件**；不修改 PHP、不串 GCS、不呼叫 Google API。

---

## 二、Source Adapter 總體架構

### 2.1 層級關係

```
Phase 6 Sync          Phase 5 Reader Backend
(Sheet → GCS)              (GCS / sample)
        \                    /
         \                  /
          ▼                ▼
    ┌─────────────────────────────────┐
    │     SourceAdapterRegistry        │
    │  ┌───────────────────────────┐  │
    │  │ SourceAdapterInterface     │  │
    │  └───────────────────────────┘  │
    │   TenantPrivate │ Shared │ HostB │ FutureRag
    └──────────────┬──────────────────┘
                   │ SourceAdapterResult[]
                   ▼
    ┌─────────────────────────────────┐
    │ SourcePriorityResolver + Merger  │  （沿用 Phase 5 語意）
    └──────────────┬──────────────────┘
                   ▼
         MergedKnowledgeContext
                   ▼
    ┌─────────────────────────────────┐
    │   Gemini Prompt Context Builder  │
    └─────────────────────────────────┘
         BATS / Hybrid / Gemini
```

### 2.2 核心元件

| 元件 | 職責 |
|------|------|
| **SourceAdapterInterface** | 單一來源適配契約：`fetch(context): SourceAdapterResult` |
| **TenantPrivateSourceAdapter** | 讀 `tenants/{sno}/*/latest/*.json` |
| **SharedSourceAdapter** | 讀 `shared/*/latest/*.json` |
| **HostBSourceAdapter** | 呼叫既有 Host B API（行程、價格、庫存） |
| **FutureRagSourceAdapter** | 未來 RAG；MVP 回傳 stub |
| **SourceAdapterRegistry** | 註冊、啟用、依 tenant 解析可用 Adapter 列表 |
| **SourceAdapterResult** | 統一回傳 DTO（第七章） |

### 2.3 與 Phase 5 Source Reader 的關係

| 項目 | Phase 5 | Phase 7 |
|------|---------|---------|
| 名稱 | `KnowledgeSource` / `SourceReader` | **SourceAdapter**（對 BATS 暴露） |
| 讀檔 | `JsonFileBackend` | Tenant/Shared Adapter **內部呼叫** Reader |
| 合併 | `SourceMerger` | Adapter 之上仍用 Merger → `MergedKnowledgeContext` |

**建議實作：** Adapter 為 **薄包裝**；JSON 路徑讀取委派 Phase 5 `LocalSampleJsonBackend` / `GcsJsonBackend`。

### 2.4 建議 PHP 命名空間（規劃，Phase 7 不實作）

```
BBC\TravelDataCenter\Adapter\
  SourceAdapterInterface.php
  SourceAdapterRegistry.php
  TenantPrivateSourceAdapter.php
  SharedSourceAdapter.php
  HostBSourceAdapter.php
  FutureRagSourceAdapter.php
  Dto\SourceAdapterResult.php
  Dto\AdapterFetchContext.php
  Orchestrator\KnowledgeContextOrchestrator.php
```

---

## 三、TenantPrivateSourceAdapter

### 3.1 讀取根路徑

```
gs://bbc-travel-data-center/tenants/{sno}/
```

MVP：`samples/bbc_travel_data_center/tenants/{sno}/`

### 3.2 讀取檔案清單

| 檔案 | 路徑 | 用途 |
|------|------|------|
| `qa.json` | `qa/latest/qa.json` | 租戶 FAQ |
| `services.json` | `services/latest/services.json` | 服務、代辦費用 |
| `company_profile.json` | `company/latest/company_profile.json` | 公司介紹 |
| `rules.json` | `rules/latest/rules.json` | 退改、代辦規則 |

### 3.3 已知 travel_b（僅標記）

| 項目 | 值 |
|------|-----|
| **tenant_key** | `travel_b` |
| **tenant_sno** | `5f99b8d665e8444d` |
| **Google 來源** | Phase 6 已規劃 Drive `1gCTmLPa4ckfhWIhxoSdUZ4H1lvSS7xEe`、Sheet `1al59g7…` gid `1538130714` |
| **Phase 7** | **不連線、不讀取、不修改** Google；僅讀 GCS/sample JSON |
| **同步後路徑** | `tenants/5f99b8d665e8444d/*/latest/*.json` |

### 3.4 Adapter 行為（規劃）

```php
// 概念簽名
interface SourceAdapterInterface {
    public function getSourceType(): string; // tenant_private
    public function fetch(AdapterFetchContext $ctx): SourceAdapterResult;
}
```

| 行為 | 說明 |
|------|------|
| `topics` 過濾 | 僅載入請求之 topic（如 `qa`, `services`） |
| 缺檔 | `matched_items=[]`，`warnings[]` 含 `file_not_found` |
| `sno` 驗證 | 由 `TenantResolver` / registry 提供，**不信任 client** |

---

## 四、SharedSourceAdapter

### 4.1 讀取根路徑

```
gs://bbc-travel-data-center/shared/
```

### 4.2 讀取檔案清單

| 檔案 | 路徑 | 用途 |
|------|------|------|
| `visa.json` | `visa/latest/visa.json` | 各國簽證 |
| `passport.json` | `passport/latest/passport.json` | 護照共通知識 |
| `travel_notice.json` | `travel_notice/latest/travel_notice.json` | 旅遊公告 |
| `common_qa.json` | `qa/latest/common_qa.json` | 平台共用 FAQ |

### 4.3 已知共用來源（Phase 7 不連線）

| 類型 | 標記 |
|------|------|
| Drive | `1aHxIxLRqB749TKpeeR_USEoMS12ExgHG` |
| Sheet | `1Nasxl2nKHnI5crEeVDddRslDkS9t0vaUEqok_5Qva8w` |
| 性質 | **Shared source**；由 Phase 6 Sync 寫入 GCS |

### 4.4 Adapter 行為

- `source_type`: `shared`
- 無 `tenant_sno`（或為 `null`）
- 所有租戶共用同一組 shared JSON（唯讀）

---

## 五、HostBSourceAdapter

### 5.1 職責

**Host B** 仍為 **正式業務資料** 權威來源；Adapter 包裝既有 API client（如 `TourSearchApiClient`），**不新增 SQL 直連**。

| 資料類型 | 範例 |
|----------|------|
| 商品 / 行程 | package 列表、標題、URL |
| 出團日 | departure_date |
| 價格 | retail_price |
| 可售數量 | available_seats |
| 訂單 metadata | 訂單狀態指標（**無旅客 PII**） |

### 5.2 主機 B 邊界（再次明確）

| 允許經 API 回傳 | 禁止在 Host B 儲存 |
|-----------------|-------------------|
| 業務欄位、庫存、價格 | 大檔二進位 |
| 輕量 metadata | 旅客姓名、生日、身分證、護照、手機 |
| package_id、sno | 長篇 QA 全文 |

### 5.3 Adapter 觸發條件

| 情境 | 是否呼叫 HostBSourceAdapter |
|------|----------------------------|
| 使用者問「六月底東京團」 | ✅（Hybrid 主路徑亦可直接呼叫；Adapter 供統一合併） |
| 使用者問「護照代辦費」 | ❌（以 TenantPrivate + Shared 為主） |
| `options.include_host_b=false` | 跳過 |

### 5.4 與 Hybrid 的差異

- **Hybrid Smart Search**：條件解析 + 搜尋編排（**核心不變**）  
- **HostBSourceAdapter**：在 **Knowledge Orchestrator** 內提供 **統一結構化結果**，供與 QA/簽證 knowledge 合併

---

## 六、FutureRagSourceAdapter

### 6.1 未來範圍

| 項目 | 路徑 |
|------|------|
| Tenant RAG | `tenants/{sno}/rag/` |
| Shared RAG | `shared/rag/` |
| 輸出 | `matched_items` 含 chunk、`confidence`、引用 id |

### 6.2 MVP / Phase 7 行為

```json
{
  "source_type": "future_rag",
  "source_name": "FutureRagSourceAdapter",
  "priority": 4,
  "matched_items": [],
  "warnings": [{ "code": "not_implemented", "message": "RAG adapter stub" }],
  "error_code": null
}
```

- **不影響** 目前 MVP  
- Registry 預設 **disabled** 或 `enabled=false`

---

## 七、Source Adapter 回傳格式

### 7.1 `SourceAdapterResult` 欄位

| 欄位 | 類型 | 必填 | 說明 |
|------|------|------|------|
| **source_type** | enum | ✅ | `tenant_private` \| `shared` \| `host_b` \| `future_rag` |
| **source_name** | string | ✅ | 如 `TenantPrivateSourceAdapter` |
| **tenant_sno** | string \| null | 條件 | private / host_b 必填；shared 為 null |
| **priority** | int | ✅ | 1=tenant, 2=shared, 3=host_b, 4=rag |
| **confidence** | float 0~1 | 否 | 匹配信心；RAG 為主用 |
| **matched_items** | array | ✅ | 結構化命中項（見 7.2） |
| **context_text** | string | 否 | 供 Gemini 直接拼接之摘要正文 |
| **metadata** | object | 否 | 路徑、hash、version、api_latency_ms |
| **warnings** | array | 否 | `{ code, message, path? }` |
| **error_code** | string \| null | 否 | 致命錯誤時非 null；部分失敗用 warnings |

### 7.2 `matched_items[]` 結構（規劃）

```json
{
  "item_id": "qa_cancel_policy",
  "item_type": "qa",
  "topic": "faq_copy",
  "title": "取消行程如何退費？",
  "content": "出發前 14 天可全額退費…",
  "gcs_path": "tenants/5f99b8d665e8444d/qa/latest/qa.json",
  "score": 1.0
}
```

### 7.3 完整範例

```json
{
  "source_type": "tenant_private",
  "source_name": "TenantPrivateSourceAdapter",
  "tenant_sno": "5f99b8d665e8444d",
  "priority": 1,
  "confidence": 0.95,
  "matched_items": [
    {
      "item_id": "svc_passport_agency",
      "item_type": "service",
      "topic": "service_copy",
      "title": "護照代辦",
      "content": "費用 NT$800，約 7 工作天",
      "gcs_path": "tenants/5f99b8d665e8444d/services/latest/services.json"
    }
  ],
  "context_text": "【本公司服務】護照代辦費用 NT$800…",
  "metadata": {
    "schema_version": "bbc_travel_data_center.v1",
    "files_read": ["services/latest/services.json"],
    "read_at": "2026-05-29T12:00:00Z"
  },
  "warnings": [],
  "error_code": null
}
```

---

## 八、Source Priority 與合併規則

### 8.1 預設優先順序

| 順序 | Adapter | priority 值 |
|------|---------|---------------|
| **1** | TenantPrivateSourceAdapter | 1 |
| **2** | SharedSourceAdapter | 2 |
| **3** | HostBSourceAdapter | 3 |
| **4** | FutureRagSourceAdapter | 4 |

### 8.2 衝突規則

| 衝突 | 規則 |
|------|------|
| **private vs shared**（同 `item_id` / 同 topic） | **private 優先**，shared 項目不進 `merged_items` |
| **private vs shared**（互補 topic） | **合併**（如 shared 護照效期 + private 代辦費） |
| **knowledge vs host_b**（價格、庫存） | **host_b 優先** |
| **rag vs 其他** | 未來：rag 補充引用，不覆蓋 host_b 價格 |

### 8.3 合併產出

多個 `SourceAdapterResult` → **SourceMerger**（Phase 5）→ **`MergedKnowledgeContext`**

| 欄位 | 說明 |
|------|------|
| `knowledge_blocks[]` | 合併後區塊 |
| `merge_audit[]` | 記錄 winner source_type |
| `context_text` | 各 Adapter `context_text` 按 priority 拼接 |

### 8.4 範例：護照共通知識 + 代辦費用

| Adapter | 提供 |
|---------|------|
| SharedSourceAdapter | `passport.json` → 效期共通知識 |
| TenantPrivateSourceAdapter | `services.json` → 代辦 NT$800 |
| **合併結果** | 兩段皆保留；若 `rules.json` 與 `passport.json` 效期敘述衝突 → **tenant rules 覆蓋** |

---

## 九、與 Hybrid Smart Search 的銜接

### 9.1 原則：不重構 Hybrid 核心

| 保留不變 | 說明 |
|----------|------|
| **Intent / 條件解析** | `HybridSearchConditionBuilder` |
| **Host B API 搜尋** | `TourSearchApiClient` |
| **Gemini 回覆編排** | `TourPromptContextService` 主路徑 |

### 9.2 新增：僅資料來源 Adapter

```
使用者訊息
    │
    ├─► [既有] Hybrid Smart Search
    │         Intent Parser → Host B API → 行程結果
    │
    └─► [新增] KnowledgeContextOrchestrator
              TenantPrivateSourceAdapter
              SharedSourceAdapter
              （視意圖）HostBSourceAdapter
              → MergedKnowledgeContext
    │
    ▼
TourPromptContextService / TourLineReplyComposer
    （合併 Hybrid 結果 + Knowledge context）
    ▼
Gemini / Fixed Formatter
```

### 9.3 意圖分流（規劃）

| 意圖類型 | Hybrid | Tenant/Shared Adapter |
|----------|--------|------------------------|
| 找團 / 價格 / 日期 | ✅ 主路徑 | 可選補充 |
| FAQ / 簽證 / 護照 / 代辦 | 不取代 | ✅ 主路徑 |
| 混合問句 | ✅ + ✅ | 並行後合併 |

---

## 十、與 Gemini Prompt Context 的銜接

### 10.1 轉換鏈

```
SourceAdapterResult[]（各 Adapter）
        ↓
   SourceMerger
        ↓
   MergedKnowledgeContext
        ↓
   GeminiPromptContextBuilder
        ↓
   prompt 區塊（system / user 補充）
        ↓
   Gemini API
```

### 10.2 Prompt 區塊建議（規劃）

```
## 租戶專屬知識（優先）
{tenant_private context_text}

## 平台共用知識
{shared context_text}

## 行程搜尋結果（若有）
{hybrid / host_b 摘要}

---
使用者問題：{user_message}
```

### 10.3 明確禁止

| 禁止 | 說明 |
|------|------|
| Gemini 直讀 Google Sheet | 僅用 Sync 後 JSON / `context_text` |
| Gemini 直讀 GCS API | 經 Adapter + Builder 轉文字 |
| 在 prompt 放入旅客 PII | Sheet 個資不進 GCS、不進 prompt |

---

## 十一、錯誤處理

| 情境 | error_code | 行為 |
|------|------------|------|
| **GCS JSON 不存在** | `FILE_NOT_FOUND` | 該 Adapter `matched_items=[]`；`warnings`；嘗試 fallback |
| **JSON 格式錯誤** | `JSON_PARSE_ERROR` | 不採用該檔；保留上次 cache（若有） |
| **tenant sno 不存在** | `TENANT_NOT_FOUND` | 中止 private adapter；僅 shared + host_b |
| **source disabled** | `SOURCE_DISABLED` | 跳過該 Adapter |
| **shared fallback** | — | tenant 無 qa 時讀 `common_qa.json` |
| **context too large** | `CONTEXT_TRUNCATED` | 依 priority 截斷低優先項；記錄 `warnings` |

### 11.1 降級策略

```
TenantPrivate 失敗 → 仍嘗試 Shared
Shared 失敗 → 仍嘗試 Host B（若需要）
全部知識失敗 → Hybrid-only / Gemini 通用回答（既有行為）
```

### 11.2 不可 fatal 的情境

- 單一 JSON 缺檔 **不應** 導致整條 LINE webhook 500  
- 記錄 `error_code` + 結構化 log 供維運

---

## 十二、Cache 設計

| 層級 | 名稱 | TTL（規劃） | 說明 |
|------|------|-------------|------|
| **L0** | memory cache | request 內 | 同請求重複讀同 path |
| **L1** | file cache | 60s（MVP） | 主機 A 本機 `cache/tdc/json/` |
| **L2** | GCS JSON etag cache | 5~15 min | manifest `hash` / `updated_at` 比對 |
| **L3** | Gemini Context Cache metadata | 依 API | `cache_id`；**不存** 全文於 Host B |

### 12.1 Cache Key

```
tdc:adapter:{source_type}:{tenant_sno|shared}:{relative_path}:{content_hash}
```

### 12.2 Cache Miss / Rebuild

| 事件 | 動作 |
|------|------|
| miss | Adapter 讀 Backend → 填 L0/L1 |
| Phase 6 sync 後 hash 變 | 失效 L1/L2；下次 miss 重建 |
| Gemini cache 過期 | Phase 8：從 GCS `qa.json` 重建 |

---

## 十三、MVP 實作建議

### 13.1 第一版範圍

| 項目 | MVP |
|------|-----|
| 正式 GCS | ❌ 不串 |
| Backend | `LocalSampleJsonBackend` |
| 根目錄 | `samples/bbc_travel_data_center/` |
| Adapters | TenantPrivate + Shared（HostB 可 mock） |
| FutureRag | stub |
| Hybrid 核心 | **不改** |

### 13.2 目錄模擬

```
samples/bbc_travel_data_center/
├── tenants/5f99b8d665e8444d/    # travel_b
│   ├── qa/latest/qa.json
│   ├── services/latest/services.json
│   └── rules/latest/rules.json
└── shared/
    ├── passport/latest/passport.json
    └── qa/latest/common_qa.json
```

### 13.3 測試案例

| # | 測試 | 預期 |
|---|------|------|
| 1 | private 覆蓋 shared 同 qa_id | merged 僅 tenant 項 |
| 2 | passport(shared) + 代辦(tenant) | 兩者皆在 `context_text` |
| 3 | 缺 tenant qa | fallback `common_qa` |
| 4 | `SourceAdapterResult` 欄位齊全 | schema 符合第七章 |
| 5 | `KnowledgeContextOrchestrator` | 不改 Hybrid 單元測試仍綠 |

### 13.4 建議注入點（規劃，不實作）

| 檔案（既有） | 注入方式 |
|--------------|----------|
| `core/tour_prompt_context_service.php` | 可選依 flag 呼叫 `KnowledgeContextOrchestrator` |
| `core/saas_router.php` | 傳 `tenant_sno` 至 context 建構 |

**Feature flag（規劃）：** `travel_data_center.adapter_enabled=false`（預設關）

---

## 十四、Phase 8 建議

| Phase | 主題 | 交付重點 |
|-------|------|----------|
| **Phase 8** | Gemini Context Cache 接入設計 | 高頻 QA 選取、`cache_id` manifest、Sync hash 驅動失效 |
| **Phase 9** | Hybrid Smart Search Integration | 並行 Hybrid + Adapter；統一 reply 組裝 |
| **Phase 10** | Travel Data Center Production Rollout | 正式 GCS、Google Sync 排程、監控、全租戶 |

---

## 附錄 A：Adapter 與 Phase 5/6 對照

| Phase | 元件 | Phase 7 對應 |
|-------|------|--------------|
| 5 | `SourceReader` | 內部由 Adapter 呼叫 |
| 5 | `SourceMerger` | Orchestrator 使用 |
| 6 | Sync → GCS | Adapter 讀取產物 |
| 7 | `SourceAdapterInterface` | 對 BATS 暴露之邊界 |

## 附錄 B：文件修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-29 | Phase 7 初版：Source Adapter 規劃 |

---

*本文件為設計規範，不構成實作承諾。程式變更須通過 code review 與 feature flag 分階段上線。*
