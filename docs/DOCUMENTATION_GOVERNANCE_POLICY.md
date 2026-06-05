# DOCUMENTATION_GOVERNANCE_POLICY.md

**專案：** BBC AI SaaS / BATS / ETT / API Gateway / Travel Data Center  
**定位：** L0 文件治理政策（Documentation Governance）  
**層級：** 上層為 `CO_WORK_POLICY.md`；下層為各領域 SSOT 與功能文件  
**適用對象：** ChatGPT、Cursor、開發者、維運人員、文件維護者

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

## 5. 新功能建立規則

新增功能時，**先確認是否已有文件可管理**。

| 情況 | 動作 |
|------|------|
| 已有 SSOT / L1–L3 文件涵蓋 | 依文件實作；必要時更新文件版本 |
| 無對應文件 | **先建立文件**（至少 L3 設計或 L2 規則補充） |
| 規則可重用既有 SSOT | 擴充既有 SSOT，**不另起平行文件** |

**再寫程式。**

禁止在未定義文件歸屬的情況下新增散落邏輯。

---

## 6. 架構變更規則

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

## 7. 多租戶治理

系統需支援：

- `travel_a`
- `travel_b`
- `travel_c`
- 未來 **200 家旅行社**

### 7.1 集中治理原則

商品源規則、tenant 設定、platform 映射 **不得散落在程式**。

必須集中管理於：

1. **正式文件**（Registry Policy、領域 SSOT）
2. **Registry 設定**（`tenant_registry`、product source catalog、GCS 權威配置）

### 7.2 禁止事項

- tenant / platform **hardcode** 於 formatter、builder、router
- 為單一 tenant 在程式中開例外而未文件化
- 同一映射規則在多處重複定義

---

## 8. 文件命名標準

### 8.1 統一後綴

| 後綴 | 用途 |
|------|------|
| `*_POLICY.md` | 治理政策、業務規則 SSOT |
| `*_SPEC.md` | 技術規格、介面契約 |
| `*_ARCHITECTURE.md` | 架構設計、分層說明 |
| `*_GUIDE.md` | 操作指南、維運手冊 |

### 8.2 命名原則

- 使用 **大寫蛇形**（`SNAKE_CASE`）前綴 + 統一後綴
- 名稱應反映 **領域或 Phase**，避免泛稱（如 `notes.md`、`temp.md`）
- Phase 報告可保留 `*_PHASE*.md` 作為 L3，但正式規則應收斂至 `*_POLICY.md`

### 8.3 避免

- 隨意命名、同義多檔（`shorturl.md` vs `SHORT_URL_POLICY.md`）
- 在 `docs/sample/` 與正式 SSOT 重複定義規則而不標註性質

---

## 9. 文件生命週期

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

## 10. 技術債與文件關聯

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

## 11. 文件審查清單

新增或修改文件前，確認：

| # | 檢查項 |
|---|--------|
| 1 | **是否已有 SSOT？** — 能擴充則不新建平行文件 |
| 2 | **是否重複？** — 與現有 L0–L3 內容重疊應合併 |
| 3 | **是否與上層政策衝突？** — 對照 `CO_WORK_POLICY.md` 與本文件 |
| 4 | **是否與程式一致？** — 採納後程式應遵循；若不一致應標註待對齊 |
| 5 | **分層是否正確？** — L2 規則不應寫進 L0 |
| 6 | **命名是否符合 §8？** | |
| 7 | **生命週期狀態是否標註？** | |
| 8 | **Revision History 是否更新？** | |

---

## 12. 結論

本專案採用：

- **Documentation First** — 文件先於程式
- **Documentation Driven Development** — 以文件驅動設計與實作

**文件是架構的一部分**，與 Registry、Service、Policy 層同等重要。

**程式必須遵循文件**；若實作與文件不符，應視為缺陷，優先透過修訂程式或正式修訂 SSOT 解決，而非默許漂移。

---

## 文件層級關係（摘要）

```
CO_WORK_POLICY.md                    （最高協作治理）
    ↓
DOCUMENTATION_GOVERNANCE_POLICY.md   （本文件 · L0 文件治理）
    ↓
L1 架構政策（TENANT_SOURCE_REGISTRY_POLICY、PRODUCT_SOURCE_SHORTURL_POLICY、API_POLICY …）
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
