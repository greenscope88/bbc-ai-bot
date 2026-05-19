# API Gateway MVP — LINE / Gemini Integration Plan (Stage 1-B-10)

**Project root:** `C:\bbc-ai-bot`  
**Branch:** `feature/api-gateway-mvp` (pushed to `origin/feature/api-gateway-mvp`)  
**Document date:** 2026-05-19  
**Type:** Planning document only. **No code, `.env`, Apache, or SQL changes** in this delivery.

**Related:** `docs/API_GATEWAY_MVP_PUBLIC_ENTRY_PLAN.md`, `docs/API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md`, `docs/API_GATEWAY_MVP_STAGE_1B8_PUBLIC_API_LIVE_TEST_REPORT.md`, `core/saas_router.php`, `core/ai_prompt_builder.php`, `core/intent_router.php`

---

## 1. Document Purpose

本文件為 **Stage 1-B-10** 產出，說明 BBC AI SaaS 如何在**既有** LINE OA → PHP → Gemini 流程中，於適當時機呼叫 **API Gateway MVP 正式 API**，取得 Host B 商品搜尋結果，並將 **商品摘要** 與 **`search_url`** 整合進 Gemini 產生的 LINE 回覆。

本文件**不實作**整合程式、**不修改** `saas_router.php` / `ai_prompt_builder.php`、**不啟用** production Host B HTTP；僅定義整合點、決策邏輯、Prompt 策略、錯誤處理與分階段實作步驟。

---

## 2. Current BBC AI SaaS Flow

```
LINE OA（使用者訊息）
    → Webhook（safe_gateway / LINE Messaging API）
    → SaaSRouter::handleEvent（core/saas_router.php）
        → 驗證 LINE signature
        → 解析 userMessage、replyToken
        → IntentRouter::detect（意圖：weather / tour_query / service_query …）
        → TenantResolver::resolve（DB：tenant 公司名、語氣、專長、sno）
        → TourService::isServiceSupported / fetchServiceData（DB 層服務資料）
        → AiPromptBuilder::build（組 prompt + serviceData JSON）
        → callGemini（core/gemini_service.php）
    → LINE Reply API（LineService::replyToLine）
```

**現況重點：**

| 元件 | 現況 |
|------|------|
| **租戶** | `TenantResolver` 自 DB 解析 `sno`、公司名等 |
| **行程相關意圖** | `IntentRouter` 偵測「行程」「團」→ `tour_query` |
| **商品資料** | 目前來自 **DB** `TourService::fetchServiceData`，**未**呼叫 Gateway 正式 API |
| **Gemini** | 僅接收文字 prompt；**不接觸** Host B API Key |
| **traceId** | `saas_router` 自行產生 `Ymd_His_` + random hex，**未**與 Gateway `traceId` 串接 |

---

## 3. New API Gateway Integration Point

**建議插入位置（實作階段）：** `SaaSRouter::handleEvent` 內，在 `TenantResolver` 成功且意圖為 **商品搜尋類** 之後、`AiPromptBuilder::build` 之前。

```
… TenantResolver::resolve
… IntentRouter::detect  → tour_query / 可搜尋關鍵字
    → [NEW] GatewayTourSearchClient::search(sno, keyword, page, pageSize, traceId)
        → HTTP GET https://bonusmee.com/api/gateway/tour/search.php
    → 將 JSON 結果轉為 prompt 用「結構化摘要」
… AiPromptBuilder::build（擴充參數：gatewaySearchResult）
… callGemini
… LINE Reply
```

**原則：**

- **Gemini 不直接打 Gateway**；由 PHP 後端代呼，避免暴露營運旗標與未來 `x-api-key`。
- **保留** 現有 DB 路徑作為 fallback（DB 失敗或 Gateway 關閉時降級文案）。
- **單一契約**：與 Web 前端、其他 SaaS 模組共用同一正式 URL 與 JSON 形狀。

---

## 4. API Endpoint

| 項目 | 值 |
|------|-----|
| **正式 URL** | `https://bonusmee.com/api/gateway/tour/search.php` |
| **方法** | `GET` |
| **實體入口** | `C:\Web\xampp\htdocs\www\api\gateway\tour\search.php` |
| **內部服務** | `TourSearchService`（`C:\bbc-ai-bot\core\tour_search_service.php`） |

