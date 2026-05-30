# BBC 旅業資料中心 — Phase 8：Gemini Context Cache 接入設計

**副標：** Gemini Context Cache 接入設計規範

**文件代號：** `TRAVEL_DATA_CENTER_PHASE8_GEMINI_CONTEXT_CACHE_DESIGN`

**版本：** Phase 8（規劃 only）

**狀態：** Planning — 本文件不觸發程式、雲端資源、資料庫或 Git 變更

**規劃主機：** 主機 A `103.1.222.14` · 專案路徑 `C:\bbc-ai-bot`

**關聯文件：**

- [BBC_TRAVEL_DATA_CENTER_GCS_DRIVE_ARCHITECTURE_PLAN.md](./BBC_TRAVEL_DATA_CENTER_GCS_DRIVE_ARCHITECTURE_PLAN.md)（Phase 1）
- [BBC_TRAVEL_DATA_CENTER_JSON_SCHEMA_PLAN.md](./BBC_TRAVEL_DATA_CENTER_JSON_SCHEMA_PLAN.md)（Phase 3）
- [BBC_TRAVEL_DATA_CENTER_GCS_JSON_SOURCE_READER_PLAN.md](./BBC_TRAVEL_DATA_CENTER_GCS_JSON_SOURCE_READER_PLAN.md)（Phase 5）
- [BBC_TRAVEL_DATA_CENTER_GOOGLE_SHEET_SYNC_FLOW_PLAN.md](./BBC_TRAVEL_DATA_CENTER_GOOGLE_SHEET_SYNC_FLOW_PLAN.md)（Phase 6）
- [BBC_TRAVEL_DATA_CENTER_SOURCE_ADAPTER_PLAN.md](./BBC_TRAVEL_DATA_CENTER_SOURCE_ADAPTER_PLAN.md)（Phase 7）

**規劃日期：** 2026-05-29

**Schema 版本（沿用）：** `bbc_travel_data_center.v1`

---

## 1. Phase 8 目標

| 目標 | 說明 |
|------|------|
| **定義角色** | 釐清 Gemini Context Cache 在 BBC 旅業資料中心中的定位：加速層，非權威資料層 |
| **定義邊界** | 明確哪些資料可快取、哪些必須即時讀 Host B |
| **設計接入點** | 從 GCS JSON（Sync 產物）到 Cache 建立、LINE 查詢消費之端到端流程 |
| **風險與成本** | TTL、失效、token 成本、個資隔離 |
| **MVP 策略** | **先做設計 + feature flag**；**不立即正式啟用** Context Cache API |

**Phase 8 交付物：** 本設計文件。  
**Phase 8 不交付：** PHP 實作、`.env` 變更、SQL、正式 Cache 建立、Google API 呼叫。

---

## 2. Gemini Context Cache 在 BBC 旅業資料中心的角色

### 2.1 三層資料模型（複習）

```
┌─────────────────────────────────────────────────────────┐
│  Layer 3：Gemini Context Cache（加速，可重建）            │
├─────────────────────────────────────────────────────────┤
│  Layer 2：GCS latest/*.json（契約層，權威）               │
├─────────────────────────────────────────────────────────┤
│  Layer 1：Google Sheet / Drive（人工協作，輸入層）        │
└─────────────────────────────────────────────────────────┘
         Layer 0：Host B API（業務事實：價格、庫存）
```

### 2.2 Context Cache 是什麼 / 不是什麼

| 是 | 不是 |
|----|------|
| 高頻、相對穩定知識的 **預載上下文** | 正式資料庫或 GCS 替代品 |
| 降低重複 token 與延遲的 **加速層** | RAG 向量索引 |
| 可由 GCS JSON **重建** 的快照 | 即時價格 / 庫存來源 |
| 仅存 **cache_id** 等 metadata 於 Host B / manifest | 旅客個資儲存處 |

### 2.3 與 Phase 7 Source Adapter 的關係

```
GCS JSON（權威）
    ├─► Source Adapter → MergedKnowledgeContext → Gemini（無 Cache 路徑，永遠可用）
    └─► Context Cache Builder → cachedContents/{id} → Gemini（有 Cache 時優先引用）
```

- **Cache miss：** 回退 Source Adapter 讀 GCS JSON（與現有規劃一致）  
- **Cache hit：** 使用者問題仍動態傳入；Cache 承載 **背景知識**，非完整回答  

---

## 3. 適合快取的資料類型

以下資料經 **Phase 6 Sync** 轉為 JSON 後，內容 **變更頻率低、重複查詢高**，適合預載入 Context Cache。

### 3.1 共用層（shared/）

