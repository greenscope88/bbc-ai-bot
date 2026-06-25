# BATS AI Business Intelligence

**專案：** BBC AI SaaS / BATS
**定位：** AI Business Intelligence SSOT — 定義 AI 如何將 Customer Memory Card 轉換為管理價值（Management Value）
**版本：** v1.1 Draft
**狀態：** Draft — 待 Review / Adopt
**主機參考：** 103.1.222.14（主機 A · `C:\bbc-ai-bot`）
**Branch：** feature/api-gateway-mvp

> 本文件屬於 **DDD（Document Driven Development）**，目的為建立 Business Intelligence SSOT。
> 本文件**不是**：Runtime、Dashboard 實作、報表程式、SQL、PDF 程式、UI Design。
> 本文件**不討論**：Coding、Dashboard、Database、Implementation。

---

## 文件目錄

| 章節 | 標題 |
|------|------|
| §1 | Document Purpose |
| §2 | Business Intelligence Definition |
| §3 | Data Source |
| §4 | Analysis Scope |
| §5 | Report Types |
| §6 | AI KPI |
| §7 | Customer Insights |
| §8 | Knowledge Gap Analysis |
| §9 | Sales Opportunity |
| §10 | AI Insights |
| §11 | Management Dashboard |
| §12 | Future Extensions |
| §13 | Relationship With Other Documents |
| §14 | Design Principles |
| §15 | Version |

---

## 1. Document Purpose

本文件定義 AI 如何從大量 Customer Memory Card 產生：

- 管理資訊
- 營運洞察
- 改善建議

本文件**不討論**：Coding、Dashboard、Database、Implementation。

---

## 2. Business Intelligence Definition

**AI Business Intelligence 是一個 Analysis Process（分析流程），不是新的資料模型。**

- 不是單純統計。
- 不是新的 Customer State Model。
- 而是 AI 分析所有 Customer Memory Card 後，提供管理決策（支援）。

Business Intelligence 分析所有 Customer Memory Card，而**不是**重新分析完整聊天紀錄。

> **Customer Memory Card 仍是唯一的 Customer Service State Model（客戶服務狀態模型）；本文件不得新增第二套 Customer State Model。**

---

## 3. Data Source

Business Intelligence 主要分析來源為 **Customer Memory Card**，而**不是**重新分析全部聊天紀錄。

若未來需要聊天內容，應以 **Memory Card 為主要入口**。

---

## 4. Analysis Scope

Business Intelligence 的分析範圍為**所有 Customer Memory Card**。可使用下列描述：

- all Customer Memory Cards
- multiple Customer Memory Cards
- aggregated Customer Memory Cards

> 本文件**不建立** Customer Memory Collection 新的 SSOT 或正式模型。上述描述僅表示「多張 Customer Memory Card 的集合」，分析單位仍以 Customer Memory Card 為基礎；Customer Memory Card 為唯一 Customer Service State Model。

---

## 5. Report Types

| 報表 | 用途 |
|------|------|
| Daily Summary | 每日對話、需求、待辦與服務狀況摘要 |
| Weekly Summary | 每週趨勢、累積待辦、服務品質回顧 |
| Monthly Summary | 每月營運總覽、KPI 走勢、改善建議 |
| Customer Summary | 單一客戶需求、進度、待辦與互動歷程摘要 |
| Human Service Summary | 真人接手次數、原因、處理狀況彙整 |
| AI Service Summary | AI 回覆、Resume、推薦與服務成效彙整 |

---

## 6. AI KPI

僅定義 KPI，**不定義計算方式**：

- AI 回覆率
- 真人接手率
- AI Resume 次數
- 平均回覆時間
- 完成案件數
- Outstanding Issues 數量
- AI 建議採納率（保留擴充）

---

## 7. Customer Insights

- 熱門目的地
- 熱門需求
- 熱門旅遊型態
- 常見問題
- 常見待辦事項
- 常見真人接手原因

---

## 8. Knowledge Gap Analysis

### Definition

**Knowledge Gap 是指 AI 經常需要真人接手、或經常無法回答的問題。**

用途：協助改善 **Knowledge Base**。

---

## 9. Sales Opportunity

例如：

- 高成交可能
- 等待報價
- 等待回覆
- 高意願客戶

> AI 僅提供建議，**不自動做商業決策**。

---

## 10. AI Insights

**AI Insights 是 AI Business Intelligence 完成分析後，產出的管理洞察（Management Insights），為 Business Intelligence 的最終輸出。**

### Customer Insights

- 熱門需求
- 熱門目的地
- 熱門旅遊型態

### Sales Insights

- 成交機會
- 等待報價
- 等待付款
- 高意願客戶

### Service Insights

- AI 回覆率
- 真人接手率
- Resume 次數
- Outstanding Issues

### Knowledge Insights

- Knowledge Gap
- 常見真人接手原因
- 知識缺漏

### Trend Insights

- 需求趨勢
- 熱門目的地變化
- 服務品質趨勢
- AI 成效趨勢

---

## 11. Management Dashboard

未來 Dashboard 可包含：

- KPI
- Customer Summary
- Outstanding Issues
- Sales Opportunity
- Human Service
- AI Service
- Trend

> 本文件**不定義 UI**。

---

## 12. Future Extensions

僅列 Roadmap：

- PDF Report
- Excel Export
- Email Report
- LINE Notify
- Executive Summary
- Trend Prediction
- AI Recommendation

---

## 13. Relationship With Other Documents

```
Grounded Response Composer
   ↓
AI Persona
   ↓
Conversation Memory
   ↓
Customer Memory Card
   ↓
AI Business Intelligence
   ↓
AI Insights
```

- **Customer Memory Card** 為唯一 Customer State Model。
- **AI Business Intelligence** 負責分析（Analysis Process）。
- **AI Insights** 負責提供管理價值（最終輸出）。

Business Intelligence 建立於 **Customer Memory Card**，**不得重新定義 Customer State**。

| 文件 / 主題 | 職責 |
|-------------|------|
| **Grounded Response Composer**（`PHASE_9C2C_GROUNDED_RESPONSE_COMPOSER.md`） | 回答內容 |
| **AI Persona**（`BATS_AI_PERSONA.md`） | 服務人格 |
| **Conversation Memory**（`BATS_AI_CONVERSATION_MEMORY.md`） | 對話延續、Customer Memory Card（唯一 Customer State Model） |
| **Business Intelligence**（本文件） | 分析所有 Customer Memory Card，產出 AI Insights |

---

## 14. Design Principles

### Principle 1

Business Intelligence 分析**所有 Customer Memory Card**，不是完整聊天紀錄。

### Principle 2

Business Intelligence 是 **Decision Support**，不是 Decision Making。

### Principle 3

AI Insights 應保持 **Grounded、可追溯、可驗證**；不得推測、杜撰、誇大。

### Principle 4

Customer Memory Card 仍然是**唯一 Customer Service State Model**；Business Intelligence 不得建立新的 Customer State Model。

---

## 15. Version

| Version | Date       | Status | Notes                                                                             |
| ------- | ---------- | ------ | --------------------------------------------------------------------------------- |
| v1.1    | 2026-06-25 | Draft  | Refine BI architecture and introduce AI Insights as the unified management output |
| v1.0    | 2026-06-25 | Draft  | Initial AI Business Intelligence SSOT                                              |

---

*本文件為 BATS AI Business Intelligence 唯一 SSOT，定義 AI 如何分析所有 Customer Memory Card 並產出 AI Insights 管理價值。Customer Memory Card 為唯一 Customer Service State Model；本文件不涉及 Dashboard、Database、報表程式、SQL、UI 或程式實作。*
