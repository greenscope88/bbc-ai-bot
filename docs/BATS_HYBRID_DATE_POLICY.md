# BATS Hybrid 日期解析正式規範

**狀態：** 正式規範（SSOT）  
**適用：** BATS（BBC AI 旅業智慧搜尋架構）  
**主機參考：** 103.1.222.14（主機 A）

---

## 文件前言（重要）

本文件為 **BATS（BBC AI 旅業智慧搜尋架構）日期解析正式規範**。

**所有日期相關邏輯修改，必須優先更新本文件。**

禁止在未同步更新本文件的情況下，直接修改下列元件：

- `DateParser`
- `HybridSearchConditionBuilder`
- `SearchCondition`
- Product Source URL Builder
- bbctravel URL Builder
- grp URL Builder
- tourcenter URL Builder
- bbcshops URL Builder

本文件為：

**Single Source of Truth (SSOT)**

---

## 章節一：文件目的

定義：**所有商品源共用**同一套日期規則，不得自行解析日期。

適用商品源（含未來擴充）：

- bbcshops
- bbctravel
- grp
- tourcenter
- 未來商品源

必須共用下列資料流：

```text
Hybrid Search
  → SearchCondition
  → Product Source URL Builder
```

同一套日期規則、同一組欄位語意。

---

## 章節二：三大搜尋條件

### 1. keyword

例如：

- 東京
- 大阪
- 北海道

### 2. departure_city

例如：

- 台北
- 高雄
- 台南

### 3. date_range

- `date_from`
- `date_to`

---

## 章節三：日期解析正式規則

以下為 Hybrid Search 對自然語言的**正式解析結果**（範例以參考年為當年；實作須依 `reference_date` 與 Y0/Y1 年份規則）。

| 客戶輸入 | `date_from` | `date_to` | 備註 |
|----------|-------------|-----------|------|
| **6/10東京** | `2026-06-10` | `2026-06-10` | 單日 |
| **7月東京** | `2026-07-01` | `2026-07-31` | 整月 |
| **6月底東京** | `2026-06-20` | `2026-06-30` | 月底區間 |
| **6/10前後東京** | `2026-06-03` | `2026-06-17` | ±7 天 |
| **近期東京** | `today` | `today+60天` | **正式採用 today+60** |
| **東京** | `null` | `null` | **AI 必須追問** |
| **東京五日** | `null` | `null` | **AI 必須追問**（「五日」為天數語意，非出團日） |

### 規則明細

#### 6/10東京

```text
date_from = 2026-06-10
date_to   = 2026-06-10
```

#### 7月東京

```text
date_from = 2026-07-01
date_to   = 2026-07-31
```

#### 6月底東京

```text
date_from = 2026-06-20
date_to   = 2026-06-30
```

#### 6/10前後東京

```text
±7 天

date_from = 2026-06-03
date_to   = 2026-06-17
```

#### 近期東京

```text
date_from = today
date_to   = today + 60 天
```

**正式採用：today+60**

#### 東京

```text
date_from = null
date_to   = null
```

**AI 必須追問。**

#### 東京五日

```text
date_from = null
date_to   = null
```

**AI 必須追問。**

---

## 章節四：日期必要原則

所有商品源：

- bbcshops
- bbctravel
- grp
- tourcenter

在**產生搜尋網址前**，必須取得：

- `date_from`
- `date_to`

### 若客戶未提供日期

**AI 必須追問。**

範例追問：

> 請問您大約想什麼時候出發呢？

可接受客戶回答範例：

- 六月底
- 暑假
- 近期
- 七月
- 八月

客戶必須提供：

- **明確日期**，或
- **模糊日期**

但最終都必須轉換成：

```text
date_from
date_to
```

**禁止**各商品源在未經 Hybrid 解析／追問補齊前，自行硬寫預設出團日於搜尋 URL。

### Date Semantic Required Rule

目的：

- BATS 不是旅遊行程瀏覽器。
- BATS 是旅遊需求引導系統。

規則核心：

若查詢僅包含「目的地、旅遊關鍵字、商品類型」但未包含任何日期資訊或日期語意，
不得直接進入搜尋流程。
必須先進入 **Date Clarification**（日期澄清）。

