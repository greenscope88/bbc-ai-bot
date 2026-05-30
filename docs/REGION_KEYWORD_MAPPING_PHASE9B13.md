# Phase 9-B-13: Keyword → Platform-Scoped Mapping

## 1. 目標

建立 **Keyword Mapping Core**，依 `platform_code` 決定如何處理搜尋關鍵字：

| 平台類型 | 行為 |
|---------|------|
| **agenttour** (`agenttour.com.tw`) | `keyword` → AgentTour `RegionCode`（如 `J`、`C`、`K`）+ `destination` |
| **其他平台**（grp、bbctravel、tourcenter、官方網站、Trip.com 等） | **不做** `region_code` 對照，僅保留 `keyword` 供平台 keyword/search URL 參數使用 |

**重要：** AgentTour 的 RegionCode 對照表**僅適用** `platform_code=agenttour`，不可當成全平台共用 Registry。

第一版僅實作 Registry + Mapper，不實作 Gemini Intent、Hybrid Search、Publisher、LINE OA。

## 2. Mapping Registry（platform_code scope）

`RegionMappingRegistry` 採 **platform-scoped、config driven** 設計：

```
byPlatformKeyword[platform_code][keyword] → { destination, region_code }
```

- `RegionMappingRegistry::createDefault()` 僅註冊 **agenttour** scope
- `register($platformCode, $keyword, $mapping)` 可為**其他平台**新增獨立對照表（若該平台日後有自己的地區碼）
- **禁止**將 agenttour 的 `J/C/K` 表套用到 grp / bbctravel 等未註冊 scope 的平台

### agenttour 預設對照（agenttour.com.tw RegionCode）

| 關鍵字 | RegionCode |
|--------|------------|
| 東京、日本、大阪、北海道 | J |
| 泰國、曼谷、清邁、普吉島 | C |
| 韓國、首爾、釜山 | K |

### 其他平台（第一版）

- **無** registry scope
- `RegionKeywordMapper::map('grp', '東京')` → `{ "keyword": "東京" }`（無 `region_code`）
- 搜尋 URL 由 `SearchUrlBuilder` 將 `keyword` 直接放入各平台 search 參數

## 3. Mapping Flow

```
platform_code + keyword
        ↓
RegionKeywordMapper::map()
        ↓
hasPlatformScope(platform)?
   ├─ NO  → { keyword }                    （grp / bbctravel / …）
   └─ YES → RegionMappingRegistry::resolve(platform, keyword)
            → { destination, region_code, keyword }   （agenttour）
```

- **agenttour** 未知關鍵字 → `RegionKeywordMapperException` / `REGION_KEYWORD_NOT_FOUND`
- **非 agenttour** 任意關鍵字 → 不查表、不拋錯，保留 keyword

## 4. 與 SearchCondition 關係

`SearchConditionContract`（Phase 9-B-12）含 `platform`、`keyword`、可選 `destination`、`region_code`。

```php
$mapper->applyToSearchCondition(
    ['keyword' => '東京'],
    'agenttour',
    '東京'
);
// → platform, keyword, destination, region_code

$mapper->applyToSearchCondition(
    ['keyword' => '東京'],
    'grp',
    '東京'
);
// → platform, keyword only（不寫入 region_code）
```

驗證仍由 `SearchConditionValidator` 負責。

## 5. 與 SearchUrlBuilder 關係

`SearchUrlBuilder` 預留 `SearchKeywordMapperInterface::resolveRegionCode($keyword, $platformId)`。

- **agenttour**：可解析出 `RegionCode` 供 `RegionCode=` query 使用
- **其他平台**：回傳 `null`，Builder 應使用 `keyword` 組 search URL

9-B-13 **不修改** `SearchUrlBuilder` 本體；9-B-14 再注入 mapper。

## 6. 與未來 Gemini Intent Parser 關係

```
使用者輸入
  → Gemini Intent Parser（抽取 keyword、推測 platform）
  → RegionKeywordMapper（依 platform_code 分支）
  → SearchCondition
```

Intent Parser 需提供 `platform`（或可由租戶 instance 推得），Mapper 才能選擇正確 scope。

## 7. 與未來 Multi Source Search 關係

```
SearchCondition
  platform=agenttour  → region_code + SearchUrlBuilder → agenttour URL
  platform=grp        → keyword only     → grp keyword search URL
  platform=bbctravel  → keyword only     → bbctravel search URL
```

**不同平台地區定義可能不同。** 若某平台未來需要自有地區碼：

1. 在 Registry 新增該 `platform_code` scope
2. 註冊**該平台專用**對照表
3. **不可**共用 agenttour 的 `J/C/K` mapping

## 8. Phase 9-B-14 規劃

1. `SearchUrlBuilder` 注入 `RegionKeywordMapper`，依 `platform` 決定用 `region_code` 或 `keyword`
2. `applyToSearchCondition` 整合進 BATS / 整合測試
3. 外部 JSON/YAML 載入各 `platform_code` scope（仍保持分表）
4. 平台專屬別名（例如 tourcenter 自有 code）獨立註冊

## 檔案清單

| 檔案 | 說明 |
|------|------|
| `core/product_source/RegionMappingRegistry.php` | platform-scoped 對照表 |
| `core/product_source/RegionKeywordMapper.php` | 依 platform 分支映射 |
| `core/product_source/RegionKeywordMapperException.php` | 錯誤碼 |
| `tests/product_sources/test_region_keyword_mapper.php` | 單元測試 |

## 測試

```powershell
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\product_sources\test_region_keyword_mapper.php
```

### 測試案例

| Case | platform | keyword | 預期 |
|------|----------|---------|------|
| 1 | agenttour | 東京 | `region_code=J` |
| 2 | agenttour | 泰國 | `region_code=C` |
| 3 | grp | 東京 | 僅 `keyword`，無 `region_code` |
| 4 | bbctravel | 東京 | 僅 `keyword`，無 `region_code` |
| 5 | agenttour | 火星旅遊 | `REGION_KEYWORD_NOT_FOUND` |
