# Phase 2-C.21 — LINE 日期 `...` 保留 + 阿拉伯數字預算

## 問題

1. `GeminiTourContextBuilder` 產生的 `06/21…06/26...` 經 `TourFallbackFormatter::normalizeDatesDisplay()` 二次處理時，最後 token 的 `...` 被 `tokenToMmDd()` 剝除，LINE 只顯示 6 個日期。
2. `6月底東京30000以下` 無法解析 `budget_max`，`AmountMax` 未送出，LINE 出現超過三萬的售價。

## 修正

### `core/tour_fallback_formatter.php`

- `normalizeDatesDisplay()` 先偵測尾端 `...` 或 `...更多`，normalize token 後再補回 suffix（僅一次）。
- `tokenToMmDd()` 防禦性剝除 token 尾端 `...`。

### `core/search/BudgetParser.php`

- 新增 `tryParseArabicMaxBudget()`：`(\d{4,7})(以下|以內|內)` → `budget_max`。
- 避免誤判：要求 4–7 位金額、排除 `/` 後綴、排除 `人/日/萬/元` 等。
- 既有「三萬以下」「3萬以下」行為不變。

## 測試

- `tests/test_gemini_tour_context_builder.php` — formatter suffix 案例
- `tests/test_budget_parser.php` — 預算解析案例

## 驗收（staging LINE）

1. `六月底東京三萬以下` — 第一筆日期含 `...`，無 58,800 / 47,800
2. `6月底東京30000以下` — `budget_max=30000`、`AmountMax=30000`，無超價商品
