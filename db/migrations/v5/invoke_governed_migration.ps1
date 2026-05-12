param(
    [Parameter(Mandatory = $true)]
    [string] $InputJsonPath,
    [Parameter(Mandatory = $true)]
    [string] $OutputDir,
    [Parameter(Mandatory = $false)]
    [string] $ContractInputPath,
    [Parameter(Mandatory = $false)]
    [switch] $EnableLiveExecution,
    [Parameter(Mandatory = $false)]
    [string] $FinalManualConfirm
)

# Safety: no SQL Server connection and no SQL execution in this script.

$ErrorActionPreference = "Stop"
$v5Root = $PSScriptRoot
$gateScript = Join-Path $v5Root "approval_gate.ps1"
$mwScript = Join-Path $v5Root "maintenance_window_validator.ps1"
$recoveryScript = Join-Path $v5Root "recovery_readiness_checker.ps1"
$reportGenScript = Join-Path $v5Root "report_generator.ps1"

function Fail([string]$Reason) {
    [PSCustomObject]@{
        success  = $false
        executed = $false
        reason   = $Reason
    } | ConvertTo-Json -Depth 10
    exit 1
}

function FailLive {
    param(
        [string]$Reason,
        [bool]$Pass = $false,
        [bool]$LiveExecutionEnabled = $false
    )
    [PSCustomObject]@{
        success               = $false
        pass                  = [bool]$Pass
        executed              = $false
        liveExecutionEnabled  = [bool]$LiveExecutionEnabled
        reason                = $Reason
    } | ConvertTo-Json -Depth 10
    exit 1
}

function FailLiveSkeletonPassed {
    [PSCustomObject]@{
        success               = $false
        pass                  = $false
        executed              = $false
        liveExecutionEnabled  = $false
        reason                = "LIVE_EXECUTE skeleton guard passed, but production execution is not enabled in Phase 5 Step 4-B"
    } | ConvertTo-Json -Depth 10
    exit 1
}

function Invoke-ChildScript {
    param(
        [Parameter(Mandatory = $true)][string] $ScriptPath,
        [Parameter(Mandatory = $true)][string[]] $ScriptArguments,
        [int] $TimeoutMs = 120000
    )
    $allArgs = @("-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", $ScriptPath) + $ScriptArguments
    $timeoutSec = [Math]::Max(1, [int][Math]::Ceiling($TimeoutMs / 1000.0))
    try {
        $p = Start-Process -FilePath "powershell.exe" -ArgumentList $allArgs -PassThru -WindowStyle Hidden
        if ($null -eq $p) {
            return [PSCustomObject]@{ ExitCode = -1 }
        }
        Wait-Process -InputObject $p -Timeout $timeoutSec -ErrorAction SilentlyContinue
        if (-not $p.HasExited) {
            try { $p.Kill() } catch { }
            return [PSCustomObject]@{ ExitCode = -1 }
        }
        $p.Refresh()
        $code = $p.ExitCode
        if ($null -eq $code) {
            return [PSCustomObject]@{ ExitCode = -1 }
        }
        return [PSCustomObject]@{ ExitCode = [int]$code }
    } catch {
        return [PSCustomObject]@{ ExitCode = -1 }
    }
}

try {
    $resolvedInput = (Resolve-Path -LiteralPath $InputJsonPath -ErrorAction Stop).Path
} catch {
    Fail "InputJsonPath not found"
}

try {
    $raw = Get-Content -LiteralPath $resolvedInput -Raw -Encoding UTF8
    $input = $raw | ConvertFrom-Json
} catch {
    Fail "Invalid JSON input"
}

$required = @("migrationId", "proposalId", "environment", "mode", "operator", "approval", "riskSummary", "recoveryReadiness", "schemaDiffSummary", "executionPlan")
$missing = @()
foreach ($k in $required) {
    if (-not ($input.PSObject.Properties.Name -contains $k)) { $missing += $k }
}
if ($missing.Count -gt 0) { Fail ("Missing required fields: " + ($missing -join ", ")) }

$mode = [string]$input.mode

