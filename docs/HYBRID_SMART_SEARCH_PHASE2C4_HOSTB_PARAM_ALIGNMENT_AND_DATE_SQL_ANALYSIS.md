# Hybrid Smart Search — Phase 2-C.4 Host B Param Alignment & Date SQL Analysis

**文件版本：** 2026-05-26  
**主機 A：** 103.1.222.14  
**主機 B：** 103.1.222.11:8080 (`BbcApiLayer`)  

---

## 1. Host A → Host B param mapping

新增 `core/search/HostBTourSearchParamMapper.php`，在 **Host B wire 邊界** 將內部 gateway 參數轉為 Swagger 參數名：

| 內部 / SearchCondition 衍生 | Host B query |
|----------------------------|--------------|
| `dateFrom` | `TourDateS` |
| `dateTo` | `TourDateE` |
| `priceMax` | `AmountMax` |
| `priceMin` | `AmountMin` |
| `departureCity` | `Departure` |
| `keyword` | `keyword`（不變） |

**SearchCondition** 仍使用 `date_from`、`date_to`、`budget_max`、`departure_city`。  
**SearchUrlBuilder** 仍使用 `dateFrom`/`dateTo`（前台 URL，不受影響）。

整合點：

- `ApiQueryMapper::toClientParams()` — 內部邏輯鍵（dry-run / debug）
- `ApiQueryMapper::toHostBParams()` — Host B wire
- `TourSearchRequestBuilder::build()` — 送出前自動 `toHostBQueryParams()`
- `TourSearchApiClient::buildRequestUrlFromParams()` — gateway URL 使用 Host B 鍵名
- `TourPromptContextService` — `searchWithParams()` 使用 `toHostBParams()`

---

## 2. 舊 naming vs 新 naming 對照表

| 舊（Phase 2-C.3 wire，Host B 忽略） | 新（Phase 2-C.4 wire，Host B 綁定） |
|-------------------------------------|-------------------------------------|
| `dateFrom` | `TourDateS` |
| `dateTo` | `TourDateE` |
| `priceMax` | `AmountMax` |
| `priceMin` | `AmountMin` |
| `departureCity` | `Departure` |

---

## 3. allowlist 修正

`TourSearchRequestBuilder::HOST_B_QUERY_ALLOWLIST` 與 `HostBTourSearchParamMapper::HOST_B_WIRE_ALLOWLIST`：

- `TourDateS`, `TourDateE`, `AmountMax`, `AmountMin`, `Departure`
- 保留：`sno`, `keyword`, `destination`, `country`, `city`, `page`, `pageSize`
- 移除 wire 上的：`dateFrom`, `dateTo`, `priceMax`, `priceMin`

---

## 4. final request URL 範例

**訊息：** `六月底東京三萬以下`（參考日 2026-05-26）

```text
https://bonusmee.com/api/gateway/tour/search.php?sno=e1fd133c7e8e45a1&page=1&pageSize=10&keyword=%E6%9D%B1%E4%BA%AC&destination=%E6%9D%B1%E4%BA%AC&country=%E6%97%A5%E6%9C%AC&city=%E6%9D%B1%E4%BA%AC&TourDateS=2026-06-21&TourDateE=2026-06-30&AmountMax=30000
```

**前台 search_url** 仍為 `dateFrom`/`dateTo`（`SearchUrlBuilder` 未改）。

---

## 5. Host B 日期 SQL root cause（唯讀推斷）

**限制：** SSH `103.1.222.11:22` 連線逾時；本 repo 無 Host B 原始碼，無法直接讀 Controller/Repository/SQL。

**HTTP 行為（Phase 2-C.4，已送正確參數名）：**

| 測試 | 參數 | total |
|------|------|-------|
| A | keyword | 7247 |
| B | + AmountMax=30000 | **4131** |
| C | + TourDateS/E | **7247**（與 A 相同） |
| D | B + TourDateS/E | **4131**（與 B 相同） |
| E | + Departure=高雄 | **1986** |

**結論（推斷）：**