| 類型 | 來源（協作層） | GCS 路徑（規劃） | 快取理由 |
|------|----------------|------------------|----------|
| **共用 Google Sheet 轉出** | 平台 Sheet（如 `1Nasxl2n…`） | `shared/qa/latest/common_qa.json` 等 | 跨租戶 FAQ、更新週期長 |
| **共用 Drive 文件摘要** | 平台 Drive 資料夾 | `shared/visa/`、`passport/`、`travel_notice/` JSON | 簽證、護照、公告文字穩定 |
| **平台政策類長上下文** | 管理者維護 | `shared/*/latest/*.json` | 單次載入、多次查詢 |

### 3.2 租戶層（tenants/{sno}/）

| 類型 | 來源（協作層） | GCS 路徑（規劃） | 快取理由 |
|------|----------------|------------------|----------|
| **私有 Google Sheet JSON** | travel_b Sheet 等 | `qa/`、`services/`、`rules/` `latest/*.json` | 租戶 FAQ、代辦說明高頻 |
| **私有 Drive 文件摘要** | 租戶 Drive 資料夾 | `parsed/` 或 `latest/` 摘要欄位 | 公司介紹、長政策 |
| **tenant policy** | `rules.json` | `tenants/{sno}/rules/latest/rules.json` | 退改、簽證代辦規則 |
| **pricing（靜態報價表）** | Sheet 中 **非即時** 之價目說明 | `services.json` 內說明性價格 | 僅「代辦費用表」類，非動態庫存價 |
| **passport / visa（租戶補充）** | 租戶 rules / qa | `rules.json`、`qa.json` | 覆蓋 shared 的租戶專屬敘述 |
| **QA** | Sheet QA 分頁 | `qa/latest/qa.json` | 最高頻快取候選 |

### 3.3 快取內容組裝（規劃）

單一租戶 Cache 條目可合併：

```
tenant:{sno}:bundle:v1  →  qa + services + rules + company_profile（精簡正文）
shared:bundle:v1        →  passport + visa + common_qa + travel_notice（平台級）
```

**原則：** Cache 內容為 **已脫敏、已驗證** 之 GCS JSON 正文拼接，**不含** Sheet 原始列之 PII。

---

## 4. 不適合快取的資料類型

| 類型 | 原因 | 正確來源 |
|------|------|----------|
| **即時庫存** | 秒級變動 | Host B API |
| **即時價格** | 與出團日綁定 | Host B API / Hybrid |
| **報名人數** | 短期變動 | Host B API / Excel sync 後仍不宜長 TTL Cache |
| **短期變動通知** | 時效性 | `travel_notice` 可短 TTL；緊急公告走即時推送另案 |
| **客戶個資與報名資料** | 合規 | **僅 Google Sheet**；不進 GCS 明文、不進 Cache |
| **行程搜尋結果列表** | 查詢相關 | Hybrid + Host B，每次請求 |
| **Gemini 動態生成全文** | 非穩定 | 不寫回 Cache |

**規則：** 若欄位在 Phase 3 標記為 **Host B 業務事實優先**，則 **禁止** 僅依 Cache 回答。

---

## 5. 共用層 shared/ 與租戶層 tenants/{sno}/ 的 Cache Key 命名策略

### 5.1 Key 格式

```
tdc:gcc:{scope}:{identifier}:{bundle}:{version}
```

| 段 | 說明 | 範例 |
|----|------|------|
| `tdc` | 固定前綴（Travel Data Center） | `tdc` |
| `gcc` | Gemini Context Cache | `gcc` |
| `scope` | `shared` \| `tenant` | `tenant` |
| `identifier` | `shared` 或 `{tenant_sno}` | `5f99b8d665e8444d` |
| `bundle` | 內容分組 | `knowledge_core` \| `qa_only` \| `policy_only` |
| `version` | 內容 hash 前 8 碼或遞增整數 | `v3_a1b2c3d4` |

### 5.2 範例

| Cache Key | 對應 GCS |
|-----------|----------|
| `tdc:gcc:shared:platform:knowledge_core:v1` | `shared/passport/` + `shared/visa/` + `shared/qa/` |
| `tdc:gcc:tenant:5f99b8d665e8444d:qa_only:v2_b8f91e2a` | `tenants/5f99b8d665e8444d/qa/latest/qa.json` |
| `tdc:gcc:tenant:5f99b8d665e8444d:knowledge_core:v1` | qa + services + rules + company |

### 5.3 Gemini API 對照

| 內部 key | 外部儲存 |
|----------|----------|
| `tdc:gcc:…` | Google `cachedContents/{cache_id}` |
| manifest | `tenants/{sno}/cache/context_cache_manifest.json` 或 Host B metadata |

**manifest 紀錄：** `cache_key` → `gemini_cache_id` → `source_hashes[]` → `expires_at`

---

## 6. Cache TTL / 失效策略

