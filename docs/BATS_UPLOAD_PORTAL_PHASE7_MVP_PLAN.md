# BDS Upload Portal — Phase 7-1 MVP Plan

**專案根目錄：** `C:\bbc-ai-bot`  
**文件版本：** 2026-06-05（Phase 7-0c～7-0e.2 Final）  
**性質：** L2 實作規劃（SSOT First / DDD）。**不**實作程式、**不**修改 runtime。

**前置審計：** Phase 7-0b Login Reuse Audit；Phase 7-0c Canonical Domain SSOT；Phase 7-0d Shared Upload Portal SSOT；Phase 7-0e UI／Field Spec SSOT；Phase 7-0e.1 UI Enhancement SSOT；Phase 7-0e.2 UI Finalization SSOT。

---

## 1. 文件目的

本文件將 **Tenant／Shared 雙 Upload Portal** 之正式網域、**Legacy 品牌登入重用**、**權限 gate**、**Pilot 對照** 與 **跨域安全邊界** 定案為 Phase 7 實作依據。

| 層級 | 文件 | 本文件角色 |
|------|------|------------|
| **L1 SSOT** | `BATS_DATA_SYNC_POLICY.md` §17、§19 | 同步觸發與 Update Entry 原則 |
| **L1 SSOT** | `BATS_DATA_SOURCE_REGISTRY.md` §10.6 | Registry／Pilot／Shared scope 對照 |
| **L1 SSOT** | `BATS_DATA_OWNERSHIP_POLICY.md` §3.7 | Upload Portal 寫入權限邊界 |
| **L2 規劃** | **本文件** | Phase 7-1～7-3 URL、Auth、Gate、**UI Field Spec**、安全約束 |
| **L3 實作** | `www/bds/upload.php`、`www/bds/shared_upload.php` + `bbc-ai-bot` BDS core | **未開始** |

---

## 2. Dual Upload Portal Model（Phase 7-0d）

Structured Knowledge 人類更新入口分為 **兩個獨立 Portal**；**不得** 混用單頁或跨層寫入。

| Portal | Canonical URL | Redirect-only | 寫入層級 | Phase |
|--------|---------------|---------------|----------|-------|
| **Tenant Upload Portal** | `https://kowanbo.com/bds/upload.php` | `https://bbcshops.com/bds/upload.php` | `tenants/{sno}/knowledge/` | **7-1** |
| **Shared Upload Portal** | `https://kowanbo.com/bds/shared_upload.php` | `https://bbcshops.com/bds/shared_upload.php` | `shared/{industry_code}/knowledge/` | **7-2** |

**共同原則：**

- Auth：Legacy `brandlogin.php` + `TourBus*` session（kowanbo canonical）
- bbcshops.com：**僅 redirect**；**非** 登入域
- **禁止** 在文件或程式中記錄明文密碼；**禁止** hardcode 手機密碼 gate

---

## 3. Tenant Upload Portal（Phase 7-1 MVP）

### 3.1 Canonical URL

| 項目 | 值 |
|------|-----|
| **正式 URL** | `https://kowanbo.com/bds/upload.php` |
| **Redirect-only** | `https://bbcshops.com/bds/upload.php` → kowanbo canonical |
| **用途** | **Tenant Private Knowledge** Excel 上傳 |
| **狀態** | **SSOT 定案**；檔案 **尚未建立** |

### 3.2 為何採 kowanbo.com 為 canonical

| 理由 | 說明 |
|------|------|
| **Legacy session 相容** | 品牌後台沿用 `TourBus*` PHP session；`memStore.php` 同模式 |
| **brandlogin 慣例** | `brandlogin.php` 與 `top.php` 以 `ChkURLDomain("kowanbo.com")` 將品牌後台 canonical 至 kowanbo |
| **單一登入域** | 避免跨 host 重複登入與權限不一致 |

---

## 4. Login Flow（Tenant & Shared 共用 Auth）

### 4.1 Tenant 正式流程

```text
使用者 → https://kowanbo.com/bds/upload.php
              │
              ├─ 已登入（IsLogin4Store）
              │       → 讀 session TourBusstoreNo → Pilot gate → Upload UI
              │
              └─ 未登入
                      → https://kowanbo.com/brandlogin.php?retUrl=/bds/upload.php
                              → memberC_ajax.php (cmd=login4Store)
                              → 寫入 TourBus* session
                              → location.href = /bds/upload.php
```

