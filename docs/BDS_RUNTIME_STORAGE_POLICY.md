# BDS_RUNTIME_STORAGE_POLICY.md

**專案：** BBC AI SaaS / BATS / BDS / Travel Data Center  
**定位：** L1 架構政策層 — BDS Runtime Storage Governance + GCS Object Versioning Policy  
**階段：** Phase 6A.1（Phase 6A Manual Sync 驗收後；Phase 6B Drive Connector 前）  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_DATA_SYNC_POLICY.md`、`BATS_DATA_CONTRACT.md`、`BATS_DATA_SOURCE_REGISTRY.md`、`BATS_DRIVE_CONNECTOR_SCOPE.md`、`BATS_DRIVE_GCS_MAPPING.md`、`BATS_DATA_SYNC_RUNBOOK.md`、`RECOVERY_AND_ROLLBACK_POLICY.md`  
**適用對象：** ChatGPT、Cursor、開發者、維運人員  
**衝突處理：** Runtime 目錄與本地工作區治理以 **本文件為準**；GCS Knowledge 路徑與 Data Contract 以 `BATS_DATA_CONTRACT.md` 為準；同步 Safety Rule 以 `BATS_DATA_SYNC_POLICY.md` 為準；Recovery 架構自 Phase 6A.1 起以 **本文件 §7～§9** 為 GCS Knowledge 還原權威。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §2 | Architecture Context（已確認決議） |
| §3 | Runtime Root |
| §4 | Tenant Runtime Structure |
| §5 | Runtime Directory Definition |
| §6 | Runtime Layer Boundary |
| §7 | Multi-Tenant Isolation Policy |
| §8 | Git Policy |
| §9 | Recovery Architecture |
| §10 | GCS Object Versioning Policy |
| §11 | Lifecycle Policy |
| §12 | rollback_backup 定位（Transitional Safety Layer） |
| §13 | GCS Enablement Plan（僅文件化） |
| §14 | Compatibility & SSOT Cross-Reference |
| §15 | Future Governance Notes |

---

## 1. Purpose

### 1.1 文件定位

本文件定義 BDS **Host A 本地 Runtime 工作區**（`var/bds/`）之治理規則，以及 **GCS Knowledge 層**之正式 Recovery Architecture（Object Versioning + Lifecycle）。

### 1.2 Phase 6A.1 目標

| 目標 | 說明 |
|------|------|
| **Runtime Storage Governance** | 明確 `var/bds/` 角色、目錄責任、租戶隔離、Git 政策 |
| **GCS Object Versioning Policy** | 將 GCS Object Versioning 定為 Primary Recovery Architecture |
| **Transitional Layer 定位** | 明確 `rollback_backup` 為過渡性安全層，非正式 Recovery Source |

### 1.3 本文件不做

| 不做 | 說明 |
|------|------|
| 修改 BDS Pipeline / Runtime Logic | Phase 6A.1 僅文件化 |
| 實際啟用或修改 GCS Bucket 設定 | 見 §13 Enablement Plan |
| 移除 `rollback_backup` 實作 | 未來 Phase 再評估 |
| 定義 Google Drive Connector 行為 | 見 Phase 6B `BATS_DRIVE_*` |

---

## 2. Architecture Context（已確認決議）

### 2.1 Source 角色

| 載體 | 角色 |
|------|------|
| **Google Sheet** | Structured Knowledge **Input** Source |
| **Google Drive** | **Archive** Source |
| **GCS** | **Runtime Knowledge** Source |
| **Future RAG** | **GCS Only** |

### 2.2 Knowledge Priority

```text
Tenant Private
    >
Industry Shared
    >
Global Shared
    >
Human Service
```

**交叉引用：** `BATS_DATA_SOURCE_REGISTRY.md` §4.4、`BATS_SHARED_KNOWLEDGE_CONTRACT.md`

### 2.3 Recovery Architecture（Phase 6A.1 正式決議）

```text
GCS Object Versioning          ← Primary Recovery Architecture
        +
Lifecycle Rule               ← Delete noncurrent versions > 30 days
        +