### 6.1 TTL 建議

| Bundle | 預設 TTL | 說明 |
|--------|----------|------|
| `shared:knowledge_core` | 7 天 | 平台共用知識 |
| `tenant:qa_only` | 24～72 小時 | 租戶 FAQ |
| `tenant:knowledge_core` | 48 小時 | 綜合租戶包 |
| `tenant:policy_only` | 7 天 | 退改規則變更少 |

**緊急覆蓋：** 管理者可設 `ttl_override=0` 強制下次請求 rebuild。

### 6.2 失效觸發

| 觸發 | 動作 |
|------|------|
| Phase 6 Sync 成功且 **content hash 變更** | 標記 `invalidated=true`；背景 job rebuild |
| TTL 到期 | 懶重建：下次 LINE 請求時建立新 Cache |
| 手動 purge | API / CLI 刪除 `gemini_cache_id` + 清 manifest |
| tenant 停用 | 不建立、不讀取該 sno Cache |
| Validator 失敗 | 不更新 Cache；保留舊 Cache 直至 TTL（可配置為立即失效） |

### 6.3 重建流程

```
invalidated 或 expired
    ↓
讀 GCS latest/*.json（權威）
    ↓
組裝 cache payload（token 上限內截斷）
    ↓
呼叫 Gemini Cache Create API
    ↓
寫 manifest（cache_id, hash, expires_at）
    ↓
後續請求帶 cachedContent 引用
```

---

## 7. Google Drive / Google Sheet → JSON → GCS → Gemini Context Cache 流程

### 7.1 完整管線

```
┌──────────────────┐
│ Google Sheet     │  人工編輯（Phase 6 不連線）
│ Google Drive     │
└────────┬─────────┘
         │ Sync Worker（Phase 6）
         ▼
┌──────────────────┐
│ JSON Transformer │
│ JSON Validator   │
└────────┬─────────┘
         ▼
┌──────────────────┐
│ GCS Writer       │  tenants/{sno}/*/latest/*.json
│                  │  shared/*/latest/*.json
└────────┬─────────┘
         │ hash / version 變更事件
         ▼
┌──────────────────┐
│ Cache Builder    │  Phase 8（feature flag OFF 時跳過）
│ (主機 A)         │
└────────┬─────────┘
         ▼
┌──────────────────┐
│ Gemini Context   │  cachedContents/{id}
│ Cache API        │
└────────┬─────────┘
         ▼
┌──────────────────┐
│ manifest         │  tenants/{sno}/cache/context_cache_manifest.json
│ Host B metadata  │  僅 cache_id、sync_status、hash
└──────────────────┘
```

### 7.2 與讀取路徑分離

| 路徑 | 時機 | 說明 |
|------|------|------|
| **寫入管線** | Sync 後 / 定時 | 更新 Cache |
| **讀取管線** | LINE 每則訊息 | 先查 manifest → hit 則引用 Cache；miss 則 Source Adapter 讀 GCS |

**Gemini 永遠不直讀 Sheet / Drive。**

---

## 8. LINE OA 查詢時如何使用 Context Cache

### 8.1 請求流程（規劃）

```
LINE Webhook → SaaSRouter (BATS)
    ↓
TenantResolver → tenant_sno
    ↓
Intent 分流（FAQ / 簽證 / 找團 / 混合）
    ↓
┌─ 知識類意圖 ─────────────────────────────────────┐
│ 1. ContextCacheResolver.lookup(sno, bundle)      │
│ 2. hit  → Gemini generateContent(cachedContent=…) │
│ 3. miss → Source Adapter → GCS JSON → Gemini      │
│ 4. （可選）async 觸發 Cache rebuild               │
└──────────────────────────────────────────────────┘
┌─ 行程搜尋意圖 ─────────────────────────────────┐
│ Hybrid Smart Search → Host B（不用 Cache 庫存價）   │
│ + 可選 tenant/shared Cache 作為背景政策 FAQ       │
└──────────────────────────────────────────────────┘
    ↓
TourLineReplyComposer / Formatter → LINE 回覆
```

### 8.2 租戶隔離

| 規則 | 說明 |
|------|------|
| **Cache 不跨租戶** | `tenant:{sno}` key 綁定 registry sno |
| **shared Cache** | 平台級一組；所有租戶可引用 **唯讀** |
| **合併順序** | tenant Cache 優先於 shared Cache（與 Source Priority 一致） |

### 8.3 與固定 formatter 的關係

- 已啟用 **fixed formatter** 且無需 Gemini 改寫時，**可不查 Cache**  
- 需 Gemini 補充說明（長政策、QA）時，才載入 Cache  

---

## 9. 與未來 RAG、AI Ranking、Hybrid Smart Search / BATS 的關係