### 4.2 Shared 正式流程

```text
使用者 → https://kowanbo.com/bds/shared_upload.php
              │
              ├─ 已登入（IsLogin4Store）
              │       → Management Center gate（§9）→ industry_code=travel → Upload UI
              │
              └─ 未登入
                      → https://kowanbo.com/brandlogin.php?retUrl=/bds/shared_upload.php
                              → memberC_ajax.php (cmd=login4Store)
                              → 寫入 TourBus* session
                              → location.href = /bds/shared_upload.php
```

### 4.3 Legacy Auth Reuse Decision

| 項目 | 決策 |
|------|------|
| **參考頁** | `https://kowanbo.com/view/store/memStore.php` |
| **守衛函式** | `IfNotLoginGoHomeAndRet('', '/brandlogin.php', $_SERVER['REQUEST_URI'])` |
| **登入判定** | `IsLogin4Store()` — `TourBusaccountNo` / `TourBusaccountId` / `TourBusstoreNo` |
| **登入 API** | `/view/member/memberC_ajax.php` — `cmd=login4Store` |
| **Session 前綴** | `TourBus`（`_PreFix4TourBus`） |
| **Tenant 識別（MVP）** | `$_SESSION['TourBusstoreNo']` → 對照 `config/tenant_registry.php` |

**不重複發明帳密系統；** Phase 7 **不** 引入 API Key 給人類操作頁；**不** 在程式或文件硬編碼密碼／手機密碼 gate。

---

## 5. 為何不直接使用 bbcshops.com 作為登入域

Phase 7-0b 唯讀審計結論：

| 項目 | 說明 |
|------|------|
| **PHPSESSID host-only** | `session.cookie_domain` 為空 → cookie **不跨 registrable domain** |
| **kowanbo ≠ bbcshops** | 在 `kowanbo.com` 登入後，`bbcshops.com` **讀不到** 同一 `TourBus*` session |
| **ChkURLDomain** | legacy `top.php` 會將非 kowanbo 請求 302 至 `kowanbo.com` |
| **結論** | `bbcshops.com/bds/upload.php` **不能** 直接承接 `kowanbo.com/brandlogin.php` 登入後的 session |

---

## 6. bbcshops.com 角色（Phase 7 MVP）

| 角色 | 說明 |
|------|------|
| **Phase 7** | **非登入域**；**非** Upload Portal canonical URL |
| **允許用途** | **Redirect-only** 對外入口 |
| **Tenant redirect** | `https://bbcshops.com/bds/upload.php` → `https://kowanbo.com/bds/upload.php` |
| **Shared redirect** | `https://bbcshops.com/bds/shared_upload.php` → `https://kowanbo.com/bds/shared_upload.php` |
| **既有職責不變** | 短網址 public base 仍為 bbcshops |

**禁止（MVP）：** 在 bbcshops.com 上實作獨立登入或假設已繼承 kowanbo session。

---

## 7. travel_b Pilot Mapping（Tenant Portal Only）

Phase 7-1 Tenant Portal **僅** 開放單一 pilot tenant。

| 欄位 | 值 | 來源 |
|------|-----|------|
| **tenant_key** | `travel_b` | `config/tenant_registry.php` |
| **sno** | `5f99b8d665e8444d` | Registry wire authority；GCS `tenants/{sno}/` |
| **depID** | `888` | Legacy `constant("_depID")` |
| **storeNo** | `6180` | Session `TourBusstoreNo`；Registry `storeNo` |
| **Pilot gate** | `TourBusstoreNo === 6180` | 非 6180 → 拒絕上傳（403／友善錯誤頁） |

**映射鏈：**

```text
TourBusstoreNo (6180) → tenant_key travel_b → sno 5f99b8d665e8444d → GCS tenants/5f99b8d665e8444d/knowledge/
```

---

## 8. Security — retUrl Allowlist

Legacy `brandlogin.php` 以 **明文** `retUrl` 做登入後導向；兩個 Portal **必須** 強化：

| 規則 | 說明 |
|------|------|
| **Allowlist 路徑** | `retUrl` 僅允許 `/bds/` 開頭之 **相對路徑** |
| **MVP 允許清單** | `/bds/upload.php`、`/bds/shared_upload.php` |
| **禁止** | 完整外部 URL、`//`、`..`、非 allowlist 路徑 |
| **預設** | 非法 `retUrl` → 依入口頁預設（tenant→`/bds/upload.php`；shared→`/bds/shared_upload.php`） |
| **Open Redirect** | 不得接受任意 `retUrl` 導向第三方 |

