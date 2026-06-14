# TECH_DEBT.md

**專案：** BBC AI SaaS / BATS / ETT / API Gateway / Travel Data Center  
**定位：** P2 / P3 技術債與 Roadmap 項目正式登錄 SSOT  
**上層治理：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**適用對象：** ChatGPT、Cursor、開發者、維運人員  
**撰寫語言：** 技術債與 Roadmap 條目以**中文為主**；必要時保留英文技術名詞（括號註記）。

---

## 1. 文件目的

本文件是 BBC AI SaaS / BATS / ETT 專案中 **P2 技術債** 與 **P3 Roadmap** 項目的唯一正式登錄處（SSOT）。

**P1 不登錄於本文件。** 依 `CO_WORK_POLICY.md` §6.1，P1 代表阻塞主線、Production 風險、安全問題或客戶可見錯誤，**必須立即處理**，不得排入技術債佇列。

本文件用途：

- 集中記錄不阻塞主線、但近期或長期應處理之項目
- 每筆項目引用相關 SSOT，避免技術債僅留於 issue 或對話
- 作為迭代排期與 Roadmap 規劃之共同依據

---

## 2. 分級規則

引用 `CO_WORK_POLICY.md` §6：

| 等級 | 定義 | 處置 |
|------|------|------|
| **P1** | 阻塞主線、Production 風險、安全風險、客戶可見錯誤 | **立即處理**；不進本文件 |
| **P2** | 技術債；不阻塞主線；近期應改善（可讀性、維護性、架構一致性） | **登錄於本文件**；排入近期迭代 |
| **P3** | Roadmap 項目；未來功能；長期優化 | **可登錄於本文件**；必要時再拆出專項 Roadmap 文件 |

---

## 3. 登錄欄位

每筆 P2 / P3 項目應包含以下欄位：

| 欄位 | 說明 |
|------|------|
| **ID** | 唯一識別（如 P2-TD-001、P3-RM-001） |
| **Level** | P2 或 P3 |
| **Title** | 簡短標題（中文為主） |
| **Source / Context** | 發現情境或背景 |
| **Related SSOT** | 相關 SSOT 文件路徑 |
| **Risk** | 不處理的後果 |
| **Blocking** | 是否阻塞主線（是 / 否） |
| **Suggested Timing** | 建議處理時機 |
| **Status** | 待處理 / 進行中 / 已解決 / 已取消 |
| **Notes** | 補充說明（選填） |

---

## 4. Open P2 Items

### P2-TD-001

| 欄位 | 內容 |
|------|------|
| **ID** | P2-TD-001 |
| **Level** | P2 |
| **Title** | 日期追問（Date Clarification）使用者體驗層尚未完善 |
| **Source / Context** | 已完成 BATS Hybrid 日期政策（`BATS_HYBRID_DATE_POLICY.md`）與 `HybridDateRequiredGate` 實作；搜尋阻擋已生效，但面向使用者的日期追問回覆流程仍待完善。 |
| **Related SSOT** | `docs/BATS_HYBRID_DATE_POLICY.md` |
| **Risk** | `HybridDateRequiredGate` 可阻止無日期語意之無效搜尋，但若缺少清楚、一致的使用者追問體驗，旅客可能仍感到困惑或無法順利補充出發日期。 |
| **Blocking** | 否 |
| **Suggested Timing** | BBCTravel Golden Reference 核心流程穩定後 |
| **Status** | 待處理 |
| **Notes** | P2 範圍：優化 Gemini / LINE 追問文案與 clarification context，非 Hybrid Gate 本身。 |

---

### P2-TD-002

| 欄位 | 內容 |
|------|------|
| **ID** | P2-TD-002 |
| **Level** | P2 |
| **Title** | Legacy Search 與 Hybrid Search 仍存在耦合風險 |
| **Source / Context** | `TourPromptContextService` 的 Hybrid 流程與 Legacy fallback 行為。 |
| **Related SSOT** | `docs/BATS_HYBRID_DATE_POLICY.md`、`docs/CO_WORK_POLICY.md` |
| **Risk** | Date Clarification 路徑不得回落為 Legacy 僅 keyword 搜尋。P1-B 已修正立即路徑（clarification 回傳非 null），但整體 Legacy 耦合仍須正式審查，避免日後回歸。 |
| **Blocking** | 否 |
| **Suggested Timing** | 多商品源（Multi Source）擴大推廣前 |
| **Status** | 待處理 |
| **Notes** | — |

---

### P2-TD-003

| 欄位 | 內容 |
|------|------|
| **ID** | P2-TD-003 |
| **Level** | P2 |
| **Title** | 工作區存在大量非本次任務修改與 CRLF 雜訊 |
| **Source / Context** | 多次 `git status -sb` 顯示大量與當前任務無關的已修改檔案。 |
| **Related SSOT** | `docs/CO_WORK_POLICY.md` |
| **Risk** | 提高誤 stage、誤 commit 的機率；需後續依 Git Safe 原則清理並隔離變更範圍。 |
| **Blocking** | 否 |
| **Suggested Timing** | 下一次重大 commit 前，或 broader rollout 前 |
| **Status** | 待處理 |
| **Notes** | 建議以白名單 stage、`.gitattributes` 或獨立分支收斂 unrelated 變更。 |

