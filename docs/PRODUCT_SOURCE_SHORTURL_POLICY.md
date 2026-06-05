# PRODUCT_SOURCE_SHORTURL_POLICY.md

**專案：** BBC AI SaaS / BATS / ETT / API Gateway / Travel Data Center  
**定位：** L1 架構政策層 — Product Source URL 與 Short URL 正式 SSOT  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**適用對象：** ChatGPT、Cursor、開發者、維運人員  
**衝突處理：** 若與下層功能文件衝突，**以本文件為準**；若與上層 L0 政策衝突，**以上層為準**。

---

## 文件目的

本文件定義 BBC AI SaaS / BATS 之 **Product Source URL**、**Search URL**、**Short URL**、**URL Publisher** 的正式治理規則。

目的包括：

- 統一多商品源（grp、bbctravel、tourcenter、agenttour 等）之 URL 產生與短網址策略
- 明確 **Long URL** 與 **Short URL** 的分層邊界
- 避免各平台、各 tenant 自行產短鏈或散落 DB 寫入
- 與 `TENANT_SOURCE_REGISTRY_POLICY.md`、既有 `ShortUrlService`、`ProductSourceUrlPublisher` 對齊

---

## 核心原則

### URL Builder 職責

**URL Builder 只負責產生長網址（Long URL）。**

| 允許 | 禁止 |
|------|------|
| 依 registry / template 組裝 `https://` 完整搜尋鏈 | 在 Builder 內呼叫 `ShortUrl_Add` / DB |
| 產出 `search_url` 供下游消費 | 在 Builder 內直接產 `bbcshops.com/{code}` |
| 平台參數映射於 Platform / Adapter 層 | formatter 內重新組 URL 或短鏈 |

**禁止 URL Builder 直接產生短網址。**

對應 `CO_WORK_POLICY.md` §4.2、`TENANT_SOURCE_REGISTRY_POLICY.md` §Search URL Policy。

---

## Product Source URL 分層

正式管線（概念模型）：

```
Hybrid Search
    ↓
SearchCondition（語意：keyword、departure_city、date…）
    ↓
Product Source Adapter / URL Template Builder
    ↓
Long Search URL（各平台完整 https 鏈）
    ↓
Publisher（ProductSourceUrlPublisher）
    ↓
Short URL（可選；經 ShortUrlService）
    ↓
LINE OA / Gemini Context / Formatter（顯示層，無 DB）
```

**要點：**

- Hybrid 產出 **語意條件**，不產平台專用 path code（見 `BATS_HYBRID_DATE_POLICY.md`）
- 短網址化發生在 **Publisher 之後**，不在 Builder 或 Formatter

---

## Long URL 原則

### URL Builder 產出

URL Builder（含 `ProductSourceSearchUrlBuilder`、`MultiSourceSearchUrlBuilder`、`SourceInstanceUrlTemplateBuilder`）**只產生** 下列平台（及未來擴充）之 **長網址**：

| 平台（範例） | 長鏈 host（概念） |
|--------------|-------------------|
| `grp` | `*.grp.com.tw` |
| `bbctravel` | `*.bbctravel.com.tw` |
| `tourcenter` | `dayitourcenter.com.tw` 等 |
| `agenttour` | `*.agenttour.com.tw` |
| （未來）`trip` | Trip.com 等外部平台 |

### 規則

- 長鏈由 **registry + template** 決定，不 hardcode 於 formatter / router
- 主搜尋鏈（bonusmee `cloud_store_tourdate.php`）與多商品源長鏈 **分離**；各自可獨立短網址化
- 日期、出發地語意見 `BATS_HYBRID_DATE_POLICY.md`；平台 path 映射見 `TENANT_SOURCE_REGISTRY_POLICY.md` §Platform Mapping

---

## Short URL 原則

### 統一服務

**短網址統一由 `ShortUrlService`（`core/short_url_service.php`）處理。**

底層機制（legacy www，不在本 repo）：

- `ShortUrl_Add()` → `bs_ShortUrl`
- `redirect2.php` + Apache rewrite → 公開短鏈跳轉

### 禁止事項

| 禁止 | 原因 |
|------|------|
| **各平台自行產生短網址** | 重複邏輯、不一致 fail-open |
| **grp / bbctravel / tourcenter 各寫一套** | 違反多商品源共用策略 |
| **formatter 內 ShortUrl_Add** | 展示層不應碰 DB |
| **URL Builder 內 ShortUrl_Add** | 職責污染 |

### 生產路徑（規劃／部分已實作）

```
ShortUrlProviderInterface
    ↓
ShortUrlServiceShortUrlProvider（委派 ShortUrlService）
    ↓
ShortUrlService::toPublicShortUrl() / toPublicShortUrlForItemLink()
```

單元測試使用 `MockShortUrlProvider`，**不寫入** `bs_ShortUrl`。

---

## Publisher 原則

### 統一 Publisher

**統一由 `ProductSourceUrlPublisher`（`core/product_source/ProductSourceUrlPublisher.php`）負責** long → published 轉換。

### Publisher 輸出

