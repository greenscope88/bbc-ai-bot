# API Gateway 實作前交叉檢查（Implementation Precheck）

## 修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.1 | 2026-05-12 | 依本檔先前建議，**五份** `docs` 內 API Gateway Markdown 已完成凍結錯誤碼總表、邊界規則、`success`／`result` 分工、對外 Response 與 log 共通欄位對齊；**本檔同步更新結論與 Checklist**。 |
| 1.0 | （先前） | 初版交叉檢查。 |

---

## 文件資訊

| 項目 | 說明 |
|------|------|
| 產出目的 | 在進入 Gateway／後端程式實作前，對設計文件做 **一致性、完整性、風險** 彙總 |
| 範圍來源 | `API_GATEWAY_TENANT_MAPPING_DESIGN.md`、`API_GATEWAY_ERROR_AND_AUDIT_LOG_DESIGN.md`、`API_GATEWAY_RESPONSE_AND_LOG_SCHEMA_DESIGN.md`、`API_GATEWAY_MIDDLEWARE_AND_TESTCASE_PLAN.md`（另含本檔自我更新） |
| 聲明 | **本檔僅為規劃／檢查文件**：不修改 PHP / ASP / .NET、不執行 SQL、不建立資料表、不進行 git 操作 |

---

## 0. 本次文件修正摘要（已完成）

以下項目已於 **2026-05-12** 寫入對應 Markdown（**僅 `docs`**）：

1. **命名**：全系列統一 **`sno`、`cid`、`depID`**；已移除 `cID` 筆誤。
2. **凍結錯誤碼總表**：含 `GW_TENANT_*`（含 `GW_TENANT_STORE_NOT_FOUND`）、`GW_AUTH_*`（四碼）、`GW_RATE_LIMITED`、`GW_INTERNAL_ERROR`、`GW_UPSTREAM_ERROR`，並 **逐碼對應 HTTP Status**（見 `API_GATEWAY_ERROR_AND_AUDIT_LOG_DESIGN.md`）。
3. **邊界規則**：`bs_store` 查無、coupon／store `depID` 不一致、是否允許僅 `cid`、`depID` 請求側選填、`cid` 不存在、`sno`／`cid` 不匹配、`seqNo` 多筆歧義等（見 `API_GATEWAY_TENANT_MAPPING_DESIGN.md`）。
4. **Log 語意**：HTTP body 使用 **`success`（boolean）**；Access／Audit／Error 結構化 log 使用 **`result`（`SUCCESS`／`FAILURE`／`ERROR`）**；共通欄位見 `API_GATEWAY_RESPONSE_AND_LOG_SCHEMA_DESIGN.md`。
5. **對外 API Response**：凍結 **`success`、`errorCode`、`message`、`traceId`、`timestamp`、`details`**，並補 **`X-Trace-Id`** Header 建議。
6. **Middleware／測試**：流程補 **store 查無**、**coupon／store dep 內部比對**、**路徑白名單**；E2E 擴充 T6b、T8b、T10–T14（見 `API_GATEWAY_MIDDLEWARE_AND_TESTCASE_PLAN.md`）。

---

## 1. 文件一致性檢查結果（更新後）

### 1.1 Tenant 語意與 Mapping Chain

| 檢查項 | 結果 |
|--------|------|
| `sno`／`cid`／`depID` 命名 | **已凍結一致** |
| `cid` → `bs_Coupon.seqNo`、`storeNo` → `bs_store.uid`、`depID` 交叉驗證 | **與 MIDDLEWARE 細部步驟一致** |
| 邊界：`bs_store` 查無、`depID` 選填／閉合、不允許僅 `cid` | **已寫入 TENANT_MAPPING** |

### 1.2 錯誤碼與 HTTP

| 檢查項 | 結果 |
|--------|------|
| 單一真相來源 | **`API_GATEWAY_ERROR_AND_AUDIT_LOG_DESIGN.md` 凍結總表** |
| MIDDLEWARE 映射表 | **與總表逐列對齊** |
| `GW_AUTH_INVALID` 舊稱 | **對應 `GW_AUTH_INVALID_CREDENTIALS`（相容註記已載明）** |

### 1.3 統一回應 JSON 與 Log

| 檢查項 | 結果 |
|--------|------|
| 對外錯誤欄位 | **`success`、`errorCode`、`message`、`traceId`、`timestamp`、`details`**（RESPONSE 文件） |
| Log `success` vs `result` | **已分工凍結**（RESPONSE 文件） |
| `apiKeyFingerprint` | **ERROR／RESPONSE 對齊** |

### 1.4 小結

- 設計文件 **已達可進入正式實作（程式碼）階段** 之規格完整度；實作時仍須依變更流程進行程式與基礎設施變更，並以 staging 驗證。

---

## 2. 歷史問題與建議（v1.0 → v1.1 已處理對照）

