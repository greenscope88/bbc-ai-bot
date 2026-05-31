# BATS Channel Publish Plan Contract (Phase 9-B-20)

## 1. 責任邊界

**ChannelPublishPlan** 是 Publisher Strategy 輸出與各通道 Renderer 輸入之間的**統一中介格式**。

```
PublisherContract[]
        ↓
PublisherStrategy (e.g. LinePublisherStrategy)
        ↓
ChannelPublishPlan          ← 本 Phase
        ↓
(未來) LineRenderer / GeminiRenderer / WebChatRenderer / …
```

| 負責 | 不負責 |
|------|--------|
| 通道中立的可渲染商品列表 | LINE Flex JSON |
| strategy 上下文（channel、strategy_name） | Gemini Prompt |
| fallback / metadata 擴充 | Telegram Markdown payload |
| payload_schema_version | HTTP 發送、webhook |

---

## 2. 與 PublisherContract 差異

| PublisherContract | ChannelPublishPlan |
|-------------------|-------------------|
| 單筆可發佈商品（搜尋結果層） | 單次發佈任務（策略套用後） |
| 含 tenant_instance、source_platform | 含 channel、strategy_name |
| 策略套用**前** | 策略套用**後**（裁剪/限制後 items） |

---

## 3. 與 PublisherStrategyContract 差異

| PublisherStrategyContract | ChannelPublishPlan |
|---------------------------|-------------------|
| **政策**（max_items、link_policy…） | **計畫**（實際 items 列表） |
| Config 驅動 | Strategy 執行結果 |
| 不含具體商品 title/url 列表 | 含 items[].title / primary_url |

---

## 4. 與未來 Renderer 差異

| ChannelPublishPlan | Renderer |
|--------------------|----------|
| 通道中立 schema | 通道專用 payload |
| `items[].primary_url` | LINE uri / Gemini text block / Telegram link |
| 無 `type: bubble` | Flex bubble JSON |
| 無 `prompt` | Gemini system/user prompt |

Renderer 只**讀取** ChannelPublishPlan，不反向修改 Contract。

---

## 5. Schema

| 欄位 | 必填 | 說明 |
|------|------|------|
| `channel` | **是** | `line` / `gemini` / `web_chat` / `telegram` / `app` / `wechat` |
| `strategy_name` | **是** | 使用的策略 ID |
| `payload_schema_version` | **是** | Plan schema 版本（預設 1） |
| `items` | **是** | 商品展示單元陣列（可為空） |
| `fallback` | 否 | 空結果/溢出 fallback |
| `metadata` | 否 | Plan 級擴充 |

### items[] 結構

```json
{
  "title": "東京五日遊",
  "summary": "精選東京行程",
  "primary_url": "https://example.test/tokyo",
  "secondary_urls": [],
  "actions": [],
  "metadata": {}
}
```

### 禁止欄位

- `type: bubble`
- `hero` / `body` / `footer`
- `template`
- `markdown`
- `prompt`

---

## 6. Validator

`ChannelPublishPlanValidator`：

| 規則 | 錯誤訊息 |
|------|----------|
| `channel` 非空且合法 | `channel is required` / `invalid channel` |
| `strategy_name` 非空 | `strategy_name is required` |
| `items` 為 array | `items must be an array` |
| 每 item 含 `title` | `items[n].title is required` |
| 每 item 含 `primary_url`（http(s)） | `items[n].primary_url is required` |
| `metadata` / `fallback` 型別 | 對應錯誤 |

`collectViolations()` 回傳 array，不 echo。

---

## 7. 未來擴充通道

| 通道 | Plan 用途 | Renderer（未來） |
|------|-----------|------------------|
| LINE | 短 items + fallback | LineRenderer → text / Flex |
| Gemini | 完整 context items | GeminiRenderer → context JSON |
| Web Chat | card_list layout metadata | WebChatRenderer → UI card |
| Telegram | markdown-friendly items | TelegramRenderer → message |
| APP | structured JSON items | AppRenderer → native API |
| WeChat | 預留 channel | WeChatRenderer（未來） |

新增通道：擴充 `ChannelPublishPlan::CHANNELS` + config + Strategy + Renderer，**不需改** items 核心 shape。

---

## 8. 檔案清單

| 檔案 | 說明 |
|------|------|
| `core/product_source/channel_publish_plan/ChannelPublishPlan.php` | `fromArray()` / `toArray()` |
| `core/product_source/channel_publish_plan/ChannelPublishPlanValidator.php` | 驗證 |
| `tests/product_sources/test_channel_publish_plan.php` | 測試 |

## 測試

```powershell
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\product_sources\test_channel_publish_plan.php
```

---

## 相關文件

- `docs/BATS_PUBLISHER_STRATEGY_CONTRACT.md` — Strategy 層（Phase 9-B-18~19）
- `docs/PUBLISHER_CONTRACT_PHASE9B16.md` — Publisher 中介內容

---

## Phase 9-B-21 實作紀錄

**PublisherStrategy → ChannelPublishPlan** 整合測試已建立。

| 檔案 | 說明 |
|------|------|
| `tests/product_sources/test_publisher_strategy_to_channel_publish_plan.php` | 整合測試 |

### 已驗證流程

```
PublisherContract[] (mock)
        ↓
PublisherStrategyResolver::resolve('line')
        ↓
LinePublisherStrategy::applyStrategy()
        ↓
ChannelPublishPlanValidator::validate()
        ↓
ChannelPublishPlan
```

### 測試涵蓋

| 項目 | 驗證 |
|------|------|
| Plan 建立 | `ChannelPublishPlan` 成功 |
| channel / strategy_name | `line` / `line_oa_default_v1` |
| title 保留 | 未超限標題原樣保留 |
| summary truncate | travel_b tenant `max_summary_length=100` |
| max_items | default 10 筆；travel_b 10→5 |
| tenant override | travel_b `max_items=5`、title 36 |
| 禁止內容 | 無 Flex / prompt / markdown 欄位 |

### 測試指令

```powershell
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\product_sources\test_publisher_strategy_to_channel_publish_plan.php
```

**下一步（Phase 9-B-22+）：** LineRenderer skeleton（讀取 ChannelPublishPlan，仍不接入 webhook）

---

## Phase 9-B-22 實作紀錄

**LineRenderer Text MVP** 已建立（見 `docs/BATS_LINE_RENDERER_CONTRACT.md`）。

| 檔案 | 說明 |
|------|------|
| `core/product_source/renderer/ChannelRendererInterface.php` | Renderer 介面 |
| `core/product_source/renderer/line/LineRenderer.php` | Plan → Payload |
| `core/product_source/renderer/line/LineTextMessageBuilder.php` | Text messages |
| `core/product_source/renderer/line/LineMessagePayload.php` | Payload contract |
| `core/product_source/renderer/line/LineMessagePayloadValidator.php` | text-only 驗證 |
| `tests/product_sources/test_line_renderer.php` | 測試 |

### 已驗證流程

```
ChannelPublishPlan
        ↓
LineRenderer
        ↓
LineMessagePayload (messages[] text only)
```

**下一步（Phase 9-B-23+）：** Gemini Renderer / LineSender / webhook 仍不在本 Phase。
