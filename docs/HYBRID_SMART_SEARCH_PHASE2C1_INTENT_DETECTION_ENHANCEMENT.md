# Hybrid Smart Search — Phase 2-C.1 Intent Detection Enhancement

**主機：** 103.1.222.14（主機 A）  
**問題：** `六月底東京三萬以下` 未進 tour pipeline → Gemini 一般聊天回覆  
**根因：** `TourQueryIntentDetector` 僅認「行程/旅遊/團」等字樣，`scoreTourIntent=0` → `no_tour_search_signal`

---

## 1. Travel intent lexicon

**檔案：** `core/search/TravelIntentLexicon.php`

### 目的地（節錄）

東京、大阪、京都、北海道、沖繩、韓國、首爾、釜山、日本、歐洲、法國、德國、義大利、北歐、東歐、西歐、泰國、新加坡、越南、美國、加拿大…

### 產品/風格詞

自由行、跟團、行程、旅遊、旅行、賞櫻、賞楓、滑雪、蜜月、親子、郵輪、團

### 規則

**只要訊息含 lexicon 目的地 → `is_tour_search=true`**（無需「旅遊/行程」字樣）。

---

## 2. Intent detection strategy

```text
message
  → exclusion check
  → TravelIntentLexicon::analyze()
       destination found? → tour_search (+ reason 細分)
  → bare destination (2–3 字)
  → tour markers + lexicon boost in scoreTourIntent
```

**`TourQueryIntentDetector::detect()` 新增欄位：**

| 欄位 | 說明 |
|------|------|
| `intent` | `tour_search` / `non_tour` |
| `intent_source` | 如 `lexicon_date_budget_destination` |
| `matched_lexicon` | 命中的目的地詞 |
| `matched_date` | 是否含日期語意 |
| `matched_area` | 目的地 |
| `matched_budget` | 是否含預算語意 |

**`IntentRouter`：** `is_tour_query=true` → `intent=tour_query`（既有，未改 prompt）。

---

## 3. 日期 + area + budget 判定規則

| 組合 | reason |
|------|--------|
| 目的地 | `lexicon_destination` |
| 日期 + 目的地 | `lexicon_date_destination` |
| 預算 + 目的地 | `lexicon_budget_destination` |
| 日期 + 預算 + 目的地 | `lexicon_date_budget_destination` |
| 產品詞 + 目的地 | `lexicon_tour_term_destination` |

**keyword 抽取：** 剝除句首 `六月底`、句尾 `三萬以下` 後取核心詞（例：東京）。

---

## 4. Fallback strategy

| 層級 | 行為 |
|------|------|
| Intent 失敗 | 不進 `TourPromptContextService` → 無 tour context |
| Hybrid ON 但 parser 空 keyword | fallback legacy keyword search |
| Hybrid exception | fallback legacy |

**未改 Gemini prompt。**

---

## 5. Dry-run / intent logs

| 檔案 | 內容 |
|------|------|
| `logs/hybrid_search_intent_decision.log` | 每次 `TourQueryIntentDetector::detect()` |
| `logs/hybrid_search_dry_run.log` | Hybrid 路徑 + **intent_source / matched_* ** |

**開關：** `gateway.hybrid_search.intent_log_enabled`（預設 true）

---

## 6. 真人 LINE OA 測試建議

1. 確認 `TourPromptFeatureGate` 對 staging sno 為 ON  
2. 傳送：`六月底東京三萬以下`  
3. 檢查 `logs/hybrid_search_intent_decision.log`：
   - `intent=tour_search`
   - `intent_source=lexicon_date_budget_destination`
   - `keyword=東京`
4. 若 Hybrid flag ON：檢查 `hybrid_search_dry_run.log` 含 `dateFrom/dateTo`  
5. 回覆應含【旅遊產品搜尋結果】或 Scheme C 固定清單，而非「沒有提供找行程服務」

---

## 檔案變更

| 檔案 | 變更 |
|------|------|
| `core/search/TravelIntentLexicon.php` | 新增 |
| `core/search/TourIntentDecisionLogger.php` | 新增 |
| `core/tour_query_intent_detector.php` | lexicon + 日期/預算 keyword 剝除 |
| `core/tour_prompt_context_service.php` | dry-run 含 intent 欄位 |
| `config/config.php` | `intent_log_enabled` |

---

*版本 v1 — 2026-05-26*
