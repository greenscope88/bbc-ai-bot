# BATS AI Runtime（BBC AI Runtime）

**專案：** BBC AI SaaS / BATS / AI Intent Understanding v2  
**定位：** L3 架構 SSOT — **BBC AI Runtime（Execution Layer）** 唯一正式依據  
**版本：** BBC AI Runtime v1.0 Rev.1 — Frozen  
**狀態：** Frozen  

**主機參考：** 103.1.222.14（主機 A · `C:\bbc-ai-bot`）

---

## Frozen / Upstream Inputs（不得修改）

| # | SSOT | 狀態 |
|---|------|------|
| 1 | AIU v2 Entity Core Final | Frozen |
| 2 | AIU v2 Entity Definition v1.0 Rev.1 | Frozen |
| 3 | AIU v2 Gemini Output Contract v1.0 Rev.1 | Frozen |
| 4 | AIU Normalize v1.0 Rev.1 | Rev.1（本 Runtime 必須承接） |

**本文件不得修改上述 SSOT。**  
BBC AI Runtime **僅**負責 Execution。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Document Purpose |
| §2 | Runtime Position |
| §3 | Responsibilities |
| §4 | Runtime Principles |
| §5 | Runtime Flow |
| §6 | Runtime Coordination |
| §7 | Out of Scope |
| §8 | Freeze Readiness Review |

---

## 1. Document Purpose

### 1.1 目標

定義 **BBC AI Runtime** 作為 BBC AI 的 **Execution Layer**：

```text
AIU Normalize（Runtime Contract）
        ↓
BBC AI Runtime（本文件）
        ↓
Runtime Modules
        ↓
Grounded Response Composer
```

### 1.2 唯一責任

接收 **AIU Normalize** 所輸出的 **Runtime Contract**，  
並協調各 Runtime Module 完成執行。

### 1.3 非目標

本文件 **不**定義任何 Runtime Module 的內部實作、演算法、Prompt、或商品源 Adapter 細節。

---

## 2. Runtime Position

### 2.1 在 AIU v2 主線中的位置

```text
Customer Utterance
        ↓
Gemini（Understanding Core）
        ↓
Gemini Output Contract（Frozen）
        ↓
AIU Entity Validation
        ↓
AIU Normalize（Rev.1）
        ↓
BBC AI Runtime Contract
        ↓
★ BBC AI Runtime（Execution Layer）★
        ↓
Runtime Modules（Product / Knowledge / Human / Clarification …）
        ↓
Grounded Response Composer
        ↓
Channels
```

### 2.2 層級責任對照

| 層級 | 元件 | 職責 |
|------|------|------|
| Understanding | Gemini | 語意理解、Entity 抽取、Clarification Detection |
| Translation | AIU Normalize | Gemini Output → Runtime Contract（**不得**新增 `dispatch_plan` / `execution_hint` / `runtime_action` / `search_action`） |
| **Execution** | **BBC AI Runtime** | 依 Runtime Contract 自行 Routing / Dispatch / Coordination / Execution Lifecycle |
| Domain Modules | Product / Knowledge / Human / Conversation Memory / Conversation State / … | 各領域執行邏輯（本文件不展開） |
| Response | Grounded Response Composer | 組裝最終回覆 |

### 2.3 與既有文件關係

| 文件 | 關係 |
|------|------|
| `BATS_AI_NORMALIZE.md` | **上游輸入契約**；Runtime 必須承接，不得修改其結果 |
| `BATS_AI_INTENT_UNDERSTANDING_V2.md` | AIU Runtime（Understand / Normalize / Plan）；本文件為其後之 Execution |
| `BATS_AI_CONVERSATION_ARCHITECTURE.md` | L2 Pipeline / Dispatch 概念；本文件為 Execution Layer 之 L3 架構 SSOT |
| `BATS_AI_RUNTIME_DESIGN.md` | Phase 9-C-1 實作設計；**不**取代本文件之 Execution Layer 架構定位 |
| `BATS_AI_GROUNDED_RESPONSE_COMPOSER.md` | Runtime 協調完成後之下游回覆層 |

> **衝突處理：** Execution Layer 之定位、職責、原則、Flow、Coordination、Out of Scope **以本文件為準**。  
> AIU 理解／Normalize 契約以上游 Frozen／Rev.1 文件為準。

---

## 3. Responsibilities

### 3.1 BBC AI Runtime 負責