### 9.1 關係總表

| 系統 | 與 Context Cache 關係 |
|------|------------------------|
| **GCS JSON** | 權威；Cache 由此重建 |
| **Context Cache** | 加速；不取代 GCS |
| **Future RAG** | 長尾、多跳檢索；Cache 載 **高頻靜態**；RAG 載 **廣泛知識** |
| **AI Ranking** | 未來對 Hybrid 結果重排；**不讀 Cache** 作排名依據 |
| **Hybrid Smart Search** | 行程搜尋核心不變；Cache 僅 **背景知識** 補充 |
| **BATS** | 編排層決定是否啟用 Cache（feature flag） |
| **Phase 7 Source Adapter** | Cache miss 時必經 Adapter |

### 9.2 層級圖

```
BATS (編排)
  ├── Hybrid Smart Search → Host B（即時行程）
  ├── Source Adapter → GCS JSON（知識 miss）
  ├── Context Cache → Gemini（知識 hit）
  └── Future RAG → chunks（未來，Phase 9+）
```

### 9.3 讀取優先（知識題）

1. Tenant Context Cache（若 hit）  
2. Tenant GCS JSON（Adapter）  
3. Shared Context Cache / Shared GCS  
4. Host B（僅業務事實）  
5. Future RAG（補充）  

---

## 10. 成本、效能、風險控管

### 10.1 成本

| 項目 | 說明 |
|------|------|
| **Cache 建立** | 一次性 token 計費；應控制 bundle 大小 |
| **Cache 命中** | 降低重複輸入 token；需監控 hit rate |
| **Rebuild 頻率** | Sync 過於頻繁會增加建立成本；建議 debounce（如 5 分鐘內只 rebuild 一次） |
| **Storage** | Google Cache 儲存費用；manifest 本地極小 |

### 10.2 效能

| 指標 | 目標（規劃） |
|------|--------------|
| Cache hit 延遲 | 較全量 JSON 注入降低 30%+ 首 token 時間 |
| miss 降級 | 與現有 Adapter 路徑相同，無額外硬依賴 |
| 並發 rebuild | 同 sno 單飛（mutex），避免重複建立 |

### 10.3 風險控管

| 風險 | 控管 |
|------|------|
| **Stale 回答** | hash 驅動失效 + TTL + 業務事實仍查 Host B |
| **個資洩漏** | Cache payload 禁止 PII；審計 manifest |
| **跨租戶洩漏** | cache_key 強制含 sno；程式 assert |
| **Cache 過大被截斷** | 分 bundle；優先 qa + services |
| **API 失敗** | 降級 GCS JSON；不阻斷 LINE 回覆 |
| **成本暴衝** | feature flag + 每租戶每日 rebuild 上限 |

---

## 11. MVP 階段建議

### 11.1 本階段（Phase 8 文件）

| 項目 | 狀態 |
|------|------|
| 設計文件 | ✅ 本文件 |
| 程式實作 | ❌ 不做 |
| 正式啟用 Cache API | ❌ 不做 |

### 11.2 Feature Flag（規劃）

```php
// 規劃鍵，Phase 8 不寫入 config.php
'travel_data_center.gemini_context_cache_enabled' => false,
'travel_data_center.gemini_context_cache_tenant_allowlist' => [], // 如 ['5f99b8d665e8444d']
```

### 11.3 MVP 實作階段建議（Phase 8+ 程式）

| 步驟 | 內容 |
|------|------|
| 1 | 僅 manifest 假資料 + 單元測試 CacheResolver 邏輯 |
| 2 | sample JSON → 組裝 payload 大小估算 |
| 3 | travel_b allowlist 單租戶試點 |
| 4 | 監控 hit/miss、cost |
| 5 | 全租戶 rollout |

### 11.4 MVP 不做的項目

- 正式 Google Cache API 批次建立（flag OFF）  
- 旅客名單 Sheet 進 Cache  
- 以 Cache 回答即時價格 / 庫存  
- 取代 RAG 或重構 Hybrid  

---

## 12. Phase 9 建議下一步

| Phase | 主題 | 交付重點 |
|-------|------|----------|
| **Phase 9** | Hybrid Smart Search Integration | 並行 Hybrid 結果 + Knowledge Context；統一 reply 組裝；意圖分流 |
| **Phase 10** | Travel Data Center Production Rollout | 正式 GCS、Sync 排程、Cache 啟用、監控儀表板 |
| **Phase 11+** | RAG + AI Ranking | 向量索引；Hybrid 結果重排；與 Cache 互補 |

**Phase 9 前置條件建議：**

- Phase 7 Source Adapter MVP（sample JSON）可穩定合併  
- Phase 8 manifest  schema 定案  
- `travel_data_center.gemini_context_cache_enabled` 仍預設 **false** 直至 Production Rollout  

