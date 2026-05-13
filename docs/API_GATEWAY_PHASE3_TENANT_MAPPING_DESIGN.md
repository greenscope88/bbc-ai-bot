# API Gateway Phase 3 - Tenant Mapping Design

## 1. 文件目的

本文件為 **API Gateway Phase 3 的第一份設計文件**，用來定義 SaaS 對外租戶識別 **`sno`** 與 Host B 內部識別鍵（如 `provider_id_no`、`depID`、`store_uid`、`storeNo`）之間的 **安全映射方式**、Gateway 內責任邊界、錯誤契約與與後續子文件之關係。

本文件 **僅規劃**，不包含 DDL、不含 production 程式變更、不接正式流量。

---

## 2. 背景與前提

- **Phase 1** 已完成 `GatewayKernel`、`TraceIdMiddleware`、`ErrorResponseBuilder` 與 CLI 測試（`tests/api_gateway/test_gateway_phase1.php`）。
- **Phase 2** 已完成 **isolated HTTP Entry** 驗證（`bbc-ai-gateway-test` 路徑下之 HTTP JSON、`traceId`、query 解析等）。
- **目前尚未進入** production API 功能實作（不接正式 SaaS / LINE / Web Chat 流量）。
- **最新 schema 快照（分析依據）：**  
  `C:\bbc-ai-bot\db\schema_snapshots\schema_only_20260513_before_api_gateway_phase3.sql`
- **Cursor 已完成 schema 解析**（唯讀檔案與搜尋，未執行 SQL）。
- **解析結果摘要：**
  - 目前 Host B 既有 schema **未發現**可直接作為 API Gateway **tenant key** 的獨立 **`sno`** 欄位或表。
  - schema 中出現的 `sno` 字樣多為 **`classNo`、`bonusNo`** 等識別字之 **子字串片段**，**不可**視為 SaaS 對外之 **`sno`**。
- Host B 既有多租戶／組織／門市識別主要涉及：
  - **`bs_Provider.id_no`**
  - **`depID`**（大量 `bs_*` 表之整數欄位）
  - **`bs_store.uid`**
  - **`storeNo` / `StoreNo`**（業務表與 `bs_Member` 等）
- **`bs_Provider` 主表沒有 `depID` 欄位**，主鍵為 **`id_no`**；另有 **`depIDNo`**（nvarchar）等，與整數 **`depID`** 不同名。
- **`depID` 大量出現**於 `bs_*` 業務表與 Provider 週邊表（如 `bs_ProviderAffiliatedSetting`、`bs_ProviderService`、`bs_ProviderUnitGrp` 等）。
- **`storeNo` / `StoreNo`** 常見於業務表與 **`bs_Member`**；多處 view 會將 **`bs_store.uid` 別名為 `storeNo`**。
- **因此 Phase 3 不直接修改既有業務資料表**，改以 **獨立 Tenant Mapping** 設計承載 SaaS `sno` 與 Host B 內部鍵之對照與政策欄位。

---

## 3. Tenant Mapping 核心原則

- **`sno` 是 SaaS 對外租戶識別**（由 SaaS 核發／註冊，對外可見；**不可**當成 secret）。
- **`sno` 不等於** Host B 現有任一資料表欄位名；為 **Gateway / SaaS 層** 的概念鍵。
- **Host B 內部識別**可能包含（依租戶型態組合）：**`provider_id_no`、`depID`、`store_uid`、`storeNo`**。
- **`provider_id_no`** 對應 **`bs_Provider.id_no`**。
- **`store_uid`** 對應 **`bs_store.uid`**。
- **`storeNo` / `StoreNo`** 為既有業務表中之 **門市識別欄位**（與 `depID` 常並用）。
- **`depID`** 為大量業務表中之 **組織／供應商／部門維度** 整數欄位。
- **`sno` 不可由 AI 自行猜測**；僅接受請求中明確、已驗證格式之輸入。
- **`sno` 不可跨 tenant 使用**（單一請求生命週期內 mapping 結果須一致且不可被覆寫為他租戶）。
- **未提供 `sno` 的正式 API 請求不得進入 Host B Proxy**（在 API Key 與 Proxy 之前即拒絕）。
- **找不到 mapping 的 `sno`** 必須回傳 **標準錯誤 JSON**（`ErrorResponseBuilder` 契約，`details` 不含敏感資訊）。
- **停用 tenant**（`disabled` / `suspended`）**不可通過 Gateway** 進入後端業務 Proxy。
- **所有錯誤回應都必須帶 `traceId`**（與 Phase 1 一致）。
- **Tenant Mapping 只負責租戶識別與政策欄位載入**，**不**等同「API 已授權」；**不**直接授權敏感資料存取。
- **API Key 才是授權憑證**（驗證見後續 `API_GATEWAY_PHASE3_API_KEY_VERIFICATION_DESIGN.md`）。

