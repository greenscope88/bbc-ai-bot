# BBC 旅業資料中心 — Phase 9-A.7 LINE Group Governance Policy

**副標：** LINE 群組治理策略與多租戶群組管理規範（2～200 家旅行社共用）

**文件代號：** `TRAVEL_DATA_CENTER_PHASE9A7_LINE_GROUP_GOVERNANCE_POLICY`

**版本：** Phase 9-A.7（規劃 only）

**狀態：** Planning — 本文件不觸發程式、JSON、cache、SQL、Git、`.env` 變更

**規劃主機：** 主機 A `103.1.222.14` · 專案路徑 `C:\bbc-ai-bot`

**關聯文件：**

- [TRAVEL_DATA_CENTER_PHASE9A6_LINE_GROUP_REDIRECT_STRATEGY.md](./TRAVEL_DATA_CENTER_PHASE9A6_LINE_GROUP_REDIRECT_STRATEGY.md)（群組導流與 Tenant Line Profile）
- [TRAVEL_DATA_CENTER_PHASE9A5_STORAGE_AND_SHORTURL_STRATEGY.md](./TRAVEL_DATA_CENTER_PHASE9A5_STORAGE_AND_SHORTURL_STRATEGY.md)（短網址、Product Source 儲存）
- [TRAVEL_DATA_CENTER_PHASE9_MVP_IMPLEMENTATION_PLAN.md](./TRAVEL_DATA_CENTER_PHASE9_MVP_IMPLEMENTATION_PLAN.md)（Phase 9-A）

**盤點日期：** 2026-05-29

---

## 1. 目的

在 **Phase 9-A.6**（Group Redirect Strategy）之上，定義 **LINE Group Governance Policy（LINE 群組治理策略）**：

| 目標 | 說明 |
|------|------|
| **共用規範** | 2～200 家旅行社 **同一套** 群組允許/禁止行為 |
| **多租戶差異** | 僅 **LINE_OA_LINK**、開關等放在 `tenant_line_profile.json` |
| **與 BATS 分界** | 群組 **不** 走 Gemini / Hybrid 全文搜尋；僅 **導流 + 促銷** |
| **安全合規** | 群組 **禁止** 私有層、PII、報名資料處理 |

**本階段交付：** 治理政策文件 + `group_trigger_policy.json` 擴充 schema 建議；**不** 修改程式。

---

## 2. 現有架構盤點

### 2.1 盤點檔案摘要

| # | 檔案 | 現況（與群組治理相關） |
|---|------|------------------------|
| 1 | `webhook/callback_core.php` | POST → `routeAIRequest()`；無群組分流 |
| 2 | `core/safe_gateway.php` | 日誌 + `你好` 快速回覆；其餘委派 `SaaSRouter` |
| 3 | `core/saas_router.php` | 需 `replyToken` + `message.text`；**未讀** `source.type`；全走 BATS |
| 4 | `core/tenant_resolver.php` | `destination` → `sno`；無 line profile |
| 5 | `config/tenant_registry.php` | `travel_a` / `travel_b` channel + sno；無群組政策 |
| 6 | `core/line_service.php` | 單則 `text` reply；**無** 多則訊息 / push API |
| 7 | Phase 9-A.6 | 已規劃 `GroupRedirectGate`、`group_trigger_policy.json` |

### 2.2 Webhook 鏈路（現行）

```
callback_core.php / safe_gateway.php
    → SaaSRouter::handleEvent()
        → LineCredentialResolver（destination）
        → verifySignature
        → events[0]：僅 message.text
        → TenantResolver → IntentRouter
        → TourPromptContextService（Hybrid + Host B + Gemini path）
        → TourLineReplyComposer
        → LineService::replyToLine()【單則文字】
```

### 2.3 與 Phase 9-A.6 的關係

| Phase 9-A.6 | Phase 9-A.7（本文件） |
|-------------|----------------------|
| 導流 **觸發** 與 **Tenant Line Profile** | **允許/禁止** 功能清單 + **促銷** 規則 + **風險** |
| `group_trigger_policy.json` 初版 | 擴充為 **完整治理 policy**（含 promo、deny_list） |
| GroupRedirectGate 概念 | **治理決策樹** + 與 BATS **硬分界** |

### 2.4 程式缺口（實作前必知）

