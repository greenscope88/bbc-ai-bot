# API Gateway MVP — Gemini Retry and LINE Fallback

## 1. Purpose

Reduce customer-visible failures when Google Gemini returns **503**, **timeouts**, or **transient CURL errors**. When Gemini ultimately fails after retries, LINE replies use a **structured tour context fallback** (no raw `AI錯誤：Gemini HTTP/CURL error - status 503`).

## 2. Retry policy

| Item | Value |
|------|--------|
| Max HTTP attempts | **3** (initial + 2 retries) |
| Backoff | **1 s** before attempt 2, **2 s** before attempt 3 |
| Retry conditions | **HTTP 503**, **any non-zero `curl_errno`** (includes connect/read timeout) |
| No retry | Other HTTP status (e.g. 400, 401, 500), empty Gemini body |

Implementation: `core/gemini_service.php` — `callGeminiUrl()` wraps `gemini_http_request_once()`; `gemini_result_is_retryable()` classifies failures.

## 3. LINE reply when Gemini still fails

In `core/saas_router.php` after `callGemini($prompt)`:

1. If Gemini **OK** → use Gemini text (unchanged).
2. Else if **`$tourContext` non-empty** → `TourFallbackFormatter::formatFromTourContext($tourContext)`（行程名稱、出團日期、價格、**出發地**、搜尋連結）。
3. Else → short friendly message (no technical error string).

Weather queries (`weather_query`) use Gemini with retries; on failure the user sees a **generic weather message** (no `AI錯誤：` prefix).

## 4. Fallback formatter

| File | Role |
|------|------|
| `core/tour_fallback_formatter.php` | Parses `GeminiTourContextBuilder` Chinese block; strips the “請 Gemini …” instruction tail; emits LINE-safe text + search URL. Accepts **出發地：**、item lines **直售價：** / **價格：** / **售價：**；missing price renders **直售價：未提供**；missing departure renders **出發地：未提供**。Search URL is taken from **https://bonusmee.com…** in context. |

## 4.1 Tour prompt context (related)

`TourPromptContextService` requests **pageSize = 30** from `TourSearchApiClient`, then `GeminiTourContextBuilder` merges rows by **title + departureStr + couponNo** and emits **at most 5** merged listings (each with **出發地：**).

## 5. Logging

`router_complete` sets `ai_ok` to **true** when either Gemini succeeded or **tour fallback** text was used (customer received product-oriented content).

## 6. Tests

```text
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\test_gemini_retry_and_fallback.php
```

Covers: retryable detection, 503→success mock, triple-503 mock, 400 no-retry, formatter output without `AI錯誤` / `503`.

## 7. Operational notes

- Does **not** change `.env` or API keys.
- Live Gemini still subject to quota / regional outages; fallback only helps when **tour context** was built (tour intent + gate + Host B search path).
