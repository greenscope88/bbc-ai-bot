# Product Source URL Builder Integration Test Report

**代號：** `PRODUCT_SOURCE_URL_BUILDER_INTEGRATION_TEST_REPORT`

**版本：** Phase 9-B-10

**日期：** 2026-05-30

**參考 Commit：** `1a53a10`（Source Instance URL Template Builder）

---

## 1. Purpose

以 **agenttour.com.tw** 為第一個正式整合測試案例，驗證從 Category / Platform / Instance Contract 到 URL Builder 的完整鏈路，產出實際商品源搜尋入口 URL。

---

## 2. Integration Stack Verified

```
Product Category Contract (group_tour)
        ↓
Source Platform Contract (agenttour.com.tw)
        ↓
Tenant Source Instance Contract (rechoice-travel + region/link params)
        ↓
Source Instance URL Template Builder (template-driven)
        ↓
entry_url (full search entry URL)
```

---

## 3. Reference Test Case: AgentTour

| 欄位 | 值 |
|------|-----|
| platform_code | agenttour |
| source_platform_domain | agenttour.com.tw |
| tenant_subdomain | rechoice-travel |
| product_category | group_tour |
| region_code | C |
| region_name | 泰國 |
| link_params / agency_source_id | 0000107929248116 |

**預期 URL：**

```
https://rechoice-travel.agenttour.com.tw/Index.aspx?WebArea=D10T2&BizType=Tour&RegionCode=C&LinkParams=0000107929248116
```

**URL Template（config-driven）：**

```
https://{tenant_subdomain}.{platform_domain}/Index.aspx
  ?WebArea=D10T2
  &BizType=Tour
  &RegionCode={region_code}
  &LinkParams={link_params}
```

---

## 4. Minimal Builder Extension (Phase 9-B-10)

為支援整合案例，**未重構核心架構**，僅在 `SourceInstanceUrlTemplateBuilder` 增加 config-driven 能力：

| 擴充 | 說明 |
|------|------|
| `tenant_subdomain` alias | subdomain 策略可讀取 `tenant_subdomain` |
| `query_template` | 靜態 + placeholder query 參數 |
| `required_identifier_keys` | 缺參數時拋出明確錯誤 |
| `assertNoUnreplacedPlaceholders` | 防止產生含 `{placeholder}` 的壞 URL |

**未硬編碼** agenttour / grp / bbctravel 等平台名稱於 Builder 核心。

---

## 5. Test File

| 檔案 | 說明 |
|------|------|
| `tests/product_sources/test_source_instance_url_builder_integration.php` | Phase 9-B-10 整合測試 |

```text
php tests/product_sources/test_source_instance_url_builder_integration.php
```

---

## 6. Test Results

| 案例 | 結果 |
|------|------|
| agenttour 完整 URL 精確比對 | PASS |
| 缺 tenant_subdomain | PASS（`URL_TEMPLATE_MISSING_IDENTIFIER`） |
| 缺 region_code | PASS |
| 缺 link_params | PASS |
| 既有 `tests/product_source/*` 回歸 | PASS |

---

## 7. Out of Scope

- SQL / `.env` / GCS
- saas_router / Hybrid Search / Gemini / Context Cache / Host B / LINE OA
- keyword / date / area 動態搜尋參數（本階段僅 entry URL template）

---

## 附錄：修訂紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-05-30 | Phase 9-B-10 agenttour 整合測試報告 |
