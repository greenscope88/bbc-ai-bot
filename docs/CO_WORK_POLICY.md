# CO_WORK_POLICY.md

**專案：** BBC AI SaaS / BATS / ETT / API Gateway / Travel Data Center  
**定位：** 最高協作治理文件（Single Source of Truth, SSOT）  
**適用對象：** ChatGPT、Cursor、開發者、維運人員  
**衝突處理：** 若其他文件與本文件衝突，**以本文件為準**。

---

## 1. 文件目的

本文件為 **最高治理文件**，用於規範 ChatGPT、Cursor、開發者之間的協作方式。

目的包括：

- 統一決策原則，避免各自解讀、各自實作
- 明確工作優先順序（P1 / P2 / P3）
- 規範文件與程式的先後順序
- 規範 Git Safe 流程（Commit ≠ Push）
- 降低範圍失控、重工、架構散落與 Production 風險

本文件不取代各領域的專項政策（如日期規則、tenant registry、短網址策略），但定義其 **優先順序與治理邊界**。

---

## 2. 核心原則

所有協作與實作必須遵守以下核心原則：

| 原則 | 說明 |
|------|------|
| **安全第一** | 不暴露憑證、不繞過 tenant 隔離、不引入 open redirect / SQL injection 等高風險變更；高風險修改需可回滾且有驗證計畫 |
| **所有修改必須可 rollback** | 變更應可透過 git revert、config 關閉、feature flag 或備份還原；禁止不可逆的一次性操作（除非 P1 且事後補文件） |
| **緊扣主線目標** | 每次工作須對齊當前主線（Pilot、里程碑、驗收項）；拒絕與主線無關的「順手優化」 |
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

---

## 8. 文件優先順序

當多份文件並存時，依下列優先順序解讀與修訂：

```
CO_WORK_POLICY.md                          ← 本文件（最高）
    ↓
DOCUMENTATION_GOVERNANCE_POLICY.md
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

- [ ] 僅 stage 預期檔案（禁止 `git add .` 除非明確授權）
- [ ] `git diff --cached --name-only` 符合預期
- [ ] 未含 secrets / `.env` / 憑證
- [ ] Commit message 反映「why」而非流水帳
- [ ] 相關文件已更新（若為架構或行為變更）

### 9.2 Push 前檢查清單

- [ ] 工作階段或里程碑已完成
- [ ] 本機測試或驗收已通過（若適用）
- [ ] 團隊或遠端備份需求明確
- [ ] 使用者已授權 Push（若協作流程要求）

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

## 12. 結論

**`CO_WORK_POLICY.md` 是最高治理文件。**

未來所有重要架構決策、流程調整、治理規則變更，必須：

1. **先更新本文件**，或其下層正式政策文件
2. **經確認後** 再修改程式
3. **依 Git Safe** 單獨 Commit；預設不 Push
4. **依 P1 / P2 / P3** 決定執行優先順序

ChatGPT、Cursor、開發者皆應以本文件為協作起點；若與其他文件或實作衝突，**以本文件為準**。

---

## Revision History

| 版本 | 日期 | 說明 |
|------|------|------|
| 1.0 | 2026-06-05 | Initial Version |