---

## 5. Example Request

**HTTP：**

```http
GET https://bonusmee.com/api/gateway/tour/search.php?sno=e1fd133c7e8e45a1&keyword=%E5%AF%8C%E5%9C%8B%E5%B3%B6&page=1&pageSize=5
Accept: application/json
X-Trace-Id: 20260519_line_abc12345
```

| 參數 | 範例 | 說明 |
|------|------|------|
| **sno** | `e1fd133c7e8e45a1` | 來自 `TenantResolver` 的租戶識別 |
| **keyword** | `富國島` | 自使用者訊息萃取 |
| **page** | `1` | LINE 首屏建議 `1` |
| **pageSize** | `5` | LINE 回覆長度考量，建議 3–5 筆 |

---

## 6. Example JSON Response

### 6.1 成功（HTTP 200）

```json
{
  "success": true,
  "traceId": "20260519_034203_01bc293a",
  "sno": "e1fd133c7e8e45a1",
  "keyword": "富國島",
  "pagination": {
    "page": 1,
    "pageSize": 5,
    "total": 2302
  },
  "items": [
    {
      "title": "太陽航空、直飛富國島～珍珠雙樂園、跳島海景纜車五日 直售23,888起 💎",
      "price": 23888,
      "tourDate": "2026/05/24",
      "couponNo": 11751,
      "tourSeqNo": 0,
      "areaNames": "",
      "departureStr": "",
      "storeName": ""
    }
  ],
  "search_url": "https://bonusmee.com/view/cloud/cloud_store_tourdate.php?openExternalBrowser=1&UnCarousel=1&fromDMDetailFlag=1&clearParam=Y&mode=1&sno=e1fd133c7e8e45a1&keyword=%E5%AF%8C%E5%9C%8B%E5%B3%B6",
  "error": null
}
```

### 6.2 失敗（HTTP 502，`GATEWAY_HOSTB_HTTP_ENABLED=false`）

```json
{
  "success": false,
  "traceId": "20260519_120000_a1b2c3d4",
  "sno": "e1fd133c7e8e45a1",
  "keyword": "富國島",
  "pagination": null,
  "items": [],
  "search_url": "https://bonusmee.com/view/cloud/...",
  "error": {
    "code": "HOSTB_HTTP_DISABLED",
    "message": "Host B HTTP is disabled for this environment."
  }
}
```

---

## 7. API Call Decision Logic

僅在**同時滿足**下列條件時呼叫 Gateway（避免每則訊息都打 Host B）：

| # | 條件 | 說明 |
|---|------|------|
| D1 | 意圖為商品搜尋 | `intent === 'tour_query'` 或擴充規則命中（見下表） |
| D2 | 可萃取 keyword | trim 後非空，長度 ≤ 契約上限 |
| D3 | `sno` 有效 | `TenantResolver` 已解析出非空 `sno` |
| D4 | 服務允許 | `TourService::isServiceSupported` 對「行程」為 supported（或對應 service_name） |
| D5 | 非純天氣 / 非問候 | 排除 `weather_query`、`你好` 等既有短路 |

### 7.1 範例使用者訊息

| 使用者訊息 | 是否呼叫 API | keyword 建議 | 備註 |
|------------|--------------|--------------|------|
| 「富國島有哪些行程」 | **是** | `富國島` | 明確目的地 + 行程意圖 |
| 「有沒有日本團」 | **是** | `日本` 或 `日本團` | 規則：去除「有哪些」「有沒有」等前綴後取核心詞 |
| 「東京五日團推薦」 | **是** | `東京五日` 或 `東京` | 可二階段：規則萃取 + Gemini 僅做排序文案 |
| 「你好」 | **否** | — | 既有 hello 短路 |
| 「東京天氣如何」 | **否** | — | `weather_query` 走既有 Gemini 直連 |
| 「護照怎麼辦」 | **否** | — | `service_query`，非 tour.search |

### 7.2 Keyword 萃取（建議實作規則）

1. 移除禮貌用語與問句尾綴（「請問」「有哪些」「推薦嗎」）。  
2. 若含「行程」「團」「旅遊」，保留目的地／天數核心詞。  
3. 若無法萃取（過短或僅停用詞）→ **不呼叫 API**，改一般客服 prompt。  
4. **禁止** Gemini 自行發明 keyword 後直接打 API；keyword 須由 PHP 規則或受控 NLU 輸出。

