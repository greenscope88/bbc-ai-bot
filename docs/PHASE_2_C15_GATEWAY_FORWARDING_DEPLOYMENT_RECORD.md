# Phase 2-C.15 Gateway Forwarding Deployment Record

## 1. 修改原因

Hybrid Smart Search 在 Host A 已正確產生 `TourDateS` / `TourDateE` / `AmountMax` 並寫入 `request_url`，但公開 gateway 入口 `bonusmee.com/api/gateway/tour/search.php` 僅將 `sno`、`keyword`、`page`、`pageSize` 傳入 `TourSearchService::search()`，導致篩選參數在 gateway 層被丟棄。Direct Host B 呼叫正常，gateway 路徑仍回傳未篩選結果（total 7252，含 12/18、71,800 等違規品項）。

Phase 2-C.15 於 gateway 入口收集 allowlisted query 參數並轉交 `TourSearchService`（搭配 `core/tour_search_service.php` 的 `$extraClientParams`）。

## 2. 原始檔路徑（線上生效）

```
C:\Web\xampp\htdocs\www\api\gateway\tour\search.php
```

公開 URL：`https://bonusmee.com/api/gateway/tour/search.php`

## 3. 備份檔路徑（bbc-ai-bot repo 內）

```
C:\bbc-ai-bot\backups\gateway\search.php.phase2c15.20260527_122905.bak
```

備份時間：2026-05-27 12:29:05 (Asia/Taipei, 主機本地時間)

## 4. SHA256

| 檔案 | SHA256 |
|------|--------|
| 原始檔（xampp gateway） | `A938FAAE51E75644676FDA80E079620D0986343ADC964B76BBC589DF34B43ED5` |
| 備份檔（bbc-ai-bot/backups） | `A938FAAE51E75644676FDA80E079620D0986343ADC964B76BBC589DF34B43ED5` |

**原檔與備份檔 SHA256 一致。**

## 5. 本次修正功能

- **gateway forwarding `TourDateS`**：自 `$_GET` 收集並傳入 `TourSearchService`
- **gateway forwarding `TourDateE`**：同上
- **gateway forwarding `AmountMax`**：同上（亦支援 camelCase `dateFrom` / `dateTo` / `priceMax` 經 `HostBTourSearchParamMapper` 對應）

實作摘要：

- 新增 `gateway_public_collect_client_params_from_get()`（allowlist 對齊 `HostBTourSearchParamMapper`）
- `TourSearchService::search(..., $extraClientParams)` 合併後由 `TourSearchRequestBuilder` 轉發至 Host B

## 6. 驗收結果（Phase 2-C.14 / 2-C.15 / 2-C.16 模擬）

| 項目 | 修正前 | 修正後 |
|------|--------|--------|
| gateway + filters **total** | 7252 | **73** |
| 是否出現 **12/18** | 是 | **否** |
| 是否出現 **71,800** | 是 | **否** |
| direct Host B + 相同 params | 73 | 73（與 gateway 一致） |
| `filter_likely_effective`（hybrid debug） | false | **true** |

測試查詢：「六月底東京三萬以下」→ `keyword=東京`, `TourDateS=2026-06-21`, `TourDateE=2026-06-30`, `AmountMax=30000`

## 7. Git 範圍說明

- **本檔（xampp gateway）目前不在 `C:\bbc-ai-bot` git repo 內**，無法與應用程式碼同一 commit 追蹤。
- 相關 repo commit：`a97e87b` — `feat(hybrid-search): enable runtime hybrid filtering and gateway forwarding`
  - 已納入：`config/config.php`（Phase 2-C.13 staging hybrid enable）、`core/tour_search_service.php`（`$extraClientParams`）
  - **未納入**：xampp 上的 `search.php`（路徑在 repo 外）

## 8. 後續建議

1. **將 `www` gateway entry 納入獨立版控**（例如 `C:\Web\xampp\htdocs\www` 建立 git，至少追蹤 `api/gateway/tour/search.php`）。
2. **或建立 repo mirror / deploy source**：在 `bbc-ai-bot` 維護 `deploy/gateway/tour/search.php` 正式副本，發版時同步至 xampp；本備份可作為 Phase 2-C.15 基線參照。
3. **staging LINE E2E（Phase 2-C.16）**：請在 gateway 修正後再送一次「六月底東京三萬以下」，以 `hybrid_search_api_debug.log` + `webhook.log` 完成正式簽核。

---

*Document: Phase 2-C.17 deployment record. No changes to live `search.php` in this step—backup and documentation only.*
