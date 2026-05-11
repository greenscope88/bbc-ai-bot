$ErrorActionPreference = "Stop"

$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$checker = Join-Path $root "recovery_readiness_checker.ps1"

function Invoke-Checker {
    param(
        [Parameter(Mandatory = $true)][string]$BackupPath,
        [Parameter(Mandatory = $true)][string]$SchemaSnapshotPath,
        [Parameter(Mandatory = $true)][string]$RestoreGuidePath,
        [Parameter(Mandatory = $true)][string]$RecoveryMode
    )

    $out = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $checker `
        -BackupPath $BackupPath `
        -SchemaSnapshotPath $SchemaSnapshotPath `
        -RestoreGuidePath $RestoreGuidePath `
        -RecoveryMode $RecoveryMode 2>&1 | Out-String

    $exitCode = $LASTEXITCODE

    $text = $out.Trim()
    $start = $text.IndexOf('{')
    $end = $text.LastIndexOf('}')
    if ($start -lt 0 -or $end -lt 0 -or $end -le $start) {
        throw "Could not locate JSON braces. Output: $out"
    }
    $jsonText = $text.Substring($start, $end - $start + 1)

    try { $obj = $jsonText | ConvertFrom-Json } catch { throw "Invalid JSON output. Output: $out" }

    return [PSCustomObject]@{ ExitCode = $exitCode; Object = $obj; Raw = $out }
}

function Assert-True([bool]$cond, [string]$msg) {
    if (-not $cond) { throw $msg }
}

$tmpRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("recovery-readiness-" + [Guid]::NewGuid().ToString("n"))
New-Item -ItemType Directory -Path $tmpRoot -Force | Out-Null

try {
    $bak = Join-Path $tmpRoot "state.bak"
    $schema = Join-Path $tmpRoot "schema-only.sql"
    $guide = Join-Path $tmpRoot "restore_guide.md"

    Set-Content -LiteralPath $bak -Value "MOCK_BACKUP" -Encoding UTF8
    Set-Content -LiteralPath $schema -Value "-- mock schema-only" -Encoding UTF8
    Set-Content -LiteralPath $guide -Value "# Mock restore guide" -Encoding UTF8

    # Case 1: all pass
    $r = Invoke-Checker -BackupPath $bak -SchemaSnapshotPath $schema -RestoreGuidePath $guide -RecoveryMode "A"
    Assert-True ($r.ExitCode -eq 0) "[PASS] expected exit 0"
    Assert-True ($r.Object.success -eq $true) "[PASS] expected success=true"
    Assert-True ($r.Object.ready -eq $true) "[PASS] expected ready=true"
    Assert-True ($r.Object.recoveryMode -eq "A") "[PASS] expected recoveryMode=A"
    Assert-True ($r.Object.checks.backupExists -eq $true) "[PASS] expected backupExists=true"
    Assert-True ($r.Object.checks.schemaSnapshotExists -eq $true) "[PASS] expected schemaSnapshotExists=true"
    Assert-True ($r.Object.checks.restoreGuideExists -eq $true) "[PASS] expected restoreGuideExists=true"
    Assert-True ($r.Object.checks.recoveryModeAEnabled -eq $true) "[PASS] expected recoveryModeAEnabled=true"
    Assert-True (-not [string]::IsNullOrWhiteSpace([string]$r.Object.timestamp)) "[PASS] expected timestamp"

    # Case 2: missing .bak
    $missingBak = Join-Path $tmpRoot "missing.bak"
    $r = Invoke-Checker -BackupPath $missingBak -SchemaSnapshotPath $schema -RestoreGuidePath $guide -RecoveryMode "A"
    Assert-True ($r.ExitCode -eq 1) "[MISSING_BAK] expected exit 1"
    Assert-True ($r.Object.success -eq $false) "[MISSING_BAK] expected success=false"
    Assert-True ($r.Object.ready -eq $false) "[MISSING_BAK] expected ready=false"
    Assert-True ($r.Object.checks.backupExists -eq $false) "[MISSING_BAK] expected backupExists=false"
    Assert-True ($r.Object.reason -eq "BackupPath not found") "[MISSING_BAK] expected reason"

    # Case 3: missing schema snapshot
    $missingSchema = Join-Path $tmpRoot "missing_schema.sql"
    $r = Invoke-Checker -BackupPath $bak -SchemaSnapshotPath $missingSchema -RestoreGuidePath $guide -RecoveryMode "A"
    Assert-True ($r.ExitCode -eq 1) "[MISSING_SCHEMA] expected exit 1"
    Assert-True ($r.Object.checks.schemaSnapshotExists -eq $false) "[MISSING_SCHEMA] expected schemaSnapshotExists=false"
    Assert-True ($r.Object.reason -eq "SchemaSnapshotPath not found") "[MISSING_SCHEMA] expected reason"

    # Case 4: missing restore guide
    $missingGuide = Join-Path $tmpRoot "missing_guide.md"
    $r = Invoke-Checker -BackupPath $bak -SchemaSnapshotPath $schema -RestoreGuidePath $missingGuide -RecoveryMode "A"
    Assert-True ($r.ExitCode -eq 1) "[MISSING_GUIDE] expected exit 1"
    Assert-True ($r.Object.checks.restoreGuideExists -eq $false) "[MISSING_GUIDE] expected restoreGuideExists=false"
    Assert-True ($r.Object.reason -eq "RestoreGuidePath not found") "[MISSING_GUIDE] expected reason"

    # Case 5: RecoveryMode not A
    $r = Invoke-Checker -BackupPath $bak -SchemaSnapshotPath $schema -RestoreGuidePath $guide -RecoveryMode "B"
    Assert-True ($r.ExitCode -eq 1) "[MODE_NOT_A] expected exit 1"
    Assert-True ($r.Object.checks.recoveryModeAEnabled -eq $false) "[MODE_NOT_A] expected recoveryModeAEnabled=false"
    Assert-True ($r.Object.reason -eq "RecoveryMode must be A") "[MODE_NOT_A] expected reason"

    Write-Output "PASS: test_recovery_readiness_checker.ps1"
}
finally {
    Remove-Item -LiteralPath $tmpRoot -Recurse -Force -ErrorAction SilentlyContinue
}

