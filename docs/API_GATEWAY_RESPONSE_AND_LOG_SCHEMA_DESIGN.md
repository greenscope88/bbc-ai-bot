# API Gateway 統一回應與 Log Schema 設計

## 文件目的與範圍

本文件定義 API Gateway **統一錯誤（及可選成功）回應 JSON 規格**，並建議 **Audit／Access Log** 欄位型別與索引策略。

**聲明**：僅規劃文件，**不執行 SQL**、**不修改 PHP / ASP / .NET 程式**。

---

## 對外 API Response 規格（凍結）

### 命名一致性

- 租戶相關請求欄位與本系列文件一律使用 **`sno`**、**`cid`**、**`depID`**（**不使用** `cID` 或其他變體）。

### 錯誤回應 JSON（HTTP 4xx／5xx 對外 body）

所有由 Gateway 產生之 **錯誤** 回應，body 頂層 **必須** 包含下列欄位（與 `API_GATEWAY_ERROR_AND_AUDIT_LOG_DESIGN.md` 錯誤碼總表一致）：

| 欄位 | 型別（邏輯） | 必填 | 說明 |
|------|--------------|------|------|
| `success` | boolean | 是 | 錯誤時 **固定** `false` |
| `errorCode` | string | 是 | 凍結總表中之對外碼，例如 `GW_TENANT_CID_NOT_FOUND` |
| `message` | string | 是 | 對人可讀、**不洩漏內部實作**（無堆疊、無表名／欄位名） |
| `traceId` | string | 是 | 與 Access／Audit／Error Log 一致 |
| `timestamp` | string | 是 | ISO-8601 UTC，例如 `2026-05-12T08:00:00.000Z` |
| `details` | object \| null | 否 | 白名單結構化附註（見下）；無則 `null` 或省略（實作擇一並全站一致） |

**`traceId` 載體（凍結）**：除 body 外，建議 **同時** 回應 HTTP Header **`X-Trace-Id`**（值與 body 之 `traceId` 相同），便於客戶端與 CDN／WAF 追蹤。

---

## 統一錯誤回應 JSON 規格（與上表相同，供快速對照）

### 頂層欄位（必填性）

| 欄位 | 型別（邏輯） | 必填 | 說明 |
|------|--------------|------|------|
| `success` | boolean | 是 | 錯誤時固定為 `false` |
| `errorCode` | string | 是 | 穩定機讀碼，例如 `GW_TENANT_CID_NOT_FOUND` |
| `message` | string | 是 | 對人可讀、**不洩漏內部實作**之說明 |
| `traceId` | string | 是 | 與 Access／Audit Log 一致，供客服與工程追查 |
| `timestamp` | string | 是 | ISO-8601 UTC，例如 `2026-05-12T08:00:00.000Z` |
| `details` | object \| null | 否 | 結構化附註；對外 API 應限制可出現之 key，避免任意堆疊 |

### 錯誤回應範例（示意）

```json
{
  "success": false,
  "errorCode": "GW_TENANT_SNO_CID_MISMATCH",
  "message": "Tenant validation failed.",
  "traceId": "a1b2c3d4e5f67890",
  "timestamp": "2026-05-12T08:00:00.000Z",
  "details": {
    "subCode": null,
    "hint": "Contact support with traceId if the issue persists."
  }
}
```

### `details` 使用準則

- 僅允許 **白名單** key（例如 `hint`、`retryable`、`subCode`）。
- **禁止**放入完整 SQL、堆疊、內部主鍵原文（除非經過審查之公開 ID 政策）。

### 成功回應（可選一致性）

若全站採 envelope 風格，成功可為：

```json
{
  "success": true,
  "data": { },
  "traceId": "...",
  "timestamp": "..."
}
```

是否採用與現有 BBC AI API 慣例對齊，於實作前與既有客戶端約定。

---

## Log 語意凍結：`success` 與 `result` 分工

| 使用處 | 欄位 | 型別／值域 | 說明 |
|--------|------|------------|------|
| **HTTP JSON 回應 body**（成功或錯誤 envelope） | **`success`** | boolean | 成功 `true`、錯誤 `false`；**不寫入** Access／Audit 欄位與之同名冗餘（避免雙重語意） |
| **Access Log／Audit Log／結構化 Error Log**（持久化或下游 SIEM） | **`result`** | string | **`SUCCESS`**：請求於 Gateway 視角正常完成（含 2xx 回應送出）；**`FAILURE`**：可預期拒絕（4xx、業務錯誤）；**`ERROR`**：未預期內部錯誤或上游嚴重失敗（5xx、`GW_INTERNAL_ERROR`、`GW_UPSTREAM_ERROR`） |

**禁止**：在同一筆 **log 列** 同時出現 `success`（boolean）與 `result`（string）以外的重複布林欄位（例如不得再加 `ok`）。

---

## Access Log／Audit Log／Error Log 共通欄位（凍結）

以下為三類 log **建議共用核心**；實作可依儲存媒體增刪 **非核心** 欄位（如 `userAgent`）。

