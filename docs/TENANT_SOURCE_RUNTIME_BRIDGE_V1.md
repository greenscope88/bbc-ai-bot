# Tenant Source Registry Runtime Bridge v1（Design）

**代號：** `TENANT_SOURCE_RUNTIME_BRIDGE_V1`

**狀態：** Phase 1B — Design Review / 文件先行（不實作、不修改 runtime）

---

## 1. 文件定位

本文件是 **Tenant Source Registry Runtime Bridge v1** 的最小橋接設計文件。

目標是：在不重做既有 **Tenant Source Registry**（讀取層）與不重寫 **BATS core**（Production runtime 主流程）的前提下，讓 Runtime 能從 Tenant Source Registry 取得「多 tenant 商品源啟用清單」所對應的 `source_instance_keys`。

本階段只做文件設計，**不進行任何 PHP / config / tests / runtime 修改**。

---

## 2. 現況 Production Runtime

目前 Production Runtime（以既有實測路徑對齊）主要流程如下：

```text
LINE OA / Gemini
↓
TourPromptContextService
↓
TravelBMultiSourceLinkBuilder
↓
travel_b_multi_source_links.php
↓
travel_b_search_registry.php
↓
MultiSourceSearchUrlBuilder
↓
GeminiTourContextBuilder
```

並註明：

- 目前 **ProductSourceRegistry 尚未接入 Production runtime**
- `source_instance_keys` 目前完全由 `travel_b_multi_source_links.php` 靜態配置決定
- 目前 runtime 所用 registry 檔由 `TravelBMultiSourceLinkBuilder` 以固定路徑載入

---

## 3. 既有 Registry 能力

以下元件已存在且可用於「Tenant product sources 讀取層」：

- `ProductSourceRegistry`
- `TenantProductSourceLoader`
- `LocalTenantProductSourcesProvider`
- `GcsTenantProductSourcesProvider`（skeleton：不含真實下載）
- `TenantProductSourcesProviderFactory`

能力描述：

1. 這些元件能處理 **tenant product sources 讀取**，並輸出 enabled 的 `source_id` 清單（例如 `bbctravel` / `grp` / `tourcenter`）。
2. 但這些 enabled `source_id` 無法直接轉換成 Runtime 目前需要的 `source_instance_keys`。
3. 因此，缺口在於「Runtime 需要的 instance key 產生」仍依賴 existing config（travel_b 專用），尚未由 Registry 驅動。

---

## 4. 核心缺口

### 4.1 Tenant Product Sources 使用 `source_id`

Tenant product sources 的啟用清單目前使用：

- `source_id`：例如 `bbctravel`、`grp`、`tourcenter`

### 4.2 Runtime 使用 `tenant_instance_key`

Production runtime 目前使用：

- `tenant_instance_key`（例：`dayitravel_bbctravel`、`dayitravel_grp`、`dayitravel_tourcenter`）

### 4.3 需要橋接

因此需要在 v1 內建立橋接規則：

```text
tenant_sno
+ source_id
↓
tenant_instance_key
```

並在不破壞既有 travel_b 行為的前提下，輸出：

- Runtime 所需：`source_instance_keys`
- 進而被 `MultiSourceSearchUrlBuilder` 消費以產生 multi-source search urls

---

## 5. TenantSourceRuntimeBridge v1 設計

### 5.1 設計類別

設計新增（或等價替代）類別：

**`TenantSourceRuntimeBridge`**

### 5.2 職責鏈（最小）

```text
tenant_sno
↓
TenantProductSourceLoader
↓
ProductSourceRegistry
↓
enabled source_id list
↓
search registry instances 過濾
↓
source_instance_keys
↓
MultiSourceSearchUrlBuilder
```

### 5.3 解析重點

1. 以 `tenant_sno` 取得該 tenant 啟用的 enabled `source_id` 清單
2. 在既有 `travel_b_search_registry.php`（Phase 1B 過渡期）或未來通用 registry 的 `instances` 中，篩選出符合條件的 instance rows
3. instance rows 過濾條件需至少包含：
   - `tenant_sno` 相符
   - instance 的 `platform_id` 落在 enabled `source_id` 集合內（針對過渡期命名差異需明確 mapping）
   - instance 的 enabled 狀態為 true
4. 輸出排序需確保與既有 runtime 行為一致（避免破壞順序或內容）

---

## 6. 建議介面

介面草案（PHP method / return format）：

```php
resolveMultiSourceConfig(string $tenantSno): array
```

輸出格式（示例）：

```php
[
  'enabled' => true,
  'tenant_sno' => '...',
  'source_instance_keys' => ['dayitravel_grp', 'dayitravel_bbctravel', ...],
  'resolver_id' => 'tenant_source_runtime_bridge_v1'
]
```

其中 `enabled=false` 代表「不生成 multi-source links」，以避免錯誤設定造成錯誤商品源連結。

---

## 7. Fallback 策略

v1 過渡期必須保留：

