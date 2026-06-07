# CO_WORK_POLICY.md

**專案：** BBC AI SaaS / BATS / ETT / API Gateway / Travel Data Center  
**定位：** 最高協作治理文件（Single Source of Truth, SSOT）  
**適用對象：** ChatGPT、Cursor、開發者、維運人員  
**衝突處理：** 若其他文件與本文件衝突，**以本文件為準**。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | 文件目的 |
| §2 | 核心原則 |
| §3 | 文件治理原則 |
| §4 | 架構治理原則 |
| §5 | Git Safe 原則 |
| §6 | P1 / P2 / P3 原則 |
| §7 | Cursor 協作原則 |
| §8 | 文件優先順序 |
| §9 | Git / Commit / Push 決策流程 |
| §10 | 技術債管理 |
| §11 | 例外情況 |
| §13 | 文件驅動開發（Document-Driven Development） |
| §14 | Recovery First 原則 |
| §15 | SSOT First 原則（含 SSOT Check 強制流程） |
| §16 | 結論 |

---

## 1. 文件目的

本文件為 **最高治理文件**，用於規範 ChatGPT、Cursor、開發者之間的協作方式。

目的包括：

- 統一決策原則，避免各自解讀、各自實作
- 明確工作優先順序（P1 / P2 / P3）
- 規範文件與程式的先後順序
- 規範 Git Safe 流程（Commit ≠ Push）
- 確立文件驅動開發（Document-Driven Development）、Recovery First 與 **SSOT First** 原則
- 降低範圍失控、重工、架構散落與 Production 風險

本文件不取代各領域的專項政策（如日期規則、tenant registry、短網址策略），但定義其 **優先順序與治理邊界**。

---

## 2. 核心原則

所有協作與實作必須遵守以下核心原則：

| 原則 | 說明 |
|------|------|
| **安全第一** | 不暴露憑證、不繞過 tenant 隔離、不引入 open redirect / SQL injection 等高風險變更；高風險修改需可回滾且有驗證計畫 |
| **所有修改必須可 rollback** | 變更應可透過 git revert、config 關閉、feature flag 或備份還原；禁止不可逆的一次性操作（除非 P1 且事後補文件） |
| **緊扣主線目標** | 每次工作須對齊當前主線（Pilot、里程碑、驗收項）；拒絕與主線無關的「順手優化」（詳 §7.4 Development First） |
| **避免無意義測試** | 不為覆蓋率而寫測試；測試應驗證真實行為、契約或回歸風險 |
| **避免範圍失控** | 單次變更保持最小正確 diff；不擴及未請求的模組、tenant、平台 |
| **避免重工** | 修改前先查既有文件、Registry、Service；不重複造輪子、不平行實作相同規則 |
| **優先可交付成果** | 以可驗收、可上線、可回報為優先；文件與程式皆須能讓下一位協作者快速接手 |
| **簡明、嚴謹、可快速擴充、低重構** | 偏好清楚分層與可重用元件；避免過度抽象，也避免為單一案例硬編碼 |

---

## 3. 文件治理原則

### 3.1 文件先於程式

**明確規定：文件先於程式。**

標準流程：

```
文件更新
  → 文件確認
    → 程式修改
      → 測試
        → Commit
```

### 3.2 禁止事項

- **禁止** 先改程式、再補文件
- **禁止** 僅在對話或口頭達成共識而未寫入正式文件
- **禁止** 在程式註解中偷偷定義架構規則以取代文件

### 3.3 必須文件化的內容

以下主題若涉及行為變更，**必須先文件化**：

- 重要架構原則與分層邊界
- Hybrid Search / 搜尋規則
- 商品源（Product Source）規則
- 日期解析與預設規則
- Tenant / Source Registry 契約
- P1 / P2 / P3 分類與處置
- Git Safe、Commit / Push 策略
- 短網址、多商品源、Publisher 整合策略

---

## 4. 架構治理原則

### 4.1 分層意識

修改程式前，必須先確認所屬架構層：

