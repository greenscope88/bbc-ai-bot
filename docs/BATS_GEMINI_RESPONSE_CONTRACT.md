# BATS Gemini Response Contract (Phase 9-B-24)

## 1. GeminiResponseContract

**GeminiResponseContract** 表示未來 Gemini Client 回傳的**結構化回覆結果**，供下游（LINE / Web Chat 等）使用。

```
GeminiContextDocument
        ↓
(未來) Gemini Client → Gemini API
        ↓
GeminiResponseContract          ← 本 Phase 定義 + 整合測試
        ↓
(未來) 通道回覆 / 人工轉接
```

| 欄位 | 必填 | 說明 |
|------|------|------|
| `schema_version` | 否（預設 1） | Contract 版本 |
| `reply_text` | **是** | 回覆文字 |
| `reply_type` | **是** | `normal_reply` / `human_agent_fallback` / `out_of_scope` |
| `used_fallback` | **是** | 是否使用 fallback |
| `used_service_scope` | 否 | 匹配的服務範圍（如 `tour`） |
| `voice_profile_used` | **是** | 使用的人設 ID |

### 禁止欄位

- `prompt` / `system_prompt` / `user_prompt`
- `api_key` / `endpoint` / `headers`
- `replyToken` / `http` / `curl`

---

## 2. Voice Profile

回覆須符合 Context 中的 `voice_profile`：

| 預設 | 值 |
|------|-----|
| `persona` | `young_female` |
| `tone` | warm, enthusiastic, professional, friendly |
| `constraints` | 不輕浮、不誇大、不亂承諾 |

`voice_profile_used` 記錄實際使用的人設，供 audit 與 tenant override 追蹤。

---

## 3. Emoji Policy

允許自然使用：

😊 ✈️ 🌸 📌 💡 🧳

| 規則 | Validator 檢查 |
|------|----------------|
| 不可過量 | emoji 數量 ≤ 5 |
| 僅允許白名單 | 不在 `allowed_emojis` 者拒絕 |
| 不可誤導 | 與 guard policy 搭配 |

---

## 4. Guard Policy

來自 `GeminiContextDocument.guard_policy`：

| 鍵 | 預設 | 整合測試驗證 |
|----|------|--------------|
| `grounding_required` | `true` | normal_reply 須有 search_results |
| `allow_hallucination` | `false` | 禁止未 grounding 價格/日期/標記 |
| `strict_data_mode` | `true` | 嚴格資料模式 |

---

## 5. Fallback Policy

| reply_type | 條件 | 行為 |
|------------|------|------|
| `human_agent_fallback` | 在服務範圍內但無資料 | `reply_text` = `fallback_policy.fallback_message` |
| `used_fallback` | 同上 | 必須為 `true` |

預設訊息：

> 這個問題我先幫您轉由專人客服確認，稍後將有客服人員與您聯絡，謝謝您 😊

---

## 6. Service Scope

`tenant_service_scope` 定義旅行社服務邊界：

- `tour` / `passport` / `visa` / `ticket` / `hotel` / `future_custom_service`

| 情境 | reply_type |
|------|------------|
| 問題在 scope 內 + 有資料 | `normal_reply` |
| 問題在 scope 內 + 無資料 | `human_agent_fallback` |
| 問題不在 scope 內 | `out_of_scope` |

---

## 7. Anti-Hallucination

Validator `collectContextViolations()` 檢查：

| 禁止 | 說明 |
|------|------|
| 未 grounding 價格 | 如 99999 元不在 metadata |
| 未 grounding 日期 | 如 2026/05/30 不在 search_results |
| 保證成團 / 限時優惠 | 不在 grounded corpus 則拒絕 |
| 機位 / 航班 / 飯店 | 須存在於 search_results |

9-B-24 **不呼叫 Gemini API**；以 mock simulator + validator 驗證規則。

---

## 8. 未來規劃

| Phase | 內容 |
|-------|------|
| **9-B-25** | LineSender |
| **9-B-26** | LINE OA Webhook Integration |

---

## 9. 檔案清單

| 檔案 | 說明 |
|------|------|
| `core/product_source/renderer/gemini/GeminiResponseContract.php` | Response contract |
| `core/product_source/renderer/gemini/GeminiResponseContractValidator.php` | Schema + 商業規則 |
| `tests/product_sources/test_gemini_response_integration.php` | 整合測試 |

## 測試

```powershell
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\product_sources\test_gemini_response_integration.php
```

---

## 相關文件

- `docs/BATS_GEMINI_RENDERER_CONTRACT.md` — Context 層（9-B-23）
- `docs/BATS_CHANNEL_PUBLISH_PLAN_CONTRACT.md` — Plan 層