**實作位置（Phase 7-1／7-2）：** 各 Portal 守衛 **與** `brandlogin` 消費端（若擴充 server-side 驗證）。

---

## 9. Shared Upload Portal（Phase 7-2 MVP）

### 9.1 Canonical URL & Redirect

| 項目 | 值 |
|------|-----|
| **Canonical URL** | `https://kowanbo.com/bds/shared_upload.php` |
| **Redirect-only** | `https://bbcshops.com/bds/shared_upload.php` → kowanbo canonical |
| **用途** | BBC 管理中心上傳 **Industry / Global Shared Knowledge** Excel |
| **Phase** | **7-2**（獨立於 Tenant Portal） |

### 9.2 Auth

| 項目 | 決策 |
|------|------|
| **機制** | 與 Tenant Portal 相同 — Legacy `brandlogin.php` + `TourBus*` session |
| **未登入導向** | `https://kowanbo.com/brandlogin.php?retUrl=/bds/shared_upload.php` |
| **禁止** | 明文密碼寫入文件或程式；hardcode 手機密碼 gate |

### 9.3 Permission Gate — BBC 管理中心

Shared Upload Portal **僅** 供 BBC 管理中心使用；以 **權限識別符** gate，**非** 密碼 gate。

| 項目 | 正式值 |
|------|--------|
| **Gate 類型** | Management Center **sno** 識別（wire authority） |
| **允許 sno** | `cff796a33d94ea31` |
| **解析方式** | 登入後由 session `TourBusstoreNo` → `encryptStr(storeNo, _DataKey)` 或直接對照已登錄之 management center 對照表（實作時 **不得** 比對明文密碼） |
| **拒絕** | 非管理中心帳號 → 403／友善錯誤頁；**不得** 降級為 Tenant upload |

### 9.4 Scope（MVP）

| 項目 | 正式值 |
|------|--------|
| **層級** | Industry Shared Knowledge |
| **MVP industry_code** | `travel` |
| **GCS 目標** | `shared/travel/knowledge/` |
| **Contract** | `BATS_SHARED_KNOWLEDGE_CONTRACT.md` |
| **Deferred** | `shared/global/knowledge/`（Global Shared upload）— Phase 7-2+ 或獨立子階段 |

### 9.5 寫入邊界（Forbidden）

| 禁止 | 說明 |
|------|------|
| Tenant → shared | Tenant Upload Portal **不得** 寫入 `shared/` |
| Shared → tenant | Shared Upload Portal **不得** 寫入 `tenants/` |
| 密碼 gate | **不得** 在程式或文件記錄密碼；**不得** hardcode 手機密碼比對 |
| 跨 industry | MVP 僅 `travel`；不得寫入 `shared/hotel/` 等未開放 industry |

**映射鏈（MVP）：**

```text
Management Center (sno cff796a33d94ea31) + industry_code travel
    → shared/travel/knowledge/
```

---

## 10. Future Option — Phase 7-x Login Bridge Token（Deferred）

若未來產品要求 **bbcshops.com 為正式操作域**（使用者全程不離開 bbcshops），另開子 Phase：

| 項目 | 說明 |
|------|------|
| **名稱** | Phase 7-x Login Bridge Token |
| **機制** | kowanbo 登入成功後簽發 **短效 signed token** → bbcshops `upload.php` server-side 驗證 |
| **狀態** | **Deferred** — Phase 7-1／7-2 **不實作** |
| **觸發條件** | 產品明確要求 bbcshops 為操作域且 redirect 不足 |

---

## 11. Phase 7 Implementation Phases

| Phase | 名稱 | 交付摘要 | 狀態 |
|-------|------|----------|------|
| **7-1** | **Tenant Upload Portal MVP** | `upload.php`、travel_b gate、`tenants/5f99b8d665e8444d/knowledge/`、6A BDS core | 未開始 |
| **7-2** | **Shared Upload Portal MVP** | `shared_upload.php`、management center sno gate、`shared/travel/knowledge/`、Shared Contract | 未開始 |
| **7-3** | **Production Hardening** | Audit log、recovery、retUrl 強化、可選 bbcshops redirect、營運 runbook | 未開始 |
| **7-x** | Login Bridge Token | 跨域 bbcshops 操作域 | **Deferred** |

