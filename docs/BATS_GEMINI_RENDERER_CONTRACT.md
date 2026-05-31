# BATS Gemini Renderer Contract (Phase 9-B-23)

## 1. GeminiRenderer 責任

**GeminiRenderer** 將 `ChannelPublishPlan`（`channel=gemini`）轉為 **GeminiContextDocument**，供未來 Gemini Client 組 prompt 與呼叫模型。

```
ChannelPublishPlan
        ↓
GeminiRenderer
        ↓
GeminiContextDocument
        ↓
(未來) Gemini Client → Gemini API → 擬真人客服回覆
```

| 負責 | 不負責 |
|------|--------|
| 整理 search_results context | 產生 Prompt 字串 |
| 合併 voice / guard / fallback 政策 | 呼叫 Gemini API |
| 支援 tenant metadata override | HTTP、cURL |
| 輸出可驗證 document | webhook、LineService |

---

## 2. 與 ChannelPublishPlan 邊界

| ChannelPublishPlan | GeminiRenderer |
|--------------------|----------------|
| 通道中立 items | `search_results[]` context |
| `items[].title/summary/primary_url/metadata` | 結構化搜尋結果 |
| `metadata` | tenant voice / scope override |
| `fallback` | 可映射至 `fallback_policy.overflow_notice` |
| 已裁剪 max_items | **不再** truncate / limit |
| 唯讀輸入 | 不修改 Plan |

Renderer **不**在 Plan 內寫入 prompt、model、API key。

---

## 3. 與未來 Gemini Client 邊界

| GeminiContextDocument | Gemini Client（未來） |
|-----------------------|----------------------|
| 結構化 context | system / user prompt 組裝 |
| guard / fallback 政策 | 模型參數、API endpoint |
| voice_profile | 回覆語氣指令 |
| 純 data contract | HTTP、streaming、retry |

9-B-23 **不**建立 Gemini Client、**不**呼叫 API。

---

## 4. GeminiContextDocument Schema

| 欄位 | 必填 | 說明 |
|------|------|------|
| `schema_version` | 否（預設 1） | Document 版本 |
| `customer_query` | **是** | 使用者問題（來自 renderContext） |
| `search_results` | **是** | 搜尋結果陣列（可為空） |
| `tenant_name` | **是** | 旅行社名稱 |
| `voice_profile` | **是** | 人設、語氣、emoji 政策 |
| `tenant_service_scope` | **是** | 服務範圍 |
| `guard_policy` | **是** | 防幻覺規則 |
| `fallback_policy` | **是** | 轉人工規則 |

### search_results[] 結構

```json
{
  "title": "東京五日遊",
  "summary": "精選東京行程",
  "primary_url": "https://example.test/tokyo",
  "metadata": { "price_from": 28888 }
}
```

### 禁止欄位

- `prompt` / `system_prompt` / `user_prompt`
- `api_key` / `endpoint` / `headers`
- `replyToken` / `http` / `curl`

---

## 5. Voice Profile 設計

### 預設

| 鍵 | 值 |
|----|-----|
| `persona` | `young_female` |
| `tone` | `warm`, `enthusiastic`, `professional`, `friendly` |
| `constraints` | 不輕浮、不誇大、不亂承諾 |

### Tenant Voice Override（未來 2~200 家）

可透過 `renderContext` 或 `plan.metadata` 覆寫：

- `persona`
- `tone`
- `emoji_policy`
- `service_style`

---

## 6. Emoji Policy

允許 Gemini 自然使用：

😊 ✈️ 🌸 📌 💡 🧳

| 規則 | 說明 |
|------|------|
| 不可過量 | 避免每句都加 emoji |
| 不可幼稚化 | 維持專業旅行社形象 |
| 不可裝可愛過頭 | 避免過度撒嬌語氣 |
| 不可影響專業感 | emoji 輔助，非主角 |
| 不可誤導客人 | 不用 emoji 暗示優惠 |
| 不可暗示不存在優惠 | 資料 grounding |
| 不可暗示保證成團 | 避免錯誤承諾 |

---

## 7. Guard Policy

| 鍵 | 預設 | 說明 |
|----|------|------|
| `grounding_required` | `true` | 只能依 search_results 回答 |
| `allow_hallucination` | `false` | 禁止自行補資料 |
| `strict_data_mode` | `true` | 嚴格資料模式 |

---

## 8. Fallback Policy

| 鍵 | 預設 | 說明 |
|----|------|------|
| `mode` | `human_agent` | 轉人工客服 |
| `fallback_message` | 見下 | 無資料時回覆 |

預設訊息：

> 這個問題我先幫您轉由專人客服確認，稍後將有客服人員與您聯絡，謝謝您 😊

---

## 9. 禁止幻覺規則

Gemini 未來**只能**根據 `GeminiContextDocument` 中的實際資料回答。

**不得自行補：**

- 價格、日期、機位、飯店、航班
- 行程內容、報名規則
- 任何不存在於 `search_results` 的資料

**行為規則：**

| 情境 | 動作 |
|------|------|
| 問題在 `tenant_service_scope` 內但無資料 | 使用 `fallback_policy` 轉人工 |
| 問題不在 `tenant_service_scope` 內 | 禮貌拒答 |
| 任何不確定資訊 | 不得幻覺回答 |

---

## 10. 未來規劃

| Phase | 內容 |
|-------|------|
| **9-B-24** | Gemini Response Integration Test |
| **9-B-25** | LineSender |
| **9-B-26** | LINE OA Integration |

---

## 11. 檔案清單

| 檔案 | 說明 |
|------|------|
| `core/product_source/renderer/gemini/GeminiRenderer.php` | Plan → Context |
| `core/product_source/renderer/gemini/GeminiContextDocument.php` | Context contract |
| `core/product_source/renderer/gemini/GeminiContextDocumentValidator.php` | 驗證 |
| `tests/product_sources/test_gemini_renderer.php` | 測試 |

## 測試

```powershell
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\product_sources\test_gemini_renderer.php
```

---

## 相關文件

- `docs/BATS_CHANNEL_PUBLISH_PLAN_CONTRACT.md` — Plan 層
- `docs/BATS_LINE_RENDERER_CONTRACT.md` — LINE Renderer（9-B-22）
- `docs/BATS_PUBLISHER_STRATEGY_CONTRACT.md` — Strategy 層

---

## Phase 9-B-24 實作紀錄

**GeminiResponseContract** 整合測試已建立（見 `docs/BATS_GEMINI_RESPONSE_CONTRACT.md`）。

| 檔案 | 說明 |
|------|------|
| `core/product_source/renderer/gemini/GeminiResponseContract.php` | Response contract |
| `core/product_source/renderer/gemini/GeminiResponseContractValidator.php` | Schema + 商業規則 |
| `tests/product_sources/test_gemini_response_integration.php` | 整合測試 |

### 已驗證流程

```
ChannelPublishPlan
        ↓
GeminiRenderer → GeminiContextDocument
        ↓
(mock) Gemini Response
        ↓
GeminiResponseContract (+ Validator)
```

**下一步（Phase 9-B-25+）：** LineSender / webhook 仍不在本 Phase。
