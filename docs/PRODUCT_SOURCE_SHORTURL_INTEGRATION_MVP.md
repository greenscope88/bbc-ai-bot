# Product Source Short URL Integration MVP

**代號：** `PRODUCT_SOURCE_SHORTURL_INTEGRATION_MVP`  
**版本：** Phase 9-B-3  
**狀態：** MVP — Mock provider only in tests; **不寫入** `bs_ShortUrl`

**前置 Commits：** `db77c53`（Registry + Contract）、`6bc1b8a`（MultiSourceSearchUrlBuilder）

---

## 1. Purpose

驗證商品源 **Long URL → Short URL** 薄層：

```
MultiSourceSearchUrlBuilder → ProductSourceUrlResult (long)
    ↓
ProductSourceUrlPublisher (+ catalog short_url_*)
    ↓
ShortUrlProviderInterface
    ↓
ProductSourcePublishedUrl (long + short)
```

**本階段不修改：** `ShortUrlService.php` 本體、`SearchUrlBuilder`、`saas_router`、Hybrid、LINE。

---

## 2. Current Architecture

```
core/product_source/
  MultiSourceSearchUrlBuilder.php      ← Phase 9-B-2
  ProductSourceUrlResult.php
  ProductSourceUrlPublisher.php        ← Phase 9-B-3（新）
  ProductSourcePublishedUrl.php        ← Phase 9-B-3（新）
  ShortUrlProviderInterface.php
  MockShortUrlProvider.php             ← 測試用
  ShortUrlServiceShortUrlProvider.php  ← 生產委派（不跑單元測試）
```

```
core/short_url_service.php             ← 未改；ShortUrlServiceShortUrlProvider 僅呼叫
```

---

## 3. ProductSourceUrlPublisher

| 方法 | 說明 |
|------|------|
| `publish(ProductSourceUrlResult, ProductSourceDefinition)` | 單筆 long → published |
| `publishMany(array $longResults, array $definitionsBySourceId)` | 批次 |

**規則：**

| `short_url_enabled` | `short_search_url` / `short_detail_url` |
|-------------------|----------------------------------------|
| `false` | 與 long 相同（passthrough） |
| `true` | 呼叫 `ShortUrlProviderInterface` |

**Catalog 欄位來源：** `ProductSourceDefinition::isShortUrlEnabled()`, `getShortUrlDomain()`, `getDomainNamespace()`.

---

## 4. ProductSourcePublishedUrl

| 欄位 | 說明 |
|------|------|
| `source_id` | 商品源 |
| `product_category` | 類別 |
| `domain_namespace` | 邏輯命名空間（稽核／多網域） |
| `long_search_url` | 長搜尋鏈 |
| `long_detail_url` | 長詳情鏈 |
| `short_search_url` | 公開搜尋鏈 |
| `short_detail_url` | 公開詳情鏈 |

輔助：`isSearchShortened()`, `isDetailShortened()`.

---

## 5. ShortUrlProvider Interface

```php
interface ShortUrlProviderInterface {
    public function shortenSearchUrl(string $longUrl, array $context): string;
    public function shortenDetailUrl(string $longUrl, array $context): string;
}
```

**`$context`：** `source_id`, `short_url_domain`, `domain_namespace`, `product_category`.

---

## 6. Mock Provider

`MockShortUrlProvider`：

- **不** 呼叫 `ShortUrl_Add` / SQL
- 依 `domain_namespace` + kind + long URL 產生 deterministic `https://{short_url_domain}/{5-char-code}`
- 單元測試 **僅使用** Mock

---

## 7. Short URL Strategy

| 連結類型 | Mock | 生產（ShortUrlServiceShortUrlProvider） |
|----------|------|----------------------------------------|
| 搜尋 | `shortenSearchUrl` | `ShortUrlService::toPublicShortUrl()` + `publicBase` from domain |
| 詳情 | `shortenDetailUrl` | `ShortUrlService::toPublicShortUrlForItemLink()` |

**Fail-open：** 生產路徑沿用 `ShortUrlService` 行為（失敗回 long）。

---

## 8. Domain Namespace Strategy

| 欄位 | 用途 |
|------|------|
| `short_url_domain` | 對外短鏈 host（`https://{domain}/{code}`） |
| `domain_namespace` | 邏輯分組（598go / bbcshops / bonusmee）；Mock 影響 code 種子 |

**過渡期：** catalog 可皆設 `bbcshops.com` + `domain_namespace=bbcshops`；未來 per-source 改 domain 無需改 Publisher 介面。

---

## 9. Future Multi-Domain Strategy

| 網域 | namespace 範例 | Publisher |
|------|----------------|-----------|
| bbcshops.com | `bbcshops` | 同一 `ProductSourceUrlPublisher` |
| 598go.com | `598go` | 同上 |
| bonusmee.com | `bonusmee` | 同上 |

**共用一套 Publisher**；差異僅 catalog 的 `short_url_domain` + `ShortUrlService` 的 `publicBaseOverride`（經 `ShortUrlServiceShortUrlProvider`）。

---

## 10. Future LINE Integration

```
GroupPromoService / TourPromptContextService
    ↓
buildAllForTenant → publishMany
    ↓
Formatter 顯示 short_search_url（非 long）
```

**本階段未接 LINE。**

---

## 11. Future BATS Integration

規劃在 `TourPromptContextService` 組 context 時：

```php
$published = $publisher->publishMany($longResults, $definitionsBySourceId);
// context['search_urls'][] = $published[i]->toArray()
```

保留既有單一 `search_url` 向後相容。

---

## 12. Future GCS Integration

Catalog 自 GCS 載入後，`ProductSourceDefinition` 不變；Publisher **無需改**。

---

## 13. Low Refactor Principles

| 元件 | 變更 |
|------|------|
| `ShortUrlService` | **無** |
| `SearchUrlBuilder` | **無** |
| `MultiSourceSearchUrlBuilder` | **無** |
| Hybrid / saas_router | **無** |

---

## 14. Final Recommendation

### 專題回答

| # | 問題 | 答案 |
|---|------|------|
| **1** | bbcshops.com / 598go.com / bonusmee.com 共用 Publisher？ | **是** — 同一 Publisher + catalog `short_url_domain` / `domain_namespace` |
| **2** | 新商品源是否 catalog+adapter+template+short_url_enabled？ | **是** |
| **3** | 改 Hybrid / Tenant Resolver / Context Cache / Host B？ | **否**（本階段） |
| **4** | 低重構？ | **是** |

### 測試

```text
php tests/product_source/test_product_source_url_publisher.php
```

### 下一步

**Phase 9-B-4：GCS Product Source Catalog** — 適合在 Publisher 穩定後進行；Loader 改讀 GCS，Builder/Publisher 不變。

---

## 附錄：修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-29 | Phase 9-B-3 MVP |
