# BATS_DATA_CONTRACT.md

**專案：** BBC AI SaaS / BATS / BDS / Travel Data Center  
**定位：** L1 架構政策層 — BDS Data Contract 正式 SSOT  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_DATA_SYNC_POLICY.md`、`BATS_DATA_SOURCE_REGISTRY.md`  
**適用範圍：** `travel_b`、`travel_c`、`travel_d` 及未來 **旅遊／飯店／餐廳／其他產業** 租戶  
**適用對象：** ChatGPT、Cursor、開發者、維運人員、資料維護人員  
**衝突處理：** 同步原則以 `BATS_DATA_SYNC_POLICY.md` 為準；來源與知識層以 `BATS_DATA_SOURCE_REGISTRY.md` 為準；**資料格式以本文件為準**。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §1.5 | Structured Knowledge Input Source |
| §1.6 | Phase 7 — Upload-Triggered Structured Knowledge |
| §1.6.5 | Upload Portal Sync Metadata Contract |
| §2 | Relationship |
| §3 | Data Category |
| §4 | Google Sheet Multi Tab Rule |
| §5 | Required Tabs |
| §6 | Output JSON Contract |
| §7 | Field Definition |
| §8 | Validation Rule |
| §9 | Tenant Isolation Rule |
| §10 | Shared Knowledge Contract |
| §11 | Anti Hardcode Rule |
| §12 | Future Roadmap |

---

## 1. Purpose

### 1.1 文件定位

本文件建立 **BDS Data Contract** 正式 SSOT，定義資料從 **Google Sheet** 經 **BDS** 轉換為 **JSON** 之正式契約。

```text
Google Sheet（Source Registry 登錄之 private_knowledge_sheet）
        ↓
      BDS（驗證、轉換）
        ↓
      JSON（GCS Knowledge Layer）
