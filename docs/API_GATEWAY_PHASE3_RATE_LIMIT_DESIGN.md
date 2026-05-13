# API Gateway Phase 3 - Rate Limit Design

## 1. 文件目的

本文件定義 API Gateway Phase 3 的 Rate Limit 設計，目標是在 Tenant Mapping、API Key 驗證與 Service Permission 通過後，對請求頻率進行可控限制，避免單一租戶或金鑰造成系統壓力，並維持 Host B 可用性。

---

## 2. 背景與前提

- Tenant Mapping Design 已完成並審核 PASS。
- API Key Verification Design 已完成並審核 PASS。
- Host B API Proxy Design 已完成並審核 PASS。
- Audit Log Design 已完成並審核 PASS。
- Host A 負責統一入口與防護策略，Host B 為既有業務系統主機。
- Rate Limit 屬於保護層，不取代授權層（API Key）與租戶識別層（Tenant Mapping）。

---

## 3. Rate Limit 控制層級

建議控制層級如下：

- Tenant 層（`sno`）  
  控制單一租戶整體流量上限，避免單租戶壓垮系統。
- API Key 層（`api_key_id`）  
  控制單一金鑰行為，避免同租戶內某把 key 異常爆量。
- Service 層（`service`）  
  不同 service 可設定不同限制（例如查詢型較高、重型查詢較低）。
- Client IP 層（`client_ip`）  
  作為進階保護，降低惡意來源高頻請求風險。

---

## 4. 建議資料表：`api_gateway_rate_limit_policy`

> 注意：本章只做設計，不建立 SQL。

建議以 policy 表管理限流規則，再由 runtime 限流元件套用規則計數。

---

## 5. 建議欄位

| 欄位 | 說明 |
|---|---|
| `id` | 主鍵 |
| `policy_name` | 規則名稱（例如 `standard_default`） |
| `sno` | 指定租戶（可為 null 代表全域） |
| `api_key_id` | 指定 API Key（可為 null） |
| `service` | 指定 service（可為 null 代表通用） |
| `limit_per_minute` | 每分鐘上限 |
| `limit_per_hour` | 每小時上限 |
| `limit_per_day` | 每日上限 |
| `burst_limit` | 瞬時突發上限 |
| `policy_status` | 規則狀態（如 `active` / `disabled`） |
| `created_at` | 建立時間（UTC） |
| `updated_at` | 更新時間（UTC） |

補充：

- 規則套用優先序可採「api_key_id + service」最精準，fallback 至 `sno + service`，再 fallback 至全域預設。

---

## 6. Rate Limit Key 設計

限流 key 建議由多維度組成，避免單一維度失真：

- by `sno`
- by `api_key_id`
- by `service`
- by `client_ip`

範例 key（概念）：

`rl:{sno}:{api_key_id}:{service}:{client_ip}:{window}`

說明：

- `window` 可為 `1m` / `1h` / `1d`。
- 對隱私敏感環境，`client_ip` 可先標準化或遮罩後再組 key。

---

## 7. 檢查順序

檢查順序應為：

Tenant Mapping  
→ API Key Verification  
→ Service Permission Check  
→ Rate Limit Check  
→ Host B API Proxy

理由：

- 先確定租戶與授權，再做限流可避免無效身份消耗限流資源。
- Rate Limit Check 必須在 Host B Proxy 前執行，超限請求不得進入 Host B。

---

## 8. 超限錯誤設計

超限時回應需標準化：

- `errorCode`：建議 `RATE_LIMIT_EXCEEDED`
- HTTP status：**429**
- 回應必須包含 `traceId`
- 是否寫入 Audit Log：**是**

建議錯誤 JSON（概念）：

```json
{
  "success": false,
  "traceId": "abc123...",
  "errorCode": "RATE_LIMIT_EXCEEDED",
  "message": "Rate limit exceeded. Please retry later."
}
```

---

## 9. 與 Audit Log 的關係

- 每次超限必須記錄。
- 可記錄 `rate_limit_policy`（命中的 policy 名稱或 id）。
- 可記錄 `current_count` / `limit_value` 供除錯與容量分析。
- 建議同時記錄 `window`（minute/hour/day）與 `burst` 命中狀態。

---

## 10. 安全設計

- 不可讓單一 tenant 壓垮 Host B。
- 不可讓單一 API Key 無限制呼叫。
- 不可讓錯誤請求繞過限流（例如高頻 4xx/5xx 也應可納入策略）。
- Rate Limit 不取代 API Key 驗證。
- 限流邏輯需與 Tenant Mapping / API Key / Service Permission 結果共同運作。
- 對高風險 service 可採更嚴格 policy（較低 `burst_limit` 與 minute 限制）。

---

## 11. 本階段不做事項

- 不建立資料表。
- 不執行 SQL。
- 不實作真實限流。
- 不修改 production PHP。
- 不呼叫 Host B API。
- 不接正式 API。
- 不做 git add / commit / push。

---

## 12. Cursor 執行回報

1. 文件是否建立：是。  
2. 完整路徑：`C:\bbc-ai-bot\docs\API_GATEWAY_PHASE3_RATE_LIMIT_DESIGN.md`。  
3. 是否只建立文件：是。  
4. 是否沒有修改正式 PHP：是。  
5. 是否沒有執行 SQL：是。  
6. 是否沒有呼叫 Host B API：是。  
7. 是否沒有 git add / commit / push：是。  
8. 建議下一步文件：`API_GATEWAY_PHASE3_IMPLEMENTATION_PLAN.md`。  