| ID | 職責 | 說明 |
|----|------|------|
| **RT-R1** | Runtime Routing | 依 Runtime Contract（如 `intent`、`entities`、`clarification` detection、Runtime State 等）**自行**決定執行路徑；**不**依賴 `dispatch_plan` |
| **RT-R2** | Runtime Dispatch | 將契約派送至對應 Runtime Module |
| **RT-R3** | Runtime Coordination | 協調 Module 之間的執行順序與交接，不侵入 Module 內部邏輯 |
| **RT-R4** | Execution Lifecycle | 管理本輪**執行**生命週期（開始 → 派送 → 協調 → 交接 Composer） |

### 3.2 BBC AI Runtime 不負責

| 禁止項 | 說明 |
|--------|------|
| Semantic Understanding | 不得對 Customer Utterance 再做語意理解 |
| Intent Re-parse | 不得重新判斷 Intent |
| Entity Extraction | 不得重新抽取或改寫 Entity |
| Normalize | 不得重做 Gemini → Runtime Contract 轉換 |
| 修改 Gemini Output | 不得回寫或覆寫 Understanding 結果 |
| 修改 Normalize 結果 | 不得改寫已輸出之 Runtime Contract 語意欄位 |
| `dispatch_plan` / `execution_hint` / `runtime_action` / `search_action` | Gemini Output Contract 已禁止；Normalize 不得新增；Runtime **不得**依賴 |
| Conversation Memory Logic | 屬 Conversation Memory Module |
| Conversation State Logic | 屬 Conversation State Module |
| Product Search Logic | 屬 Product Module |
| Knowledge Runtime Logic | 屬 Knowledge Module |
| Human Service Logic | 屬 Human Module |
| Grounded Response | 最終回覆組裝屬 Grounded Response Composer |
| 產生最終回覆 | Runtime **不**產生客人可見最終文案 |

### 3.3 Runtime ↔ Module Boundary（RC-2）

| BBC AI Runtime **可**管理 | 屬各 Runtime Module（Runtime **不得**實作） |
|---------------------------|-----------------------------------------------|
| Execution Lifecycle | Conversation Memory Logic |
| Execution Coordination | Conversation State Logic |
| Routing / Dispatch（依 Runtime Contract） | Human Service Logic |
| | Product Search Logic |
| | Knowledge Runtime Logic |

> Runtime 可**讀取／消費** Module 提供之 State／Memory 投影以完成 Routing／Coordination；  
> **不得**在 Runtime 核心實作上述 Module 邏輯。

### 3.4 責任邊界（一句話）

> **BBC AI Runtime = Execution Orchestrator。**  
> 它依 Runtime Contract 自行路由、派送、協調，並管理 **Execution Lifecycle**；  
> **不**理解、**不**轉換語意、**不**實作領域／Memory／State Module 邏輯、**不**組最終回覆。

---

## 4. Runtime Principles

| ID | 原則 | 說明 |
|----|------|------|
| **RP-1** | **Execution Only** | Runtime 只做執行協調，不做語意理解 |
| **RP-2** | **依 Runtime Contract 執行** | 唯一執行依據為 AIU Normalize 輸出之 Runtime Contract |
| **RP-3** | **不得修改 AIU 理解結果** | 不得修改 Gemini Output、Normalize Entity、Intent、Clarification Detection 語意 |
| **RP-4** | **負責 Routing、Dispatch、Coordination** | Runtime 的核心能力是路由、派送與協調，而非領域實作 |
| **RP-5** | **保持低耦合、高擴充** | Module 可獨立演進；Runtime 僅透過契約與協調介面耦合 |

### 4.1 Supporting Invariants

| ID | Invariant |
|----|-----------|
| **RI-1** | Runtime **自行**依 Runtime Contract（`intent` / `entities` / `clarification` / Runtime State 等）完成 Routing；**不得**依賴 `dispatch_plan` |
| **RI-2** | `dispatch_plan` / `execution_hint` / `runtime_action` / `search_action` **不得**出現於 Gemini Output，亦**不得**由 Normalize 新增，亦**不得**作為 Runtime 執行依據 |
| **RI-3** | Clarification Detection（AIU）≠ Clarification Execution（Runtime 依 Contract 協調至對應路徑） |
| **RI-4** | Product Search Strategy（Keyword / Source / URL Mapping）屬 Product Module / Adapter |
| **RI-5** | Runtime 管理 Execution Lifecycle／Coordination；Conversation Memory／State Logic 屬各 Module；不得以 State 重判 Intent |

