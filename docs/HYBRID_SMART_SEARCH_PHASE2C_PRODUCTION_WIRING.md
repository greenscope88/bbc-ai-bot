# Hybrid Smart Search — Phase 2-C Production Wiring

**主機：** 103.1.222.14（主機 A）  
**分支：** `feature/api-gateway-mvp`  
**狀態：** Feature-flagged wiring（**預設 OFF**）

---

## 1. 接線架構圖

```text
LINE webhook (saas_router)
    ↓
TourPromptFeatureGate (既有 Stage 1-B-18，控制是否進入 tour prompt)
    ↓
TourPromptContextService::buildTourContextForPrompt()
    ↓
HybridSearchFeatureGate (Phase 2-C，預設 OFF)
    ├─ OFF / allowlist 未通過
    │     → TourQueryIntentDetector → keyword
    │     → TourSearchApiClient::search()
    │     → GeminiTourContextBuilder
    │
    └─ ON + allowlist 通過
          → HybridSearchConditionBuilder → SearchCondition
          → ApiQueryMapper → TourSearchApiClient::searchWithParams()
          → SearchUrlBuilder (覆寫 search_url，與 API 同源)
          → HybridSearchDryRunLogger (可選)
          → GeminiTourContextBuilder
          │
          └─ 失敗 / 空 keyword → fallback 舊 flow（同上）
```

---

## 2. Feature flag 設計

**設定位置：** `config/config.php` → `gateway.hybrid_search`

```php
'hybrid_search' => [
    'enabled' => false,              // 主開關，預設 OFF
    'allowed_sno' => [],             // staging sno 白名單
    'allowed_channels' => [],        // 非空時需同時符合 LINE channel
    'dry_run_log_enabled' => true,   // 寫入 logs/hybrid_search_dry_run.log
],
```

**程式閘門：** `core/search/HybridSearchFeatureGate.php`  
沿用 `TourPromptFeatureGate::check()` 邏輯（sno 必填、allowlist 非空才通過）。

> **注意：** 本階段**不**修改 `.env`；啟用 staging 時僅在 `config.php` 調整 allowlist（需部署審核）。

---

## 3. Allowlist 設計

| 條件 | 行為 |
|------|------|
| `enabled=false` | 100% 舊 keyword flow |
| `enabled=true` 且 `allowed_sno=[]` | 視同關閉（不通過） |
| sno 不在 `allowed_sno` | 舊 flow |
| `allowed_channels` 非空且 channel 不符 | 舊 flow |
| 全部通過 | Hybrid flow |

**saas_router** 已傳入 `channelId`（`tenant.channel_id` 或 `event.destination`）與 `traceId`。

---

## 4. Rollback flow

1. **一鍵關閉：** `gateway.hybrid_search.enabled = false` → 立即回到舊 flow  
2. **清空 allowlist：** `allowed_sno = []` → 即使 enabled=true 也不會啟用  
3. **無需改 DB / SQL / Host B**  
4. **重新部署** `config.php` 或 hotfix 該檔即可

---

## 5. Dry-run log 設計

**檔案：** `logs/hybrid_search_dry_run.log`  
**類別：** `HybridSearchDryRunLogger`

| 欄位 | 說明 |
|------|------|
| trace_id | 與 saas_router 一致 |
| sno | tenant sno |
| channel | LINE channel（非 userId） |
| message | 原始使用者問句 |
| flow | `hybrid` / `legacy` / `legacy_fallback` |
| fallback_reason | 如 `hybrid_allowlist_blocked`、`empty_keyword_hybrid` |
| search_condition | SearchCondition JSON |
| api_params | ApiQueryMapper 輸出 |
| search_url_params | SearchUrlBuilder::searchParamsOnly() |

**禁止寫入：** api_key、userId、電話、email、護照、旅客姓名等。

---

## 6. Production safety rules

| ID | 規則 |
|----|------|
| P1 | 預設 `enabled=false` |
| P2 | Hybrid 路徑全部 `try/catch`，失敗回傳 legacy |
| P3 | 不可因 hybrid 拋錯中斷 LINE 回覆 |
| P4 | 不修改 Host B API / DB schema |
| P5 | 不修改 TourFallbackFormatter UI |
| P6 | 短網址仍由 `SearchUrlBuilder` + `short_url.enabled` 控制 |

---

## 7. API query / search_url 一致性

- **同源：** `SearchConditionCanonicalizer`
- **API：** `ApiQueryMapper::toClientParams()` → `TourSearchApiClient::searchWithParams()`
- **URL：** `SearchUrlBuilder::build()` 覆寫 API 回傳的 `search_url`
- **鍵名對齊：** `keyword`, `dateFrom`, `dateTo`, `destination`

---

## 8. Staging rollout plan

1. 部署 Phase 2-C commit（flag 仍 OFF）  
2. 在 staging 設定：
   ```php
   'enabled' => true,
   'allowed_sno' => ['e1fd133c7e8e45a1'],
   'allowed_channels' => ['<staging-channel-id>'],  // 可選
   ```
3. 執行 `tests/hybrid_search/test_hybrid_search_integration_dry_run.php`  
4. 檢查 `logs/hybrid_search_dry_run.log`  
5. LINE staging channel 實測五句測試文案  
6. 確認正式 channel / sno 不在 allowlist  
7. 通過後再考慮擴大 allowlist（仍建議分階段）

---

## 9. 測試清單

| 測試檔 | 目的 |
|--------|------|
| `test_hybrid_search_feature_flag.php` | gate ON/OFF、allowlist |
| `test_hybrid_search_integration_dry_run.php` | hybrid 接線 + API URL 含 date |
| `test_hybrid_search_search_url_consistency.php` | API / URL / client 參數一致 |
| `test_hybrid_search_fallback_legacy_flow.php` | OFF、allowlist、exception fallback |

**測試句：**

- 六月底東京三萬以下  
- 高雄出發東京  
- 歐洲團  
- 日本親子團  
- 六月三十出發的東京團  

---

## 10. 未來 Gemini fallback 插入點

```text
TourLineReplyComposer::resolve()
    ↑ 目前：Gemini 失敗 → TourFallbackFormatter（Scheme C）
    ↑ 未來：可在 prompt 組裝前注入 hybrid SearchCondition 摘要
    ↑ 或在 Gemini 空結果時，用 hybrid search_url + 固定列表並列
```

**建議插入位置：** `saas_router` 內 `buildTourContextForPrompt` 之後、`TourLineReplyComposer::resolve` 之前，傳遞 `hybrid_meta`（非本階段範圍）。

---

## 檔案變更摘要

| 檔案 | 變更 |
|------|------|
| `config/config.php` | 新增 `gateway.hybrid_search` |
| `core/search/HybridSearchFeatureGate.php` | 新增 |
| `core/search/HybridSearchDryRunLogger.php` | 新增 |
| `core/tour_prompt_context_service.php` | Hybrid 接線 + fallback |
| `core/tour_search_api_client.php` | `searchWithParams()` |
| `core/saas_router.php` | 傳入 channelId、traceId |

---

*版本 v1 — 2026-05-26*
