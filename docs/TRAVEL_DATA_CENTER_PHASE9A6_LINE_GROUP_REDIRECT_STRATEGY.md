# BBC 旅業資料中心 — Phase 9-A.6 LINE Group Redirect Strategy

**副標：** 多租戶 LINE 群組導流與 Tenant LINE Profile 規劃（唯讀盤點）

**文件代號：** `TRAVEL_DATA_CENTER_PHASE9A6_LINE_GROUP_REDIRECT_STRATEGY`

**版本：** Phase 9-A.6（規劃 only）

**狀態：** Planning — 本文件不觸發程式、JSON、目錄、cache、SQL、Git、`.env` 變更

**規劃主機：** 主機 A `103.1.222.14` · 專案路徑 `C:\bbc-ai-bot`

**關聯文件：**

- [TRAVEL_DATA_CENTER_PHASE9A5_STORAGE_AND_SHORTURL_STRATEGY.md](./TRAVEL_DATA_CENTER_PHASE9A5_STORAGE_AND_SHORTURL_STRATEGY.md)（GCS / cache 混合策略）
- [TRAVEL_DATA_CENTER_PHASE9A7_LINE_GROUP_GOVERNANCE_POLICY.md](./TRAVEL_DATA_CENTER_PHASE9A7_LINE_GROUP_GOVERNANCE_POLICY.md)（群組治理；含促銷參數）
- [TRAVEL_DATA_CENTER_PHASE9_MVP_IMPLEMENTATION_PLAN.md](./TRAVEL_DATA_CENTER_PHASE9_MVP_IMPLEMENTATION_PLAN.md)（Phase 9-A）
- [TENANT_REGISTRY_PHASE2A_STAGE1.md](./TENANT_REGISTRY_PHASE2A_STAGE1.md)
- [TENANT_TRAVEL_B_ONBOARDING_CHECKLIST.md](./TENANT_TRAVEL_B_ONBOARDING_CHECKLIST.md)

**盤點日期：** 2026-05-29

---

## 1. 目的

在 **Phase 9-A / 9-A.5**（商品源、短網址、儲存策略）之外，補充 **LINE 群組導流** 之正式架構規劃。

**已確認業務規則（全旅行社共用觸發條件）：**

| # | 觸發條件 |
|---|----------|
| 1 | OA 被加入群組 |
| 2 | 使用者在群組 **@OA** |
| 3 | 使用者輸入關鍵字：**客服**、**一對一**、**報名**、**行程詢問** |

**系統回覆（導流）：**

> 請加入一對一客服 LINE OA  
> （附該社 **LINE_OA_LINK**）

**已確認架構決策：**

| 層級 | 路徑（規劃） | 內容 |
|------|--------------|------|
| **共用規則** | `shared/config/group_trigger_policy.json` | 觸發條件、關鍵字、回覆模板 |
| **旅行社專屬** | `tenants/{sno}/config/tenant_line_profile.json` | `LINE_OA_LINK`、開關、policy 引用 |

**LINE_OA_LINK：** 串接時 **人工輸入** 或 **後台維護**；**不依賴** webhook 自動解析。

**本階段交付：** 唯讀盤點 + 架構建議；**不** 修改程式。

**與 Phase 9-A.7 促銷參數對齊（正式）：** 群組「最新促銷」（A-2）之 `promo_reply_mode = single_item_per_message`、`promo_max_items = 5`。理由：LINE Reply API 單次最多 **5 則** message；MVP **不採** reply + push 混合。詳見 [Phase 9-A.7](./TRAVEL_DATA_CENTER_PHASE9A7_LINE_GROUP_GOVERNANCE_POLICY.md) §3.2。

---

## 2. 現有 LINE 架構盤點

### 2.1 Webhook 入口鏈路