if ($mode -eq "LIVE_EXECUTE") {
    if ([string]::IsNullOrWhiteSpace($ContractInputPath)) {
        FailLive "LIVE_EXECUTE requires -ContractInputPath to the Phase 5 governed migration contract JSON"
    }
    if (-not $EnableLiveExecution.IsPresent -or -not [bool]$EnableLiveExecution) {
        FailLive "LIVE_EXECUTE requires -EnableLiveExecution switch to be explicitly enabled"
    }

    try {
        $resolvedContract = (Resolve-Path -LiteralPath $ContractInputPath -ErrorAction Stop).Path
    } catch {
        FailLive "LIVE_EXECUTE: ContractInputPath not found or not accessible"
    }

    try {
        $contractRaw = Get-Content -LiteralPath $resolvedContract -Raw -Encoding UTF8
        $contract = $contractRaw | ConvertFrom-Json
    } catch {
        FailLive "LIVE_EXECUTE: contract JSON could not be read or parsed"
    }

    $cMode = $null
    try { $cMode = [string]$contract.mode } catch { $cMode = $null }
    if ($cMode -ne "LIVE_EXECUTE") {
        FailLive "LIVE_EXECUTE: contract.mode must be LIVE_EXECUTE"
    }

    $cEnv = $null
    try { $cEnv = [string]$contract.environment } catch { $cEnv = $null }
    if ($cEnv -ne "PRODUCTION") {
        FailLive "LIVE_EXECUTE: contract.environment must be PRODUCTION"
    }

    if ($null -eq $contract.enableLiveExecution -or $contract.enableLiveExecution -ne $true) {
        FailLive "LIVE_EXECUTE: contract.enableLiveExecution must be true"
    }

    if (-not (Test-Path -LiteralPath $gateScript)) {
        FailLive "LIVE_EXECUTE: approval_gate.ps1 not found"
    }
    $gateRun = Invoke-ChildScript -ScriptPath $gateScript -ScriptArguments @("-ContractInputPath", $resolvedContract)
    if ($gateRun.ExitCode -eq -1) {
        FailLive "LIVE_EXECUTE: approval_gate.ps1 timed out"
    }
    if ($gateRun.ExitCode -ne 0) {
        FailLive ("LIVE_EXECUTE: approval_gate.ps1 failed (exit " + $gateRun.ExitCode + ")")
    }

    if (-not (Test-Path -LiteralPath $mwScript)) {
        FailLive "LIVE_EXECUTE: maintenance_window_validator.ps1 not found"
    }
    $mwRun = Invoke-ChildScript -ScriptPath $mwScript -ScriptArguments @("-ContractInputPath", $resolvedContract)
    if ($mwRun.ExitCode -eq -1) {
        FailLive "LIVE_EXECUTE: maintenance_window_validator.ps1 timed out"
    }
    if ($mwRun.ExitCode -ne 0) {
        FailLive ("LIVE_EXECUTE: maintenance_window_validator.ps1 failed (exit " + $mwRun.ExitCode + ")")
    }

    $rrc = $null
    try { $rrc = $contract.recoveryReadinessChecker } catch { $rrc = $null }
    if ($null -eq $rrc) {
        FailLive "LIVE_EXECUTE skeleton: recoveryReadinessChecker block not provided on contract (backupPath, schemaSnapshotPath, restoreGuidePath, recoveryMode required to invoke recovery_readiness_checker.ps1)"
    }
    $bp = $null
    $sp = $null
    $gp = $null
    $rm = $null
    try { $bp = [string]$rrc.backupPath } catch { $bp = $null }
    try { $sp = [string]$rrc.schemaSnapshotPath } catch { $sp = $null }
    try { $gp = [string]$rrc.restoreGuidePath } catch { $gp = $null }
    try { $rm = [string]$rrc.recoveryMode } catch { $rm = $null }
    if ([string]::IsNullOrWhiteSpace($bp) -or [string]::IsNullOrWhiteSpace($sp) -or [string]::IsNullOrWhiteSpace($gp) -or [string]::IsNullOrWhiteSpace($rm)) {
        FailLive "LIVE_EXECUTE: recoveryReadinessChecker must include backupPath, schemaSnapshotPath, restoreGuidePath, and recoveryMode"
    }
    if (-not (Test-Path -LiteralPath $recoveryScript)) {
        FailLive "LIVE_EXECUTE: recovery_readiness_checker.ps1 not found"
    }
    $recRun = Invoke-ChildScript -ScriptPath $recoveryScript -ScriptArguments @(
        "-BackupPath", $bp,
        "-SchemaSnapshotPath", $sp,
        "-RestoreGuidePath", $gp,
        "-RecoveryMode", $rm
    )
    if ($recRun.ExitCode -eq -1) {
        FailLive "LIVE_EXECUTE: recovery_readiness_checker.ps1 timed out"
    }
    if ($recRun.ExitCode -ne 0) {
        FailLive ("LIVE_EXECUTE: recovery_readiness_checker.ps1 failed (exit " + $recRun.ExitCode + ")")
    }

    $fs = $null
    try { $fs = $contract.finalSignOff } catch { $fs = $null }
    if ($null -eq $fs -or $fs.approved -ne $true) {
        FailLive "LIVE_EXECUTE: contract.finalSignOff.approved must be true"
    }

    $expectedConfirm = "I_UNDERSTAND_THIS_IS_PRODUCTION_LIVE_EXECUTION"
    if ($FinalManualConfirm -ne $expectedConfirm) {
        FailLive "LIVE_EXECUTE: -FinalManualConfirm must equal the exact string I_UNDERSTAND_THIS_IS_PRODUCTION_LIVE_EXECUTION"
    }

    try {
        if (-not (Test-Path -LiteralPath $OutputDir)) {
            New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
        }
        $resolvedOut = (Resolve-Path -LiteralPath $OutputDir -ErrorAction Stop).Path
    } catch {
        FailLive "LIVE_EXECUTE: failed to create or resolve OutputDir"
    }

    $preReportInput = [PSCustomObject]@{
        migrationId       = [string]$input.migrationId
        proposalId        = [string]$input.proposalId
        environment       = [string]$input.environment
        operator          = [string]$input.operator
        approval          = $input.approval
        riskSummary       = $input.riskSummary
        recoveryReadiness = $input.recoveryReadiness
        schemaDiffSummary = $input.schemaDiffSummary
        executionResult   = [PSCustomObject]@{
            executed = $false
            mode     = "LIVE_EXECUTE"
            note     = "Phase 5 Step 4-B audit pre-report (skeleton; no SQL execution)"
            plan     = $input.executionPlan
        }
    }
    $prePath = Join-Path $resolvedOut "report_generator_pre_live.json"
    $preReportInput | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath $prePath -Encoding UTF8

    if (Test-Path -LiteralPath $reportGenScript) {
        $rgRun = Invoke-ChildScript -ScriptPath $reportGenScript -ScriptArguments @("-InputJsonPath", $prePath, "-OutputDir", $resolvedOut)
        if ($rgRun.ExitCode -eq -1) {
            FailLive "LIVE_EXECUTE: report_generator.ps1 timed out"
        }
        if ($rgRun.ExitCode -ne 0) {
            FailLive ("LIVE_EXECUTE: report_generator.ps1 failed (exit " + $rgRun.ExitCode + ")")
        }
    } else {
        FailLive "LIVE_EXECUTE: report_generator.ps1 not found"
    }

    FailLiveSkeletonPassed
}

