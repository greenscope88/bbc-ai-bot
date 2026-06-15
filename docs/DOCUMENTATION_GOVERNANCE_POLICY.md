# DOCUMENTATION_GOVERNANCE_POLICY.md

**專案：** BBC AI SaaS / BATS / ETT / API Gateway / Travel Data Center  
**定位：** L0 文件治理政策（Documentation Governance）  
**層級：** 上層為 `CO_WORK_POLICY.md`；下層為各領域 SSOT 與功能文件  
**適用對象：** ChatGPT、Cursor、開發者、維運人員、文件維護者

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | 文件目的 |
| §2 | SSOT 原則 |
| §3 | 文件優先於程式 |
| §4 | 文件分層標準（L0–L3） |
| §5 | 文件建立判斷原則 |
| §6 | 新功能建立規則 |
| §7 | 架構變更規則 |
| §8 | 多租戶治理 |
| §9 | 文件命名標準 |
| §10 | 文件生命週期 |
| §11 | 技術債與文件關聯 |
| §12 | 文件審查清單 |
| §13 | 結論 |
| §14 | 文件成本控制原則 |

---

## 1. 文件目的

本文件定義 BBC AI SaaS / BATS / ETT 的 **文件治理標準**。

所有重要決策（架構、規則、契約、流程、tenant / source 治理）**必須文件化**，作為協作與實作的共同依據。

避免以下做法：

| 避免 | 風險 |
|------|------|
| **靠記憶** | 人員異動後規則遺失 |
| **靠聊天室回顧** | 無法稽核、版本不可追 |
| **靠個人理解** | 實作分歧、重工、回歸 |

本文件與 `CO_WORK_POLICY.md` 共同構成治理基礎；本文件專注 **「如何管理文件」**，`CO_WORK_POLICY.md` 專注 **「如何協作與決策」**。

---

## 2. SSOT 原則

### 2.1 Single Source of Truth

**每個領域只能有一份正式 SSOT 文件。**

同一規則不得在多篇文件中以不同表述並存；不得在未升級為 SSOT 的草稿中定義正式行為。

### 2.2 領域與 SSOT 對照（範例）

| 領域 | 正式 SSOT 文件 |
|------|----------------|
| Hybrid 日期規則 | `BATS_HYBRID_DATE_POLICY.md` |
| 商品源 / Tenant Registry 治理 | `TENANT_SOURCE_REGISTRY_POLICY.md` |
| 短網址治理 | `PRODUCT_SOURCE_SHORTURL_POLICY.md` |
| Recovery / Rollback | `RECOVERY_AND_ROLLBACK_POLICY.md` |
| API 治理 | `API_POLICY.md` |
| 技術債登錄 | `TECH_DEBT.md` |
| 最高協作治理 | `CO_WORK_POLICY.md` |
| 文件治理本身 | 本文件 |

> 上表所列下層政策文件若尚未建立，應在實作前補齊；過渡期以既有 Phase 設計文件為參考，但**不得**與未來 SSOT 衝突而不更新。

### 2.3 衝突處理

若多份文件內容衝突：

1. **以該領域 SSOT 為準**
2. 跨領域衝突依 `CO_WORK_POLICY.md` §8 文件優先順序向上裁決
3. 程式實作與 SSOT 衝突時，**以 SSOT 為準**，程式應修正或 SSOT 應先修訂後再改程式

---

## 3. 文件優先於程式

### 3.1 正式流程

```
需求
  ↓
文件
  ↓
審查
  ↓
程式
  ↓
測試
  ↓
Commit
```

### 3.2 禁止事項

- **禁止** 先改程式、再補文件
- **禁止** 僅在 PR 描述或對話中定義架構規則
- **禁止** 以「之後再寫文件」作為合併條件（P1 hotfix 例外見 `CO_WORK_POLICY.md` §11）

### 3.3 審查通過標準

文件進入實作前，至少應確認：範圍、分層、SSOT 歸屬、與上層政策一致性、測試與驗收方式。

---

## 4. 文件分層標準

文件依治理與技術深度分為四層：

### L0 — 治理層

定義協作方式、文件治理、Git Safe、優先順序。

| 範例 |
|------|
| `CO_WORK_POLICY.md` |
| `DOCUMENTATION_GOVERNANCE_POLICY.md`（本文件） |

### L1 — 架構政策層

定義跨功能、跨 tenant 的架構邊界與治理契約。

