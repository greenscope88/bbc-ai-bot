# Tenant Registry Phase 2A — Stage 1

## 1. 目的

建立 **Config-based Multi-Tenant Registry** 薄層，作為未來多 LINE OA / 多旅行社接入的單一租戶設定來源，**不修改**已驗收成功的 BATS runtime（Hybrid、gateway、formatter、Host B 契約）。

Stage 1 交付：

- `config/tenant_registry.php`
- `core/tenant/ResolvedTenant.php`
- `core/tenant/TenantRegistryInterface.php`
- `core/tenant/ConfigTenantRegistry.php`
- `tests/tenant/test_config_tenant_registry.php`

## 2. 為何採 Config Registry

- 第二家旅行社即將接入，3～10 家規模下 **設定檔可讀、可 diff、可 code review**。
- 與現有 `tenant_context_map.php` 模式一致，遷移成本低。
- 不依賴 DB 連線即可單元測試與本機驗證。

## 3. 為何不直接改 Runtime（Stage 1）

已驗收鏈路：

`LINE → safe_gateway → saas_router → Hybrid → TourSearchApiClient → gateway → GeminiTourContextBuilder → TourFallbackFormatter`

Stage 1 **只新增** tenant layer，不接入 `saas_router` / `webhook`，避免：

- 回歸 staging LINE 驗收結果
- 同時改 gate + resolver + config 難以除錯

## 4. ResolvedTenant 設計

- **Immutable DTO**（僅 getter + `isEnabled` / `isFeatureEnabled` / `toArray`）
- 不含業務邏輯（不呼叫 Host B、不組 prompt）
- 欄位涵蓋：identity（tenantKey, channelId, sno）、internal context（depID, storeNo, …）、status、features、profile、policies

## 5. 未來如何委派 TenantResolver

Stage 2 建議：

1. `TenantResolver::resolve()` 內部改為先呼叫 `ConfigTenantRegistry::resolveByChannel($destination)`。
2. 將 `ResolvedTenant` 映射為現有回傳陣列（`sno`, `company_name`, `channel_id`, …）以保持 `saas_router` 契約不變。
3. `TenantContextResolver` 改為 `resolveBySno()` 或讀 DTO 的 depID/storeNo。
4. `TourPromptFeatureGate` / `HybridSearchFeatureGate` 改讀 `ResolvedTenant->getFeatures()` + global master switches。

## 6. Phase 2A Stage 2 規劃

| 項目 | 內容 |
|------|------|
| 接線 | `TenantResolver` + feature gates 委派 registry |
| 相容 | 保留 `tenant_context_map.php` 或由其自動產生過渡 |
| 測試 | 整合測試（mock event destination） |
| 驗收 | travel_a 與現 staging 行為一致；travel_b staging 關閉 features |
| 不變 | TourSearchApiClient、Hybrid 解析、formatter、Host B query |

## 7. 3 → 10 Tenant 擴充策略

- 每家一列 `tenants[travel_x]`，內部 key 穩定。
- `status`: `disabled` → `staging` → `enabled` 分階段開啟。
- `features.*` 預設 OFF，逐項開啟並跑 2-C 類驗收句。
- 超過 ~15 家可拆 `config/tenants/*.php` 合併載入，registry loader 不變。

## 8. 未來 DB-based Registry 路線

- 定義 `TenantRegistryInterface`（Stage 1 已完成）。
- 新增 `DbTenantRegistry` 實作，讀 `tenant_profiles` / `tenant_line_channels`。
- `ConfigTenantRegistry` 作為 fallback 或 cache seed。
- **不改** Hybrid / Host B / formatter 核心，只替換「租戶從哪來」。

---

*Stage 1：未修改 saas_router、webhook、Hybrid runtime、.env、SQL。*
