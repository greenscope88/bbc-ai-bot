# API Gateway Phase 3 - Audit Log Design

## 1. 文件目的

本文件定義 API Gateway Phase 3 的 Audit Log 設計，目標是建立可追蹤、可稽核、可鑑識的事件紀錄機制，涵蓋 Tenant Mapping、API Key 驗證、Host B Proxy 與回應正規化全流程。  
本文件為設計規格，不含程式實作與資料庫建置。

---

## 2. 背景與前提

- Tenant Mapping Design 已完成並審核 PASS。
- API Key Verification Design 已完成並審核 PASS。
- Host B API Proxy Design 已完成並審核 PASS。
- Host A 為 Gateway 控制層，需對成功與失敗事件建立一致稽核格式。
- 稽核資料需支援安全審查、異常追蹤、責任界定與營運分析。
- `traceId` 貫穿 middleware、gateway、proxy、response，需成為稽核關聯主軸。

---

## 3. Audit Log 事件範圍

Audit Log 事件範圍至少包含：

- HTTP Entry 請求進入事件。
- Tenant Mapping Resolver 成功 / 失敗事件。
- API Key Verification 成功 / 失敗事件。
- Service Permission Check 成功 / 失敗事件。
- Host B Proxy 請求送出 / 回應接收 / timeout / connection failed。
- Response Normalize 成功 / 失敗事件。
- 最終回應輸出事件（含 HTTP status 與 errorCode）。

原則：

- **所有成功與失敗事件皆應記錄。**
- **Tenant Mapping 與 API Key 驗證失敗也必須記錄。**
- **Host B timeout 必須記錄。**

---

## 4. 建議資料表：`api_gateway_audit_log`

> 注意：本章為設計，不建立 SQL。

建議以單一主稽核表先落地，後續再依流量拆分 hot/cold table 或資料倉儲。

---

## 5. 建議欄位

建議欄位如下（本階段僅定義，不執行 DDL）：

| 欄位 | 說明 |
|---|---|
| `trace_id` | 全鏈路追蹤識別；`traceId` 是整個稽核主鍵之一 |
| `sno` | SaaS tenant 識別 |
| `provider_id_no` | 對應 `bs_Provider.id_no` 的 tenant 維度 |
| `depID` | 組織/部門維度 |
| `store_uid` | 對應 `bs_store.uid` |
| `storeNo` | 相容既有業務門市欄位 |
| `api_key_id` | API Key 內部識別 |
| `api_key_prefix` | 僅記錄 prefix，不記錄完整 key |
| `service` | Gateway service 名稱（如 `tour.search`） |
| `request_method` | HTTP method（GET/POST...） |
| `request_path` | Gateway request path |
| `upstream_url` | Host B 上游 URL（可做必要遮罩） |
| `http_status` | 最終回應狀態碼 |
| `error_code` | 錯誤代碼（成功可為 null 或 `OK`） |
| `client_ip` | 呼叫端 IP（可依政策做部分遮罩） |
| `user_agent` | 呼叫端 User-Agent |
| `duration_ms` | Gateway 端總處理時間 |
| `request_summary` | 經白名單與遮罩後的請求摘要 |
| `response_summary` | 經白名單與遮罩後的回應摘要 |
| `created_at` | 建立時間（UTC） |

補充：

- 可追加 `event_type`、`event_stage`、`retry_count`、`host_b_status` 供營運分析。
- `trace_id + created_at` 可作為主要查詢索引組合之一。

---

## 6. 何時寫入 Audit Log

建議時機：

1. Request 進入 Gateway 後，建立初始事件（含 `trace_id`、path、method、client_ip）。  
2. Tenant Mapping 完成後寫入結果（success/fail + 原因碼）。  
3. API Key 驗證完成後寫入結果（success/fail + 原因碼）。  
4. Service Permission Check 後寫入結果。  
5. Host B Proxy 呼叫前後各寫一筆（含 upstream、duration、status）。  
6. Normalize Response 完成後寫入結果。  
7. 最終回應前寫入收斂事件（最終 `http_status` / `error_code`）。  