### 11.1 Phase 7-1 Scope（Tenant）

| # | 交付項 |
|---|--------|
| 1 | `www/bds/upload.php` — session 守衛 + `storeNo=6180` gate |
| 2 | Upload UI（§13.2 欄位規格）+ POST → BDS pipeline（6A core） |
| 3 | GCS `tenants/5f99b8d665e8444d/knowledge/` + Read-back |
| 4 | Audit：`accountNo`、`storeNo`、`sno`、`tenant_key` |

**不做：** Shared upload；global shared；bbcshops 登入域。

### 11.2 Phase 7-2 Scope（Shared）

| # | 交付項 |
|---|--------|
| 1 | `www/bds/shared_upload.php` — session 守衛 + management center sno gate |
| 2 | Shared Upload UI（§13.3～§13.4）+ `BATS_SHARED_KNOWLEDGE_CONTRACT` validation |
| 3 | GCS `shared/travel/knowledge/` + Read-back |
| 4 | 強制邊界：不可寫 `tenants/` |

**不做：** `shared/global/knowledge/`（Deferred）；密碼 gate。

### 11.3 Phase 7-3 Scope（Hardening）

| # | 交付項 |
|---|--------|
| 1 | 上傳／同步 audit trail |
| 2 | 失敗 recovery 與營運 runbook 對齊 |
| 3 | retUrl allowlist server-side 強化 |
| 4 | 可選：`bbcshops.com` 雙 redirect 頁 |

---

## 13. Upload Portal UI / Field Spec（Phase 7-0e.2 Final SSOT）

> **Status: SSOT 定案（2026-06-05）** — Tenant／Shared **最終**可見欄位、欄位順序、Sync Version、同步狀態、UX 文案與後端解析邊界。  
> **7-0e.2 定案：** 新增 `目前 AI 使用版本`；成功／失敗訊息最終格式；Sync Version Contract；Metadata Source Priority。  
> **禁止：** 新增或修改 Host B SQL 欄位／Schema／同步版本資料表作為 UI 資料來源。

### 13.1 共通規則

#### 13.1.1 同步 Metadata 資料來源（Metadata Source Priority — 禁止 Host B SQL）

下列 **readonly** 欄位 **不得** 來自 Host B SQL 新增／修改欄位、Schema 變更或同步版本資料表：

| UI 欄位 | 後端鍵 |
|---------|--------|
| 上一次成功同步時間 | `finished_at`／`published_at`（最近一次 **success**） |
| 目前 AI 使用版本 | `sync_id` |
| 目前 AI 使用資料狀態 | 衍生自 GCS 現行 knowledge 與最近成功 `sync_id` 對照 |

| 優先序 | 來源 | 說明 |
|--------|------|------|
| **1** | GCS `sync_report.json` | 如 `tenants/{sno}/meta/sync_report.json`、`shared/{industry}/meta/sync_report.json` |
| **2** | GCS knowledge 物件 metadata | `updated`、`published_at`、`sync_id` 等 |
| **3** | Host A `var/bds/` report | fallback（營運／除錯） |
| **4** | Google Drive archive metadata | **僅**輔助；**不可** 作為 Runtime SSOT |

**禁止：**

- 新增 Host B SQL 欄位
- 修改 Host B SQL Schema
- 建立同步版本資料表

#### 13.1.2 同步版本（Sync Version / `sync_id` Contract）

| 項目 | 規格 |
|------|------|
| **UI 標籤** | 目前 AI 使用版本 |
| **後端鍵** | `sync_id` |
| **格式** | `SYNC-YYYYMMDD-HHMMSS` |
| **範例** | `SYNC-20260613-153025` |

**用途：**

| 用途 | 說明 |
|------|------|
| 客服確認 | 使用者回報問題時提供版本號 |
| Tenant 驗證 | 租戶確認 AI 所載入知識批次 |
| Read-back 驗證 | 上傳後 read-back 與 `sync_id` 對照 |
| 問題追蹤 | Audit／營運 log 關聯 |
| Versioning 對照 | GCS knowledge metadata 與 `sync_report` 一致 |

**顯示規則：**

| 狀態 | UI 文案 |
|------|---------|
| 有成功紀錄 | 顯示現行 GCS knowledge 對應之 `sync_id`（格式如上） |
| 無成功紀錄 | `尚無成功同步紀錄` |

