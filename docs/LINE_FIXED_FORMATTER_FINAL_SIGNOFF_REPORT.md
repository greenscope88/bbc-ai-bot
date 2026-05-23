# LINE Fixed Formatter 最終 Sign-off 報告

**主機：** A（103.1.222.14）  
**專案路徑：** `C:\bbc-ai-bot`  
**文件版本：** 2026-05-23  
**最終 HEAD（撰寫時）：** `c036cf9` — `style(line): shorten fixed formatter item separator for LINE`  
**分支：** `feature/api-gateway-mvp`

---

## 1. 文件目的

本文件彙整 LINE 行程查詢回覆（fixed formatter）從問題發現、架構修正到 staging 驗收的完整脈絡，作為：

- 工程與維運之**最終 sign-off 依據**
- 後續部署、recycle、LINE 重測之**對照基線**
- 說明 **Gemini 不再改寫商品清單**、**schLinks 端到端保留**、**TourFallbackFormatter 為唯一版面來源** 之設計決策

本文件為**純文件整理**，不包含程式或資料庫變更。

---

## 2. 背景問題

### 2.1 Gemini 改寫商品格式

- `TourPromptContextService` 產出結構化行程 context 後，原流程將完整 prompt 送交 Gemini。
- Gemini 成功時以**自行改寫**的文字回覆客人，導致：
  - 舊標籤（`出團日期：`、`行程內頁：`）
  - 行程表連結名稱（如「精彩行程點我」）
  - 多筆 `schLinks` 被摘要為「另有 N 筆行程表」
- 與系統在 context 中整理的格式不一致，無法作為 staging 驗收基準。

### 2.2 schLinks 被 redacted

- `HostBResponseNormalizer` 早期對回應做敏感欄位清理時，連帶移除或遮蔽 `schLink` / `schLinks` 內 URL。
- 下游 `GeminiTourContextBuilder` 與 LINE 回覆因此**缺少行程表**，客人看不到 PDF／短網址。

### 2.3 行程表 URL 問題

- 需以 **URL-only** 呈現（不顯示 `schLinkName`）。
- 多筆 `schLinks` 須**全部列出**，不可只顯示 2 筆再加「另有 N 筆」。
- 釜山 couponNo=11849 等案例需驗證 4 筆短鏈全列。

### 2.4 舊標籤問題

- 舊 LINE 回覆使用：`出團日期：`、`行程內頁：`、`更多參考行程及出團日期：` 等。
- 目標改為：`最近出團：`、`詳細內容：`、`更多行程 & 出團日：`（最終輸出層再套 emoji，見第 5 節）。

### 2.5 LINE 換行問題

- 商品間分隔線若過長（如 17 個 `─` 或 `=` 長線），在 LINE 客戶端可能**自動換行**，破壞版面。
- 最終改為較短分隔線 `──────────────`（`c036cf9`）。

---

## 3. 最終架構

資料與回覆鏈路（tour_query 且具 tour context 時）：

```
Host B API（schLinks[]）
    ↓
HostBResponseNormalizer（保留行程表 URL，redact 其他敏感欄位）
    ↓
TourSearchService / API Gateway
    ↓
TourPromptContextService（組 Gemini 用 context；供解析用）
    ↓
TourLineReplyComposer
    ├─ tourContext 非空 → TourFallbackFormatter（不呼叫 Gemini 改寫清單）
    └─ 否則 → Gemini / fallback
    ↓
LINE OA（Push Message API）
```

**關鍵檔案（參考，本文件不修改程式）：**

| 元件 | 路徑 |
|------|------|
| Normalizer | `core/api_gateway/production/http/HostBResponseNormalizer.php` |
| Context | `core/gemini_tour_context_builder.php` |
| Composer | `core/tour_line_reply_composer.php` |
| Formatter | `core/tour_fallback_formatter.php` |
| Router | `core/saas_router.php` |

---

## 4. 關鍵 commit timeline（依序）

| Commit | 摘要 | 解決問題 |
|--------|------|----------|
| `704734f` | feat(api-gateway): forward schLinks from Host B gateway | Gateway 轉發 Host B `schLinks[]` |
| `a920d5a` | fix(api-gateway): preserve schedule link URLs in Host B response normalizer | Normalizer 保留 `schLink` / `schLinks` URL |
| `e9858eb` | feat(api-gateway): show all schedule URLs and rename LINE tour labels | Context 新標籤、多筆行程表全列、URL-only |
| `6fa7c9e` | fix(api-gateway): use fixed tour list for LINE instead of Gemini rewrite | **Scheme C**：`used_fixed_tour_list=true`，略過 Gemini 改寫清單 |
| `52d7cc9` | style(line): refine fixed tour formatter emoji layout | Emoji 標籤、🚩 商品名（無編號）、`💰 售價：` |
| `548b8d5` | style(line): final fixed tour formatter UI spacing and separators | Emoji 後空格、靠左、商品間 `─────────────────` |
| `c036cf9` | style(line): shorten fixed formatter item separator for LINE | 分隔線改為 `──────────────`，降低 LINE 換行 |