正式規則：

若查詢僅包含以下資訊：

- 目的地
- 旅遊關鍵字
- 商品類型

但未包含：

- 日期
- 日期區間
- 日期語意

則：

1. `date_from = null`
2. `date_to = null`
3. 不得產生 Search URL
4. 不得呼叫商品源搜尋
5. 必須進入 Date Clarification

以下案例 → 結果：Date Clarification（不進入搜尋）

- 東京
- 東京行程
- 東京旅遊
- 大阪
- 北海道

以下案例 → 結果：允許解析 `date_from/date_to`（允許進入搜尋）

- 近期東京
- 最近東京
- 本月東京
- 東京6月底
- 東京暑假
- 東京明年
- 東京2026/07/15

日期語意對照表（正式規格）：

| 語意 | date_from | date_to |
| -- | --------- | -------- |
| 近期 | Today | Today + 60 Days |
| 最近 | Today | Today + 60 Days |
| 本月 | 當月1日 | 當月月底 |
| 下月 | 下月1日 | 下月月底 |
| 月初 | 當月1日 | 當月10日 |
| 月中 | 當月11日 | 當月20日 |
| 月底 | 當月21日 | 當月月底 |
| 暑假 | 當年度7/1 | 當年度8/31 |
| 寒假 | 當年度1/15 | 當年度2/15 |
| 明年 | 明年1/1 | 明年12/31 |

---

## 章節五：出發地規則

**出發地不是必要條件。**

### `departure_city = null` 語意（Hybrid Layer）

若使用者**未指定出發地**，Hybrid Layer 必須寫入：

```text
departure_city = null
```

語意為：

```text
不限出發地
```

**不是**「預設台北」或任何平台 path 代碼。

Hybrid Layer **不得**將 `null` 自行轉換為 `tpetsa`、`all`、`khh` 等平台代碼；僅保存中文語意欄位（或 `null`）。

### 出發地解析格式（Hybrid Layer）

#### 格式 A：`{城市}出發` 後綴

| 客戶輸入 | `departure_city` | 說明 |
|----------|------------------|------|
| **高雄出發東京** | `高雄` | 明確出發地 |
| **高雄出發東京6月底** | `高雄` | 出發地 + 日期語意並存 |
| **高雄或台北都可以** | `高雄` | **採第一個出發地**，避免產生多組搜尋網址 |

#### 格式 B：裸出發地前綴（正式支援）

當訊息以**已知台灣出發城市**開頭，且後接已解析之目的地／關鍵字（如東京）時，寫入對應 `departure_city`：

| 客戶輸入 | `departure_city` |
|----------|------------------|
| **高雄東京6月底** | `高雄` |
| **台南東京6月底** | `台南` |
| **台中東京6月底** | `台中` |
| **台北東京6月底** | `台北` |
| **桃園東京6月底** | `桃園` |
| **松山東京6月底** | `松山` |

裸前綴規則屬 **Hybrid Layer 語意解析**；path code（如 `khh`、`RMG`）由 Platform Mapping Layer 處理（見章節六）。

#### 未指定出發地

| 客戶輸入 | `departure_city` | 說明 |
|----------|------------------|------|
| **東京** | `null` | 僅目的地，不限出發地 |
| **東京6月底** | `null` | 有日期語意，仍不限出發地 |

### 若客戶未提供出發地

**不得追問。**

Hybrid Layer 保持 `departure_city = null`。各商品源平台依 **Platform Mapping Layer** 規則自行映射（見章節六、`TENANT_SOURCE_REGISTRY_POLICY.md` §Platform Mapping）；**不得**在 Hybrid 層硬編平台 path 或預設城市。

---

## 章節六：出發地代碼 Mapping（Platform Mapping Layer）

本章定義 **bbctravel.com.tw** 之出發地 path 映射；屬 **Platform Mapping Layer**，**不是** Hybrid Rule。

### BBCTravel 正式 Mapping（中文 → path code）

| `departure_city`（中文） | path code |
|--------------------------|-----------|
| 台北 | `tpetsa` |
| 桃園 | `tpe` |
| 松山 | `tsa` |
| 台中 | `RMG` |
| 高雄 | `khh` |
| 台南 | `tnn` |

