# Hybrid Smart Search — Phase 2-A Core Layer

**主機：** 103.1.222.14（主機 A）  
**專案根目錄：** `C:\bbc-ai-bot`  
**文件版本：** 2026-05-25  
**狀態：** Phase 2-A 已實作（**未接線 production**）

**前置文件：** `docs/HYBRID_SMART_SEARCH_PHASE1_ARCHITECTURE_PLAN.md`

---

## 1. 本階段目標

建立 **低 CPU、高穩定、可測試** 的搜尋核心層：

- `SearchCondition` DTO
- Rule-Based parsers（Date / Area / Budget / Keyword）
- CLI 測試矩陣

**刻意不做：** 修改 `TourPromptContextService`、`saas_router`、Host B HTTP、LINE formatter、Gemini Intent。

---

## 2. 唯讀分析摘要（現行 production 對照）

| 元件 | 路徑 | 現況 | Phase 2-A 關係 |
|------|------|------|----------------|
| Rule 意圖 | `core/tour_query_intent_detector.php` | 單一 `keyword` | 未改；未來委派 Core 或並行 |
| Context 入口 | `core/tour_prompt_context_service.php` | `keyword` → API | **未接線** |
| API Builder | `core/api_gateway/production/TourSearchRequestBuilder.php` | allowlist 含 date/destination | **未接線** |
| 搜尋編排 | `core/tour_search_service.php` | `buildSearchUrl($sno,$keyword)` | **未接線** |
| Context 輸出 | `core/gemini_tour_context_builder.php` | 商品列 + search_url | **未接線** |
| LINE 輸出 | `core/tour_fallback_formatter.php` | Scheme C 固定清單 | **未改** |
| Gateway 測試 | `tests/api_gateway/` | Host B 契約 / normalizer | 獨立於 hybrid_search |

---

## 3. 目錄與 Namespace 規劃

```
core/search/
  SearchCondition.php      # DTO
  ChineseNumberHelper.php  # 中文數字 / 萬元
  DateParser.php           # Rule-Based 日期
  AreaParser.php           # Rule-Based 區域階層
  BudgetParser.php         # Rule-Based 預算
  KeywordNormalizer.php    # Rule-Based 同義詞

tests/hybrid_search/
  _test_helpers.php
  test_search_condition_dto.php
  test_date_parser.php
  test_area_parser.php
  test_budget_parser.php
  test_keyword_normalizer.php
```

**未來 Phase 2-B（規劃，未實作）：**

```
core/search/
  HybridSearchConditionBuilder.php  # 編排 L1 parsers
  ApiQueryMapper.php
  SearchUrlBuilder.php
  GeminiIntentParser.php            # L3 only
```

---

## 4. Parser 分工

| Parser | 類型 | 本階段 | 未來 Gemini |
|--------|------|--------|-------------|
| `DateParser` | Rule | ✅ | 僅 patch 解不出的模糊句 |
| `AreaParser` | Rule + 靜態階層表 | ✅ | 罕見新地名 |
| `BudgetParser` | Rule + regex | ✅ | 複雜敘述 |
| `KeywordNormalizer` | Rule + 同義詞表 | ✅ | 新造詞 |
| `TourQueryIntentDetector` | Rule（既有） | 不修改 | 不取代 |
| `DepartureCityParser` | Rule | Phase 2-B | — |
| `GeminiIntentParser` | LLM JSON | ❌ | Phase 2-C |

### 4.1 建議編排順序（未來接線）

```text
SearchCondition::empty(user_message)
  → DateParser
  → BudgetParser
  → AreaParser
  → KeywordNormalizer
  → (optional) merge TourQueryIntentDetector keyword
  → (optional L3) GeminiIntentParser.patch()
```

日期優先於區域，避免「六月底東京」被錯誤切成 keyword。

---

## 5. SearchCondition DTO

