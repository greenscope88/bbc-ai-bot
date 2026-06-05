# RECOVERY_AND_ROLLBACK_POLICY.md

**專案：** BBC AI SaaS / BATS / ETT / API Gateway / Travel Data Center  
**定位：** L1 架構政策層 — Recovery 與 Rollback 正式 SSOT  
**對應：** `CO_WORK_POLICY.md` §14 Recovery First 原則  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**適用對象：** ChatGPT、Cursor、開發者、維運人員  
**衝突處理：** 若與下層功能文件衝突，**以本文件為準**；若與 `CO_WORK_POLICY.md` 衝突，**以上層為準**。

---

## 文件目的

本文件定義 BBC AI SaaS / BATS / ETT 在變更、部署、故障處理時的 **Recovery（恢復）** 與 **Rollback（回滾）** 標準流程。

涵蓋以下情境的正式依據：

| 術語 | 定義 |
|------|------|
| **Recovery** | 將系統或服務恢復至可接受運作狀態（含還原設定、資料、程式版本） |
| **Rollback** | 將變更撤銷至已知安全狀態（git revert、設定還原、schema 還原等） |
| **Restore** | 從備份或前一版本還原檔案、設定或資料庫 |
| **Emergency Fix** | P1 生產故障下的緊急修復（可先恢復服務，事後必須補文件與復盤） |

**目標：** 所有變更 **可恢復、可追蹤、可驗證**；功能開發速度不得優先於 Recovery 能力。

---

## Recovery First 原則

本節為 `CO_WORK_POLICY.md` §14 之 **L1 實作依據**。

### 核心原則（引用 CO_WORK_POLICY）

| 原則 | 要求 |
|------|------|
| **可恢復** | 每項變更須有明確還原路徑（git、備份、config 關閉、feature flag） |
| **可追蹤** | 變更須有 Commit、備份檔名、操作紀錄、Revision History |
| **可驗證** | 還原或修復後須執行驗證清單，確認服務正常 |

### 與上層政策的關係

- **Commit = Recovery Point**（本機還原點）
- **Push ≠ Recovery Point**（遠端同步不等於可立即還原生產）
- **Git Safe** 為 Recovery 機制之一部分（見 `CO_WORK_POLICY.md` §5、§9）
- **上線前** 必須通過 **Rollback Gate**（見本文 §Rollback Gate）

---

## Recovery 分級

依影響範圍與緊急程度分級，對應 `CO_WORK_POLICY.md` §6 P1 / P2 / P3。

### P1 — Production 中斷（立即處理）

**定義：** 服務不可用或客戶廣泛受影響。

**範例：**

| 類別 | 範例 |
|------|------|
| **Apache** | httpd 無法啟動、全站 403/502 |
| **IIS** | App Pool 持續崩潰、站台無法回應 |
| **SQL** | 無法連線、資料庫毀損、migration 失敗導致服務中斷 |
| **API** | Host B / API Gateway 完全無回應 |
| **LINE OA** | Webhook 無法回覆、簽章驗證全面失敗 |
| **網站** | 關鍵網域無法開啟（含短網址跳轉失效） |

**處置：** 立即 Recovery / Emergency Hotfix；事後補文件、Commit、Root Cause、Tech Debt。

### P2 — 部分功能異常

**定義：** 服務仍運作，但特定功能錯誤或降級。

**範例：**

- 多商品源連結錯誤但主搜尋仍可用
- 短網址 fail-open 全顯示長鏈
- 單一 tenant 設定錯誤

**處置：** 評估是否立即 Rollback 或排期修復；記錄 `TECH_DEBT.md`。

### P3 — 改善與優化

**定義：** 不影響現行服務的架構改善、可讀性、長期優化。

**處置：** 納入 Roadmap；仍須遵守 Recovery 流程，但可不觸發 Emergency Hotfix。

---

## Git Recovery

### 標準流程

```
修改
  ↓
測試
  ↓
Stage（whitelist，禁止 git add .）
  ↓
Commit
  ↓
驗證
```

### 失敗時還原

| 情境 | 動作 |
|------|------|
| 工作區未 commit 的錯誤修改 | `git restore <file>` |
| 已 stage 但尚未 commit | `git restore --staged` + `git restore` |
| 需回到前一安全 commit | `git reset`（依團隊授權與是否已 push 選擇模式） |
| 已 push 且需撤銷 | `git revert`（優先於 force push） |

### 原則

| 原則 | 說明 |
|------|------|
| **Commit = Recovery Point** | 每個安全 Commit 為本機還原錨點 |
| **Push ≠ Recovery Point** | Push 不取代本機驗證與 Rollback 計畫 |
| **單檔單目的 Commit** | 便於 `git revert` 精準還原 |
| **禁止未授權 force push** | 見 `CO_WORK_POLICY.md` §5.4 |