| 欄位 | 說明 | Access | Audit | Error（結構化） |
|------|------|:------:|:-----:|:---------------:|
| `timestamp` | UTC 事件時間 | ✓ | ✓ | ✓ |
| `traceId` | 請求關聯 | ✓ | ✓ | ✓ |
| `logCategory` | 建議值 `ACCESS` / `AUDIT` / `ERROR` | ✓ | ✓ | ✓ |
| `result` | `SUCCESS`／`FAILURE`／`ERROR` | ✓ | ✓ | ✓ |
| `httpStatus` | 對客戶端 HTTP 狀態碼 | ✓ | 選填 | ✓ |
| `errorCode` | 凍結總表之碼；成功可空 | 選填 | 選填 | ✓（失敗時） |
| `message` | 內部或對外訊息摘要（無密碼／無完整堆疊） | 選填 | 選填 | ✓ |
| `level` | `INFO`／`WARN`／`ERROR` | INFO 為主 | INFO／WARN | **ERROR** 為主 |
| `sno` | 遮罩依政策 | ✓ | ✓ | 選填 |
| `cid` | 遮罩依政策 | ✓ | ✓ | 選填 |
| `depID` | 依政策 | ✓ | ✓ | 選填 |
| `apiKeyFingerprint` | API Key 之 hash 或尾碼，**禁止**明文 | ✓ | ✓ | ✓ |
| `clientIp` | 來源 IP | ✓ | ✓ | ✓ |
| `requestPath` | Path（是否含 query 全專案一致） | ✓ | ✓ | ✓ |
| `httpMethod` | GET／POST… | ✓ | 選填 | ✓ |
| `durationMs` | Gateway 處理耗時（ms） | ✓ | 選填 | ✓ |
| `eventType` | 如 `TENANT_MAP_OK`、`TENANT_MAP_FAIL`、`AUTH_FAIL` | 可與 Access 合併 | **✓** | 選填 |

**Audit 專屬**：`eventType` 建議必填，並與 `API_GATEWAY_ERROR_AND_AUDIT_LOG_DESIGN.md` 之寫入時機一致。

**Error Log 專屬**：可增 **`stackTrace`** 或 **`exceptionType`**（**僅內部** store，不寫入對外 API body）。

---

## Log 欄位型別建議

| 欄位 | 建議型別 | 說明 |
|------|----------|------|
| `timestamp` | `timestamp with time zone` / 等同 UTC 儲存 | 一律 UTC |
| `traceId` | `varchar(64)` 或 `uuid` | 依產生演算法固定長度 |
| `logCategory` | `varchar(16)` | `ACCESS` / `AUDIT` / `ERROR` |
| `result` | `varchar(16)` | **`SUCCESS`** / **`FAILURE`** / **`ERROR`**（與 HTTP body 的 `success` 分工見上表） |
| `errorCode` | `varchar(64)` | 可建立檢查約束或維度表（實作階段） |
| `message` | `varchar(512)` 或 `text` | 內部訊息長度控管 |
| `sno` | `varchar(32)`（長度依實際 domain） | 視是否需索引 |
| `cid` | `varchar(64)` | 視是否需索引 |
| `depID` | `varchar(32)` 或整數型 | 依現行 `bs_*` 慣例 |
| `apiKeyFingerprint` | `varchar(128)` | 建議存 hash；與 Audit 表之 `apiKey`／`clientId` 欄位語意對齊時，以 **fingerprint** 為準 |
| `clientIp` | `varchar(45)` | 支援 IPv6 |
| `requestPath` | `varchar(512)` | 含 query 與否應一致定義 |
| `httpMethod` | `varchar(8)` | GET/POST/… |
| `durationMs` | `integer` | 非負 |
| `userAgent` | `varchar(512)` | 可選 |
| `details` | `jsonb` / `json` | 若資料庫儲存結構化附註 |

---

## Log 索引建議

以下為 **查詢與維運** 導向之索引建議（DDL 於實作階段執行，本文件不執行 SQL）。

| 索引用途 | 建議欄位組合 | 說明 |
|----------|--------------|------|
| 依請求追查 | `(traceId)` | 唯一或高度選擇性查詢 |
| 依時間區間掃描 | `(timestamp DESC)` | 列表、清理、報表 |
| 依錯誤監控 | `(timestamp, errorCode)` | 錯誤率、特定碼爆量 |
| 依租戶維度 | `(timestamp, sno)`、`(timestamp, depID)` | 依門市／部門鑽取（視查詢模式） |
| 依 API 維度 | `(timestamp, requestPath)` | 熱點 path |
| 複合稽核 | `(clientIp, timestamp)` | 異常 IP 調查（注意資料量） |

**維護注意**：高寫入 log 表應規劃 **分區／保留天數／非同步寫入**，避免影響 Gateway 延遲。

---

## 欄位規劃總覽（`sno`、`cid`、`depID`、`apiKey`／`apiKeyFingerprint`、`clientIp`、`requestPath`、`errorCode`、`traceId`）

| 欄位 | 出現在 HTTP JSON | 出現在 Audit Log | 備註 |
|------|------------------|-------------------|------|
| `sno` | 通常不出現在錯誤 JSON | 建議有（遮罩策略） | 屬租戶脈絡 |
| `cid` | 通常不出現在錯誤 JSON | 建議有（遮罩策略） | 屬租戶脈絡 |
| `depID` | 通常不出現在錯誤 JSON | 建議有 | 交叉驗證用 |
| `apiKeyFingerprint`（或等價之 `clientId` 遮罩） | 永不回傳明文 | 存 hash 或尾段 | 防洩漏 |
| `clientIp` | 不回傳 | 建議有 | 安全分析 |
| `requestPath` | 可不回傳或僅回傳 generic | 建議有 | 與路由監控一致 |
| `errorCode` | 必填 | 必填（失敗時） | 與監控對齊 |
| `traceId` | 必填 | 必填 | 關聯客戶通報與後端 log |

---

## 與其他文件之關係

- 凍結錯誤碼總表與 Audit 寫入時機：`API_GATEWAY_ERROR_AND_AUDIT_LOG_DESIGN.md`
- Tenant mapping 與邊界規則：`API_GATEWAY_TENANT_MAPPING_DESIGN.md`
- Middleware 與測試：`API_GATEWAY_MIDDLEWARE_AND_TESTCASE_PLAN.md`
- 實作前檢查與修訂紀錄：`API_GATEWAY_IMPLEMENTATION_PRECHECK.md`
