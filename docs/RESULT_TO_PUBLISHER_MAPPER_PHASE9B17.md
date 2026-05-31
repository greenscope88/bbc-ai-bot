# Phase 9-B-17: Result To Publisher Mapper

## 1. 目標

建立 **ResultToPublisherMapper**，作為 `SourceSearchResultContract` → `PublisherContract` 的**轉換層**（架構層，非功能層）。

本階段只做 **Contract → Contract**，不實作 LINE / Gemini / Telegram / Web Chat Publisher，不發送訊息。

```
SourceSearchResultContract
        ↓
ResultToPublisherMapper
        ↓
PublisherContract
        ↓
(未來) 各通道 Publisher
```

## 2. Mapper Flow

1. 檢查 `search_url` 非空 → 否則 `RESULT_TO_PUBLISHER_URL_REQUIRED`
2. `SourceSearchResultValidator::validate()` 正規化來源文件
3. 依 mapping rules 組裝 Publisher 文件（含預設 `actions`）
4. `PublisherContractValidator::validate()` 輸出

## 3. Mapping Rules

| SourceSearchResult | PublisherContract |
|--------------------|-------------------|
| `source_platform` | `source_platform` |
| `tenant_instance` | `tenant_instance` |
| `product_category` | `product_category` |
| `title` | `title` |
| `summary` | `summary` |
| `search_url` | `primary_url` |
| `detail_url` | `secondary_urls[]`（若與 primary 不同） |
| `metadata` | `metadata`（完整保留） |

### 預設 actions

```json
[
  {
    "type": "open_url",
    "label": "查看內容",
    "url": "<search_url>"
  }
]
```

## 4. SourceSearchResultContract 關係

Mapper **輸入**必須符合（或經 validator 正規化後符合）Source Search Result 契約。類別專屬欄位仍在 `metadata`，Mapper 不展開頂層欄位。

## 5. PublisherContract 關係

Mapper **輸出**經 `PublisherContractValidator` 驗證，確保下游 Publisher 收到一致的中介格式。

## 6. 為何需要 Mapper Layer

| 若無 Mapper | 有 Mapper |
|-------------|-----------|
| 各 Publisher 重複映射邏輯 | 單一轉換點 |
| 修改 URL 欄位需改 N 處 | 只改 Mapper |
| 難以測試 Contract 轉換 | 獨立單元測試 |

## 7. 低重構設計分析

- **Contract Driven**：只操作已驗證的 array 文件
- **依賴注入**：可注入 Source / Publisher Validator（測試友好）
- **無通道分支**：不含 `if (line)` / `if (gemini)`
- **低耗能**：純 PHP array 映射，無 I/O、無 LLM

未來若需自訂 action label，可透過建構選項或 config 擴充 Mapper，無需改 Publisher 核心。

## 8. 未來 LINE Publisher 關係

```
SourceSearchResult → ResultToPublisherMapper → PublisherContract → LINE Publisher → Flex / text
```

## 9. 未來 Gemini Publisher 關係

```
SourceSearchResult → ResultToPublisherMapper → PublisherContract → Gemini Publisher → context JSON
```

## 10. 未來 Telegram Publisher 關係

```
SourceSearchResult → ResultToPublisherMapper → PublisherContract → Telegram Publisher → inline keyboard
```

## 11. 未來 Web Chat Publisher 關係

```
SourceSearchResult → ResultToPublisherMapper → PublisherContract → Web Chat Publisher → UI card
```

## 12. Phase 9-B-18 規劃

1. `PublisherContractToLineMapper` 骨架（Flex / 文字）
2. 批次 `mapMany()` 支援多筆搜尋結果
3. 租戶級 action label 設定（config driven）
4. Multi-source 結果列表 → Publisher 列表整合測試

## 禁止產出

ResultToPublisherMapper **不得**產生：

- LINE Message / Flex Message
- Gemini Prompt
- Telegram Message
- HTML

## 檔案清單

| 檔案 | 說明 |
|------|------|
| `core/product_source/ResultToPublisherMapper.php` | 轉換邏輯 |
| `core/product_source/ResultToPublisherMapperException.php` | 錯誤碼 |
| `tests/product_sources/test_result_to_publisher_mapper.php` | 測試 |

## 測試

```powershell
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\product_sources\test_result_to_publisher_mapper.php
```