---

## Apache Recovery

**適用：** Host A（及共用 legacy www 路徑之 HTTPS vhost）。

### 標準流程

```
備份（含時間戳檔名）
  ↓
修改設定
  ↓
httpd -t（語法檢查）
  ↓
Restart Apache
  ↓
驗證（網站、rewrite、短網址）
```

### 失敗時

**立即還原備份**，重新 `httpd -t` 後再啟動；不得在未通過 `-t` 時強制上線。

### 案例紀錄

| 日期 | 案例 | 摘要 |
|------|------|------|
| 2026-06-05 | **bbcshops Rewrite Recovery** | `httpd-ssl.conf` RewriteRule 修正；備份 `httpd-ssl.conf.bak.before_bbcshops_shorturl_fix_*`；修正後 Apache restart 全站驗證 |

> Apache 設定變更不在 `bbc-ai-bot` repo 內時，備份路徑與操作紀錄應寫入維運備忘或相關 L3 報告。

---

## IIS Recovery

**適用：** Host B（IIS + ASP / ASP.NET）。

### 標準流程

```
備份 web.config（及相關設定檔）
  ↓
修改
  ↓
Recycle App Pool
  ↓
驗證（API、站台回應）
```

### 失敗時

**還原 web.config**（及相關備份），Recycle App Pool，重新驗證。

---

## SQL Recovery

**適用：** SQL Server schema / migration 變更。

採用目前正式 **12 步流程**：

| 步驟 | 動作 |
|------|------|
| 1 | **停止寫入** — 降低變更期間資料不一致風險 |
| 2 | **建立 .bak** — 完整資料庫備份 |
| 3 | **schema-only.sql** — 匯出目前 schema |
| 4 | **取得最後正常 schema** — 已知良好基線 |
| 5 | **schema diff** — 比對差異 |
| 6 | **建立 migration** — 可重現、可審查的變更腳本 |
| 7 | **Migration Engine 驗證** — 非生產或隔離環境試跑 |
| 8 | **再次確認 .bak** — 執行前最後備份確認 |
| 9 | **執行 migration** |
| 10 | **重新匯出 schema** |
| 11 | **再次比對** — 與預期 schema 一致 |
| 12 | **驗證系統** — 應用連線、關鍵查詢、API |

### 失敗時

- 依 `.bak` **還原資料庫**
- 記錄 Root Cause、影響範圍、後續防呆
- P1 情境下優先恢復服務，再進行事後分析

---

## Host A Recovery

**Host A（103.1.222.14）** 涵蓋以下元件之 Recovery 責任：

| 元件 | Recovery 要點 |
|------|----------------|
| **Apache** | 見本文 §Apache Recovery；`httpd -t` 必跑 |
| **PHP** | `bbc-ai-bot` 程式以 git revert / 前一 Commit 還原；`.env` 備份還原 |
| **Gemini** | config / feature flag 關閉；fallback formatter 路徑驗證 |
| **LINE OA** | webhook 簽章、credential、channel 設定還原 |
| **BATS** | Publisher / Orchestrator feature gate 關閉；回到已知安全 commit |

**專案路徑：** `C:\bbc-ai-bot`  
**原則：** 程式變更以 **Git Recovery** 為主；基礎設施變更以 **備份還原** 為主。

---

## Host B Recovery

**Host B** 涵蓋以下元件之 Recovery 責任：

| 元件 | Recovery 要點 |
|------|----------------|
| **IIS** | 見本文 §IIS Recovery |
| **ASP / ASP.NET** | 部署包或設定還原；App Pool recycle |
| **SQL** | 見本文 §SQL Recovery（12 步） |
| **API Gateway** | 設定還原、版本回退、端點健康檢查 |

**原則：** Host B 變更須與 Host A 協調驗證（API 契約、trace、tenant 隔離）。

---

## Emergency Hotfix

### 允許條件

**P1 Production 中斷** 時，允許：

- **先恢復服務**（可先改程式、Apache、IIS、SQL）
- 文件與完整測試可緊接補上

對應 `CO_WORK_POLICY.md` §11 例外情況。

### 修復後必須補齊

| 項目 | 說明 |
|------|------|
| **文件** | 行為變更、Recovery 步驟、本政策或相關 SSOT 更新 |
| **Commit** | 程式變更須進 git（單獨、可 revert 的 Commit） |
| **Root Cause** | 根因、影響範圍、時間線 |
| **Tech Debt** | 暫行方案記錄於 `docs/TECH_DEBT.md` |

**禁止** 讓 Emergency Hotfix 成為未文件化的永久架構。

---

## Rollback Gate

**任何上線前**（含 Apache、IIS、SQL migration、API 部署、BATS 上線、LINE 設定變更），必須能回答：