| 層級 | 職責範例 |
|------|----------|
| **Policy** | 業務規則、日期策略、治理邊界（文件 SSOT） |
| **Layer** | Hybrid / BATS / Publisher / Formatter 等管線分層 |
| **Registry** | tenant、product source、platform、template 設定 |
| **Resolver** | tenant context、credential、strategy 解析 |
| **Service** | ShortUrl、Search、MultiSource Link 等可重用服務 |
| **Adapter** | 外部平台、Host B、legacy www 適配 |

**不要為單一功能東寫一塊、西寫一塊。**

### 4.2 禁止事項

| 禁止 | 原因 |
|------|------|
| **duplicated rules** | 同一規則多處實作導致不一致 |
| **scattered logic** | 邏輯散落各層，難維護、難測試 |
| **tenant hardcode** | 破壞多租戶擴展；應走 Registry / Resolver |
| **platform hardcode** | grp / bbctravel / tourcenter 等應走 catalog + adapter |
| **formatter 直接碰 DB** | 展示層不應承擔持久化或短鏈寫入 |
| **URL Builder 直接碰 DB** | Builder 只產長 URL；短鏈應在 Service / Publisher 層 |

### 4.3 可重用優先

- 短網址：**集中** `ShortUrlService` / `ProductSourceUrlPublisher`
- 多商品源 URL：**集中** `MultiSourceSearchUrlBuilder` + Link Service
- 日期規則：**集中** `BATS_HYBRID_DATE_POLICY.md` 及對應 Policy 層

---

## 5. Git Safe 原則

### 5.1 Commit ≠ Push

| 動作 | 定義 |
|------|------|
| **Commit** | 本機還原點；記錄已完成且經確認的變更 |
| **Push** | 遠端同步點；影響團隊與部署基線 |

**Commit 不等於 Push。**

### 5.2 預設策略

- **預設累積 1～3 個安全 Commit 後**，再評估是否 Push
- 單檔、單一目的 Commit 優先（避免 `git add .` 誤納無關檔案）
- Commit 前確認 `git diff --cached --name-only` 僅含預期檔案

### 5.3 建議 Push 的情況

僅在以下情況建議 Push：

- 工作階段結束
- 重大里程碑達成
- 高風險修改前（遠端備份）
- 明確遠端備份需求
- 團隊同步需求

### 5.4 禁止事項

- **禁止** 因為 `ahead 1` 就自動 Push
- **禁止** 未經確認就 `git add .`
- **禁止** 將含憑證檔（`.env`、secrets）納入 Commit
- **禁止** 未經授權的 force push 至 main / master

### 5.5 Commit Necessity Principle（Commit 必要性原則）

每次 Commit **必須同時符合** 下列雙軸；任一不符 → **不建議 Commit**（見 §9.1、§9.3）：

| 軸向 | 要求 | 對應既有條款 |
|------|------|----------------|
| **Necessity（必要性）** | 有明確目的；形成可還原的 Recovery Point 或階段成果 | §5.1、§14.4 |
| **Safety（安全性）** | 白名單 stage、無 secrets、可 rollback、已驗證（若適用） | §2「安全第一」、§5.4、§9.1 |

**建議 Commit 的情境：**

- Recovery Point（本機還原點）
- 功能階段完成（含測試或 LINE OA 實測通過）
- 里程碑達成
- 高風險修改前（先建立本機還原點）
- 已完成驗證之 docs / 程式變更

**不建議 Commit 的情境：**

- 純格式微調、無行為或 SSOT 意義
- 尚未驗證（測試未跑、實測未完成）
- 無明確目的、無法一句話說明「why」
- **僅為了 Push**（違反 §5.1 Commit ≠ Push）

### 5.6 中文 Commit Message 政策

**預設使用中文 Conventional Commits**（補強 §9.1「反映 why」之格式規範）：

```
<type>(<scope>): <中文簡述>
```

**範例：**

```
feat(bats): 完成 grp 商品源短網址整合
fix(line): 修正多商品源 CTA 顯示
docs(cwp): 補充 Git 治理規範
```

| 允許 | 說明 |
|------|------|
| 英文技術名詞 | 如 `ShortUrlService`、`ClassifyProduct.aspx` |
| 專案／產品名 | BATS、BBCTravel、LINE OA、API Gateway |

**禁止** 無意義或過於籠統的訊息，例如單獨使用：`update`、`fix`、`modify`、`temp`。

