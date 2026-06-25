# BATS AI Conversation Memory

**專案：** BBC AI SaaS / BATS
**定位：** AI Conversation Memory SSOT — 定義 AI 如何跨多輪對話理解客戶上下文、真人協作、AI Resume、Conversation Summary、Customer State 與 Outstanding Issues
**版本：** v1.0 Draft
**狀態：** Draft — 待 Review / Adopt
**主機參考：** 103.1.222.14（主機 A · `C:\bbc-ai-bot`）
**Branch：** feature/api-gateway-mvp

> 本文件屬於 **DDD（Document Driven Development）**，目的為建立 AI Conversation Memory SSOT。
> 本文件**不**討論：Runtime Memory、Token Window、Prompt Engineering、Coding。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Document Purpose |
| §2 | Conversation Memory Definition |
| §3 | Conversation Lifecycle |
| §4 | Conversation State |
| §5 | Customer Context |
| — | **Customer Memory Card（客戶記憶卡）— Core Concept** |
| §6 | Conversation Summary |
| §7 | Human Collaboration |
| §8 | AI Resume |
| §9 | Outstanding Issues |
| §10 | Memory Boundaries |
| §11 | Relationship With Other Documents |
| §12 | Future Extensions |
| §13 | Version |

---

## 1. Document Purpose

本文件定義 AI 如何管理 **Customer Conversation Lifecycle**。

AI 應維持：

- 對話連續性
- Context Continuity
- Human Collaboration
- Session Recovery

而**非** Runtime Memory。

---

## 2. Conversation Memory Definition

**Conversation Memory 不是聊天紀錄，而是 AI 對客戶目前狀態的理解。**

例如：

```
客戶：北海道
   ↓
日期：八月
   ↓
預算：四萬
   ↓
目前等待：客服報價
```

AI 應理解**目前狀態**，而不是**重新聊天**。

---

## 3. Conversation Lifecycle

### Customer Conversation Lifecycle

```
New Conversation
   ↓
Requirement Understanding
   ↓
Recommendation
   ↓
Clarification
   ↓
Human Service
   ↓
Resume
   ↓
Completed
```

每個階段，AI 都應知道：**目前客戶在哪一階段。**

---

## 4. Conversation State

AI 必須知道目前的 **Conversation State**，例如：

| State | 意義 |
|-------|------|
| Waiting Customer | 等待客戶回覆 |
| Waiting AI | 等待 AI 處理 |
| Waiting Human | 等待真人客服 |
| Completed | 已完成 |
| Pending | 處理中／待決 |
| Closed | 已結束 |

AI 必須隨時知道**目前狀態**。

---

## 5. Customer Context

AI 應理解 **Customer Context**，例如：

- 目的地
- 日期
- 預算
- 人數
- 旅遊型態
- 是否親子
- 是否蜜月
- 已推薦商品
- 未回答問題

**禁止：每輪重新開始。**

---

## Customer Memory Card（客戶記憶卡）

> **Core Concept（核心概念）** — Customer Memory Card 是本文件的核心概念。未來 AI Persona、AI Conversation Memory、AI Business Intelligence 皆應引用 Customer Memory Card。

### Definition

> **Customer Memory Card 是 AI 對目前客服服務狀態的標準化理解模型（Standardized Service State Model）。**

Customer Memory Card **不是**：

- CRM
- 會員資料
- Runtime Memory
- Prompt Context
- JSON Schema
- 永久客戶 Profile

而是：**AI 為維持客服連續性所建立的服務狀態模型。**

### Minimum Required Fields

Customer Memory Card 七個核心欄位：

| # | 欄位 | 中文 |
|---|------|------|
| 1 | Current Requirement | 目前需求 |
| 2 | Conversation Stage | 對話階段 |
| 3 | Completed Items | 已完成事項 |
| 4 | Outstanding Issues | 待辦事項 |
| 5 | Human Handoff Status | 真人接手狀態 |
| 6 | AI Summary | AI 摘要 |
| 7 | Recently Recommended Products | 最近推薦商品 |

> 以上七項為**最低必要核心欄位**，未來可擴充。

### Memory Card Lifecycle

```
Conversation
   ↓
AI Understanding
   ↓
Customer Memory Card
   ↓
Conversation Update
   ↓
Human Service
   ↓
AI Resume
   ↓
Business Intelligence
   ↓
Archive
```

AI 每輪互動只**更新** Customer Memory Card，而不是重新理解全部聊天內容。

### AI Resume