| 範例 |
|------|
| `TENANT_SOURCE_REGISTRY_POLICY.md` |
| `PRODUCT_SOURCE_SHORTURL_POLICY.md` |
| `RECOVERY_AND_ROLLBACK_POLICY.md` |
| `API_POLICY.md` |

### L2 — 領域規則層

定義特定領域的業務規則、解析規則、映射表。

| 範例 |
|------|
| `BATS_HYBRID_DATE_POLICY.md` |

### L3 — 功能文件

定義單一 Phase、功能、Contract、MVP 實作計畫、驗收報告。

| 範例 |
|------|
| `MULTI_SOURCE_SEARCH_URL_BUILDER_PHASE9B14.md` |
| `BATS_PUBLISHER_STRATEGY_CONTRACT.md` |
| 各 Phase 設計 / 完成報告 |

**規則：** L3 不得違反 L2；L2 不得違反 L1；L1 不得違反 L0。

---

## 5. 文件建立判斷原則

本節定義 **何時必須**、**何時不需要** 建立正式文件，以及與聊天室、SSOT、淘汰規則之關係。對應 `CO_WORK_POLICY.md` §3（文件治理）、§13（文件驅動開發）。

### 5.1 何時必須建立正式文件

符合以下 **任一條件**，應建立或更新正式文件（L0 / L1 / L2 / L3 依 §4 分層）：

| # | 條件 | 範例 |
|---|------|------|
| 1 | **涉及架構決策** | SaaS 架構、BATS 架構、Tenant Mapping、Registry、API Gateway、Hybrid Search |
| 2 | **涉及多人協作** | ChatGPT、Cursor、工程師需共同依據之規格 |
| 3 | **未來可能重複使用** | Short URL、Upload、Search、Notification |
| 4 | **可能跨專案使用** | BBC AI SaaS、BATS、ETT 共用規則 |
| 5 | **可能影響安全、Recovery、Rollback** | 見 `RECOVERY_AND_ROLLBACK_POLICY.md`、`CO_WORK_POLICY.md` §14 |
| 6 | **超過一次以上被討論** | 表示已成為正式規格，不得僅留於對話 |

### 5.2 何時不需要建立正式文件

以下內容 **不必** 升級為 L0–L3 正式 SSOT，可放於輕量載體：

| 類型 | 說明 | 建議載體 |
|------|------|----------|
| **一次性測試** | 單次驗證、用完即丟 | Issue、Notes |
| **臨時調查** | 唯讀盤點、短期分析 | Temporary Docs、Issue |
| **Debug 紀錄** | 除錯日誌、單次故障筆記 | Notes、維運備忘 |
| **短期實驗** | 未採納之 POC | Temporary Docs |

**注意：** 若實驗結果被採納為正式行為，**必須** 升級為正式文件後再寫入程式。

### 5.3 文件優先於聊天室

**正式規格來源優先順序：**

```
1. L0 / L1 / L2 正式文件（及已 Adopted 之 L3）
2. 程式碼（實作須與文件對齊）
3. 聊天室（僅作背景參考，不得作為唯一依據）
```

**禁止：** 以聊天室歷史作為 **唯一** 正式規格來源。

對應 `CO_WORK_POLICY.md` §13.1 第 5 點。

### 5.4 文件建立順序

**標準流程：**

```
需求
  ↓
文件
  ↓
審查
  ↓
程式
  ↓
測試
  ↓
文件更新（與實作、驗收結果對齊）
```

與 §3 之差異：本節明確要求 **實作與測試後再次更新文件**，避免文件與程式漂移。

**補充：** 此處所指為 **里程碑／Phase／契約對齊** 之必要更新，非每個 commit 或每項測試皆須改文件；細則見 §5.4.1、§14。

### 5.4.1 最小文件更新原則

文件更新應聚焦於：

* 架構變更
* Contract 變更
* Policy 變更
* Roadmap / Phase 狀態變更
* SSOT 與實作不一致

不要求因以下事項更新正式文件：

* 每個 Function 完成
* 每個 Class 完成
* 每個 Test 完成
* 每個 Commit 完成
* 小型 UX polish
* 小型 bug fix
* 不影響架構與契約的 refactor

除非上述事項造成 SSOT、架構、契約、Policy 或 Roadmap 改變。

### 5.5 文件淘汰原則

若文件：

- **已過時**，或
- **被新文件取代**

**必須：**

1. 標示狀態為 **Deprecated**（見 §10）
2. 註明 **取代文件** 之檔名與章節
3. **不得直接刪除**（保留稽核與歷史追溯）

