# BATS_SHARED_KNOWLEDGE_CONTRACT.md

**專案：** BBC AI SaaS / BATS / BDS / Travel Data Center  
**定位：** L1 架構政策層 — Shared Knowledge Layer Data Contract 正式 SSOT  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_DATA_SYNC_POLICY.md`、`BATS_DATA_SOURCE_REGISTRY.md`、`BATS_DATA_CONTRACT.md`、`BATS_SHARED_KNOWLEDGE_SHEET_CONTRACT.md`、`BATS_DATA_OWNERSHIP_POLICY.md`、`BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md`  
**適用範圍：** `shared/{industry_code}/knowledge/`、`shared/global/knowledge/`；支援 **travel、hotel、restaurant、beauty、auto、retail、education、medical** 及未來新增產業  
**適用對象：** ChatGPT、Cursor、開發者、維運人員、BBC Industry Maintainer、BBC Platform Admin  
**衝突處理：** 同步原則以 `BATS_DATA_SYNC_POLICY.md` 為準；Registry 與 Knowledge Resolution Order 以 `BATS_DATA_SOURCE_REGISTRY.md` 為準；Tenant Private Knowledge 格式以 `BATS_DATA_CONTRACT.md` 為準；歸屬與覆蓋以 `BATS_DATA_OWNERSHIP_POLICY.md` 為準；**Shared Knowledge 資料格式以本文件為準**。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §2 | Scope |
| §3 | Shared Knowledge Layers |
| §3.4 | Shared Layer Governance |
| §3.5 | Google Drive Platform Layer（Shared Archive） |
| §3.6 | Structured Knowledge Input Source（Google Sheet） |
| §4 | Industry Code Rule |
| §5 | Shared Knowledge Categories |
| §6 | Shared Knowledge JSON Contract |
| §7 | Industry Examples |
| §8 | Resolution Rule & Knowledge Priority |
| §8.5 | Industry Shared First Principle |
| §8.6 | Shared Knowledge Sheet Contract |
| §9 | Ownership Rule |
| §10 | Validation Rule |
| §11 | Future Roadmap |
| §12 | Cross References |

---

## 1. Purpose

### 1.1 文件定位

本文件建立 **BATS / BDS Shared Knowledge Layer** 之正式 **Data Contract**，定義：

| 術語 | 定義 |
|------|------|
| **Industry Shared Knowledge** | 產業級共用知識；路徑 `shared/{industry_code}/knowledge/`；同產業租戶 fallback 使用 |
| **Global Shared Knowledge** | 跨產業共用知識；路徑 `shared/global/knowledge/`；最終 fallback 使用 |
| **正式資料契約** | JSON 結構、欄位、category、Validation；**Industry-Agnostic（跨行業通用）** |

### 1.2 設計原則

| 原則 | 說明 |
|------|------|
| **Industry-Agnostic** | Contract **不得** 設計成旅遊業專用格式；travel / hotel / medical 等使用 **同一套** schema |
| **與 Tenant Contract 分離** | `tenant_private_knowledge` 見 `BATS_DATA_CONTRACT.md`；**禁止** 共用同一 JSON schema |
| **Category 驅動** | 以 `category`（faq / guide / notice 等）組織內容，不以產業專屬欄位組織 |
| **Resolution 不變** | Tenant > Industry > Global 優先序 **不由本 Contract 變更** |
| **BDS v1 不同步** | 本 Contract 定義格式；Shared Layer **同步管線** 列入 Future Roadmap |

### 1.3 本文件回答

| 問題 | 章節 |
|------|------|
| Shared Layer 放哪裡？ | §3 |
| Shared Layer 治理與存取預設？ | §3.4 |
| 支援哪些產業？ | §4 |
| JSON 長什麼樣？ | §6 |
| 誰擁有、誰可覆蓋？ | §8、§9 |
| 如何驗證？ | §10 |

### 1.4 與 `BATS_DATA_CONTRACT.md` 關係

`BATS_DATA_CONTRACT.md` §10 保留 Shared 章節並註明欄位將於獨立 Contract 補齊。**本文件即為該獨立 Contract**，承接 `shared_knowledge` 之 L3 SSOT。

---

## 2. Scope

### 2.1 In Scope

| 項目 | 說明 |
|------|------|
| **data_category** | `shared_knowledge` |
| **Industry Shared Layer** | `shared/{industry_code}/knowledge/` |
| **Global Shared Layer** | `shared/global/knowledge/` |
| **JSON Contract** | 共通欄位、category、Validation |
| **跨產業擴展** | 新增 `industry_code` 即可；schema 不變 |

### 2.2 Out of Scope

| 排除項目 | 說明 |
|----------|------|
| **`tenant_private_knowledge`** | 見 `BATS_DATA_CONTRACT.md` |
| **`itinerary_data`** | 下一階段；租戶層行程資料 |
| **PDF / Image Parser** | 未納入 |
| **RAG / Vector DB** | 未納入；見 §11 |
| **Admin UI** | 未納入 |
| **RBAC 實作** | 未納入；見 Ownership Future Roadmap |
| **BDS Shared Sync 程式** | v1 未實作；見 §11 |
| **Cron / 自動排程** | 未納入 |

### 2.3 BDS v1 現況

| 層級 | Contract | BDS 同步 |
|------|----------|----------|
| Tenant Private | `BATS_DATA_CONTRACT.md` | Phase 6A（Sheet → GCS） |
| Industry / Global Shared | **本文件** | **未實作**（Sheet Input SSOT 已定；§3.6） |

---

## 3. Shared Knowledge Layers

### 3.1 Industry Shared Layer

| 項目 | 說明 |
|------|------|
| **層級** | Layer 2 — Industry Shared |
| **GCS 路徑** | `shared/{industry_code}/knowledge/` |
| **data_category** | `shared_knowledge` |
| **`owner_scope`** | `industry` |
| **用途** | 同產業租戶無私有匹配時之 **產業級 fallback** |
| **`industry_code`** | 來自 Tenant Registry（`BATS_DATA_SOURCE_REGISTRY.md` §7.3）；決定 fallback 路徑 |

```text
shared/
└── {industry_code}/
    └── knowledge/
        ├── faq.json
        ├── guide.json
        ├── notice.json
        ├── reference.json
        ├── policy.json
        └── compliance.json