---

## 5. 最終 UI 規格

以下為 **`TourFallbackFormatter` LINE 輸出**（`c036cf9`）之驗收規格：

### 5.1 開頭／結尾（固定）

- 開頭：`您好，以下為行程參考資訊（系統自動整理，實際以官網與客服確認為準）：`
- 結尾：`如需更多協助，歡迎再告訴我們。`
- **不得**出現 Gemini 式開場（如「哈囉！」「很高興為您服務」等）。

### 5.2 單筆商品區塊

| 欄位 | 格式 |
|------|------|
| 商品標題 | `🚩 ` + 商品名稱（**無** `1.` / `2.` 編號） |
| 標題後 | 空一行 |
| 最近出團 | `📅 最近出團：` + 日期（MM/DD，可合併） |
| 售價 | `💰 售價：` + 金額 |
| 出發地 | `🛫 出發地：` + 出發地 |
| 詳細內容 | `📄 詳細內容：` + `tourdate_dm.php` URL |
| 行程表 | `🗓️ 行程表：` + URL-only（見下） |

**排版規則：**

- Emoji 與中文之間：**一個半形空格**（例：`📅 最近出團：`）。
- 欄位行：**靠左，無**行首三格縮排。
- 多筆行程表：標題行 `🗓️ 行程表：` 後接  
  `1. https://...`  
  `2. https://...`（僅 URL，無連結名稱）。

### 5.3 商品分隔線

- 字串：`──────────────`（14 個 `─`，U+2500）
- **每一筆商品結束後**皆有一行分隔線（**包含最後一筆**）。
- **禁止**恢復：`========================================`、`━━━━━━━━━━━━━━━━━━━`。

### 5.4 搜尋 footer

```
更多行程 & 出團日：
https://bonusmee.com/view/cloud/cloud_store_tourdate.php?...&keyword=...
```

### 5.5 禁止項目

- `精彩行程點我` 或任何 `schLinkName` 作為顯示文字
- `另有 N 筆行程表`
- 舊標籤：`出團日期：`、`行程內頁：`、`更多參考行程及出團日期：`
- Gemini 改寫之商品清單內文

---

## 6. Gemini 行為最終策略

| 項目 | 行為 |
|------|------|
| `used_fixed_tour_list` | `true`（`saas_router.log` → `router_complete`） |
| Gemini 與商品清單 | **不介入**；有 tour context 時不以其文字作為清單來源 |
| 唯一清單輸出 | `TourFallbackFormatter::formatFromTourContext()` |
| `used_tour_fallback` | 固定清單路徑為 `false`（Gemini 失敗後才走 fallback，與 fixed list 路徑不同） |
| 回覆時間特徵 | 固定清單約 4–7 秒級（非 30s+ Gemini 改寫路徑） |

**Log 欄位位置：** `used_fixed_tour_list` 記錄於 `logs/saas_router.log`，非 `webhook.log`。

---

## 7. LINE 驗收結果

驗收環境：主機 A、LINE OA、sno=`e1fd133c7e8e45a1`（staging）。  
驗收訊息（標準三則）：

1. `幫我找釜山行程`
2. `幫我找 G331 行程`
3. `幫我找北京行程`

### 7.1 釜山

| 項目 | 結果 |
|------|------|
| traceId（固定清單） | `20260523_063958_1011b836`（06:40，`6fa7c9e` 後） |
| traceId（最終 UI `c036cf9`） | `20260523_081414_11d9f70b`（08:14；訊息為「請幫我找釜山的行程」，意圖同釜山） |
| `used_fixed_tour_list` | **true** |
| LINE API | **200** |
| 4 筆 schLinks URL | **是**（`agt.tw` 四筆全列） |
| 最終 UI（081414） | `🚩 `、emoji 空格、靠左、`──────────────`、無舊標籤／無 Gemini intro |

**備註：** `20260523_081004_6a806319`（08:10）已具 `548b8d5` emoji 排版，分隔線仍為較長之 `─────────────────`（部署 `c036cf9` 前）。

### 7.2 G331

