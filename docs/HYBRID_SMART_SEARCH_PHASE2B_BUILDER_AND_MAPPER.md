# Hybrid Smart Search — Phase 2-B Builder & Mapper

**主機：** 103.1.222.14（主機 A）  
**狀態：** Phase 2-B 已實作（**未接線 production**）  
**前置：** Phase 2-A (`core/search/*` parsers)、Phase 1 架構文件

---

## 1. Builder flow

```text
LINE 問句 (string)
    ↓
HybridSearchConditionBuilder::parse($message, $context)
    ↓  DateParser
    ↓  BudgetParser
    ↓  AreaParser
    ↓  KeywordNormalizer
    ↓  departure regex (高雄出發…)
    ↓  (optional) merge_legacy_keyword → TourQueryIntentDetector
    ↓
SearchCondition (DTO)
    ↓
SearchConditionCanonicalizer::canonicalize()   ← 單一真相
    ├→ ApiQueryMapper::toClientParams()        → Host B client query
    └→ SearchUrlBuilder::build()               → cloud_store_tourdate.php URL
```

---

## 2. Parser integration

| 順序 | Parser | 產出欄位 |
|------|--------|----------|
| 1 | `DateParser` | `date_from`, `date_to`, `date_precision`, `date_label` |
| 2 | `BudgetParser` | `budget_min`, `budget_max`（**僅 meta**，暫不送 Host B） |
| 3 | `AreaParser` | `area`, `destination`, `keyword` |
| 4 | `KeywordNormalizer` | `travel_style`, `special_tags`, 修正 `keyword` |
| 5 | Builder 內建 | `departure_city` |

---

## 3. SearchCondition lifecycle

1. `SearchCondition::empty($message)` — `free_text` 保留原文  
2. 各 parser `->parse($message, $condition)` 累加 patch  
3. `SearchConditionCanonicalizer` — 產 API/URL 共用參數（**不再解析自然語言**）  
4. 未來 production：`TourPromptContextService` 僅消費步驟 1–3 的結果

---

## 4. ApiQueryMapper strategy

| 規則 | 說明 |
|------|------|
| Allowlist | `keyword`, `destination`, `country`, `city`, `dateFrom`, `dateTo`, `page`, `pageSize` |
| 無 `area` 欄位 | 大區（歐洲）→ `keyword=歐洲`（`area_fallback=true`） |
| 國家級 area | `country=日本` 等（`AREA_AS_COUNTRY` 表） |
| 城市 | `destination` + `city` 同步 |
| Budget | **不送出** Host B query（`metaOnly()` 供 log/測試） |
| 禁止 | SQL、DB、非 allowlist 鍵 |

---

## 5. search_url strategy

| 項目 | 說明 |
|------|------|
| Base | `https://bonusmee.com/view/cloud/cloud_store_tourdate.php` |
| 固定參數 | `openExternalBrowser`, `UnCarousel`, `fromDMDetailFlag`, `clearParam`, `mode`, `sno` |
| 搜尋參數 | 與 API **相同鍵名**：`keyword`, `dateFrom`, `dateTo`, `destination` |
| 出發地 | `departureCity`（若 `departure_city` 有值；需前台支援） |
| 短網址 | `SearchUrlBuilder(false)` 預設長網址；測試不碰 DB |

---

## 6. API / search_url consistency rules

| ID | 規則 |
|----|------|
| C1 | `canonicalize()` 為唯一 keyword/日期來源 |
| C2 | `urlParams.keyword === apiParams.keyword` |
| C3 | `urlParams.dateFrom/dateTo === apiParams.dateFrom/dateTo` |
| C4 | 禁止在 `TourSearchService::buildSearchUrl($sno,$keyword)` 與 hybrid 並用不同解析 |
| C5 | keyword 長度 ≤ 48 字元 |

---

## 7. Area fallback strategy

| 客人說 | area | destination | API keyword |
|--------|------|-------------|-------------|
| 歐洲 | 歐洲 | — | `歐洲` |
| 東歐 | 歐洲 | 東歐 | `東歐` |
| 東京 | 日本 | 東京 | `東京` |

**不展開**「歐洲 → 法國德國義大利…」字串（CPU / 長度 / 安全）。

---

## 8. CPU safety strategy

- 僅 Rule-Based parser（無 Gemini）  
- 靜態區域表 + regex  
- keyword 截斷 48 字  
- 單次 `parse()` 線性掃描  

---

## 9. 未來 production 接線點

| 檔案 | 變更（規劃） |
|------|--------------|
| `TourPromptContextService` | `HybridSearchConditionBuilder` → `ApiQueryMapper` → `TourSearchApiClient` |
| `TourSearchService::buildSearchUrl` | 委派 `SearchUrlBuilder` 或共用 canonicalizer |
| Feature flag | `HYBRID_SEARCH_ENABLED=0` 預設關閉 |

**本階段未修改上述檔案。**

---

## 10. 檔案清單

| 檔案 | 職責 |
|------|------|
| `core/search/HybridSearchConditionBuilder.php` | 編排 parsers |
| `core/search/SearchConditionCanonicalizer.php` | 單一真相 |
| `core/search/ApiQueryMapper.php` | → Host B query |
| `core/search/SearchUrlBuilder.php` | → search_url |

---

*版本 v1 — 2026-05-25*