```

> 檔名為 **category** 對應之建議輸出；實作可合併為單檔，但 **item 級** `category` 欄位仍必填。

---

### 3.2 Global Shared Layer

| 項目 | 說明 |
|------|------|
| **層級** | Layer 3 — Global Shared |
| **GCS 路徑** | `shared/global/knowledge/` |
| **data_category** | `shared_knowledge` |
| **`owner_scope`** | `global` |
| **用途** | Industry Shared 仍無匹配時之 **最終 fallback** |
| **`industry_code` 語意** | 固定值 `global`（見 §6.2）；**不作** Layer 2 路徑填入 |

> **注意：** `global` 為 Layer 3 專用路徑 segment，**不是** 可填入 `shared/{industry_code}/` 的產業代碼（見 `BATS_DATA_SOURCE_REGISTRY.md` §4.3）。

```text
shared/
└── global/
    └── knowledge/
        ├── faq.json
        ├── guide.json
        ├── notice.json
        ├── reference.json
        ├── policy.json
        └── compliance.json
```

---

### 3.3 兩層對照

| 項目 | Industry Shared | Global Shared |
|------|-----------------|---------------|
| **路徑** | `shared/{industry_code}/knowledge/` | `shared/global/knowledge/` |
| **`owner_scope`** | `industry` | `global` |
| **`industry_code`** | `travel`、`hotel`、`medical` 等 | `global` |
| **Owner** | BBC Industry Maintainer | BBC Platform |
| **讀取順序** | 2（fallback） | 3（最終 fallback） |

---

### 3.4 Shared Layer Governance

#### 3.4.1 治理範圍

本節定義 **Shared Layer** 之架構治理；與 JSON 欄位契約（§6）、Ownership（§9）互補。

```text
Knowledge Layer（Category B）
├── Private Layer     →  tenant_private_knowledge（見 BATS_DATA_CONTRACT.md）
└── Shared Layer      →  shared_knowledge（本文件）
        ├── Industry Shared Layer
        └── Global Shared Layer
