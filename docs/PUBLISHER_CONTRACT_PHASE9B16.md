# Phase 9-B-16: Publisher Contract

## 1. 目標

建立 **PublisherContract**，作為 `SourceSearchResultContract` 轉換為各通道輸出格式前的**中介標準**。

本階段**只定義契約與驗證**，不實作 LINE / Gemini / Web Chat / Telegram Publisher，不發送訊息、不呼叫 API。

```
SourceSearchResultContract
        ↓
PublisherContract
        ↓
(未來) LINE / Gemini / Web Chat / Telegram Publisher
```

## 2. Contract Schema

| 欄位 | 必填 | 說明 |
|------|------|------|
| `schema_version` | 否（預設 1） | 契約版本 |
| `tenant_instance` | **是** | 租戶來源實例 |
| `source_platform` | **是** | 來源平台 ID |
| `product_category` | **是** | 商品類別 |
| `title` | **是** | 發佈標題 |
| `summary` | 否 | 摘要 |
| `primary_url` | **是** | 主要連結 |
| `secondary_urls` | 否（預設 `[]`） | 次要連結列表 |
| `actions` | 否（預設 `[]`） | 可點擊動作（如 `open_url`） |
| `metadata` | 否（預設 `{}`） | 擴充資料 |

### 範例

```json
{
  "tenant_instance": "rechoice_agenttour",
  "source_platform": "agenttour",
  "product_category": "group_tour",
  "title": "東京五日遊",
  "summary": "東京精選團體行程",
  "primary_url": "https://xxxxx",
  "secondary_urls": [],
  "actions": [
    {
      "type": "open_url",
      "label": "查看行程",
      "url": "https://xxxxx"
    }
  ],
  "metadata": {}
}
```

### 禁止內容

- LINE Flex Message JSON
- Gemini Prompt Text
- HTML Template
- Telegram Bot Payload

## 3. Validator Rules

| 規則 | 錯誤碼 |
|------|--------|
| `tenant_instance` 非空 | `PUBLISHER_CONTRACT_TENANT_INSTANCE_REQUIRED` |
| `source_platform` 非空 | `PUBLISHER_CONTRACT_SOURCE_PLATFORM_REQUIRED` |
| `product_category` 非空且合法 | `PUBLISHER_CONTRACT_PRODUCT_CATEGORY_REQUIRED` |
| `title` 非空 | `PUBLISHER_CONTRACT_TITLE_REQUIRED` |
| `primary_url` 非空且為 http(s) URL | `PUBLISHER_CONTRACT_PRIMARY_URL_REQUIRED` |
| `actions[]` 每筆須含 `url` | `PUBLISHER_CONTRACT_ACTION_URL_REQUIRED` |
| `metadata` 須為 object | `PUBLISHER_CONTRACT_INVALID_METADATA` |

## 4. 與 SourceSearchResultContract 關係

| SourceSearchResult | Publisher |
|--------------------|-----------|
| `search_url` | `primary_url` |
| `detail_url` | 可放入 `secondary_urls` 或 `actions` |
| `summary` | `summary` |
| `metadata` | `metadata`（延用） |

未來 **Result → Publisher** 轉換器（Phase 9-B-17+）負責映射，本階段不實作轉換邏輯。

## 5. 與未來 LINE Publisher 關係

```
PublisherContract → LINE Publisher → LINE text / Flex bubble
```

Flex 版型、hero、footer 由 LINE Publisher 組裝，不寫入 PublisherContract。

## 6. 與未來 Gemini Publisher 關係

```
PublisherContract → Gemini Publisher → structured context / prompt blocks
```

Gemini 可讀取 `title`、`summary`、`actions`、`metadata` 組 context；Prompt 字串在 Gemini Publisher 層產生。

## 7. 與未來 Web Chat Publisher 關係

```
PublisherContract → Web Chat Publisher → UI card (JSON for frontend)
```

Web UI 卡片欄位由 Web Publisher 對應，PublisherContract 保持通道中立。

## 8. 與未來 Telegram Publisher 關係

```
PublisherContract → Telegram Publisher → inline keyboard / message text
```

Telegram `reply_markup` 在 Telegram Publisher 產生，不進入本 Contract。

## 9. Metadata Strategy

與 SourceSearchResult 相同：**類別專屬欄位只放 `metadata`**。

- 團體旅遊：`price_from`、`currency`
- 飯店：`hotel_star`
- 機票：`airline`

Publisher 依 `product_category` 決定如何呈現 metadata。

## 10. Phase 9-B-17 規劃

1. `SourceSearchResultToPublisherMapper` — 結果轉中介格式
2. LINE Publisher 骨架（Flex / 文字）
3. Web Chat Publisher 骨架
4. 批次發佈與租戶級 Publisher 設定（config driven）

## 檔案清單

| 檔案 | 說明 |
|------|------|
| `core/product_source/PublisherContract.php` | 常數與 normalize |
| `core/product_source/PublisherContractValidator.php` | 驗證 |
| `core/product_source/PublisherContractException.php` | 錯誤碼 |
| `tests/product_sources/test_publisher_contract.php` | 測試 |

## 測試

```powershell
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\product_sources\test_publisher_contract.php
```