| # | 問題 | 若無答案 |
|---|------|----------|
| 1 | **如何 rollback？** | **禁止上線** |
| 2 | **Rollback 需要多久？** | **禁止上線** |
| 3 | **Rollback 驗證方式？** | **禁止上線** |

### Rollback Gate 通過範例

- Git：`git revert <commit>` + 執行測試腳本
- Apache：還原 `*.bak` + `httpd -t` + restart + curl 驗證
- SQL：從步驟 8 之 `.bak` restore + 步驟 12 驗證

---

## Recovery 驗證清單

Recovery 或 Rollback **完成後**，必須驗證以下項目（依變更範圍取捨）：

| 項目 | 驗證方式（範例） |
|------|------------------|
| **網站** | HTTPS 可開啟、關鍵路徑 200、短網址跳轉正常 |
| **API** | Host B 健康檢查、關鍵 endpoint 回應、401/403 符合預期 |
| **SQL** | 連線、關鍵查詢、migration 後 schema 比對 |
| **LINE OA** | Webhook 測試、回覆格式、簽章驗證 |
| **關鍵功能** | Hybrid Search、多商品源連結、fallback formatter、tenant 隔離 |

**未通過驗證 → 視為 Recovery 未完成**，不得宣告結案。

---

## Recovery Checklist

以下為 **統一 Recovery Checklist**（上線或重大變更前後使用）。

### 變更前（Pre-Change）

- [ ] 已通過 **Rollback Gate**（三問皆有答案）
- [ ] 已建立 **備份**（設定檔 / `.bak` / 已知良好 commit hash）
- [ ] 已確認 **影響範圍**（Host A / Host B / SQL / LINE）
- [ ] 已更新 **相關文件**（若為架構或行為變更）
- [ ] P1 以外變更已分類 **P2 / P3**

### 變更中（During Change）

- [ ] 依本文件對應章節執行（Git / Apache / IIS / SQL）
- [ ] 語法檢查通過（`httpd -t` 等）
- [ ] 單檔 whitelist stage（禁止 `git add .`）

### 變更後（Post-Change）

- [ ] 執行 **Recovery 驗證清單**
- [ ] 必要時建立 **Commit**（Recovery Point）
- [ ] 評估是否 Push（預設累積 1～3 commit，見 `CO_WORK_POLICY.md` §5）
- [ ] Emergency Hotfix 已補 **文件 / Root Cause / Tech Debt**

### Rollback 時（If Rollback）

- [ ] 執行已預先定義的 rollback 步驟
- [ ] 記錄 rollback 時間與結果
- [ ] 再次執行 **Recovery 驗證清單**
- [ ] 更新文件與 `TECH_DEBT.md`（若適用）

---

## 與其他文件關係

### 上層（L0）

| 文件 | 關係 |
|------|------|
| `CO_WORK_POLICY.md` | 最高協作治理；§14 Recovery First 為本文件之政策來源 |
| `DOCUMENTATION_GOVERNANCE_POLICY.md` | L0 文件治理；本文件為 L1 SSOT |

### 同層（L1，領域架構政策）

| 文件 | 關係 |
|------|------|
| `TENANT_SOURCE_REGISTRY_POLICY.md` | Tenant / source 變更之 Recovery 須符合本文件 |
| `PRODUCT_SOURCE_SHORTURL_POLICY.md` | 短網址 / Apache rewrite 變更須符合 Apache Recovery |
| `API_POLICY.md` | API / Gateway 部署與 Rollback Gate |

### 下層（L2 領域規則）

| 文件 | 關係 |
|------|------|
| `BATS_HYBRID_DATE_POLICY.md` | 日期規則變更之 rollback 以 git + config 為主 |

### 文件優先順序鏈（摘要）

```
CO_WORK_POLICY.md
    ↓
DOCUMENTATION_GOVERNANCE_POLICY.md
    ↓
RECOVERY_AND_ROLLBACK_POLICY.md   ← 本文件
    ↓
其他 L1 / L2 / L3 文件
    ↓
程式實作
```

---

## 結論

本專案採用：

- **Recovery First** — 可恢復優先於開發速度
- **Rollback First** — 上線前必須能回答 Rollback 三問

**所有變更必須：**

- **可恢復**
- **可追蹤**
- **可驗證**

若無法滿足上述條件，**禁止上線**。

ChatGPT、Cursor、開發者、維運人員進行 Apache、IIS、SQL、API、LINE OA、BATS 相關修改時，應以本文件為 **Recovery 與 Rollback 之正式 SSOT**。

---

## Revision History

| 版本 | 日期 | 狀態 | 說明 |
|------|------|------|------|
| 1.0 | 2026-06-05 | Adopted | Initial Version |