```
LINE Messaging API (POST + X-Line-Signature)
    ↓
webhook/callback_core.php  或  core/safe_gateway.php
    ↓
routeAIRequest() → SaaSRouter::handleEvent()
    ↓
LineCredentialResolver::resolveByChannelId(destination)   【Stage 5-3】
    ↓
LineService::verifySignature()
    ↓
【僅處理 events[0]】
    ↓
需同時具備 replyToken + message.text
    ↓
TenantResolver::resolve() → IntentRouter → TourService / TourPromptContextService
    ↓
TourLineReplyComposer → LineService::replyToLine()
```

| 檔案 | 路徑 | 職責 |
|------|------|------|
| Webhook | `webhook/callback_core.php` | POST 解析、`__meta` 簽章/raw_body |
| 閘道 | `core/safe_gateway.php` | 健康檢查、日誌、`你好` 快速路徑 |
| 路由 | `core/saas_router.php` | **主業務** BATS 鏈路（見 §2.6） |
| LINE API | `core/line_service.php` | 簽章驗證、`reply` API |
| 憑證 | `core/tenant/LineCredentialResolver.php` | `destination` → per-tenant secret/token（`.env`） |

### 2.2 saas_router 現況（關鍵限制）

```104:107:core/saas_router.php
        if ($replyToken === '' || $userMessage === '') {
            Logger::log('saas_router.log', 'empty_reply_or_message', ['trace_id' => $traceId]);
            return ['ok' => true, 'message' => 'ignored'];
        }
```

| 行為 | 現況 |
|------|------|
| 事件數 | **只讀 `events[0]`** |
| 必要欄位 | `replyToken` **且** `message.text` 非空 |
| `event.type` | **未判斷**（message / join / leave / postback 等） |
| `source.type` | **未讀取**（user / group / room） |
| `message` 非 text | **忽略**（貼圖、位置、檔案等） |
| @mention | **未解析** `message.mention` |
| 群組關鍵字 | **未實作**（客服 / 一對一 / 報名 / 行程詢問） |
| `memberJoined` | **未處理**（通常無 `message.text` → 直接 ignored） |

**結論：** 現行 BATS 鏈路為 **一對一文字客服 + 行程查詢**；**群組導流尚未存在**。

### 2.3 tenant_resolver

**解析順序：**

1. `ConfigTenantRegistry::resolveByChannel($event['destination'])`
2. Legacy `tenant_context_map.php`（channel → sno）
3. Legacy SQL：`tenant_profiles` JOIN `tenant_line_channels` WHERE `channel_id = :channel_id`

```13:22:core/tenant_resolver.php
    public static function resolve(PDO $pdo, array $event, array $config): array
    {
        $channelId = isset($event['destination']) ? (string) $event['destination'] : '';

        $registryTenant = self::resolveViaRegistry($channelId);
        if ($registryTenant !== null) {
            return $registryTenant;
        }

        return self::resolveViaLegacy($pdo, $event, $config, $channelId);
```

**回傳 shape（維持 saas_router 契約）：**

`sno`, `company_name`, `ai_tone`, `travel_specialties`, `price_catalog_json`, `channel_id`

**缺口：** 無 `line_oa_link`、無 `group_redirect_enabled`、無群組 policy 引用。

### 2.4 tenant_registry（ConfigTenantRegistry）

| 欄位（現有） | 用途 |
|--------------|------|
| `tenant_key` | 內部鍵（travel_a / travel_b） |
| `line_channel_id` | Webhook `destination` |
| `sno` | Host B wire authority |
| `depID` / `storeNo` / `provider_id_no` | Host B / URL 內部 |
| `profile.*` | prompt 語氣（company_name, ai_tone, …） |
| `features.*` | tour_prompt / hybrid_search / fixed_formatter |
| `credential_env_prefix` | `LINE_CHANNEL_SECRET__*` / `LINE_CHANNEL_ACCESS_TOKEN__*` |

**缺口：** 無 LINE 一對一好友連結、無群組導流開關。

### 2.5 travel_b tenant mapping

| 項目 | 值（`config/tenant_registry.php`） |
|------|-------------------------------------|
| `tenant_key` | `travel_b` |
| `display_name` | 旅行蜜優惠 |
| `line_channel_id` | `Uc29debdbf97e5e3aa050a6f54cf32091` |
| `sno` | `5f99b8d665e8444d` |
| `depID` / `storeNo` | 888 / 6180 |
| `features` | tour_prompt / hybrid_search / fixed_formatter = **true**（staging 文件與 registry 狀態需營運對齊） |

