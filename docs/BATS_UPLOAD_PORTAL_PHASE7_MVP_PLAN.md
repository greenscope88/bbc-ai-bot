# BDS Upload Portal — Phase 7-1 MVP Plan

**專案根目錄：** `C:\bbc-ai-bot`  
**文件版本：** 2026-06-15（Phase 7-1e.10b MVP Close-out）  
**性質：** L2 實作規劃（SSOT First / DDD）。

**前置審計：** Phase 7-0b～7-0e.2；Phase 7-1c-0～7-1c-1b；Phase 7-1e.6～7-1e.9a（Tenant Upload Portal MVP Runtime Close-out）。

---

## 1. 文件目的

本文件將 **Tenant／Shared 雙 Upload Portal** 之正式網域、**Legacy 品牌登入重用**、**權限 gate**、**Pilot 對照** 與 **跨域安全邊界** 定案為 Phase 7 實作依據。

| 層級 | 文件 | 本文件角色 |
|------|------|------------|
| **L1 SSOT** | `BATS_DATA_SYNC_POLICY.md` §17、§19 | 同步觸發與 Update Entry 原則 |
| **L1 SSOT** | `BATS_DATA_SOURCE_REGISTRY.md` §10.6 | Registry／Pilot／Shared scope 對照 |
| **L1 SSOT** | `BATS_DATA_OWNERSHIP_POLICY.md` §3.7 | Upload Portal 寫入權限邊界 |
| **L2 規劃** | **本文件** | Phase 7-1～7-3 URL、Auth、Gate、**UI Field Spec**、安全約束 |
| **L3 實作** | `www/bds/upload.php` + `bbc-ai-bot` BDS core | **Completed（7-1）** ✅ |
| **L3 實作（Post-MVP）** | `www/bds/shared_upload.php` | **Post-MVP（7-2）** |

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
| **7-1** | **Tenant Upload Portal MVP** | `upload.php`、travel_b gate、staging、CLI→BDS、GCS read-back | **Completed** ✅（7-1e.9a） |
| **7-2** | **Shared Upload Portal MVP** | `shared_upload.php`、management center sno gate、`shared/travel/knowledge/`、Shared Contract | **Post-MVP** |
| **7-3** | **Production Hardening** | Audit log、recovery、retUrl 強化、可選 bbcshops redirect、營運 runbook | **Post-MVP** |
| **7-x** | Login Bridge Token | 跨域 bbcshops 操作域 | **Deferred** |

### 11.1 Phase 7-1 Scope（Tenant）

| 1 | `www/bds/upload.php` — session 守衛 + `storeNo=6180` gate | **7-1a Completed** ✅ |
| 2 | Upload receive + staging（`var/bds/uploads/...`） | **7-1b Completed** ✅ |
| 3 | POST → CLI Trigger → `bin/bds-sync.php` → GCS + Read-back | **7-1c Completed** ✅（7-1e.9a） |
| 4 | Audit：`accountNo`、`storeNo`（**不** UI 暴露 sno） | **Post-MVP（7-3）** |

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
| 4 | 可選：`bbcshops.com` 雙 redirect 頁（**7-1d**） |

### 11.4 Phase 7-1 子階段分解（Runtime）

| 子階段 | 名稱 | 狀態 | 摘要 |
|--------|------|------|------|
| **7-1a** | Login gate + UI shell | **Completed** ✅ | legacy www |
| **7-1b** | Upload receive + staging | **Completed** ✅ | `var/bds/uploads/.../staging/` |
| **7-1c-1** | CLI Command Wrapper（dry-run build） | **Completed** ✅ | legacy www |
| **7-1c-1b** | Upload CLI Input Contract SSOT | **Completed** ✅ | `--upload-session-id` 定案 |
| **7-1c-2a** | bbc-ai-bot upload mode（Resolver + XlsxReader） | **Completed** ✅ | `807f6b7` |
| **7-1c-2b** | legacy www CLI execution | **Completed** ✅ | 7-1c-3b |
| **7-1c-3a** | Formal sync（live GCS + read-back） | **Completed** ✅ | `d1c52f1` |
| **7-1c-3b** | Portal POST → CLI formal sync | **Completed** ✅ | legacy www |
| **7-1c** | Upload → BDS Sync Trigger（端到端） | **Completed** ✅ | 7-1e.9a E2E sign-off |
| **7-1e** | Worksheet Mapping + Validation UX | **Completed** ✅ | `d94a558`；7-1e.9a |
| **7-1e.8+** | Workbook Download + Drive Promote | **Post-MVP** | SSOT closed；runtime deferred |
| **7-1d** | bbcshops redirect-only（可選） | **Post-MVP** | 非 7-1 阻塞項 |