下列為初版 precheck 所載問題；**v1.1 已於 docs 修正** 者標示 ✅；仍屬 **實作／維運層**、非本輪 docs 能結案者標示 ⏳。

| 原編號 | 主題 | 狀態 |
|--------|------|------|
| L1 | `depID` 是否必填 | ✅ 已凍結：請求側 **選填**；未帶仍驗 coupon／store 內部一致 |
| L2 | `bs_store` 查無 | ✅ `GW_TENANT_STORE_NOT_FOUND`／503 |
| L3 | `seqNo` 多筆 | ✅ `GW_TENANT_MAPPING_FAILED`（建議 `AMBIGUOUS_CID`） |
| L4 | `GW_RATE_LIMITED` 等入總表 | ✅ |
| L5 | 內部／上游對外碼 | ✅ `GW_INTERNAL_ERROR`、`GW_UPSTREAM_ERROR` |
| L6 | `traceId` 載體 | ✅ body + `X-Trace-Id` |
| L7 | 路徑白名單 | ✅ MIDDLEWARE 補章節 |
| L8 | 讀取層架構（DB／API／快取） | ⏳ **實作階段 ADR** |
| C2 | HTTP 政策 | ✅ 已逐碼凍結於總表 |
| C3 | dep 測試分層 | ✅ T6／T6b |
| 2.3 `cID` 筆誤 | 命名 | ✅ |

---

## 3. 正式實作前 Checklist

### 3.1 規格凍結（文件面）

- [x] 錯誤碼總表定稿（tenant + auth + rate + internal + upstream）
- [x] 每個 `errorCode` 對應 HTTP status 全站一致（見總表）
- [x] `depID` 請求側選填與驗證閉合規則
- [x] 統一錯誤 JSON 欄位與 `details` 白名單原則
- [x] 成功回應 envelope 範例（`success` + `data` + `traceId`）—見 RESPONSE 文件

### 3.2 資料與安全（實作前仍須執行）

- [ ] 對 `db/schema.json` 或實際 DB 確認 `seqNo`／`storeNo` 唯一性假設（**不改 schema 則僅驗證**）
- [ ] 定稿遮罩與 log 保留政策（合規）
- [ ] Staging 測資準備（對應 E2E T6b、T10 等）

### 3.3 技術與維運

- [ ] Gateway 讀取 mapping 架構與逾時（ADR）
- [ ] 告警閾值初值（AL-GW-*）
- [ ] CI 納入擴充後 E2E

### 3.4 測試

- [x] T7 僅 `cid` 行為已明確（不允許 → 400）
- [x] 補 store 缺列、coupon／store dep 不一致、rate、internal、upstream、白名單（T10–T14）

---

## 4. 建議實作順序

與 `API_GATEWAY_MIDDLEWARE_AND_TESTCASE_PLAN.md` 一致，並以 **已凍結 docs** 為依據：

1. 實作錯誤碼與 HTTP 映射模組（對照總表）。
2. Middleware 骨架：`traceId`、`X-Trace-Id`、路徑白名單、逾時。
3. Tenant mapping 讀取層與完整驗證鏈（含 `GW_TENANT_STORE_NOT_FOUND`）。
4. 統一 JSON 錯誤／成功 envelope。
5. Access／Audit／Error log 管線與 `result` 欄位。
6. 認證、限流、上游代理與 `GW_UPSTREAM_ERROR`。
7. 告警與 E2E CI。

---

## 5. 風險與注意事項

| 風險 | 說明 | 緩解方向 |
|------|------|----------|
| **資訊洩漏** | 錯誤訊息過細 | 遵守 `message` 通用化、`details` 白名單 |
| **503 與維運誤判** | `GW_TENANT_STORE_NOT_FOUND` 為資料鏈問題 | 監控 AL-GW-003b、資料修復流程 |
| **延遲** | 每請求查表 | 唯讀副本、快取、逾時（ADR） |
| **文件與程式漂移** | 未來變更未回寫 | 變更錯誤碼時必同步更新 **凍結總表** 與本 precheck 修訂紀錄 |

---

## 6. 實作階段就緒聲明

- **規劃文件（`docs` 內五份 API Gateway 設計／precheck）已可支撐正式程式實作開工**。
- 實際上線仍須完成第 3 節中標 ⏳／未勾選之 **工程與合規** 項目，並通過 staging 與 code review。

---

## 相關文件

- `API_GATEWAY_TENANT_MAPPING_DESIGN.md`
- `API_GATEWAY_ERROR_AND_AUDIT_LOG_DESIGN.md`
- `API_GATEWAY_RESPONSE_AND_LOG_SCHEMA_DESIGN.md`
- `API_GATEWAY_MIDDLEWARE_AND_TESTCASE_PLAN.md`

---

## 文件維護

本檔於 **錯誤碼或邊界規則變更**、**重大架構變更** 或 **測試矩陣改版** 時應更新修訂紀錄；不替代 code review 或變更管理流程。
