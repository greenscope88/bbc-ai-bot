# Hybrid Smart Search — Phase 2-C.3 Host B Tour Search Filter 根因分析

**文件版本：** 2026-05-26  
**主機 A：** 103.1.222.14（`C:\bbc-ai-bot`）  
**主機 B：** 103.1.222.11:8080（`BbcApiLayer` / `GET /api/tour/search`）  
**問題訊息：** `六月底東京三萬以下`  
**實測異常範例：** 12/18 出發、71,800 元、非六月底、非三萬以下  

**分析類型：** 唯讀診斷（未修改 Host B 程式、未修改 Host A production flow、未執行 SQL 寫入）

---

## 1. 問題摘要

LINE 實測輸入「六月底東京三萬以下」後，回覆仍含不符合條件商品（例如 2026/12/18、71,800 元）。  
本階段以 **HTTP 對照測試 + Host A 程式/日誌 + Host B OpenAPI（Swagger）** 交叉驗證，結論如下：

| 層級 | 結論 |
|------|------|
| Host A 語意解析（SearchCondition） | ✅ 正確 |
| Host A API 參數映射（ApiQueryMapper / URL） | ✅ 已送出 `keyword`、`dateFrom`、`dateTo`、`priceMax` 等 |
| Host B **參數命名契約** | ❌ **主要根因**：Host A 使用 `dateFrom`/`dateTo`/`priceMax`，Host B 實際綁定 **`TourDateS`/`TourDateE`/`AmountMax`**；錯名參數被 **靜默忽略** |
| Host B **日期 SQL 篩選** | ❌ **次要根因**：即使使用正確參數名 `TourDateS`/`TourDateE`，`total` 與未帶日期時 **完全相同** → Repository/SQL **未套用日期條件** |
| 排序 | ⚠️ 在篩選未生效時，高價/非六月底商品可排在第一筆（非獨立根因） |

**一句話根因：** Host A 已正確解析並送出 Gateway 風格參數，但 Host B API 契約為 **另一套參數名稱**；`priceMax`/`dateFrom` 等未到達 Host B 有效綁定欄位，導致價格與日期篩選失效，僅 `keyword`（及部分原生參數如 `AmountMax`）會改變結果集。

---

## 2. Host A 條件傳遞分析

### 2.1 SearchCondition（`六月底東京三萬以下`）

以 `HybridSearchConditionBuilder::parse()` + 參考日 `2026-05-26` 驗證（`tests/hybrid_search/test_hybrid_search_api_param_mapping.php` 全數通過）：

| 欄位 | 值 |
|------|-----|
| `keyword` | 東京 |
| `area` | 日本 |
| `destination` | 東京 |
| `date_from` | 2026-06-21 |
| `date_to` | 2026-06-30 |
| `budget_max` | 30000 |
| `departure_city` | null（訊息未含「○○出發」） |

### 2.2 ApiQueryMapper → client params

| SearchCondition | API param |
|-----------------|-----------|
| `budget_max` | `priceMax` = 30000 |
| `date_from` / `date_to` | `dateFrom` / `dateTo` |
| `destination` / `area` | `destination` / `country` / `city` |

### 2.3 日誌

| 檔案 | 觀察 |
|------|------|
| `logs/hybrid_search_dry_run.log` | hybrid flow 可見 `dateFrom`/`dateTo`；舊測試訊息「六月底東京團」無 `budget_max` |
| `logs/hybrid_search_api_debug.log` | 整合測試「六月底東京團」有日期、**無 priceMax**；**尚無**「六月底東京三萬以下」production 實測列 |

### 2.4 欄位命名（Host A 內部 vs wire）

| 概念 | SearchCondition | Host A wire（camelCase） |
|------|-----------------|---------------------------|
| 預算上限 | `budget_max` | `priceMax` |
| 日期起迄 | `date_from` / `date_to` | `dateFrom` / `dateTo` |
| 出發地 | `departure_city` | **未映射**至 API client params（`ApiQueryMapper` 無 `departureCity`） |

Host A 內部 `budget_*` → wire `priceMax` 的映射 **正確**；問題在 Host B 不認 `priceMax`。

---

## 3. Host A final request URL

**訊息：** `六月底東京三萬以下`（參考日 2026-05-26）

