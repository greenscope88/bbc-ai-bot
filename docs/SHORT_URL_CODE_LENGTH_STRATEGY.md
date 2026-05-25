# 短網址 Code 長度策略

**主機：** 103.1.222.14（主機 A）  
**文件類型：** 產品 / 技術策略紀錄  
**建立日期：** 2026-05-25  
**狀態：** 正式策略（取代 Phase 1C「強制 6 碼」方案）

---

## 1. 目前正式策略

| 原則 | 說明 |
|------|------|
| 維持既有 **5 碼** code pool | `bs_ShortUrl` 現況以 5 碼英數為主；LINE / BBC AI 新短網址沿用既有 `ShortUrl_Add()` 邏輯 |
| 舊碼 **永久有效** | 已發行之 `https://bbcshops.com/{code}`、`bonusmee.com/{code}` 不因新策略失效 |
| **不回收** | 不刪除、不清空已指派 `url` 的列 |
| **不覆蓋** | 不因升碼而改寫既有 code 或長網址對應 |
| **不強制轉 6 碼** | Phase 1C「LINE 僅能產生 6 碼」已 **rollback**；現行為 Phase 1B-A |

**程式現況（Phase 1B-A）：**

- `SHORT_URL_ENABLED` →「更多行程 & 出團日」→ `ShortUrlService::toPublicShortUrl()`
- `SHORT_URL_ITEM_LINKS_ENABLED` →「詳細內容」→ `ShortUrlService::toPublicShortUrlForItemLink()`
- 底層呼叫 `ShortUrl_Add()`；同 URL 可重用既有 code（含 5 碼）
- 失敗時 **fail-open** 回傳長網址

---

## 2. 未來升碼策略

採 **漸進加長、舊長度永久可用**，非一次性強制升級。

| 階段 | 觸發條件（規劃） | 行為 |
|------|------------------|------|
| **5 碼** | 現況 | 預設池；持續使用直至可用空列接近不足 |
| **6 碼** | 5 碼 `url IS NULL` 空池低於營運門檻 | **新增** 6 碼空列（INSERT），新配發優先或僅用 6 碼池 |
| **7 碼** | 6 碼空池不足 | 同上，再加 7 碼池 |
| **8 碼** | 7 碼空池不足 | 同上，再加 8 碼池 |

**重要：**

- 5 / 6 / 7 / 8 碼 **可並存**；`redirect2.php` 依 `code` 查詢，**不區分長度**
- 舊長度 code **永久繼續可用**（例如既有 `EIU45` 仍為 5 碼）
- 升碼時 **不刪除** 舊長度列、**不強制** 將舊 URL 改綁到新 code

---

## 3. 不做事項

- 不刪除 5 碼資料
- 不強制所有新短網址改 6 碼（Phase 1C 已取消）
- 不做 `bs_ShortUrl` **schema migration**
- 不做 **domain + code** migration（仍為全域 `code` 唯一）
- 不修改 `ShortUrl.php` / `ShortUrlDB.php` / `redirect2.php` 作為升碼前提（除非另案評估）

---

## 4. 未來實作方向（建議）

於 `ShortUrlService`（或後續 `ShortUrlCodeAllocator`）：

1. **自動偵測** 各長度可用空池（`url IS NULL AND LEN(code)=N`）
2. **優先使用最短可用長度**（例如先 5 → 再 6 → 再 7 → 再 8）
3. 該長度空池不足時 **自動升級** 下一長度；仍 fail-open 若全池耗盡
4. 查詢既有 URL 時：可依「由短到長」重用已存在列，**不** 因存在 5 碼而強制配 6 碼（除非產品另訂「僅新長度」規則）

**與 Phase 1C 差異：**

| 項目 | Phase 1C（已取消） | 建議未來 |
|------|-------------------|----------|
| 新 LINE 連結 | 強制 6 碼 | 依池況用最短可用長度 |
| 同 URL 已有 5 碼 | 強制另配 6 碼 | 重用 5 碼 |
| 5 碼池為 0 | fail-open | 自動嘗試 6/7/8 或 fail-open |

---

## 5. 相關文件

- `docs/LINE_SHORTURL_PHASE1A_COMPLETION_REPORT.md` — search_url 短網址化
- `docs/BBCSHOPS_SHORTURL_PHASE2B_2C_COMPLETION_REPORT.md` — Apache `/{code}` Rewrite
- Phase 1B-A — 詳細內容短網址（`SHORT_URL_ITEM_LINKS_ENABLED`）

---

*文件版本：v1 — 2026-05-25（Phase 1C rollback 後）*