**`tenant_context_map.php`：** 目前 **僅含 travel_a**（`Ufcedee37a93230a802c30b138f6228f8` → `e1fd133c7e8e45a1`）；**travel_b 不在 legacy map**，依 registry 解析即可。

**憑證：** `LineCredentialResolver` 需 `.env` 中 `LINE_CHANNEL_SECRET__travel_b`、`LINE_CHANNEL_ACCESS_TOKEN__travel_b`；缺失時 **fail-closed 403**（travel_a 除外保留 legacy）。

### 2.6 LINE 群組事件是否已有處理

| 事件類型（LINE API） | 現行程式 | 評估 |
|---------------------|----------|------|
| `message`（group + text） | 若有 text 會走 **完整 BATS**（非導流） | 與群組導流 **需求衝突**，需分流 |
| `message`（group + @mention） | 未特別處理 | 需新增 mention 偵測 |
| `memberJoined`（OA 入群） | **ignored**（無 text） | 需新增 handler |
| `follow` / `unfollow` | 未見專用處理 | 非本階段 MVP |
| `postback` | 未見 | 非本階段 |

**結論：** **群組導流為全新橫切能力**，應在 `saas_router` **最前端** 以 **early return** 插入，避免進入 Hybrid / Gemini 路徑。

### 2.7 intent_router 與關鍵字

`IntentRouter` 現有關鍵字：天氣、護照、簽證、訂單、匯率、價格、行程（`TourQueryIntentDetector`）。

**不含：** 客服、一對一、報名、行程詢問（群組導流用）。

建議：**群組導流關鍵字放在 `group_trigger_policy.json`**，**不** 擴充 `IntentRouter`（避免一對一聊天誤觸發導流）。

### 2.8 BATS 鏈路定義（本文件用法）

**BATS** = 現行已驗收之 **Bot AI Tour Service** 執行鏈：

`LINE webhook → saas_router → TenantResolver → IntentRouter → TourPromptContextService (Hybrid/Host B) → TourLineReplyComposer → LINE reply`

群組導流為 **BATS 前置分流**，不取代 BATS 核心。

---

## 3. Group Trigger Policy 設計

### 3.1 建議路徑與職責

**正式（GCS）：** `shared/config/group_trigger_policy.json`  
**開發 fallback（Git）：** `config/group_trigger_policy.default.json`

| 職責 | 說明 |
|------|------|
| 定義 **全平台共用** 觸發規則 | 所有旅行社同一套觸發邏輯 |
| 定義 **回覆模板骨架** | 含 `{LINE_OA_LINK}`、`{company_name}` 占位 |
| **不含** 各社 OA 連結 | 連結僅來自 tenant_line_profile |

### 3.2 建議 schema（規劃）

```json
{
  "schema_version": 1,
  "policy_id": "default_group_redirect_v1",
  "enabled": true,
  "triggers": {
    "on_bot_join_group": true,
    "on_mention_bot": true,
    "on_keywords": true
  },
  "keywords": ["客服", "一對一", "報名", "行程詢問"],
  "keyword_match": "contains",
  "scopes": ["group"],
  "reply_template": {
    "type": "text",
    "body": "您好！群組內無法提供完整客服與報名服務。\n請加入 {company_name} 一對一客服 LINE：\n{LINE_OA_LINK}"
  },
  "skip_if_already_dm": false
}
```

| 欄位 | 說明 |
|------|------|
| `policy_id` | 版本化；tenant 可引用 |
| `scopes` | 僅 `group` 生效（一對一 chat 不走此 policy） |
| `keyword_match` | `contains` 或 `exact`（建議 contains） |
| `on_mention_bot` | 解析 LINE `mention.mentionees` 是否含 bot userId |

### 3.3 是否應獨立於 Product Source Registry？

**是，必須獨立。**

