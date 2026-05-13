# API Gateway Phase 3 - API Key Verification Design

## 1. 文件目的

本文件定義 API Gateway 的 API Key 驗證機制，並延續 Tenant Mapping Design 的設計前提與租戶邊界。  
API Key 驗證的核心目標如下：

- 驗證呼叫方是否有權使用 API Gateway。
- 確認 API Key 是否屬於該 `sno` / tenant。
- 確認 API Key 是否允許使用指定 service。
- 不直接使用 `bs_Provider` / `bs_store` / `bs_Member` 既有敏感欄位作為 Gateway 驗證來源。

---

## 2. 背景與前提

- Phase 3 Step 1 Tenant Mapping Design 已完成並審核 PASS。
- 最新 `schema_only.sql` 顯示 Host B 目前沒有專用 API Gateway Key 表。
- `bs_Provider` 雖有 `GoogleMapAPIKey`、`GooglePeopleAPIKey`、`LineApiKey`、`ChannelSecret_LinePay`、`hashKey`、`hashIV` 等欄位，但那些屬於既有金流或第三方服務密鑰，不可作為 API Gateway API Key。
- API Gateway API Key 應獨立設計，不應存放於 `bs_Provider`、`bs_store`、`bs_Member`。
- `sno` 是 SaaS 對外 tenant 識別。
- API Key 是授權憑證。
- Tenant Mapping 先確認 tenant context，API Key 再確認授權。

---

## 3. 驗證順序

文字流程：

HTTP Entry  
→ TraceIdMiddleware  
→ ErrorResponseBuilder  
→ GatewayKernel  
→ Tenant Mapping Resolver  
→ API Key Verification  
→ Service Permission Check  
→ Rate Limit Check  
→ Host B API Proxy  
→ Normalize Response  
→ Audit Log  
→ Response

驗證步驟：

1. 先解析 `sno`。  
2. 透過 Tenant Mapping Resolver 取得 tenant context。  
3. 再讀取 API Key。  
4. 驗證 API Key 是否存在。  
5. 驗證 API Key 是否屬於此 `sno`。  
6. 驗證 API Key 狀態是否 `active`。  
7. 驗證 API Key 是否過期。  
8. 驗證 API Key 是否允許指定 service。  
9. 通過後產生 authenticated context。  

---

## 4. API Key 傳遞方式

建議正式 API 使用 Header：

`X-BBC-API-Key: bbc_live_xxxxxxxxxxxxxxxxx`

說明：

- 不建議把 API Key 放在 query string。
- query string 容易出現在 log、瀏覽器歷史紀錄、proxy log。
- Header 較適合 server-to-server API。
- 測試階段可允許 isolated entry 使用固定假 key，但 production 不可。

---

## 5. 建議資料表：api_gateway_keys

> 注意：本章只做設計，不建立 SQL。

規劃欄位：

- `id`
- `sno`
- `key_name`
- `key_prefix`
- `key_hash`
- `key_status`
- `allowed_services`
- `allowed_ips`
- `expires_at`
- `last_used_at`
- `created_at`
- `updated_at`
- `revoked_at`
- `notes`

補充說明：

- 不存完整 API Key 明碼。
- `key_prefix` 只用於辨識與管理，例如 `bbc_live_abcd`。
- `key_hash` 用於驗證。
- `key_status` 建議包含 `active` / `disabled` / `revoked` / `expired`。
- `allowed_services` 限制此 key 可用服務。
- `allowed_ips` 可作為進階安全限制。
- `expires_at` 支援金鑰到期。
- `revoked_at` 支援撤銷紀錄。

---

## 6. API Key 格式建議

- 測試環境：`bbc_test_xxxxxxxxx`
- 正式環境：`bbc_live_xxxxxxxxx`
- 長度需足夠，不可容易猜測。
- API Key 只在建立時顯示一次。
- 之後只顯示 prefix，不顯示完整 key。
- `key_hash` 建議使用安全 hash，不可用可逆加密儲存。

---

## 7. API Key 與 sno 的關係

- 每一把 API Key 必須綁定一個 `sno`。
- API Key 不可跨 `sno` 使用。
- 即使 key 存在，只要與 request `sno` 不一致，也必須拒絕。
- Tenant `disabled` / `suspended` 時，即使 API Key `active`，也不可通過。
- API Key 通過不代表可查所有資料，仍需依 tenant context 限制 `provider_id_no` / `depID` / `store_uid` / `storeNo`。