---

## 6. P1 / P2 / P3 原則

### 6.1 P1 — 立即處理

符合以下任一項即為 **P1**：

- 阻塞主線進度
- Production 風險（服務中斷、資料錯誤、安全漏洞）
- 安全問題
- 客戶可見錯誤（錯誤連結、錯誤回覆、404/403 等）

**處置：** 立即處理；可先 hotfix，但事後必須補文件與復盤（見 §11）。

### 6.2 P2 — 記錄技術債

符合以下特徵為 **P2**：

- 技術債
- 不阻塞主線
- 近期應改善（可讀性、維護性、架構一致性）

**處置：** 記錄於 `docs/TECH_DEBT.md`；排入近期迭代，不與 P1 搶資源。

### 6.3 P3 — 納入 Roadmap

符合以下特徵為 **P3**：

- Roadmap 項目
- 未來功能
- 長期優化（點擊追蹤、多網域短鏈、進階分析等）

**處置：** 納入 Roadmap；不在當前主線無謂展開。

---

## 7. Cursor 協作原則

### 7.1 建議必須先分類

所有 Cursor（或 AI）建議，**必須先分類**：

- P1
- P2
- P3

再決定：

- **立即執行**
- **延後執行**
- **納入 Roadmap**

**不要直接照單全收。**

### 7.2 延伸建議的評估清單

Cursor 若提出延伸建議，必須先評估：

| 問題 | 若為「是」則需警惕 |
|------|-------------------|
| 是否符合當前目標？ | 否 → 拒絕或降為 P3 |
| 是否造成範圍擴張？ | 是 → 需文件更新與明確授權 |
| 是否造成重工？ | 是 → 先查既有 Service / 文件 |
| 是否需要先更新文件？ | 是 → 先文件、後程式 |

### 7.3 執行邊界

- 使用者明確限制（不 commit、不 push、唯讀盤點）**必須遵守**
- 單次任務保持最小 scope；不主動擴及未請求檔案
- 高風險操作（SQL、Apache、`.env`、force push）需明確授權

### 7.4 Development First Principle（主線優先原則）

**Review、Commit、Push 屬於治理工具**，用於可恢復、可追蹤、可協作；**不得取代主線開發**。

| 原則 | 說明 |
|------|------|
| **主線未完成 → 優先主線** | 功能交付、驗收、Pilot 實測優先於文件整理或工作區清理 |
| **治理可批次處理** | Workspace Cleanup、CWP 補強、docs-only commit 可累積後一次處理（見 §5.2、§6.2 P2） |
| **避免治理循環** | 勿陷入 Review → Commit → Push → Review → Commit → Push 而停滯開發 |

與 §2「緊扣主線目標」、§6 P1/P2/P3 一致：P1 阻塞主線立即處理；P2/P3 治理項目不與主線搶資源。

---

## 8. 文件優先順序

當多份文件並存時，依下列優先順序解讀與修訂：

```
CO_WORK_POLICY.md                          ← 本文件（最高）
    ↓
DOCUMENTATION_GOVERNANCE_POLICY.md
    ↓
RECOVERY_AND_ROLLBACK_POLICY.md
    ↓
BATS_HYBRID_DATE_POLICY.md
    ↓
TENANT_SOURCE_REGISTRY_POLICY.md
    ↓
PRODUCT_SOURCE_SHORTURL_POLICY.md
    ↓
API_POLICY.md
    ↓
功能文件（Phase 設計、Contract、MVP 報告等）
    ↓
程式實作
```

**規則：**

- 下層文件不得違反上層政策
- 程式實作不得違反已確認之正式文件
- 新增領域政策時，應在本節清單中掛載位置，避免平行 SSOT

---

## 9. Git / Commit / Push 決策流程

每次 **Commit 完成後**，必須先自問：

| 問題 | 預設答案 |
|------|----------|
| 是否需要立即 Push？ | **否** |
| 是否只是本機還原點？ | **是**（多數情況） |
| 是否可累積 1～3 個 commit？ | **是** |
| 是否已達工作階段結束或重大里程碑？ | 若是 → 可評估 Push |

**預設不 Push，除非有明確必要理由。**

