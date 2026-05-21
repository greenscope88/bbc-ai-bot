# SQL Server PDO — `TOP` vs `LIMIT`

Host A uses **Microsoft SQL Server** over PDO (`sqlsrv`). Dynamic SQL in this repo must **not** use MySQL-style `LIMIT n`.

| File | Pattern |
|------|--------|
| `core/tenant_resolver.php` | `SELECT TOP 1 ...` for tenant resolution |
| `core/tour_service.php` | `SELECT TOP 1 is_supported, note ...` in `isServiceSupported()` |

CLI checks: `tests/test_tenant_resolver_sql_server_syntax.php`, `tests/test_tour_service_sql_server_syntax.php`.