---

## 8. Authenticated Context 建議格式

以下為 PHP array 範例（不需實作）：

```php
[
  'sno' => 'e1fd133c7e8e45a1',
  'tenantName' => 'Example Travel',
  'providerIdNo' => 1,
  'depID' => 1,
  'storeUid' => 1001,
  'storeNo' => 1001,
  'apiKeyId' => 10,
  'apiKeyName' => 'Primary Production Key',
  'apiKeyPrefix' => 'bbc_live_abcd',
  'allowedServices' => ['tour.search', 'order.query'],
  'allowedIps' => ['203.0.113.10'],
  'apiProfile' => 'standard',
  'rateLimitProfile' => 'standard'
]
```

---

## 9. 錯誤情境設計

| 情境 | errorCode | HTTP status | 是否寫入 Audit Log | 回應是否包含 traceId |
|---|---|---|---|---|
| 缺少 API Key | `MISSING_API_KEY` | 401 | 是 | 是 |
| API Key 格式錯誤 | `INVALID_API_KEY_FORMAT` | 400 | 是 | 是 |
| API Key 找不到 | `API_KEY_NOT_FOUND` | 401 | 是 | 是 |
| API Key hash 驗證失敗 | `API_KEY_HASH_MISMATCH` | 401 | 是 | 是 |
| API Key disabled | `API_KEY_DISABLED` | 403 | 是 | 是 |
| API Key revoked | `API_KEY_REVOKED` | 403 | 是 | 是 |
| API Key expired | `API_KEY_EXPIRED` | 401 | 是 | 是 |
| API Key 與 sno 不一致 | `API_KEY_TENANT_MISMATCH` | 403 | 是 | 是 |
| API Key 無權使用指定 service | `API_KEY_SERVICE_NOT_ALLOWED` | 403 | 是 | 是 |
| IP 不在 allowed_ips | `API_KEY_IP_NOT_ALLOWED` | 403 | 是 | 是 |
| tenant disabled / suspended 但 API Key active | `TENANT_INACTIVE_WITH_ACTIVE_KEY` | 403 | 是 | 是 |

---

## 10. 安全設計

- API Key 不可存明碼。
- API Key 不可放 query string。
- API Key 不可寫入一般 access log。
- 回應錯誤時不可顯示完整 API Key。
- 只可顯示 `key_prefix`。
- API Key 產生、撤銷、停用需有 Audit Log。
- API Key 驗證要與 Tenant Mapping 結果一起使用。
- 不可讓 client 直接傳 `depID` / `storeNo` 取代 `sno`。
- 不可使用 `bs_Provider` 中既有第三方 API Key 或金流密鑰作為 Gateway Key。
- API Key 驗證失敗時不可進入 Host B Proxy。
- 未來可支援 key rotation。

---

## 11. 與後續 Phase 3 文件的關係

本文件作為以下文件的設計基礎：

- `API_GATEWAY_PHASE3_HOST_B_PROXY_DESIGN.md`
- `API_GATEWAY_PHASE3_AUDIT_LOG_DESIGN.md`
- `API_GATEWAY_PHASE3_RATE_LIMIT_DESIGN.md`
- `API_GATEWAY_PHASE3_IMPLEMENTATION_PLAN.md`

後續文件需沿用本文件定義的驗證順序、錯誤碼策略與 authenticated context 欄位語意。

---

## 12. 本階段不做事項

- 不建立 `api_gateway_keys` 表。
- 不執行 SQL。
- 不修改 production PHP。
- 不修改 `.htaccess`。
- 不接正式 API。
- 不產生真實 API Key。
- 不驗證真實 API Key。
- 不接 Host B Proxy。
- 不修改 `bs_Provider` / `bs_store` / `bs_Member`。
- 不使用既有金流或第三方服務 key。

---

## 13. Cursor 執行回報

1. 文件是否已建立：是。  
2. 文件完整路徑：`C:\bbc-ai-bot\docs\API_GATEWAY_PHASE3_API_KEY_VERIFICATION_DESIGN.md`。  
3. 是否只建立文件：是。  
4. 是否完全沒有修改正式 PHP：是。  
5. 是否完全沒有修改 `.htaccess`：是。  
6. 是否完全沒有執行 SQL：是。  
7. 是否沒有 git add / commit / push：是。  
8. 建議下一步文件：`API_GATEWAY_PHASE3_HOST_B_PROXY_DESIGN.md`。  