---

### P2-TD-004

| 欄位 | 內容 |
|------|------|
| **ID** | P2-TD-004 |
| **Level** | P2 |
| **Title** | TECH_DEBT.md 技術債 SSOT 文件缺失 |
| **Source / Context** | `CO_WORK_POLICY.md` 與 `DOCUMENTATION_GOVERNANCE_POLICY.md` 已引用 `docs/TECH_DEBT.md`，但本任務完成前實體檔不存在。 |
| **Related SSOT** | `docs/CO_WORK_POLICY.md`、`docs/DOCUMENTATION_GOVERNANCE_POLICY.md` |
| **Risk** | P2 / P3 技術債缺乏專案層級正式登錄處，易散落於對話或 issue。 |
| **Blocking** | 否 |
| **Suggested Timing** | 立即 |
| **Status** | 已解決（本文件已建立） |
| **Notes** | — |

---

### P2-TD-005

| 欄位 | 內容 |
|------|------|
| **ID** | P2-TD-005 |
| **Level** | P2 |
| **Title** | 日期解析器尚未完整支援模糊日期語意 |
| **Source / Context** | `BATS_HYBRID_DATE_POLICY.md` 已定義模糊日期語意對照表，但 `DateParser` 尚未完全落實。 |
| **Related SSOT** | `docs/BATS_HYBRID_DATE_POLICY.md` |
| **Risk** | 如「近期東京」等輸入，在 `DateParser` 產出 `date_from` / `date_to` 前，仍會被 `HybridDateRequiredGate` 阻擋而進入 Date Clarification。 |
| **Blocking** | 否（目前 BBCTravel Golden Reference 若測試／輸入使用已支援片語如「6月底」則不阻塞）；若需面向客戶的完整模糊日期支援，可能升級為阻塞項。 |
| **Suggested Timing** | Hybrid Smart Search 優化階段，或 BBCTravel Golden Reference 穩定後 |
| **Status** | 待處理 |
| **Notes** | 下列語意尚未完全落實至 `DateParser`：近期、最近、本月、下月、月初、月中、月底、暑假、寒假、明年。 |

---

## BDS Technical Debt

BDS / Drive Connector 相關 P2 技術債登錄區。不阻塞 Phase 6 Core Architecture 關閉與 Phase 7 主線。

### P2-TD-6D2

| 欄位 | 內容 |
|------|------|
| **ID** | P2-TD-6D2 |
| **Level** | P2 |
| **Title** | Google Workspace Export（Phase 6D-2） |
| **Source / Context** | Phase 6 Drive Connector MVP（6D Tenant Private Archive + 6E Industry Shared Archive）已 **CONDITIONAL CLOSED**；`application/vnd.google-apps.*` 原生檔尚未實作 Export → Archive promote 管線。Phase 6E-6 Close-out 將本項列為 P1 候選，經治理審查 **降級為 P2 Deferred**，不納入目前主線。 |
| **Related SSOT** | `docs/BATS_DRIVE_GCS_MAPPING.md` §8.2、`docs/BATS_DRIVE_CONNECTOR_SCOPE.md` §6、`docs/BATS_DRIVE_METADATA_CONTRACT.md`、`core/bds/BdsDriveArchivePromoter.php`、`core/bds/BdsSharedArchivePromoter.php` |
| **Status** | **Deferred** |
| **Blocking** | 否（Non-blocking） |
| **Suggested Timing** | Phase 7 完成後；Multi-Tenant MVP 初期 |
| **Notes** | **不列為目前主線。** |

### P2-TD-7C0

| 欄位 | 內容 |
|------|------|
| **ID** | P2-TD-7C0 |
| **Level** | P2 |
| **Title** | BDS Core Orchestrator（in-process 編排服務） |
| **Source / Context** | Phase 7-1c-0 架構決策：MVP 採 **CLI Trigger** → `bin/bds-sync.php`；不重構 BDS Core。 |
| **Related SSOT** | `BATS_DATA_SYNC_POLICY.md` §17.11.4；`BATS_UPLOAD_PORTAL_PHASE7_MVP_PLAN.md` §12.6 |
| **Status** | **Deferred** — Future Architecture Enhancement |
| **Blocking** | 否（Non-blocking for Phase 7-1c） |
| **Suggested Timing** | 多租戶正式商品化；大量 Upload Portal 使用；Shared Upload 穩定後 |
| **Notes** | Portal／API 入口仍須共用同一 BDS Sync Core，Orchestrator 僅改 **觸發與編排方式**，不改 Safety Rule。 |

#### Classification

| 分類 | 說明 |
|------|------|
| **P2 Technical Debt** | 不阻塞主線；登錄於本文件 |
| **Non-blocking** | 不影響 Phase 7、travel_b 上線、Multi-Tenant MVP |
| **Governance / Archive Completeness Improvement** | 補齊 Workspace 原生檔 Archive 覆蓋率與歷史快照能力 |

#### 目前行為（Runtime）