### BBCTravel 不限出發地（`departure_city = null`）

當 Hybrid 產出 `departure_city = null`（使用者未指定出發地）時，bbctravel 平台映射為：

```text
/searchlist/all/
```

範例：

```text
https://dayitravel.bbctravel.com.tw/searchlist/all/?q=東京&datefrom=2026-06-21&dateto=2026-06-30&order=1&standby=1
```

**`/all/` 僅屬 bbctravel Platform Rule**，不得視為 Hybrid Rule，亦不得套用到 grp、tourcenter 或未來其它商品源。

日期參數 `datefrom` / `dateto` 仍須來自 Hybrid 解析之 `date_from` / `date_to`（本文件日期規則）；平台層不得硬寫固定出團日。

### 分層摘要

| 層級 | 職責 |
|------|------|
| **Hybrid Layer** | 解析並寫入 `departure_city`（中文或 `null`） |
| **Platform Mapping Layer** | `departure_city` → 平台 path code（如 `khh`、`all`） |
| **URL Builder Layer** | 組裝 `/searchlist/{code}/` 與 query 參數 |

Hybrid Layer 僅產出 `departure_city`（中文），不產出 `tpetsa` / `khh` / `RMG` / `all` 等平台代碼。

---

## 章節七：Layer 分工

### Hybrid Layer

負責解析並寫入 `SearchCondition`：

- `keyword`
- `departure_city`
- `date_from`
- `date_to`

元件參考（實作位置，非本文件定義實作細節）：

- `HybridSearchConditionBuilder`
- `DateParser`
- `AreaParser`
- `SearchCondition`

### Platform Mapping Layer

負責出發地等平台專屬代碼（依平台而異；bbctravel 範例）：

- `tpetsa`、`tpe`、`tsa`、`RMG`、`khh`、`tnn`
- `all`（**僅 bbctravel**：對應 Hybrid `departure_city = null`）

不得在此層重新解析自然語言日期。不得將 bbctravel 的 `all` 規則套用到 grp、tourcenter 或其它平台。

### URL Builder Layer

負責各平台搜尋網址組裝：

- bbctravel
- grp
- tourcenter
- bbcshops

僅消費 Hybrid 產出的 `keyword`、`departure_city`、`date_from`、`date_to`（及 Mapping 產出的平台代碼），**不得**自行定義日期解析規則。

---

## 章節八：多商品源共用原則

下列租戶／旅行社 Pilot **共用同一套日期規則**：

- travel_a
- travel_b
- travel_c
- 未來旅行社

**禁止：**

- travel_b 一套日期規則、travel_c 另一套
- grp 一套、bbctravel 一套、各自解析「近期」「六月底」

所有差異僅允許出現在 **Platform Mapping** 與 **URL 參數命名**（例如 `datefrom` / `dateto`），不允許出現在 **日期語意解析**。

---

## 章節九：未來擴充

未來可新增節日／區間語意，例如：

- 暑假
- 寒假
- 春節
- 連假
- 中秋
- 國慶

**但必須先修改本文件**，定義：

- 觸發詞
- `date_from` / `date_to` 對照表
- 與「AI 追問」的邊界

未列入本文件者，**不得**在 `DateParser` 或各 URL Builder 私下實作。

---

## 章節十：結論

**Hybrid Search 為唯一日期解析來源。**

所有商品源：

- bbcshops
- bbctravel
- grp
- tourcenter

只接收：

- `keyword`
- `departure_city`
- `date_from`
- `date_to`

**不得自行定義日期規則。**

任何程式變更與本文件衝突時，**以本文件為準**；實作須先修訂本 SSOT，再修改程式。

---

## 修訂紀錄

| 日期 | 說明 |
|------|------|
| 2026-06-05 | 初版建立：BATS Hybrid 日期解析正式規範（SSOT） |
| 2026-06-05 | Add Date Semantic Required Rule（日期語意必要）與日期語意對照表 |
| 2026-06-06 | Phase C-1A：裸出發地前綴、`departure_city = null` 語意、BBCTravel `null → all` 與台中→RMG（Platform Layer） |
