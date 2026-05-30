# Phase 9-B-15: Source Search Result Contract

## 1. 目標

建立 **SourceSearchResultContract**，作為 BATS 跨平台商品搜尋結果的**統一輸出格式**。

本階段**只定義契約與驗證**，不實作商品搜尋、API、Publisher、Gemini、LINE OA、Host B。

未來消費者包含：LINE OA Publisher、Gemini Publisher、Web Chat、Telegram、AI Recommendation、API Gateway。

## 2. Contract Schema

| 欄位 | 必填 | 說明 |
|------|------|------|
| `schema_version` | 否（預設 1） | 契約版本 |
| `source_platform` | **是** | 來源平台 ID（如 `agenttour`） |
| `tenant_instance` | **是** | 租戶來源實例鍵 |
| `product_category` | **是** | 商品類別（`ProductCategoryContract`） |
| `title` | **是** | 顯示標題 |
| `summary` | 否 | 摘要 |
| `search_url` | **是** | 搜尋／列表入口 URL |
| `detail_url` | 否 | 商品詳情 URL |
| `metadata` | 否（預設 `{}`） | 類別專屬擴充欄位 |

### 範例（最小）

```json
{
  "source_platform": "agenttour",
  "tenant_instance": "rechoice_agenttour",
  "product_category": "group_tour",
  "title": "東京五日遊",
  "summary": null,
  "search_url": "https://xxxxx",
  "detail_url": null,
  "metadata": {}
}
```

### 禁止出現於本 Contract

- LINE Message / Flex Message
- Gemini Prompt
- HTML 片段
- Telegram Message 結構

本 Contract **只描述商品搜尋結果**；通道格式由未來 Publisher Contract 負責。

## 3. Validator Rules

`SourceSearchResultValidator` 至少驗證：

| 規則 | 錯誤碼 |
|------|--------|
| `source_platform` 非空 | `SOURCE_RESULT_SOURCE_PLATFORM_REQUIRED` |
| `tenant_instance` 非空 | `SOURCE_RESULT_TENANT_INSTANCE_REQUIRED` |
| `product_category` 非空且合法 | `SOURCE_RESULT_PRODUCT_CATEGORY_REQUIRED` / `SOURCE_RESULT_INVALID_PRODUCT_CATEGORY` |
| `title` 非空 | `SOURCE_RESULT_TITLE_REQUIRED` |
| `search_url` 非空且為 http(s) URL | `SOURCE_RESULT_URL_REQUIRED` / `SOURCE_RESULT_INVALID_URL` |
| `metadata` 若存在須為 object | `SOURCE_RESULT_INVALID_METADATA` |
| `detail_url` 若存在須為合法 URL | `SOURCE_RESULT_INVALID_URL` |

## 4. Metadata Strategy

**不在 Contract 頂層固定** `price_from`、`currency`、`hotel_star`、`airline` 等欄位。

類別專屬資料一律放入 `metadata`：

```json
{ "metadata": { "price_from": 28888, "currency": "TWD" } }
```

```json
{ "metadata": { "hotel_star": 5 } }
```

```json
{ "metadata": { "airline": "CI" } }
```

```json
{ "metadata": { "package_type": "FIT" } }
```

Publisher / AI 可依 `product_category` 解讀 metadata，無需修改核心 Contract。

## 5. 與 Product Category Contract 關係

`product_category` 必須通過 `ProductCategoryContract::isValidProductCategory()`。

支援 `group_tour`、`hotel`、`ticket`、`fit` 等；`private_group` 等別名由 Product Category Contract 正規化。

## 6. 與 SearchCondition 關係

| 階段 | Contract |
|------|----------|
| 輸入 | `SearchConditionContract`（keyword、destination…） |
| 輸出 | `SourceSearchResultContract`（title、search_url、metadata…） |

搜尋**條件**與搜尋**結果**分離，便於同一條件對應多筆跨平台結果。

## 7. 與 MultiSourceSearchUrlBuilder 關係

Phase 9-B-14 產出多筆 `{ platform, tenant_instance, search_url }`。

未來搜尋執行層可將每筆 URL 對應的抓取結果填入：

```
MultiSourceSearchUrlBuilder → (future) Search Executor → SourceSearchResultContract[]
```

9-B-15 先定義結果形狀，不實作抓取。

## 8. 與未來 Publisher 關係

```
SourceSearchResultContract
        ↓
Publisher Contract（通道專用）
        ↓
LINE Publisher / Web Chat / Telegram / …
```

同一筆結果可經不同 Publisher 轉為 Flex、純文字、HTML 等，**不污染**結果 Contract。

## 9. 與未來 Gemini Context 關係

Gemini 可將 `SourceSearchResultContract[]` 序列化為結構化 context（JSON），供推薦或摘要；Prompt 模板屬 Publisher / Gemini 層，不寫入本 Contract。

## 10. 與未來 LINE OA 關係

LINE Flex 由 LINE Publisher 依 `title`、`summary`、`search_url`、`metadata` 組裝；本 Contract 不含 `type: bubble` 等 LINE 專用欄位。

## 11. 與未來 AI Recommendation 關係

推薦引擎可比對多筆結果的 `metadata`（價格、星等、航空公司等）與使用者偏好；欄位擴充僅增 metadata key，無需改 schema 頂層。

## 12. Phase 9-B-16 規劃

1. `SourceSearchResultList` / 批次驗證器
2. 由 Multi-source URL + Mock adapter 產生範例結果陣列
3. Publisher Contract 骨架（LINE / Web）
4. Search Executor 介面（仍不抓真實網頁時可先 stub）

## 檔案清單

| 檔案 | 說明 |
|------|------|
| `core/product_source/SourceSearchResultContract.php` | 常數與 normalize |
| `core/product_source/SourceSearchResultValidator.php` | 驗證邏輯 |
| `core/product_source/SourceSearchResultContractException.php` | 錯誤碼 |
| `tests/product_sources/test_source_search_result_contract.php` | 測試 |

## 測試

```powershell
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\product_sources\test_source_search_result_contract.php
```