| 維度 | Group Trigger Policy | Product Source Registry |
|------|---------------------|-------------------------|
| 領域 | LINE 通道行為 / 導流 | 商品搜尋 URL / 庫存來源 |
| 觸發 | 群組事件、關鍵字 | 行程查詢 intent |
| 資料 | 關鍵字、模板 | source_id、capability、url template |
| 變更頻率 | 低（平台規則） | 中（新增 GRP 等源） |
| 200 家擴充 | **一份共用 policy** | catalog + 每社 product_sources |

**不建議** 將 `group_trigger_policy_id` 塞入 `product_sources.json` 或 `ProductSourceRegistry` 主表。

---

## 4. Tenant Line Profile 設計

### 4.1 建議路徑

**正式（GCS）：** `tenants/{sno}/config/tenant_line_profile.json`  
**開發 sample（Git）：** `config/tenants/samples/travel_b_line_profile.json`（可選）  
**執行時 cache（Host A）：** `cache/tenants/{sno}/tenant_line_profile.json`（與 Phase 9-A.5 一致）

### 4.2 建議欄位

| 欄位 | 類型 | 必填 | 說明 |
|------|------|------|------|
| `schema_version` | int | 是 | |
| `sno` | string | 是 | 與 registry 一致 |
| `tenant_key` | string | 建議 | 內部鍵（travel_b） |
| `line_channel_id` | string | 建議 | 與 webhook `destination` 對照（稽核用） |
| **`line_oa_link`** | string (URL) | **是** | 一對一 OA 好友連結（`https://line.me/R/...` 或 `https://liff.line.me/...`） |
| `line_oa_display_name` | string | 否 | 回覆顯示用名稱 |
| **`group_redirect_enabled`** | bool | 是 | 該社是否啟用群組導流 |
| **`group_trigger_policy_id`** | string | 是 | 引用 `shared/config/group_trigger_policy.json` 的 `policy_id` |
| `group_reply_override` | object | 否 | 僅覆寫模板文字，不改觸發規則 |
| `updated_at` | string | 建議 | ISO8601 |
| `maintained_by` | string | 否 | 後台操作者 |

**不建議放入 tenant_line_profile：**

- `depID` / `storeNo`（留在 `tenant_registry` / TenantContextResolver）
- 商品源清單（留在 `product_sources.json`）
- Channel secret / token（留在 `.env` / vault）

### 4.3 是否適合獨立 Tenant Line Profile？

**是，強烈建議獨立。**

| 理由 | 說明 |
|------|------|
| 關注點分離 | LINE 通道 ≠ 商品源 ≠ Host B 商業欄位 |
| 200 家擴充 | 每社一檔 `tenant_line_profile.json`，不膨脹 `tenant_registry.php` |
| 營運維護 | `LINE_OA_LINK` 由業務/後台改 GCS，不需 deploy PHP |
| 安全 | OA link 非 secret，可進 GCS；token 仍只在 `.env` |

`tenant_registry.php` 保留 **身分與 feature gate**；`tenant_line_profile.json` 管 **LINE UX 與導流**。

---

## 5. LINE_OA_LINK 管理策略

### 5.1 原則

| 原則 | 說明 |
|------|------|
| **人工維護** | 串接 onboarding 時由 BBC 或旅行社提供官方 OA 連結 |
| **後台寫入 GCS** | 正式營運以 GCS 為權威；可選同步至 Host A cache |
| **禁止 webhook 推導** | LINE API **不提供**「此 Bot 的加好友連結」於 message event；`destination` 僅為 channel id |
| **禁止從群組推導** | 群組 `groupId` 與一對一 OA link **無對應關係** |

### 5.2 驗證規則（實作期建議）

| 檢查 | 說明 |
|------|------|
| URL scheme | 僅 `https://` |
| Host allowlist | `line.me`、`liff.line.me`（可擴充） |
| 非空 | `group_redirect_enabled=true` 時必填 |
| Fail-closed | 缺 link 時回覆 **平台預設客服說明** + log alert（不 crash webhook） |

### 5.3 與 tenant_registry 關係

