# Source Platform Contract

**代號：** `SOURCE_PLATFORM_CONTRACT`

**版本：** Phase 9-B-8

**前置 Commits：** `6456933`（Tenant GCS Provider）、`16409ff`（Provider Integration Test）、`23a102e`（Category Contract）

---

## 1. Purpose

Product Source Registry **不可**假設「一個平台 = 一個商品源」。

正確模型：

```
Source Platform（共用技術／供應平台）
    +
Tenant Source Instance（旅行社在該平台上的識別與啟用設定）
```

**本 Contract 定義 Platform 層**，Tenant 實例見 [TENANT_SOURCE_INSTANCE_CONTRACT.md](./TENANT_SOURCE_INSTANCE_CONTRACT.md)。

**本階段不涵蓋：** GCS 同步、Search URL 執行、Hybrid / Gemini / Host B / LINE。

---

## 2. Platform Document

| 欄位 | 類型 | 必填 | 說明 |
|------|------|------|------|
| `platform_id` | string | ✅ | 穩定鍵；`^[a-z][a-z0-9_]{0,63}$` |
| `platform_name` | string | ✅ | 顯示名稱 |
| `platform_domain` | string | ✅ | 平台根網域（如 `agenttour.com.tw`） |
| `platform_type` | enum | ✅ | §3 |
| `supported_categories` | string[] | ✅ | `ProductCategoryContract` enum |
| `identifier_type` | enum | ✅ | §4 |
| `adapter` | string | ✅ | Adapter 類別名稱 |
| `url_template_id` | string | ✅ | URL 模板識別 |
| `identifier_param_keys` | string[] | 條件 | `query_param` / `affiliate_param` 時建議提供 |

**執行期驗證：** `core/product_source/SourcePlatformContract.php`

---

## 3. Platform Type Enum

| 值 | 說明 | 範例 |
|----|------|------|
| `storefront` | 站內 storefront | bbcshops / cloud_store |
| `external_search` | 外部搜尋平台子網域 | AgentTour、GRP、BBC Travel、TourCenter |
| `affiliate_marketplace` | 聯盟參數識別 | Trip.com Hotel / Things To Do |
| `custom_website` | 旅行社私有官網模板 | xinxin.com.tw LIKEGO |
| `future` | 占位 | 新平台 onboarding |

---

## 4. Identifier Type Enum

| 值 | 說明 | 範例 |
|----|------|------|
| `subdomain` | 子網域前綴 | `dayitravel.grp.com.tw` → `{subdomain: dayitravel}` |
| `query_param` | URL query 參數 | `GetStore=XXN` |
| `affiliate_param` | 聯盟參數組 | `Allianceid` + `SID` |
| `fixed_url` | 固定完整 URL | 單一搜尋頁 |
| `custom` | 自訂 key-value map | 非標準整合 |

---

## 5. Reference Platforms（Phase 9-B-8）

| platform_id | platform_domain | identifier_type | adapter |
|-------------|-----------------|-----------------|---------|
| `agenttour` | agenttour.com.tw | subdomain | StubProductSourceAdapter |
| `grp` | grp.com.tw | subdomain | GrpAdapter |
| `bbctravel` | bbctravel.com.tw | subdomain | BbcTravelAdapter |
| `tourcenter` | tourcenter.com.tw | subdomain | TourCenterAdapter |
| `trip_com` | trip.com | affiliate_param | StubProductSourceAdapter |
| `custom_website` | xinxin.com.tw | query_param | StubProductSourceAdapter |

**常數：** `SourcePlatformContract::KNOWN_PLATFORMS`

---

## 6. Backward Compatibility

| 項目 | 策略 |
|------|------|
| Phase 9-B-1 catalog `source_id` | 仍有效；未來可映射至 `platform_id` + instance |
| `ProductSourceDefinition` | 本階段不修改 |
| `ProductSourceRegistry` | 本階段不修改 |
| Category enum | 沿用 `ProductCategoryContract` |

---

## 7. Test

```text
php tests/product_source/test_source_platform_contract.php
```

---

## 附錄：修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-30 | Phase 9-B-8 初版 |