```

### 1.2 目標

| 目標 | 說明 |
|------|------|
| **格式統一** | `travel_b`、`travel_c`、`travel_d` 及未來 `hotel`、`restaurant` 等租戶使用 **同一套 Contract** |
| **避免分叉** | 禁止 per-tenant、per-industry 自訂 JSON 結構（除非修訂本 SSOT） |
| **可驗證** | BDS Validation 可依本文件機械化執行 |
| **可擴展** | `shared_knowledge`、`itinerary_data` 保留章節架構，後續補欄位 |

### 1.3 本文件不做

| 不做 | 說明 |
|------|------|
| 定義同步觸發與 Mode B 流程 | 見 `BATS_DATA_SYNC_POLICY.md` |
| 定義 GCS 實際同步管線細節 | 見 `BATS_DATA_SYNC_POLICY.md` §13、§14 |
| 定義排程 / Cron / Batch | 見 `BATS_DATA_SYNC_POLICY.md`（Mode B only） |
| 定義 Google API 權限與 Service Account | 見 `BATS_DATA_SYNC_POLICY.md` §10、§22 |
| 定義 Source Registry 結構 | 見 `BATS_DATA_SOURCE_REGISTRY.md` |
| 實作 BDS 程式 / JSON Schema 機器檔 | 實作階段另開 Phase |

### 1.4 BDS v1 MVP 正式決策

下列三項為 **BDS v1 MVP 文件定案**；實作須遵循，變更須修訂本 SSOT。

#### 決策 A — 僅接受英文欄位名稱

| 項目 | 說明 |
|------|------|
| **v1 規則** | Sheet 欄位名稱 **僅** 接受英文 snake_case（如 `question`、`answer`、`company_name`） |
| **禁止 v1** | 中文欄位別名映射（如「問題」「答案」「公司名稱」） |
| **Future** | 中文別名列入 §12 Future Roadmap |

### 1.5 Structured Knowledge Input Source

> **正式原則：** **Structured Knowledge = Google Sheet**。本 Contract 定義 **Tenant Private** Sheet → JSON；Shared 層格式見 `BATS_SHARED_KNOWLEDGE_CONTRACT.md`。

#### 1.5.1 三層 Knowledge 輸入

| 層級 | 輸入來源 | Contract SSOT | GCS 輸出 |
|------|----------|-------------|----------|
| **Tenant Private Knowledge** | Google Sheet | **本文件**（5 Tab） | `tenants/{sno}/knowledge/*.json` |
| **Industry Shared Knowledge** | Google Sheet | `BATS_SHARED_KNOWLEDGE_CONTRACT.md` | `shared/{industry_code}/knowledge/` |
| **Global Shared Knowledge** | Google Sheet | `BATS_SHARED_KNOWLEDGE_CONTRACT.md` | `shared/global/knowledge/` |

#### 1.5.2 與 Google Drive 分工

| 載體 | 角色 |
|------|------|
| **Google Sheet** | Structured Knowledge **Input Source** — FAQ、條列、表格、服務型知識 |
| **Google Drive** | Archive Source — PDF、Image、Word、PPT、DM **原件** |
| **GCS** | Runtime Knowledge Source — BATS／Gemini 消費層 |

FAQ 型、表格型、條列型知識（含護照、簽證、入境規定、行李規定等）**應優先** 以 Google Sheet 維護，經 BDS 同步至 GCS，**不必等待** PDF 解析或 RAG 階段。

### 1.6 Phase 7 — Upload-Triggered Structured Knowledge

> **Status: Pre-Planning** — 與 `BATS_DATA_SYNC_POLICY.md` §17 對齊；**本文件不實作** Upload Portal。

#### 1.6.1 正式決策

| 項目 | 說明 |
|------|------|
| **Phase 7** | **Upload-Triggered Sync** — 上傳成功觸發 BDS；**非** Cron Scheduler First |
| **唯一更新入口** | **Host A Upload Portal** — 人類更新 Structured Knowledge 的 **唯一** 正式入口 |
| **輸入格式** | **Google Sheet / Excel** — Structured Knowledge **Source Format**（本 Contract 定義 Tab／欄位／JSON） |
| **Runtime** | **GCS** — BATS／Gemini／Future RAG **僅** 消費 GCS Knowledge JSON |
| **Drive** | **Archive** — 非 Structured 更新入口；見 `BATS_DATA_SYNC_POLICY.md` §18 |

#### 1.6.2 三層適用（同一 Contract 管線）

| 層級 | Contract SSOT | 輸出 GCS 路徑 |
|------|---------------|---------------|
| **Tenant Private** | **本文件**（5 Tab / 5 JSON） | `tenants/{sno}/knowledge/` |
| **Industry Shared** | `BATS_SHARED_KNOWLEDGE_CONTRACT.md` | `shared/{industry_code}/knowledge/` |
| **Global Shared** | `BATS_SHARED_KNOWLEDGE_CONTRACT.md` | `shared/global/knowledge/` |

三層皆走：**Upload Portal → Excel → Validation（本 Contract 或 Shared Contract）→ BDS Build → GCS → Read-back**。

**Industry Shared** 由產業維護者上傳產業級 Excel；**不是** tenant-specific shared、**不是** 租戶直接改 Runtime。  
**Global Shared** 由平台管理者上傳平台級 Excel。

#### 1.6.3 與 Phase 6B 邊界

| 載體 | Phase 6B Drive Connector | Phase 7 Upload-Triggered |
|------|--------------------------|---------------------------|
| **Excel（Structured）** | 可選 Archive 原件 | **主徑** — Contract Validation → GCS JSON |
| **PDF / Image / Word** | Archive read／promote | **不在** 本 Contract；非 Phase 7 Structured 主徑 |

#### 1.6.4 Upload Portal Canonical URLs（Phase 7-0c / 7-0d）

| Portal | Canonical URL | 寫入層級 |
|--------|---------------|----------|
| **Tenant** | `https://kowanbo.com/bds/upload.php` | `tenants/{sno}/knowledge/` |
| **Shared** | `https://kowanbo.com/bds/shared_upload.php` | `shared/{industry_code}/knowledge/` |

| 共同項 | 說明 |
|--------|------|
| **Auth** | Legacy kowanbo `brandlogin.php`；`retUrl` allowlist `/bds/` |
| **bbcshops.com** | Redirect-only |
| **Tenant Pilot** | `travel_b` / `storeNo` 6180 |
| **Shared Gate** | Management Center `sno` `cff796a33d94ea31`；MVP `travel` |

**交叉引用：** `BATS_UPLOAD_PORTAL_PHASE7_MVP_PLAN.md`

#### 1.6.5 Upload Portal Sync Metadata Contract（Phase 7-0e.2 SSOT）

> **Status: SSOT 定案（2026-06-05）** — BDS 寫入與 Upload Portal UI 讀取之同步 metadata 契約。  
> **禁止：** Host B SQL 欄位／Schema／同步版本資料表。

##### 1.6.5.1 `sync_id`（Sync Version）

| 項目 | 規格 |
|------|------|
| **欄位名** | `sync_id` |
| **UI 顯示名** | 目前 AI 使用版本 |
| **格式** | `SYNC-YYYYMMDD-HHMMSS` |
| **範例** | `SYNC-20260613-153025` |
| **產生** | BDS job 於 **成功** promote 至 GCS knowledge 時產生 |
| **寫入位置** | `sync_report.json`；GCS knowledge 物件 metadata |

##### 1.6.5.2 `sync_report.json` 必填欄位（Upload Portal 相關）

| 欄位 | 說明 |
|------|------|
| `sync_id` | 同步版本（§1.6.5.1） |
| `status` | `success`／`failed` |
| `finished_at` | 最近一次 job 結束時間（ISO 8601） |
| `tenant_sno` 或 `industry_code` | scope 識別（依 Tenant／Shared） |
| `data_category` | `tenant_private_knowledge` 或 `shared_knowledge` |

##### 1.6.5.3 UI 讀取來源優先序

| 優先序 | 來源 |
|--------|------|
| 1 | GCS `sync_report.json` |
| 2 | GCS knowledge 物件 metadata |
| 3 | Host A `var/bds/` report |
| 4 | Google Drive archive metadata（**僅**輔助） |

##### 1.6.5.4 AI Data Status（UI 衍生 — 非獨立儲存欄位）

| 顯示值 | 條件 |
|--------|------|
| `已同步版本` | 現行 GCS knowledge `sync_id` = 最近 success `sync_id` |
| `仍為上一次成功同步版本` | 有歷史 success，session 尚未新 success promote |

**交叉引用：** `BATS_DATA_SYNC_POLICY.md` §17.10.3、§17.10.6；`BATS_UPLOAD_PORTAL_PHASE7_MVP_PLAN.md` §13.1、§13.6

---

#### 1.5.3 Phase 6A 邊界

| 項目 | 說明 |
|------|------|
| **Phase 6A** | 僅 **Tenant Private** Sheet → `tenants/{sno}/knowledge/` |
| **Shared Sheet 同步** | **未實作**；本節為架構 SSOT，不變更 6A 範圍 |

---

#### 決策 B — 整檔 Fail 策略

| 項目 | 說明 |
|------|------|
| **v1 規則** | 任一 Required Tab 或 Required Field 驗證失敗 → **本次同步整檔 Fail** |
| **不得覆蓋** | Fail 時 **禁止** 覆蓋 `tenants/{sno}/knowledge/` 下任一正式 JSON |
| **保留舊版** | 須保留 GCS 既有正式 JSON（對齊 `BATS_DATA_SYNC_POLICY.md` §14.1） |
| **Future** | 單列錯誤跳過（skip row + warning）列入 §12 Future Roadmap |

#### 決策 C — 本文件邊界

| 本文件定義 | 本文件不定義 |
|------------|--------------|
| Tab 名稱、欄位、JSON 結構、Validation | GCS 實際同步流程 |
| `tenant_private_knowledge` Data Contract | 排程、權限、Drive API |
| 輸出 JSON 檔名與語意 | Source Registry 欄位登錄細節 |

---

## 2. Relationship

### 2.1 三份 BDS SSOT 分工

| 文件 | 職責 | 隱喻 |
|------|------|------|
| **`BATS_DATA_SYNC_POLICY.md`** | 定義 **同步原則** | 怎麼同步、何時同步、分層邊界 |
| **`BATS_DATA_SOURCE_REGISTRY.md`** | 定義 **資料來源與知識層** | 同步哪些來源、三層 Knowledge 解析順序 |
| **`BATS_DATA_CONTRACT.md`（本文件）** | 定義 **資料格式** | Sheet 欄位、JSON 結構、驗證規則 |

```text
Sync Policy     →  Mode B、Archive、GCS、Safety Rule
Source Registry →  Drive ID、industry_code、Knowledge Layer 三層
Data Contract   →  Tab 名稱、欄位、JSON schema、Validation
```

### 2.2 資料流對齊

```text
主機A固定上傳頁 / 核准維運流程
        ↓
Google Sheet（1 份、多分頁）← Registry: private_knowledge_sheet_id
        ↓
BDS Transform（本文件 Contract）
        ↓
tenants/{sno}/knowledge/*.json
        ↓
BATS 讀取（tenant_private_knowledge）
```

### 2.3 BDS Data Input Cross Reference

本 Contract **僅** 約束 **Google Sheet 結構化輸入**；與 `BATS_TENANT_DATA_CLASSIFICATION.md` §1.7 **BDS Data Input Strategy** 對齊。

| 輸入載體 | 資料型態 | 本文件約束 | 處理 Phase |
|----------|----------|------------|------------|
| **Google Sheet** | **Structured Data** | **是** — Tab、Header、JSON、Validation 均以本文件為 SSOT | Phase 4 ✅（Reader）；Phase 5+ GCS |
| **Google Drive** | **Unstructured Data** | **否** — PDF、Image、Excel、Word、PowerPoint 等 **不受** 本 Contract 約束 | **Phase 6** Google Drive Source Connector |

#### Google Sheet = Structured Data Input

| 項目 | 說明 |
|------|------|
| **Contract First** | Tab Name、Header Name **固定**；Data Content 可自由填寫 |
| **適用** | `tenant_private_knowledge` 五 Tab / 五 JSON（§4～§9） |
| **BDS 管線** | Sheet → `BdsGoogleSheetReader` → Parser → Validator → JSON |

#### Google Drive = Unstructured Data Input

| 項目 | 說明 |
|------|------|
| **Content First** | 以檔案內容為主；無本文件 Tab／欄位契約 |
| **Archive 角色** | 見 `BATS_DATA_SYNC_POLICY.md` §18、§20 |
| **未來** | Phase 6 Drive Source Connector；**不** 於 Phase 4～5 實作 |

> **Cross Reference：** `BATS_TENANT_DATA_CLASSIFICATION.md` §1.7、`BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` Phase 4 Close-out、`BATS_DATA_SYNC_TEST_PLAN.md` Phase 4.2。

---

## 3. Data Category

### 3.1 正式分類

| 代碼 | 中文 | 本文件狀態 |
|------|------|------------|
| **A. `tenant_private_knowledge`** | 租戶私有知識 | **BDS v1 MVP 正式定義**（§4～§9） |
| **B. `shared_knowledge`** | 共用知識 | **保留 Contract 架構**（§10）；本版不定義欄位 |
| **C. `itinerary_data`** | 行程資料 | **Future Roadmap**（§12）；本版不定義欄位 |

### 3.2 `tenant_private_knowledge`（MVP 範圍）

| 項目 | 說明 |
|------|------|
| **來源** | 1 個 Google Sheet、5 個 Required Tabs（§4、§5） |
| **輸出** | `tenants/{sno}/knowledge/` 下 5 個 JSON（§6） |
| **消費** | BATS Tenant Layer（`BATS_DATA_SOURCE_REGISTRY.md` §4） |
| **Pilot** | `travel_b`（`industry_code: travel`）；格式與未來 `travel_c`、`hotel` 租戶 **相同** |

### 3.3 `shared_knowledge`（保留）

| 項目 | 說明 |
|------|------|
| **路徑** | `shared/{industry_code}/knowledge/`、`shared/global/knowledge/` |
| **本版** | 僅保留章節與原則（§10） |
| **未來** | 另開 Contract 修訂定義欄位 |

### 3.4 `itinerary_data`（保留）

| 項目 | 說明 |
|------|------|
| **路徑** | `tenants/{sno}/knowledge/itinerary/` |
| **本版** | 僅列於 Future Roadmap（§12） |

---

## 4. Google Sheet Multi Tab Rule

### 4.1 正式規則

**`tenant_private_knowledge` 採用：1 個 Google Sheet、多分頁（Multi Tab）模式。**

| 規則 | 說明 |
|------|------|
| **1 Sheet** | 每租戶 **1 份** 私有知識 Google Sheet |
| **Multi Tab** | 知識分類以 **分頁（Tab）** 區分，不得拆成多份 Sheet |
| **Registry 登錄** | Sheet ID 登錄於 `private_knowledge_sheet_id`（`BATS_DATA_SOURCE_REGISTRY.md` §7） |
| **禁止** | 將 `qa`、`service_items` 等拆成各自獨立 Google Sheet |

### 4.2 原因

| 原因 | 說明 |
|------|------|
| **維運單一入口** | 旅行社／維運只需維護一份 Sheet |
| **BDS 原子轉換** | 一次同步可產出完整 `knowledge/` JSON 集合 |
| **格式一致** | 所有租戶、所有產業共用 Tab 命名與欄位契約 |

### 4.3 Tab 命名規範

| 規則 | 說明 |
|------|------|
| **正式 Tab 名稱** | 使用 §5 定義之 **英文 snake_case** 名稱（`company_profile`、`qa` 等） |
| **大小寫** | Tab 名稱 **區分大小寫**；BDS 須精確匹配 |
| **禁止** | 自訂 Tab 名稱不同步至 JSON（除非修訂本 SSOT 新增 Tab） |

---

## 5. Required Tabs

### 5.1 正式定義

每個租戶 `tenant_private_knowledge` Google Sheet **必須** 包含下列 **5 個分頁**：

| # | Tab 名稱 | 中文語意 | 輸出 JSON |
|---|----------|----------|-----------|
| 1 | **`company_profile`** | 公司資料 | `company_profile.json` |
| 2 | **`qa`** | 問答（QA） | `service_qa.json` |
| 3 | **`external_product_links`** | 其他商品源／外部連結 | `external_product_links.json` |
| 4 | **`service_items`** | 服務項目 | `service_items.json` |
| 5 | **`special_prices`** | 特殊價格 | `special_prices.json` |

### 5.2 Tab 可否為空

| Tab | 允許空分頁 | 說明 |
|-----|------------|------|
| `company_profile` | ❌ | 至少 1 筆有效資料（§7.1） |
| `qa` | ✅（不建議） | 空則輸出 `items: []`；須通過 Validation |
| `external_product_links` | ✅ | 無外部連結時可空陣列 |
| `service_items` | ✅ | 無服務項目時可空陣列 |
| `special_prices` | ✅ | 無特殊價格時可空陣列 |

---

## 6. Output JSON Contract

### 6.1 Tab → JSON 映射

| Google Sheet Tab | 輸出 JSON 檔名 | GCS 正式路徑 |
|------------------|----------------|--------------|
| `company_profile` | `company_profile.json` | `tenants/{sno}/knowledge/company_profile.json` |
| `qa` | `service_qa.json` | `tenants/{sno}/knowledge/service_qa.json` |
| `external_product_links` | `external_product_links.json` | `tenants/{sno}/knowledge/external_product_links.json` |
| `service_items` | `service_items.json` | `tenants/{sno}/knowledge/service_items.json` |
| `special_prices` | `special_prices.json` | `tenants/{sno}/knowledge/special_prices.json` |

> **注意：** `qa` Tab 輸出檔名為 **`service_qa.json`**（與 `BATS_DATA_SYNC_POLICY.md` §15 MVP 路徑一致）。

### 6.2 JSON 檔案共通包裝

每個輸出 JSON **必須** 包含下列 **檔案級** 欄位：

| 欄位 | 類型 | 必填 | 說明 |
|------|------|------|------|
| `schema_version` | string | ✅ | 固定 `bds_data_contract.v1` |
| `data_category` | string | ✅ | 固定 `tenant_private_knowledge` |
| `tenant_sno` | string | ✅ | 租戶 `sno`；須與路徑一致 |
| `source_sheet_id` | string | ❌ | 來源 Google Sheet ID（稽核用） |
| `source_tab` | string | ✅ | 來源 Tab 名稱 |
| `published_at` | string | ✅ | ISO 8601 發布時間 |
| `items` 或語意根欄位 | array / object | ✅ | 見 §7 各檔定義 |

### 6.3 轉換流程

```text
Sheet Tab（列式資料）
        ↓
BDS Row Mapper（欄位對照）
        ↓
tmp JSON（暫存）
        ↓
Validation（§8）
        ↓
正式 JSON → tenants/{sno}/knowledge/{filename}.json
```

與 `BATS_DATA_SYNC_POLICY.md` §14.1 BDS Safety Rule **對齊**。

---

## 7. Field Definition

### 7.0 共通列欄位（適用於列式 Tab）

下列欄位常見於 `qa`、`external_product_links`、`service_items`、`special_prices`：

| 欄位 | Sheet 欄名 | 類型 | 必填 | 說明 |
|------|------------|------|------|------|
| `enabled` | `enabled` | boolean | ❌ | 預設 `true`；`false` 時 BATS 不消費該筆 |
| `sort_order` | `sort_order` | integer | ❌ | 顯示排序；預設 `0`；越小越前 |

**空值規則：** 見 §8.2。

---

### 7.1 `company_profile` Tab → `company_profile.json`

**結構：** 單筆物件（非陣列）；Sheet 採 **key-value 兩欄** 或 **單列多欄** 模式。

#### 必要欄位

| JSON 欄位 | Sheet 欄名 | 類型 | 說明 |
|-----------|------------|------|------|
| `company_name` | `company_name` | string | 公司／旅行社名稱 |

#### 建議欄位

| JSON 欄位 | Sheet 欄名 | 類型 | 說明 |
|-----------|------------|------|------|
| `summary` | `summary` | string | 公司簡介 |
| `phone` | `phone` | string | 聯絡電話 |
| `address` | `address` | string | 地址 |
| `line_official` | `line_official` | string | LINE 官方帳號 |
| `email` | `email` | string | 聯絡信箱 |
| `website` | `website` | string | 官網 URL |
| `business_hours` | `business_hours` | string | 營業時間說明 |

#### 範例（JSON）

```json
{
  "schema_version": "bds_data_contract.v1",
  "data_category": "tenant_private_knowledge",
  "tenant_sno": "5f99b8d665e8444d",
  "source_tab": "company_profile",
  "published_at": "2026-06-08T10:00:00+08:00",
  "profile": {
    "company_name": "大億旅行社",
    "summary": "專營日本、東南亞團體旅遊",
    "phone": "02-1234-5678",
    "line_official": "@dayitravel",
    "business_hours": "週一至週五 09:00–18:00"
  }
}
```

---

### 7.2 `qa` Tab → `service_qa.json`

**結構：** `items[]` 陣列；一列一筆 QA。

#### 必要欄位（每筆 `items[]`）

| JSON 欄位 | Sheet 欄名 | 類型 | 說明 |
|-----------|------------|------|------|
| `question` | `question` | string | 問題（客戶可能詢問的語句） |
| `answer` | `answer` | string | 答案（AI 回覆依據） |

#### 建議欄位

| JSON 欄位 | Sheet 欄名 | 類型 | 說明 |
|-----------|------------|------|------|
| `qa_id` | `qa_id` | string | 唯一識別；缺則 BDS 自動產生 `qa_{row}` |
| `category` | `category` | string | 分類（如 `護照代辦`、`簽證`、`退款`） |
| `enabled` | `enabled` | boolean | 預設 `true` |
| `sort_order` | `sort_order` | integer | 排序 |
| `tags` | `tags` | string | 逗號分隔標籤（如 `護照,代辦`） |

#### 範例（JSON）

```json
{
  "schema_version": "bds_data_contract.v1",
  "data_category": "tenant_private_knowledge",
  "tenant_sno": "5f99b8d665e8444d",
  "source_tab": "qa",
  "published_at": "2026-06-08T10:00:00+08:00",
  "items": [
    {
      "qa_id": "qa_passport_fee",
      "question": "護照代辦怎麼收費？",
      "answer": "本社護照代辦費用 NT$1,600，約 7 個工作天。",
      "category": "護照代辦",
      "enabled": true,
      "sort_order": 10,
      "tags": "護照,代辦"
    }
  ]
}
```

---

### 7.3 `external_product_links` Tab → `external_product_links.json`

**用途：** 其他商品源、外部平台、非 bbctravel/grp/tourcenter 之補充連結。

#### 必要欄位（每筆 `items[]`）

| JSON 欄位 | Sheet 欄名 | 類型 | 說明 |
|-----------|------------|------|------|
| `name` | `name` | string | 連結名稱（如 `AgentTour 搜尋`） |
| `url` | `url` | string | 完整 URL（`https://`） |

#### 建議欄位

| JSON 欄位 | Sheet 欄名 | 類型 | 說明 |
|-----------|------------|------|------|
| `link_id` | `link_id` | string | 唯一識別 |
| `platform` | `platform` | string | 平台代碼（如 `agenttour`、`custom`） |
| `description` | `description` | string | 說明 |
| `enabled` | `enabled` | boolean | 預設 `true` |
| `sort_order` | `sort_order` | integer | 排序 |

#### 範例（JSON）

```json
{
  "schema_version": "bds_data_contract.v1",
  "data_category": "tenant_private_knowledge",
  "tenant_sno": "5f99b8d665e8444d",
  "source_tab": "external_product_links",
  "published_at": "2026-06-08T10:00:00+08:00",
  "items": [
    {
      "link_id": "link_agenttour",
      "name": "AgentTour 商品搜尋",
      "url": "https://rechoice-travel.agenttour.com.tw/",
      "platform": "agenttour",
      "enabled": true,
      "sort_order": 10
    }
  ]
}
```

---

### 7.4 `service_items` Tab → `service_items.json`

**用途：** 服務項目（代辦、加購、手續等）。

#### 必要欄位（每筆 `items[]`）

| JSON 欄位 | Sheet 欄名 | 類型 | 說明 |
|-----------|------------|------|------|
| `service_id` | `service_id` | string | 唯一識別；缺則 BDS 自動產生 `svc_{row}` |
| `name` | `name` | string | 服務名稱 |

#### 建議欄位

| JSON 欄位 | Sheet 欄名 | 類型 | 說明 |
|-----------|------------|------|------|
| `description` | `description` | string | 服務說明 |
| `category` | `category` | string | 分類（如 `代辦`、`保險`） |
| `price_amount` | `price_amount` | number | 價格數值 |
| `price_currency` | `price_currency` | string | 幣別；預設 `TWD` |
| `price_unit` | `price_unit` | string | 計價單位（如 `per_person`、`per_item`） |
| `enabled` | `enabled` | boolean | 預設 `true` |
| `sort_order` | `sort_order` | integer | 排序 |

#### 範例（JSON）

```json
{
  "schema_version": "bds_data_contract.v1",
  "data_category": "tenant_private_knowledge",
  "tenant_sno": "5f99b8d665e8444d",
  "source_tab": "service_items",
  "published_at": "2026-06-08T10:00:00+08:00",
  "items": [
    {
      "service_id": "svc_passport",
      "name": "護照代辦",
      "description": "含郵資，約 7 工作天",
      "category": "代辦",
      "price_amount": 1600,
      "price_currency": "TWD",
      "price_unit": "per_person",
      "enabled": true,
      "sort_order": 10
    }
  ]
}
```

---

### 7.5 `special_prices` Tab → `special_prices.json`

**用途：** 特殊價格、促銷價、專案報價（覆寫或補充 `service_items` 價格語意）。

#### 必要欄位（每筆 `items[]`）

| JSON 欄位 | Sheet 欄名 | 類型 | 說明 |
|-----------|------------|------|------|
| `item_name` | `item_name` | string | 項目名稱（如 `護照代辦`） |
| `price_amount` | `price_amount` | number | 特殊價格數值 |

#### 建議欄位

| JSON 欄位 | Sheet 欄名 | 類型 | 說明 |
|-----------|------------|------|------|
| `price_id` | `price_id` | string | 唯一識別 |
| `service_id` | `service_id` | string | 關聯 `service_items.service_id`（可選） |
| `price_currency` | `price_currency` | string | 預設 `TWD` |
| `price_unit` | `price_unit` | string | 計價單位 |
| `description` | `description` | string | 價格說明、適用條件 |
| `category` | `category` | string | 分類 |
| `effective_from` | `effective_from` | string | 生效日 `YYYY-MM-DD`（可選） |
| `effective_to` | `effective_to` | string | 失效日 `YYYY-MM-DD`（可選） |
| `enabled` | `enabled` | boolean | 預設 `true` |
| `sort_order` | `sort_order` | integer | 排序 |

#### 範例（JSON）

```json
{
  "schema_version": "bds_data_contract.v1",
  "data_category": "tenant_private_knowledge",
  "tenant_sno": "5f99b8d665e8444d",
  "source_tab": "special_prices",
  "published_at": "2026-06-08T10:00:00+08:00",
  "items": [
    {
      "price_id": "price_passport_promo",
      "item_name": "護照代辦",
      "service_id": "svc_passport",
      "price_amount": 1600,
      "price_currency": "TWD",
      "price_unit": "per_person",
      "description": "2026 夏季優惠價",
      "category": "代辦",
      "enabled": true,
      "sort_order": 10
    }
  ]
}
```

---

## 8. Validation Rule

### 8.1 驗證流程

```text
Sheet → tmp JSON → Validation → 正式 JSON → GCS
                      │
                      ├─ Pass → 寫入 tenants/{sno}/knowledge/
                      └─ Fail → 保留舊版 JSON（§8.4）
```

與 `BATS_DATA_SYNC_POLICY.md` §14.1 **BDS Safety Rule** 對齊。

### 8.2 必填欄位規則

| 層級 | 規則 |
|------|------|
| **檔案級** | `schema_version`、`data_category`、`tenant_sno`、`source_tab`、`published_at` 必填 |
| **Tab 級** | 見 §5.2；`company_profile` 不可空 |
| **列級** | 各 Tab 必要欄位見 §7；缺必填 → 該列 **拒絕** 並記錄 `validation_errors[]` |
| **唯一性** | `qa_id`、`service_id`、`link_id`、`price_id` 於同檔內不得重複 |

### 8.3 空值規則

| 情境 | 處理 |
|------|------|
| Sheet 空列 | 跳過 |
| 可選欄位空值 | 省略或 `null`；`enabled` 空值視為 `true` |
| `items[]` 空陣列 | 允許（除 `company_profile`）；須明確輸出 `[]` |
| 整欄空白 Tab | 依 §5.2；`company_profile` 空 → **Validation Fail** |

### 8.4 型別驗證

| 類型 | 規則 |
|------|------|
| `string` | 非空字串（必填欄位）；去除首尾空白 |
| `number` | `price_amount` 須為有效數字、≥ 0 |
| `integer` | `sort_order` 須為整數 |
| `boolean` | 接受 `true`/`false`、`1`/`0`、`Y`/`N`（BDS 正規化） |
| `url` | 須以 `http://` 或 `https://` 開頭 |
| `date` | `YYYY-MM-DD`（`effective_from` / `effective_to`） |

### 8.5 啟用狀態

| 規則 | 說明 |
|------|------|
| `enabled = false` | 該筆 **寫入 JSON** 但 BATS **不消費** |
| 檔案級無 `enabled` | 整檔有效；由列級 `enabled` 控制 |
| 刪除列 | 等效於不再出現在 `items[]`；下次同步自然移除 |

### 8.6 Validation Fail 處理

| 規則 | 說明 |
|------|------|
| **不得覆蓋正式 JSON** | 驗證失敗時 **禁止** 寫入 `tenants/{sno}/knowledge/` 正式路徑 |
| **必須保留舊版 JSON** | GCS 既有正式檔維持不變 |
| **記錄錯誤** | 寫入 `tenants/{sno}/meta/sync_status.json`；含 `validation_errors[]` |
| **單列錯誤** | **v1 MVP：整檔 Fail**（§1.4 決策 B）；單列跳過列入 §12 Future Roadmap |
| **PII 偵測** | 含身分證、完整電話等 PII 欄位 → **整檔 Fail** |

### 8.7 錯誤碼（概念）

| 錯誤碼 | 說明 |
|--------|------|
| `BDC_REQUIRED_FIELD_MISSING` | 缺必填欄位 |
| `BDC_INVALID_TYPE` | 型別不符 |
| `BDC_DUPLICATE_ID` | ID 重複 |
| `BDC_TENANT_MISMATCH` | `tenant_sno` 與路徑不符 |
| `BDC_TAB_MISSING` | 缺 Required Tab |
| `BDC_PII_DETECTED` | 含禁止 PII |

---

## 9. Tenant Isolation Rule

### 9.1 正式規則

**所有 `tenant_private_knowledge` 輸出 JSON 必須存放於 `tenants/{sno}/knowledge/`。**

| 規則 | 說明 |
|------|------|
| **路徑邊界** | `tenants/{sno}/` 為不可跨越之寫入邊界 |
| **禁止跨租戶** | 不得將 A 社 Sheet 轉換寫入 B 社路徑 |
| **`tenant_sno` 一致** | JSON 內 `tenant_sno` 須與路徑 `{sno}` 一致 |
| **與 Registry 對齊** | `gcs_prefix` = `tenants/{sno}/`（`BATS_DATA_SOURCE_REGISTRY.md` §7） |

### 9.2 正式輸出路徑一覽

```text
tenants/{sno}/knowledge/
├── company_profile.json
├── service_qa.json
├── external_product_links.json
├── service_items.json
└── special_prices.json
```

### 9.3 禁止

| 禁止 | 原因 |
|------|------|
| 寫入 `shared/` | 屬 `shared_knowledge`；非本 Contract MVP 輸出 |
| 寫入他社 `tenants/{other_sno}/` | 違反隔離 |
| 寫入 `knowledge/` 以外路徑 | 違反 Sync Policy §11 |

---

## 10. Shared Knowledge Contract

### 10.1 保留章節

**`shared_knowledge` 未來亦須遵守 Data Contract**；本版 **不定義欄位**。

### 10.2 預留路徑

| 層級 | GCS 路徑 |
|------|----------|
| Industry Shared | `shared/{industry_code}/knowledge/` |
| Global Shared | `shared/global/knowledge/` |

見 `BATS_DATA_SOURCE_REGISTRY.md` §4 Knowledge Layer Architecture。

### 10.3 預留原則

| 原則 | 說明 |
|------|------|
| **輸入來源** | Industry / Global Shared Knowledge **亦以 Google Sheet 治理**（§1.5）；**不是** Drive PDF |
| **獨立 Contract** | Shared 與 Tenant **不得** 共用同一 JSON schema 檔案 |
| **檔案級 `data_category`** | 固定 `shared_knowledge` |
| **讀取優先序** | Tenant > Industry > Global（不由 Contract 變更） |
| **Shared 治理** | Shared ≠ Public；須 Registry + Policy 啟用後才進 Runtime |
| **完整定義** | `BATS_SHARED_KNOWLEDGE_CONTRACT.md` §3.6 |

---

## 11. Anti Hardcode Rule

### 11.1 正式規則

**`travel_b` 僅為 Pilot Tenant，不得 hardcode。**

| 禁止 | 說明 |
|------|------|
| hardcode `travel_b` | BDS 轉換器不得寫死 tenant 名稱 |
| hardcode `5f99b8d665e8444d` | 不得寫死 Pilot `sno` |
| per-tenant JSON 格式 fork | 所有租戶使用 **同一** `bds_data_contract.v1` |

### 11.2 新增租戶／產業

| 租戶範例 | 正確方式 |
|----------|----------|
| `travel_c`、`travel_d` | 新增 Registry Config + 同結構 Sheet |
| `hotel_a`、`restaurant_a` | 新增 Registry Config + `industry_code`；**Contract 不變** |

**只能新增 Registry Config；不得修改 BDS 轉換核心邏輯。**

### 11.3 與其他 SSOT 對齊

本節與 `BATS_DATA_SYNC_POLICY.md` §23、`BATS_DATA_SOURCE_REGISTRY.md` §8 **同等效力**。

---

## 12. Future Roadmap

### 12.1 保留項目

下列 Contract **保留於 Roadmap**；本版 **不實作**：

| 項目 | 說明 | 狀態 |
|------|------|------|
| **中文欄位別名** | Sheet 欄位「問題」「答案」等映射至英文欄位 | 規劃中（§1.4 決策 A） |
| **單列錯誤跳過** | Validation 失敗列跳過 + `sync_warnings[]`；其餘列繼續 | 規劃中（§1.4 決策 B） |
| **`itinerary_data` Contract** | 行程 Excel / 商品欄位 schema | 規劃中 |
| **PDF Contract** | 行程 PDF 解析後 JSON 結構 | 規劃中 |
| **Image Contract** | 圖片 DM metadata schema | 規劃中 |
| **RAG Contract** | 向量／chunk metadata（若未來採用） | 規劃中 |
| **`shared_knowledge` 欄位** | Industry / Global 各檔欄位定義 | 規劃中 |

### 12.2 建議 Phase 順序

| Phase | 內容 |
|-------|------|
| **Contract v1** | 本文件；`tenant_private_knowledge` 五 Tab 五 JSON |
| **Contract v1 實作** | BDS Sheet Reader + Validator + travel_b Pilot |
| **Contract v2** | `shared_knowledge` 欄位定義 |
| **Contract v3** | `itinerary_data` + PDF / Image |

### 12.3 MVP 明確排除

| 排除 | 說明 |
|------|------|
| 多 Sheet 模式 | 違反 §4 |
| 自訂 Tab 名稱 | 違反 §5 |
| Excel 直轉（非 Sheet） | BDS v1 MVP 以 Google Sheet 為準；Excel 上傳可另開 profile |

---

## 相關文件

| 文件 | 關係 |
|------|------|
| `BATS_DATA_SYNC_POLICY.md` | Mode B、BDS Safety Rule、Data Category、`service_qa.json` MVP 路徑 |
| `BATS_DATA_SOURCE_REGISTRY.md` | `private_knowledge_sheet_id`、Knowledge Layer 三層、`industry_code` |
| `TENANT_SOURCE_REGISTRY_POLICY.md` | 商品源 Registry（與本文件 `external_product_links` 互補） |
| `DOCUMENTATION_GOVERNANCE_POLICY.md` | 本文件為 BDS Data Contract 領域 L1 SSOT |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.7** | 2026-06-05 | Phase 7-0e.2 Final：§1.6.5 Upload Portal Sync Metadata Contract（sync_id、來源優先序） |
| **v1.6** | 2026-06-05 | Phase 7-0d：§1.6.4 雙 Upload Portal URL（Tenant／Shared） |
| **v1.5** | 2026-06-05 | Phase 7-0c：§1.6.4 Upload Portal canonical URL（kowanbo）；cross-ref MVP Plan |
| **v1.4** | 2026-06-06 | Phase 7 Pre-Planning：§1.6 Upload-Triggered Structured Knowledge；三層同一 Upload 管線 |
| **v1.3** | 2026-06-10 | §1.5 Structured Knowledge Input Source；§10.3 Shared 亦以 Sheet 治理 |
| **v1.2** | 2026-06-09 | 新增 §2.3 BDS Data Input Cross Reference（Sheet Structured / Drive Unstructured） |
| **v1.1** | 2026-06-08 | MVP 決策：英文欄位 only、整檔 Fail、文件邊界；cross-ref Sync / Registry |
| **v1.0** | 2026-06-08 | 第一版：`tenant_private_knowledge` 五 Tab 五 JSON Contract、Validation、Tenant Isolation |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| 文件狀態 | **Draft — 待審核** |
| 程式實作 | **未開始** |
| Git commit | **尚未提交** |