| 缺口 | 影響 |
|------|------|
| 無 `source.type === 'group'` 判斷 | 群組訊息會誤入 BATS |
| 無 `memberJoined` 處理 | OA 入群歡迎未實作 |
| `LineService::reply` 僅 1 則 text | A-2「每商品一則」需擴充 **messages[]**（LINE reply 上限 **5 則/次**） |
| 無促銷「今日新增」查詢 | A-2 需新 **GroupPromoService**（Host B 或 catalog，**無 Gemini**） |
| 無 rate limit | 洗版 / 惡意觸發風險 |

---

## 3. 群組允許功能

群組 **僅允許** 下列兩類能力（全旅行社共用規則，文案可含 `{LINE_OA_LINK}`、`{company_name}`）。

### 3.1 A-1 客服導流（`action: redirect_to_oa`）

**觸發關鍵字（`keyword_match: contains`）：**

| 關鍵字 |
|--------|
| 客服 |
| 一對一 |
| 報名 |
| 行程詢問 |

**亦觸發（事件，見 §5）：** OA 入群、@OA。

**正式回覆模板（`redirect_template_v1`）：**

```
歡迎您加入本公司 LINE 群組 😊

若需要一對一旅遊諮詢、報名或提供旅客資料，
請加入本公司 LINE 官方帳號：

{LINE_OA_LINK}

加入後可直接詢問行程、出發日期、價格與報名相關問題。
```

**資料來源：** `tenant_line_profile.json` → `line_oa_link`（人工維護，見 Phase 9-A.6）。

**禁止：** 本動作不呼叫 Gemini、Hybrid、Context Cache。

---

### 3.2 A-2 最新促銷（`action: promo_today_new`）

**觸發關鍵字：**

| 關鍵字 |
|--------|
| 最新優惠 |
| 最新促銷 |
| 今日最新促銷 |
| 今日出團 |
| 今日新上架 |

**動作：** 查詢 **今日新增商品**（定義見 §3.2.1）。

**回覆規則（正式）：**

| 參數 | 值 | 說明 |
|------|-----|------|
| `promo_reply_mode` | `single_item_per_message` | 每筆商品 **獨立一則** LINE 訊息 |
| `promo_max_items` | `5` | 最多回覆 **5 筆**（與 LINE Reply API 單次上限一致） |
| `promo_include_total_count` | `false` | **不回覆**總筆數 |
| `promo_use_short_url` | `true` | 每筆連結經 **ShortUrlService**（Phase 9-A.5） |
| `promo_use_gemini` | `false` | **禁止** Gemini 改寫 |
| `promo_use_hybrid_search` | `false` | **禁止** 使用者自由文字 Hybrid 搜尋 |

#### 3.2.1 「今日新增商品」資料定義（規劃）

| 優先序 | 來源 | 說明 |
|--------|------|------|
| 1 | Host B API | `created_at` 或 `listed_date = today`（依 Host B 既有欄位；**不新增 API 契約則用現有 list + 篩選**） |
| 2 | GCS / catalog 快取 | `tenants/{sno}/latest/...` 同步檔之 `published_at` |
| 3 | 失敗 | 回覆導流模板（A-1），不進 BATS |

**單則訊息格式（建議）：**

```
【{product_title}】
出發：{departure_date_display}
{short_url}
```

**MVP 促銷上限決策（正式）：**

| 項目 | 值 |
|------|-----|
| `promo_reply_mode` | `single_item_per_message` |
| `promo_max_items` | `5` |

