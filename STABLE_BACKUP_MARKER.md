## BBC AI Stable Backup Marker

This marker records a known stable LINE webhook + Gemini reply state.

- Marked at: 2026-04-27 14:14 (UTC+8)
- Status: `stable`
- Validation note: LINE message "東京今天天氣如何？" received a successful reply.
- Snapshot status: second-layer backup completed.
- Snapshot path: `C:/bbc-ai-bot/backups/stable-20260427/`

### Protected Stable Scope

- `webhook/callback.php`
- `core/safe_gateway.php`
- `core/saas_router.php`
- `core/line_service.php`
- `core/gemini_service.php`
- `config/config.php`
- `bootstrap.php`

### Snapshot Apply Policy

- If a regression is detected, explain the identified issue first.
- After explanation, auto-apply files from `C:/bbc-ai-bot/backups/stable-20260427/` as the first recovery action.
- If only part of the system is affected, restore only impacted files from the snapshot.

### Rollback Reference

If behavior regresses after future edits, first compare changed files against this stable scope and restore only the affected files.
