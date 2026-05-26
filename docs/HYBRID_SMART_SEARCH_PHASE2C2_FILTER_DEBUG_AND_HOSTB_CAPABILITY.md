# Hybrid Smart Search — Phase 2-C.2 Filter Debug & Host B Capability

**主機：** 103.1.222.14（主機 A）  
**問題：** `六月底東京三萬以下` 仍出現 `12月東北樹冰 71800` → date/budget filter 可能未生效

---

## 1. SearchCondition → API params flow

```text
HybridSearchConditionBuilder::parse()
  → SearchCondition (keyword, date_from/to, budget_max)
  → SearchConditionCanonicalizer
  → ApiQueryMapper::toClientParams()
       budget_max → priceMax
       budget_min → priceMin
  → TourSearchApiClient::buildRequestUrlFromParams()  (gateway GET)
  → bonusmee.com/api/gateway/tour/search.php?...
  → TourSearchRequestBuilder::build()  (Host B GET /api/tour/search)
```

---

## 2. Allowlist 分析

### Host A — `TourSearchRequestBuilder::HOST_B_QUERY_ALLOWLIST`

| 欄位 | Phase 2-C.2 |
|------|-------------|
| `dateFrom` | ✅ 已有 |
| `dateTo` | ✅ 已有 |
| `priceMax` | ✅ **本階段新增** |
| `priceMin` | ✅ **本階段新增** |
| `destination` / `country` / `city` | ✅ 已有 |

### `ApiQueryMapper::ALLOWED_QUERY_KEYS`

與上表對齊（含 `priceMax` / `priceMin`）。

### 修正前缺口（根因）

| 欄位 | Phase 2-B/C 修正前 |
|------|-------------------|
| `budget_max` → `priceMax` | ❌ 僅 `metaOnly()`，**未上 wire** |
| `dateFrom` / `dateTo` | ✅ Mapper 有送；dry-run 可見 |

---

## 3. Final request URL 範例

**訊息：** `六月底東京三萬以下`  
**參考日：** 2026-05-26

```text
https://bonusmee.com/api/gateway/tour/search.php?sno=e1fd133c7e8e45a1&keyword=%E6%9D%B1%E4%BA%AC&destination=%E6%9D%B1%E4%BA%AC&country=%E6%97%A5%E6%9C%AC&city=%E6%9D%B1%E4%BA%AC&dateFrom=2026-06-21&dateTo=2026-06-30&priceMax=30000&page=1&pageSize=30
```

**search_url（前台）** 同期含 `dateFrom` / `dateTo`（**尚無 `priceMax`**，需前台支援後再補）。

---

## 4. Host B API filter capability

| 能力 | Host A 送參 | Host B 實際 filter | 證據 |
|------|-------------|-------------------|------|
| `keyword` | ✅ | ✅ 有效 | 可搜到東京相關 |
| `dateFrom` / `dateTo` | ✅（2-C 起） | ⚠️ **待驗證** | 若回傳 12 月東北 → **可能未 filter** |
| `priceMax` | ✅（2-C.2 起） | ⚠️ **待驗證** | 需看 `hybrid_search_api_debug.log` violations |
| `destination` | ✅ | ⚠️ 待驗證 | — |

**本 repo 不含 Host B 原始碼** → 無法確認 DB WHERE 是否使用日期/價格；以 **response violation 分析** 推斷。

---

## 5. 目前哪些 filter 真正有效

| Filter | 狀態 |
|--------|------|
| keyword | **有效**（已驗證 LIVE） |
| dateFrom/dateTo | **參數已送**；若結果含 12 月行程 → **Upstream 可能未 filter** |
| priceMax | **2-C.2 起參數已送**；是否 filter 待 LIVE debug log |

---

## 6. 哪些 filter 尚未真正生效

1. **修正前：** `priceMax` 完全未送（`budget_max` 只在 SearchCondition / meta）  
2. **可能：** Host B 收到 `dateFrom/dateTo` 但未套用 SQL（需 Host B 團隊確認）  
3. **search_url：** 前台 URL 可能僅 keyword 生效（與 API 行為獨立）

---

## 7. Host A Pre/Post Filter MVP 建議（尚未實作）

```text
keyword=東京 → Host B（較寬結果集）
  ↓ Host A post-filter（PHP）
  - tourDate ∈ [date_from, date_to]
  - price ≤ budget_max
  ↓ Gemini / formatter（已過濾列表）
```

| 優點 | 缺點 |
|------|------|
| 不依賴 Host B schema 變更 | 多抓資料、CPU 略增 |
| 可立即改善 LINE 顯示 | 分頁 total 可能不準 |

**觸發條件建議：** `filter_violations` 非空 且 `HybridSearchFeatureGate` ON。

---

## 8. Debug logs

| 檔案 | 用途 |
|------|------|
| `logs/hybrid_search_api_debug.log` | SearchCondition、api_params、**request_url**、item 數、前 5 筆 price/date、**filter_violations** |
| `logs/hybrid_search_dry_run.log` | 既有 hybrid flow |

**開關：** `gateway.hybrid_search.api_debug_log_enabled`（預設 true）

---

## 9. 未來 Ranking 階段建議

1. 確認 filter 生效後再做 relevance ranking  
2. 評分：日期接近度、價格、目的地匹配、出發地  
3. 若 Pre/Post Filter MVP 上線，ranking 在 filter 之後執行

---

*版本 v1 — 2026-05-26*
