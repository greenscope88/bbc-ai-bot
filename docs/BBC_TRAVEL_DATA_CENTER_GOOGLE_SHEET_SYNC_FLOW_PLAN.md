# BBC 旅業資料中心 — Phase 6 Google Sheet Sync Flow 規劃

**副標：** Google Sheet / Drive → GCS JSON 同步流程設計規範

**文件代號：** `BBC_TRAVEL_DATA_CENTER_GOOGLE_SHEET_SYNC_FLOW_PLAN`

**版本：** Phase 6（規劃 only）

**狀態：** Planning — 本文件不觸發程式、雲端資源、資料庫或 Git 變更

**規劃主機：** 主機 A `103.1.222.14` · 專案路徑 `C:\bbc-ai-bot`

**關聯文件：**

- [BBC_TRAVEL_DATA_CENTER_GCS_DRIVE_ARCHITECTURE_PLAN.md](./BBC_TRAVEL_DATA_CENTER_GCS_DRIVE_ARCHITECTURE_PLAN.md)（Phase 1）
- [BBC_TRAVEL_DATA_CENTER_DATA_CLASSIFICATION_PLAN.md](./BBC_TRAVEL_DATA_CENTER_DATA_CLASSIFICATION_PLAN.md)（Phase 2）
- [BBC_TRAVEL_DATA_CENTER_JSON_SCHEMA_PLAN.md](./BBC_TRAVEL_DATA_CENTER_JSON_SCHEMA_PLAN.md)（Phase 3）
- [BBC_TRAVEL_DATA_CENTER_PRIVATE_SHARED_MVP_PLAN.md](./BBC_TRAVEL_DATA_CENTER_PRIVATE_SHARED_MVP_PLAN.md)（Phase 4）
- [BBC_TRAVEL_DATA_CENTER_GCS_JSON_SOURCE_READER_PLAN.md](./BBC_TRAVEL_DATA_CENTER_GCS_JSON_SOURCE_READER_PLAN.md)（Phase 5）

**規劃日期：** 2026-05-29

**Schema 版本（沿用）：** `bbc_travel_data_center.v1`

---

## 一、文件目的

本文件定義 **BBC 旅業資料中心（BBC Travel Data Center）** 之 **Google Sheet Sync Flow** 設計規範，描述如何將 **Google Sheets / Drive** 上的人工維護資料，經讀取、轉換、驗證後寫入 **GCS 標準 JSON**，並更新 **Host B metadata**，最終供下游 **Source Reader → BATS / Gemini / Hybrid / Future RAG** 消費。

**核心原則：**

| 原則 | 說明 |
|------|------|
| **Sheet 是協作層** | 人工編輯；非正式 AI 查詢層 |
| **GCS JSON 是契約層** | 機器只讀 `latest/*.json` |
| **個資不進 Host B** | 旅客名單等僅留 Sheet（若含個資） |
| **Phase 6 僅文件** | 不連線、不讀取、不修改任何 Google 資源 |

**供以下系統共用（規劃）：**

- **GCS** — 正式 JSON 儲存  
- **Gemini** — Prompt / Context Cache 素材來源  
- **BATS** — LINE 回覆 context  
- **Hybrid Smart Search** — 知識補充（**不直讀 Sheet**）  
- **Future RAG** — 索引素材來自 GCS，非 Sheet API  

---

## 二、Sync Flow 架構總覽

### 2.1 端到端流程

```
Google Sheet / Drive（人工協作層）
        ↓
   Sheet Reader          ← Google Sheets API（Phase 7+ 實作）
        ↓
   JSON Transformer      ← tenant mapping → bbc_travel_data_center.v1
        ↓
   JSON Validator        ← schema / 必要欄位
        ↓
   GCS Writer            ← tenants/{sno}/ 或 shared/
        ↓
   Metadata Writer       ← Host B：sheet_id、sync_status、hash（無個資）
        ↓
   Source Reader         ← Phase 5：TenantPrivateSource / SharedSource
        ↓
   Gemini / BATS / Hybrid（消費 GCS JSON）
```

### 2.2 元件職責

