# BATS Gemini Client (Phase 9-B-26B-2)

## 1. GeminiClient

**GeminiClient** 是 `GeminiContextDocument` 與 `GeminiResponseContract` 之間的 **Adapter**。

```
GeminiContextDocument
        ↓
GeminiClient.generateResponse()
        ↓
GeminiResponseContract
        ↓
(future) LineRenderer / orchestrator reply path
```

| 方法 | 說明 |
|------|------|
| `generateResponse()` | Skeleton 入口；目前委派 `generateMockResponse()` |
| `generateMockResponse()` | 無 API mock 回覆，經 validator 驗證 |

9-B-26B-2 **不**呼叫 Gemini API、**不**使用 HTTP/curl。

---

## 2. GeminiContextDocument

輸入 contract（Phase 9-B-23）：

- `customer_query`
- `search_results`
- `tenant_name`
- `voice_profile`
- `tenant_service_scope`
- `guard_policy`
- `fallback_policy`

由 `GeminiRenderer` 從 `ChannelPublishPlan` 產生。

---

## 3. GeminiResponseContract

輸出 contract（Phase 9-B-24）：

- `reply_text`
- `reply_type` — `normal_reply` / `human_agent_fallback` / `out_of_scope`
- `used_fallback`
- `used_service_scope`
- `voice_profile_used`

由 `GeminiResponseContractValidator.validateWithContext()` 驗證。

---

## 4. 未來 API Integration

| 9-B-26B-2（本 Phase） | 9-B-26C+（未來） |
|-----------------------|------------------|
| `generateMockResponse()` only | `generateResponse()` 呼叫 API |
| 無 prompt 字串外洩 | 在 client 內組裝 request |
| wrap `callGeminiUrl` | 不 rewrite `gemini_service.php` core |

未來流程：

```
GeminiContextDocument
  → buildApiRequest() (private, future)
  → callGeminiUrl() (existing stable module)
  → parseApiResponse() (future)
  → GeminiResponseContract
```

---

## 5. 未來 Tenant Voice Profile

| 來源 | 注入 |
|------|------|
| `GeminiContextDocument.voice_profile` | system instruction / tone |
| tenant override config | persona / emoji_policy |
| `voice_profile_used` in response | audit trail |

Mock skeleton 已從 context 讀取 `persona` 並寫入 response。

---

## 6. 未來 Service Scope

Mock 邏輯依 `customer_query` + `tenant_service_scope` 決定：

| 情境 | reply_type |
|------|------------|
| 在 scope 內 + 有 search_results | `normal_reply` |
| 在 scope 內 + 無資料 | `human_agent_fallback` |
| 不在 scope 內 | `out_of_scope` |

---

## 7. Phase 9-B-26B-3 — LineTransport

```
GeminiResponseContract.reply_text
        ↓
(future) orchestrator formats LINE payload
        ↓
LineRenderer → LineMessagePayload
        ↓
LineSender → LineTransport → LINE Reply API
```

GeminiClient 本 Phase 不連接 LineTransport。

---

## 8. 檔案清單

| 檔案 | 說明 |
|------|------|
| `core/product_source/integration/GeminiClient.php` | Adapter skeleton |
| `tests/product_sources/test_gemini_client.php` | 測試 |

## 測試

```powershell
C:\Web\xampp\php\php.exe C:\bbc-ai-bot\tests\product_sources\test_gemini_client.php
```

---

## 相關文件

- `docs/BATS_GEMINI_RENDERER_CONTRACT.md` — Context 層
- `docs/BATS_GEMINI_RESPONSE_CONTRACT.md` — Response 層
- `docs/BATS_WEBHOOK_ORCHESTRATOR.md` — Orchestrator 層