| 層 | 內容 |
|----|------|
| `tenant_registry.php` | `line_channel_id`、`sno`、credentials prefix |
| `tenant_line_profile.json` | `line_oa_link`、群組導流開關 |

Onboarding checklist 應新增一項：**提供並驗證 LINE_OA_LINK**。

---

## 6. 多租戶 LINE 群組導流流程

### 6.1 建議執行流程（Phase 9-B 目標）

```
LINE webhook POST
    ↓
驗簽 + LineCredentialResolver（既有）
    ↓
TenantResolver → sno（既有）
    ↓
【新增】GroupRedirectGate::shouldHandle(event, sno)
    ├─ 讀 group_trigger_policy（共用）
    ├─ 讀 tenant_line_profile（per sno）
    ├─ group_redirect_enabled == false → 跳過
    ├─ source.type != 'group' → 跳過（走原 BATS）
    └─ 符合觸發 → 組 reply → LineService::reply → return（不進 Hybrid）
    ↓
【既有】BATS：IntentRouter → TourPrompt → Gemini → reply
```

### 6.2 觸發判定矩陣

| 條件 | `on_bot_join_group` | `on_mention_bot` | `on_keywords` |
|------|---------------------|------------------|-----------------|
| OA 被加入群組 | ✅ `memberJoined` + bot in members | — | — |
| 使用者 @OA | — | ✅ `message` + mention | — |
| 關鍵字 | — | — | ✅ text contains 任一 keyword |

**三者為 OR**；任一成立且 `scopes` 含 group → 導流回覆。

### 6.3 與一對一聊天分流

| `source.type` | 行為 |
|---------------|------|
| `user`（一對一） | **不** 走群組 policy；維持 BATS |
| `group` | 先評估 Group Redirect；未觸發再走 BATS（可配置：群組內行程查詢是否允許 — **建議 Phase 9-B 先只做導流，群組內 BATS 關閉或另案**） |

**風險：** 群組內使用者問「東京五日」若走 BATS，回覆過長；**建議 Phase 9-B MVP 群組僅導流**，行程查詢仍引導一對一。

### 6.4 儲存與 cache（對齊 Phase 9-A.5）

| 資料 | 權威 | Host A cache |
|------|------|--------------|
| `group_trigger_policy.json` | GCS shared | 可選全域單檔 cache |
| `tenant_line_profile.json` | GCS per sno | `cache/tenants/{sno}/tenant_line_profile.json` |

GCS 不可用 → 讀 cache → Git default policy + 該社 profile 缺省則 **關閉群組導流**（fail-safe）。

---

## 7. travel_b 範例

**規劃範例（非實際建立檔案）：**

```json
{
  "schema_version": 1,
  "sno": "5f99b8d665e8444d",
  "tenant_key": "travel_b",
  "line_channel_id": "Uc29debdbf97e5e3aa050a6f54cf32091",
  "line_oa_link": "https://line.me/R/todo/travel_b-official-oa",
  "line_oa_display_name": "旅行蜜優惠 客服",
  "group_redirect_enabled": true,
  "group_trigger_policy_id": "default_group_redirect_v1",
  "updated_at": "2026-05-29T00:00:00+08:00",
  "maintained_by": "onboarding"
}
```

| 項目 | 說明 |
|------|------|
| `line_oa_link` | **待 onboarding 填入真實 URL** |
| `group_trigger_policy_id` | 指向共用 policy，travel_b 不需自訂關鍵字 |
| 測試 | staging 群組加入 travel_b OA → 應回覆含連結文案 |

---

## 8. 與 Tenant Mapping 關聯

```
webhook destination (line_channel_id)
    ↓
TenantResolver / ConfigTenantRegistry
    ↓
sno  （例如 travel_b → 5f99b8d665e8444d）
    ↓
載入 tenants/{sno}/config/tenant_line_profile.json
    ↓
GroupRedirectHandler
```