---

## 12. Upload → BDS Sync Trigger Architecture（Phase 7-1c-0 SSOT）

> **Status: SSOT 定案（2026-06-05）** — Phase 7-1c 架構決策；**不**另立獨立 ADR 文件（整合 L2 MVP Plan + L1 Sync Policy §17.11）。

### 12.1 文件治理決策

| 方案 | 決策 |
|------|------|
| 新增 `BATS_UPLOAD_SYNC_TRIGGER_DECISION.md` | **不採用** — 避免 L2 文件碎片化 |
| **採用** | 本節（L2）+ `BATS_DATA_SYNC_POLICY.md` §17.11（L1）+ Implementation Plan §8.8（交付） |

### 12.2 唯一正式同步方式

```text
https://kowanbo.com/bds/upload.php
        ↓
Upload-Triggered Sync（Mode B）
        ↓
bin/bds-sync.php → BDS Pipeline → GCS → Read-back → sync_report
```

### 12.3 P1 Safety Rule — Last Successful Version

與 `BATS_DATA_SYNC_POLICY.md` §14.1.1 對齊：

- Validation／Upload／Sync／Read-back **任一失敗** → **不得** 覆蓋 GCS 正式 `knowledge/*.json`
- AI Runtime 永遠使用 **Last Successful Version**
- 失敗 UI：`本次同步失敗` + `本次未更新 GCS，舊版資料仍維持可用。`

### 12.4 Phase 7-1c MVP — CLI Trigger（正式採用）

| 階段 | 元件 | 說明 |
|------|------|------|
| 1 | Portal POST | 7-1b staging 完成後觸發 sync |
| 2 | **CLI Trigger** | 呼叫 `C:\bbc-ai-bot\bin\bds-sync.php`（**upload mode**：`--upload-session-id`） |
| 3 | BDS Pipeline | upload mode：Resolver → XlsxReader → validation → build → GCS → read-back |
| 4 | 輸出 | GCS `tenants/5f99b8d665e8444d/knowledge/` + `meta/sync_report.json` |
| 5 | Portal UI | 成功：`本次同步成功`（§13.1.7）；失敗：§14.1.1 模板 |

**正式 CLI 指令範例（Tenant Pilot — Phase 7-1c-1b 定案）：**

```text
"C:\Web\xampp\php\php.exe" "C:\bbc-ai-bot\bin\bds-sync.php"
  --tenant=travel_b
  --upload-session-id={upload_session_id}
  --dry-run
```

**正式 GCS 寫入（7-1c-2b／7-1c-3，須 dry-run PASS 後）：**

```text
  --tenant=travel_b
  --upload-session-id={upload_session_id}
  --dry-run=false
  --write-gcs
```

**Upload Portal 禁止行為：**

| 禁止 | 說明 |
|------|------|
| 直接同步 Google Sheet | Portal **不得** 觸發 sheet mode（無 `--upload-session-id`）作為產品更新 |
| `--input-xlsx` | Portal 正式模式 **不** 傳實體路徑；使用 `--upload-session-id` |
| 獨立 sync 邏輯 | 須共用 `bin/bds-sync.php` BDS Sync Core |

**7-1c（Completed）：** Portal 於 staging 完成後執行 `bin/bds-sync.php --upload-session-id=...`；成功路徑寫入 GCS + `sync_report`；失敗遵守 §12.3 Last Successful Version（7-1e.9a 驗證）。

**不做（7-1c）：** in-process BDS 重構；Core Orchestrator；Cron；Drive Watch。

### 12.5 Out of Scope — 非產品主線（Not Planned）

| 機制 | 狀態 |
|------|------|
| Auto Sync | Out of Scope |
| Scheduled Sync | Out of Scope |
| Cron Sync | Out of Scope |
| Drive Watch Sync | Out of Scope |
| Google Drive Change Trigger | Out of Scope |
| Background Scheduled Synchronization | Out of Scope |

> 不得於未來 SSOT 再列為 Phase 7 主線。

### 12.6 Deferred — Core Orchestrator

