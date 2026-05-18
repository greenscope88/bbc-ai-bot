# API Gateway MVP — Dry-Run Test Suite (one-click runner)
# No HTTP, no SQL; runs PHP CLI tests only.

$ErrorActionPreference = 'Stop'

$PhpExe = 'C:\Web\xampp\php\php.exe'
$ScriptDir = $PSScriptRoot

$Tests = @(
    'test_service_registry_mvp.php',
    'test_tour_search_request_builder_mvp.php',
    'test_tour_search_dry_run_proxy_mvp.php',
    'test_tour_search_dry_run_e2e_mvp.php',
    'test_host_b_api_key_config_mvp.php',
    'test_host_b_outbound_header_builder_mvp.php',
    'test_host_b_http_client_mvp.php'
)

foreach ($name in $Tests) {
    $fullPath = Join-Path -Path $ScriptDir -ChildPath $name
    Write-Host "Running: $name"
    if (-not (Test-Path -LiteralPath $fullPath)) {
        Write-Host "FAILED: missing file $fullPath" -ForegroundColor Red
        exit 1
    }
    & $PhpExe $fullPath
    if ($LASTEXITCODE -ne 0) {
        Write-Host "FAILED: $name (exit code $LASTEXITCODE)" -ForegroundColor Red
        exit $LASTEXITCODE
    }
}

Write-Host 'API Gateway MVP Dry-Run Suite passed.'
exit 0
