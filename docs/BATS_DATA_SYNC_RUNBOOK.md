# BATS_DATA_SYNC_RUNBOOK.md

**專案：** BBC AI SaaS / BATS / BDS / Travel Data Center  
**定位：** L2 維運操作層 — BDS v1 Runbook（手動同步操作手冊）  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_DATA_SYNC_POLICY.md`、`BATS_DATA_SOURCE_REGISTRY.md`、`BATS_DATA_CONTRACT.md`、`BATS_DATA_OWNERSHIP_POLICY.md`、`BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md`、`BATS_DATA_SYNC_TEST_PLAN.md`  
**適用範圍：** BDS v1 MVP — `tenant_private_knowledge` 手動同步  
**適用對象：** BBC Admin、維運人員、開發者  
**衝突處理：** 同步原則以 `BATS_DATA_SYNC_POLICY.md` 為準；實作介面以 `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` 為準；測試驗收以 `BATS_DATA_SYNC_TEST_PLAN.md` 為準；**維運操作步驟與失敗處理以本文件為準**。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §2 | Manual Sync Flow |
| §3 | Pre-Sync Checklist |
| §4 | Validation Fail Handling |
| §5 | GCS Write Fail Handling |
| §6 | Rollback / Restore Rule |
| §7 | Required Reports |
| §8 | Do Not Do List |
| §9 | Cross References |
| §10 | Phase 4 Verification Record |
| §11 | Phase 5 Safety Boundary |
| §12 | Phase 5 Close-out Verification Record |

---

## 1. Purpose

### 1.1 文件定位

本文件為 **BDS v1 維運操作手冊（Runbook）**，定義：

| 項目 | 說明 |
|------|------|
| **手動同步流程** | 如何執行 dry-run 與受控 GCS 寫入 |
| **同步前檢查** | Pre-Sync Checklist |
| **失敗處理** | Validation Fail、GCS 寫入失敗之標準處置 |
| **Rollback** | 還原原則與步驟（v1 不依賴 Object Versioning） |
| **報告解讀** | `sync_report`、`validation_report`、`error_report` |
| **禁止事項** | 維運不得執行之高風險操作 |

### 1.2 適用情境

| 情境 | 使用本文件 |
|------|------------|
| Pilot 租戶首次同步 | ✅ |
| 營運更新 Google Sheet 後手動觸發 | ✅ |
| 同步失敗排查 | ✅ |
| 需還原上一版 knowledge JSON | ✅ |
| 設定 Cron 自動同步 | ❌ v1 不支援 |
| 直接編輯 `shared/` 知識 | ❌ v1 不支援 |

### 1.3 前置條件

| 項目 | 狀態 |
|------|------|
| BDS SSOT 文件齊備 | Sync / Registry / Contract / Ownership |
| Implementation Plan Phase 6 已實作 | Manual Sync Command 可用 |
| Registry 已登錄目標租戶 | 含 `sno`、`private_knowledge_sheet_id`、`gcs_prefix` |
| Google Sheet 符合 5 Tab Contract | 見 `BATS_DATA_CONTRACT.md` |

### 1.4 本文件不做

| 不做 | 說明 |
|------|------|
| 定義程式 API 實作細節 | 見 Implementation Plan |
| 定義欄位 schema | 見 Data Contract |
| 取代 Test Plan 測試案例 | 見 Test Plan T-01～T-10 |

---

## 2. Manual Sync Flow

### 2.1 正式管線

```text
[維運] 確認 Pre-Sync Checklist（§3）
        ↓
[CLI]  載入 Registry（sno → sheet_id、gcs_prefix）
        ↓
[CLI]  讀取 Google Sheet（5 Required Tabs）
        ↓
[CLI]  tmp JSON → Validation（整檔 Fail）
        ↓
   ┌────┴────┐
   │ Fail    │ Pass
   ↓         ↓
 報告      dry-run 或 GCS 寫入
 保留舊版      ↓
           5 JSON + meta + reports