---

## 附錄 A：manifest 範例

**路徑：** `tenants/5f99b8d665e8444d/cache/context_cache_manifest.json`

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "tenant_sno": "5f99b8d665e8444d",
  "entries": [
    {
      "cache_key": "tdc:gcc:tenant:5f99b8d665e8444d:knowledge_core:v2_b8f91e2a",
      "gemini_cache_id": "cachedContents/abc123",
      "bundle": "knowledge_core",
      "source_gcs_paths": [
        "tenants/5f99b8d665e8444d/qa/latest/qa.json",
        "tenants/5f99b8d665e8444d/services/latest/services.json"
      ],
      "source_content_hash": "sha256:combined…",
      "expires_at": "2026-06-05T00:00:00Z",
      "invalidated": false,
      "created_at": "2026-05-29T06:00:00Z"
    }
  ]
}
```

## 附錄 B：文件修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-29 | Phase 8 初版：Gemini Context Cache 接入設計 |
| 1.1 | 2026-05-29 | 新增 §13 附錄 C：BATS、商品來源與 Multi-Source Search |

---

# §13 附錄 C：BATS、商品來源與 Multi-Source Search

> **定位：** Phase 8 **主體**仍為 **Gemini Context Cache**（§1～§12）。  
> 本附錄定義 **商品來源註冊、租戶商品源、主機 A 本機快取、多來源搜尋 URL、BATS 多來源搜尋** 之架構邊界，建議列入 **Phase 9 MVP 實作範圍**。

---

## C.1 Product Source Registry

### C.1.1 用途

**Product Source Registry（商品來源註冊表）** 用於登錄與管理 **可搜尋商品／行程／旅遊產品** 的 **資料來源與 URL 產出規則**，供 BATS、Hybrid Smart Search、Multi-Source Search URL Builder 在 **查詢當下** 選擇正確來源，而非管理 FAQ、簽證等 **知識文本**。

| 職責 | 說明 |
|------|------|
| 註冊商品源 | 平台支援哪些外部／內部商品 API、列表頁、搜尋端點 |
| 綁定租戶 | 哪些 `tenant_sno` 啟用哪些 `source_id` |
| 對應 URL 模板 | 各源之 Search URL 建構方式 |
| 對應 category | `group_tour`、`hotel` 等（見 C.2） |

### C.1.2 與 Phase 7 SourceAdapterRegistry 的差異

| 維度 | **SourceAdapterRegistry**（Phase 7） | **Product Source Registry**（本附錄） |
|------|--------------------------------------|----------------------------------------|
| **管理對象** | **知識來源** | **商品來源** |
| **典型內容** | QA、簽證、護照、規則、服務說明 | 商品搜尋、外部商品源、Search URL、Host B 商品 |
| **讀取層** | `tenants/{sno}/qa/`、`shared/visa/` 等 JSON | Host B API、GCS `packages/`、外部 GR P/BBC Travel 等 |
| **消費場景** | Gemini 知識 context、FAQ 回答 | 找團、比價、列表連結、Hybrid 搜尋 |
| **與 Cache** | Context Cache **可**快取其產出之知識 | **不**進 Context Cache 作庫存 |

**SourceAdapterRegistry 管理：**

- 知識來源  
- QA  
- 簽證  
- 護照  
- 規則  
- 服務說明  

**Product Source Registry 管理：**

- 商品來源  
- 商品搜尋  
- 外部商品源  
- Search URL  
- Host B 商品來源  
- 未來 GCS 商品 JSON（`tenants/{sno}/latest/packages/`）

### C.1.3 明確邊界：Gemini Context Cache 不是 Product Source

| 項目 | 規範 |
|------|------|
| **Gemini Context Cache** | **不是** Product Source Registry 中的一筆來源 |
| **角色** | 僅能做 **知識快取**（QA、政策、靜態服務說明等） |
| **禁止** | 將 Context Cache 視為 **商品庫存來源** 或 **即時價格／可售** 依據 |
| **查詢商品** | 必須走 Product Source → Host B / 外部 API / GCS package JSON（非 Cache 內容） |

---

## C.2 Product Category 擴充設計

### C.2.1 設計原則

**Product Source Registry 不得設計成僅支援團體行程（group_tour）的專用表。**

必須保留可擴充欄位：

- **`product_category`**（單一類別），或  
- **`product_categories`**（多類別陣列）

於 registry、catalog、tenant 啟用檔中一致使用。

### C.2.2 目前與未來類別

| 代碼 | 說明 | 狀態 |
|------|------|------|
| **`group_tour`** | 團體行程 | **目前主力** |
| **`hotel`** | 飯店 | 未來 |
| **`car_rental`** | 租車 | 未來 |
| **`fit`** | 自由行（FIT） | 未來 |
| **`private_group`** | 小包團 | 未來 |
| **`other`** | 其他旅遊商品 | 未來 |

### C.2.3 擴充時對核心流程的要求

未來新增 `product_category` 時：

- **不應** 修改 Hybrid Smart Search **條件解析核心**（`HybridSearchConditionBuilder`）  
- **不應** 修改 Tenant Resolver、LINE Formatter **主流程**  
- **應** 透過 registry + adapter + URL template **增量註冊** 新類別  

---

## C.3 未來新增商品源類別的低重構原則

### C.3.1 架構規範

新增商品源類別（飯店、租車、自由行、小包團、其他）時，**必須評估程式重構面積是否最小**。

**目標：只需**

| 增量項 | 說明 |
|--------|------|
| 新增 **config** | 類別定義、URL 基底、feature flag |
| 新增 **registry** | `product_source_catalog` / tenant 啟用 |
| 新增 **adapter** | 該源之搜尋與 URL 建構 adapter |
| 新增 **url template** | 各源列表／詳情 URL 規則 |

**不需**

| 禁止項 | 說明 |
|--------|------|
| 重寫 **BATS** | SaaSRouter 僅增量編排 |
| 重寫 **Hybrid Search** | 核心保留；可選 category 參數向下傳 |
| 重寫 **Tenant Resolver** | `sno` 仍為權威 |
| 重寫 **LINE Formatter** | 回覆格式不變 |
| 重寫 **Gemini Context Cache** | 與商品類別無關 |

### C.3.2 擴充模式（強制優先）

優先採用：

1. **Config-Based** — 類別與源定義在 JSON/config  
2. **Registry-Based** — 平台 catalog + 租戶啟用清單  
3. **Adapter-Based** — 每源一個 `ProductSourceAdapter` 實作  

### C.3.3 新增商品源類別前檢查清單

每次新增商品源類別或新 `source_id` 前，必須填寫：

| # | 檢查項 | 是/否 | 備註 |
|---|--------|-------|------|
| 1 | 需新增哪些 **config**？ | | |
| 2 | 需新增哪些 **registry** 條目？ | | |
| 3 | 需新增哪些 **adapter**？ | | |
| 4 | 是否影響 **SearchUrlBuilder**？ | | 僅新增 template 分支則可接受 |
| 5 | 是否影響 **LINE Formatter**？ | | 應為否 |
| 6 | 是否影響 **Context Cache**？ | | 應為否（知識層獨立） |
| 7 | 是否需要 **DB schema** 變更？ | | 優先僅 Host B metadata / GCS |

**通過標準：** 1～3 有明確答案；4 僅擴充；5～6 為「否」或「僅設定」；7 為「否」或「僅 metadata」。

---

## C.4 Tenant Product Sources

### C.4.1 平台商品源目錄（正式來源 · GCS）

**路徑（規劃）：**

```
gs://bbc-travel-data-center/shared/config/product_source_catalog.json
```

**用途：** 定義平台 **支援哪些商品源**（全域 catalog），供 Product Source Registry 載入。

**範例結構（規劃）：**

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "sources": [
    {
      "source_id": "grp",
      "display_name": "GRP 商品源",
      "product_categories": ["group_tour"],
      "adapter": "GrpProductSourceAdapter",
      "url_template_id": "grp_search_v1"
    },
    {
      "source_id": "bbctravel",
      "display_name": "BBC Travel",
      "product_categories": ["group_tour", "fit"],
      "adapter": "BbcTravelProductSourceAdapter",
      "url_template_id": "bbctravel_search_v1"
    },
    {
      "source_id": "tourcenter",
      "display_name": "TourCenter",
      "product_categories": ["group_tour"],
      "adapter": "TourCenterProductSourceAdapter",
      "url_template_id": "tourcenter_search_v1"
    },
    {
      "source_id": "agenttour",
      "display_name": "AgentTour",
      "product_categories": ["group_tour"],
      "adapter": "AgentTourProductSourceAdapter",
      "url_template_id": "agenttour_search_v1"
    },
    {
      "source_id": "future_hotel_provider",
      "display_name": "飯店源（預留）",
      "product_categories": ["hotel"],
      "adapter": "StubProductSourceAdapter",
      "enabled": false
    },
    {
      "source_id": "future_car_provider",
      "display_name": "租車源（預留）",
      "product_categories": ["car_rental"],
      "adapter": "StubProductSourceAdapter",
      "enabled": false
    }
  ]
}
```

