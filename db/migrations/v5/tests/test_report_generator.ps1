$ErrorActionPreference = "Stop"

$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$gen = Join-Path $root "report_generator.ps1"

function Invoke-Generator([string]$InputJsonPath, [string]$OutputDir) {
    $out = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $gen `
        -InputJsonPath $InputJsonPath `
        -OutputDir $OutputDir 2>&1 | Out-String
    $code = $LASTEXITCODE

    $text = $out.Trim()
    $start = $text.IndexOf('{')
    $end = $text.LastIndexOf('}')
    if ($start -lt 0 -or $end -lt 0 -or $end -le $start) {
        throw "Could not locate JSON braces. Output: $out"
    }
    $jsonText = $text.Substring($start, $end - $start + 1)
    try { $obj = $jsonText | ConvertFrom-Json } catch { throw "Invalid JSON output. Output: $out" }

    return [PSCustomObject]@{ ExitCode = $code; Object = $obj; Raw = $out }
}

function Assert-True([bool]$cond, [string]$msg) { if (-not $cond) { throw $msg } }

$tmpRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("report-generator-" + [Guid]::NewGuid().ToString("n"))
New-Item -ItemType Directory -Path $tmpRoot -Force | Out-Null

try {
    Write-Output "INFO: tempDir=$tmpRoot"
    $inputOk = Join-Path $tmpRoot "input_ok.json"
    $outDir1 = Join-Path $tmpRoot "out1"
    $outDir2 = Join-Path $tmpRoot "out2_created"

    $payload = @{
        migrationId       = "MIG-TEST-001"
        proposalId        = "DBCR-TEST-001"
        environment       = "TEST"
        operator          = "unit-test"
        approval          = @{ approved = $true; by = "human"; timestamp = "2026-05-11T00:00:00Z" }
        riskSummary       = @{ calculatedRisk = "Low"; underestimation = $false }
        recoveryReadiness = @{ ready = $true; recoveryMode = "A" }
        schemaDiffSummary = @{ finalStatus = "PASS"; totalChanges = 1 }
        executionResult   = @{ executed = $false; note = "dry-run only" }
    } | ConvertTo-Json -Depth 10

    Set-Content -LiteralPath $inputOk -Value $payload -Encoding UTF8

    # Case 1: normal generation + OutputDir auto-create
    Write-Output "INFO: Case1 OK generate"
    $r = Invoke-Generator -InputJsonPath $inputOk -OutputDir $outDir1
    Assert-True ($r.ExitCode -eq 0) "[OK] expected exit 0"
    Assert-True ($r.Object.success -eq $true) "[OK] expected success=true"
    Assert-True ($r.Object.reportGenerated -eq $true) "[OK] expected reportGenerated=true"
    Assert-True (Test-Path -LiteralPath (Join-Path $outDir1 "execution_report.json")) "[OK] execution_report.json missing"
    Assert-True (Test-Path -LiteralPath (Join-Path $outDir1 "execution_report.md")) "[OK] execution_report.md missing"
    Assert-True (Test-Path -LiteralPath (Join-Path $outDir1 "risk_summary.md")) "[OK] risk_summary.md missing"
    Assert-True (Test-Path -LiteralPath (Join-Path $outDir1 "schema_diff_summary.md")) "[OK] schema_diff_summary.md missing"
    Assert-True (Test-Path -LiteralPath (Join-Path $outDir1 "recovery_readiness_summary.md")) "[OK] recovery_readiness_summary.md missing"

    # Case 2: InputJsonPath missing
    Write-Output "INFO: Case2 missing input"
    $missingInput = Join-Path $tmpRoot "missing.json"
    $r = Invoke-Generator -InputJsonPath $missingInput -OutputDir $outDir1
    Assert-True ($r.ExitCode -eq 1) "[MISSING_INPUT] expected exit 1"
    Assert-True ($r.Object.success -eq $false) "[MISSING_INPUT] expected success=false"
    Assert-True ($r.Object.reportGenerated -eq $false) "[MISSING_INPUT] expected reportGenerated=false"

    # Case 3: OutputDir does not exist but can be created
    Write-Output "INFO: Case3 outdir create"
    Assert-True (-not (Test-Path -LiteralPath $outDir2)) "[OUTDIR_CREATE] outDir2 should not exist before"
    $r = Invoke-Generator -InputJsonPath $inputOk -OutputDir $outDir2
    Assert-True ($r.ExitCode -eq 0) "[OUTDIR_CREATE] expected exit 0"
    Assert-True (Test-Path -LiteralPath $outDir2) "[OUTDIR_CREATE] expected outDir2 created"

    # Case 4: missing required fields should fail
    Write-Output "INFO: Case4 missing fields"
    $inputBad = Join-Path $tmpRoot "input_bad.json"
    $badPayload = @{ migrationId = "MIG-TEST-002" } | ConvertTo-Json -Depth 5
    Set-Content -LiteralPath $inputBad -Value $badPayload -Encoding UTF8
    $r = Invoke-Generator -InputJsonPath $inputBad -OutputDir $outDir1
    Assert-True ($r.ExitCode -eq 1) "[MISSING_FIELDS] expected exit 1"
    Assert-True ($r.Object.success -eq $false) "[MISSING_FIELDS] expected success=false"
    Assert-True ($r.Object.reason -match "Missing required fields") "[MISSING_FIELDS] expected missing fields reason"

    # Case 5: confirm no dependency on tenant_service_limits.sql (only uses provided paths)
    Write-Output "INFO: Case5 no tenant_service_limits dependency"
    Assert-True ($r.Raw -notmatch "tenant_service_limits") "[NO_TSL_DEP] unexpected mention of tenant_service_limits"

    Write-Output "PASS: test_report_generator.ps1"
}
finally {
    # Intentionally keep temp files to avoid slow cleanup on some hosts.
    # They are safe mock artifacts under %TEMP% and can be manually removed.
    Write-Output "INFO: temp files kept (no cleanup) at $tmpRoot"
}