---

## 5. Runtime Flow

### 5.1 主流程

```text
[1] AIU Normalize 輸出 BBC AI Runtime Contract
        ↓
[2] BBC AI Runtime 接收 Contract（唯讀語意；可讀 Module 提供之 State 投影）
        ↓
[3] Runtime Routing（依 intent / entities / clarification / Runtime State 等自行決定）
        ↓
[4] Runtime Dispatch（派送至目標 Module）
        ↓
[5] Runtime Coordination（必要時跨 Module 交接）
        ↓
[6] Runtime Module(s) 執行領域邏輯
        ↓
[7] 執行結果交接至 Grounded Response Composer
        ↓
[8] Composer 產出最終回覆 → Channels
```

### 5.2 Routing 概念（不展開 Module 實作）

BBC AI Runtime **根據 Runtime Contract 自行 Routing**，例如：

| Runtime Contract 訊號（示例） | Runtime 行為 |
|-------------------------------|--------------|
| `intent = product_search` 且 clarification 未要求追問 | Dispatch → Product Runtime Module |
| `intent = knowledge` | Dispatch → Knowledge Runtime Module |
| `clarification.required = true`（Detection） | Dispatch → Clarification Execution 路徑 |
| `intent = human_service` 或 State 顯示需真人 | Dispatch → Human Service 路徑 |

> 上表僅為 **Routing 概念示例**，非完整決策表。  
> **不得**依賴 `dispatch_plan` / `execution_hint` / `runtime_action` / `search_action`。  
> 各 Module 內部邏輯 **不在本文件定義**。

### 5.3 Flow 邊界

| 步驟 | 屬於 BBC AI Runtime？ | 說明 |
|------|----------------------|------|
| [1] Normalize 產出 Contract | ❌ | AIU Normalize |
| [2]～[5] 接收／Routing／Dispatch／Coordination | ✅ | Runtime 核心 |
| [6] Module 領域執行 | ⚠ 協調是 Runtime；邏輯是 Module | |
| [7]～[8] Composer / Channel | ❌ | Composer / Channel；Runtime 只做交接 |

---

## 6. Runtime Coordination

### 6.1 Coordination 定義

**Runtime Coordination** 指：在不侵入 Module 內部實作的前提下，依 Runtime Contract 與 Lifecycle／State，安排 Module 的啟動、交接與完成訊號。

### 6.2 Coordination 允許

| 允許項 | 說明 |
|--------|------|
| 依 Runtime Contract 自行選擇 Module | 依 `intent` / `entities` / `clarification` / Runtime State 等完成 Routing 結果之執行 |
| 傳遞 Runtime Contract（唯讀語意投影） | Module 消費契約，不改寫 AIU 語意 |
| 讀取 Module 提供之 State／Memory 投影 | 供 Routing／Coordination；**不**實作 Conversation State／Memory Logic |
| 將 Module 輸出交接給 Composer | 執行完成後之下游交接 |
| 依 Contract 觸發 Clarification Execution | Detection 已由 AIU 完成；Runtime 決定是否執行追問路徑 |

### 6.3 Coordination 禁止

| 禁止項 | 說明 |
|--------|------|
| 在 Runtime 內重做 NLU | 違反 RP-1 / RP-3 |
| 為「修正理解」改寫 Entity / Intent | 違反 RP-3 |
| 依賴 `dispatch_plan` / `execution_hint` / `runtime_action` / `search_action` | 違反 RC-1 / RI-1 / RI-2 |
| 在 Runtime 核心實作 Product／Knowledge／Human／Memory／State 業務邏輯 | 違反 RC-2 / RP-4 |
| 直接產生最終客人回覆 | 屬 Composer |
| 繞過 Runtime Contract 自行發明執行意圖 | 違反 RP-2 |

### 6.4 低耦合擴充模型

```text
BBC AI Runtime
   │
   ├── Product Module              （可獨立擴充）
   ├── Knowledge Module            （可獨立擴充）
   ├── Human Module                （可獨立擴充）
   ├── Conversation Memory Module  （可獨立擴充）
   ├── Conversation State Module   （可獨立擴充）
   ├── Clarification Path          （可獨立擴充）
   └── （Future Modules）          （依契約掛載）
```

新增 Module 時：

