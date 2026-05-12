$ErrorActionPreference = "Stop"

$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$validator = Join-Path $root "maintenance_window_validator.ps1"

function Get-JsonFromOutput {
    param([object]$Out)
    $text = ($Out | Out-String)
    $start = $text.IndexOf('{')
    $end = $text.LastIndexOf('}')
    if ($start -lt 0 -or $end -lt 0 -or $end -le $start) {
        throw "Could not locate JSON. Output: $text"
    }
    return ($text.Substring($start, $end - $start + 1) | ConvertFrom-Json)
}

function Invoke-MwValidator {
    param(
        [string]$Path,
        [string]$Mode = $null,
        [string]$Environment = $null
    )
    $args = @("-NoProfile", "-ExecutionPolicy", "Bypass", "-File", $validator, "-ContractInputPath", $Path)
    if (-not [string]::IsNullOrWhiteSpace($Mode)) {
        $args += "-Mode"
        $args += $Mode
    }
    if (-not [string]::IsNullOrWhiteSpace($Environment)) {
        $args += "-Environment"
        $args += $Environment
    }
    $out = & powershell.exe @args 2>&1
    return [PSCustomObject]@{
        ExitCode = $LASTEXITCODE
        Out      = $out
        Json     = (Get-JsonFromOutput -Out $out)
    }
}

function New-MwDoc {
    param(
        [string]$Mode = "MOCK",
        [string]$Environment = "DEV",
        [object]$MaintenanceWindow
    )
    return [ordered]@{
        mode               = $Mode
        environment        = $Environment
        maintenanceWindow  = $MaintenanceWindow
    }
}

$tmp = Join-Path ([System.IO.Path]::GetTempPath()) ("mw-val-" + [Guid]::NewGuid().ToString("n"))
New-Item -ItemType Directory -Path $tmp -Force | Out-Null

try {
    # 1) Missing file
    $r = Invoke-MwValidator -Path (Join-Path $tmp "missing.json")
    if ($r.ExitCode -ne 1) { throw "[MISSING_FILE] expected exit 1" }
    if ($r.Json.pass -ne $false) { throw "[MISSING_FILE] pass must be false" }

    # 2) maintenanceWindow missing
    $p = Join-Path $tmp "no_mw.json"
    (@{ mode = "MOCK"; environment = "DEV" } | ConvertTo-Json -Depth 5) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-MwValidator -Path $p
    if ($r.ExitCode -ne 1) { throw "[NO_MW] expected exit 1" }
    if ($r.Json.reasons -notcontains "maintenanceWindow is required") {
        throw "[NO_MW] unexpected reasons: $($r.Json.reasons | ConvertTo-Json -Compress)"
    }

    # 3) approved false
    $doc = New-MwDoc -MaintenanceWindow @{
        approved    = $false
        windowStart = "2026-05-01T00:00:00Z"
        windowEnd   = "2026-05-01T04:00:00Z"
        approvedBy  = "ops"
    }
    $p = Join-Path $tmp "approved_false.json"
    ($doc | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-MwValidator -Path $p
    if ($r.ExitCode -ne 1) { throw "[APPROVED_FALSE] expected exit 1" }

    # 4) windowEnd before windowStart
    $doc = New-MwDoc -MaintenanceWindow @{
        approved    = $true
        windowStart = "2026-05-02T04:00:00Z"
        windowEnd   = "2026-05-02T01:00:00Z"
        approvedBy  = "ops"
    }
    $p = Join-Path $tmp "bad_order.json"
    ($doc | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-MwValidator -Path $p
    if ($r.ExitCode -ne 1) { throw "[BAD_ORDER] expected exit 1" }

    # 5) approvedBy blank
    $doc = New-MwDoc -MaintenanceWindow @{
        approved    = $true
        windowStart = "2026-05-01T00:00:00Z"
        windowEnd   = "2026-05-01T04:00:00Z"
        approvedBy  = "   "
    }
    $p = Join-Path $tmp "blank_by.json"
    ($doc | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-MwValidator -Path $p
    if ($r.ExitCode -ne 1) { throw "[BLANK_BY] expected exit 1" }

    # 6) MOCK format OK
    $doc = New-MwDoc -Mode "MOCK" -Environment "DEV" -MaintenanceWindow @{
        approved    = $true
        windowStart = "2026-05-01T00:00:00Z"
        windowEnd   = "2026-05-01T04:00:00Z"
        approvedBy  = "ops@test"
    }
    $p = Join-Path $tmp "mock_ok.json"
    ($doc | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-MwValidator -Path $p
    if ($r.ExitCode -ne 0) { throw "[MOCK_OK] expected exit 0: $($r.Out)" }
    if ($r.Json.pass -ne $true -or $r.Json.component -ne "maintenance_window_validator") { throw "[MOCK_OK] bad json" }

    # 7) DRY_RUN format OK
    $doc = New-MwDoc -Mode "DRY_RUN" -Environment "STAGING" -MaintenanceWindow @{
        approved    = $true
        windowStart = "2026-06-01T00:00:00Z"
        windowEnd   = "2026-06-01T06:00:00Z"
        approvedBy  = "ops@test"
    }
    $p = Join-Path $tmp "dry_ok.json"
    ($doc | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-MwValidator -Path $p
    if ($r.ExitCode -ne 0) { throw "[DRY_OK] expected exit 0" }

    # 8) PRODUCTION + LIVE_EXECUTE + now outside window
    $doc = New-MwDoc -Mode "LIVE_EXECUTE" -Environment "PRODUCTION" -MaintenanceWindow @{
        approved    = $true
        windowStart = "2020-01-01T00:00:00Z"
        windowEnd   = "2020-01-01T02:00:00Z"
        approvedBy  = "ops"
    }
    $p = Join-Path $tmp "live_outside.json"
    ($doc | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-MwValidator -Path $p
    if ($r.ExitCode -ne 1) { throw "[LIVE_OUTSIDE] expected exit 1" }
    $found = $false
    foreach ($x in @($r.Json.reasons)) {
        if ($x -match "outside maintenanceWindow") { $found = $true }
    }
    if (-not $found) { throw "[LIVE_OUTSIDE] reasons: $($r.Json.reasons | ConvertTo-Json -Compress)" }

    # 9) PRODUCTION + LIVE_EXECUTE + now inside window
    $mid = [DateTimeOffset]::UtcNow
    $ws = $mid.AddMinutes(-30).ToString("o")
    $we = $mid.AddMinutes(60).ToString("o")
    $doc = New-MwDoc -Mode "LIVE_EXECUTE" -Environment "PRODUCTION" -MaintenanceWindow @{
        approved    = $true
        windowStart = $ws
        windowEnd   = $we
        approvedBy  = "ops"
    }
    $p = Join-Path $tmp "live_inside.json"
    ($doc | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-MwValidator -Path $p
    if ($r.ExitCode -ne 0) { throw "[LIVE_INSIDE] expected exit 0: $($r.Out)" }
    if ($r.Json.pass -ne $true) { throw "[LIVE_INSIDE] pass must be true" }

    Write-Output "PASS: test_maintenance_window_validator.ps1"
}
finally {
    Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue
}