`ProductSourcePublishedUrl` 可輸出：

| 欄位 | 說明 |
|------|------|
| `long_search_url` | 完整搜尋鏈（必備語意來源） |
| `short_search_url` | 公開短鏈（catalog `short_url_enabled` 時） |
| `long_detail_url` / `short_detail_url` | 詳情鏈（若適用） |

### 規則

- Publisher 讀取 catalog：`short_url_enabled`、`short_url_domain`、`domain_namespace`
- `short_url_enabled = false` 時，short 欄位 **passthrough** 為 long
- 批次：`publishMany()` 對多筆 `ProductSourceUrlResult` 處理

**接入點（規劃）：** `TourPromptContextService` 於 multi-source links 進入 Gemini context **之前** 呼叫 Publisher（非 formatter、非 builder）。

---

## Product Source Registry 關聯

本政策與 **`TENANT_SOURCE_REGISTRY_POLICY.md`** 緊密關聯。

正式資料流：

```
Tenant（sno / tenant_code）
    ↓
Source Instance（tenant_instance_key）
    ↓
Source Platform（platform_id）
    ↓
Long URL（Builder + template）
    ↓
Publisher（ProductSourceUrlPublisher + catalog）
    ↓
Short URL（ShortUrlService）
```

**規則：**

- 新增 tenant / source **只增 registry + catalog**，不 fork Publisher 核心
- `source_instance_keys` 決定多商品源清單；短網址策略 **不因 tenant 分叉**

---

## 多商品源原則

以下平台（及未來擴充）**全部共用同一套 Short URL 策略**：

| 現行 Pilot | 未來 |
|------------|------|
| `grp` | Trip.com |
| `bbctravel` | 其他 OTA / 平台 |
| `tourcenter` | … |

### 規則

- **一套** `ProductSourceUrlPublisher`
- **一套** `ShortUrlService` / `bs_ShortUrl` code 空間
- 差異僅在 catalog：`short_url_domain`、`domain_namespace`、`short_url_enabled`
- **禁止** 為 travel_b 或單一平台寫獨立短鏈邏輯

---

## 多網域短網址策略

### 目前

- 對外短鏈預設顯示：**`bbcshops.com/{code}`**
- `ShortUrlService::DEFAULT_PUBLIC_BASE`；可經 config / `.env` `SHORT_URL_PUBLIC_BASE` 覆寫

### 未來

| 網域（規劃） | 用途 |
|--------------|------|
| `598go.com` | 替代或分流顯示域 |
| `bonusmee.com` | 與 storefront 對齊之顯示域 |
| 其他網域 | per-source 或 per-tenant `short_url_domain` |

### 規則

- **不得寫死** `bbcshops.com` 於 formatter、builder、Publisher 核心
- 顯示網域由 catalog **`short_url_domain`** + `ShortUrlService` `publicBaseOverride` 決定
- DB 層（`bs_ShortUrl`）目前 **無 domain 欄位**；同 code 空間共用；多 vhost 需 Apache/IIS 對應（基礎設施另案）

---

## Allowlist 原則

### 允許

僅對 **已註冊 Product Source Host**（registry / catalog 定義之平台網域）呼叫 `ShortUrl_Add`。

**範例允許 host 模式（概念）：**

- `*.grp.com.tw`
- `*.bbctravel.com.tw`
- `dayitourcenter.com.tw`
- `*.agenttour.com.tw`
- `bonusmee.com`（主搜尋鏈）
- `drive.google.com` / `docs.google.com`（行程表專用 gate，見 `ShortUrlService::toPublicShortUrlForScheduleLink`）

### 禁止

| 禁止 | 風險 |
|------|------|
| **未知 Host** 寫入 `bs_ShortUrl` | Open Redirect |
| **已是短鏈的 host 再短鏈** | 雙層短鏈（如 `bbcshops.com` → 再 ShortUrl_Add） |
| **`javascript:` 或非 https 惡意 URL** | redirect2.php 信任 DB url 全文 |

**實作位置：** Allowlist 應在 **ShortUrlService 或 ShortUrlServiceShortUrlProvider** 擴充，非散落各平台。

---

## Fail Open 原則

**Short URL 建立失敗時，回傳 Long URL。**

| 情境 | 行為 |
|------|------|
| `ShortUrl_Add` 失敗 | 保留 long URL |
| legacy stack 不可用 | 保留 long URL |
| allowlist 拒絕 | 保留 long URL（或拒絕短鏈化、仍輸出 long） |
| DB 逾時 / 例外 | 記錄 log；**fail-open** |

### 不得造成

- **LINE OA 無法回覆**（連結欄位為空）
- **客戶看不到可點擊連結**

與 legacy bonusmee `search_url` Phase 1A 行為一致。

---

## Tenant 原則

`travel_a`、`travel_b`、`travel_c` 及未來 **20～200 Tenant**：

| 規則 | 說明 |
|------|------|
| **共用同一套 Publisher** | `ProductSourceUrlPublisher` + `ShortUrlProviderInterface` |
| **共用 ShortUrlService** | 同一 `bs_ShortUrl` pool（過渡期） |
| **禁止 Tenant 客製短網址邏輯** | 不得 `if (travel_b)` 於 formatter / builder 產短鏈 |
| **差異僅在 catalog** | `short_url_enabled`、`short_url_domain`、`source_instance_keys` |