### 9.1 Commit 前檢查清單

- [ ] 符合 §5.5 **Necessity + Safety** 雙軸
- [ ] 僅 stage 預期檔案（禁止 `git add .` 除非明確授權）
- [ ] `git diff --cached --name-only` 符合預期
- [ ] 未含 secrets / `.env` / 憑證
- [ ] Commit message 符合 §5.6（中文 Conventional Commits；反映「why」）
- [ ] 相關文件已更新（若為架構或行為變更）

### 9.2 Push 前檢查清單

- [ ] 工作階段或里程碑已完成
- [ ] 本機測試或驗收已通過（若適用）
- [ ] 團隊或遠端備份需求明確
- [ ] 使用者已授權 Push（若協作流程要求）

### 9.3 Commit 規劃回報（提出 Commit 建議時）

ChatGPT、Cursor 在**建議建立 Commit** 時（執行前或 Commit Review 階段），**必須同時說明**下列四項（補強 §5.5 Necessity 之溝通格式）：

| 項目 | 說明 |
|------|------|
| **本次 Commit 目的** | 一句話說明此 commit 完成什麼 |
| **是否必要** | 是／否；若否，說明延後理由 |
| **預估剩餘 Commit 數量** | 本工作階段尚待 commit 的獨立變更數 |
| **是否接近 Push 時機** | 對照 §5.2、§5.3；預設「暫不 Push」 |

**範例：**

```
本次 Commit：完成 grp 商品源短網址整合
必要性：是
預估剩餘 Commit：1 個
Push 建議：暫不 Push；累積至 1～3 個有意義 Commit 後再評估
```

---

## 10. 技術債管理

技術債統一記錄於：

```
C:\bbc-ai-bot\docs\TECH_DEBT.md
```

### 10.1 記錄格式

每筆技術債應包含：

| 欄位 | 說明 |
|------|------|
| **編號** | 唯一 ID（如 TD-001） |
| **等級** | P2 或 P3 |
| **來源** | 發現情境（盤點、Pilot、Review 等） |
| **風險** | 不處理的後果 |
| **建議處理時機** | 近期迭代 / 下個 Phase / Roadmap |
| **是否阻塞主線** | 是 / 否 |

### 10.2 與 P2 / P3 的關係

- **P2** 項目 → 寫入 `TECH_DEBT.md`，排期處理
- **P3** 項目 → 可同時寫入 `TECH_DEBT.md` 與 Roadmap
- **P1** 不進技術債佇列，直接處理

---

## 11. 例外情況

### 11.1 P1 Hotfix

**P1 Production 問題可先修復**，允許：

- 先改程式恢復服務
- 文件與測試可緊接補上

### 11.2 Hotfix 後必須補齊

修復後 **必須** 補：

- **文件**（行為變更、架構決策、復盤摘要）
- **技術債記錄**（若引入暫時性方案）
- **復盤紀錄**（根因、影響、後續防呆）

不得讓 Hotfix 成為永久架構。

---

## 13. 文件驅動開發（Document-Driven Development）

### 13.1 原則

1. **重要架構決策必須文件化。**
2. **文件為正式依據（SSOT）。** 實作、審查、驗收皆以已採納之正式文件為準。
3. **ChatGPT、Cursor、工程師** 進行分析與實作前，應 **優先查閱對應文件**（本文件 → `DOCUMENTATION_GOVERNANCE_POLICY.md` → 領域 SSOT）。
4. **若缺少正式文件：** **先建立文件，再進行實作。**
5. **禁止長期依賴** 以下來源作為正式規格：
   - 聊天室歷史
   - 個人記憶
   - 口頭約定
6. **重大架構調整** 必須依序進行：

```
文件更新
  ↓
文件確認
  ↓
程式修改
```

**禁止反向操作**（先改程式、後補文件；P1 hotfix 例外見 §11）。

7. **所有 L0 / L1 / L2 文件應形成完整治理鏈**（見 §8、`DOCUMENTATION_GOVERNANCE_POLICY.md` §4），避免平行 SSOT 或規則漂移。

### 13.2 與本文件其他章節的關係