**產生時機：** BDS job **成功** promote 至 GCS knowledge 後寫入 `sync_report.json` 與 knowledge metadata；Upload Portal **僅讀取**，不自行產生。

**交叉引用：** `BATS_DATA_CONTRACT.md` §1.6.5；`BATS_DATA_SYNC_POLICY.md` §17.10.6

#### 13.1.3 上一次成功同步時間

| 狀態 | UI 文案 |
|------|---------|
| 有成功紀錄 | 顯示最近一次 **success** 之 `finished_at`／`published_at`（ISO 或在地格式） |
| 無成功紀錄 | `尚無成功同步紀錄` |

資料來源：§13.1.1 優先序。

#### 13.1.4 目前 AI 使用資料狀態（AI Data Status Rule）

**用途：** 讓使用者於上傳前即可知道目前 AI 是否已使用最新資料。

**可用值（僅下列兩項）：**

| 值 | 判定 |
|----|------|
| `已同步版本` | 頁面呈現之 GCS 現行 knowledge `sync_id` 與最近一次成功 promote 一致；**或** 本次 session 上傳成功後已更新 |
| `仍為上一次成功同步版本` | 存在歷史成功紀錄，但本次 session 尚未完成新一次成功 promote；GCS 仍為上一版 |

**無任何成功紀錄時：** 顯示 `—`（**不** 使用上述兩值之一）。

> **UX 原則：** 頁面初次載入且曾有成功同步 → 預設 `仍為上一次成功同步版本`；本次上傳成功後 → `已同步版本`，並更新欄位 2（時間）、3（版本）。

#### 13.1.5 上傳提示（Upload Hint）

選檔區 **上方** 必須顯示：

```text
請上傳 .xlsx 檔案，系統會先檢查格式，通過後才會更新 AI 知識庫。
```

#### 13.1.6 同步中狀態（Sync Running）

使用者按下「上傳並同步」後、收到最終結果前：

| 元素 | 文案／行為 |
|------|------------|
| **進行中訊息** | `正在上傳並同步，請勿關閉此頁面。` |
| **按鈕文字** | `同步處理中...`（disabled，防止重複提交） |
| **檔案輸入** | disabled（同步進行中） |

#### 13.1.7 同步結果顯示（Sync Result Display — Final）

**Tenant 成功 — 模板（必須）：**

```text
本次同步成功

同步範圍：
{tenant_key}

同步版本：
{sync_id}

AI 知識庫已更新。
```

- `{tenant_key}`：Registry 業務鍵（如 `travel_b`）；**不** 顯示 `sno`
- `{sync_id}`：格式 `SYNC-YYYYMMDD-HHMMSS`（§13.1.2）
- 後端可另附 Read-back PASS／FAIL 摘要（營運／除錯；**非** 使用者必填主文案）

**Shared 成功 — 模板（必須）：**

```text
本次同步成功

同步範圍：
shared/{industry_code}

同步版本：
{sync_id}

公有知識庫已更新。
```

- `{industry_code}`：使用者所選 industry（MVP：`travel`）

**失敗 — 統一模板（Tenant／Shared 共用）：**

```text
本次同步失敗

失敗原因：
{reason}

本次未更新 GCS，
舊版資料仍維持可用。

請修正 Excel 後重新上傳。
```

`{reason}` 含 validation error 摘要（若有）。**Safety Rule：** 失敗時 **不得** 覆蓋 GCS 正式 knowledge JSON（`BATS_DATA_SYNC_POLICY.md` §14）。

成功後 **必須** 更新 readonly 欄位：上一次成功同步時間、目前 AI 使用版本、目前 AI 使用資料狀態。

#### 13.1.8 檔案輸入共通約束

| 項目 | 規則 |
|------|------|
| **格式** | `.xlsx` only |
| **HTML** | `accept=".xlsx"`（或等價 MIME 限制） |
| **必填** | Excel 檔案為 required |

---

### 13.2 Tenant Private Upload Portal — UI Fields

**URL：** `https://kowanbo.com/bds/upload.php`  
**用途：** Tenant Private Knowledge Excel 上傳。

