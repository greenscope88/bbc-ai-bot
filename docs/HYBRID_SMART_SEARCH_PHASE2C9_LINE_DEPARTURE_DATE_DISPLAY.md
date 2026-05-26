# Hybrid Smart Search — Phase 2-C.9 LINE 出團日期顯示優化

**版本：** 2026-05-26  
**範圍：** Host A 顯示層（`GeminiTourContextBuilder` / `TourFallbackFormatter` / `AiPromptBuilder`）

## 變更摘要

| 項目 | 前 | 後 |
|------|----|----|
| 最多顯示日期數 | 4 | **6** |
| 超過上限 suffix | `...更多` | **`...`** |
| 過期日期 | 會顯示 | **不顯示**（`tourDate` 含年份且 &lt; 今日 Asia/Taipei） |
| 全過期 | 可能顯示過期 MM/DD | **`最近出團：未提供`** |

## 實作位置

- `core/gemini_tour_context_builder.php` — 合併前以 ISO 日期過濾、排序後轉 MM/DD、`formatDepartureDatesDisplay()`
- `core/ai_prompt_builder.php` — Gemini / 固定清單規則同步
- `tests/test_gemini_tour_context_builder.php` — Phase 2-C.9 案例

**未修改：** Host B、SQL、搜尋 query。

## 測試

```bash
php tests/test_gemini_tour_context_builder.php
```

Phase 2-C.9 新增案例應全數通過；若 `links:` 相關失敗多為 short URL / crypto 環境，與本階段日期邏輯無關。