```text
https://bonusmee.com/api/gateway/tour/search.php?sno=e1fd133c7e8e45a1&page=1&pageSize=10&keyword=%E6%9D%B1%E4%BA%AC&destination=%E6%9D%B1%E4%BA%AC&country=%E6%97%A5%E6%9C%AC&city=%E6%9D%B1%E4%BA%AC&dateFrom=2026-06-21&dateTo=2026-06-30&priceMax=30000
```

`TourSearchApiClient::buildRequestUrlFromParams()` allowlist 含：`keyword`, `destination`, `country`, `city`, `dateFrom`, `dateTo`, `priceMax`, `priceMin`。

`TourSearchRequestBuilder::HOST_B_QUERY_ALLOWLIST` 亦含上述欄位（直連 Host B 時同樣送出 camelCase 名稱）。

---

## 4. Host B Controller query parameter 分析

**來源：** `GET http://103.1.222.11:8080/swagger/v1/swagger.json`（OpenAPI 3.0.4，`BbcApiLayer`）

### 4.1 Host B 實際宣告的 query parameters

| Swagger 名稱 | 用途（推斷） |
|--------------|--------------|
| `Sno` | 租戶 |
| `Keyword` | 關鍵字 |
| `AreaTypeNo` | 區域代碼 |
| `OtherTypeNo` | 其他分類 |
| `AreaNamesLike` | 區域名稱模糊 |
| **`TourDateS`** | 出團日期起 |
| **`TourDateE`** | 出團日期迄 |
| **`Departure`** | 出發地 |
| **`AmountMin`** | 價格下限 |
| **`AmountMax`** | 價格上限 |
| `Clearance`, `Mcno`, `DmFileFlag`, … | 其他業務旗標 |
| `Page`, `PageSize`, `OrderBy` | 分頁/排序 |

### 4.2 Host A 送出但 Swagger **未列出** 的參數

`dateFrom`, `dateTo`, `priceMax`, `priceMin`, `destination`, `country`, `city`, `departureCity`

ASP.NET Core 預設 **不會** 將 `dateFrom` 綁定至 `TourDateS`；測試證實這些參數被 **忽略**（見第 7 節）。

### 4.3 契約文件差異

`docs/API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md` §10.3 MVP 僅列 `sno`, `keyword`, `page`, `pageSize`。  
Phase 2 Host A 已擴充 camelCase 篩選欄位，但 **未與 Host B Swagger 對齊**。

---

## 5. Host B DTO / Service / Repository 分析

**限制：** 本 repo **不含** Host B 原始碼；SSH `103.1.222.11:22` 連線逾時，無法唯讀檢視 Controller/Repository 原始碼與 SQL。

**由 HTTP 行為推斷：**

| 層 | 推斷 |
|----|------|
| Controller / Model Binding | 僅辨識 Swagger 所列 PascalCase（或大小寫不敏感同名）參數；`dateFrom`/`priceMax` **未進 DTO** |
| Service | `AmountMax`、`Departure` 有往下影響 `total`；`TourDateS`/`TourDateE` **可能未傳入查詢或 SQL 未使用** |
| Repository / SQL | `AmountMax` 會縮小結果（7247→4131）；日期條件 **即使參數名正確也無效果**（641 vs 641） |

---

## 6. 各條件支援狀態表

| 條件 | Host A 送出 | Host B Swagger | HTTP 實測（錯名 camelCase） | HTTP 實測（正確原生名） |
|------|-------------|----------------|----------------------------|-------------------------|
| **keyword** | `keyword` | `Keyword` | ✅ 有效（total=7247；無 keyword → 0） | ✅ |
| **dateFrom / dateTo** | `dateFrom`, `dateTo` | `TourDateS`, `TourDateE` | ❌ 忽略（A–D 皆 total=7247） | ❌ **SQL 未生效**（加日期 total 不變） |
| **priceMax** | `priceMax` | `AmountMax` | ❌ 忽略（仍出現 71800） | ✅ 有效（total 7247→4131） |
| **priceMin** | `priceMin` | `AmountMin` | ❌ 未測（推定同 priceMax） | （未測） |
| **departureCity** | 未送（此句無出發地） | `Departure` | ❌ `departureCity` 忽略 | ✅ `Departure=高雄` → total=1986 |
| **area** | `area`→`country` 等 | `AreaTypeNo`, `AreaNamesLike` | ❌ `destination` 忽略 | `AreaNamesLike` 單獨測試 total 仍 7247 |
| **destination / country / city** | 有送 | 無直接對應 | ❌ 忽略 | — |

---

## 7. HTTP 對照測試結果