### C.4.2 租戶啟用（正式來源 · GCS）

**路徑（規劃）：**

```
gs://bbc-travel-data-center/tenants/{sno}/config/product_sources.json
```

**用途：** 記錄 **該旅行社啟用了哪些商品源**（`source_id` 子集 + 優先權）。

**travel_b 範例：**

| 項目 | 值 |
|------|-----|
| **tenant_key** | `travel_b` |
| **tenant_sno** | `5f99b8d665e8444d` |
| **可啟用（規劃）** | `grp`、`bbctravel`、`tourcenter` |

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "tenant_sno": "5f99b8d665e8444d",
  "enabled_sources": [
    { "source_id": "grp", "priority": 10, "product_categories": ["group_tour"] },
    { "source_id": "bbctravel", "priority": 20, "product_categories": ["group_tour", "fit"] },
    { "source_id": "tourcenter", "priority": 30, "product_categories": ["group_tour"] }
  ],
  "default_category": "group_tour",
  "updated_at": "2026-05-29T00:00:00Z"
}
```

### C.4.3 與 Tenant Private Knowledge 的區分

| 維度 | **Tenant Product Sources** | **Tenant Private Knowledge** |
|------|----------------------------|------------------------------|
| **路徑** | `config/product_sources.json` | `qa/`、`services/`、`rules/` |
| **用途** | 去哪裡 **搜商品**、產 **Search URL** | 如何 **回答政策/FAQ** |
| **即時性** | 庫存/價格走 Host B / API | 靜態知識；可進 Context Cache |
| **Phase 7 Adapter** | Product Source Adapter（規劃） | TenantPrivateSourceAdapter |

**Tenant Product Sources ≠ Tenant Private Knowledge**（不可混用 registry 或快取 bundle）。

---

## C.5 Host A Local Cache

### C.5.1 正式建議

| 層級 | 角色 |
|------|------|
| **GCS** | **正式資料** 權威儲存（catalog、tenant 啟用、package JSON） |
| **Host A Local Cache** | 查詢時 **避免每次即時讀 GCS**，提高 LINE OA 回覆速度 |
| **Gemini Context Cache** | 知識加速（§5～§6）；**與本節商品 cache 分離** |

### C.5.2 路徑與流程

**規劃路徑（主機 A）：**

```
C:\bbc-ai-bot\cache\tenants\{sno}\product_sources.json
```

（另可選：`cache\shared\product_source_catalog.json` 鏡像平台 catalog）

**流程：**

```
GCS（shared/config/product_source_catalog.json
     tenants/{sno}/config/product_sources.json）
        ↓
   同步 Worker / 啟動時 refresh / Sync 後事件
        ↓