| 項目 | 說明 |
|------|------|
| **名稱** | BDS Core Orchestrator（PHP in-process 或常駐服務） |
| **狀態** | Future Architecture Enhancement |
| **再評估條件** | 多租戶商品化；Upload Portal 大量使用；Shared Upload 穩定 |

### 12.7 共用 BDS Sync Core（長期）

Tenant Portal、Shared Portal、未來 API Trigger **必須** 共用同一 BDS Sync Core；**禁止** 各入口獨立 sync 實作。

**交叉引用：** `BATS_DATA_SYNC_POLICY.md` §17.11；`BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` §8.8

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

`{reason}` 含 validation error 摘要（若有）。**Safety Rule：** 失敗時 **不得** 覆蓋 GCS 正式 knowledge JSON（`BATS_DATA_SYNC_POLICY.md` §14.1.1 Last Successful Version Rule）。

成功後 **必須** 更新 readonly 欄位：上一次成功同步時間、目前 AI 使用版本、目前 AI 使用資料狀態。

#### 13.1.8 檔案輸入共通約束

| 項目 | 規則 |
|------|------|
| **格式** | `.xlsx` only |
| **HTML** | `accept=".xlsx"`（或等價 MIME 限制） |
| **必填** | Excel 檔案為 required |
| **原始檔名** | **不檢查** — 使用者可上傳任意檔名（如 `大億旅行社QA.xlsx`、`最新版.xlsx`）；系統 **不得** 依檔名判定合法性（Contract §4.4.4；Policy §17.12.4） |
| **工作表名稱** | **不要求** 英文技術名；接受 §4.4.2 中／英別名（Mapping Layer） |
| **工作表順序** | **不檢查**（Policy §17.12.2） |

#### 13.1.9 Worksheet UX 與中文化錯誤訊息（Phase 7-1e.6 SSOT）

> **Status: SSOT 定案（2026-06-14）** — Upload Portal **使用者可見** Worksheet 語意與 Validation 錯誤之中文化規格。  
> **L3 別名表：** `BATS_DATA_CONTRACT.md` §4.4.2；**L1 驗收：** `BATS_DATA_SYNC_POLICY.md` §17.12。

##### 13.1.9.1 Upload Hint 補充（選填展示）

除 §13.1.5 外，產品 **可** 於 Hint 或說明區補充（**非** 強制驗證文案）：

```text
Excel 需包含以下工作表（名稱可為中文或英文，順序不拘）：
公司基本資料、問與答、服務項目、其他商品源、特殊價格
```

##### 13.1.9.2 使用者可見工作表語意（Display Names）

Portal UI、說明文字、錯誤訊息 **一律** 使用下列 **中文顯示名**；**不得** 向旅行社暴露 Canonical Key：

| Canonical Key（僅後端） | 中文顯示名 |
|-------------------------|------------|
| `company_profile` | 公司基本資料 |
| `qa` | 問與答 |
| `external_product_links` | 其他商品源 |
| `service_items` | 服務項目 |
| `special_prices` | 特殊價格 |

##### 13.1.9.3 錯誤訊息中文化規則（Must）

| 規則 | 說明 |
|------|------|
| **語言** | 所有 Upload Portal 錯誤訊息 **必須** 為繁體中文 |
| **禁止直出** | **不得** 直接顯示 CLI／BDS 英文技術訊息（如 `company_profile missing`、`worksheet not found`、`invalid worksheet`、`Missing required worksheet tabs`） |
| **轉換責任** | `upload.php`（或共用 Portal 錯誤 formatter）**必須** 將內部錯誤映射為中文顯示名 |
| **整體模板** | 仍包在 §13.1.7 失敗模板內之 `{reason}` |

##### 13.1.9.4 錯誤文案模板（定案）

**單一缺少工作表：**

```text
缺少「{中文顯示名}」工作表
```

範例：

```text
缺少「公司基本資料」工作表
```

**多個缺少工作表：**

```text
缺少必要工作表：

{中文顯示名_1}
{中文顯示名_2}
{中文顯示名_3}
```

範例：

```text
缺少必要工作表：

公司基本資料
問與答
服務項目
```

**重複映射（兩個工作表對應同一語意）：**

```text
工作表「{工作表名_A}」與「{工作表名_B}」皆對應「{中文顯示名}」，請保留其中一個並重新命名。
```

**檔案無法讀取：**