if ($mode -ne "MOCK" -and $mode -ne "DRY_RUN") {
    Fail "LIVE_EXECUTE is not supported in this phase"
}

if ($null -eq $input.approval -or $input.approval.approved -ne $true) {
    Fail "Governance failed: approval.approved must be true"
}
if ($null -eq $input.recoveryReadiness -or $input.recoveryReadiness.ready -ne $true) {
    Fail "Governance failed: recoveryReadiness.ready must be true"
}
if ($null -eq $input.riskSummary -or $input.riskSummary.allowed -ne $true) {
    Fail "Governance failed: riskSummary.allowed must be true"
}
if ($null -eq $input.schemaDiffSummary -or $input.schemaDiffSummary.safe -ne $true) {
    Fail "Governance failed: schemaDiffSummary.safe must be true"
}

try {
    if (-not (Test-Path -LiteralPath $OutputDir)) {
        New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
    }
    $resolvedOut = (Resolve-Path -LiteralPath $OutputDir -ErrorAction Stop).Path
} catch {
    Fail "Failed to create OutputDir"
}

try {
    $reportInput = [PSCustomObject]@{
        migrationId         = [string]$input.migrationId
        proposalId          = [string]$input.proposalId
        environment         = [string]$input.environment
        operator            = [string]$input.operator
        approval            = $input.approval
        riskSummary         = $input.riskSummary
        recoveryReadiness   = $input.recoveryReadiness
        schemaDiffSummary   = $input.schemaDiffSummary
        executionResult     = [PSCustomObject]@{
            executed = $false
            mode     = $mode
            note     = "No SQL execution in this phase"
            plan     = $input.executionPlan
        }
    }
    $reportInput | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath (Join-Path $resolvedOut "report_generator_input.json") -Encoding UTF8

    $execReport = [PSCustomObject]@{
        migrationId       = [string]$input.migrationId
        proposalId        = [string]$input.proposalId
        environment       = [string]$input.environment
        operator          = [string]$input.operator
        mode              = $mode
        executed          = $false
        generatedAt       = (Get-Date).ToString("o")
    }
    $execReport | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath (Join-Path $resolvedOut "execution_report.json") -Encoding UTF8

    $nl = [Environment]::NewLine
    $md = "# Governed Migration Execution Report" + $nl + $nl +
        "migrationId: " + [string]$input.migrationId + $nl +
        "proposalId: " + [string]$input.proposalId + $nl +
        "mode: " + $mode + $nl +
        "executed: false" + $nl
    Set-Content -LiteralPath (Join-Path $resolvedOut "execution_report.md") -Value $md -Encoding UTF8

    ($input.riskSummary | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath (Join-Path $resolvedOut "risk_summary.md") -Encoding UTF8
    ($input.schemaDiffSummary | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath (Join-Path $resolvedOut "schema_diff_summary.md") -Encoding UTF8
    ($input.recoveryReadiness | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath (Join-Path $resolvedOut "recovery_readiness_summary.md") -Encoding UTF8
} catch {
    Fail "Report generation failed"
}

[PSCustomObject]@{
    success         = $true
    executed        = $false
    mode            = $mode
    reportGenerated = $true
    outputDir       = $resolvedOut
    timestamp       = (Get-Date).ToString("o")
} | ConvertTo-Json -Depth 10
exit 0