**環境：** `http://103.1.222.11:8080/api/tour/search`，`sno=e1fd133c7e8e45a1`，`x-api-key` 自 `.env`（未記錄於本文件）。  
**腳本：** `tests/hybrid_search/diagnose_hostb_filter_http.php` 等。

### 7.1 Host A 風格參數（camelCase）— 測試 A–D

| 測試 | URL 參數 | total | 第一筆 tourDate | 第一筆 price | 備註 |
|------|----------|-------|-----------------|--------------|------|
| A | keyword | 7247 | 2026/12/18 | 71800 | 標題無「東京」 |
| B | + dateFrom/dateTo | **7247** | 2026/12/18 | 71800 | 與 A **完全相同** |
| C | + priceMax=30000 | **7247** | 2026/12/18 | 71800 | 與 A **完全相同** |
| D | + date + priceMax | **7247** | 2026/12/18 | 71800 | 與 A **完全相同** |

**結論：** `dateFrom`、`dateTo`、`priceMax` 對 Host B **零效果**。

### 7.2 Host B 原生參數（Swagger 名稱）

| 測試 | 參數 | total | 第一筆 | 備註 |
|------|------|-------|--------|------|
| B-native | TourDateS/E + Keyword | 7247 | 12/18, 71800 | 日期仍無效 |
| C-native | AmountMax=30000 | **4131** | 06/08, 28888 | ✅ 價格篩選生效 |
| D-native | 日期+AmountMax | 4131 | 06/08, 28888 | 與 C-native 相同 |
| E-native | Departure=高雄 | 1986 | — | ✅ 出發地生效 |
| kw+amt+dep | AmountMax+Departure | 641 | — | 組合有效 |
| kw+amt+dep+date | 再加 TourDateS/E | **641** | — | 加日期 **total 不變** |

### 7.3 測試 E（出發地）

| 參數 | total |
|------|-------|
| `departureCity=高雄`（camelCase） | 7247（忽略） |
| `Departure=高雄`（原生） | 1986（有效） |

---

## 8. SQL / Repository filter 根因

**無法直接讀取 SQL**；由 HTTP 行為推斷邏輯應為：

```text
實際（推斷）：
  Keyword 條件（有效，可能較寬）
  AND AmountMax  （僅當參數名 = AmountMax）
  AND Departure  （僅當參數名 = Departure）
  -- TourDateS/TourDateE：綁定可能存在，但 WHERE 未套用或條件無效

非 AND 全條件：
  dateFrom / priceMax / destination 等 camelCase → 完全不進查詢
```

**不是**「keyword OR date OR price」的寬鬆 OR；而是 **多數篩選參數根本未進查詢** + **日期欄位即使原生名也無法縮小結果集**。

---

## 9. 是否為 Host A mapping 問題？

**部分成立，但性質為「契約錯配」而非「解析錯誤」：**

| 項目 | Host A 狀態 |
|------|-------------|
| 自然語意 → SearchCondition | ✅ 正確 |
| budget_max → priceMax | ✅ 正確（Host A 內） |
| date → dateFrom/dateTo | ✅ 正確（Host A 內） |
| wire 參數名 vs Host B Swagger | ❌ **未映射**至 `TourDateS`/`TourDateE`/`AmountMax`/`Departure` |
| departure_city | ⚠️ 解析有支援，但 `ApiQueryMapper` **未輸出** `departureCity` |

---

## 10. 是否為 Host B 接收問題？

**是（參數綁定層）：**  
Host B 只接受 Swagger 定義名稱；Host A/Gateway 送的 `dateFrom`/`priceMax` **不綁定**，靜默忽略，無 400 錯誤。

---

## 11. 是否為 Host B 查詢未套用問題？

**是（兩個層次）：**

1. **錯名參數：** `priceMax` 未對應 `AmountMax` → 價格條件未進 SQL → **71800 可出現**。  
2. **日期即使正名仍無效：** `TourDateS`/`TourDateE` 改變 `total` 的實驗為 **0**（需搭配其他條件）；在 `Keyword + AmountMax + Departure` 固定下，加日期 **641→641** → **Repository/SQL 日期 filter 故障或未實作**。

---

## 12. 是否為排序問題？

**非主因。**  
在篩選失效時，第一筆為「高雄出發｜藏王樹冰… 71,800｜2026/12/18」，標題不含「東京」，顯示為 **未價格/日期過濾的 keyword 結果集 + 預設排序**（可能依直售價、熱門度等）。  
當 `AmountMax=30000` 生效後，第一筆變為 28,888 的東京特輯團。