---

## 8. Gemini Prompt Injection Strategy

`AiPromptBuilder` 擴充時，在「參考資料」區塊注入 **Gateway 搜尋摘要**（非完整 raw JSON dump，避免 token 浪費與幻覺）。

### 8.1 建議注入欄位

| 來源 | 注入內容 |
|------|----------|
| `pagination.total` | 「共找到 N 筆行程（本則顯示前 K 筆）」 |
| `items[]` | 每筆：`title`、`price`（格式化为 `$23,888`）、`tourDate` |
| `search_url` | 固定給 Gemini：**必須**在回覆末尾提供「查看更多行程」連結 |

### 8.2 Prompt 片段範例（繁中）

```text
【即時行程搜尋結果】（資料來源：官方商品 API，請勿捏造未列出的商品）
- 搜尋關鍵字：富國島
- 總筆數：2302（以下為第 1 頁前 5 筆）
1. 太陽航空、直飛富國島… | 價格 23888 | 出發日 2026/05/24
2. …
【查看更多】請在回覆最後附上此連結（原文勿改）：{search_url}

規則：
- 僅能介紹上述列表中的商品；若列表為空，請說明暫無符合結果並仍可提供 search_url。
- 不得編造 couponNo、價格或日期。
```

### 8.3 與現有 `serviceData` 關係

| 資料來源 | 用途 |
|----------|------|
| **Gateway `items`** | 即時商品（優先） |
| **DB `serviceData`** | 非商品類 FAQ、服務限制；可並存或 Gateway 成功時降權 DB 行程欄位 |

---

## 9. Suggested AI Reply Format

**目標：** LINE 手機可讀、短段落、含連結。

```text
為您找到「富國島」相關行程，共 2302 筆，以下為精選 5 筆：

1️⃣ 太陽航空、直飛富國島～珍珠雙樂園…
   💰 23,888 元起｜📅 2026/05/24

2️⃣ …

👉 查看更多行程：
https://bonusmee.com/view/cloud/cloud_store_tourdate.php?…
```

| 元素 | 來源 |
|------|------|
| 商品摘要 | `items[].title` / `price` / `tourDate` |
| 總筆數 | `pagination.total` |
| 「查看更多」 | **`search_url` 原樣**（LINE 可點擊） |

Gemini 負責潤飾語氣；**數字與連結**須與 API 回傳一致（可在 prompt 中要求「不得改寫 URL」）。

---

## 10. Error Handling Strategy

| 情境 | `error.code` / HTTP | LINE 使用者文案（建議） | 後端動作 |
|------|---------------------|-------------------------|----------|
| Host B 關閉 | `HOSTB_HTTP_DISABLED` / 502 | 「行程搜尋維護中，請稍後再試或點選官網連結。」 | 記錄 traceId；可選附 `search_url`（若 API 仍回傳） |
| 逾時 / 網路 | `HOSTB_TIMEOUT` 等 / 502 | 「搜尋逾時，請稍後再試。」 | 重試 0 次（MVP）；記錄 log |
| `success=false` 其他 | 依 `error.code` | 通用：「暫時無法查詢行程，客服將協助您。」 | 不暴露內部訊息 |
| `items` 空、total=0 | HTTP 200, `success=true` | 「目前沒有符合『{keyword}』的行程，您可以換關鍵字或點連結瀏覽全部。」 | 仍附 `search_url` |
| 缺 `sno` / keyword | 400（Gateway） | 不應發生（PHP 層先驗證） | 不呼叫 API |
| Gemini 失敗 | — | 既有「AI錯誤：…」或改為友善句 | 與 Gateway 錯誤分開記錄 |

**禁止：** 將 stack trace、`GATEWAY_HOSTB_API_KEY`、SQL、內部路徑寫入 LINE 訊息。

---

## 11. TraceId Propagation

| 階段 | 建議 |
|------|------|
| **LINE webhook 入口** | `saas_router` 產生 `lineTraceId`（現有格式可保留） |
| **呼叫 Gateway** | Header `X-Trace-Id: {lineTraceId}` 或 query `traceId=`（與正式入口一致） |
| **Gateway 回應** | 使用回傳 `traceId` 作為 canonical id 寫入 log |
| **日誌關聯** | `saas_router.log` 記錄 `line_trace_id`、`gateway_trace_id`、`error.code` |
| **Gemini** | Prompt 內**不需要** traceId；僅後端 log 與未來 audit |