AI Resume 第一步**不是回答**，而是：先閱讀 Customer Memory Card，理解目前服務狀態，再繼續 Conversation。

### Human Collaboration

真人客服亦可閱讀 Customer Memory Card，快速理解目前需求、目前進度、待辦事項，降低重複詢問。

### Business Intelligence

Daily Summary、Weekly Report、Monthly Report、Customer Summary、Dashboard、AI KPI 皆應**優先引用** Customer Memory Card，而不是重新分析完整聊天紀錄。

### Relationship

Customer Memory Card 是下列概念共同的**上層概念**，避免未來重複定義：

- Customer Context
- Conversation State
- Conversation Summary
- Outstanding Issues
- AI Summary

### Terminology

| 名詞 | 中文 | 英文 | 簡稱 |
|------|------|------|------|
| Customer Memory Card | 客戶記憶卡 | Customer Memory Card | Memory Card |

另定義 **Customer Tags（客戶標籤）**：Customer Tags 不是另一套模型，而是 Customer Memory Card 的重要組成。

> 未來若提到「客戶記憶卡」或「客戶標籤」，均代表同一套 **Customer Memory Card**。

### Design Principles

#### Principle 1

Customer Memory Card 描述的是 AI 對目前服務狀態的理解。不是永久會員資料、不是 CRM、不是客戶長期 Profile。

#### Principle 2

Customer Memory Card 應描述**現在需要知道什麼，才能持續服務客戶**，而不是**客戶所有資訊**。

#### Principle 3

Memory Card 應保持 Grounded、精簡、持續更新，避免演變成另一套 CRM。

#### Principle 4

Customer Memory Card 屬於 Customer Conversation Lifecycle。未來 Business Intelligence 可分析多張 Customer Memory Card，但本文件**不定義 Customer Memory Collection**，僅保留未來擴充空間。

---

## 6. Conversation Summary

AI 應能將整段聊天整理成一段 **Conversation Summary**，例如：

- 需求
- 已完成事項
- 待完成事項
- 目前等待
- 下一步

摘要應可供 **AI、真人客服、主管** 閱讀。

---

## 7. Human Collaboration

### Human Takeover

```
AI
 ↓
真人接手
 ↓
AI 停止回覆
 ↓
AI 持續理解
 ↓
AI Resume
```

**AI Resume 前必須重新分析：**

- 真人剛剛說了什麼。
- 客戶剛剛說了什麼。

**不得直接重新回答。**

---

## 8. AI Resume

### Resume Flow

```
Read Conversation
   ↓
Analyze Summary
   ↓
Understand Outstanding Issues
   ↓
Continue Conversation
```

**AI Resume 不是重新開始，而是延續。**

---

## 9. Outstanding Issues

AI 應知道哪些事情尚未完成，例如：

- 等待報價
- 等待日期
- 等待客服
- 等待付款
- 等待確認

---

## 10. Memory Boundaries

定義 AI 記住什麼、不記住什麼。

**AI 可以記住：**

- 旅遊需求
- 目前進度
- 客服狀態

**AI 不得：**

- 自行推測
- 自行補充
- 自行修改
- 記住／散布未確認資訊

---

## 11. Relationship With Other Documents

| 文件 / 主題 | 職責 |
|-------------|------|
| **Grounded Response Composer**（`PHASE_9C2C_GROUNDED_RESPONSE_COMPOSER.md`） | 負責回答內容 |
| **AI Persona**（`BATS_AI_PERSONA.md`） | 負責服務人格 |
| **Conversation Memory**（本文件） | 負責對話延續 |
| **Business Intelligence**（AI Service Matrix ⑪） | 負責管理分析 |

---

## 12. Future Extensions

以下僅列 Roadmap，本文件不詳細定義：

- Daily Summary
- Customer Summary
- Conversation Analytics
- Conversation Timeline
- Knowledge Gap
- Case Replay
- Human Review

---

## 13. Version

| Version | Date       | Status | Notes                                        |
| ------- | ---------- | ------ | -------------------------------------------- |
| v1.1    | 2026-06-25 | Draft  | Elevate Customer Memory Card as Core Concept |
| v1.0    | 2026-06-25 | Draft  | Initial Conversation Memory SSOT             |

---

*本文件為 BATS AI Conversation Memory 唯一 SSOT，定義 AI 跨多輪對話之上下文理解、真人協作與 Resume 行為。不涉及 Runtime Memory、Token Window、Prompt Engineering 或程式實作。*
