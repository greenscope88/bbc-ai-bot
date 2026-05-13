# API Gateway Phase 2 — Step 2-D Front-Controller Rewrite 設計

**專案根目錄：** `C:\bbc-ai-bot`  
**文件日期：** 2026-05-13  
**文件性質：** 設計文件（僅規劃，不實作）

---

## 1. Step 2-D 目標

- 規劃讓 `/bbc-ai-gateway-test/*` 的所有請求都能進入 `index.php`（front-controller）。
- 讓 `/not-found` 這類目前由 Apache 回應的錯誤路徑，後續可回 Gateway 標準 JSON error。
- 本階段僅產出設計，不進行任何 rewrite/PHP/Apache 實作變更。

---

## 2. 現況摘要

- `/bbc-ai-gateway-test/index.php` 可正常回 JSON。
- `/bbc-ai-gateway-test/index.php?sno=test001&service=ping` 可正常解析 query。
- `/bbc-ai-gateway-test/not-found` 目前由 Apache 回 `403` HTML。
- `/www/bbc-ai-gateway-test/index.php` 是錯誤路徑（不應使用）。

---

## 3. Isolated Front-Controller 設計範圍

- 只限 ` /bbc-ai-gateway-test/ ` 目錄與其子路徑。
- 不影響正式 LINE webhook。
- 不影響正式 SaaS router。
- 不影響主機 B API。
- 不影響其他既有站台 rewrite 規則。

---

## 4. Rewrite 設計草案

- 建議在 `C:\Web\xampp\htdocs\www\bbc-ai-gateway-test\.htaccess` 建立**局部 rewrite**。
- 不建議直接擴大修改根目錄 `C:\Web\xampp\htdocs\www\.htaccess` 的全站規則。
- 草案（設計用，不在本階段套用）：

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L,QSA]
```

設計意圖：
- 既有檔案/目錄維持原樣。
- 其餘未知路徑統一送入 `index.php`，由 Gateway 決定回應（含 JSON error）。

---

## 5. `index.php` 後續需支援的 Request Context

- `REQUEST_METHOD`
- `REQUEST_URI`
- `QUERY_STRING` / `$_GET`
- path info 或原始路徑（供 route 判斷）
- `traceId`
- `errorCode`
- HTTP status code（由應用層回應策略決定）

---

## 6. 標準 JSON Error 規劃

- `404 / ROUTE_NOT_FOUND`
- `405 / METHOD_NOT_ALLOWED`
- `400 / BAD_REQUEST`
- `500 / GATEWAY_INTERNAL_ERROR`

建議維持與 Phase 1 `ErrorResponseBuilder` 一致的欄位形狀：
- `success`
- `errorCode`
- `message`
- `traceId`
- `timestamp`
- `details`

---

## 7. 測試案例規劃

- `/index.php`
- `/index.php?sno=test001&service=ping`
- `/not-found`
- `/unknown/path`
- `/health`
- `POST` method
- `OPTIONS` method

測試重點：
- 狀態碼是否符合預期。
- JSON 格式是否一致。
- `traceId` 是否存在且可追蹤。

---

## 8. Rollback 策略

- 刪除 isolated 目錄下的 `.htaccess`（或停用該 rewrite）即可回復。
- 不動正式站台 rewrite。
- 不動 Apache vhost/ssl。
- 不動 DB。

---

## 9. 風險與注意事項

- 不可讓局部 rewrite 影響正式站。
- 不可讓測試入口接正式流量。
- 不可把錯誤路徑導向正式 LINE/Gemini。
- 不可寫入正式 log 或 DB。

---

## 10. 建議下一步

1. **Step 2-E：** 建立 isolated `.htaccess` + front-controller 測試（僅測試目錄內生效）。
2. 或先建立 Phase 2 中期完成報告，再決定是否進入 Step 2-E 實作。

---

*本文件為 API Gateway Phase 2 Step 2-D 設計輸出。*