1. 不修改 AIU Understanding / Normalize 契約語意  
2. 由 Runtime 依 Runtime Contract 訊號掛載 Routing／Dispatch  
3. Runtime 僅增加 Routing／Coordination 接點，不吸收領域／Memory／State 邏輯  
4. **不得**引入 `dispatch_plan` 作為掛載依據 

---

## 7. Out of Scope

本文件 **不**定義、**不**修改：

| 排除項 | 歸屬 |
|--------|------|
| Entity Core / Definition / Output Contract | AIU Frozen SSOT |
| AIU Normalize Rules / Mapping | `BATS_AI_NORMALIZE.md` |
| Gemini Prompt / Understanding | AIU Prompt Scheme / Understanding Core |
| Product Search Logic / Keyword Composition / Source / URL Mapping | Product Module / Adapter |
| Knowledge Runtime Logic | Knowledge Module |
| Human Service Logic | Human Module |
| Conversation Memory Logic | Conversation Memory Module |
| Conversation State Logic | Conversation State Module |
| `dispatch_plan` / `execution_hint` / `runtime_action` / `search_action` | 禁止欄位；非 Runtime 輸入 |
| Grounded Response 組句／版型 | Grounded Response Composer |
| Module 內部演算法、類別、檔案路徑 | Coding / 各 Module SSOT |
| Legacy Migration / Dual-track 細節 | 遷移專案／既有 Design 文件 |
| Coding / Commit | 本階段禁止 |

---

## 8. Freeze Readiness Review

### 8.1 是否符合 AIU v2 主線

| 檢查 | 結果 |
|------|------|
| 承接 AIU Normalize Runtime Contract | ✅ |
| 不依賴 `dispatch_plan` / `execution_hint` 等禁止欄位 | ✅ RC-1 |
| 不重新理解／不重抽 Entity／不重判 Intent | ✅ RP-1 / RP-3 |
| 不修改 Gemini Output / Normalize 結果 | ✅ |
| 不產生最終回覆 | ✅ 交接 Composer |
| 官方 RP-1～RP-5 已定義 | ✅ |

### 8.2 是否符合 Responsibility Separation

| 檢查 | 結果 |
|------|------|
| Runtime = Execution Orchestrator | ✅ |
| Runtime 僅管理 Execution Lifecycle／Coordination | ✅ RC-2 |
| Memory／State／Product／Knowledge／Human Logic 留在 Modules | ✅ RC-2 |
| Composer 負責最終回覆 | ✅ |
| Normalize 負責 Translation（不新增 Execution Decision） | ✅ 上游 |

### 8.3 Boundary 衝突

| 項目 | 狀態 | 阻擋 Freeze？ |
|------|------|---------------|
| 與 `BATS_AI_RUNTIME_DESIGN.md`（9-C-1 實作設計）並存 | 本文件為 Execution Layer 架構 SSOT；Design 文件為實作參考 | 否 |
| Module 內部未定義 | 刻意 Out of Scope | 否 |
| Search Strategy 細節 | 屬 Product Module；Runtime 僅 Coordination | 否 |
| Routing 決策細節表 | 僅概念示例；完整決策屬後續實作／政策，不阻擋架構 Freeze | 否 |

**無阻擋 Freeze 的 Boundary 衝突。**

### 8.4 Ready for Freeze？

### **Frozen — BBC AI Runtime v1.0 Rev.1**

**理由：**

1. RC-1／RC-2 已完成；不再依賴 `dispatch_plan`。  
2. Runtime ↔ Module Boundary 已明確（Lifecycle／Coordination vs Memory／State／Domain Logic）。  
3. 承接 AIU Normalize，且未修改任何 Frozen／Rev.1 上游文件。  
4. 未越界定義 Module 內部實作。

---

## Revision History

| 版本 | 日期 | 說明 |
|------|------|------|
| v1.0 | 2026-07-10 | BBC AI Runtime（Execution Layer）Architecture Definition（Freeze Candidate） |
| v1.0 Rev.1 | 2026-07-10 | RC-1 移除 `dispatch_plan` 依賴；RC-2 補強 Runtime ↔ Module Boundary |
| v1.0 Rev.1 Frozen | 2026-07-10 | Phase A Freeze：狀態標示 Frozen |

---

**本階段：Phase A Freeze Correction only。未 Coding。未 Commit。未修改任何 Frozen 上游文件。**