| # | 欄位標籤 | 控制項 | 屬性 | 說明 |
|---|----------|--------|------|------|
| 1 | **旅行社名稱** | text（display） | `readonly` | session／Registry；範例：`旅行蜜優惠` |
| 2 | **上一次成功同步時間** | text（display） | `readonly` | §13.1.3 |
| 3 | **目前 AI 使用版本** | text（display） | `readonly` | `sync_id`；§13.1.2；範例：`SYNC-20260613-153025` |
| 4 | **目前 AI 使用資料狀態** | text（display） | `readonly` | §13.1.4：`已同步版本`／`仍為上一次成功同步版本` |
| 5 | **Excel 檔案** | file input | `required`；`accept .xlsx` | 上方 Upload Hint（§13.1.5） |
| 6 | **上傳並同步** | button | submit | 同步中見 §13.1.6 |
| 7 | **同步結果** | system block | — | §13.1.7；成功後更新欄位 2、3、4 |

**後端解析（不可顯示於 UI）：**

| 隱藏項 | 解析來源 |
|--------|----------|
| `tenant_key` | Registry（如 `travel_b`） |
| `sno` | Registry wire authority |
| `gcs_prefix` | `tenants/{sno}/knowledge/` |
| `bucket` | BDS runtime config |
| `folder_id` | Drive archive（若啟用） |

**禁止可見欄位：** `tenant_key`、`sno`、`gcs_prefix`、`bucket`、`folder_id`。

---

### 13.3 Shared Upload Portal — UI Fields

**URL：** `https://kowanbo.com/bds/shared_upload.php`  
**用途：** BBC 管理中心上傳 Industry Shared Knowledge Excel。

| # | 欄位標籤 | 控制項 | 屬性 | 說明 |
|---|----------|--------|------|------|
| 1 | **資料層級** | text（display） | `readonly` | 固定：`Industry Shared Knowledge` |
| 2 | **Industry Code** | select | required | §13.4；MVP 僅 `travel` enabled |
| 3 | **上一次成功同步時間** | text（display） | `readonly` | §13.1.3；依所選 `industry_code` |
| 4 | **目前 AI 使用版本** | text（display） | `readonly` | §13.1.2；依所選 `industry_code` |
| 5 | **目前 AI 使用資料狀態** | text（display） | `readonly` | §13.1.4 |
| 6 | **Excel 檔案** | file input | `required`；`accept .xlsx` | 上方 Upload Hint（§13.1.5） |
| 7 | **上傳並同步** | button | submit | 同步中見 §13.1.6 |
| 8 | **同步結果** | system block | — | §13.1.7；成功顯示 `公有知識庫已更新。` |

**後端解析（不可顯示於 UI）：**

| 隱藏項 | 解析來源 |
|--------|----------|
| `owner_scope` | `industry`（BDS job） |
| `bucket` | BDS runtime config |
| `gcs_prefix` | `shared/{industry_code}/knowledge/` |
| `folder_id` | Platform Drive Registry |
| `policy flags` | Shared archive／promote policy |

**禁止可見欄位：** `owner_scope`、`bucket`、`gcs_prefix`、`folder_id`、`policy flags`。

---

### 13.4 Industry Code — UI Option List & Mapping（Final）

UI **必須** 建立下列 **六項** 選項；後端 **僅** 允許 Registry／Policy 已啟用之 `industry_code`。

| industry_code | UI MVP 狀態 | GCS target | Drive shared archive |
|---------------|---------------|------------|----------------------|
| `travel` | **enabled** | `shared/travel/knowledge/` | `industries/travel/shared/02_Shared_Layer/` |
| `hotel` | **disabled** | `shared/hotel/knowledge/` | `industries/hotel/shared/02_Shared_Layer/` |
| `restaurant` | **disabled** | `shared/restaurant/knowledge/` | `industries/restaurant/shared/02_Shared_Layer/` |
| `beauty` | **disabled** | `shared/beauty/knowledge/` | `industries/beauty/shared/02_Shared_Layer/` |
| `education` | **disabled** | `shared/education/knowledge/` | `industries/education/shared/02_Shared_Layer/` |
| `medical` | **disabled** | `shared/medical/knowledge/` | `industries/medical/shared/02_Shared_Layer/` |

**MVP（Phase 7-2）：** 僅 `travel` = enabled；其餘 = disabled（或標示「尚未開放」）。

**未來：** 由 **Platform Registry／Policy** 控制各 `industry_code` 是否 enabled；UI 列出六項不變，後端 gate 依 Registry 動態拒絕未啟用 industry。

**重要原則：**

