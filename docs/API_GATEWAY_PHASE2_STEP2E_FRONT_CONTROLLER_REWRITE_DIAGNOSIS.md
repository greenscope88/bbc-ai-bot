# API Gateway Phase 2 — Step 2-E Front-Controller Rewrite 診斷報告

**專案根目錄：** `C:\bbc-ai-bot`  
**文件日期：** 2026-05-13  
**範圍：** Isolated HTTP test entry（`bbc-ai-gateway-test`）front-controller `.htaccess` 行為與 Apache 403 診斷

---

## 1. Step 2-E 測試目標

- 嘗試讓下列路徑經由 isolated front-controller 進入 `index.php`，並回傳 Gateway 標準 JSON（含錯誤情境時之 JSON error）：
  - `/bbc-ai-gateway-test/not-found`
  - `/bbc-ai-gateway-test/unknown/path`
  - `/bbc-ai-gateway-test/health`
- 預期：上述路徑不應再由 Apache 回傳純 HTML 403，而應由 Gateway 應用層處理。

---

## 2. 測試結果

| 測試 URL | 結果 |
|----------|------|
| `/bbc-ai-gateway-test/index.php` | **成功**：HTTP **200**，`Content-Type: application/json; charset=utf-8`，有 **`traceId`** |
| `/bbc-ai-gateway-test/index.php?sno=test001&service=ping` | **成功**：HTTP **200**，JSON，query 可解析，有 **`traceId`** |
| `/bbc-ai-gateway-test/not-found` | **失敗**：仍為 Apache **403**，`text/html` 錯誤頁，**無** Gateway JSON / **`traceId`** |
| `/bbc-ai-gateway-test/unknown/path` | **失敗**：同上，Apache **403** HTML |
| `/bbc-ai-gateway-test/health` | **失敗**：同上，Apache **403** HTML |

---

## 3. Isolated `.htaccess` 最終測試內容

實際路徑：`C:\Web\xampp\htdocs\www\bbc-ai-gateway-test\.htaccess`

```apache
RewriteEngine On
RewriteBase /bbc-ai-gateway-test/

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^.*$ index.php [L,QSA]
```

（註：上述規則在測試中 **未能** 使 `/not-found` 等路徑進入 `index.php`；詳見診斷。）

---

## 4. 診斷結果

- **`mod_rewrite`：** 已在 `C:\Web\xampp\apache\conf\httpd.conf` 載入 `rewrite_module`（啟用）。
- **`AllowOverride`：** `C:/Web/xampp/htdocs/www` 的 `<Directory>` 為 **`AllowOverride All`**，子目錄 `.htaccess` 理論上可套用 rewrite。
- **`Options`：** 含 **`FollowSymLinks`**（未強制 `Indexes`，與本問題關聯較低）。
- **Isolated `.htaccess`：** 檔名為 **`.htaccess`**（非 `.htaccess.txt`），內容與上節一致。
- **實體檔案：** `bbc-ai-gateway-test` 目錄下 **無** 名為 `not-found`、`unknown`、`health` 之實體檔案或資料夾（僅 `index.php` 與 `.htaccess`）。
- **研判主因：** `httpd.conf` 在 `<Directory "C:/Web/xampp/htdocs/www">` **之前**的全域 `<FilesMatch>` 使用 **`deny from all`**（`mod_access_compat`），對 **無白名單副檔名** 之 URL 最後路徑段（例如 `not-found`）可能先被拒絕為 **403**，導致 **無法進入** 由子目錄 `.htaccess` 將請求改寫至 `index.php` 的流程。

---

## 5. 風險判斷

- **不建議**在未經完整影響評估下修改 **全域** `httpd.conf` 之 `FilesMatch` / `deny` 規則（可能影響整站靜態與動態路徑）。
- **不建議**為 isolated 測試擴大 rewrite 範圍至根目錄或其他正式路徑。
- **不得**影響正式 LINE webhook、正式 SaaS router、主機 B API 與 production 流量。

---

## 6. 建議結論

- **Step 2-E** 以「診斷完成」暫告一段落：isolated `.htaccess` front-controller **在現有全域存取規則下無法達成** REST 風格無副檔名路徑進入 `index.php` 的目標。
- **Phase 2** 仍可視為 **HTTP `index.php` 入口** 已成功（200、JSON、`traceId`、query 解析正常）。
- 若未來需支援 **REST-style path**，應 **另開** Apache policy / routing 設計（含是否調整全域 `FilesMatch`、或改以獨立 vhost / 獨立 DocumentRoot 隔離測試站）。
- **短期測試**可採 **`index.php?path=/not-found`**（或等價 query）方式驗證路由邏輯，**不碰** Apache 全域規則（實作時若需改 PHP，應另排任務與審核）。

---

*本文件由 API Gateway Phase 2 Step 2-E 診斷任務產出。*