---

## 4. 建議資料表：`api_gateway_tenant_mapping`

> **注意：** 本章只做設計，**不建立 SQL**、不執行 DDL。

| 欄位 | 型態（建議） | 說明 |
|------|----------------|------|
| `id` | bigint / uniqueidentifier（擇一） | 主鍵 |
| `sno` | varchar / nvarchar（固定長度或 UUID 字串，依 SaaS 規格） | SaaS 對外租戶識別 |
| `tenant_name` | nvarchar | 顯示／稽核用名稱（非機密） |
| `provider_id_no` | int NULL | 對應 **`bs_Provider.id_no`** |
| `depID` | int NULL | Host B 大量 `bs_*` 表之資料範圍維度 |
| `store_uid` | int NULL | 對應 **`bs_store.uid`** |
| `storeNo` | int NULL | 相容業務表 **`storeNo` / `StoreNo`** 語意 |
| `tenant_status` | varchar | 見下 |
| `allowed_services` | nvarchar(max) 或 JSON 字串 | 允許之 service id 清單 |
| `host_b_base_url` | varchar(500) | 該租戶 Host B API 基底 URL（**僅 server-side 使用**） |
| `api_profile` | varchar(50) | 功能／行為 profile |
| `rate_limit_profile` | varchar(50) | 限流方案識別 |
| `notes` | nvarchar(max) NULL | 內部註記（不可寫入客戶端） |
| `created_at` | datetime2 | 建立時間（UTC） |
| `updated_at` | datetime2 | 更新時間（UTC） |

**補充說明：**

- **`sno` 應唯一**（資料庫層建議 unique index；實作於 migration 階段處理）。
- **`provider_id_no`** 對應 **`bs_Provider.id_no`**。
- **`depID`** 用於 Host B 大量 `bs_*` 業務表之 **資料範圍控制** 與 join 鍵。
- **`store_uid`** 對應 **`bs_store.uid`**。
- **`storeNo`** 用於相容既有業務表中 **`storeNo` / `StoreNo`** 欄位與慣例（含 view 別名）。
- **`provider_id_no`、`depID`、`store_uid`、`storeNo` 之間的實際組合與必填規則**，需後續依 **業務資料樣本與安全查詢** 確認（不同旅行社可能僅需 `provider_id_no + depID`，或需門市維度）。
- **`tenant_status` 建議值：** `active` / `disabled` / `suspended`（可再擴充 `pending` 等）。
- **`allowed_services`：** 限制此 tenant 可呼叫之 API service（例如 `tour.search`），供 Proxy 與稽核使用。
- **`host_b_base_url`：** 預留 **多 Host B**、**多 API server**、或 **藍綠切換**。
- **`api_profile`：** 對應不同旅行社可用功能、逾時、錯誤對照等（細節見 Implementation Plan）。
- **`rate_limit_profile`：** 對應不同流量方案（細節見 Rate Limit 設計文件）。

---

## 5. 不建議直接修改既有主資料表

**不建議**把 API Gateway 專用欄位直接加到以下表：

- **`bs_store`**
- **`bs_Provider`**
- **`bs_Member`**

**原因：**