| 元件 | 是否需改 |
|------|----------|
| `TenantResolver` 回傳 shape | **不必改**（仍回傳 sno）；群組模組 **另讀** line profile |
| `tenant_registry.php` | **不必** 塞入 line_oa_link；可選增加 `features.group_redirect` gate |
| `tenant_context_map.php` | **不必**（Host B 欄位與 LINE 導流無關） |
| `TenantContextResolver` | **不必** |

**可選 gate：** `tenant_registry.features.group_redirect` 與 `group_redirect_enabled` **雙重開關**（registry 平台級 + profile 社級）。

---

## 9. 與 Product Source Registry 關聯

| 項目 | 關聯 |
|------|------|
| 資料檔 | **無直接關聯**；分開路徑、分開 loader |
| 執行順序 | 群組導流 **早於** Product Source / Hybrid |
| 回覆內容 | 導流訊息 **不含** 商品搜尋 URL、不含短網址 |
| 一對一 BATS | 進入 BATS 後才走 Product Source / SearchUrlBuilder / ShortUrl |

**結論：** Product Source Registry **不需** 增加群組相關欄位。

---

## 10. 與 BATS 關聯

| 面向 | 說明 |
|------|------|
| 入口 | 同一 `saas_router` / `safe_gateway` |
| 分流點 | `handleEvent` 內，Tenant resolve **之後**、DB / Hybrid **之前** |
| Feature flag | 建議 `group_redirect.enabled` + per-tenant `group_redirect_enabled` |
| 日誌 | `saas_router.log` 新增 `group_redirect_reply` step |
| 不變 | `TourPromptContextService`、`HybridSearchConditionBuilder`、`TourLineReplyComposer` 契約 |

---

## 11. 低重構可行性評估

### 11.1 規模矩陣

| 規模 | 可行性 | 作法 |
|------|--------|------|
| 2 家（travel_a / travel_b） | ✅ 高 | GCS 兩份 profile + 共用 policy |
| 10 家 | ✅ 高 | 同上 + cache sync |
| 200 家 | ✅ 中高 | GCS 200 檔 + 自動化 onboarding API；**不改** saas_router 核心迴圈結構 |

### 11.2 元件影響表

| 元件 | 需改？ | 規模 |
|------|--------|------|
| `saas_router.php` | 是 | **小** — early gate + 呼叫新 handler |
| 新 `GroupRedirectHandler` / `GroupTriggerPolicyLoader` | 新增 | 中 |
| `TenantResolver` | 否 | — |
| `tenant_registry.php` | 可選 | 小 — feature flag |
| `IntentRouter` | 否 | — |
| `Hybrid Search` | 否 | — |
| `ProductSourceRegistry` | 否 | — |
| `Host B API` | 否 | — |
| **DB schema** | **否（MVP）** | JSON + GCS 足夠 |
| `.env` | 否（本功能） | 憑證既有 |

### 11.3 結論

**低重構可行。** 以 **前置分流 + 獨立 JSON 設定** 擴充，無需重寫 BATS 或 Hybrid。

---

## 12. 未來後台管理規劃

| 功能 | 說明 |
|------|------|
| **旅行社 onboarding** | 表單：sno、line_channel_id、**LINE_OA_LINK**、啟用群組導流 |
| **GCS 寫入** | `tenants/{sno}/config/tenant_line_profile.json` |
| **共用 policy 管理** | 平台管理員編輯 `group_trigger_policy.json`（關鍵字、模板） |
| **稽核** | `updated_at`、`maintained_by` |
| **預覽** | 顯示套用模板後的回覆文字 |
| **不經 Host B** | 純 LINE 設定，與價格/庫存無關 |

**Phase 9-B 可無後台 UI**，以 GCS 手動上傳 + Git sample 驗證。

---

## 13. 最終建議架構