1. **參數已能綁定到 DTO**（`AmountMax`、`Departure` 改變 total → 非全系統忽略 query）。
2. **`TourDateS`/`TourDateE` 對 total 無影響** → 較可能為：
   - Repository/SQL **未將日期加入 WHERE**；或
   - 比對欄位錯誤（如 `departureDate` vs `tourDate` / `GoDate`）；或
   - 型別/格式（DB `nvarchar` `yyyy/MM/dd` vs query `yyyy-MM-dd`）導致條件恆真；或
   - 日期條件被包在寬鬆 `OR` 分支中。
3. **較不可能** 仍是 Host A 錯名（2-C.4 已對齊 Swagger）。

**建議 Host B 團隊檢查：** `TourSearch` Repository 中 `TourDateS`/`TourDateE` 是否對 **`tourDate`（或實際出團日欄位）** 做 **AND** 區間比較；並以 SQL Profiler / 單元測試驗證。

---

## 6. HTTP LIVE 對照結果

執行：`tests/hybrid_search/test_hostb_date_filter_capability.php`、`diagnose_hostb_live_phase2c4.php`

| 測試 | total | 日期/價格篩選 |
|------|-------|----------------|
| A keyword | 7247 | baseline |
| B + AmountMax | 4131 | ✅ 價格生效 |
| C + TourDateS/E | 7247 | ❌ 日期未生效 |
| D + AmountMax + 日期 | 4131 | 價格✅；日期❌ |
| E + Departure | 1986 | ✅ 出發地生效 |

`AmountMax` 生效後，以 SearchCondition 檢查 sample items 的 **filter_violations** 可為 0（高價 12 月商品已被價格條件排除）。

---

## 7. 哪些 filter 已真正生效

| Filter | 狀態 |
|--------|------|
| keyword | ✅ |
| AmountMax | ✅（2-C.4 對齊後） |
| Departure | ✅ |
| TourDateS / TourDateE | ❌（參數名正確，SQL 仍無效） |
| destination / country / city | ⚠️ 仍送但 Host B 未列於 Swagger 有效篩選 |

---

## 8. 哪些 filter 仍未生效

- **出團日期區間**（`TourDateS`/`TourDateE`）— 需 Host B Repository/SQL 修復。
- **destination/country/city** — 待與 Host B 確認對應 `AreaTypeNo` / `AreaNamesLike`。

---

## 9. 是否仍需要 Host A Post Filter

| 情境 | 建議 |
|------|------|
| 價格（三萬以下） | 2-C.4 後 **AmountMax 已生效**，Post Filter 對價格為 **可選保險** |
| 日期（六月底） | **仍建議** 短期 Post Filter 或 Host B 修 SQL 前標註限制 |
| 長期 | Host B 修日期 SQL 後可再評估是否保留 Post Filter |

---

## 10. 下一步建議

1. **Host B：** 修復 `TourDateS`/`TourDateE` 的 SQL WHERE（本端已無法代修）。
2. **Host A：** staging LINE 實測「六月底東京三萬以下」，確認 `hybrid_search_api_debug.log` 含 `hostb_param_mapping`。
3. **契約文件：** 更新 `API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md` 與 Swagger 一致。
4. **可選：** `destination` → `AreaNamesLike` 映射（需 Host B 確認）。

---

## 附錄：Debug log 欄位

`hybrid_search_api_debug.log` 新增：

```json
"hostb_param_mapping": {
  "dateFrom": "2026-06-21",
  "TourDateS": "2026-06-21",
  "dateTo": "2026-06-30",
  "TourDateE": "2026-06-30",
  "priceMax": 30000,
  "AmountMax": 30000
},
"hostb_params": { "keyword": "東京", "TourDateS": "2026-06-21", ... }
```

---

## 附錄：Tests

| 測試 | 結果 |
|------|------|
| `test_hostb_param_mapping.php` | PASS |
| `test_hostb_request_alignment.php` | PASS |
| `test_hostb_date_filter_capability.php` | PASS（live HTTP） |
| `test_hybrid_search_api_param_mapping.php` | PASS |
| `test_hybrid_search_request_url.php` | PASS |
| `test_tour_search_request_builder_mvp.php` | PASS |

---

*Phase 2-C.4 — 2026-05-26*