過渡期可並存，但僅 **Adopted** 文件可作為 SSOT。

### 5.6 SSOT 原則補充

每個重要領域 **只能有一份正式文件**，避免多份文件互相矛盾。

| 領域 | 正式 SSOT |
|------|-----------|
| 日期規則 | `BATS_HYBRID_DATE_POLICY.md` |
| Recovery / Rollback | `RECOVERY_AND_ROLLBACK_POLICY.md` |
| Tenant / Source Registry | `TENANT_SOURCE_REGISTRY_POLICY.md` |
| 短網址 | `PRODUCT_SOURCE_SHORTURL_POLICY.md` |
| 文件治理 | 本文件 |
| 最高協作治理 | `CO_WORK_POLICY.md` |

**規則：** 新主題應 **擴充既有 SSOT** 或 **新建單一 SSOT**；禁止平行多檔定義同一規則（詳 §2、§12）。

---

## 6. 新功能建立規則

新增功能時，**先確認是否已有文件可管理**。

| 情況 | 動作 |
|------|------|
| 已有 SSOT / L1–L3 文件涵蓋 | 依文件實作；必要時更新文件版本 |
| 無對應文件 | **先建立文件**（至少 L3 設計或 L2 規則補充） |
| 規則可重用既有 SSOT | 擴充既有 SSOT，**不另起平行文件** |

**再寫程式。**

禁止在未定義文件歸屬的情況下新增散落邏輯。

---

## 7. 架構變更規則

任何涉及以下元件的變更，**先更新文件，再修改程式**：

| 元件 | 文件應說明 |
|------|------------|
| **Policy** | 規則、預設值、例外 |
| **Registry** | 欄位、schema、tenant / source 對應 |
| **Resolver** | 解析順序、fail-closed 行為 |
| **Service** | 職責邊界、依賴、fail-open / fail-closed |
| **Adapter** | 外部系統契約、host / path 規則 |
| **Mapping** | 對照表、預設值、多租戶共用策略 |

變更完成後，相關 L3 功能文件與測試說明應同步更新。

---

## 8. 多租戶治理

系統需支援：

- `travel_a`
- `travel_b`
- `travel_c`
- 未來 **200 家旅行社**

### 8.1 集中治理原則

商品源規則、tenant 設定、platform 映射 **不得散落在程式**。

必須集中管理於：

1. **正式文件**（Registry Policy、領域 SSOT）
2. **Registry 設定**（`tenant_registry`、product source catalog、GCS 權威配置）

### 8.2 禁止事項

- tenant / platform **hardcode** 於 formatter、builder、router
- 為單一 tenant 在程式中開例外而未文件化
- 同一映射規則在多處重複定義

---

## 9. 文件命名標準

### 9.1 統一後綴

| 後綴 | 用途 |
|------|------|
| `*_POLICY.md` | 治理政策、業務規則 SSOT |
| `*_SPEC.md` | 技術規格、介面契約 |
| `*_ARCHITECTURE.md` | 架構設計、分層說明 |
| `*_GUIDE.md` | 操作指南、維運手冊 |

### 9.2 命名原則

- 使用 **大寫蛇形**（`SNAKE_CASE`）前綴 + 統一後綴
- 名稱應反映 **領域或 Phase**，避免泛稱（如 `notes.md`、`temp.md`）
- Phase 報告可保留 `*_PHASE*.md` 作為 L3，但正式規則應收斂至 `*_POLICY.md`

### 9.3 避免

- 隨意命名、同義多檔（`shorturl.md` vs `SHORT_URL_POLICY.md`）
- 在 `docs/sample/` 與正式 SSOT 重複定義規則而不標註性質

---

## 10. 文件生命週期

每份正式文件應處於下列狀態之一：

```
Draft
  ↓
Reviewed
  ↓
Adopted
  ↓
Deprecated
  ↓
Archived
```

| 狀態 | 說明 |
|------|------|
| **Draft** | 草稿，不得作為實作 SSOT |
| **Reviewed** | 已審查，待採納 |
| **Adopted** | 正式採納，可作為 SSOT |
| **Deprecated** | 已取代，僅供歷史參考；應註明取代文件 |
| **Archived** | 封存，不再用於決策 |

**建議：** 在文件開頭或 Revision History 標註狀態與版本。

---

## 11. 技術債與文件關聯