**理由：** LINE [Reply API](https://developers.line.biz/en/reference/messaging-api/#send-reply-message) 單次請求最多 **5 則** message。MVP 採 `single_item_per_message` + `promo_max_items = 5`，一次 `reply` 即可送完，**避免** reply + push 混合實作。

**未來擴充：** 若營運需超過 5 筆，可透過 **後台設定** 調整 `promo_max_items`（需另案：push API 或分次互動，非 MVP）。

**實作：** Phase 9-B 擴充 `LineService::replyMessages()`，單次 payload 最多 5 則 text。

---

## 4. 群組禁止功能

群組內 **一律不執行** 下列能力；命中時 **不查商品、不呼叫 Gemini**，直接 **A-1 導流模板**（`force_redirect: true`）。

### 4.1 B-1 商品搜尋（`deny: product_search`）

**範例使用者輸入（非完整列表，規則為「行程查詢意圖」）：**

- 東京團、北海道團、日本旅遊、大阪自由行
- 含目的地 + 團/旅遊/自由行 等（與 `TourQueryIntentDetector` 同族）

**治理決策：**

| 項目 | 群組 |
|------|------|
| Hybrid Search | **禁止** |
| Host B 自由搜尋 | **禁止** |
| Gemini 行程推薦 | **禁止** |
| 回覆 | 僅 `{LINE_OA_LINK}` 導流文案 |

**實作建議：** 群組內 **重用** `TourQueryIntentDetector::detect()` 若 `is_tour_query === true` → 視為 B-1，**不進** `TourPromptContextService`。

---

### 4.2 B-2 私有層資料查詢（`deny: private_layer`）

**禁止主題範例：**

| 類別 | 範例關鍵字 / 意圖 |
|------|------------------|
| 私有 QA | 私有、內部 QA、不公開 |
| 私有商品/價格 | 私有商品、私有價格、特價表（非促銷觸發詞） |
| 訂單 | 訂單查詢、我的訂單、訂單編號 |
| 報名資料 | 報名資料、名單、已報名幾人 |
| Google Sheet | sheet、試算表、Google 表單 |
| 旅客資料 | 旅客資料、團員名單 |

**治理決策：**

- **不讀** GCS private JSON、**不讀** Context Cache 私有層、**不開** Sheet API。
- 回覆：A-1 導流模板。

---

### 4.3 B-3 旅客個資（`deny: pii`）

**禁止在群組收集或處理：**

| 欄位 |
|------|
| 姓名 |
| 電話 / 手機 |
| 出生年月日 |
| 護照號碼 / 護照資料 |
| 身分證字號 |

**治理決策：**

- 偵測到 PII 模式（regex + 關鍵字）→ **立即導流** LINE OA 一對一。
- **不在群組內** 儲存、log 明文 PII（僅 log `pii_detected: true`）。

---

### 4.4 群組禁止總表

| 禁止項 | 一對一 OA | 群組 |
|--------|-----------|------|
| 商品搜尋 / Hybrid | ✅ 允許（BATS） | ❌ 導流 |
| 商品推薦 / Gemini 改寫 | ✅ 允許 | ❌ 導流 |
| 私有層 / Sheet / 訂單 | ✅ 允許（依權限） | ❌ 導流 |
| 報名需求單 | ✅ 允許 | ❌ 導流 |
| 旅客 PII 處理 | ✅ 允許（一對一） | ❌ 導流 |
| 最新促銷（A-2） | ✅ 可選 | ✅ **僅群組允許** |
| 客服導流（A-1） | N/A | ✅ |

---

## 5. 群組事件策略

三種情境 **全旅行社共用**（`group_trigger_policy.json`），差異僅 `{LINE_OA_LINK}`。

| # | 情境 | LINE `event.type` | 條件 | 動作 |
|---|------|-------------------|------|------|
| **C-1** | OA 被加入群組 | `memberJoined` | Bot 在 `joined.members` | A-1 歡迎 + 導流（可縮短版） |
| **C-2** | 使用者 @OA | `message` | `source.type=group` 且 `mention` 含 bot | A-1 導流 |
| **C-3** | 關鍵字觸發 | `message` | `source.type=group` 且命中 §3 / §4 分類 | 見決策樹 §6 |

**C-1 注意：** 常無 `message.text`；須 **獨立 handler**（Phase 9-A.6 已指出），不可被 `empty_reply_or_message` 略過。

**C-3 優先序：**

1. B-3 PII → 導流  
2. B-2 私有層 → 導流  
3. A-2 促銷關鍵字 → 促銷流程  
4. A-1 客服關鍵字 → 導流  
5. B-1 行程搜尋意圖 → 導流  
6. 其他未分類 → **預設導流**（見 §13，**不建議進 BATS**）

---

## 6. GroupRedirectGate 設計

### 6.1 定位

**GroupRedirectGate** = 群組治理 **唯一入口**（在 `saas_router` 內，Tenant resolve **之後**、DB / BATS **之前**）。

### 6.2 決策流程（正式）

```
Webhook event
    ↓
TenantResolver → sno
    ↓
載入 group_trigger_policy.json（shared）
載入 tenant_line_profile.json（per sno）
    ↓
group_redirect_enabled == false ? → 跳過 Gate（見 §6.4）
    ↓
source.type != 'group' ? → 跳過 Gate →【一對一 BATS】
    ↓
【GroupRedirectGate】
    ↓
分類 message / event
    ├─ ALLOW redirect (A-1, C-1, C-2, B-* force)
    │       → 組模板 → LineService reply → return ✅ 結束
    ├─ ALLOW promo (A-2)
    │       → GroupPromoService（Host B/catalog only）
    │       → ShortUrlService per item
    │       → LineService replyMessages (≤5) → return ✅ 結束
    └─ 未命中 / 模糊
            → 預設：A-1 導流（治理建議，§13）
            → 【不進 BATS】
```

### 6.3 與 Phase 9-A.6「不符合才進 BATS」的釐清

| 文件 | 敘述 |
|------|------|
| Phase 9-A.6 | 技術占位：未觸發導流者可進 BATS |
| **Phase 9-A.7（本文件，優先）** | **群組預設 deny BATS**；僅 A-1 / A-2 / 事件 C 在 Gate 內結束 |

**正式治理結論：**

```
GroupRedirectGate
    ↓
符合治理允許（A-1 / A-2 / C-*）
    ↓
直接回覆 → 結束
（不進 Gemini / Hybrid / Product Source Registry / Context Cache）

群組 + 任意其他輸入（含 B-1/B-2/B-3）
    ↓
強制導流 → 結束
（仍不進 BATS）

一對一（source.type = user）
    ↓
跳過 GroupRedirectGate
    ↓
進入 BATS（既有鏈路）
```

### 6.4 建議 Gate 模組拆分（Phase 9-B）

| 類別 | 職責 |
|------|------|
| `GroupGovernancePolicyLoader` | 讀 shared policy |
| `TenantLineProfileLoader` | 讀 per-tenant profile |
| `GroupMessageClassifier` | A-1 / A-2 / B-1 / B-2 / B-3 / unknown |
| `GroupRedirectResponder` | 模板替換 + reply |
| `GroupPromoService` | 今日新增 + short URL + 多則訊息 |
| `GroupRedirectGate` |  orchestration |

---

## 7. 多租戶治理策略

### 7.1 設定檔分工（2～200 家）

| 檔案 | 路徑 | 用途 | 誰維護 |
|------|------|------|--------|
| **Group Trigger / Governance Policy** | `shared/config/group_trigger_policy.json` | 觸發詞、允許/禁止、促銷參數、模板、rate limit | BBC 平台（共用） |
| **Tenant Line Profile** | `tenants/{sno}/config/tenant_line_profile.json` | `line_oa_link`、`group_redirect_enabled`、`group_trigger_policy_id` | 各旅行社 / 後台 |

**原則：**

- **200 家不複製 200 份治理規則**；僅 **200 份 line profile**（含 OA link）。
- 若單社需關閉群組功能：`group_redirect_enabled: false`（該社群組訊息 **忽略或僅靜默**，**不進 BATS** — 建議仍不進 BATS）。

### 7.2 `group_trigger_policy.json` 擴充 schema（規劃）

```json
{
  "schema_version": 2,
  "policy_id": "line_group_governance_v1",
  "governance": {
    "group_default_deny_bats": true,
    "allowed_actions": ["redirect_to_oa", "promo_today_new"],
    "deny_actions": ["product_search", "private_layer", "pii", "gemini", "hybrid_search", "context_cache"]
  },
  "triggers": {
    "on_bot_join_group": true,
    "on_mention_bot": true,
    "scopes": ["group"]
  },
  "redirect": {
    "keywords": ["客服", "一對一", "報名", "行程詢問"],
    "template_id": "redirect_template_v1"
  },
  "promo": {
    "keywords": ["最新優惠", "最新促銷", "今日最新促銷", "今日出團", "今日新上架"],
    "promo_reply_mode": "single_item_per_message",
    "promo_max_items": 5,
    "promo_include_total_count": false,
    "promo_use_short_url": true
  },
  "deny": {
    "force_redirect_on_tour_query": true,
    "private_layer_keywords": ["訂單", "報名資料", "Google Sheet", "旅客資料", "私有"],
    "pii_patterns_enabled": true
  },
  "rate_limit": {
    "per_user_per_group_per_minute": 3,
    "promo_per_group_per_hour": 5
  },
  "templates": {
    "redirect_template_v1": "歡迎您加入本公司 LINE 群組 😊\n\n若需要一對一旅遊諮詢、報名或提供旅客資料，\n請加入本公司 LINE 官方帳號：\n\n{LINE_OA_LINK}\n\n加入後可直接詢問行程、出發日期、價格與報名相關問題。"
  }
}
```

### 7.3 Host A cache（對齊 9-A.5 / 9-A.6）

| 快取 | 路徑 |
|------|------|
| 共用 policy | `cache/shared/group_trigger_policy.json` |
| 租戶 profile | `cache/tenants/{sno}/tenant_line_profile.json` |

---

## 8. 安全與風險控管

| # | 風險 | 說明 | 控管措施 |
|---|------|------|----------|
| **F-1** | **洗版** | 促銷觸發連續 reply 5 則 × 多人 | `rate_limit`：per user / per group；促銷冷卻；同一關鍵字 N 分鐘內去重 |
| **F-2** | **Gemini 成本** | 群組長文對話若進 BATS | **`group_default_deny_bats: true`**；Gate 硬擋 |
| **F-3** | **私有資料外洩** | 群組公開可見回覆含私有 QA/價格 | B-2 deny list；不載入 private GCS / Context Cache |
| **F-4** | **群組濫用** | 惡意 @OA 洗頻 | mention 觸發計入 rate limit；可選管理員 groupId 黑名單（後台） |
| **F-5** | **惡意關鍵字觸發** | 故意刷「最新促銷」 | promo 每群每小時上限；需 **exact/contains 白名單** 僅 policy 內詞彙 |
| **F-6** | **PII 留在群組** | 使用者貼護照 | B-3 偵測 → 導流；log 不打明文 |
| **F-7** | **跨租戶 link 錯置** | 回覆錯社 OA link | profile 以 **resolved sno** 載入；禁止跨 sno fallback |

**日誌原則：** `saas_router.log` 記 `group_action`, `policy_id`, `sno`；**不記** PII、完整促銷客戶名單。

---

## 9. 與 BATS 關聯

| 項目 | 群組 | 一對一 |
|------|------|--------|
| `IntentRouter` | **不呼叫**（Gate 前結束） | ✅ |
| `TourPromptContextService` / Hybrid | **不呼叫** | ✅ |
| `callGemini` | **不呼叫** | ✅ |
| `TourLineReplyComposer` | **不呼叫** | ✅ |
| `ProductSourceRegistry` | **不呼叫** | ✅（一對一） |
| `Context Cache` | **不呼叫** | ✅（一對一） |
| `GroupPromoService` | ✅ **允許**（非 BATS，唯讀列表 + 短鏈） | 可選 |

**BATS 定義不變：** `TenantResolver → IntentRouter → TourService → TourPromptContextService → Gemini/Formatter`。

---

## 10. 與 Product Source Registry 關聯

| 面向 | 關聯 |
|------|------|
| 設定檔 | **分離**；Registry 不含群組關鍵字 |
| A-2 促銷 | 回覆 URL 可經 **Product Source** 的 `short_url_*` 設定縮短；**不** 執行多源自由搜尋 |
| 擴充商品源 | **不影響** 群組治理 policy（200 家仍一份 shared policy） |

**結論：** Product Source Registry **不需** 為群組新增欄位；促銷僅 **讀** catalog / Host B 列表端點。

---

## 11. 與 Tenant Line Profile 關聯

| 欄位（profile） | 治理用途 |
|-----------------|----------|
| `line_oa_link` | 所有導流模板 `{LINE_OA_LINK}` |
| `group_redirect_enabled` | 是否啟用 GroupRedirectGate |
| `group_trigger_policy_id` | 指向 `line_group_governance_v1`（預設共用） |
| `promo_enabled` | 可選；單社關閉 A-2 僅保留 A-1 |
| `line_oa_display_name` | 模板 `{company_name}` 替代 |

**不放入 profile：** 關鍵字清單（放 shared policy）、Hybrid 開關（放 `tenant_registry.features`）。

---

## 12. 未來 200 家旅行社治理模式

| 模式 | 說明 |
|------|------|
| **單一治理政策** | 1 份 `group_trigger_policy.json` 版本化（`policy_id`） |
| **200 份 line profile** | 僅 OA link + 開關 |
| **Onboarding** |  checklist：OA link 驗證 → 上傳 GCS → 刷新 cache → 群組煙霧測試 |
| **政策升級** | 改 policy 版本 → 全網生效；不需每社改檔 |
| **例外** | 極少數社需關促銷：`promo_enabled: false`；**不建議** 每社自訂關鍵字 |
| **稽核** | 後台看 `group_action` 統計：導流次數、促銷次數、被 rate limit 次數 |

---

## 13. 最終建議架構

```
                    ┌─────────────────────────────┐
                    │ shared/group_trigger_policy  │
                    │  (Governance v1)             │
                    └──────────────┬──────────────┘
                                   │
                    ┌──────────────┴──────────────┐
                    │ tenants/.../tenant_line_     │
                    │   profile.json (×200)        │
                    └──────────────┬──────────────┘
                                   ▼
┌──────────────────────────────────────────────────────────┐
│ saas_router → GroupRedirectGate (group only)              │
│   A-1 redirect │ A-2 promo │ B-* force redirect          │
│   END — no BATS                                           │
└──────────────────────────────────────────────────────────┘
                                   │ user (1:1)
                                   ▼
┌──────────────────────────────────────────────────────────┐
│ BATS：Hybrid / Gemini / Product Source / Context Cache    │
└──────────────────────────────────────────────────────────┘
```

### 13.1 專題九問

| # | 問題 | 建議答案 |
|---|------|----------|
| **1** | 群組只做導流 + 促銷？ | **是** — 正式治理僅 **A-1 + A-2** |
| **2** | 禁止群組商品搜尋？ | **是** — B-1，命中即導流 |
| **3** | 禁止群組私有資料查詢？ | **是** — B-2 |
| **4** | 私有資料全部導流 LINE OA？ | **是** — 群組不處理；一對一才允許 |
| **5** | 影響 Product Source Registry？ | **否**（結構不變；促銷僅讀 URL/列表） |
| **6** | 影響 Hybrid Search？ | **否** — 群組不呼叫；一對一不變 |
| **7** | 影響 Context Cache？ | **否** — 群組不載入 |
| **8** | 需要 DB schema？ | **MVP 不需要** — JSON + GCS；可選日後 `group_usage_log` |
| **9** | 需要 Host B API 變更？ | **MVP 不需要** — A-2 用現有列表 + 日期篩選；無新契約 |

### 13.2 低重構可行性

| 項目 | 評估 |
|------|------|
| 2～200 家 | ✅ 共用 policy + per-tenant profile |
| `saas_router` | ✅ 小改：插入 Gate |
| 新服務 | `GroupPromoService` + `LineService` 多則訊息 |
| BATS / Hybrid / Registry | ✅ 不修改核心契約 |

### 13.3 建議 Phase 9-B 實作順序（治理線）

| 順序 | 項目 |
|------|------|
| **9-B-G0** | `group_trigger_policy.json` schema v2（治理欄位） |
| **9-B-G1** | GroupRedirectGate + A-1 + B-1/B-2/B-3 導流 |
| **9-B-G2** | 事件 C-1 / C-2 |
| **9-B-G3** | A-2 GroupPromoService + ShortUrl + 多則 reply |
| **9-B-G4** | rate_limit + 日誌 |
| **9-B-G5** | 200 家 onboarding 文件對齊 |

---

## 附錄 A：盤點檔案清單

| 檔案 |
|------|
| `webhook/callback_core.php` |
| `core/safe_gateway.php` |
| `core/saas_router.php` |
| `core/tenant_resolver.php` |
| `config/tenant_registry.php` |
| `core/line_service.php` |
| `docs/TRAVEL_DATA_CENTER_PHASE9A6_LINE_GROUP_REDIRECT_STRATEGY.md` |

---

## 附錄 B：文件修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-29 | Phase 9-A.7 初版：LINE Group Governance Policy |
| 1.1 | 2026-05-29 | 一致性修正：`promo_max_items` 10→5（LINE Reply API 上限；MVP 不採 reply+push） |

---

*本文件為唯讀盤點與治理建議，不構成實作承諾。*