| 元件 | 職責 | 執行位置 |
|------|------|----------|
| **Sheet Reader** | 依 `sheet_id`、`gid`、列範圍讀取儲存格 | 主機 A Sync Worker |
| **JSON Transformer** | 列 → 物件；套用 `tenant_mapping` | 主機 A |
| **JSON Validator** | `schema_version`、必要欄位、型別 | 主機 A |
| **GCS Writer** | 寫入 `*/latest/*.json` + 可選 snapshot | 主機 A → GCS |
| **Metadata Writer** | 更新 Host B / manifest | 主機 A → Host B API |
| **Source Reader** | 讀取已同步 JSON（不呼叫 Sheet） | 主機 A（請求路徑） |

### 2.3 資料流與讀取邊界

```
[Sync 路徑]  Sheet → GCS（寫入，批次/觸發）
[Read 路徑]  GCS → Source Reader → Gemini（執行時，不經 Sheet）
```

---

## 三、Tenant Private Sync Flow

### 3.1 已知 travel_b 私有來源（僅標記 · Phase 6 不連線）

| 項目 | 內容 |
|------|------|
| **tenant（內部 key）** | `travel_b` |
| **tenant_sno** | `5f99b8d665e8444d` |
| **性質** | **Tenant private source** |
| **不是** | **Shared source** |
| **Phase 6** | **不連線、不讀取、不修改** |

**travel_b 私有 Google Drive 上層資料夾：**

```
https://drive.google.com/drive/folders/1gCTmLPa4ckfhWIhxoSdUZ4H1lvSS7xEe?usp=drive_link
```

| 解析欄位（規劃） | 值 |
|------------------|-----|
| `drive_folder_id` | `1gCTmLPa4ckfhWIhxoSdUZ4H1lvSS7xEe` |
| `tenant_key` | `travel_b` |
| `tenant_sno` | `5f99b8d665e8444d` |
| `scope` | `tenant` |

**travel_b 私有 Google Sheet：**

```
https://docs.google.com/spreadsheets/d/1al59g7_h_VmeZiL3K0LZpbTf9GdWj5PL92WFSCs1kP8/edit?gid=1538130714#gid=1538130714
```

| 解析欄位（規劃） | 值 |
|------------------|-----|
| `spreadsheet_id` | `1al59g7_h_VmeZiL3K0LZpbTf9GdWj5PL92WFSCs1kP8` |
| `gid` | `1538130714` |
| `parent_drive_folder_id` | `1gCTmLPa4ckfhWIhxoSdUZ4H1lvSS7xEe`（規劃關聯） |

### 3.2 同步目標（GCS）

**根路徑：**

```
gs://bbc-travel-data-center/tenants/5f99b8d665e8444d/
```

**同步後標準 JSON（`latest/`）：**

| 輸出檔 | GCS 路徑 |
|--------|----------|
| `qa.json` | `tenants/5f99b8d665e8444d/qa/latest/qa.json` |
| `services.json` | `tenants/5f99b8d665e8444d/services/latest/services.json` |
| `company_profile.json` | `tenants/5f99b8d665e8444d/company/latest/company_profile.json` |
| `rules.json` | `tenants/5f99b8d665e8444d/rules/latest/rules.json` |

**快照（可選）：**

```
tenants/5f99b8d665e8444d/sheets_json/latest/
├── manifest.json
└── 1al59g7_h_VmeZiL3K0LZpbTf9GdWj5PL92WFSCs1kP8/
    └── snapshot_{yyyyMMddTHHmmssZ}.json   # 脫敏或結構化列，不含旅客 PII 明文
```

### 3.3 Tenant Private Sync 流程（規劃）

```
1. Sync Trigger（手動 / 定時 / 未來 webhook）
2. 載入 registry：travel_b → sno、sheet_id、gid、mapping_profile_id
3. Sheet Reader 讀取指定 gid 分頁
4. 依分頁語意分流：
      - 分頁「QA」        → Transformer → qa.json
      - 分頁「服務項目」  → services.json
      - 分頁「公司介紹」  → company_profile.json
      - 分頁「規則」      → rules.json
5. JSON Validator 逐檔驗證
6. GCS Writer 寫入 latest/（原子：先寫 tmp 再 promote）
7. Metadata Writer：sync_status=synced、hash、version、updated_at
8. （可選）L2 cache manifest 失效
```

### 3.4 讀取優先權（同步後）

**travel_b 私有層** 優先於 **shared**；衝突時 **tenant private 覆蓋 shared**（由 Phase 5 SourcePriorityResolver 執行，非 Sync 職責）。

---

## 四、Shared Sync Flow