```

### 2.2 建議命令（概念）

> 實際路徑與參數名稱以 Phase 6 實作為準；下列為 Runbook 參考介面。

#### Step 1 — Dry-run（預設、必做）

```bash
php bin/bds-sync.php --sno=<TENANT_SNO>
```

| 項目 | 說明 |
|------|------|
| **預設行為** | `dry_run=true`；**不寫 GCS** |
| **產出** | 本機 preview 5 JSON + reports |
| **用途** | 同步前驗證 Sheet 與 Contract 一致性 |

#### Step 2 — 受控寫入 GCS（需明確授權）

```bash
php bin/bds-sync.php --sno=<TENANT_SNO> --dry-run=false --write-gcs
```

| 項目 | 說明 |
|------|------|
| **前置** | Step 1 dry-run 已通過 |
| **環境變數** | `BDS_DRY_RUN=false`、`BDS_GCS_WRITE_ENABLED=true`（雙重門禁） |
| **範圍** | 僅 `tenants/{sno}/knowledge/` 下 5 JSON |
| **禁止** | 未經 dry-run 直接執行本步驟 |

### 2.3 Pilot 範例（travel_b）

| 項目 | 值 |
|------|-----|
| `tenant_key` | `travel_b` |
| `tenant_sno` | `5f99b8d665e8444d` |
| GCS 目標 | `tenants/5f99b8d665e8444d/knowledge/` |

```bash
# 1. dry-run
php bin/bds-sync.php --sno=5f99b8d665e8444d

# 2. 確認 reports 通過後，受控寫入
php bin/bds-sync.php --sno=5f99b8d665e8444d --dry-run=false --write-gcs
```

> `travel_b` 僅為 Pilot 範例；命令須以 Registry 登錄之 `sno` 為準，**不得** 在腳本中寫死。

### 2.4 5 Tab → 5 JSON 對照

| Required Tab | JSON Output |
|--------------|-------------|
| `company_profile` | `company_profile.json` |
| `qa` | `service_qa.json` |
| `external_product_links` | `external_product_links.json` |
| `service_items` | `service_items.json` |
| `special_prices` | `special_prices.json` |

### 2.5 成功判定

| 條件 | 說明 |
|------|------|
| `validation_report.json` → `passed=true` | Validation 通過 |
| `sync_report.json` → `status=success` | 同步成功 |
| GCS 存在 5 JSON | 路徑正確、可讀 |
| `meta/sync_status.json` → `success` | meta 已更新（若實作） |
| **舊版未被錯誤覆蓋** | Safety Rule 成立 |

---

## 3. Pre-Sync Checklist

同步前 **必須** 逐項確認；任一項未通過則 **停止**，不執行 GCS 寫入。

### 3.1 Registry 與租戶

| # | 檢查項 | 通過條件 |
|---|--------|----------|
| 1 | Registry entry 存在 | 目標 `sno` 已登錄 |
| 2 | `private_knowledge_sheet_id` 已填 | 非空、可讀 |
| 3 | `gcs_prefix` 正確 | 值為 `tenants/{sno}/` |
| 4 | `industry_code` 已填 | 僅影響讀取 fallback；v1 同步不依賴 |
| 5 | 目標 `sno` 正確 | 人工核對 CLI 參數與 Registry |

### 3.2 Google Sheet

| # | 檢查項 | 通過條件 |
|---|--------|----------|
| 6 | 5 Required Tabs 存在 | `company_profile`、`qa`、`external_product_links`、`service_items`、`special_prices` |
| 7 | 欄位名為英文 snake_case | 無「問題」「答案」等中文欄位名 |
| 8 | 必填欄位已填 | 依 `BATS_DATA_CONTRACT.md` §7 |
| 9 | 無 PII 混入 | 無身分證、完整電話等 |
| 10 | Sheet 為該租戶專屬 | 非多租戶共用 Sheet |

### 3.3 環境與權限

| # | 檢查項 | 通過條件 |
|---|--------|----------|
| 11 | Service Account 可讀 Sheet | Phase 4 Reader 權限正常 |
| 12 | GCS 寫入權限（僅 write 步驟） | 限 `tenants/{sno}/` prefix |
| 13 | `BDS_DRY_RUN` 狀態已知 | 先 `true` 做 dry-run |
| 14 | 非營運尖峰 / 有 rollback 準備 | 建議離峰；已知上一版位置 |

### 3.4 建議執行順序

```text
1. 完成 §3.1～3.3 Checklist
2. 執行 dry-run（§2.2 Step 1）
3. 檢查 validation_report（§7）
4. 人工 spot-check preview JSON
5. 獲得授權後執行 GCS write（§2.2 Step 2）
6. 檢查 sync_report + GCS 物件
```

---

## 4. Validation Fail Handling

### 4.1 觸發條件

下列任一情況 → **整檔 Fail**（`BATS_DATA_CONTRACT.md` §1.4 決策 B）：

| 類型 | 範例 | 錯誤碼（概念） |
|------|------|----------------|
| 缺 Required Tab | 無 `special_prices` tab | `BDC_TAB_MISSING` |
| 缺 Required Field | `qa` 缺 `answer` | `BDC_REQUIRED_FIELD_MISSING` |
| 中文欄位名 | 欄位為「問題」 | `BDC_INVALID_TYPE` 或欄位名錯誤 |
| 型別錯誤 | 價格非數字 | `BDC_INVALID_TYPE` |
| tenant 不符 | `tenant_sno` 與路徑不一致 | `BDC_TENANT_MISMATCH` |
| PII 偵測 | 含身分證字號 | `BDC_PII_DETECTED` |

### 4.2 標準處置流程

```text
Validation Fail
        ↓
