# Tenant travel_b — 第二家旅行社 Onboarding Checklist

Phase 2A Stage 4：僅設定與 staging 檢查，**不啟用** tour_prompt / hybrid_search / fixed_formatter。

---

## 1. LINE OA

| 項目 | 必填 | 說明 | 目前狀態 |
|------|------|------|----------|
| `destination` / `line_channel_id` | 是 | Webhook `destination`，對應 `config/tenant_registry.php` → `travel_b.line_channel_id` | `U_TODO_ONBOARDING_TRAVEL_B_CHANNEL`（待替換） |
| Channel secret | 是 | LINE Developers → Basic settings；**不寫入 repo**，僅 Host A 部署設定 | 待提供 |
| Channel access token | 是 | Messaging API；**不寫入 repo** | 待提供 |
| Webhook URL | 是 | 指向 Host A `safe_gateway` / webhook 端點 | 待確認 |

**注意：** Channel secret / token 僅能放在伺服器設定（`.env` 或 secret store），本輪 **不修改 `.env`**。

---

## 2. Host B（內部對應 + wire sno）

| 項目 | 必填 | 說明 | 目前狀態 |
|------|------|------|----------|
| `sno` | 是 | Host B tour search **wire authority**（API 只帶 sno） | `00000000-0000-4000-8000-0000000000b1`（staging 占位，待 Host B 核發） |
| `depID` | staging 必須 | `TenantContextResolver` / detail URL 內部用 | `0`（`onboarding_required`） |
| `storeNo` | staging 必須 | 同上 | `0`（`onboarding_required`） |
| `store_uid` | staging 必須 | 同上 | `0`（`onboarding_required`） |
| `provider_id_no` | staging 必須 | BuySmart / Host B 確認值；**禁止 mock `1`** | `0`（`onboarding_required`） |

**可後補：** 若先完成 LINE OA 綁定，Host B 欄位可後續填入；在 features 全 OFF 時不會走 Hybrid / Host B live 查詢。

---

## 3. AI / Gemini（profile）

| 項目 | 必填 | 說明 | 目前狀態 |
|------|------|------|----------|
| `company_name` | 建議 | `TenantResolver` → prompt 語氣 | `旅行社 B 客服（staging）` |
| `ai_tone` | 建議 | AiPromptBuilder | `親切` |
| `travel_specialties` | 建議 | AiPromptBuilder | `綜合旅遊` |
| `price_catalog_json` | 可後補 | 預設 `{}` | `{}` |

---

## 4. Staging 驗收（本輪自動化測試）

執行：

```text
php tests/tenant/test_travel_b_staging_readiness.php
php tests/tenant/test_config_tenant_registry.php
php tests/tenant/test_feature_gate_registry_bridge.php
```

| 檢查項 | 預期 |
|--------|------|
| Registry `resolveByChannel` / `resolveBySno` | travel_b 可解析 |
| `status` | `staging` |
| `features.tour_prompt` | `false` |
| `features.hybrid_search` | `false` |
| `features.fixed_formatter` | `false` |
| `TourPromptFeatureGate` | OFF |
| `HybridSearchFeatureGate` | OFF |
| `TenantContextResolver`（registry 路徑） | shape 含 `sno, depID, storeNo, store_uid, provider_id_no` |
| travel_a | **完全不變** |

**不做：** Host B live HTTP、正式 LINE webhook、Gemini live。

---

## 5. 正式啟用順序（人工，逐項驗收）

1. **tour_prompt** — `features.tour_prompt = true`，跑 staging LINE 句型驗收（非本輪）
2. **fixed_formatter** — `features.fixed_formatter = true` + `gemini_policy.allow_fixed_formatter`
3. **hybrid_search** — `features.hybrid_search = true` + `config.php` allowlist 對齊 + 2-C 類查詢驗收

每步前確認：`status` 由 `staging` → `enabled`（僅在營運核准後）。

---

## 6. 欄位分類摘要

### 必填（上線前）

- `line_channel_id`（真實 LINE destination）
- `sno`（Host B 核發）
- LINE channel secret / access token（伺服器設定，非 repo）

### Staging 必須（resolver / context 形狀）

- `depID`, `storeNo`, `store_uid`, `provider_id_no`（非 0 且 `provider_id_no ≠ 1` 方可走完整 Host B context）
- `status = staging` 期間 features 維持 OFF

### 可後補

- `price_catalog_json`
- `gemini_policy` 微調
- `tenant_context_map.php` legacy 列（可選；registry 已為主）

---

*文件版本：Phase 2A Stage 4 — 2026-05-27*