```

#### 3.4.2 Shared Layer

| 項目 | 說明 |
|------|------|
| **定義** | 跨租戶 **fallback 知識層**；當 Private Layer 無匹配時，BATS 依 Resolution Order 向下解析 |
| **data_category** | `shared_knowledge` |
| **Structured 輸入** | **Google Sheet** — Industry / Global Shared Knowledge 之 **Input Source**（§3.6） |
| **Runtime 輸出** | **GCS** `shared/{industry_code}/knowledge/`、`shared/global/knowledge/` |
| **Drive 角色** | **Archive only** — PDF／DM 原件；**不是** Structured Knowledge 輸入 |
| **≠ Public Layer** | Shared Layer **不是** 對外公開層；名稱指知識解析共用，**不是** 匿名公開讀取 |

#### 3.4.3 Industry Shared Layer

| 項目 | 說明 |
|------|------|
| **路徑** | `shared/{industry_code}/knowledge/` |
| **`owner_scope`** | `industry` |
| **Owner** | BBC Industry Maintainer + BBC Admin |
| **用途** | 同 `industry_code` 租戶之產業級 fallback |
| **擴展** | 新增產業僅新增 `industry_code` + 路徑；schema 不變（§4） |

#### 3.4.4 Global Shared Layer

| 項目 | 說明 |
|------|------|
| **路徑** | `shared/global/knowledge/` |
| **`owner_scope`** | `global` |
| **Owner** | BBC Platform + BBC Admin |
| **用途** | Industry Shared 仍無匹配時之 **最終 fallback** |
| **注意** | `global` 為 Layer 3 專用 segment，**不是** 可填入 `shared/{industry_code}/` 的產業代碼 |

#### 3.4.5 Default Private

| 項目 | 規則 |
|------|------|
| **正式預設** | **Default Private** — Shared Layer 相關資源 **預設不對外共享** |
| **GCS** | `shared/` 僅供受控 BDS / BATS 管線讀寫；**非** 公開匿名讀取政策 |
| **Google Drive** | Shared Layer 對應 Drive 資料夾 **預設 Private**；不得假設「在 shared 資料夾即公開」 |
| **與 Private Layer 對照** | 租戶 `tenants/{sno}/` 與 `shared/` 皆 **非** Public；差異在 **歸屬與 fallback 語意**，非公開程度 |

詳細 Drive 資料夾存取預設見 `BATS_DATA_OWNERSHIP_POLICY.md` §2.5。

#### 3.5 Google Drive Platform Layer（Shared Archive）

> **Phase 6 Pre-Governance：** Shared Layer **不屬於單一旅行社**；位於營運 Google Drive 平台層。

**營運帳號：** `bbcshops88@gmail.com`

| 層級 | Drive Archive 路徑 | GCS 路徑（不變） |
|------|-------------------|------------------|
| **Industry Shared** | `shared/{industry_code}/02_Shared_Layer/` | `shared/{industry_code}/knowledge/` |
| **Global Shared** | `shared/global/02_Global_Shared_Layer/` | `shared/global/knowledge/` |

```text
shared/
├── travel/02_Shared_Layer/
├── hotel/02_Shared_Layer/
├── restaurant/02_Shared_Layer/
└── global/02_Global_Shared_Layer/
```

| 原則 | 說明 |
|------|------|
| **禁止租戶下 Shared** | 不得使用 `tenants/{tenant_key}/02_Shared_Layer/` |
| **Default Private** | Shared Drive 資料夾預設 Private |
| **Explicit 啟用** | Tenant 不自動消費 Shared；須 Registry + Policy |
| **產業可擴展** | `travel` 為首個產業；未來產業適用同架構 |

**禁止新增** `private_drive_folder_id` 至 Registry Schema。詳見 `BATS_DATA_SOURCE_REGISTRY.md` §6.5。

#### 3.4.6 Explicit Share Policy

**禁止** 依資料夾命名或路徑慣例 **隱式** 對外共享。任何跨角色、跨租戶、對第三方之可見性，須符合 **Explicit Share Policy**：

| 要件 | 說明 |
|------|------|
| **Registry 登錄** | Shared 來源須於 `BATS_DATA_SOURCE_REGISTRY.md` 登錄（路徑、`industry_code`、`data_category`） |
| **Ownership 授權** | 寫入／發布須符合 `BATS_DATA_OWNERSHIP_POLICY.md` §2、§3 |
| **禁止隱式公開** | 不得將 Shared Layer 連結貼至公開網站作為預設行為 |
| **Service Account** | API 讀寫權限採最小權限；**非** 「任何人可讀」 |
| **BDS v1** | Shared Layer **同步未啟用**；Explicit Share 主要約束 Archive／未來發布流程 |

```text
Default Private（預設）
        ↓
Registry + Ownership Policy 明確授權
        ↓
Explicit Share Policy 啟用（受控對象／角色）
        ↓
（仍非 Public Layer）
```

#### 3.4.7 交叉引用

| 議題 | SSOT |
|------|------|
| Private + Shared Layer 定義 | `BATS_TENANT_DATA_CLASSIFICATION.md` §1.6 |
| Drive 資料夾 Default Private | `BATS_DATA_OWNERSHIP_POLICY.md` §2.5 |
| Resolution Order | `BATS_DATA_SOURCE_REGISTRY.md` §4.4 |
| Shared JSON 格式 | 本文件 §6 |
| Structured Knowledge = Google Sheet | 本文件 §3.6 |

#### 3.6 Structured Knowledge Input Source（Google Sheet）

> **正式原則：** **Industry Shared Knowledge = Google Sheet**；**Global Shared Knowledge = Google Sheet**。Drive 僅 Archive。

##### 3.6.1 正式定義

| 層級 | Input Source（Structured） | Runtime Output（GCS） |
|------|---------------------------|------------------------|
| **Industry Shared Knowledge** | Google Sheet（`shared/{industry}/` 治理） | `shared/{industry_code}/knowledge/` |
| **Global Shared Knowledge** | Google Sheet（平台治理） | `shared/global/knowledge/` |

```text
Google Sheet（Industry / Global Shared）
        ↓ BDS Sync（未來；須 Registry + Policy）
