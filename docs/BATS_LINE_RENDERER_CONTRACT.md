# BATS Line Renderer Contract (Phase 9-B-22)

## 1. LineRenderer 責任

**LineRenderer** 將 `ChannelPublishPlan`（`channel=line`）轉為 **LINE Messaging API `messages[]` payload**（Text MVP）。

```
ChannelPublishPlan
        ↓
LineRenderer
        ↓
LineMessagePayload
        ↓
(未來) LineSender → LINE Reply / Push API
```

| 負責 | 不負責 |
|------|--------|
| 驗證 `channel=line` | 搜尋、Mapper、Strategy truncate |
| 呼叫 `LineTextMessageBuilder` | Flex / Template message |
| 產出 `LineMessagePayload` | HTTP、cURL、replyToken |
| 經 `LineMessagePayloadValidator` 驗證 | webhook、`.env`、SQL |

---

## 2. 與 ChannelPublishPlan 邊界

| ChannelPublishPlan | LineRenderer |
|--------------------|--------------|
| 通道中立 items | LINE text 排版 |
| `items[].title/summary/primary_url` | `🚩 {title}` + 摘要 + URL 區塊 |
| `fallback.message` | 額外一則 text |
| 已裁剪 max_items | **不再** truncate / limit |
| 唯讀輸入 | 不修改 Plan |

Renderer **只讀** Plan；Flex bubble、hero、footer 不在 Plan 內，也不在 9-B-22 範圍。

---

## 3. 與未來 LineSender 邊界

| LineMessagePayload | LineSender（未來 9-B-25） |
|--------------------|---------------------------|
| `{ "messages": [ ... ] }` | 加上 `replyToken`、Authorization |
| 純 data contract | `LineService::postJson` / HTTP |
| 可單元測試 | 網路、retry、webhook 整合 |

9-B-22 **不**建立 LineSender、**不**修改 `LineService`、**不**接入 webhook。

---

## 4. Text MVP 範圍

### 已實作

| 元件 | 說明 |
|------|------|
| `ChannelRendererInterface` | `render(ChannelPublishPlan, renderContext): array` |
| `LineRenderer` | Plan → Payload |
| `LineTextMessageBuilder` | 每 item 一則 text |
| `LineMessagePayload` | `fromArray()` / `toArray()` |
| `LineMessagePayloadValidator` | text-only 驗證 |

### Text 格式（每 item）

```
🚩 {title}

{summary}

詳細內容：
{primary_url}
```

### Payload 範例

```json
{
  "messages": [
    {
      "type": "text",
      "text": "🚩 東京五日\n\n精選東京行程\n\n詳細內容：\nhttps://example.test/tokyo"
    }
  ]
}
```

### 禁止內容

- `replyToken` / `accessToken` / `endpoint` / `headers`
- `type: flex` / `template` / `bubble` / `hero` / `body` / `footer`

---

## 5. 為何不做 Flex（9-B-22）

| 原因 | 說明 |
|------|------|
| 分層 | Text MVP 先驗證 Plan → Payload 管線 |
| 複雜度 | Flex 需 altText、size limit、carousel |
| 穩定 | 不碰 webhook / LineService baseline |
| 測試 | Text payload 可 pure PHP 測試，無 I/O |

Flex 將在後續 Phase 以獨立 builder（如 `LineFlexBubbleBuilder`）加入，不污染 Text MVP。

---

## 6. 未來規劃

| Phase | 內容 |
|-------|------|
| **9-B-23** | Gemini Renderer（Plan → context payload） |
| **9-B-24** | Gemini Integration Test |
| **9-B-25** | LineSender（Payload + token → HTTP） |
| **9-B-26** | LINE OA Integration（webhook 接入） |

---

## 7. 檔案清單

| 檔案 | 說明 |
|------|------|
| `core/product_source/renderer/ChannelRendererInterface.php` | 通道 Renderer 介面 |
| `core/product_source/renderer/line/LineRenderer.php` | LINE orchestration |
| `core/product_source/renderer/line/LineTextMessageBuilder.php` | Text messages builder |
| `core/product_source/renderer/line/LineMessagePayload.php` | Payload contract |
| `core/product_source/renderer/line/LineMessagePayloadValidator.php` | Payload 驗證 |
| `tests/product_sources/test_line_renderer.php` | 測試 |

## 測試

```powershell
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\product_sources\test_line_renderer.php
```

---

## 相關文件

- `docs/BATS_CHANNEL_PUBLISH_PLAN_CONTRACT.md` — Plan 層（9-B-20~21）
- `docs/BATS_PUBLISHER_STRATEGY_CONTRACT.md` — Strategy 層（9-B-18~19）
