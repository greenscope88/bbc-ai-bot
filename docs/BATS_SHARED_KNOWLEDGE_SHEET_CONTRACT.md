# BATS_SHARED_KNOWLEDGE_SHEET_CONTRACT.md

**專案：** BBC AI SaaS / BATS / BDS / Travel Data Center  
**定位：** L3 資料契約層 — Shared Knowledge Google Sheet Contract（P2-1）  
**上層文件：** `CO_WORK_POLICY.md`、`DOCUMENTATION_GOVERNANCE_POLICY.md`  
**相關文件：** `BATS_SHARED_KNOWLEDGE_CONTRACT.md`、`BATS_DATA_CONTRACT.md`、`BATS_DATA_SOURCE_REGISTRY.md`、`BATS_DATA_SYNC_POLICY.md`、`BATS_TENANT_DATA_CLASSIFICATION.md`  
**適用範圍：** Industry Shared、Global Shared 之 **Google Sheet** 輸入；首個產業範例 `travel`  
**適用對象：** ChatGPT、Cursor、開發者、維運人員、BBC Industry Maintainer  
**衝突處理：** Knowledge Priority 以 `BATS_DATA_SOURCE_REGISTRY.md` §4.4、`BATS_DATA_SYNC_POLICY.md` §18.8 為準；Shared JSON 輸出格式以 `BATS_SHARED_KNOWLEDGE_CONTRACT.md` 為準；**Shared Sheet Tab／欄位語意以本文件為準**。

> **Status: Planned** — Sheet Contract 治理方向；BDS Shared Sheet 同步 **未實作**。  
> **不修改** Registry JSON Schema；**不新增** `private_drive_folder_id`。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Purpose |
| §2 | Scope & Non-Scope |
| §3 | Input Strategy Alignment |
| §4 | Shared Travel Sheet — 建議 Tabs |
| §5 | Field Semantics |
| §6 | Sheet → GCS Mapping（治理方向） |
| §7 | Industry Shared First Principle |
| §8 | Governance & Runtime Boundary |
| §9 | Cross References |
| §10 | 版本紀錄 |

---

## 1. Purpose

### 1.1 文件定位

本文件定義 **Shared Knowledge** 之 **Google Sheet Structured Knowledge Input Source** 契約。

| 術語 | 定義 |
|------|------|
| **Shared Knowledge** | Industry / Global Shared Layer 之 `shared_knowledge` |
| **Input Source** | **Google Sheet**（Structured）；**不是** Google Drive PDF |
| **Runtime Output** | GCS `shared/{industry_code}/knowledge/`、`shared/global/knowledge/` |

### 1.2 與 Tenant Private Contract 分工

| 層級 | Sheet Contract SSOT |
|------|---------------------|
| **Tenant Private** | `BATS_DATA_CONTRACT.md`（5 Tab） |
| **Industry / Global Shared** | **本文件** |

---

## 2. Scope & Non-Scope

### 2.1 In Scope

| 項目 | 說明 |
|------|------|
| **Industry Shared Sheet** | `shared/{industry_code}/` 治理之 Google Sheet |
| **Global Shared Sheet** | 平台治理之 Global Sheet（跨產業極少數主題） |
| **建議 Tabs** | §4（以 `travel` 為首個產業範例） |
| **欄位語意** | §5 |

### 2.2 Out of Scope

| 排除 | 說明 |
|------|------|
| **BDS 同步程式** | 未實作 |
| **Registry Schema 修訂** | 不新增正式必填欄位 |
| **RAG / Vector / Embedding** | 未來僅從 GCS |
| **PDF / Drive 解析** | 屬 Drive Archive（Phase 6B+） |
| **Tenant Private 5 Tab** | 見 `BATS_DATA_CONTRACT.md` |

---

## 3. Input Strategy Alignment

| 載體 | 角色 |
|------|------|
| **Google Sheet** | Structured Knowledge **Input Source** |
| **Google Drive** | Archive Source / Original File Repository |
| **GCS** | Runtime Knowledge Source |
| **Future RAG** | **僅能** 從 GCS 取得資料；**不得** 直接從 Google Drive 建立 Runtime Flow |

```text
Shared Google Sheet（本文件）
        ↓ BDS Sync（未來；須 Registry + Policy）
shared/{industry_code}/knowledge/*.json
shared/global/knowledge/*.json
        ↓
Gemini / BATS Search（Runtime）
```

---

## 4. Shared Travel Sheet — 建議 Tabs

> **產業範例：** `travel`（`shared/travel/`）。其他產業 **可增列** Tabs；核心欄位語意不變（Industry-Agnostic 精神）。

### Tab 1 — `Travel_FAQ`

| 欄位 | 類型（語意） | 說明 |
|------|--------------|------|
| **`question`** | string | 問題（必填） |
| **`answer`** | string | 答案（必填） |
| **`last_updated`** | date / ISO 8601 | 最後更新 |
| **`status`** | enum | `active` / `inactive` / `draft`（概念） |

**典型內容：** 護照新辦、護照效期、台胞證、國際旅遊常識等 FAQ。

---

### Tab 2 — `Country_Entry_Rules`