1. 停止同步（不寫 GCS 正式路徑）
        ↓
2. 保留 GCS 既有 5 JSON（Safety Rule）
        ↓
3. 讀取 error_report.json + validation_report.json
        ↓
4. 通知營運 / 資料維護人員修正 Sheet
        ↓
5. 修正後重新 dry-run
        ↓
6. passed=true 後才可申請 GCS write
```

### 4.3 維運動作對照

| 動作 | 做 | 不做 |
|------|-----|------|
| 讀取報告 | ✅ `error_report.json` 定位 tab/field | |
| 修正來源 | ✅ 請維護人員改 Google Sheet | |
| 手動上傳 JSON 覆蓋 GCS | | ❌ 見 §8 |
| 跳過錯誤列繼續同步 | | ❌ v1 不支援 |
| 刪除 GCS 舊版強制重寫 | | ❌ |

### 4.4 常見錯誤速查

| 現象 | 可能原因 | 處置 |
|------|----------|------|
| `BDC_TAB_MISSING` | Sheet 分頁名稱不符 Contract | 更名或新增 tab |
| `BDC_REQUIRED_FIELD_MISSING` | 欄位空白或欄名拼錯 | 補欄位；確認英文 snake_case |
| 中文欄位 Fail | 使用「問題」「答案」 | 改為 `question`、`answer` |
| `BDC_TENANT_MISMATCH` | Sheet 內 `tenant_sno` 與 CLI 不符 | 修正 Sheet 或 CLI 參數 |

### 4.5 錯誤等級

對齊 `BATS_DATA_SYNC_POLICY.md` §14.2：

| 等級 | Validation Fail 歸類 | 處理 |
|------|----------------------|------|
| **E1** | 來源 / schema 不符 | 拒絕同步；保留舊版 |
| **E2** | 轉換 / 欄位型別錯誤 | 拒絕同步；`sync_status=failed` |

---

## 5. GCS Write Fail Handling

### 5.1 觸發條件

Validation **已通過**，但 GCS 寫入階段失敗：

| 類型 | 範例 | 錯誤等級 |
|------|------|----------|
| 權限不足 | Service Account 無 prefix 寫入權 | E3 |
| Quota / 配額 | GCS 配額用盡 | E3 |
| 網路 / 逾時 | 上傳中斷 | E3 |
| 部分物件寫入成功 | 5 檔中僅寫入 3 檔 | E3（嚴重） |

### 5.2 標準處置流程

```text
GCS Write Fail
        ↓
1. 記錄 sync_report（status=failed）+ error_report
        ↓
2. 檢查 GCS 既有 5 JSON 是否完整
        ↓
3. 若部分寫入 → 執行 §6 Rollback 評估
        ↓
4. 排查權限 / 網路 / quota
        ↓