- 使用者選擇未啟用 industry 並提交 → 拒絕並顯示原因（不寫入 GCS）。

**交叉引用：** `BATS_DATA_SOURCE_REGISTRY.md` §10.6.7

---

### 13.5 UI Wireframe（邏輯順序 — Phase 7-0e.2 Final）

**Tenant Portal：**

```text
[旅行社名稱 readonly]
[上一次成功同步時間 readonly]
[目前 AI 使用版本 readonly]     ← SYNC-YYYYMMDD-HHMMSS
[目前 AI 使用資料狀態 readonly]
---
[Upload Hint]
[Excel 檔案 *]
[上傳並同步]  → 同步中：同步處理中... + 正在上傳並同步，請勿關閉此頁面。
---
[同步結果區塊]
```

**Shared Portal：**

```text
[資料層級 readonly: Industry Shared Knowledge]
[Industry Code select *]        ← travel enabled；其餘 disabled
[上一次成功同步時間 readonly]
[目前 AI 使用版本 readonly]
[目前 AI 使用資料狀態 readonly]
---
[Upload Hint]
[Excel 檔案 *]
[上傳並同步]  → 同步中狀態同上
---
[同步結果區塊]
```

---

### 13.6 Sync Version & Metadata Contract（Phase 7-0e.2）

| 項目 | SSOT |
|------|------|
| `sync_id` 格式 | `SYNC-YYYYMMDD-HHMMSS` |
| UI 顯示名稱 | 目前 AI 使用版本 |
| 寫入時機 | BDS 成功 promote 後寫入 `sync_report.json` + knowledge metadata |
| UI 讀取來源 | §13.1.1 優先序（GCS → `var/bds/` → Drive 輔助） |
| Host B SQL | **禁止** |
| L3 Contract | `BATS_DATA_CONTRACT.md` §1.6.5 |
| L1 Policy | `BATS_DATA_SYNC_POLICY.md` §17.10.6 |

---

## 14. Cross References

| 文件 | 關係 |
|------|------|
| `BATS_DATA_SYNC_POLICY.md` §17.8～§17.10.6、§19.4 | L1 — Dual Portal、UI Final、Sync Version |
| `BATS_DATA_SOURCE_REGISTRY.md` §10.6.5～§10.6.8 | L1 — Tenant pilot、Shared scope、Industry、Sync metadata |
| `BATS_DATA_CONTRACT.md` §1.6.5 | L3 — Upload Portal Sync Metadata Contract |
| `BATS_DATA_OWNERSHIP_POLICY.md` §3.7 | L1 — Upload Portal 寫入權限 |
| `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` §8 | L2 — Phase 7-1／7-2／7-3 順序 |
| `BATS_SHARED_KNOWLEDGE_CONTRACT.md` | Shared Excel Contract（7-2） |
| `config/tenant_registry.php` | `travel_b` pilot 欄位 |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.4** | 2026-06-05 | Phase 7-0e.2 Final：目前 AI 使用版本；Sync Version Contract；成功／失敗訊息定案；Metadata Source Priority |
| **v1.3** | 2026-06-05 | Phase 7-0e.1：欄位順序調整；AI 使用資料狀態；Upload Hint；同步中 UX；成功／失敗訊息模板 |
| **v1.2** | 2026-06-05 | Phase 7-0e：§13 Upload Portal UI／Field Spec；sync result 文案；industry 選項映射；last upload time 來源 |
| **v1.1** | 2026-06-05 | Phase 7-0d：Shared Upload Portal；dual portal model；management center sno gate；7-1／7-2／7-3 分解 |
| **v1.0** | 2026-06-05 | Phase 7-0c：Canonical domain、brandlogin reuse、bbcshops redirect-only、travel_b pilot、retUrl allowlist、Login Bridge Deferred |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | **SSOT 定案（Phase 7-0e.2 Final）** — Phase 7-1a 前文件閉合；待 commit |
| **程式實作** | **未開始** |
| **SAFE TO IMPLEMENT Phase 7-1a** | **是** — Tenant Portal **Final UI spec** 已閉合 |
| **SAFE TO IMPLEMENT Phase 7-1** | **是** — domain／auth／pilot／UI field spec 已閉合 |
| **SAFE TO IMPLEMENT Phase 7-2** | **是（文件層）** — Shared Portal Final UI spec 已閉合；**依賴** Shared BDS 管線就緒 |