- `travel_b_multi_source_links.php` 作為 fallback（travel_b 的 bit-identical 回歸保障）

Fallback 優先序：

1. Bridge 成功且 `source_instance_keys` 非空
   - → 使用 Bridge 結果
2. `tenant_sno` 是 travel_b
   - → fallback 到 `travel_b_multi_source_links.php`
3. 其它 tenant 無設定
   - → `enabled=false`

必須避免：

- bridge 產出空 keys 或失敗時，對非 travel_b tenant 仍強行使用某個預設多源清單
- 錯誤設定導致產生錯誤商品源連結（因此非 travel_b 預設 `enabled=false`）

---

## 8. travel_b 保護規則

明確規範：

1. travel_b 已完成三商品源 Production（既有實測與回歸基線）
2. Bridge v1 不得破壞 travel_b 的 `source_instance_keys` 順序或內容
3. travel_b 必須可回到 legacy config（fallback 到 `travel_b_multi_source_links.php`）
4. 必須建立 regression test

因此 Phase 1C 的橋接實作需包含 travel_b 回歸測試，確保「bit-identical 行為」。

---

## 9. travel_c / travel_d Pilot 設計

用以下範例驗證設計是否可行（僅描述設計，不實作）：

```text
travel_b
├─ bbctravel
├─ grp
└─ tourcenter

travel_c
└─ bbctravel

travel_d
├─ grp
└─ tourcenter
```

### 9.1 每 tenant 只需不同 enabled source list

- enabled sources：由 tenant product sources（`enabled_sources`）決定
- platform templates：共用平台級 template
- source instances：tenant-specific（由 search registry instances 中的子網域 / identifier_values 決定）

### 9.2 需要的 config/sample（驗證用）

1. travel_c 需要：
   - Tenant registry：啟用 `travel_c`（目前可能為 disabled 占位，需在後續 Phase 補齊）
   - travel_c product sources sample：enabled source_id 僅包含 `bbctravel`
   - 與現有平台 templates / adapter 共用（不做複製）
   - instances：需在 search registry instances 具備對應 `tenant_sno` 與 `platform_id=bbctravel` 的 rows
2. travel_d 需要：
   - travel_d product sources sample：enabled source_id 僅包含 `grp` 與 `tourcenter`
   - 與現有平台 templates 共用
   - instances：在 search registry instances 中具備對應 `tenant_sno` 與 platform rows

### 9.3 是否需要新增 search_registry？

v1 建議：

- Phase 1B/1C 過渡期仍使用既有（travel_b 專用）search registry
- Phase 1D 若要接 travel_c / travel_d pilot，需確認是否可共用 templates 並用 instances rows 過濾滿足需求

若共用不可行（instances 不足或命名不一致），才考慮在後續 Phase 才增加通用 multi-tenant search registry（避免重工）。

---

## 10. 不做事項

本文件與 v1 bridge 設計的「不做」清單：

- 不重寫 `ProductSourceRegistry`
- 不重寫 `TenantProductSourceLoader`
- 不重寫 `MultiSourceSearchUrlBuilder`
- 不重寫 `GeminiTourContextBuilder`
- 不接 `ChannelPublishPlan`（另一條 renderer / publisher 管線）
- 不刪除 `travel_b_multi_source_links.php`（過渡期 fallback）
- 不為每個 tenant 複製整份 platform templates

---

## 11. Phase Plan

1. **Phase 1B（本文件）**
   - 建立 bridge v1 設計與 fallback 契約
   - 定義 source_id → tenant_instance_key mapping 規則（過渡期與 travel_b bit-identical）
2. **Phase 1C**
   - TenantSourceRuntimeBridge 最小實作
   - 加入 travel_b regression test（確保 keys 順序與內容不變）
3. **Phase 1D**
   - travel_c pilot（以 bbctravel 為單源）
4. **Phase 1E**
   - GCS Tenant Product Sources Provider 實作（真實下載 + cache；不在本 Phase 做）

---

## 12. 風險分類

### P1

1. 破壞 travel_b 的 `source_instance_keys` 順序或內容
2. `bbcshops` / `bbctravel` source_id 混淆（過渡期名詞語意差異導致錯誤 instance 篩選）

### P2

1. duplicated registry（同一責任多處定義 enabled sources / instances）
2. 過度重構（牽動多個核心檔案或改名導致回歸成本飆升）

### P3

1. `TravelBMultiSourceLinkBuilder` 命名未泛化（命名僅影響可讀性，不直接影響功能；後續可重構）

---

## 13. CWP / DDD 合規

本階段合規要求：

- 本階段為文件先行（Document-Driven Development）
- 不實作、不修改 runtime
- 不修改任何檔案（本文件僅描述設計）
- 符合：
  - **DDD / SSOT First**
  - **Development Rhythm**

---

## 14. 下一步

建議進入 **Phase 1C（TenantSourceRuntimeBridge 最小實作）**。

但需要另行確認後才開始實作（例如 bridge mapping 規則、test scope、以及 travel_b regression expectation）。