5. 修復後重新 dry-run → 再 write
```

### 5.3 部分寫入處理

| 情境 | 處置 |
|------|------|
| 0 檔寫入成功 | 舊版完整保留；修復後重試 |
| 部分檔寫入成功 | **視為不一致狀態**；執行 §6 還原至上一版完整 5 JSON |
| 5 檔全寫入成功 | 正常；更新 `source_revision` |

> v1 **不依賴** GCS Object Versioning；一致性靠 Safety Rule + 手動 rollback。

### 5.4 重試原則

| 規則 | 說明 |
|------|------|
| **有限次數重試** | 網路暫時性錯誤可重試 2～3 次 |
| **重試前確認** | 每次重試前檢查 GCS 現狀 |
| **不可 blind overwrite** | 不確定狀態時先 rollback 再重試 |
| **記錄每次嘗試** | `sync_report` 含 attempt 資訊（若實作） |

### 5.5 E4 Cache 刷新失敗

| 項目 | 說明 |
|------|------|
| **現象** | GCS 已成功，Host A cache 未刷新 |
| **等級** | E4 |
| **影響** | BATS 可能暫用舊 cache |
| **處置** | 告警；手動觸發 cache 刷新；GCS 資料視為正確 |

---

## 6. Rollback / Restore Rule

### 6.1 原則

| 原則 | 說明 |
|------|------|
| **自動保護** | Validation Fail → 系統自動不覆蓋舊 JSON |
| **手動還原** | GCS 寫入後發現問題 → 維運手動還原 |
| **不依賴 Versioning** | v1 不啟用 GCS Object Versioning |
| **完整 5 檔** | rollback 須還原完整 5 JSON，不做 partial |

### 6.2 還原來源優先序

| 優先 | 來源 | 說明 |
|------|------|------|
| **1** | `meta/source_revision.json` | 記錄上一版成功 sync 之參照 |
| **2** | 上一次成功之 `sync_report.json` | `output_files` + checksum |
| **3** | 維運備份 | 離線備份或手動匯出之 5 JSON |
| **4** | dry-run preview（僅參考） | **不可** 直接 promote 未驗證 preview |

### 6.3 Rollback 決策表

| 情境 | 是否需 Rollback | 動作 |
|------|-----------------|------|
| Validation Fail | 否（自動保留） | 修正 Sheet → 重跑 dry-run |
| GCS write 零寫入 | 否 | 修復權限後重試 |
| GCS 部分寫入 | **是** | 還原完整 5 JSON |
| 寫入成功但內容錯誤 | **是** | 還原上一版；修正 Sheet 後重 sync |
| BATS 讀到錯誤答案 | 視情況 | 先確認 GCS 內容；必要時 rollback |

### 6.4 手動 Rollback 步驟（概念）

```text
1. 確認目標 sno 與 GCS prefix
        ↓
2. 從 source_revision / 備份取得上一版 5 JSON
        ↓
3. 驗證備份 5 檔完整性（checksum / 檔名）
        ↓
4. 寫回 tenants/{sno}/knowledge/ 下 5 JSON
        ↓
5. 更新 meta/sync_status.json（status=rolled_back 或註記）
        ↓
6. 觸發 cache 刷新（若適用）
        ↓