| 欄位 | 說明 |
|------|------|
| `keyword` | API / search_url 主字串 |
| `area` / `destination` | 區域階層 |
| `departure_city` | 出發地（Phase 2-B） |
| `date_from` / `date_to` | ISO `Y-m-d` |
| `budget_min` / `budget_max` | TWD |
| `travel_style[]` / `special_tags[]` | 語意標籤 |
| `free_text` | 原始訊息 |
| `intent` | 預設 `tour_search` |
| `confidence` | 累加式 0–0.99 |
| `parser_flags` | `date_parsed`, `area_parsed`, `budget_parsed`, `keyword_normalized` |
| `date_precision` / `date_label` | 可選 metadata |

**API：** `empty()`, `with()`, `flag()`, `bumpConfidence()`, `toArray()`, getters。

**相容性：** PHP 7.4+（主機 XAMPP CLI 為 7.4.33）。

---

## 6. DateParser 規則（已實作）

| 輸入 | 行為 |
|------|------|
| 六月三十、6/30、6月30日 | 單日；無年→參考年；已過→+1 年 |
| 六月底、七月初、7月中 | 月內區間（21–末、1–10、11–20） |
| 暑假 | 07-01 ~ 08-31 |
| 端午、中秋、過年 | 靜態節日表（按參考年；需逐年擴充） |

**注入：** `new DateParser(?DateTimeImmutable $reference)` 利於測試。

---

## 7. AreaParser 階層（已實作）

| 節點 | area | destination |
|------|------|-------------|
| 歐洲 | 歐洲 | — |
| 東歐/西歐/北歐/南歐 | 歐洲 | 子區 |
| 日本 | 日本 | — |
| 關東/關西/北海道 | 日本 | 子區 |
| 東京/大阪 | 日本 | 城市（東京 parent 關東） |
| 韓國、東南亞 | 同左 | — |

**匹配：** 最長片語優先（`MATCH_ORDER`）。

---

## 8. KeywordNormalizer（已實作）

| 類型 | 範例 |
|------|------|
| 目的地標籤 | 東京 → 關東、富士山、迪士尼 |
| 別名→canonical | 京都 → 大阪（關西） |
| 旅遊風格 | 親子↔小孩/家庭；長輩↔慢活/輕鬆 |

---

## 9. Rule-Based vs Gemini

| Level | 內容 | Phase |
|-------|------|-------|
| L1 | 本目錄 parsers + 既有 `TourQueryIntentDetector` | 2-A / 現行 |
| L2 | 同義詞 YAML / 租戶覆寫 | 2-B |
| L3 | `GeminiIntentParser` JSON only | 2-C |
| L4 | RAG | 未來 |

---

## 10. 未來接線方式（production）

### 10.1 `TourPromptContextService`（規劃）

```php
// 僅示意 — 本階段未實作
$condition = (new HybridSearchConditionBuilder())->buildFromMessage($userText);
$apiResult = $searchClient->search(
    $sno,
    $condition->getKeyword() ?? '',
    1,
    $apiPageSize,
    null,
    $condition  // 未來擴充 client 簽名
);
```

### 10.2 `search_url` 一致性（規劃）

`SearchUrlBuilder::fromCondition(SearchCondition $c, string $sno)` 與 `ApiQueryMapper` 共用同一 `keyword` / `dateFrom` / `dateTo`。

### 10.3 Feature flag（規劃）

```env
HYBRID_SEARCH_ENABLED=0
HYBRID_SEARCH_GEMINI_INTENT=0
```

---

## 11. 測試執行

```powershell
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\hybrid_search\test_search_condition_dto.php
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\hybrid_search\test_date_parser.php
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\hybrid_search\test_area_parser.php
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\hybrid_search\test_budget_parser.php
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\hybrid_search\test_keyword_normalizer.php
```

---

## 12. 修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| v1 | 2026-05-25 | Phase 2-A core layer 初版 |

---

*本階段僅新增 `core/search/*` 與 `tests/hybrid_search/*`，未修改 production flow。*
