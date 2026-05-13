# API Gateway Tenant Mapping 安全設計文件

## 文件目的與範圍

本文件定義 BBC AI SaaS API Gateway 層級之 **Tenant Mapping** 安全模型：請求識別欄位（`sno`、`cid`、`depID`）與後端資料語意、資料表欄位之對應關係，以及交叉驗證原則。

**重要聲明（必讀）**

- 本文件僅做 **設計與規格說明**，**不執行任何 SQL**、**不建立或變更資料表**、**不修改正式環境之 PHP / ASP / .NET 程式**。
- 實際實作、DDL、程式變更應於後續獨立工作項中依變更流程進行。

---

## 名詞與識別欄位

| 欄位 | 語意（設計定義） | 主要用途 |
|------|------------------|----------|
| `sno` | 對應優惠券／租戶情境之 **門市編號** | Gateway 將請求綁定至正確門市／租戶脈絡 |
| `cid` | 對應 **優惠券序號**（coupon 識別） | 與 `bs_Coupon` 單筆記錄對應 |
| `depID` | **部門／組織** 識別 | 與門市所屬部門交叉驗證，降低偽造或錯配風險 |

---

## 資料表與欄位關聯（設計定義）

### 核心對應（Coupon 為 mapping 樞紐）

以下為本設計之 **核心 mapping**（邏輯關聯，非執行指令）：

| 請求／Token 概念 | 對應資料表.欄位 |
|------------------|-----------------|
| `sno` | `bs_Coupon.storeNo` |
| `cid` | `bs_Coupon.seqNo` |
| `depID` | `bs_Coupon.depID` |

### 門市與部門鏈結

- `bs_Coupon.storeNo` = `bs_store.uid`（以門市主檔 `uid` 與 coupon 之 `storeNo` 對齊）
- `bs_store.depID` 可用於與請求或 coupon 帶入之 `depID` 做 **交叉驗證**（見下節）

---

## 完整 Mapping Chain（由請求到可驗證租戶脈絡）

### 鏈路一：`sno` → 門市 → 部門

```
sno
  → bs_Coupon.storeNo（以 cid 定位 coupon 後取得 storeNo；見鏈路二）
  → bs_store.uid
  → bs_store.depID
```

說明：`sno` 在設計上與 `bs_Coupon.storeNo` 對齊；透過 coupon 與 store 主檔關聯後，可取得 `bs_store.depID` 供驗證。

### 鏈路二：`cid` → Coupon 主鍵語意

```
cid
  → bs_Coupon.seqNo
```

說明：`cid` 對應 `bs_Coupon.seqNo`，用於唯一定位（或篩選）coupon 記錄，再讀取 `storeNo`、`depID` 等欄位。

### 建議驗證順序（邏輯）

1. 以 `cid` 解析 `bs_Coupon`（`seqNo`）。
2. 確認該筆 coupon 之 `storeNo` 與請求 `sno` **一致**（受保護 API 須同時帶 `sno` 與 `cid`，見下節「請求參數與邊界規則」）。
3. 以 `storeNo` 解析 `bs_store`（`uid`）；若無對應列，為資料鏈斷裂（見邊界規則）。
4. 比對 `bs_Coupon.depID` 與 `bs_store.depID` **一致**（coupon／store 內部一致）。
5. 若請求**另帶** `depID`，則須與上述兩者**皆一致**（三者閉合）；若請求**未帶** `depID`，則不強制要求客戶端重複傳送，僅執行步驟 4。

---

## 請求參數與邊界規則（凍結）

以下與 `API_GATEWAY_ERROR_AND_AUDIT_LOG_DESIGN.md` 錯誤碼總表、`API_GATEWAY_MIDDLEWARE_AND_TESTCASE_PLAN.md` E2E 矩陣一致。

### `sno`、`cid`、`depID` 命名

- 全文件與對外 API 一律使用 **`sno`**、**`cid`**、**`depID`**（大小寫固定如此；**不使用** `cID` 或其他變體）。

### 是否允許僅 `cid` 不帶 `sno`