Host A Local Cache（product_sources.json 等）
        ↓
LINE OA 查詢 → Product Source Registry 讀本機 cache
        ↓
Host B / SearchUrlBuilder / 外部 adapter
```

**架構目標：** 提高回覆速度；降低每次 webhook 對 GCS 的延遲依賴。

### C.5.3 禁止寫入 Host A Local Cache 的內容

| 禁止項 | 原因 |
|--------|------|
| **PII** | 合規 |
| **即時庫存** | 必須即時查 Host B / API |
| **即時價格** | 同上 |
| **報名人數** | 短期變動 |

本機 cache 僅鏡像 **registry 設定與靜態 catalog**，不鏡像 **搜尋結果列表**。

### C.5.4 與 Gemini Context Cache 的分工

| Cache 類型 | 路徑語意 | 內容 |
|------------|----------|------|
| Host A Local | `cache\tenants\{sno}\product_sources.json` | 商品 **源設定** |
| Host A Local（知識） | `cache\tdc\json\…`（Phase 5） | 知識 JSON 副本 |
| Gemini Context | `cachedContents/…` | 知識 **prompt 快取** |

---

## C.6 Multi-Source Search URL Builder

### C.6.1 現況與目標

**現況（程式庫）：** `SearchUrlBuilder` 主要產出 **cloud_store_tourdate** 搜尋 URL（`bonusmee.com/view/cloud/cloud_store_tourdate.php`），與 `ApiQueryMapper` 共用 canonical 參數。

**目標（規劃）：** **Multi-Source Search URL Builder** 可針對同一查詢條件，依 **租戶啟用之 Product Source**，同時或擇一產生多個搜尋網址。

### C.6.2 未來可產生的搜尋網址類型（規劃）

| 來源類型 | 範例 |
|----------|------|
| **Host B** | API 回傳之 `detail_url` / `search_url` |
| **cloud_store_tourdate** | 既有 `SearchUrlBuilder` |
| **GRP** | GRP 列表搜尋 URL |
| **BBC Travel** | bbctravel 搜尋端點 |
| **TourCenter** | tourcenter 搜尋端點 |
| **飯店來源** | `product_category=hotel` 之模板 |
| **租車來源** | `product_category=car_rental` |
| **自由行來源** | `product_category=fit` |
| **小包團來源** | `product_category=private_group` |

### C.6.3 SearchUrlBuilder 擴充參數（規劃）

未來 **SearchUrlBuilder**（或 `MultiSourceSearchUrlBuilder` facade）需支援：

| 參數 | 說明 |
|------|------|
| **`tenant_sno`** | 租戶權威 |
| **`product_category`** | `group_tour` / `hotel` / … |
| **`keyword`** | 搜尋關鍵字 |
| **`destination`** | 目的地 |
| **`date`** / `dateFrom` / `dateTo` | 出團日區間 |
| **`source_type`** / **`source_id`** | 指定單一商品源 |

**保留 Adapter 設計：** 每 `source_id` 對應 `ProductSearchUrlAdapter`，Builder 負責路由與合併輸出。

```json
{
  "urls": [
    { "source_id": "host_b", "product_category": "group_tour", "url": "https://…" },
    { "source_id": "grp", "product_category": "group_tour", "url": "https://…" }
  ]
}
```

### C.6.4 與 Context Cache 的邊界

- **Context Cache 不參與 URL 組裝**  
- **URL Builder 不依賴 Context Cache**  
- URL 產出依 **即時查詢條件 + Product Source Registry + Host B**

---

## C.7 BATS Multi-Source Search 關聯

### C.7.1 BATS 能力拆分（規劃）

BATS（`SaaSRouter` 編排層）應將下列能力 **拆分為獨立模組與 feature flag**，避免耦合成單一開關：

| 模組 / Flag | 職責 |
|-------------|------|
| **`hybrid_search`** | Hybrid Smart Search + Host B 行程搜尋（既有） |
| **`product_source_registry`** | 載入 catalog + tenant `product_sources.json` |
| **`search_url_builder`** | Multi-Source Search URL 產出 |
| **`knowledge_context`** | Phase 7 Source Adapter（知識 GCS JSON） |
| **`gemini_context_cache_enabled`** | Phase 8 知識 Context Cache（本文件主體） |

### C.7.2 Multi-Source Search 編排（規劃）

```
LINE Webhook
    ↓