### 4.1 已知共用來源（僅標記 · Phase 6 不連線）

| 項目 | 內容 |
|------|------|
| **性質** | **Shared source** |
| **不是** | **Tenant source** |
| **維護者** | BBC / 管理者 |
| **Phase 6** | **不連線、不讀取、不修改** |

**共用 Google Drive 資料夾：**

```
https://drive.google.com/drive/folders/1aHxIxLRqB749TKpeeR_USEoMS12ExgHG?usp=drive_link
```

| 解析欄位 | 值 |
|----------|-----|
| `drive_folder_id` | `1aHxIxLRqB749TKpeeR_USEoMS12ExgHG` |
| `scope` | `shared` |

**共用 Google Sheet：**

```
https://docs.google.com/spreadsheets/d/1Nasxl2nKHnI5crEeVDddRslDkS9t0vaUEqok_5Qva8w/edit?usp=sharing
```

| 解析欄位 | 值 |
|----------|-----|
| `spreadsheet_id` | `1Nasxl2nKHnI5crEeVDddRslDkS9t0vaUEqok_5Qva8w` |
| `scope` | `shared` |

### 4.2 同步目標（GCS）

**根路徑：**

```
gs://bbc-travel-data-center/shared/
```

**同步後標準 JSON：**

| 輸出檔 | GCS 路徑 |
|--------|----------|
| `visa.json` | `shared/visa/latest/visa.json` |
| `passport.json` | `shared/passport/latest/passport.json` |
| `travel_notice.json` | `shared/travel_notice/latest/travel_notice.json` |
| `common_qa.json` | `shared/qa/latest/common_qa.json` |

### 4.3 Shared Sync 流程（規劃）

```
1. 管理者觸發或定時任務（BBC 服務帳號）
2. Sheet Reader 讀取共用 Sheet 各分頁
3. Transformer 依「平台 mapping」（非 tenant mapping）
4. Validator → GCS Writer → shared/*/latest/
5. Metadata Writer（平台級 source_id，無 tenant_sno）
6. 所有租戶 Source Reader 皆可讀取 shared（唯讀）
```

---

## 五、JSON Transformer

### 5.1 轉換流程

```
Google Sheet（列 × 欄）
        ↓
   標題列 → 欄位 key 對照（tenant mapping）
        ↓
   資料列 → 物件陣列
        ↓
   組裝 bbc_travel_data_center.v1 JSON 文件
```

### 5.2 Tenant Mapping

**問題：** 不同旅行社 Sheet **欄位名、順序、分頁名** 可不同。

**解法：** 每租戶一份 **mapping profile**（規劃路徑）：

```
tenants/{sno}/config/sheet_mapping_{profile_id}.json
```