```text
無法讀取 Excel 檔案，請確認檔案未損壞且為 .xlsx 格式。
```

##### 13.1.9.5 內部錯誤 → 中文對照（實作參考）

| 內部訊息模式（BDS／CLI） | Portal `{reason}` 片段 |
|--------------------------|------------------------|
| `Missing required worksheet tabs: company_profile` | `缺少「公司基本資料」工作表` |
| `Missing required worksheet tabs: company_profile, qa` | §13.1.9.4 多列模板 |
| `Unable to open xlsx file` | §13.1.9.4 檔案無法讀取 |
| `duplicate worksheet mapping`（未來） | §13.1.9.4 重複映射模板 |

**欄位級 Validation 錯誤：** 優先顯示 **中文業務語意**（如「公司基本資料缺少必要欄位：公司名稱」）；若暫無細映射，至少 **不得** 裸顯英文 snake_case 鍵作為唯一訊息。

**交叉引用：** `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` §8.8.6.5

#### 13.1.10 Portal Download UX — 下載最新版 Excel（Phase 7-1e.8 SSOT）

> **Status: SSOT 定案（2026-06-15）** — Tenant Upload Portal **Latest Successful Workbook** 下載 UX。  
> **L3 契約：** `BATS_DATA_CONTRACT.md` §1.6.9；**L1 規則：** `BATS_DATA_SYNC_POLICY.md` §14.1.2。

##### 13.1.10.1 使用者故事

旅行社登入 `https://kowanbo.com/bds/upload.php` 後，除上傳外，可 **下載目前 AI 正在使用之最新成功 Excel**，作為離線編輯模板，修改後再透過同一 Portal 上傳。

##### 13.1.10.2 Readonly 區塊整合（Tenant）

下載區塊 **必須** 與既有 readonly metadata **同一視覺群組**，建議順序：

| # | 欄位／控制項 | 類型 | 說明 |
|---|--------------|------|------|
| 1 | 旅行社名稱 | readonly | 既有 §13.2 |
| 2 | 上一次成功同步時間 | readonly | §13.1.3 |
| 3 | 目前 AI 使用版本 | readonly | §13.1.2；例：`SYNC-20260614-112620` |
| 4 | 目前 AI 使用資料狀態 | readonly | §13.1.4 |
| 5 | **最新版 Excel** | **download action** | 見 §13.1.10.3 |
| 6 | Excel 檔案（上傳） | file input | 既有 |
| 7 | 上傳並同步 | submit | 既有 |
| 8 | 同步結果 | system block | 既有 |

##### 13.1.10.3 下載控制項規格

**有成功紀錄時（`latest_successful_workbook` 可用且 `sync_id` 對齊最近 success）：**

```text
最新版 Excel：
【下載最新版 Excel】
```

| 項目 | 規格 |
|------|------|
| **控制項** | 連結或 button 觸發下載（`<a download>` 或 server redirect） |
| **文案** | `下載最新版 Excel`（**定案**） |
| **href 來源** | `drive_download_url`（Contract §1.6.9.3）；**禁止** 硬編碼 Drive ID |
| **行為** | 點擊後下載 Google Drive 上 **最後一次成功同步** 之 `.xlsx` |
| **Download Mode** | View URL 或 Download URL；**禁止** Edit URL |

**無成功紀錄時：**

```text
最新版 Excel：
尚無可下載的最新版 Excel
```

（無連結、無按鈕。）

##### 13.1.10.4 失敗 session 行為

| 情境 | 下載區塊 |
|------|----------|
| 本次 POST sync **失敗**（`status=failed`） | 仍顯示 **上一版成功** 之下載連結（Last Successful Workbook Rule） |
| 本次 POST **workbook warning**（`status=success_with_workbook_warning`） | 仍顯示 **上一版成功** 下載連結；同步結果見 §13.1.11 |
| readonly `目前 AI 使用資料狀態` | `仍為上一次成功同步版本`（§13.1.4）；workbook warning 時見 §13.1.11.3 |
| 不得 | 連結至本次失敗 staging 檔、`upload_session` 暫存路徑 |

##### 13.1.10.5 禁止 UI 暴露（下載相關）

| 禁止 |
|------|
| `drive_file_id`、`folder_id` |
| `tenant_sno`、`tenant_key` |
| `bucket`、`gcs_prefix`、`gs://` |
| `var/bds/` 路徑 |
| staging／`upload_session_id` 作為下載參數 |