---

## 13. 造成 12 月 / 71,800 商品出現的原因

| 原因 | 說明 |
|------|------|
| 1 | Host A 送 `priceMax=30000`，Host B 只認 **`AmountMax`** → 價格上限 **未套用** |
| 2 | Host A 送 `dateFrom`/`dateTo`，Host B 只認 **`TourDateS`/`TourDateE`** → 日期 **未套用** |
| 3 | Host B 在正確日期參數下仍 **未縮小 total** → 六月底條件 **即使對齊名稱也可能無效** |
| 4 | `keyword=東京` 結果集仍可能含標題不明示東京的商品（關鍵字匹配範圍/排序） |

---

## 14. 建議修正方案

### 方案 A：優先修 Host B / Gateway 參數對齊（建議）

1. **Host A `TourSearchRequestBuilder` / `ApiQueryMapper` 增加 Host B 原生映射：**
   - `dateFrom` → `TourDateS`
   - `dateTo` → `TourDateE`
   - `priceMax` → `AmountMax`
   - `priceMin` → `AmountMin`
   - `departureCity` → `Departure`
   - `destination` / `area` → `AreaNamesLike` 或 `AreaTypeNo`（需與 Host B 團隊確認）
2. **Host B 修復 `TourDateS`/`TourDateE` SQL**（HTTP 已證明目前無效）。
3. 更新 `API_GATEWAY_MVP_TOUR_SEARCH_CONTRACT.md` 與 Swagger 一致。

### 方案 B：Host A Post Filter（保險層）

在 Host B 修復前，可對 `items` 做 `tourDate ∈ [date_from, date_to]`、`price ≤ budget_max` 過濾。  
**缺點：** 分頁 `total` 不準、多抓資料；**優點：** 可立即改善 LINE 顯示。  
**建議：** 作為 **過渡保險**，非長期替代方案 A。

### 方案 C：後續 Ranking Engine

篩選正確後，再依日期接近度、價格、目的地相關性排序。

---

## 15. 不建議現在做什麼

- ❌ 不要僅加 Host A Post Filter 而不修參數映射（無法修正 `total` 與 Host B 負載）。
- ❌ 不要假設 `dateFrom`/`priceMax` 已被 Host B 使用（本報告已反證）。
- ❌ 不要在未對齊 Swagger 前擴充更多 camelCase 參數。
- ❌ 不要修改 Gemini prompt / formatter 來「掩蓋」錯誤商品（治標不治本）。
- ❌ 本次分析範圍內 **未** 修改 Host B 程式與 production flow。

---

## 16. 下一步實作建議

1. **Phase 2-C.4（建議）：** Host A `HostBTourSearchParamTranslator`（或擴充 `TourSearchRequestBuilder`）做 camelCase → Swagger 名稱映射；整合測試對 Host B 驗證 `total` 變化。  
2. **Host B 工單：** 修復 `TourDateS`/`TourDateE` Repository WHERE；補單元/整合測試。  
3. **補 production log：** 以「六月底東京三萬以下」實測寫入 `hybrid_search_api_debug.log`，確認 gateway 實際 URL。  
4. **評估 Post Filter：** Host B 日期修復完成前，是否啟用短期保險層。  

---

## 附錄 A：診斷腳本（唯讀）

| 腳本 | 用途 |
|------|------|
| `tests/hybrid_search/diagnose_hostb_filter_http.php` | 測試 A–D（camelCase） |
| `tests/hybrid_search/diagnose_hostb_native_params.php` | Swagger 原生參數名 |
| `tests/hybrid_search/diagnose_hostb_date_formats.php` | 日期格式對照 |
| `tests/hybrid_search/diagnose_hostb_departure.php` | 出發地對照 |
| `tests/hybrid_search/diagnose_hostb_combo.php` | 日期是否改變 total |
| `tests/hybrid_search/diagnose_hosta_url.php` | Host A SearchCondition + URL |

---

## 附錄 B：合規確認

| 項目 | 狀態 |
|------|------|
| 修改 Host B API 程式 | **否** |
| 修改 Host A production flow | **否** |
| SQL UPDATE/INSERT/DELETE | **否** |
| 修改 `.env` | **否** |
| git push | **否** |
| 建立 Host A Post Filter | **否**（本次僅分析） |

---

*Phase 2-C.3 — Host B filter root cause analysis — 2026-05-26*