所有 **P2 / P3** 技術債必須記錄於：

```
C:\bbc-ai-bot\docs\TECH_DEBT.md
```

每筆技術債應：

- 標註等級（P2 / P3）
- **引用相關 SSOT 或 L3 文件**（連結或檔名）
- 說明與文件的差距（文件已定但程式未跟上，或程式暫行方案待收斂）

禁止技術債僅存在於 issue 或對話而未與文件體系關聯。

---

## 12. 文件審查清單

新增或修改文件前，確認：

| # | 檢查項 |
|---|--------|
| 1 | **是否已有 SSOT？** — 能擴充則不新建平行文件 |
| 2 | **是否重複？** — 與現有 L0–L3 內容重疊應合併 |
| 3 | **是否與上層政策衝突？** — 對照 `CO_WORK_POLICY.md` 與本文件 |
| 4 | **是否與程式一致？** — 採納後程式應遵循；若不一致應標註待對齊 |
| 5 | **分層是否正確？** — L2 規則不應寫進 L0 |
| 6 | **命名是否符合 §9？** | |
| 7 | **生命週期狀態是否標註？** | |
| 8 | **Revision History 是否更新？** | |
| 9 | **是否符合 §5 建立判斷原則？** | |
| 10 | **是否真的需要更新文件？** | 見 §5.4.1、§14.1 |
| 11 | **是否只是小型實作 / UX polish？** | 若是，通常不需更新 SSOT |
| 12 | **是否可只更新 phase status？** | Phase / MVP 完成時見 §14.4 |
| 13 | **是否可使用 SSOT Read Mode B 或 C 以降低 token 成本？** | 見 §14.3 |
| 14 | **是否造成重複文件或文件膨脹？** | 見 §14.2 |
| 15 | **是否觸發 SSOT Frozen？** | UI／文案／小型重構通常不應改 SSOT（§14.7） |
| 16 | **是否已 Read Before Ask？** | 優先 C → B → A（§14.8） |
| 17 | **是否 Mainline 優先？** | P2/P3 不得阻擋主線（§14.9） |
| 18 | **是否應以增量子階段處理？** | 已結案 Phase 不重開（§14.10） |
| 19 | **是否應列為 Post-MVP 新 Phase？** | Close-out 後不回頭改 MVP 定義（§14.11） |

---

## 13. 結論

本專案採用：

- **Documentation First** — 文件先於程式
- **Documentation Driven Development** — 以文件驅動設計與實作

**文件是架構的一部分**，與 Registry、Service、Policy 層同等重要。

**程式必須遵循文件**；若實作與文件不符，應視為缺陷，優先透過修訂程式或正式修訂 SSOT 解決，而非默許漂移。

---

## 14. 文件成本控制原則

本節補強文件治理之 **成本邊界**：在保留 DDD / SSOT First 之前提下，降低不必要文件維護、避免文件膨脹、降低 Cursor / AI token 消耗。

### 14.1 文件用途邊界

文件主要用途：

1. Direction
2. Goal
3. Architecture
4. Contract
5. Policy
6. Scope
7. Roadmap
8. Phase / Milestone status

文件不是每日工作日誌，也不是每個程式修改的流水帳。

### 14.2 文件膨脹防止

同一資訊不得重複散落在多份文件。

若資訊已由 Git commit、測試報告或 phase report 保存，不應再重複寫入多個 SSOT。

### 14.3 SSOT Read Mode

定義 Cursor / AI 開發時的文件讀取模式：

**A = Full Read**

適用：

* 新主線
* 新模組
* 架構變更
* 跨模組變更
* 不確定 SSOT 是否仍有效

**B = Reuse Previous Context**

適用：

* 同一主線連續開發
* 已於同日或同一 phase 讀過相關 SSOT
* 僅需檢查特定章節

**C = No Read Needed**

適用：

* Git close-out
* Commit
* Push
* 單純 verification
* 不改架構、不改契約、不改文件的 UX polish

詳細判斷與升級條件見 **§14.8 Read Before Ask Principle**。

### 14.4 Phase Completion Update

Phase / MVP 完成時，應更新 status，使文件與實際系統一致。

但只需更新必要狀態與驗收摘要，不需記錄每個測試細節。

### 14.5 Delivery First Principle

在 SSOT 已穩定、架構未變更時，應優先完成主線交付。

文件維護不得高於其產生的開發價值。

### 14.6 Mainline First Principle