原則：

- 失敗流程不可漏記（包括早期拒絕）。
- 單次請求至少可由 `trace_id` 關聯出完整事件鏈。

---

## 7. 敏感資料遮罩規則

- **不記錄完整 API Key。**
- **只記錄 `api_key_prefix`。**
- **不記錄 `password`、`token`、`cookie`。**
- 不記錄完整 Authorization header。
- 可記錄必要欄位摘要，但值需遮罩（例如只保留前後 2~4 碼）。
- `request_summary` / `response_summary` 僅保留白名單欄位。
- 個資欄位（如 email、手機、證號）若必要記錄，需雜湊或部分遮罩。

---

## 8. 錯誤事件記錄

以下錯誤事件需完整記錄：

- 缺少/格式錯誤 `sno`。
- Tenant Mapping not found / disabled / suspended。
- API Key 缺失、格式錯誤、hash mismatch、disabled/revoked/expired。
- API Key 與 `sno` 不一致。
- service 不存在、未啟用、無權限。
- client 傳入禁止欄位（含嘗試覆寫 `depID` / `storeNo`）。
- Host B timeout / 非 JSON / 4xx / 5xx / connection failed。
- Response normalize failed。

每筆錯誤至少需含：

- `trace_id`
- `sno`（若可取得）
- `service`（若可解析）
- `http_status`
- `error_code`
- `duration_ms`
- `request_summary`
- `created_at`

---

## 9. 成功事件記錄

成功事件同樣必須記錄，至少包含：

- `trace_id`
- `sno`
- `provider_id_no` / `depID` / `store_uid` / `storeNo`
- `api_key_id` / `api_key_prefix`
- `service`
- `request_method` / `request_path`
- `upstream_url`
- `http_status`
- `duration_ms`
- `response_summary`
- `created_at`

目的：

- 供 SLA/效能分析（P95/P99）。
- 供帳務與流量稽核。
- 供安全異常偵測（高頻、異常地區、異常 service 組合）。

---

## 10. Log Retention 建議

- 線上稽核資料建議保留 90~180 天（依法遵與容量調整）。
- 冷存/歸檔可延長至 1~2 年（或依合約要求）。
- 高敏感摘要欄位可採較短保存週期。
- 建議分層儲存：Hot（即時查詢）/ Warm（近線分析）/ Cold（歸檔）。
- 建議設定自動清理與不可逆刪除流程，並保留刪除稽核記錄。

---

## 11. 安全設計

- 稽核資料存取需最小權限控管（RBAC）。
- 稽核表需限制直接查詢權限，僅開放受控查詢介面。
- 稽核資料需防竄改（append-only 策略或簽章/雜湊鏈可評估）。
- 不得在 log 中暴露敏感憑證與完整個資。
- `trace_id` 應作為跨模組關聯主鍵之一，避免以敏感欄位做主查詢鍵。
- 失敗事件不可被靜默吞掉，必須可回溯到 `error_code`。
- 若需輸出對外診斷訊息，僅輸出安全摘要，不輸出內部堆疊。

---

## 12. 本階段不做事項

- 不建立 `api_gateway_audit_log` 資料表。
- 不執行 SQL。
- 不修改 production PHP。
- 不修改 `.htaccess`。
- 不呼叫 Host B API。
- 不接正式 API 流量。
- 不實作實際寫入 Audit Log 程式碼。
- 不做 git add / commit / push。

---

## 13. Cursor 執行回報

1. 文件是否建立：是。  
2. 完整路徑：`C:\bbc-ai-bot\docs\API_GATEWAY_PHASE3_AUDIT_LOG_DESIGN.md`。  
3. 是否只建立文件：是。  
4. 是否沒有修改正式 PHP：是。  
5. 是否沒有執行 SQL：是。  
6. 是否沒有呼叫 Host B API：是。  
7. 是否沒有 git add / commit / push：是。  
8. 建議下一步文件：`API_GATEWAY_PHASE3_RATE_LIMIT_DESIGN.md`。  