**允許：** 使用者可見之 `sync_id`、同步時間、下載按鈕文案。

##### 13.1.10.6 Shared Upload Portal（7-2 預留）

Shared Portal **可** 於 7-2 採相同模式：下載來源為 **Industry Shared** Drive workbook；metadata scope 為 `shared/{industry_code}/`。**MVP（7-1）** 僅 Tenant Portal 實作。

**交叉引用：** `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` §8.8.6.6

#### 13.1.11 Workbook Promote Warning UX（Phase 7-1e.8b-1a SSOT）

> **Status: SSOT 定案（2026-06-14）** — `status=success_with_workbook_warning` 之 Portal 呈現；L1：`BATS_DATA_SYNC_POLICY.md` §14.1.3。

##### 13.1.11.1 觸發條件

| 條件 | 值 |
|------|-----|
| `knowledge_sync_status` | `success` |
| `workbook_promote_status` | `failed` |
| `sync_report.status` | `success_with_workbook_warning` |

##### 13.1.11.2 同步結果區塊（Tenant — 定案模板）

```text
本次同步成功

同步範圍：
{tenant_key}

同步版本：
{sync_id}

AI 知識庫已更新。

但最新版 Excel 下載功能暫時無法更新。

目前系統仍維持上一版成功下載檔案。

請通知系統管理員協助處理。
```

##### 13.1.11.3 Readonly 欄位行為

| 欄位 | 行為 |
|------|------|
| 上一次成功同步時間 | **更新** 為本次 knowledge success 時間 |
| 目前 AI 使用版本 | **更新** 為本次 `{sync_id}` |
| 目前 AI 使用資料狀態 | `已同步版本（下載檔案待更新）`（Contract §1.6.5.4） |
| 最新版 Excel 下載 | **維持** 上一版成功 `drive_download_url`；**不** 隱藏按鈕（若有歷史成功） |

##### 13.1.11.4 禁止行為

| 禁止 |
|------|
| 顯示 `本次同步失敗` |
| 顯示 `請修正 Excel 後重新上傳` |
| 顯示 `請重新上傳` |
| 顯示 `本次未更新 GCS` |
| 將本次 session 標為 `sync_failed`（應為 `sync_success` + workbook warning） |
| 隱藏或清空既有下載連結（若有上一版成功 workbook） |

**交叉引用：** `BATS_DATA_SYNC_POLICY.md` §14.1.3.5；Implementation Plan §8.8.6.6.8

#### 13.1.12 Error Classification UX（Phase 7-1e.8b-1a SSOT）

> **Status: SSOT 定案（2026-06-14）** — Upload Portal 錯誤分類與 UX 對照；L1：`BATS_DATA_SYNC_POLICY.md` §14.1.4、§14.1.5。

##### 13.1.12.1 分類矩陣

| 分類 | `sync_report.status` | 主標題 | 重新上傳 | 聯絡管理員 | 下載連結 |
|------|----------------------|--------|----------|------------|----------|
| **User Actionable** | `failed` | `本次同步失敗` | ✅ 建議 | ✗ | 維持上一版 |
| **Infra — 全失敗** | `failed` | `本次同步失敗` | 視情況 | 可選 | 維持上一版 |
| **Infra — Knowledge OK + Workbook Fail** | `success_with_workbook_warning` | `本次同步成功` + Warning 區塊 | ✗ **禁止建議** | ✅ | 維持上一版 |

##### 13.1.12.2 User Actionable — UX 模板

沿用 §13.1.7 失敗模板：

```text
本次同步失敗

失敗原因：
{中文錯誤訊息}

本次未更新 GCS，
舊版資料仍維持可用。

請修正 Excel 後重新上傳。
```

**範例 `{中文錯誤訊息}`：** 缺少必要工作表、工作表名稱錯誤、工作表重複映射、必填欄位缺漏（§13.1.9）。

##### 13.1.12.3 System Infrastructure — Knowledge OK + Workbook Fail

沿用 §13.1.11.2；**分類為** Workbook Download Service Warning，**非** User Upload Failure。

**理由：** 重新上傳 **不能保證** 解決 Drive API／權限／平台問題。

##### 13.1.12.4 決策規則（Portal 實作）

```text
IF knowledge_sync_status = failed
  → §13.1.7 失敗模板（User Actionable 或 Infra 全失敗）

ELSE IF status = success_with_workbook_warning
  → §13.1.11.2（禁止重新上傳文案）

ELSE IF status = success
  → §13.1.7 成功模板
```