下列 Google Workspace **原生** MIME 在 Archive Promote Planner / Promoter 一律 **SKIP**：

| MIME | 處置 |
|------|------|
| `application/vnd.google-apps.spreadsheet` | SKIP |
| `application/vnd.google-apps.document` | SKIP |
| `application/vnd.google-apps.presentation` | SKIP |

- **reason：** `W_GOOGLE_WORKSPACE_EXPORT_REQUIRED`
- 已匯出之 binary 格式（如 `.xlsx`、`.pdf`）**不受本項限制**，依既有 Gate 4 判定。

#### 不影響（Non-Blocking）

| 範圍 | 說明 |
|------|------|
| Phase 7 Upload Triggered BDS Sync | Sheet → Knowledge 管線獨立於 Drive Export |
| `travel_b` 正式上線 | Tenant Private Knowledge（Sheet）與 binary Archive 已驗證 |
| Multi-Tenant MVP | 不阻塞多租戶 Sheet 同步主線 |
| BDS Knowledge Pipeline | Structured Knowledge 仍以 Google Sheet 為 SSOT |
| Gemini Runtime | Runtime 讀取 GCS，不依賴 Workspace Export |

#### 影響（若維持 Deferred）

| 影響 | 說明 |
|------|------|
| Google Workspace Native Files 無法進入 `archive/` | 原生 Sheet / Doc / Slides 不寫入 GCS `archive/` |
| Workspace 歷史快照無法保存 | 無 Export 即無不可變快照 |
| Archive Coverage 非 100% | Drive 資料夾若含原生 Workspace 檔，覆蓋率缺口可預期 |
| Workspace 檔案無 Read-back 驗證 | 無 promote 即無 `sha256_content` read-back |

#### 建議啟動條件（Trigger）

| Trigger | 條件 |
|---------|------|
| **Trigger A** | Google Workspace Native Files 成為主要資料來源之一 |
| **Trigger B** | 旅行社提出歷史版本追溯需求 |
| **Trigger C** | 至少 2 家旅行社正式使用 BDS |
| **Trigger D** | Phase 7 Upload Triggered BDS Sync 已完成並穩定運作 |

#### 預估執行時機

- **Phase 7 完成後**
- **Multi-Tenant MVP 初期**
- 須同時滿足上述 Trigger 至少一項，並經 CWP Git Safe 獨立 PR 交付

---

## 5. P3 / Roadmap Items

### P3-RM-001

| 欄位 | 內容 |
|------|------|
| **ID** | P3-RM-001 |
| **Level** | P3 |
| **Title** | 建立 BATS_HYBRID_SMART_SEARCH_POLICY.md 作為混合聰明搜尋 SSOT |
| **Source / Context** | 未來 Hybrid Smart Search / Query Normalizer 優化階段。 |
| **Related SSOT** | `docs/BATS_HYBRID_DATE_POLICY.md`、`docs/TENANT_SOURCE_REGISTRY_POLICY.md` |
| **Risk** | 若無專責 Hybrid Smart Search 政策文件，進階語意解析規則可能分散於各模組或對話，難以治理。 |
| **Blocking** | 否 |
| **Suggested Timing** | Hybrid Smart Search 優化階段 |
| **Status** | 待處理 |
| **Notes** | — |

---

### P3-RM-002

| 欄位 | 內容 |
|------|------|
| **ID** | P3-RM-002 |
| **Level** | P3 |
| **Title** | 進階旅遊語意解析與旅遊意圖辨識 |
| **Source / Context** | 未來需支援之主題型／意圖型查詢，例如：東京迪士尼、東京賞楓、東京親子、北海道滑雪。 |
| **Related SSOT** | 未來 `docs/BATS_HYBRID_SMART_SEARCH_POLICY.md` |
| **Risk** | 過早實作可能分散 BBCTravel Golden Reference 主線；若無 SSOT 即實作，易造成解析邏輯散落、難以維護。 |
| **Blocking** | 否 |
| **Suggested Timing** | Hybrid Smart Search 優化階段 |
| **Status** | 待處理 |
| **Notes** | 範例查詢：東京迪士尼、東京賞楓、東京親子、北海道滑雪。 |

---

## 6. Closed / Resolved Items

（目前無額外已關閉項目；P2-TD-004 已於 §4 標記為已解決。）

---

## 7. 修訂紀錄

| 日期 | 說明 |
|------|------|
| 2026-06-05 | 初版建立 TECH_DEBT.md，作為 P2/P3 技術債治理登錄 SSOT。 |
| 2026-06-05 | 技術債與 Roadmap 條目中文化（Title、Risk、Notes、Suggested Timing 等以中文為主）。 |
| 2026-06-06 | 新增 P2-TD-006：BBCTravel Departure Mapping 規格漂移（null→tpetsa vs null→all） |
| 2026-06-06 | 移除 P2-TD-006：已轉為 Phase C-1 主線工作（C-1A 文件、C-1B 程式），不屬技術債 |
| 2026-06-13 | 新增 **BDS Technical Debt** 區段；登錄 P2-TD-6D2：Google Workspace Export（Phase 6D-2）— Status: Deferred |