SaaSRouter (BATS)
    ├─ TenantResolver → tenant_sno
    ├─ [hybrid_search] HybridSearchConditionBuilder → TourSearchApiClient → Host B
    ├─ [product_source_registry] 讀 Host A cache / GCS → 啟用源清單
    ├─ [search_url_builder] Multi-Source URLs（不依賴 Context Cache）
    ├─ [knowledge_context] Source Adapter → GCS JSON
    ├─ [gemini_context_cache_enabled] Context Cache lookup（僅知識）
    └─ TourLineReplyComposer / Formatter → LINE 回覆
```

### C.7.3 明確定義

| 規則 | 說明 |
|------|------|
| **Context Cache 不參與 URL 組裝** | URL 來自 Host B / Builder / 外部商品 adapter |
| **URL Builder 不依賴 Context Cache** | 無 cache hit 亦須能產 URL |
| **商品搜尋不依賴 Context Cache** | 庫存/價格永遠即時源 |
| **知識回答可選 Cache** | 與商品流平行 |

### C.7.4 與 Phase 8 主體的關係

- `gemini_context_cache_enabled=false` 時，BATS 行為應與現網（如 `5010444`）一致。  
- `product_source_registry` / `search_url_builder` 可獨立於 Cache 分階段上線（Phase 9）。

---

## C.8 結論

| 項目 | 說明 |
|------|------|
| **Phase 8 主體** | 仍為 **Gemini Context Cache**（§1～§12、附錄 A/B） |
| **本附錄目的** | 定義商品與搜尋側架構，避免與知識 Cache 混淆 |
| **本附錄涵蓋** | Product Source Registry、Tenant Product Sources、Host A Local Cache、Multi-Source Search URL Builder、BATS Multi-Source Search、商品類別擴充策略 |
| **建議實作階段** | **Phase 9 MVP**（sample catalog、本機 cache、URL builder 分支、feature flag；Cache 仍預設 OFF） |

**Phase 9 MVP 建議範圍（摘要）：**

1. `shared/config/product_source_catalog.json` + `tenants/{sno}/config/product_sources.json`（sample）  
2. `C:\bbc-ai-bot\cache\tenants\{sno}\product_sources.json` 同步邏輯  
3. `SearchUrlBuilder` 擴充設計評估（`product_category`、`source_id`）  
4. BATS 編排拆分與 flag 文件化  
5. **不** 在 Phase 9 正式啟用 Gemini Context Cache（除非另案 rollout）  

---

*本文件為設計規範。正式啟用 Context Cache 須完成資安審查、feature flag 分階段 rollout 與成本監控。*