**Mapping 範例（規劃）：**

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "profile_id": "travel_b_default_v1",
  "tenant_sno": "5f99b8d665e8444d",
  "spreadsheet_id": "1al59g7_h_VmeZiL3K0LZpbTf9GdWj5PL92WFSCs1kP8",
  "sheets": [
    {
      "gid": "1538130714",
      "target_json": "qa.json",
      "header_row": 1,
      "data_start_row": 2,
      "columns": {
        "問題": "question",
        "答案": "answer",
        "標籤": "tags"
      },
      "row_to_item": {
        "id_column": "qa_id",
        "id_prefix": "qa_"
      }
    }
  ]
}
```

| 轉換目標 | 來源分頁語意（範例） |
|----------|----------------------|
| `qa.json` | QA 分頁 |
| `services.json` | 服務項目 |
| `company_profile.json` | 公司介紹（可單列 key-value） |
| `rules.json` | 規則、退改 |

**Shared mapping：** 使用 `shared/config/platform_sheet_mapping_v1.json`，**不含 tenant_sno**。

### 5.3 特殊列處理

| 情境 | 處理 |
|------|------|
| 空列 | 跳過 |
| 合併儲存格 | Reader 展開後再 map |
| 含旅客 PII 欄位 | **不寫入** qa/services/rules JSON；旅客名單 Sheet 另案（不同 sync profile） |
| 多 gid | 一 gid 對一 `target_json` 或合併規則 |

---

## 六、JSON Validator

### 6.1 驗證層級

| 層級 | 檢查內容 |
|------|----------|
| **格式** | 合法 JSON、UTF-8 |
| **Schema** | `schema_version`、`$schema` 類型 |
| **必要欄位** | 見 Phase 3 各檔定義 |
| **型別** | 價格為 number、日期 ISO、boolean |
| **業務** | `qa_id` 唯一、`is_active` 預設 true |

### 6.2 各檔必要欄位（摘要）

| 檔案 | 必要欄位 |
|------|----------|
| `qa.json` | `schema_version`, `items[]`, `items[].qa_id`, `question`, `answer` |
| `services.json` | `services[]`, `services[].service_id`, `name` |
| `company_profile.json` | `company_name` |
| `rules.json` | `rules[]`, `rules[].rule_id`, `content` |
| `visa.json` | `countries[]` 或 `scope: shared` |
| `passport.json` | `topics[]` |
| `travel_notice.json` | `notices[]` |
| `common_qa.json` | 同 `qa.json`，`scope: shared` |

### 6.3 驗證失敗處理

| 失敗類型 | 處理 |
|----------|------|
| **單列錯誤** | 跳過該列 + 寫入 `sync_warnings[]`；其餘列繼續 |
| **整檔缺必要欄位** | **不寫入 GCS**；保留上一版 `latest`；`sync_status=failed` |
| **Schema 版本不符** | `failed` + 告警；不 promote |
| **空檔** | 若允許空陣列則寫入並標記 `empty_sync`；否則 `failed` |

**Metadata 紀錄：**

```json
{
  "sync_status": "failed",
  "last_sync_error": "validator: missing required field items[].qa_id at row 12",
  "validation_errors": [{ "row": 12, "field": "qa_id", "code": "required" }]
}
```

---

## 七、Metadata 設計

### 7.1 欄位定義

| 欄位 | 說明 | 存放 |
|------|------|------|
| **source_id** | 邏輯來源 ID，如 `travel_b_sheet_main` | Host B |
| **sheet_id** | Google `spreadsheet_id` | Host B |
| **sheet_name** | 人類可讀名稱 | Host B |
| **gid** | 工作表 gid（選用） | Host B |
| **tenant_sno** | 租戶（shared 為 null） | Host B |
| **scope** | `tenant` \| `shared` | Host B |
| **updated_at** | 最後成功同步 UTC | Host B |
| **sync_status** | 見下表 | Host B |
| **version** | 遞增整數，每次成功 sync +1 | Host B |
| **hash** | 寫入 GCS 之 JSON 內容 SHA-256 | Host B |
| **gcs_path** | 如 `tenants/…/qa/latest/qa.json` | Host B |
| **mapping_profile_id** | 使用的 mapping | Host B |

### 7.2 sync_status 枚舉

| 值 | 說明 |
|----|------|
| `pending` | 待同步 |
| `syncing` | 進行中 |
| `synced` | 成功 |
| `partial` | 部分分頁成功 |
| `failed` | 失敗 |
| `disabled` | 停用 |

### 7.3 Host B 只存 metadata

- **不存** Sheet 全文、長篇 QA 內容、旅客 PII  
- **不存** 大檔二進位  
- GCS 為 JSON 全文權威；Host B 僅 **指標 + 狀態**

### 7.4 GCS manifest（可選）

**路徑：** `tenants/{sno}/sheets_json/latest/manifest.json`

```json
{
  "schema_version": "bbc_travel_data_center.v1",
  "tenant_sno": "5f99b8d665e8444d",
  "sources": [
    {
      "source_id": "travel_b_sheet_main",
      "sheet_id": "1al59g7_h_VmeZiL3K0LZpbTf9GdWj5PL92WFSCs1kP8",
      "last_sync_at": "2026-05-29T06:00:00Z",
      "sync_status": "synced",
      "version": 3,
      "hash": "sha256:…",
      "outputs": ["qa/latest/qa.json", "services/latest/services.json"]
    }
  ]
}
```

---

## 八、Sync Trigger

### 8.1 觸發類型

| 類型 | 說明 | 實作 Phase |
|------|------|------------|
| **手動同步** | 後台按鈕 / CLI `tdc:sync --sno=…` | MVP / Phase 7 |
| **定時同步** | cron 每 N 分鐘（租戶可配置） | Phase 7+ |
| **Webhook 同步** | Google Apps Script / Drive push → 主機 A endpoint | 未來 Phase 10 |

### 8.2 MVP 建議

| 項目 | MVP |
|------|-----|
| 觸發 | **僅手動**（CLI 或單一 admin API） |
| 範圍 | 單一 `tenant_sno` 或 `scope=shared` |
| Google API | **不呼叫**；以 Sample Sheet → Sample JSON 離線模擬 |
| 排程 | 不做 cron |

### 8.3 觸發流程（規劃）

```
Trigger
  → 建立 sync_job_id
  → metadata sync_status=syncing
  → 執行 Reader → Transformer → Validator → Writer
  → 成功：synced + version++ + hash
  → 失敗：failed + last_sync_error + Retry 策略