**交叉引用：** `BATS_DATA_CONTRACT.md` §1.6.9.7；`BATS_DATA_SYNC_POLICY.md` §14.1.4、§14.1.5

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
| 5 | **最新版 Excel** | link／button | readonly action | §13.1.10；`下載最新版 Excel` |
| 6 | **Excel 檔案** | file input | `required`；`accept .xlsx` | 上方 Upload Hint（§13.1.5） |
| 7 | **上傳並同步** | button | submit | 同步中見 §13.1.6 |
| 8 | **同步結果** | system block | — | §13.1.7；成功後更新欄位 2、3、4 |

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
| `BATS_DATA_SYNC_POLICY.md` §17.8～§17.11、§19.4 | L1 — Dual Portal、UI Final、Sync Trigger |
| `BATS_DATA_SOURCE_REGISTRY.md` §10.6.5～§10.6.8 | L1 — Tenant pilot、Shared scope、Industry、Sync metadata |
| `BATS_DATA_CONTRACT.md` §1.6.5、§4.4、§1.6.9 | L3 — Sync Metadata；Worksheet Mapping；Latest Successful Workbook |
| `BATS_DATA_OWNERSHIP_POLICY.md` §3.7 | L1 — Upload Portal 寫入權限 |
| `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` §8 | L2 — Phase 7-1／7-2／7-3 順序 |
| `BATS_SHARED_KNOWLEDGE_CONTRACT.md` | Shared Excel Contract（7-2） |
| `config/tenant_registry.php` | `travel_b` pilot 欄位 |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v2.0** | 2026-06-15 | Phase 7-1e.10b：§11 MVP Close-out — 7-1／7-1c／7-1e **Completed**；Workbook／Drive **Post-MVP** |
| **v1.9** | 2026-06-14 | Phase 7-1e.8b-1a：§13.1.11 Workbook Promote Warning UX；§13.1.12 Error Classification UX |
| **v1.8** | 2026-06-15 | Phase 7-1e.8：§13.1.10 Portal Download UX；§13.2 新增「下載最新版 Excel」 |
| **v1.7** | 2026-06-14 | Phase 7-1e.6：§13.1.8 檔名／順序不檢查；§13.1.9 Worksheet UX 與中文化錯誤訊息 |
| **v1.6** | 2026-06-05 | Phase 7-1c-1b：§12.4 CLI 指令含 `--upload-session-id`；禁止 Portal 直接同步 Sheet |
| **v1.5** | 2026-06-05 | Phase 7-1c-0：§12 Upload→BDS Sync Trigger；§11.4 子階段；7-1a／7-1b Done |
| **v1.4** | 2026-06-05 | Phase 7-0e.2 Final：目前 AI 使用版本；Sync Version Contract；成功／失敗訊息定案；Metadata Source Priority |
| **v1.3** | 2026-06-05 | Phase 7-0e.1：欄位順序調整；AI 使用資料狀態；Upload Hint；同步中 UX；成功／失敗訊息模板 |
| **v1.2** | 2026-06-05 | Phase 7-0e：§13 Upload Portal UI／Field Spec；sync result 文案；industry 選項映射；last upload time 來源 |
| **v1.1** | 2026-06-05 | Phase 7-0d：Shared Upload Portal；dual portal model；management center sno gate；7-1／7-2／7-3 分解 |
| **v1.0** | 2026-06-05 | Phase 7-0c：Canonical domain、brandlogin reuse、bbcshops redirect-only、travel_b pilot、retUrl allowlist、Login Bridge Deferred |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | **SSOT 定案（Phase 7-1e.10b）** — Tenant Upload Portal MVP **Completed** |
| **7-1 Runtime** | **Completed** ✅ — 7-1e.9a production E2E（`travel_b` / `storeNo=6180`） |
| **Post-MVP Runtime** | Workbook Download、Drive Promote、Shared Drive、Auto Provisioning、Shared Upload Portal（§11、§13.1.10） |
| **SAFE TO IMPLEMENT Phase 7-2** | **是（文件層）** — Shared Portal spec 已閉合；**依賴** Shared BDS 管線 + 7-2 runtime |
| **E2E 證據** | `UPLOAD-20260615-160459-43a5b1` / `SYNC-20260615-100459`（7-1e.9a） |