| 項目 | 結果 |
|------|------|
| traceId | `20260523_064818_402bcd95`（06:48） |
| `used_fixed_tour_list` | **true** |
| LINE API | **200** |
| 行程表 | 多筆商品皆 URL-only（含 `drive.google.com` 等） |
| 無「另有 N 筆」 | **是** |

### 7.3 北京

| 項目 | 結果 |
|------|------|
| traceId | `20260523_064917_4736baf3`（06:49） |
| `used_fixed_tour_list` | **true** |
| LINE API | **200** |
| 多筆商品 | 5 筆等級之清單；`詳細內容` + `行程表` URL-only |
| search_url | `cloud_store_tourdate.php?...&keyword=%E5%8C%97%E4%BA%AC` |

### 7.4 彙總

| 檢查項 | 釜山 | G331 | 北京 |
|--------|------|------|------|
| `used_fixed_tour_list=true` | ✅ | ✅ | ✅ |
| LINE HTTP 200 | ✅ | ✅ | ✅ |
| 固定 formatter 開頭 | ✅ | ✅ | ✅ |
| 無 Gemini 商品清單改寫 | ✅ | ✅ | ✅ |
| `c036cf9` 短分隔線（建議再確認） | ✅（081414） | 建議 recycle 後重送確認 | 建議 recycle 後重送確認 |

### 7.5 `ai_error.log`

- 驗收期間（2026-05-23）：**無與本次 tour_query 相關之新錯誤**。
- 歷史 Gemini 503／model 404 等紀錄與本次 fixed formatter 路徑無關。

---

## 8. 非阻斷項

| 項目 | 說明 |
|------|------|
| `tenant_service_limits` | `db_layer_fallback`：`SQLSTATE[42S02] ... 無效的物件名稱 'tenant_service_limits'` |
| 影響 | 不阻斷 tour_query、不影響 `used_fixed_tour_list` 與 LINE 200 回覆 |
| 建議 | 後續 DB migration／表建立時一併處理，不納入本次 LINE formatter sign-off 阻斷條件 |

---

## 9. 最終 sign-off 結論

### 9.1 技術結論

1. **schLinks 端到端已成功：** Host B → Normalizer → Context → Fixed Formatter → LINE，URL 保留且 URL-only 全列。  
2. **Gemini 不再改寫商品清單：** `6fa7c9e` 起 `used_fixed_tour_list=true`，清單由 `TourFallbackFormatter` 唯一產出。  
3. **UI 已穩定：** `52d7cc9` → `548b8d5` → `c036cf9` 完成 emoji、靠左、售價標籤、短分隔線；log `20260523_081414_11d9f70b` 已驗證最終版面。  

### 9.2 Staging sign-off 狀態

| 狀態 | 說明 |
|------|------|
| **已可進入 staging sign-off** | 架構、fixed list、schLinks、三目的地 fixed list 路徑均已驗證 |
| **建議補充（非阻斷）** | 於 `c036cf9` deploy + App Pool recycle 後，再送標準三則（釜山／G331／北京），確認三則皆含 `──────────────` 且 LINE 客戶端分隔線不換行 |

### 9.3 部署後快速檢查清單

1. `git log -1 --oneline` → 應為 `c036cf9`（或更新之 formatter 相關 commit）。  
2. `Restart-WebAppPool -Name "DefaultAppPool"`  
3. LINE 送三則標準訊息。  
4. 查 `saas_router.log`：`used_fixed_tour_list:true`。  
5. 查 `webhook.log`：`🚩 `、`📅 最近出團：`、`──────────────`、無「另有」「精彩行程點我」。  

---

## 附錄 A：相關測試（參考，本文件不修改測試）

| 測試檔 | 涵蓋 |
|--------|------|
| `tests/test_gemini_retry_and_fallback.php` | `TourFallbackFormatter` 排版與標籤 |
| `tests/test_tour_line_reply_composer.php` | `used_fixed_tour_list`、Gemini 未呼叫 |
| `tests/api_gateway/test_host_b_response_normalizer_schedule_urls.php` | schLinks 保留 + formatter 輸出 |

執行範例（主機 A）：

```text
C:\Web\xampp\php\php.exe tests\test_gemini_retry_and_fallback.php
C:\Web\xampp\php\php.exe tests\test_tour_line_reply_composer.php
C:\Web\xampp\php\php.exe tests\api_gateway\test_host_b_response_normalizer_schedule_urls.php
```

---

## 附錄 B：文件變更記錄

| 日期 | 說明 |
|------|------|
| 2026-05-23 | 初版：LINE fixed formatter 最終 sign-off 整理 |