| 欄位 | 類型（語意） | 說明 |
|------|--------------|------|
| **`country`** | string | 國家／地區（必填） |
| **`rule_content`** | text | 入境規定內容（必填） |
| **`source`** | string | 參考來源（選填） |
| **`last_updated`** | date / ISO 8601 | 最後更新 |
| **`status`** | enum | `active` / `inactive` / `draft` |

**典型內容：** 日本入境規定、泰國入境規定等。

---

### Tab 3 — `Travel_Notices`

| 欄位 | 類型（語意） | 說明 |
|------|--------------|------|
| **`title`** | string | 標題（必填） |
| **`content`** | text | 公告內容（必填） |
| **`effective_date`** | date | 生效日 |
| **`status`** | enum | `active` / `inactive` / `draft` |

**典型內容：** 旅遊須知、季節性提醒。

---

### Tab 4 — `Baggage_Rules`

| 欄位 | 類型（語意） | 說明 |
|------|--------------|------|
| **`airline`** | string | 航空公司（必填） |
| **`rule_content`** | text | 行李規定（必填） |
| **`last_updated`** | date / ISO 8601 | 最後更新 |
| **`status`** | enum | `active` / `inactive` / `draft` |

**典型內容：** 航空行李規定。

---

## 5. Field Semantics

| 原則 | 說明 |
|------|------|
| **英文 snake_case** | 與 `BATS_DATA_CONTRACT.md` §1.4 決策 A 對齊（v1） |
| **`status`** | 僅 `active` 列參與同步（概念）；細節於 BDS 實作 Phase 定案 |
| **整檔 Fail** | 建議與 Tenant Contract 相同策略；實作 Phase 定案 |
| **可擴充** | 新產業可 **增列** Tab；不破壞 v1 語意 |

---

## 6. Sheet → GCS Mapping（治理方向）

| Sheet Tab | 建議 GCS 輸出（概念） | `category`（概念） |
|-----------|----------------------|-------------------|
| `Travel_FAQ` | `shared/travel/knowledge/faq.json` | `faq` |
| `Country_Entry_Rules` | `shared/travel/knowledge/guide.json` 或專檔 | `guide` |
| `Travel_Notices` | `shared/travel/knowledge/notice.json` | `notice` |
| `Baggage_Rules` | `shared/travel/knowledge/reference.json` 或專檔 | `reference` |

> 正式 JSON 結構見 `BATS_SHARED_KNOWLEDGE_CONTRACT.md` §6。**不寫死** 實作檔名；上表為治理方向。

**Global Shared：** 僅極少數跨產業主題；同步至 `shared/global/knowledge/`（§7）。

---

## 7. Industry Shared First Principle

### 7.1 正式原則

若知識 **明確屬於特定產業**，應 **優先歸屬** `shared/{industry_code}/`，**而非** `shared/global/`。

| 歸屬 | 範例（`travel`） |
|------|------------------|
| **`shared/travel/`** | 護照規定、護照效期、台胞證、日本／泰國入境、航空行李、國際旅遊常識 |
| **`shared/global/`** | **僅** 跨所有產業共通知識；尚未建立產業分類之 **暫存** 知識 |

### 7.2 Status

**Reserved For Future Multi-Industry Expansion**

| 項目 | 說明 |
|------|------|
| **`travel`** | 首個產業；本文件 §4 為建議 Tabs |
| **`hotel`、`restaurant` 等** | 未來新增 `industry_code` + 同架構 Sheet Contract |
| **Global** | 最小化使用；避免將產業專屬知識誤放 Global |

---

## 8. Governance & Runtime Boundary

| 原則 | 說明 |
|------|------|
| **Shared Layer ≠ Public Layer** | 受控 fallback |
| **Default Private** | Sheet 預設不公開 |
| **預設不進 Runtime** | 須 Registry + Policy 啟用後才同步至 GCS |
| **Knowledge Priority** | Tenant > Industry > Global > Human Service（§9 cross-ref） |
| **禁止 AI 幻覺** | 三層皆無資料 → Human Service；不得編造旅遊規定 |

---

## 9. Cross References

| 文件 | 關係 |
|------|------|
| `BATS_SHARED_KNOWLEDGE_CONTRACT.md` | Shared JSON Contract、§3.6 Sheet Input |
| `BATS_DATA_SOURCE_REGISTRY.md` §4.4 | Knowledge Priority Rule |
| `BATS_DATA_SYNC_POLICY.md` §18.8 | P1-7 SSOT、Fallback Rule |
| `BATS_TENANT_DATA_CLASSIFICATION.md` §1.9 | 四層 Priority 總覽 |
| `BATS_DATA_CONTRACT.md` §1.5 | Structured Knowledge = Google Sheet |
| `BATS_GEMINI_RENDERER_CONTRACT.md` §9 | 禁止幻覺、轉人工 |

---

## 版本紀錄

| 版本 | 日期 | 說明 |
|------|------|------|
| **v1.0** | 2026-06-10 | P2-1：Shared Knowledge Sheet Contract（Travel 四 Tab 建議） |

---

## 審核狀態

| 項目 | 狀態 |
|------|------|
| **文件狀態** | Draft — Planned |
| **BDS 同步** | 未實作 |
| **Registry Schema** | **不變** |