rollback_backup (local)        ← Transitional Safety Layer（非 Primary）
```

---

## 3. Runtime Root

### 3.1 正式路徑

| 項目 | 值 |
|------|-----|
| **Runtime Root** | `var/bds/`（Host A 專案根目錄下） |
| **權威邊界** | 單一 Host A 工作機器本地磁碟 |
| **產生方式** | `bin/bds-sync.php` 及 `BdsJsonWriter` / `BdsGcsUploader` 於 sync 執行時自動建立 |

### 3.2 Runtime Root 是什麼

`var/bds/` 為 **Runtime Working Directory**（同步管線執行時的本地工作區）。

### 3.3 Runtime Root 不是什麼

| 禁止角色 | 說明 |
|----------|------|
| **SSOT** | 非任何知識或設定的權威來源 |
| **Structured Knowledge Source** | Sheet 才是 Structured Input |
| **Runtime Knowledge Source** | GCS `tenants/{sno}/knowledge/` 才是 Runtime 權威 |
| **Gemini Source** | BATS 從 GCS（+ cache mirror）讀取 |
| **RAG Source** | Future RAG 僅 GCS |
| **Recovery Source** | 正式還原依 GCS Object Versioning（§10） |

### 3.4 可重建性原則

`var/bds/` 內所有內容 **必須可由下列來源重新建構**：

```text
Google Sheet（Registry private_knowledge_sheet_id）
        +
