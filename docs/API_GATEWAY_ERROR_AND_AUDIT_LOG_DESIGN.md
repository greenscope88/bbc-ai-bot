# API Gateway 錯誤碼與 Audit Log 設計

## 文件目的與範圍

本文件規劃 API Gateway 之 **錯誤碼**、**Tenant mapping 失敗情境**，以及 **Audit Log** 欄位與寫入時機。

**聲明**：本文件僅為規劃與規格，**不執行 SQL**、**不修改 PHP / ASP / .NET 程式**、不建立實體資料表。

---

## 錯誤碼規劃原則

- 對外錯誤碼應 **穩定、可文件化**；HTTP status 與 `errorCode` 之對應以本文件 **「凍結錯誤碼總表」** 為準。
- 命名空間：`GW_TENANT_*`（租戶／資料鏈）、`GW_AUTH_*`（認證授權）、`GW_RATE_LIMITED`（限流）、`GW_INTERNAL_ERROR`／`GW_UPSTREAM_ERROR`（系統與上游）。
- 同一請求生命週期內應產生 **traceId**，錯誤回應與 Log 共用。
- **`cid` 不存在**時優先回傳 `GW_TENANT_CID_NOT_FOUND`，**不**與 `GW_TENANT_MAPPING_FAILED` 混用（後者用於缺欄位、格式錯誤、`cid` 歧義等）。

---

## 凍結錯誤碼總表（對外 `errorCode` × HTTP Status）

以下為 **全站凍結** 對照；實作與客戶端文件應與本表一致。`eventType` 為建議稽核分類（見 Audit 一節）。

| `errorCode` | HTTP Status | 語意摘要 | 建議 `eventType`（Audit） |
|-------------|---------------|----------|---------------------------|
| `GW_TENANT_MAPPING_FAILED` | **400** | 缺必填欄位（含僅 `cid` 無 `sno`）、`cid` 格式無效、業務上不可閉合之泛稱／`cid` 歧義多筆等 | `TENANT_MAP_FAIL` |
| `GW_TENANT_CID_NOT_FOUND` | **404** | `cid` 對應 `bs_Coupon.seqNo` 不存在 | `TENANT_MAP_FAIL` |
| `GW_TENANT_SNO_CID_MISMATCH` | **403** | 請求 `sno` 與 coupon 之 `storeNo` 不一致 | `TENANT_MAP_FAIL` |
| `GW_TENANT_DEPID_VERIFICATION_FAILED` | **403** | 請求 `depID`（若有）與 coupon／store 不一致，或 **`bs_Coupon.depID` ≠ `bs_store.depID`** | `TENANT_MAP_FAIL` |
| `GW_TENANT_STORE_NOT_FOUND` | **503** | coupon 之 `storeNo` 於 `bs_store.uid` 無對應列（資料鏈斷裂） | `TENANT_MAP_FAIL` |
| `GW_AUTH_MISSING_CREDENTIALS` | **401** | 未帶或空白 API Key／Token 等 | `AUTH_FAIL` |
| `GW_AUTH_INVALID_CREDENTIALS` | **401** | API Key／Token 無效、簽章錯誤 | `AUTH_FAIL` |
| `GW_AUTH_EXPIRED_CREDENTIALS` | **401** | 憑證或 Token 已過期 | `AUTH_FAIL` |
| `GW_AUTH_FORBIDDEN` | **403** | 憑證有效但無權存取該資源／操作 | `AUTH_FAIL` |
| `GW_RATE_LIMITED` | **429** | 觸發限流／配額 | `RATE_LIMIT` |
| `GW_INTERNAL_ERROR` | **500** | Gateway 內部未預期錯誤（對外不附堆疊） | `GATEWAY_ERROR` |
| `GW_UPSTREAM_ERROR` | **502** | 上游／後端連線失敗、逾時、非 2xx 且無法安全轉譯 | `GATEWAY_ERROR` |

**相容註記**：舊稿或程式中若出現 `GW_AUTH_INVALID`，應視為 **`GW_AUTH_INVALID_CREDENTIALS`** 之同義逐步汰除。

---

## Tenant 與資料一致性相關錯誤（詳述）

### 1. Tenant mapping 失敗（泛稱）— `GW_TENANT_MAPPING_FAILED`

| 項目 | 內容 |
|------|------|
| **HTTP** | **400** |
| **語意** | 無法完成合法閉合：缺 `cid`／缺 `sno`（含不允許僅 `cid`）、`cid` 格式無效、多筆 `seqNo` 歧義等（**不含**「`cid` 單筆不存在」—該情形見下節） |
| **對外訊息** | 通用化文案 |

### 2. `cid` 不存在 — `GW_TENANT_CID_NOT_FOUND`

| 項目 | 內容 |
|------|------|
| **HTTP** | **404** |
| **語意** | 以 `cid` 對應 `bs_Coupon.seqNo` 查無符合業務規則之記錄 |
| **對外訊息** | 通用化文案，避免洩漏內部表名或鍵值 |