已完成 MVP 後的 P2 / P3 延伸功能，若不阻擋上線、不阻擋下一個 tenant、不阻擋主線 roadmap，應記錄於 Roadmap 或 `TECH_DEBT.md`，不應優先於主線開發。

具體優先順序與主線衝突處理見 **§14.9 Mainline Priority Principle**。

### 14.7 SSOT Frozen Principle

**目的：** 避免 **Document Churn（文件震盪）** — 已穩定運作之架構、Contract、Policy、SSOT，不得因非實質變更而頻繁修改。

**不得** 僅因下列原因更新正式 SSOT：

* UI 微調
* 文案調整
* 小型重構
* 非 Contract 行為變更

**僅** 下列情況允許更新 SSOT：

* Architecture Change（架構變更）
* Contract Change（契約變更）
* Policy Change（政策變更）
* Tenant Behavior Change（租戶行為變更）

### 14.8 Read Before Ask Principle

**目的：** 控制 Cursor / AI Token 成本。

AI 在要求重新閱讀文件前，**應先判斷是否已有足夠上下文**。

**優先順序：**

```text
Read Mode C
    ↓
Read Mode B
    ↓
Read Mode A
```

**禁止：** 每個 Prompt 重新閱讀全部 SSOT。

**僅於** 下列情況升級閱讀模式（C → B → A）：

* 架構變更
* Contract 變更
* Policy 變更
* SSOT 衝突

### 14.9 Mainline Priority Principle

**目的：** 確保 **主線優先**。

當 **Mainline** 與 **P2 / P3** 衝突時，**優先 Mainline**。

**不得** 因下列事項阻擋主線交付：

* 技術債
* 文件美化
* 預留設計
* 未來功能

**範例（Post-MVP，不得阻擋主線）：**

* Workbook Download
* Drive Promote
* Shared Drive
* Auto Provisioning

**不得阻擋之主線（範例）：**

* BATS Hybrid Smart Search
* Tenant Mapping
* Multi Tenant Rollout

### 14.10 Stable Phase Principle

**目的：** 已結案 Phase **不因小型修正重新開啟**。

**原則：** 狀態為 **Completed**、**Closed-out**、**Pushed** 之 Phase，後續修正應建立增量子階段，例如：

* `Phase x.x.xa`
* `Phase x.x.xb`

**避免：** 反覆修改歷史里程碑定義與驗收範圍。

### 14.11 One-Way Close-out Principle

**目的：** 降低重構與文件成本；Close-out 後 **預設不回頭**。

**原則：** 後續新增功能應建立 **新 Phase**、**新 Roadmap** 條目，**而非** 修改原 MVP 定義。

**範例：**

| 項目 | 狀態 | 後續處理 |
|------|------|----------|
| **BDS Upload Portal MVP** | **Completed** | 維持結案範圍不變 |
| Drive Promote | Post-MVP | 新 Phase（如 7-1e.8b） |
| Workbook Download | Post-MVP | 新 Phase（如 7-1e.8c） |
| Shared Drive / Auto Provisioning | Post-MVP | Roadmap 或 `TECH_DEBT.md` |

**不得** 因 Post-MVP 需求重新定義或擴寫原 MVP 範圍。

---

## 文件層級關係（摘要）

```
CO_WORK_POLICY.md                    （最高協作治理）
    ↓
DOCUMENTATION_GOVERNANCE_POLICY.md   （本文件 · L0 文件治理）
    ↓
L1 架構政策（TENANT_SOURCE_REGISTRY_POLICY、RECOVERY_AND_ROLLBACK_POLICY、PRODUCT_SOURCE_SHORTURL_POLICY、API_POLICY …）
    ↓
L2 領域規則（BATS_HYBRID_DATE_POLICY …）
    ↓
L3 功能文件（Phase 設計、Contract、MVP 報告 …）
    ↓
程式實作
```

---

## Revision History

| 版本 | 日期 | 狀態 | 說明 |
|------|------|------|------|
| 1.0 | 2026-06-05 | Adopted | Initial Version |
| 1.1 | 2026-06-05 | Adopted | Add document creation criteria and SSOT governance rules. |
| 1.2 | 2026-06-15 | Adopted | Add document cost control, SSOT Read Mode, minimal completion update, and mainline-first documentation principles. |
| 1.3 | 2026-06-15 | Adopted | Phase GOV-1.3: §14.7–§14.11 SSOT Frozen, Read Before Ask, Mainline Priority, Stable Phase, One-Way Close-out. |
