param(
    [Parameter(Mandatory = $true)]
    [string]$BackupPath,
    [Parameter(Mandatory = $true)]
    [string]$SchemaSnapshotPath,
    [Parameter(Mandatory = $true)]
    [string]$RestoreGuidePath,
    [Parameter(Mandatory = $true)]
    [string]$RecoveryMode
)

# Plan-only safety: this checker must not connect to SQL Server or execute SQL.

$backupExists = $false
$schemaExists = $false
$guideExists = $false
$modeOk = $false

try { $backupExists = Test-Path -LiteralPath $BackupPath } catch { $backupExists = $false }
try { $schemaExists = Test-Path -LiteralPath $SchemaSnapshotPath } catch { $schemaExists = $false }
try { $guideExists = Test-Path -LiteralPath $RestoreGuidePath } catch { $guideExists = $false }
$modeOk = ($RecoveryMode -eq "A")

$checks = [PSCustomObject]@{
    backupExists          = [bool]$backupExists
    schemaSnapshotExists  = [bool]$schemaExists
    restoreGuideExists    = [bool]$guideExists
    recoveryModeAEnabled  = [bool]$modeOk
}

if ($backupExists -and $schemaExists -and $guideExists -and $modeOk) {
    $obj = [PSCustomObject]@{
        success      = $true
        ready        = $true
        recoveryMode = "A"
        checks       = $checks
        timestamp    = (Get-Date).ToString("o")
    }
    $obj | ConvertTo-Json -Depth 5
    exit 0
}

$reason = ""
if (-not $backupExists) { $reason = "BackupPath not found" }
elseif (-not $schemaExists) { $reason = "SchemaSnapshotPath not found" }
elseif (-not $guideExists) { $reason = "RestoreGuidePath not found" }
elseif (-not $modeOk) { $reason = "RecoveryMode must be A" }
else { $reason = "Unknown failure" }

$obj = [PSCustomObject]@{
    success      = $false
    ready        = $false
    recoveryMode = $RecoveryMode
    checks       = $checks
    reason       = $reason
}
$obj | ConvertTo-Json -Depth 5
exit 1