- **`bs_Provider`、`bs_store`、`bs_Member`** 皆含 **大量敏感欄位** 與長期業務邏輯依賴。
- **`bs_Provider`** 含 `GoogleMapAPIKey`、`GooglePeopleAPIKey`、`LineApiKey`、`ChannelSecret_LinePay`、`hashKey` / `hashIV`、多組金流與外部 API 憑證等。
- **`bs_store`** 含金流、物流、`password`、發票與門市營運欄位等。
- **`bs_Member`** 含會員登入、個資、`LineLoginId`、`Email`、手機、`身分證字號` 等。
- **避免污染**既有業務資料表與 **ASP / ASP.NET** 既有系統假設。
- **避免** API Gateway 與舊業務邏輯 **過度耦合**。
- **有利於** SaaS 化、**獨立 migration**、版本控管與權限最小化。

---

## 6. Gateway 流程位置

**文字流程：**

```
HTTP Entry
→ TraceIdMiddleware
→ ErrorResponseBuilder（錯誤路徑組裝；成功回應可於最後輸出）
→ GatewayKernel
→ Tenant Mapping Resolver
→ API Key Verification
→ Service Permission Check
→ Rate Limit Check
→ Host B API Proxy
→ Normalize Response
→ Audit Log
→ Response
```

**Tenant Mapping Resolver 責任：**

- 解析 request 中的 **`sno`**（例如 header 或受控 query／path 規格，於 API Key 設計中凍結）。
- 查詢 **`sno`** 對應之 **`provider_id_no` / `depID` / `store_uid` / `storeNo`**（來自 **mapping 表或受控快取**，非 client 任意指定）。
- 檢查 **`tenant_status`**。
- 檢查 **`allowed_services`** 是否涵蓋本次呼叫之 service。
- 產生 **`tenant context`**（純 server-side 結構）。
- 將 **`tenant context`** 傳給後續 **API Key / Proxy / Log / Rate Limit** 模組。
- **不接受** client 以直接傳入 **`depID` / `storeNo` / `provider_id_no` / `store_uid`** 取代 **`sno`**（防繞過與跨租戶竄改）。

---

## 7. Tenant Context 建議格式

以下為 **PHP array 概念範例**（**不需**在本階段實作）：

```php
[
    'sno' => 'e1fd133c7e8e45a1',
    'tenantName' => 'Example Travel',
    'providerIdNo' => 1,
    'depID' => 1,
    'storeUid' => 1001,
    'storeNo' => 1001,
    'status' => 'active',
    'allowedServices' => ['tour.search', 'order.query'],
    'hostBBaseUrl' => 'http://103.1.222.11:8080',
    'apiProfile' => 'standard',
    'rateLimitProfile' => 'standard',
]
```

---

## 8. 錯誤情境設計

以下 **`errorCode`** 為建議字串，實作時需與 `ErrorResponseBuilder` 及文件全集對齊。

| 情境 | errorCode（建議） | HTTP status | 是否寫入 Audit Log | 回應含 traceId |
|------|-------------------|-------------|---------------------|----------------|
| 缺少 `sno` | `MISSING_SNO` | **400** | 是（拒絕原因摘要，無敏感 payload） | 是 |
| `sno` 格式錯誤 | `INVALID_SNO_FORMAT` | **400** | 是 | 是 |
| `sno` 找不到 mapping | `TENANT_NOT_FOUND` | **404** | 是 | 是 |
| `tenant_status = disabled` | `TENANT_DISABLED` | **403** | 是 | 是 |
| `tenant_status = suspended` | `TENANT_SUSPENDED` | **403** | 是 | 是 |
| tenant 無權使用指定 service | `SERVICE_NOT_ALLOWED` | **403** | 是 | 是 |
| `provider_id_no` 未設定 | `TENANT_MAPPING_INCOMPLETE_PROVIDER` | **500** 或 **503**（依營運政策） | 是 | 是 |
| `depID` 未設定 | `TENANT_MAPPING_INCOMPLETE_DEPID` | 同上 | 是 | 是 |
| `store_uid` / `storeNo` 未設定（若該 service 必填） | `TENANT_MAPPING_INCOMPLETE_STORE` | **400** 或 **503** | 是 | 是 |
| Host B base URL 未設定 | `TENANT_MAPPING_INCOMPLETE_HOST` | **503** | 是 | 是 |
| client 嘗試直接傳入 `depID` / `storeNo`（或其他內部鍵）取代 `sno` | `FORBIDDEN_TENANT_KEY_OVERRIDE` | **403** | 是 | 是 |