GCS（tenants/{sno}/knowledge/*.json）
```

刪除 `var/bds/` **不得** 影響 GCS 正式 Knowledge 或 Google Sheet 來源。

---

## 4. Tenant Runtime Structure

### 4.1 正式結構圖

```text
var/bds/
└── tenants/
    └── {sno}/
        ├── knowledge/
        ├── reports/
        ├── gcs/
        │   ├── write_report.json
        │   └── rollback_backup/
        └── (future subdirs — 須先修訂本文件)
```

> `{sno}` 為 Registry 權威租戶邊界（如 pilot：`5f99b8d665e8444d`）。

### 4.2 與 GCS 路徑對照

| 本地 Runtime | GCS 正式路徑 | 關係 |
|--------------|--------------|------|
| `var/bds/tenants/{sno}/knowledge/*.json` | `gs://bbc-ai-saas-data/tenants/{sno}/knowledge/*.json` | 本地 preview；GCS 為 Runtime 權威 |
| `var/bds/tenants/{sno}/reports/` | （無對應 GCS 正式路徑） | 僅本地稽核 |
| `var/bds/tenants/{sno}/gcs/` | （無對應 GCS 正式路徑） | 上傳操作紀錄與過渡備份 |

---

## 5. Runtime Directory Definition

### 5.1 `knowledge/`

| 項目 | 說明 |
|------|------|
| **用途** | `BdsJsonWriter::writeDryRun()` 產出之本地 JSON preview |
| **檔案** | 5 個 Knowledge JSON（對齊 `BATS_DATA_CONTRACT.md`） |
| **生命週期** | 每次 sync 覆寫；可隨時刪除並由下次 sync 重建 |
| **是否權威** | **否** — GCS 為 Runtime 權威 |

### 5.2 `reports/`

| 項目 | 說明 |
|------|------|
| **用途** | 同步稽核報告 |
| **典型檔案** | `sync_report.json`、`validation_report.json`、`error_report.json`（如有） |
| **生命週期** | 每次 sync 更新；保留供維運排查 |
| **是否權威** | **否** |

### 5.3 `gcs/`

| 項目 | 說明 |
|------|------|
| **用途** | GCS 寫入操作之本地紀錄目錄 |
| **典型檔案** | `write_report.json`（`BdsGcsUploader` 產出） |
| **子目錄** | `rollback_backup/`（見 §12） |
| **是否權威** | **否** |

### 5.4 `rollback_backup/`

| 項目 | 說明 |
|------|------|
| **完整路徑** | `var/bds/tenants/{sno}/gcs/rollback_backup/` |
| **用途** | GCS Upload **前**下載並保留之上一版 JSON 快照 |
| **產生者** | `BdsGcsUploader::backupExistingObject()` |
| **定位** | **Transitional Safety Layer**（§12） |
| **是否權威** | **否** — 非 Recovery Source |

---

## 6. Runtime Layer Boundary

### 6.1 分層對照

```text
┌─────────────────────────────────────────────────────────────┐
│  Input Layer                                                │
│  Google Sheet（Structured Knowledge Input）                  │
└───────────────────────────┬─────────────────────────────────┘
                            │ BDS Sync Pipeline
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  Local Runtime Working Layer（本文件治理）                   │
│  var/bds/ — preview、reports、transitional backup             │
│  ❌ 非 Runtime Source / ❌ 非 Recovery Source                 │
└───────────────────────────┬─────────────────────────────────┘
                            │ GCS Upload（受控門禁）
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  Runtime Knowledge Layer                                    │
│  gs://bbc-ai-saas-data/tenants/{sno}/knowledge/*.json        │
│  ✅ Runtime Source / ✅ BATS & Gemini 讀取層                  │
└───────────────────────────┬─────────────────────────────────┘
                            │ Object Versioning（§10）
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  Recovery Layer                                             │
│  GCS noncurrent object versions（Primary Recovery）           │
└─────────────────────────────────────────────────────────────┘
```

### 6.2 禁止行為

| 禁止 | 說明 |
|------|------|
| 將 `var/bds/` 作為 BATS Runtime 讀取來源 | 違反 GCS Runtime 規則 |
| 將 `rollback_backup/` 作為正式還原依據 | 違反 §9 Recovery Architecture |
| 跨 tenant 共用 `var/bds/` 子目錄 | 違反 §7 隔離政策 |
| 將 `var/bds/` commit 至 Git | 違反 §8 Git Policy |

---

## 7. Multi-Tenant Isolation Policy

### 7.1 路徑隔離

| 規則 | 說明 |
|------|------|
| **一 sno 一目錄** | 所有 runtime 檔案位於 `var/bds/tenants/{sno}/` 下 |
| **禁止跨 sno 讀寫** | Pipeline 不得將 tenant A 資料寫入 tenant B 目錄 |
| **Registry 驅動** | `{sno}` 僅從 BDS Source Registry 解析，禁止 hardcode |

### 7.2 與 GCS 隔離對齊

本地 `var/bds/tenants/{sno}/` 必須與 GCS `tenants/{sno}/` prefix 一致（對齊 `BATS_DATA_SYNC_POLICY.md` §10）。

### 7.3 Pilot 與多租戶擴展

| 項目 | 說明 |
|------|------|
| **Pilot** | `travel_b` / `5f99b8d665e8444d` 為驗收用例，非架構特例 |
| **新增租戶** | 僅新增 Registry entry；`var/bds/tenants/{new_sno}/` 於首次 sync 自動建立 |

---

## 8. Git Policy

### 8.1 正式規則

| 路徑 | Git 政策 |
|------|----------|
| `var/bds/` | **必須忽略（MUST NOT commit）** |
| `var/bds/tenants/{sno}/knowledge/` | 本地 preview；不進版控 |
| `var/bds/tenants/{sno}/reports/` | 稽核報告；不進版控 |
| `var/bds/tenants/{sno}/gcs/rollback_backup/` | 過渡備份；不進版控 |

### 8.2 `.gitignore` 建議（Phase 6A.1 檢查結果）

截至 Phase 6A.1 檢查，專案 `.gitignore` **尚未** 包含 `var/` 或 `var/bds/`。

**建議新增（未來 commit 時一併處理）：**

```gitignore
# BDS Runtime Working Directory (Phase 6A.1)
var/bds/
```

或更寬鬆：

```gitignore
var/
```

### 8.3 允許進版控者

| 允許 | 說明 |
|------|------|
| `bin/bds-sync.php` | CLI 入口 |
| `config/bds_source_registry.php` | Registry config |
| `core/bds/*.php` | BDS 元件 |
| `docs/BDS_RUNTIME_STORAGE_POLICY.md` | 本文件 |

---

## 9. Recovery Architecture

### 9.1 正式 Recovery 層級

| 優先序 | 機制 | 角色 |
|--------|------|------|
| **1（Primary）** | **GCS Object Versioning** | 正式 Recovery Architecture |
| **2（Operational）** | Safety Rule（Validation Fail 不覆蓋） | 同步失敗時保留 current 版 |
| **3（Transitional）** | `rollback_backup/` 本地快照 | 上傳前快速回復輔助 |
| **4（Manual）** | 重新執行 `bin/bds-sync.php` | 由 Sheet + Pipeline 重建 |

### 9.2 Recovery 情境對照

| 情境 | 正式還原方式 |
|------|--------------|
| Validation Fail | Safety Rule — GCS **不變**；無需還原 |
| GCS Upload 部分失敗 | 檢查 `write_report.json`；必要時從 **GCS Object Version** 或 `rollback_backup` 還原 |
| 寫入後發現內容錯誤 | **GCS Object Versioning** 還原至前一 noncurrent 版 |
| Host A 磁碟遺失 `var/bds/` | 從 Sheet 重新 sync；GCS current 版不受影響 |
| 需要歷史版本（≤30 天） | GCS Object Versioning |
| 需要歷史版本（>30 天） | Lifecycle 已清除 noncurrent 版；需從 Sheet 重建或離線備份 |

### 9.3 與 Safety Rule 關係

GCS Object Versioning **不取代** Safety Rule：

| 機制 | 職責 |
|------|------|
| **Safety Rule** | 阻止 invalid 資料成為 current 版 |
| **Object Versioning** | 保留 valid 寫入的歷史 current 版，供還原 |

---

## 10. GCS Object Versioning Policy

### 10.1 正式定位

```text
GCS Object Versioning = Primary Recovery Architecture
```

### 10.2 適用 Bucket

| 項目 | 值 |
|------|-----|
| **Bucket** | `bbc-ai-saas-data` |
| **適用前綴** | `tenants/{sno}/knowledge/`（BDS v1 Knowledge JSON） |
| **啟用範圍** | Bucket-level versioning（建議全 bucket 啟用；Knowledge 路徑為主要受益區） |

### 10.3 行為說明

| 行為 | 說明 |
|------|------|
| **Overwrite 同一路徑** | 舊版成為 **noncurrent version**；新版為 **current** |
| **BDS Upload** | `BdsGcsUploader` 對同 `object_path` 上傳 — 與 versioning **相容** |
| **Read-back Verification** | 讀取 **current** 版 — 與 versioning **相容** |
| **listKnowledgeObjects** | 列出 **current** 物件 — 與 versioning **相容** |
| **還原** | 將指定 generation 設為 current，或複製 noncurrent 至新路徑 |

### 10.4 禁止

| 禁止 | 說明 |
|------|------|
| 以 Versioning 取代 Validation Gate | Safety Rule 仍須遵守 |
| 以刪除 current 物件作為 rollback 預設手段 | 見 Runbook |
| 將 Versioning 用於 `shared/`（未授權時） | Shared 同步尚未啟用 |

---

## 11. Lifecycle Policy

### 11.1 正式規則

```text
Delete noncurrent object versions older than 30 days
```

### 11.2 政策細節

| 項目 | 說明 |
|------|------|
| **作用對象** | **noncurrent** object versions only |
| **保留期** | 30 天 |
| **Current 版** | **不受影響** — Lifecycle 不刪除 current 物件 |
| **目的** | 控制儲存成本；保留合理還原窗口 |

### 11.3 與 BDS Runtime 相容性

| 元件 | 影響 |
|------|------|
| `BdsGcsUploader` | 無 — 僅寫入 current |
| Read-back Verification | 無 — 驗證 current |
| `rollback_backup` | 無直接依賴 — 本地過渡層獨立於 GCS lifecycle |
| BATS Runtime 讀取 | 無 — 讀 current |

---

## 12. rollback_backup 定位（Transitional Safety Layer）

### 12.1 正式定義

```text
rollback_backup = Pre-upload GCS JSON Temporary Backup
                  = Transitional Safety Layer
```

### 12.2 用途

| 項目 | 說明 |
|------|------|
| **時機** | GCS Upload **之前** |
| **內容** | 既有 `tenants/{sno}/knowledge/*.json` 下載快照 |
| **目的** | 同步失敗或上傳中斷時之**快速本地回復** |

### 12.3 rollback_backup 不是什麼

| 禁止角色 | 說明 |
|----------|------|
| **Knowledge Source** | Sheet 才是 Input |
| **Runtime Source** | GCS 才是 Runtime |
| **Gemini Source** | 不注入 context |
| **RAG Source** | 不進向量庫 |
| **Recovery Source** | Primary Recovery = GCS Object Versioning |

### 12.4 實作現況（Phase 6A）

| 項目 | 值 |
|------|-----|
| **產生者** | `BdsGcsUploader` |
| **路徑** | `var/bds/tenants/{sno}/gcs/rollback_backup/` |
| **Phase 6A.1** | **保留** — 本次不得實作移除 |

### 12.5 未來演進（Phase 6B+ 再評估）

當 GCS Object Versioning **已正式啟用並完成驗證**後，`rollback_backup` 可：

| 選項 | 說明 |
|------|------|
| **A** | 保留最近一次備份 |
| **B** | 保留 24～72 小時後自動清除 |
| **C** | 完全移除本地備份邏輯 |

> **Phase 6A.1 不實作任何移除。** 僅文件化決策框架。

---

## 13. GCS Enablement Plan（僅文件化）

> **注意：** 本節為操作規劃。Phase 6A.1 **不得** 實際修改 GCS。執行須另開維運 Change Request。

### 13.1 Google Cloud Console 啟用位置

| 步驟 | 路徑 |
|------|------|
| 1 | [Google Cloud Console](https://console.cloud.google.com/) |
| 2 | 選擇專案：`bbc-ai-saas-platform`（或實際持有 bucket 之專案） |
| 3 | **Navigation** → **Cloud Storage** → **Buckets** |
| 4 | 點選 **`bbc-ai-saas-data`** |
| 5 | **Configuration** 分頁 → **Object versioning** → **Enable** |
| 6 | **Lifecycle** 分頁 → **Add a rule** |

### 13.2 gcloud 指令

**啟用 Object Versioning：**

```bash
gcloud storage buckets update gs://bbc-ai-saas-data --versioning
```

**驗證 Versioning 狀態：**

```bash
gcloud storage buckets describe gs://bbc-ai-saas-data --format="json(versioning)"
```

### 13.3 Lifecycle Rule 設定

**Console 設定：**

| 欄位 | 值 |
|------|-----|
| **Action** | Delete object |
| **Object conditions** | **Noncurrent** object versions |
| **Age** | **30** days |
| **Prefix（可選）** | `tenants/`（建議限制於 tenant knowledge 區；或全 bucket 若僅 BDS 使用） |

**gcloud lifecycle JSON 範例（`lifecycle.json`）：**

```json
{
  "lifecycle": {
    "rule": [
      {
        "action": { "type": "Delete" },
        "condition": {
          "daysSinceNoncurrentTime": 30,
          "matchesPrefix": ["tenants/"]
        }
      }
    ]
  }
}
```

**套用：**

```bash
gcloud storage buckets update gs://bbc-ai-saas-data --lifecycle-file=lifecycle.json
```

### 13.4 驗收步驟

| # | 步驟 | 預期 |
|---|------|------|
| 1 | `gcloud storage buckets describe gs://bbc-ai-saas-data` | `versioning.enabled: true` |
| 2 | 執行一次受控 sync（pilot tenant） | 5 JSON upload PASS |
| 3 | `gcloud storage objects list gs://bbc-ai-saas-data/tenants/5f99b8d665e8444d/knowledge/ --all-versions` | 可見多個 generation |
| 4 | Read-back Verification | current 版 PASS |
| 5 | 手動還原測試（noncurrent → current） | 還原成功 |
| 6 | 確認 Lifecycle rule 存在 | noncurrent > 30d 刪除規則已設定 |
| 7 | 記錄於維運 log | Change Request 結案 |

---

## 14. Compatibility & SSOT Cross-Reference

### 14.1 COMPATIBILITY CHECK（BDS Runtime / Upload / Read-back）

| 元件 | Versioning 相容 | 說明 |
|------|-----------------|------|
| `bin/bds-sync.php` | ✅ | 編排層無假設「無版本」 |
| `BdsGcsUploader` | ✅ | 覆寫同 path → 產生 noncurrent 版 |
| `BdsGcsWriter` | ✅ | 路徑規則不變 |
| Read-back Verification | ✅ | 驗證 current 版 |
| `rollback_backup` | ✅ | 與 versioning 互補，非互斥 |
| Lifecycle 30d | ✅ | 僅影響 noncurrent；不影響 current |

**結論：COMPATIBILITY CHECK PASS**

### 14.2 SSOT 一致性

| 文件 | 對齊狀態 |
|------|----------|
| `BATS_DATA_CONTRACT.md` | ✅ Knowledge 路徑、5 JSON 不變 |
| `BATS_DATA_SOURCE_REGISTRY.md` | ✅ Registry 驅動、`gcs_prefix` 不變 |
| `BATS_DATA_SYNC_POLICY.md` | ✅ Safety Rule、Mode B、GCS 結構一致；§14.5 已提及 versioning 策略 |
| `BATS_DRIVE_CONNECTOR_SCOPE.md` | ✅ Sheet=Input、Drive=Archive、GCS=Runtime 一致 |
| `BATS_DRIVE_GCS_MAPPING.md` | ✅ 路徑映射不變 |

### 14.3 歷史文件演進說明（Cross-Ref Debt）

下列文件在 Phase 6A 前記載「MVP versioning OFF / 不依賴 versioning」：

| 文件 | 原表述 |
|------|--------|
| `BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md` §4.4、§6A.10 | 不啟用 / 不依賴 Object Versioning |
| `BATS_DATA_SYNC_RUNBOOK.md` §Rollback | v1 不依賴 Versioning |
| `BATS_DRIVE_CONNECTOR_SCOPE.md` §10 | MVP 維持 OFF |
| `BATS_DRIVE_GCS_MAPPING.md` §12 | MVP 維持 OFF |

**Phase 6A.1 決議：** 上述表述為 **Phase 5～6A 實作期過渡護欄**。自本文件生效起，**Recovery Architecture** 改以 **GCS Object Versioning 為 Primary**；Safety Rule 與 Validation Gate **不變**。

**建議（未來文件同步，非 Phase 6A.1 範圍）：** 於上述文件新增 cross-ref 至本文件 §9～§11，更新 versioning 狀態為「Phase 6A.1 起 Primary Recovery（待 Enablement）」。

**結論：SSOT CHECK PASS**（核心契約與架構無衝突；歷史 versioning OFF 表述已由此文件正式演進覆蓋 Recovery 範圍）

---

## 15. Future Governance Notes

### 15.1 Phase 6B 前檢查清單

- [ ] GCS Object Versioning 依 §13 完成 Enablement
- [ ] Lifecycle 30d 規則驗證
- [ ] `.gitignore` 新增 `var/bds/`
- [ ] 歷史 SSOT 文件 cross-ref 更新
- [ ] `rollback_backup` 保留策略再評估

### 15.2 儲存成本治理

| 項目 | 說明 |
|------|------|
| **Versioning 成本** | noncurrent 版累積；Lifecycle 30d 控制 |
| **本地磁碟** | `var/bds/` 可定期清理；不影響 GCS |
| **監控** | 建議監控 bucket 儲存量與 noncurrent 比例 |

### 15.3 與 Phase 6B Drive Archive 關係

Drive Connector（Phase 6B+）promote 至 `tenants/{sno}/archive/`（**GCS** 概念路徑），**不覆蓋** `knowledge/*.json`。Object Versioning 同樣適用於 archive 物件之 Recovery，但 Structured Knowledge 仍以 Sheet → GCS knowledge 管線為準。

**Drive 來源路徑**（Archive Source）以 `BATS_DATA_SOURCE_REGISTRY.md` §6.5 為準：**Industry First, Tenant Second**（`industries/{industry_code}/tenants/{tenant_key}/01_Private_Layer/`）。本文件 **不修改** GCS Runtime Knowledge 路徑。

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.1** | 2026-06-06 | Phase 6B-1D：§15.3 Drive 來源路徑 cross-ref §6.5（GCS 不變） |
| **v1.0** | 2026-06-05 | Phase 6A.1 初版：Runtime Storage Governance + GCS Object Versioning Policy |