```

---

## 九、Error Handling

### 9.1 錯誤類型與處理

| 錯誤 | 處理 | sync_status |
|------|------|-------------|
| **Google Sheet 不存在** | 記錄錯誤；不更新 GCS；告警 | `failed` |
| **權限不足** | 同上；檢查服務帳號 | `failed` |
| **格式錯誤**（標題列不符 mapping） | 中止該分頁；其他分頁可 `partial` | `partial` / `failed` |
| **JSON 驗證失敗** | 不 promote `latest` | `failed` |
| **GCS 寫入失敗** | Retry；仍失敗則 `failed` | `failed` |
| **Host B metadata 寫入失敗** | GCS 已成功則標記 `partial`；排程補寫 metadata | `partial` |

### 9.2 Retry Flow

```
Attempt 1 → 失敗
    ↓ wait 30s
Attempt 2 → 失敗
    ↓ wait 120s
Attempt 3 → 失敗
    ↓
sync_status=failed
alert + 保留上次成功 version/hash
（不刪除 GCS 既有 latest）
```

| 參數（規劃） | 值 |
|--------------|-----|
| max_attempts | 3 |
| backoff | 30s, 120s, 300s |
| idempotent | 以 `hash` 比對，相同內容可 skip 寫入 |

---

## 十、與 Source Reader 關係

### 10.1 銜接 Phase 5

| 階段 | 職責 |
|------|------|
| **Phase 6 Sync** | Sheet → **寫入** GCS `latest/*.json` |
| **Phase 5 Reader** | **讀取** GCS，不呼叫 Sheet API |

### 10.2 Reader 讀取路徑

**TenantPrivateSource** 讀取（Sync 完成後）：

```
tenants/5f99b8d665e8444d/qa/latest/qa.json
tenants/5f99b8d665e8444d/services/latest/services.json
tenants/5f99b8d665e8444d/company/latest/company_profile.json
tenants/5f99b8d665e8444d/rules/latest/rules.json
```

**SharedSource** 讀取：

```
shared/visa/latest/visa.json
shared/passport/latest/passport.json
shared/travel_notice/latest/travel_notice.json
shared/qa/latest/common_qa.json
```

### 10.3 一致性

- Sync 成功後應 **失效** Reader L1/L2 cache（Phase 5 第八章）  
- `hash` / `version` 變更 → Reader 下次強制重讀  

---

## 十一、與 Hybrid Smart Search 關係

| 項目 | 說明 |
|------|------|
| **Hybrid 不讀 Sheet** | `TourSearchApiClient` 等 **不呼叫** Google Sheets API |
| **Hybrid 不讀 Drive** | 行程搜尋仍走 Host B |
| **知識補充** | 若需 QA / 簽證 context → **Source Reader 讀 GCS JSON** |
| **整合點** | `TourPromptContextService` 合併 Hybrid 結果 + `MergedKnowledgeContext`（Phase 9） |

```
使用者問行程 → Hybrid → Host B
使用者問簽證/代辦 → Source Reader → GCS JSON（來自 Phase 6 Sync）
```

---

## 十二、與 Gemini Context Cache 關係

### 12.1 資料流

```
GCS JSON（qa.json 等，Sync 產出）
        ↓
   選取高頻 qa_id（規則或人工配置）
        ↓
   建立 Gemini Context Cache（Phase 8）
        ↓
   寫入 cache manifest（cache_id、expires_at、source hash）
```

### 12.2 定位

| 項目 | 說明 |
|------|------|
| **GCS JSON** | 權威；Sync 更新後可重建 Cache |
| **Context Cache** | 加速；**不取代** GCS JSON、**不取代** RAG |

### 12.3 Cache 失效與重建

| 事件 | 動作 |
|------|------|
| Sync 後 `hash` 變更 | 標記 `invalidated=true` |
| `expires_at` 到期 | 背景 job 讀 GCS 重建 |
| 手動 purge | 刪除 cache entry，下次請求 lazy rebuild |
| Sync `failed` | **不更新** Cache；沿用舊 cache（若未過期） |

---

## 十三、主機 A / 主機 B 分工

### 13.1 主機 A

| 職責 | 元件 |
|------|------|
| **Sync** | Sync Worker、Trigger 處理 |
| **Transform** | JSON Transformer + mapping |
| **Validate** | JSON Validator |
| **Write JSON** | GCS Writer |
| **Read（執行時）** | Source Reader（非 Sync 路徑） |

### 13.2 主機 B

| 允許 | 禁止 |
|------|------|
| Metadata（第七章） | 旅客姓名 |
| Sync Status | 出生年月日 |
| Reference（sheet_id、url） | 身分證 |
| version、hash、gcs_path | 護照號碼 |
| | 長篇 QA 全文、大檔 |

### 13.3 分工示意

```
主機 A：Sync Pipeline + GCS 全文
主機 B：狀態機 + 指標（給維運與增量 Sync 判斷）
```

---

## 十四、MVP 建議

### 14.1 離線模擬鏈

```
Sample Sheet（CSV / 固定 xlsx 檔，不呼叫 Google）
        ↓
Sample JSON Transformer（PHP 單元測試或腳本，Phase 7 才寫程式）
        ↓
Sample GCS Path（本地目錄）
        samples/bbc_travel_data_center/tenants/5f99b8d665e8444d/…
        samples/bbc_travel_data_center/shared/…
        ↓
Source Reader（LocalSampleJsonBackend，Phase 5 MVP）
        ↓
驗證 priority / merge / fallback
```

### 14.2 Sample 檔案建議

| 檔案 | 路徑 |
|------|------|
| 模擬 travel_b Sheet | `samples/sheets/travel_b_main_gid1538130714.csv` |
| 模擬共用 Sheet | `samples/sheets/shared_platform.csv` |
| mapping | `samples/mappings/travel_b_default_v1.json` |
| 預期輸出 | `samples/bbc_travel_data_center/tenants/5f99b8d665e8444d/qa/latest/qa.json` |

### 14.3 驗證流程（規劃）

| # | 步驟 | 預期 |
|---|------|------|
| 1 | CSV → Transformer | 產出合法 `qa.json` |
| 2 | Validator | pass |
| 3 | 寫入 sample GCS 路徑 | 檔案存在 |
| 4 | Metadata 寫入（mock Host B） | `sync_status=synced` |
| 5 | Source Reader 讀取 | `TenantPrivateSource` 取得 items |
| 6 | 與 shared 合併 | tenant 覆蓋衝突 QA |

### 14.4 MVP 不做

- 正式 Google OAuth / Sheets API  
- 正式 GCS bucket  
- Webhook trigger  
- 旅客名單 Sheet 含 PII 同步至 GCS  

---

## 十五、後續 Phase

| Phase | 主題 | 交付重點 |
|-------|------|----------|
| **Phase 7** | Source Adapter | `KnowledgeSourceInterface` 實作、注入 BATS |
| **Phase 8** | Gemini Context Cache | Sync 後 hash 驅動 cache 重建 |
| **Phase 9** | Hybrid Smart Search Integration | 搜尋 + GCS 知識並列 |
| **Phase 10** | Travel Data Center Production Rollout | 正式 bucket、Google API、cron、監控、travel_a 擴展 |

---

## 附錄 A：travel_b vs shared 來源對照

| 項目 | travel_b（Tenant Private） | 平台（Shared） |
|------|---------------------------|----------------|
| Drive | `1gCTmLPa4ckfhWIhxoSdUZ4H1lvSS7xEe` | `1aHxIxLRqB749TKpeeR_USEoMS12ExgHG` |
| Sheet | `1al59g7…` gid `1538130714` | `1Nasxl2n…` |
| GCS 根 | `tenants/5f99b8d665e8444d/` | `shared/` |
| 輸出 | qa, services, company, rules | visa, passport, travel_notice, common_qa |

## 附錄 B：文件修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-29 | Phase 6 初版：Google Sheet Sync Flow 規劃 |

---

*本文件為設計規範，不構成實作承諾。Google API 與程式變更自 Phase 7 起另開實作計畫。*