- **不允許**（受保護、須 tenant mapping 之 API）。
- 僅帶 `cid`、缺少 `sno` → `GW_TENANT_MAPPING_FAILED`（`details.subCode` 可標示 `MISSING_SNO`，實作選填）。

### `depID` 是否必填（請求側）

- **選填**。未帶時：Gateway 仍必須驗證 **`bs_Coupon.depID` = `bs_store.depID`**（資料內部一致）。
- 有帶時：請求 `depID` 必須與 `bs_Coupon.depID` 及 `bs_store.depID` **皆相同**，否則為 `GW_TENANT_DEPID_VERIFICATION_FAILED`。

### `cid` 不存在

- 以 `cid` 對應 `bs_Coupon.seqNo` 查無符合業務規則之記錄 → `GW_TENANT_CID_NOT_FOUND`（HTTP 見錯誤碼總表）。

### `sno` / `cid` 不匹配

- 已定位 coupon，但請求 `sno` ≠ 該筆 `bs_Coupon.storeNo` → `GW_TENANT_SNO_CID_MISMATCH`。

### `bs_store` 查無（coupon 有 `storeNo` 但門市主檔無 `uid`）

- 視為租戶鏈斷裂／資料完整性問題 → **`GW_TENANT_STORE_NOT_FOUND`**（與「客戶傳錯參數」區隔；HTTP 見總表）。

### `bs_Coupon.depID` 與 `bs_store.depID` 不一致（資料品質）

- coupon 與 store 主檔部門欄位互斥 → **`GW_TENANT_DEPID_VERIFICATION_FAILED`**（可於 `details.subCode` 區分 `REQUEST_MISMATCH` / `COUPON_STORE_MISMATCH`，實作選填）。

### `bs_Coupon.seqNo`（`cid`）唯一性假設

- 設計假設：**同一時間僅一筆有效 coupon 對應該 `seqNo`**。若資料庫出現多筆，Gateway 應 **拒絕** 並記錄 `GW_TENANT_MAPPING_FAILED`（建議 `details.subCode` = `AMBIGUOUS_CID`），不得任意挑選一筆。

---

## 安全設計要點

- **最小揭露**：Gateway 僅依 mapping 結果決定是否放行；不應在錯誤訊息中回傳完整內部鍵值或堆疊細節（細節規格見 `API_GATEWAY_RESPONSE_AND_LOG_SCHEMA_DESIGN.md`）。
- **防錯配**：`sno` 與 `cid` 必須指向同一筆業務上合理之 coupon／store 組合；不一致應拒絕並記錄（錯誤碼見 `API_GATEWAY_ERROR_AND_AUDIT_LOG_DESIGN.md`）。
- **交叉驗證**：`depID` 不得僅信任請求標頭或 body；應與 `bs_store.depID`（經由已驗證之 `storeNo`）比對。
- **不可略過**：Tenant mapping 應為受保護 API 之 **必要前置步驟**（Middleware 流程見 `API_GATEWAY_MIDDLEWARE_AND_TESTCASE_PLAN.md`）。

---

## 與其他設計文件之關係

| 主題 | 文件 |
|------|------|
| 錯誤碼總表、HTTP、稽核欄位、寫入時機 | `API_GATEWAY_ERROR_AND_AUDIT_LOG_DESIGN.md` |
| 統一回應 JSON、Log 型別、`success`／`result` 分工、索引 | `API_GATEWAY_RESPONSE_AND_LOG_SCHEMA_DESIGN.md` |
| Middleware 流程、測試矩陣、實作順序 | `API_GATEWAY_MIDDLEWARE_AND_TESTCASE_PLAN.md` |
| 實作前交叉檢查與就緒聲明 | `API_GATEWAY_IMPLEMENTATION_PRECHECK.md` |

---

## 文件維護

- 若資料模型變更（例如 coupon 或 store 主鍵語意調整），應同步修訂本文件之 mapping 定義與驗證鏈路。
- 修訂時仍應遵守「設計文件與實作分離」：本文件不替代程式碼 review 或 DBA 變更單。