Pilot gate（如 `TravelBMultiSourceLinkBuilder::isEnabledForSno`）只控制 **是否產多源連結**，不實作獨立短鏈演算法。

---

## 點擊追蹤 Roadmap

**目前不實作（P3）。**

未來可於 redirect 層或 metadata 擴充：

| 欄位（規劃） | 用途 |
|--------------|------|
| Click Count | 點擊統計 |
| Source Platform | grp / bbctravel / … |
| Tenant | sno / tenant_code |
| Conversion | 轉換分析 |

**現況：** `bs_ShortUrl` 僅 `code` + `url`；無 platform / tenant 欄位。實作前須先更新本 SSOT 與 schema 評估文件。

---

## ProductSourceUrlPublisher

### 正式定位

**Publisher Layer** — 介於 Long URL 產出與對外（LINE / Gemini）顯示之間。

| 職責 | 非職責 |
|------|--------|
| long → published（long + short 欄位） | 組裝 URL template |
| 讀 catalog `short_url_*` | 呼叫 Gemini / LINE API |
| 委派 `ShortUrlProviderInterface` | 直接 SQL / `ShortUrl_Add`（透過 Provider 委派 Service） |

### 禁止

- **URL Builder 直接呼叫 DB**
- **Formatter 直接呼叫 DB**
- **修改 `ShortUrlService` 本體** 以塞入平台 if（應擴充 Provider / allowlist）

**測試：** `tests/product_source/test_product_source_url_publisher.php`（Mock only）。

---

## 與其他文件關係

### 上層（L0）

| 文件 | 關係 |
|------|------|
| `CO_WORK_POLICY.md` | 架構分層、禁止 formatter/Builder 碰 DB、Recovery First |
| `DOCUMENTATION_GOVERNANCE_POLICY.md` | L1 SSOT、文件建立判斷 |

### 同層（L1）

| 文件 | 關係 |
|------|------|
| `TENANT_SOURCE_REGISTRY_POLICY.md` | Tenant / Instance / Platform、Long URL 產生 |
| `RECOVERY_AND_ROLLBACK_POLICY.md` | Apache rewrite、DB 寫入失敗之 Recovery |

### 下層（L2）

| 文件 | 關係 |
|------|------|
| `BATS_HYBRID_DATE_POLICY.md` | 日期語意；不定義短網址 |

### L3 參考（非 SSOT，實作細節）

| 文件 | 說明 |
|------|------|
| `PRODUCT_SOURCE_SHORTURL_INTEGRATION_MVP.md` | Phase 9-B-3 MVP 設計 |
| `TRAVEL_DATA_CENTER_PHASE9A5_STORAGE_AND_SHORTURL_STRATEGY.md` | 歷史策略盤點 |

**規則：** L3 與本文件衝突時，**以本文件為準**。

### 文件優先順序（本領域）

```
CO_WORK_POLICY.md
    ↓
DOCUMENTATION_GOVERNANCE_POLICY.md
    ↓
PRODUCT_SOURCE_SHORTURL_POLICY.md   ← 本文件
    ↓
TENANT_SOURCE_REGISTRY_POLICY.md（Long URL / Registry）
    ↓
BATS_HYBRID_DATE_POLICY.md（日期語意）
    ↓
L3 MVP / Phase 文件
```

---

## 未來 Roadmap

本政策支撐以下演進（多為 P2 / P3）：

| 方向 | 說明 |
|------|------|
| **多網域短網址** | 598go / bonusmee 顯示域 + vhost |
| **多平台** | 新 platform 僅增 catalog + allowlist |
| **多 Tenant** | GCS catalog；Publisher 不變 |
| **Tracking** | redirect 埋點、metadata |
| **Analytics** | 點擊 / 轉換報表 |

Roadmap 項目記錄於 `TECH_DEBT.md` 或專項 Roadmap；實作前須更新本 SSOT。

---

## 結論

**Product Source URL** 與 **Short URL** 必須：

| 要求 | 實作錨點 |
|------|----------|
| **統一治理** | 本文件 + `DOCUMENTATION_GOVERNANCE_POLICY.md` |
| **統一 Publisher** | `ProductSourceUrlPublisher` |
| **統一 ShortUrlService** | `ShortUrlService` + `ShortUrlServiceShortUrlProvider` |
| **Builder 只產 Long URL** | `ProductSourceSearchUrlBuilder` / `MultiSourceSearchUrlBuilder` |
| **Fail-open** | 短鏈失敗仍顯示 long URL |

ChatGPT、Cursor、開發者進行多商品源 URL、短網址、LINE 連結顯示相關決策或實作前，應以本文件為 **Product Source URL 與 Short URL 之正式 SSOT**。

---

## Revision History

| 版本 | 日期 | 狀態 | 說明 |
|------|------|------|------|
| 1.0 | 2026-06-05 | Adopted | Initial Version |