| 章節 | 關聯 |
|------|------|
| §3 文件治理原則 | 定義「文件先於程式」流程 |
| §8 文件優先順序 | 定義 L0–L3 解讀順序 |
| §15 SSOT First 原則 | 工作前 SSOT 確認與五步流程 |
| `DOCUMENTATION_GOVERNANCE_POLICY.md` | L0 文件治理細則（SSOT、命名、生命週期） |

---

## 14. Recovery First 原則

### 14.1 原則

1. **任何變更都必須：** **可恢復**、**可追蹤**、**可驗證**。
2. **功能開發速度不得優先於 Recovery 能力。**
3. **任何上線前必須能回答：**
   - 如何 rollback？
   - Rollback 需要多久？
   - 如何驗證 rollback 成功？

   **若無法回答 → 禁止上線。**

4. **Commit 視為 Recovery Point**（本機還原點）。
5. **Git Safe 為 Recovery 機制的一部分**（見 §5、§9）；Commit ≠ Push，累積安全 Commit 後再評估 Push。
6. **任何 Apache、IIS、SQL、API、LINE OA、BATS 相關修改，** 都必須 **先評估 Recovery 方案**（備份、還原步驟、驗證方式）。
7. **詳細流程以 `RECOVERY_AND_ROLLBACK_POLICY.md` 為正式依據**（L1 架構政策層；若尚未建立，應於高風險變更前補齊）。

### 14.2 與核心原則的關係

| 原則 | 對應 |
|------|------|
| §2「所有修改必須可 rollback」 | Recovery First 的基礎要求 |
| §5 Git Safe | Commit 作為 Recovery Point |
| §11 P1 Hotfix | 可先修復，但事後必須補文件與復盤 |

---

## 15. SSOT First 原則

### 15.1 SSOT First

**任何工作開始前，必須先確認是否已有正式 SSOT 文件。**

### 15.2 若已有 SSOT

**必須依序：**

1. **閱讀 SSOT**
2. **依據 SSOT 分析**
3. **依據 SSOT 設計**
4. **依據 SSOT 開發**
5. **依據 SSOT 驗證**

### 15.3 若尚未有 SSOT

**必須：**

- **先建立正式文件**
- **完成審查（Adopted）後**，才能開始設計與開發

詳見 `DOCUMENTATION_GOVERNANCE_POLICY.md` §5 文件建立判斷原則。

### 15.4 禁止作為正式依據

以下情況 **禁止** 直接作為正式規格來源：

- 聊天室歷史
- ChatGPT 記憶
- Cursor 記憶
- 個人記憶
- 口頭約定

### 15.5 正式依據順序

```
L0 — CO_WORK_POLICY.md
    ↓
L0 — DOCUMENTATION_GOVERNANCE_POLICY.md
    ↓
L1 — Policy Files（如 RECOVERY、TENANT_SOURCE_REGISTRY、PRODUCT_SOURCE_SHORTURL …）
    ↓
L2 — Design Files（如 BATS_HYBRID_DATE_POLICY …）
    ↓
程式碼
    ↓
聊天室（僅背景參考）
```

### 15.6 衝突裁決

| 衝突情境 | 裁決 |
|----------|------|
| **Cursor 建議** 與 **ChatGPT 建議** 不同 | **以 SSOT 文件為準** |
| **程式** 與 **SSOT** 衝突 | **先更新文件**，或 **先修正程式**；**不得長期不一致** |

### 15.7 單一領域單一 SSOT

**每個領域只能有一份正式 SSOT。**

| 領域 | 正式 SSOT |
|------|-----------|
| 日期規則 | `BATS_HYBRID_DATE_POLICY.md` |
| Recovery / Rollback | `RECOVERY_AND_ROLLBACK_POLICY.md` |
| Tenant / Source Registry | `TENANT_SOURCE_REGISTRY_POLICY.md` |
| Short URL | `PRODUCT_SOURCE_SHORTURL_POLICY.md` |
| 文件治理 | `DOCUMENTATION_GOVERNANCE_POLICY.md` |
| 最高協作治理 | 本文件 |

**禁止** 同一領域平行多份 SSOT 互相矛盾（詳 §8、`DOCUMENTATION_GOVERNANCE_POLICY.md` §2、§5.6）。

