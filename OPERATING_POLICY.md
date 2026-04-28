# BBC AI SaaS Operating Policy

Last updated: 2026-04-27 (UTC+8)
Status: active

## 1) Correct Project Directories

1. Private core root:
   - `C:/bbc-ai-bot/`
2. Public entry root:
   - `C:/Web/xampp/htdocs/www/bbc-ai-bot/`
3. Deprecated path (excluded from main architecture):
   - `C:/web/xampp/htdocs/bbc-ai-bot/`
   - This path is renamed to `bbc-ai-bot_unused_back` and planned for removal.

## 2) AI Responsibility Routing (Mandatory)

Before executing any task, classify task type and use the matching AI role name:

1. Architecture / system planning / SaaS Router / multi-tenant / security strategy
   - Use: `GPT`
   - Rule: design and evaluate first, avoid large code edits immediately.

2. Coding / refactor / debug / multi-file changes / PHP-ASP-config fixes
   - Use: `Claude`
   - Rule: minimal changes first, preserve existing architecture.

3. LINE reply copy / customer-service wording / open-ended response generation
   - Use: `Gemini`
   - Rule: content generation only; do not handle system logic.

4. File creation / batch updates / project cleanup / automation execution
   - Use: `Cursor Agent (Composer)`
   - Rule: perform file operations and automation tasks.

5. Real-time data queries (weather, flights, external info)
   - Use external APIs first
   - Use Gemini only as assistant summarizer if no API is available
   - Never fabricate or guess external facts.

## 3) Security Rules (Highest Priority)

1. Do not modify any secret values in `.env` (keys, tokens, credentials).
2. Do not modify SQL Server schema unless explicitly authorized by the user.
3. Do not break webhook headers, signature verification, or routing logic.
4. Do not place core business logic in public entry directory.
5. Keep all core logic in `C:/bbc-ai-bot/`.
6. Public entry files only forward requests; no core computation there.
7. If DB changes are needed, present plan and risks before execution.
8. If uncertain, ask first; do not assume.

## 4) Execution Workflow (Mandatory)

At the beginning of every task, output:

- `【任務類型】`: 架構 / coding / debug / 回覆 / 查詢 / 批次執行
- `【使用 AI】`: GPT / Claude / Gemini / Cursor Agent
- `【原因】`: one sentence explanation

Then execute work after this declaration.

## 5) Project Goals

1. Keep a stable LINE + Gemini + SaaS Router architecture.
2. Support multi-tenant isolation by `sno`.
3. Route key processes through API / Service Layer.
4. Keep system maintainable, extensible, and usage-trackable.
5. Support long-term growth from 20+ to 200+ travel agencies.

## 6) Execution Priority Order

1. Stability
2. Security
3. Maintainability
4. Scalability
5. Development speed

## 7) Snapshot Recovery Behavior

- Stable snapshot marker: `C:/bbc-ai-bot/STABLE_BACKUP_MARKER.md`
- Snapshot directory: `C:/bbc-ai-bot/backups/stable-20260427/`
- If regression occurs: explain issue first, then auto-apply snapshot (full or partial based on impact).