### 3. `sno` / `cid` 不匹配 — `GW_TENANT_SNO_CID_MISMATCH`

| 項目 | 內容 |
|------|------|
| **HTTP** | **403** |
| **語意** | `cid` 所對應 coupon 之 `storeNo` 與請求 `sno` 不一致 |

### 4. `depID` 交叉驗證失敗 — `GW_TENANT_DEPID_VERIFICATION_FAILED`

| 項目 | 內容 |
|------|------|
| **HTTP** | **403** |
| **語意** | 請求帶入之 `depID`（若有）與 `bs_Coupon.depID` 或 `bs_store.depID` 不一致；**或** `bs_Coupon.depID` ≠ `bs_store.depID`（coupon／store 資料不一致） |

### 5. 門市主檔查無 — `GW_TENANT_STORE_NOT_FOUND`

| 項目 | 內容 |
|------|------|
| **HTTP** | **503** |
| **語意** | `bs_Coupon.storeNo` 於 `bs_store.uid` 無對應列 |

### 錯誤碼與回應格式

統一 JSON 欄位（`success`、`errorCode`、`message`、`traceId`、`timestamp`、`details`）見 `API_GATEWAY_RESPONSE_AND_LOG_SCHEMA_DESIGN.md`。

---

## Audit Log 欄位規劃

以下為 **邏輯欄位** 建議；實際儲存媒體（檔案、DB、SIEM）於實作階段決定。

| 欄位 | 說明 | 建議 |
|------|------|------|
| `timestamp` | 事件時間（UTC） | 必填，ISO-8601 |
| `traceId` | 請求追蹤 ID | 必填 |
| `level` | 日誌層級 | `INFO` / `WARN` / `ERROR` |
| `eventType` | 事件類型 | 例如 `TENANT_MAP_OK`、`TENANT_MAP_FAIL`、`API_ACCESS` |
| `errorCode` | 失敗時之對外錯誤碼 | 成功可為空 |
| `sno` | 請求解析後之門市識別 | 遮罩或部分遮罩政策另訂 |
| `cid` | 請求之 coupon 識別 | 遮罩政策另訂 |
| `depID` | 請求或解析後部門 ID | 視敏感度 |
| `apiKeyFingerprint`（或稽核表併列之 `clientId` 尾碼） | 呼叫端識別 | **建議欄位名** `apiKeyFingerprint`：只存 hash 或尾碼（與 `API_GATEWAY_RESPONSE_AND_LOG_SCHEMA_DESIGN.md` 一致） |
| `clientIp` | 來源 IP | 必填（若可得） |
| `requestPath` | API path | 必填 |
| `httpMethod` | HTTP 方法 | 建議 |
| `durationMs` | 處理耗時 | 建議 |
| `logCategory` | 建議 `ACCESS`／`AUDIT`／`ERROR` | 建議與 RESPONSE 文件一致 |
| `result` | 成功／失敗摘要（**Log 專用**；HTTP body 用 `success`） | `SUCCESS`／`FAILURE`／`ERROR` |
| `message` | 內部簡述 | 不應含 PII 原文密碼 |

型別與索引建議見 `API_GATEWAY_RESPONSE_AND_LOG_SCHEMA_DESIGN.md`。

---

## Log 寫入時機

| 時機 | 建議 `eventType` / 說明 |
|------|-------------------------|
| Tenant mapping **成功**且即將進入業務處理 | `TENANT_MAP_OK` 或合併於 `API_ACCESS` 之成功分支 |
| Tenant mapping **失敗**（含 `cid` 不存在、`sno`/`cid` 不符、`depID` 驗證失敗、`bs_store` 查無） | `TENANT_MAP_FAIL`，**必寫** `errorCode`、`traceId` |
| 認證／授權失敗 | `AUTH_FAIL`，`errorCode` 為 `GW_AUTH_*` 之一 |
| 限流 | `RATE_LIMIT`，`errorCode` = `GW_RATE_LIMITED` |
| 未預期例外（Gateway 內部）／上游失敗 | `GATEWAY_ERROR`，`errorCode` = `GW_INTERNAL_ERROR` 或 `GW_UPSTREAM_ERROR`，`level=ERROR`，避免將堆疊全文寫入對外可讀 store |

**原則**：凡拒絕請求之 **tenant 相關原因**，皆應留下可追溯之稽核記錄，以利安全調查與誤用偵測。

---

## 與其他文件之關係

- 回應 JSON 與 `details` 結構：`API_GATEWAY_RESPONSE_AND_LOG_SCHEMA_DESIGN.md`
- Middleware 寫入點與告警：`API_GATEWAY_MIDDLEWARE_AND_TESTCASE_PLAN.md`
- Tenant 欄位與表關聯：`API_GATEWAY_TENANT_MAPPING_DESIGN.md`
- 實作前檢查與修訂紀錄：`API_GATEWAY_IMPLEMENTATION_PRECHECK.md`
