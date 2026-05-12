param(
    [Parameter(Mandatory = $false)]
    [string] $ContractInputPath
)

$ErrorActionPreference = "Stop"

function Write-GateFailure {
    param([string]$Reason)
    [PSCustomObject]@{
        success  = $false
        approved = $false
        reason   = $Reason
    } | ConvertTo-Json -Depth 5
    exit 1
}

function Write-GateSuccess {
    [PSCustomObject]@{
        success   = $true
        approved  = $true
        timestamp = (Get-Date).ToString("o")
    } | ConvertTo-Json -Depth 5
    exit 0
}

function Test-NonEmptyString {
    param($Value)
    if ($null -eq $Value) { return $false }
    return ([string]$Value).Trim().Length -gt 0
}

function Test-ContractInput {
    param([Parameter(Mandatory = $true)] $InputObject)

    $names = $InputObject.PSObject.Properties.Name

    if (-not ($names -contains "mode")) {
        Write-GateFailure "Contract validation failed: mode is required"
    }
    if (-not ($names -contains "environment")) {
        Write-GateFailure "Contract validation failed: environment is required"
    }
    if (-not ($names -contains "migrationFile")) {
        Write-GateFailure "Contract validation failed: migrationFile is required"
    }
    if (-not ($names -contains "enableLiveExecution")) {
        Write-GateFailure "Contract validation failed: enableLiveExecution is required"
    }
    if (-not ($names -contains "humanApprovals")) {
        Write-GateFailure "Contract validation failed: humanApprovals is required"
    }
    if (-not ($names -contains "maintenanceWindow")) {
        Write-GateFailure "Contract validation failed: maintenanceWindow is required"
    }
    if (-not ($names -contains "backupConfirmation")) {
        Write-GateFailure "Contract validation failed: backupConfirmation is required"
    }
    if (-not ($names -contains "recoveryReadiness")) {
        Write-GateFailure "Contract validation failed: recoveryReadiness is required"
    }
    if (-not ($names -contains "finalSignOff")) {
        Write-GateFailure "Contract validation failed: finalSignOff is required"
    }
    if (-not ($names -contains "auditMetadata")) {
        Write-GateFailure "Contract validation failed: auditMetadata is required"
    }

    $mode = [string]$InputObject.mode
    if (-not (Test-NonEmptyString $mode)) {
        Write-GateFailure "Contract validation failed: mode must be non-empty"
    }

    $environment = [string]$InputObject.environment
    if (-not (Test-NonEmptyString $environment)) {
        Write-GateFailure "Contract validation failed: environment must be non-empty"
    }

    if (-not (Test-NonEmptyString $InputObject.migrationFile)) {
        Write-GateFailure "Contract validation failed: migrationFile must be non-empty"
    }

    if ($mode -eq "LIVE_EXECUTE") {
        if ($InputObject.enableLiveExecution -ne $true) {
            Write-GateFailure "Contract validation failed: LIVE_EXECUTE requires enableLiveExecution true"
        }
    }

    $mw = $InputObject.maintenanceWindow
    if ($null -eq $mw) {
        Write-GateFailure "Contract validation failed: maintenanceWindow is null"
    }
    if ($mw.approved -ne $true) {
        Write-GateFailure "Contract validation failed: maintenanceWindow.approved must be true"
    }

    $bc = $InputObject.backupConfirmation
    if ($null -eq $bc) {
        Write-GateFailure "Contract validation failed: backupConfirmation is null"
    }
    if (-not (Test-NonEmptyString $bc.backupFile)) {
        Write-GateFailure "Contract validation failed: backupConfirmation.backupFile is required"
    }

    $rr = $InputObject.recoveryReadiness
    if ($null -eq $rr) {
        Write-GateFailure "Contract validation failed: recoveryReadiness is null"
    }
    $rrStatus = [string]$rr.status
    if ($rrStatus -ne "PASS") {
        Write-GateFailure "Contract validation failed: recoveryReadiness.status must be PASS"
    }

    $meta = $InputObject.auditMetadata
    if ($null -eq $meta) {
        Write-GateFailure "Contract validation failed: auditMetadata is null"
    }
    if (-not (Test-NonEmptyString $meta.changeRequestId)) {
        Write-GateFailure "Contract validation failed: auditMetadata.changeRequestId is required"
    }

    $approvals = @($InputObject.humanApprovals)
    if ($approvals.Count -lt 2) {
        Write-GateFailure "Contract validation failed: humanApprovals must contain at least 2 entries"
    }

    foreach ($h in $approvals) {
        if ($null -eq $h) {
            Write-GateFailure "Contract validation failed: humanApprovals entry is null"
        }
        if (-not (Test-NonEmptyString $h.role)) {
            Write-GateFailure "Contract validation failed: humanApprovals.role is required"
        }
        if (-not (Test-NonEmptyString $h.approver)) {
            Write-GateFailure "Contract validation failed: humanApprovals.approver is required"
        }
        if (-not (Test-NonEmptyString $h.approvedAt)) {
            Write-GateFailure "Contract validation failed: humanApprovals.approvedAt is required"
        }
        if (-not (Test-NonEmptyString $h.signatureRef)) {
            Write-GateFailure "Contract validation failed: humanApprovals.signatureRef is required"
        }
    }

    if ($environment -eq "PRODUCTION") {
        $fs = $InputObject.finalSignOff
        if ($null -eq $fs) {
            Write-GateFailure "Contract validation failed: finalSignOff is null"
        }
        if ($fs.approved -ne $true) {
            Write-GateFailure "Contract validation failed: PRODUCTION requires finalSignOff.approved true"
        }
    }
}

$useContract = $false
if ($PSBoundParameters.ContainsKey("ContractInputPath")) {
    if (-not [string]::IsNullOrWhiteSpace($ContractInputPath)) {
        $useContract = $true
    }
}

if (-not $useContract) {
    Write-Warning "This operation requires explicit human approval."

    $inputText = Read-Host "Type YES to continue"

    if ($inputText -ne "YES") {
        $obj = [PSCustomObject]@{
            success  = $false
            approved = $false
            reason   = "User did not type YES"
        }
        $obj | ConvertTo-Json -Depth 3
        exit 1
    }

    $obj = [PSCustomObject]@{
        success   = $true
        approved  = $true
        timestamp = (Get-Date).ToString("o")
    }
    $obj | ConvertTo-Json -Depth 3
    exit 0
}

try {
    $resolved = (Resolve-Path -LiteralPath $ContractInputPath -ErrorAction Stop).Path
} catch {
    Write-GateFailure "Contract input path not found or not accessible"
}

try {
    $raw = Get-Content -LiteralPath $resolved -Raw -Encoding UTF8
    $doc = $raw | ConvertFrom-Json
} catch {
    Write-GateFailure "Contract validation failed: invalid JSON"
}

Test-ContractInput -InputObject $doc
Write-GateSuccess