```
┌─────────────────────────────────────────────────────────────┐
│ GCS shared/config/group_trigger_policy.json                  │
│   triggers · keywords · reply_template                       │
└────────────────────────────┬────────────────────────────────┘
                             │
┌────────────────────────────┴────────────────────────────────┐
│ GCS tenants/{sno}/config/tenant_line_profile.json            │
│   line_oa_link · group_redirect_enabled · policy_id          │
└────────────────────────────┬────────────────────────────────┘
                             │ sync
┌────────────────────────────┴────────────────────────────────┐
│ Host A cache/tenants/{sno}/tenant_line_profile.json          │
└────────────────────────────┬────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────┐
│ saas_router: GroupRedirectGate (early return)                │
└────────────────────────────┬────────────────────────────────┘
                             │ 未觸發
                             ▼
┌─────────────────────────────────────────────────────────────┐
│ BATS（既有）→ Product Source / Hybrid / Short URL（另線）     │
└─────────────────────────────────────────────────────────────┘
```

### 13.1 專題七問

| # | 問題 | 建議答案 |
|---|------|----------|
| **1** | 是否適合獨立 Tenant Line Profile？ | **是** — `tenants/{sno}/config/tenant_line_profile.json` |
| **2** | LINE_OA_LINK 是否應人工維護？ | **是** — onboarding / 後台寫 GCS；**禁止** webhook 自動解析 |
| **3** | 是否需要 DB schema？ | **MVP 不需要**；GCS + cache 即可；長期可選 `tenant_line_profiles` 表做快取索引 |
| **4** | 是否需要修改 Host B？ | **不需要** |
| **5** | 是否需要修改 Hybrid Search？ | **不需要** |
| **6** | 是否需要修改 Product Source Registry？ | **不需要**（獨立 policy / profile） |
| **7** | 是否影響 200 家擴充？ | **不影響** — 共用 1 份 policy + 每社 1 份 profile；saas_router 僅加 gate |

### 13.2 建議 Phase 9-B 實作順序（群組導流子項）

若與 Phase 9-A.5 並行，建議 **獨立 PR / 子階段**：

| 順序 | 項目 |
|------|------|
| **9-B-G1** | `GroupTriggerPolicyLoader` + Git default JSON sample（不提交正式 GCS） |
| **9-B-G2** | `TenantLineProfileLoader` + travel_b sample |
| **9-B-G3** | `GroupRedirectHandler` + `saas_router` early gate |
| **9-B-G4** | 處理 `memberJoined` / group `message` / mention 三觸發 |
| **9-B-G5** | staging LINE 群組驗收（travel_a、travel_b） |

### 13.3 風險與不建議

| 風險 | 控管 |
|------|------|
| 群組內仍走 BATS 回長文 | MVP 群組 **僅導流** |
| 缺 LINE_OA_LINK | fail-closed 記錄 + 通用文案 |
| 關鍵字與行程查詢衝突 | 關鍵字 **僅在 group scope** 生效 |
| 200 家 link 放 Git | **禁止**；僅 GCS + cache |
| 與 Product Source 混檔 | **禁止** |

---

## 附錄 A：盤點檔案清單

| 類別 | 路徑 |
|------|------|
| Webhook | `webhook/callback_core.php`, `core/safe_gateway.php` |
| 路由 | `core/saas_router.php` |
| 租戶 | `core/tenant_resolver.php`, `config/tenant_registry.php`, `config/tenant_context_map.php` |
| Registry | `core/tenant/ConfigTenantRegistry.php`, `core/tenant/ResolvedTenant.php` |
| 憑證 | `core/tenant/LineCredentialResolver.php` |
| Host B context | `core/tenant_context_resolver.php` |
| Intent | `core/intent_router.php` |
| LINE API | `core/line_service.php` |
| 文件 | `docs/TENANT_REGISTRY_PHASE2A_STAGE1.md`, `docs/TENANT_TRAVEL_B_ONBOARDING_CHECKLIST.md`, `docs/TRAVEL_DATA_CENTER_PHASE9A5_STORAGE_AND_SHORTURL_STRATEGY.md` |

---

## 附錄 B：文件修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-29 | Phase 9-A.6 初版：LINE Group Redirect & Tenant Line Profile |
| 1.1 | 2026-05-29 | 一致性修正：與 9-A.7 對齊 `promo_max_items = 5`（LINE Reply API 上限） |

---

*本文件為唯讀盤點與架構建議，不構成實作承諾。*