shared/{industry_code}/knowledge/*.json
shared/global/knowledge/*.json
        ↓
Gemini / BATS Search（Runtime）
```

##### 3.6.2 典型 Sheet 知識主題（travel 範例）

| 主題 | 說明 |
|------|------|
| 護照新辦基本規定 | FAQ／條列型 |
| 護照效期規定 | FAQ／條列型 |
| 台胞證申請規定 | FAQ／條列型 |
| 日本／泰國入境規定 | FAQ／條列型 |
| 航空行李規定 | 條列型 |
| 國際旅遊常識 | FAQ 型 |

> 上述 **應以 Google Sheet 維護**，經 BDS 同步至 GCS，供 Gemini 回答；**不必等待** PDF 解析或 RAG。

##### 3.6.3 與 Google Drive 分工

| 載體 | Shared Layer 角色 |
|------|-------------------|
| **Google Sheet** | Structured Knowledge **Input** |
| **Google Drive** `02_Shared_Layer/` | Archive 原件（PDF、DM 等）；**不是** Structured 輸入 |
| **GCS** | Runtime Knowledge Source |

##### 3.6.4 治理邊界（不變）

| 原則 | 說明 |
|------|------|
| **Shared ≠ Public** | 受控 fallback |
| **Default Private** | Sheet／Drive 預設不公開 |
| **預設不進 Runtime** | 未 Registry + Policy 啟用前 **不同步** 至 GCS |
| **BDS v1** | Shared Sheet 同步 **未實作**；本節為 SSOT 定案 |
| **Registry Schema** | **不變**；**不新增** `private_drive_folder_id` |
| **Sheet Tabs** | `BATS_SHARED_KNOWLEDGE_SHEET_CONTRACT.md`（P2-1） |

---

## 4. Industry Code Rule

### 4.1 已知 `industry_code`

| `industry_code` | 產業 |
|-----------------|------|
| `travel` | 旅遊業 |
| `hotel` | 飯店民宿 |
| `restaurant` | 美食餐廳 |
| `beauty` | 美業 |
| `auto` | 汽車 |
| `retail` | 零售 |
| `education` | 教育 |
| `medical` | 醫療 |
| （其他） | 依 Registry 登錄 |

### 4.2 新增產業規則

**只允許：**

| 允許 | 說明 |
|------|------|
| **新增 `industry_code`** | Registry 登錄新代碼 |
| **新增 `shared/{industry_code}/knowledge/`** | GCS 新路徑 |
| **使用本 Contract 同一 schema** | 不 fork 產業專用 JSON 格式 |

**不得修改：**

| 禁止 | 說明 |
|------|------|
| **Ownership Rule** | 見 `BATS_DATA_OWNERSHIP_POLICY.md` §2 |
| **Resolution Rule** | Tenant > Industry > Global（§8） |
| **Sync Flow** | BDS v1 仍僅 Tenant；Shared Sync 未啟用 |
| **本 Contract 核心欄位** | 不得為單一產業新增必填專屬欄位 |

```text
新增 medical 產業：
  1. Registry 新增 industry_code: medical
  2. 建立 shared/medical/knowledge/
  3. JSON 使用本 Contract 同一 schema
  4. Ownership / Resolution / Sync Flow 不變
```

### 4.3 Anti Hardcode

| 禁止 | 說明 |
|------|------|
| 為 `travel` fork schema | 違反 Industry-Agnostic |
| 在 Contract 寫死產業專屬欄位 | 如 `departure_city`、`room_type` 等 |
| 將 Global 寫入 `shared/travel/` | 路徑錯層 |

---

## 5. Shared Knowledge Categories

### 5.1 正式 category 清單

Shared Knowledge 以 **category** 組織；**跨產業通用**，不綁定單一產業語意。

| category | 中文 | 用途 |
|----------|------|------|
| **`faq`** | 常見問答 | 通用 Q&A fallback |
| **`guide`** | 指南／說明 | 流程、操作、入門說明 |
| **`notice`** | 公告／提醒 | 時效性公告、注意事項 |
| **`reference`** | 參考資料 | 術語、收費參考、對照表 |
| **`policy`** | 政策 | 服務政策、取消規則等 |
| **`compliance`** | 法規／合規 | 法規、個資、行業合規說明 |

### 5.2 category 使用規則

| 規則 | 說明 |
|------|------|
| **必填於 item** | 每筆 knowledge item 須有 `category` |
| **值域固定** | 僅允許 §5.1 六類；新增 category 須修訂本 SSOT |
| **產業語意在 content** | 產業差異寫在 `title` / `content`，非 schema |
| **建議檔名** | 可依 category 輸出 `{category}.json`（見 §3.1） |

### 5.3 `item_type`（可選細分）

| 項目 | 說明 |
|------|------|
| **定位** | category 下之輕量細分；**可選** |
| **範例** | `standard`、`emergency`、`seasonal` |
| **規則** | 不強制枚舉；不得取代 `category` |

---

## 6. Shared Knowledge JSON Contract

### 6.1 檔案級 Envelope（建議）

每個 JSON 檔案為 **envelope + items 陣列**；適用 Industry 與 Global 兩層。

| 欄位 | 型別 | 必填 | 說明 |
|------|------|------|------|
| **`schema_version`** | string | ✅ | 固定 `bats_shared_knowledge.v1` |
| **`data_category`** | string | ✅ | 固定 `shared_knowledge` |
| **`owner_scope`** | string | ✅ | `industry` 或 `global` |
| **`industry_code`** | string | ✅ | Industry：`travel` 等；Global：`global` |
| **`published_at`** | string | ✅ | ISO 8601 發布時間 |
| **`source_type`** | string | 建議 | 來源類型，如 `manual`、`import`、`editor` |
| **`items`** | array | ✅ | knowledge item 清單 |

### 6.2 Item 級欄位

| 欄位 | 型別 | 必填 | 說明 |
|------|------|------|------|
| **`category`** | string | ✅ | §5.1 六類之一 |
| **`item_type`** | string | 可選 | 細分類型 |
| **`title`** | string | ✅ | 標題 |
| **`content`** | string | ✅ | 正文（Markdown 或純文字） |
| **`tags`** | string[] | 可選 | 標籤；跨產業搜尋用 |
| **`enabled`** | boolean | 建議 | `false` 時讀取層跳過 |
| **`item_id`** | string | 建議 | 穩定識別；便於覆寫與稽核 |

### 6.3 `owner_scope` 與 `industry_code` 對照

| `owner_scope` | 路徑 | `industry_code` |
|---------------|------|-----------------|
| `industry` | `shared/{industry_code}/knowledge/` | 實際產業代碼 |
| `global` | `shared/global/knowledge/` | **`global`**（固定） |

### 6.4 JSON 範例 — Industry Shared（hotel / faq）

```json
{
  "schema_version": "bats_shared_knowledge.v1",
  "data_category": "shared_knowledge",
  "owner_scope": "industry",
  "industry_code": "hotel",
  "published_at": "2026-06-08T10:00:00+08:00",
  "source_type": "manual",
  "items": [
    {
      "item_id": "hotel-faq-checkin-001",
      "category": "faq",
      "item_type": "standard",
      "title": "Check-in time and ID requirements",
      "content": "Standard check-in from 15:00. Government-issued photo ID required.",
      "tags": ["check-in", "id"],
      "enabled": true
    },
    {
      "item_id": "hotel-faq-cancel-001",
      "category": "faq",
      "item_type": "standard",
      "title": "Cancellation policy overview",
      "content": "Free cancellation up to 24 hours before arrival unless otherwise stated.",
      "tags": ["cancellation", "policy"],
      "enabled": true
    }
  ]
}
```

**建議路徑：** `shared/hotel/knowledge/faq.json`

---

### 6.5 JSON 範例 — Global Shared（policy）

```json
{
  "schema_version": "bats_shared_knowledge.v1",
  "data_category": "shared_knowledge",
  "owner_scope": "global",
  "industry_code": "global",
  "published_at": "2026-06-08T10:00:00+08:00",
  "source_type": "manual",
  "items": [
    {
      "item_id": "global-policy-privacy-001",
      "category": "policy",
      "item_type": "standard",
      "title": "Platform privacy policy summary",
      "content": "We process personal data in accordance with applicable privacy laws.",
      "tags": ["privacy", "platform"],
      "enabled": true
    }
  ]
}
```

**建議路徑：** `shared/global/knowledge/policy.json`

---

### 6.6 與 Tenant Contract 差異

| 項目 | Tenant（`BATS_DATA_CONTRACT.md`） | Shared（本文件） |
|------|-----------------------------------|------------------|
| **路徑** | `tenants/{sno}/knowledge/` | `shared/.../knowledge/` |
| **組織方式** | 5 固定 Tab → 5 JSON | category 驅動 |
| **租戶識別** | `tenant_sno` | 無；靠 `industry_code` / `global` |
| **產業專屬欄位** | 服務項目、特殊價格等 | **禁止**；僅通用 category + content |

---

## 7. Industry Examples

下列為 **各產業內容範例**；僅說明 `title` / `content` 語意，**不得** 寫入 Contract 成為必填欄位或產業專屬 schema。

### 7.1 `travel`（旅遊業）

| category | 範例 title |
|----------|------------|
| `faq` | Passport processing overview |
| `guide` | Visa application general steps |
| `notice` | Seasonal travel advisory |
| `reference` | Common document checklist |
| `policy` | Group tour cancellation rules |
| `compliance` | Travel insurance disclosure |

### 7.2 `hotel`（飯店民宿）

| category | 範例 title |
|----------|------------|
| `faq` | Check-in and check-out times |
| `guide` | How to request late checkout |
| `notice` | Peak season occupancy notice |
| `reference` | Standard amenity list |
| `policy` | Pet and smoking policy |
| `compliance` | Guest data retention notice |

### 7.3 `restaurant`（美食餐廳）

| category | 範例 title |
|----------|------------|
| `faq` | Reservation hold time |
| `guide` | Private room booking steps |
| `notice` | Holiday operating hours |
| `reference` | Allergen labeling reference |
| `policy` | No-show policy |
| `compliance` | Food safety compliance summary |

### 7.4 `beauty`（美業）

| category | 範例 title |
|----------|------------|
| `faq` | Appointment cancellation window |
| `guide` | First visit consultation flow |
| `notice` | Product patch test reminder |
| `reference` | Service duration reference |
| `policy` | Refund for prepaid packages |
| `compliance` | Cosmetology hygiene standards |

### 7.5 `auto`（汽車）

| category | 範例 title |
|----------|------------|
| `faq` | Warranty coverage FAQ |
| `guide` | Maintenance appointment booking |
| `notice` | Recall service bulletin |
| `reference` | Common service interval table |
| `policy` | Test drive policy |
| `compliance` | Vehicle registration compliance |

### 7.6 `retail`（零售）

| category | 範例 title |
|----------|------------|
| `faq` | Return and exchange window |
| `guide` | Online order pickup steps |
| `notice` | Flash sale terms notice |
| `reference` | Size chart reference |
| `policy` | Member points policy |
| `compliance` | Consumer protection summary |

### 7.7 `education`（教育）

| category | 範例 title |
|----------|------------|
| `faq` | Course enrollment FAQ |
| `guide` | How to access learning portal |
| `notice` | Semester schedule change |
| `reference` | Grading rubric reference |
| `policy` | Tuition refund policy |
| `compliance` | Student data protection |

### 7.8 `medical`（醫療）

| category | 範例 title |
|----------|------------|
| `faq` | Appointment booking FAQ |
| `guide` | First visit registration steps |
| `notice` | Clinic holiday hours |
| `reference` | Required documents checklist |
| `policy` | Telehealth service policy |
| `compliance` | HIPAA / local health data rules |

> **正式規則：** 上表僅為內容靈感；實作與 Validator **只** 驗證 §6 共通欄位與 §5 category，不驗證產業專屬語意。

---

## 8. Resolution Rule & Knowledge Priority

### 8.1 正式優先序（P1-7）

BATS 解析客服知識時，**Knowledge Retrieval Priority** 以 `BATS_DATA_SOURCE_REGISTRY.md` §4.4、`BATS_DATA_SYNC_POLICY.md` §18.8 為準：

```text
Level 1  Tenant Private Knowledge     tenants/{sno}/knowledge/
        ↓（若無資料）
Level 2  Industry Shared Knowledge    shared/{industry_code}/knowledge/
        ↓（若無資料）
Level 3  Global Shared Knowledge      shared/global/knowledge/
        ↓（若無資料）
Level 4  Human Service                轉人工
```

**正式原則：** Tenant Private **>** Industry Shared **>** Global Shared **>** Human Service

### 8.2 規則摘要

| 規則 | 說明 |
|------|------|
| **Tenant 優先** | Level 1 命中則採用；**結束** |
| **Shared 僅 fallback** | Level 2 / 3 **僅在** 上層無匹配時使用 |
| **Human Service** | Level 3 仍無資料 → Level 4；**禁止** AI 幻覺 |
| **禁止反向覆寫** | Shared **不得** 覆寫 Tenant 已存在答案 |
| **禁止 AI 編造** | 與 `BATS_GEMINI_RENDERER_CONTRACT.md` §9 一致 |
| **本 Contract 不變更順序** | 欄位設計不得破壞四層解析模型 |

### 8.3 比對單位（概念）

| 層級 | 比對建議 |
|------|----------|
| Tenant | `service_qa.json` 等 Tenant Contract 結構 |
| Shared | 以 `category` + 語意匹配（title / tags / content）；實作 Phase 定案 |

### 8.4 價格覆寫範例（跨文件一致）

| 層級 | 範例主題 | 值 | AI 採用 |
|------|----------|-----|---------|
| Tenant | 護照代辦 | 1600 | **1600** |
| Industry (`travel`) | 護照代辦 | 1800 | （不採用） |
| Global | 護照代辦 | 2000 | （不採用） |

### 8.5 Industry Shared First Principle

| 原則 | 說明 |
|------|------|
| **產業優先** | 明確屬於特定產業的知識 → `shared/{industry_code}/` |
| **Global 最小化** | `shared/global/` 僅跨產業共通與暫存知識 |
| **`travel` 範例** | 護照、台胞證、入境、行李、旅遊常識 → `shared/travel/` |

**Status:** Reserved For Future Multi-Industry Expansion

### 8.6 Shared Knowledge Sheet Contract

| 項目 | SSOT |
|------|------|
| **Sheet Input** | `BATS_SHARED_KNOWLEDGE_SHEET_CONTRACT.md` |
| **Travel 建議 Tabs** | `Travel_FAQ`、`Country_Entry_Rules`、`Travel_Notices`、`Baggage_Rules` |
| **JSON Output** | 本文件 §6 |

---

## 9. Ownership Rule

### 9.1 引用 SSOT

資料歸屬、寫入邊界、同步權責之 **完整定義** 見 **`BATS_DATA_OWNERSHIP_POLICY.md`**。本節僅摘要 Shared Layer 要點。

### 9.2 Industry Shared Owner

| 項目 | 說明 |
|------|------|
| **Owner** | **BBC Industry Maintainer** + BBC Admin |
| **路徑** | `shared/{industry_code}/knowledge/` |
| **可寫入** | 該產業 Shared 路徑（未來 Shared Sync 啟用後） |
| **不可寫入** | `tenants/{sno}/knowledge/`；他產業 `shared/{other}/` |

### 9.3 Global Shared Owner

| 項目 | 說明 |
|------|------|
| **Owner** | **BBC Platform** + BBC Admin |
| **路徑** | `shared/global/knowledge/` |
| **可寫入** | Global Shared 路徑（未來版本） |
| **不可寫入** | 任一租戶路徑；Industry 路徑 |

### 9.4 BDS v1 同步邊界

| 層級 | BDS v1 |
|------|--------|
| Tenant Private | **允許** 同步 |
| Industry / Global Shared | **不允許** 同步（見 Ownership §5.3、Implementation Plan §2.2） |

---

## 10. Validation Rule

### 10.1 檔案級必填

| 欄位 | 規則 |
|------|------|
| **`schema_version`** | 必填；須為 `bats_shared_knowledge.v1` |
| **`industry_code`** | 必填；Industry 為實際代碼；Global 為 `global` |
| **`owner_scope`** | 必填；`industry` 或 `global` |
| **`published_at`** | 必填；合法 ISO 8601 |
| **`data_category`** | 必填；須為 `shared_knowledge` |
| **`items`** | 必填；至少一筆 item（整檔空檔視為無效） |

### 10.2 Item 級必填

| 欄位 | 規則 |
|------|------|
| **`category`** | 必填；須為 §5.1 六類之一 |
| **`title`** | 必填；非空字串 |
| **`content`** | 必填；非空字串 |

### 10.2.1 建議欄位

| 欄位 | 規則 |
|------|------|
| **`enabled`** | 建議必填；預設 `true` |
| **`tags`** | 可選；須為字串陣列 |
| **`item_type`** | 可選 |
| **`source_type`** | 建議於 envelope 提供 |

### 10.3 一致性檢查

| 檢查 | 規則 |
|------|------|
| **路徑與 `industry_code`** | `owner_scope=industry` 時，路徑須為 `shared/{industry_code}/` |
| **Global 路徑** | `owner_scope=global` 時，`industry_code` 須為 `global` |
| **禁止 Tenant 欄位** | 不得含 `tenant_sno` 作為歸屬鍵 |
| **禁止 PII** | 不得含可識別個人之身分證、完整電話等 |

### 10.4 錯誤碼（概念）

| 錯誤碼 | 說明 |
|--------|------|
| `BSK_SCHEMA_VERSION_INVALID` | schema_version 不符 |
| `BSK_INDUSTRY_CODE_MISSING` | 缺 industry_code |
| `BSK_CATEGORY_INVALID` | category 不在允許清單 |
| `BSK_REQUIRED_FIELD_MISSING` | 缺 title / content 等 |
| `BSK_OWNER_SCOPE_MISMATCH` | owner_scope 與路徑不一致 |
| `BSK_PII_DETECTED` | 含禁止 PII |

### 10.5 Fail 策略（對齊 Tenant）

| 項目 | 說明 |
|------|------|
| **整檔 Fail** | 建議與 Tenant Contract 一致；任一 item 必填失敗 → 整檔不發布 |
| **不覆蓋** | Validation Fail 不得覆蓋既有 Shared JSON（未來 Sync 啟用時） |

---

## 11. Future Roadmap

下列項目 **保留於 Roadmap**；本版 **不定義實作**：

| 項目 | 說明 | 狀態 |
|------|------|------|
| **Shared Sync** | BDS 同步 `shared/{industry}/`、`shared/global/` | 規劃中 |
| **Approval Workflow** | Industry / Global 知識發布核准 | 規劃中 |
| **Audit Log** | Ownership 變更、發布紀錄 | 規劃中 |
| **Multilingual Knowledge** | 多語系 `content` 或 `locale` 欄位 | 規劃中 |
| **Shared RAG** | Shared 層向量檢索 | 規劃中 |
| **Knowledge Editor** | Shared Knowledge 視覺化編輯器 | 規劃中 |
| **category 擴充** | 新增第 7 類 category | 須修訂本 SSOT |

### 11.1 建議 Phase 順序

| Phase | 內容 |
|-------|------|
| **Shared Contract v1** | 本文件；Industry-Agnostic schema |
| **Shared Validator** | BSK_* 驗證器 |
| **Shared Sync v1** | Industry Maintainer 發布管線 |
| **Shared Sync v2** | Global + Approval + Audit |

---

## 12. Cross References

### 12.1 BDS 文件體系

```text
L1 SSOT（政策 / Contract）
├── BATS_DATA_SYNC_POLICY.md           同步原則、Category C shared_knowledge
├── BATS_DATA_SOURCE_REGISTRY.md       三層路徑、industry_code、Resolution Order
├── BATS_DATA_CONTRACT.md              Tenant Private Knowledge（5 Tab / 5 JSON）
├── BATS_DATA_OWNERSHIP_POLICY.md      歸屬、寫入邊界、Override
└── BATS_SHARED_KNOWLEDGE_CONTRACT.md  ← 本文件：Shared Layer JSON Contract

L2 規劃 / 維運
├── BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md   v1 僅 Tenant Sync
├── BATS_DATA_SYNC_TEST_PLAN.md
└── BATS_DATA_SYNC_RUNBOOK.md
```

### 12.2 引用對照

| 文件 | 關係 |
|------|------|
| `BATS_DATA_SYNC_POLICY.md` | §12 Category C、`shared_knowledge` 路徑、§12.7 優先序 |
| `BATS_DATA_SOURCE_REGISTRY.md` | §4 Knowledge Layer、`industry_code`、§4.4 Resolution |
| `BATS_DATA_CONTRACT.md` | §10 預留 Shared；Tenant Contract **互補不共用 schema** |
| `BATS_DATA_OWNERSHIP_POLICY.md` | §2.2 / §2.3 Owner、§3 Write Boundary、§5 Sync 範圍 |
| `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` | v1 Out of Scope：shared sync |

### 12.3 衝突處理

| 議題 | SSOT |
|------|------|
| Shared JSON 欄位與 category | **本文件** |
| 路徑與 Resolution Order | `BATS_DATA_SOURCE_REGISTRY.md` |
| 誰可寫 Shared | `BATS_DATA_OWNERSHIP_POLICY.md` |
| Tenant JSON 格式 | `BATS_DATA_CONTRACT.md` |
| BDS v1 是否同步 Shared | `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md`（不同步） |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.4** | 2026-06-10 | §8 P1-7 四層 Priority + Human Service；§8.5 Industry Shared First；§8.6 Sheet Contract cross-ref |
| **v1.3** | 2026-06-10 | §3.6 Structured Knowledge Input Source（Industry/Global Shared = Google Sheet） |
| **v1.2** | 2026-06-10 | 新增 §3.5 Google Drive Platform Layer；Shared 移出租戶資料夾 |
| **v1.1** | 2026-06-09 | 新增 §3.4 Shared Layer Governance：Default Private、Explicit Share Policy、Drive 載體 |
| **v1.0** | 2026-06-08 | 第一版：Industry-Agnostic Shared Knowledge Contract、六 category、Industry / Global JSON、Validation |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | Draft — 待審核 |
| **實作狀態** | 未實作（Contract only） |
| **BDS Sync** | Shared Layer 同步未啟用 |
| **支援產業** | travel、hotel、restaurant、beauty、auto、retail、education、medical + 可擴展 |