> **備註：** `500` vs `503` 可依「設定錯誤」vs「暫時無法路由」區分；於 Implementation Plan 統一。

---

## 9. 安全設計

- **`sno` 不可視為 secret**；不得單憑 `sno` 即視為已授權存取 Host B 敏感資料。
- **API Key 才是授權憑證**（與 Tenant Mapping **分層**）。
- **Tenant Mapping 不可跳過**（正式 API 路徑必經 Resolver）。
- **Tenant Mapping 結果不可由 client 端自行提供** `provider_id_no` / `depID` / `store_uid` / `storeNo`；上述鍵 **必須**由 **Gateway server-side resolver** 產生或自受控儲存載入。
- **所有 Host B Proxy 請求**必須使用 **resolver 後之 tenant context**（與 trace、金鑰驗證結果一併傳遞）。
- **不允許** client 直接傳 `depID` / `storeNo` **取代** `sno`（與第 6 節一致）。
- **未來可加 tenant mapping cache**；cache **必須**在 `tenant_status` 變更或撤銷時 **快速失效**（TTL + 主動失效併用為佳）。
- **Gateway 不應直接讀取或回傳** `bs_Provider` / `bs_store` / `bs_Member` 之 **敏感欄位** 給 client。
- 若需查核既有表，應透過 **白名單 view**、**stored procedure** 或 **受控 repository**（最小欄位、參數化查詢、與稽核）。

---

## 10. 與後續 Phase 3 文件的關係

本文件作為以下文件之 **共同前提與名詞／流程基礎**：

- `docs/API_GATEWAY_PHASE3_API_KEY_VERIFICATION_DESIGN.md`
- `docs/API_GATEWAY_PHASE3_HOST_B_PROXY_DESIGN.md`
- `docs/API_GATEWAY_PHASE3_AUDIT_LOG_DESIGN.md`
- `docs/API_GATEWAY_PHASE3_RATE_LIMIT_DESIGN.md`
- `docs/API_GATEWAY_PHASE3_IMPLEMENTATION_PLAN.md`

後續文件應 **引用**本章之 **`sno`、tenant context、錯誤碼**，並補齊金鑰驗證、Proxy 路由、稽核欄位、限流演算法與實作切分。

---

## 11. 本階段不做事項

- **不建立資料表**。
- **不執行 SQL**。
- **不修改 production PHP**。
- **不修改 `.htaccess`**。
- **不接正式 API**。
- **不接 LINE Webhook**。
- **不接 Web Chat**。
- **不實作 API Key**。
- **不實作 Host B Proxy**。
- **不直接查詢或修改** `bs_Provider` / `bs_store` / `bs_Member`（僅能於未來實作階段經白名單與審核後為之）。

---

## 12. Cursor 執行回報

1. **文件是否已建立：** 是。  
2. **文件完整路徑：** `C:\bbc-ai-bot\docs\API_GATEWAY_PHASE3_TENANT_MAPPING_DESIGN.md`  
3. **是否只建立文件：** 是。  
4. **是否完全沒有修改正式 PHP：** 是。  
5. **是否完全沒有修改 `.htaccess`：** 是。  
6. **是否完全沒有執行 SQL：** 是。  
7. **是否沒有 `git add` / `commit` / `push`：** 是。  
8. **建議下一步文件：** 撰寫 **`API_GATEWAY_PHASE3_API_KEY_VERIFICATION_DESIGN.md`**（定義 `sno` + API Key 雙層驗證順序、header 名稱、錯誤碼與與 tenant context 合併規則），接著 **`HOST_B_PROXY`** 與 **`AUDIT_LOG`**。