### 15.8 與本文件其他章節的關係

| 章節 | 關聯 |
|------|------|
| §3 文件治理原則 | 文件先於程式 |
| §13 文件驅動開發 | 實作前查閱 SSOT |
| §8 文件優先順序 | L0–L2 鏈 |
| §7 Cursor 協作原則 | AI 建議不得凌駕 SSOT |
| §7.4 Development First | 主線優先；治理工具不取代交付 |
| §5.5 Commit Necessity | Commit 須 Necessity + Safety 雙軸 |
| §5.6 中文 Commit Message | 預設中文 Conventional Commits |
| §9.3 Commit 規劃回報 | 提出 commit 建議時之四項說明 |
| §15.9 SSOT Check | 功能開發前強制五步檢查流程 |

### 15.9 SSOT Check

**所有功能開發前，必須先執行 SSOT Check。**

#### SSOT Check 標準流程

**Step 1 — 確認是否已有對應 SSOT**

- 查閱 §8 文件優先順序與 §15.7 領域 SSOT 對照表
- 確認本次需求所屬領域（日期、Recovery、Tenant、Short URL 等）

**Step 2 — 若已有 SSOT：閱讀 SSOT**

- 完整閱讀該領域正式 SSOT（Adopted 狀態）
- 必要時一併查閱上層 L0 / L1 政策

**Step 3 — 判斷本次需求是否需更新文件**

- 若需求超出 SSOT 現有範圍 → **先更新 SSOT**，再設計與開發
- 若需求在 SSOT 範圍內 → 依 SSOT 執行

**Step 4 — 確認是否與現有 SSOT 衝突**

- 檢查 ChatGPT / Cursor 建議、既有程式是否與 SSOT 一致
- 若有衝突 → 依 §15.6 裁決（以 SSOT 為準；先更新文件或先修正程式）

**Step 5 — 完成 SSOT Check 後，才能開始**

- **分析**
- **設計**
- **開發**
- **測試**

#### 若沒有 SSOT

流程改為：

```
需求
  ↓
建立文件
  ↓
文件審查
  ↓
建立 SSOT（Adopted）
  ↓
設計
  ↓
開發
  ↓
測試
```

#### 禁止事項

- **禁止直接跳過 SSOT Check**

#### 適用場景

以下情境 **皆應先執行 SSOT Check**：

| 場景 | 說明 |
|------|------|
| **ChatGPT Prompt** | 分析、設計、實作建議前 |
| **Cursor Prompt** | 任何程式修改或架構建議前 |
| **Code Review** | 審查變更是否符合 SSOT |
| **Architecture Review** | 架構決策與分層邊界審查 |

---

## 16. 結論

**`CO_WORK_POLICY.md` 是最高治理文件。**

未來所有重要架構決策、流程調整、治理規則變更，必須：

1. **先更新本文件**，或其下層正式政策文件
2. **經確認後** 再修改程式（**文件驅動開發**，見 §13）
3. **依 Git Safe** 單獨 Commit；須符合 §5.5 Necessity + Safety；預設不 Push
4. **依 §5.6** 使用中文 Conventional Commits；**依 §9.3** 提出 commit 建議時說明目的與 push 時機
5. **依 P1 / P2 / P3** 決定執行優先順序；**依 §7.4** 主線未完成時優先交付
6. **依 Recovery First**（§14）確保可恢復、可追蹤、可驗證後再上線
7. **依 SSOT First**（§15）任何工作先確認並遵循正式 SSOT；**功能開發前必須完成 SSOT Check**（§15.9）

ChatGPT、Cursor、開發者皆應以本文件為協作起點；若與其他文件或實作衝突，**以本文件為準**。

---

## Revision History

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-06-05 | Initial Version |
| 1.1 | 2026-06-05 | Add Document-Driven Development and Recovery First principles. |
| 1.2 | 2026-06-05 | Add SSOT first principle and single-source governance rules. |
| 1.3 | 2026-06-05 | Add mandatory SSOT Check workflow. |
| 1.4 | 2026-06-06 | Add Commit Necessity、Commit 規劃回報、中文 Commit Message、Development First 原則（Gap D-3 補強） |