7. 記錄維運日誌：原因、操作者、時間、還原來源
```

### 6.5 Rollback 後驗證

| # | 檢查項 |
|---|--------|
| 1 | GCS 5 JSON 與備份一致 |
| 2 | BATS 讀取結果恢復預期 |
| 3 | `sync_status` / 維運日誌已更新 |
| 4 | 根因已記錄（Sheet 錯誤 / 程式 bug / 權限） |

---

## 7. Required Reports

每次 sync（含 dry-run）**至少** 產出下列報告；維運 **必須** 留存以供稽核。

### 7.1 報告總覽

| 檔案 | 成功時 | 失敗時 | 路徑（概念） |
|------|--------|--------|--------------|
| `sync_report.json` | **必須** | **必須** | `reports/` 或 `tenants/{sno}/meta/` |
| `validation_report.json` | **必須** | **必須** | 同上 |
| `error_report.json` | 可省略 | **必須** | 同上 |

### 7.2 `sync_report.json`

**用途：** 同步執行摘要。

| 欄位 | 說明 | 範例 |
|------|------|------|
| `sync_id` | 唯一識別 | `sync-20260608-001` |
| `tenant_sno` | 租戶 sno | `5f99b8d665e8444d` |
| `data_category` | 固定 | `tenant_private_knowledge` |
| `status` | 結果 | `success` / `failed` / `dry_run` |
| `dry_run` | 是否 dry-run | `true` / `false` |
| `started_at` | 開始時間 | ISO 8601 |
| `finished_at` | 結束時間 | ISO 8601 |
| `source_sheet_id` | Sheet ID | Registry 值 |
| `output_files` | 輸出清單 | 5 JSON 檔名 |
| `operator` | 操作者（可選） | 維運帳號 |

**解讀：**

| `status` | 意義 | 下一步 |
|----------|------|--------|
| `dry_run` | 預覽成功，未寫 GCS | 可申請 write |
| `success` | 同步完成 | 驗證 GCS + cache |
| `failed` | 同步失敗 | 讀 error_report；§4 或 §5 |

### 7.3 `validation_report.json`

**用途：** Validation 細節。

| 欄位 | 說明 |
|------|------|
| `passed` | `true` / `false` |
| `tabs_checked` | 5 tabs 各自狀態 |
| `field_errors` | 缺欄位 / 型別錯誤清單 |
| `error_codes` | `BDC_*` 列表 |

**解讀：**

| `passed` | 意義 | 下一步 |
|----------|------|--------|
| `true` | Contract 驗證通過 | 可進入 GCS write |
| `false` | 整檔 Fail | §4；修正 Sheet |

### 7.4 `error_report.json`

**用途：** 失敗時之錯誤定位（**僅失敗時必須**）。

| 欄位 | 說明 |
|------|------|
| `error_level` | E1～E5 |
| `error_code` | 如 `BDC_TAB_MISSING` |
| `message` | 人類可讀訊息 |
| `tab` | 相關 tab（如有） |
| `field` | 相關欄位（如有） |
| `row` | 列號（如有） |

### 7.5 報告留存建議

| 項目 | 建議 |
|------|------|
| **留存位置** | 與 `tenants/{sno}/meta/` 同層或集中 `reports/{sno}/` |
| **留存期限** | 至少保留最近 10 次 sync |
| **關聯** | `sync_id` 串聯三份報告 |

---

## 8. Do Not Do List

下列為 **維運與開發禁止事項**；違反可能導致 production 資料損壞或 SSOT 不一致。

### 8.1 資料與 GCS

| # | 禁止 | 原因 |
|---|------|------|
| 1 | **手動覆蓋 production JSON** | 繞過 Validation / Safety Rule |
| 2 | **直接編輯 GCS `knowledge/*.json`** | 須經 BDS 管線 |
| 3 | **先寫正式路徑再驗證** | 違反 §14.1 tmp → validate → promote |
| 4 | **刪除 GCS 舊版以強制重寫** | 破壞 rollback 能力 |
| 5 | **啟用 GCS Object Versioning 取代 Safety Rule** | v1 明確排除 |

### 8.2 分層與租戶

| # | 禁止 | 原因 |
|---|------|------|
| 6 | **直接改 `shared/` layer** | v1 不寫 shared；見 Ownership §5 |
| 7 | **跨 tenant 寫入** | 違反 Tenant Isolation |
| 8 | **多租戶共用 Sheet** | 違反 Registry / Drive 隔離 |
| 9 | **hardcode `travel_b` 於維運腳本** | Pilot ≠ 架構特例 |

### 8.3 流程與基礎設施

| # | 禁止 | 原因 |
|---|------|------|
| 10 | **設定 Cron 自動同步** | v1 手動 only |
| 11 | **跳過 dry-run 直接 write GCS** | 高風險 |
| 12 | **關閉 feature flag 寫入未授權 tenant** | Controlled Mode 要求 |
| 13 | **批次 sync 全部租戶** | v1 單 tenant |
| 14 | **手動合併 shared + tenant JSON** | 違反 Override 讀取模型 |

### 8.4 格式與 Contract

| # | 禁止 | 原因 |
|---|------|------|
| 15 | **使用中文欄位名稱** | v1 僅英文 snake_case |
| 16 | **單列錯誤跳過繼續** | v1 整檔 Fail |
| 17 | **自行發明第 6 個 JSON 檔** | Contract 固定 5 檔 |

---

## 9. Cross References

### 9.1 BDS 文件體系（完整）

```text
L1 SSOT（政策）
├── BATS_DATA_SYNC_POLICY.md          同步原則、Safety Rule、MVP
├── BATS_DATA_SOURCE_REGISTRY.md      Registry、industry_code
├── BATS_DATA_CONTRACT.md             5 Tab / 5 JSON、Validation
└── BATS_DATA_OWNERSHIP_POLICY.md     歸屬、寫入邊界

L2 規劃 / 維運
├── BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md   實作 Phase 1～6
├── BATS_DATA_SYNC_TEST_PLAN.md             測試 T-01～T-10
└── BATS_DATA_SYNC_RUNBOOK.md（本文件）     手動操作、失敗處理、rollback
```

### 9.2 引用對照

| 文件 | 本文件引用之內容 |
|------|------------------|
| `BATS_DATA_SYNC_POLICY.md` | Mode B、§14 Safety Rule、§14.2 錯誤分類、§15 MVP、§23 Anti Hardcode |
| `BATS_DATA_SOURCE_REGISTRY.md` | §7 Registry Contract、`private_knowledge_sheet_id` |
| `BATS_DATA_CONTRACT.md` | 5 Tab / 5 JSON、§1.4 決策、§8 Validation、`BDC_*` |
| `BATS_DATA_OWNERSHIP_POLICY.md` | Tenant Layer only、禁止寫 shared |
| `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` | Phase 6 命令介面、Required Outputs、Safety Rules |
| `BATS_DATA_SYNC_TEST_PLAN.md` | T-05 不覆蓋、T-06～T-08 reports、Acceptance Criteria |

### 9.3 Runbook 與其他文件關係

| 文件 | 關係 |
|------|------|
| Implementation Plan | 定義 **怎麼做（程式）**；Runbook 定義 **怎麼操作（維運）** |
| Test Plan | 定義 **怎麼驗**；Runbook 失敗處理與 Test 預期一致 |
| Sync Policy §14 | Safety Rule 為 Runbook §4、§6 之政策依據 |

### 9.4 衝突處理

| 議題 | SSOT |
|------|------|
| 維運操作步驟、rollback | **本文件** |
| 同步原則、Fail 不覆蓋 | `BATS_DATA_SYNC_POLICY.md` |
| CLI 參數與 Phase 實作 | `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` |
| 欄位錯誤定義 | `BATS_DATA_CONTRACT.md` |

---

## 10. Phase 4 Verification Record

### 10.1 定位

本節記錄 **BDS Phase 4 Google Sheet Reader** 與 **Phase 4.1 Live Read** 之受控驗證結果；供 Phase 5 啟動前稽核。**非** Production 排程同步紀錄。

### 10.2 驗證環境

| 項目 | 值 |
|------|-----|
| **主機** | 主機 A `103.1.222.14` |
| **專案路徑** | `C:/bbc-ai-bot` |
| **Pilot Tenant** | `travel_b`（`sno`: `5f99b8d665e8444d`） |
| **Spreadsheet** | travel_b private knowledge sheet（`private_knowledge_sheet_id`） |
| **認證** | Service Account `bbc-ai-sync@bbc-ai-saas-platform.iam.gserviceaccount.com` |
| **API 範圍** | Google Sheets API **唯讀** |

### 10.3 Required Tabs 驗證

| Tab | Phase 4.1 Live Read | Phase 4.2 Pipeline Dry-run |
|-----|---------------------|----------------------------|
| `company_profile` | **PASS** | **PASS** |
| `qa` | **PASS** | **PASS** |
| `external_product_links` | **PASS** | **PASS** |
| `service_items` | **PASS** | **PASS** |
| `special_prices` | **PASS** | **PASS** |

**整體結果：** **PASS** ✅

### 10.4 驗證產物（本機）

| 類型 | 路徑（概念） | 說明 |
|------|--------------|------|
| Knowledge JSON preview | `tests/bds/output/tenants/{sno}/knowledge/*.json` | Phase 4.2；**非 GCS** |
| Reports | `tests/bds/output/tenants/{sno}/reports/` | `sync_report.json`、`validation_report.json` |

### 10.5 本階段未執行

| 項目 | 原因 |
|------|------|
| GCS 寫入 | Phase 5 |
| Google Drive API | Phase 6 Drive Source Connector |
| Shared Layer 讀取 | v1 Out of Scope |
| `private_drive_folder_id` Registry | 延後 Phase 6 前規劃 |

### 10.6 相關測試指令（維運參考）

```bash
# Phase 4 Reader（含 mock + 可選 live）
C:/Web/xampp/php/php.exe tests/bds/test_bds_google_sheet_reader.php

# Phase 4.2 Pipeline Dry-run（需設定 Sheet ID / sno / credentials 環境變數）
C:/Web/xampp/php/php.exe tests/bds/test_bds_google_sheet_pipeline_dry_run.php
```

---

## 11. Phase 5 Safety Boundary

### 11.1 定位

本節定義 **BDS Phase 5 GCS Writer Controlled Mode** 之維運安全邊界；實作前須與 `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` Phase 5、`BATS_DATA_SYNC_POLICY.md` §14 Safety Rule 對齊。

### 11.2 核心規則

```text
Validation Fail
        ↓
不寫 GCS（零 promote）
        ↓
保留 GCS 既有正式 JSON
```

| 規則 | 說明 |
|------|------|
| **Validation Fail → 不寫 GCS** | 任一 `BDC_*` 錯誤 → 禁止寫入 `tenants/{sno}/knowledge/` |
| **雙重門禁** | `BDS_DRY_RUN=true` 或 `BDS_GCS_WRITE_ENABLED=false` → 零 GCS 寫入 |
| **單一 tenant** | `BDS_TARGET_SNO` 須與 Registry / CLI `--sno` 一致 |

### 11.3 僅允許寫入路徑

| 允許 | 路徑 |
|------|------|
| **Tenant Private Knowledge** | `tenants/{sno}/knowledge/` 下 5 JSON |

**正式檔名：** `company_profile.json`、`service_qa.json`、`external_product_links.json`、`service_items.json`、`special_prices.json`

### 11.4 明確禁止（Phase 5）

| 禁止 | 說明 |
|------|------|
| **`shared/`** | 禁止寫入任何 Shared Layer 路徑 |
| **Google Drive** | Phase 5 不呼叫 Drive API；不寫入 Drive |
| **Delete Object** | 禁止以刪除 GCS 物件作為同步或 rollback 預設手段 |
| **跨 tenant 路徑** | 禁止寫入 `tenants/{other_sno}/` |
| **未驗證 promote** | 禁止跳過 Validator 直接寫 GCS |

### 11.5 與 Phase 4 dry-run 差異

| 項目 | Phase 4.2 dry-run | Phase 5 GCS Write |
|------|-------------------|-------------------|
| **輸出位置** | `tests/bds/output/tenants/{sno}/` | `gs://{bucket}/tenants/{sno}/knowledge/` |
| **預設** | 允許（本機） | **拒絕**（須明確啟用 flag） |
| **Validation Fail** | 不產 knowledge JSON | 不寫 GCS |

### 11.6 相關文件

| 文件 | 章節 |
|------|------|
| `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` | Phase 5 |
| `BATS_DATA_SYNC_TEST_PLAN.md` | Phase 5 Test Matrix |
| `BATS_DATA_OWNERSHIP_POLICY.md` | §3 Write Boundary |

---

## 12. Phase 5 Close-out Verification Record

### 12.1 定位

本節記錄 **BDS Phase 5 GCS Writer Controlled Mode**（5A～5D）之受控驗證與 **Read-back Verification** 結果；標記 Phase 5 **Completed**。**非** Production 排程同步紀錄。

### 12.2 驗證環境

| 項目 | 值 |
|------|-----|
| **主機** | 主機 A `103.1.222.14` |
| **專案路徑** | `C:/bbc-ai-bot` |
| **Pilot Tenant** | `travel_b`（`sno`: `5f99b8d665e8444d`） |
| **GCS Bucket** | `bbc-ai-saas-data` |
| **GCS Prefix** | `tenants/5f99b8d665e8444d/knowledge/` |
| **認證** | Service Account `bbc-ai-sync@bbc-ai-saas-platform.iam.gserviceaccount.com` |
| **IAM** | Bucket 層級 **Storage Object Admin** |

### 12.3 Phase 5 里程碑

| 子階段 | 結果 | 說明 |
|--------|------|------|
| **5A Controlled Preview** | **PASS** ✅ | Upload Plan / Payload Preview；零 GCS 寫入 |
| **5B GCS Preflight** | **PASS** ✅ | SA token；`objects.list` HTTP 200 |
| **5C Controlled Real Write** | **PASS** ✅ | 5 JSON 首次寫入 GCS |
| **5D Read-back Verification** | **PASS** ✅ | `objects.list` 5 物件；逐一 read-back + Schema |

**整體結果：** **PASS** ✅ — Phase 5 **Completed**

### 12.4 GCS 物件驗證（Phase 5D）

| 物件 | Read-back | Schema | 路徑 |
|------|-----------|--------|------|
| `company_profile.json` | **PASS** | **PASS** | `tenants/5f99b8d665e8444d/knowledge/` |
| `service_qa.json` | **PASS** | **PASS** | 同上 |
| `external_product_links.json` | **PASS** | **PASS** | 同上 |
| `service_items.json` | **PASS** | **PASS** | 同上（`items[]` 目前為空；結構有效） |
| `special_prices.json` | **PASS** | **PASS** | 同上 |

### 12.5 驗證產物（本機）

| 類型 | 路徑（概念） | 說明 |
|------|--------------|------|
| Close-out Report | `tests/bds/output/phase5_closeout_report.json` | Phase 5D 彙總 |
| Write Report | `tests/bds/output/tenants/{sno}/gcs/write_report.json` | Phase 5C 寫入紀錄 |
| Rollback Backup | `tests/bds/output/tenants/{sno}/gcs/rollback_backup/` | 寫入前既有物件備份（若有） |

### 12.6 相關測試指令（維運參考）

```bash
# Phase 5A Controlled Preview
C:/Web/xampp/php/php.exe tests/bds/test_bds_gcs_writer.php

# Phase 5C Controlled Real Write（須雙重門禁環境變數）
C:/Web/xampp/php/php.exe tests/bds/test_bds_gcs_real_write.php

# Phase 5D Read-back Verification（唯讀）
C:/Web/xampp/php/php.exe tests/bds/test_bds_gcs_readback_verification.php
```

### 12.7 Phase 6 前提醒（Google Drive Folder Governance）

| 原則 | 說明 |
|------|------|
| **一旅行社一專屬 Folder** | 每 Tenant 一個 Google Drive Folder |
| **營運帳號** | 目前使用 `bbcshops88@gmail.com` Google Drive |
| **Default Private** | Folder 預設 Private |
| **禁止提前新增 Registry 欄位** | **不得** 新增 `private_drive_folder_id` 至 Registry JSON Schema |
| **Framework** | 見 `BATS_DATA_SOURCE_REGISTRY.md` §10.5 |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.3** | 2026-06-10 | 新增 §12 Phase 5 Close-out Verification Record（5A～5D PASS） |
| **v1.2** | 2026-06-09 | 新增 §11 Phase 5 Safety Boundary |
| **v1.1** | 2026-06-09 | 新增 §10 Phase 4 Verification Record（Live Read + Pipeline Dry-run PASS） |
| **v1.0** | 2026-06-08 | 第一版：手動同步流程、Checklist、失敗處理、Rollback、Reports、Do Not Do List |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | Draft — 待審核 |
| **適用實作** | Phase 6 Manual Sync Command 完成後正式啟用 |
| **BDS 文件補齊** | 本文件為補齊階段 **最後一份** |
| **Pilot** | `travel_b`（`5f99b8d665e8444d`） |