---

## 12. Multi-Tenant Considerations

| 主題 | 說明 |
|------|------|
| **sno** | 必須來自 **該 LINE channel 對應租戶** 的 `TenantResolver` 結果，禁止寫死 staging sno（除測試環境） |
| **租戶隔離** | Gateway 以 `sno` 解析 `tenant_context_map` + Host B TenantResolver；租戶 A 的 sno 不得查租戶 B 商品 |
| **價格與產品** | 不同旅行社 `items` / `pagination.total` 不同屬正常；prompt 不得混用他牌商品 |
| **search_url** | URL 內 `sno` 須與請求一致，供使用者回到**該店** storefront |
| **staging vs prod** | 測試 channel 可用 `e1fd133c7e8e45a1`；上線前確認 `tenant_context_map` 與 DB `TenantResolver` 對齊 |

---

## 13. Security Considerations

| 主題 | MVP 現況 | 建議演進 |
|------|----------|----------|
| **驗證** | 正式 URL 目前無 `x-api-key`（內網/伺服器端呼叫） | Phase 5+：SaaS → Gateway 帶 **server-side** API key；LINE 使用者永遠不持有 |
| **Rate limit** | Gateway production 有 rate limit 骨架；LINE 整合需防刷 | 以 `sno` + channel 計數；超限回友善句 |
| **Audit log** | Host A audit 設計已有；整合時記錄 `sno`、keyword、traceId、結果筆數 | 不記錄完整 API key |
| **Prompt 注入** | 使用者訊息進 Gemini 前須跳脫/長度限制 | 禁止使用者文字覆寫 `search_url` |
| **營運旗標** | `GATEWAY_HOSTB_HTTP_ENABLED` 預設 `false` | 僅營運窗口開啟；整合程式需優雅降級 |

---

## 14. Recommended Integration Steps

| 階段 | 工作項 | 產出 |
|------|--------|------|
| **B-10** | 本規劃文件 | ✅ 本文件 |
| **B-11** | 新增 `GatewayTourSearchClient`（HTTP GET 封裝 + timeout） | `core/gateway_tour_search_client.php`（建議路徑） |
| **B-12** | 擴充 `IntentRouter` / keyword 萃取器 | 單元測試覆蓋範例句 |
| **B-13** | 擴充 `AiPromptBuilder::build` 接受 `gatewaySearch` 區塊 | 不破壞既有 DB 路徑 |
| **B-14** | `saas_router` 接線 + feature flag（如 `LINE_GATEWAY_TOUR_SEARCH_ENABLED`） | 可逐 channel 開啟 |
| **B-15** | Staging LINE 實測（`GATEWAY_HOSTB_HTTP_ENABLED=true` 窗口） | 測試報告 |
| **B-16** | Production 啟用 + 監控 | 與 MVP Launch  checklist 對齊 |

**每階段原則：** 小步 commit、可回滾、預設關閉 Gateway 呼叫直至 staging 通過。

---

## 15. Conclusion

1. **整合點明確：** 在 `saas_router` 意圖與租戶解析之後，以 **HTTP GET** 呼叫 `https://bonusmee.com/api/gateway/tour/search.php`，再將結果注入 `AiPromptBuilder` → Gemini → LINE Reply。  
2. **契約已就緒：** Stage 1-B-7 / 1-B-8 已驗證正式 URL 與 JSON；Live 成功路徑在 `GATEWAY_HOSTB_HTTP_ENABLED=true` 時可用。  
3. **尚未實作：** 目前 LINE 流程仍僅使用 DB `TourService`；本計畫為下一輪開發依據。  
4. **建議優先順序：** `GatewayTourSearchClient` → keyword 規則 → prompt 擴充 → `saas_router` feature flag → staging LINE 測試 → 營運啟用 Host B HTTP。  

完成上述步驟後，BBC AI SaaS 即可在 LINE 對話中提供**即時商品摘要**與**官網搜尋連結**，與 Web 前端共用同一 Gateway MVP，並維持多租戶與安全邊界。
