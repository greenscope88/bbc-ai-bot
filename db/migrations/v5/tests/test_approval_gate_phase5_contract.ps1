$ErrorActionPreference = "Stop"

$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$gate = Join-Path $root "approval_gate.ps1"

function Get-JsonFromGateOutput {
    param([object]$Out)
    $text = ($Out | Out-String)
    $start = $text.IndexOf('{')
    $end = $text.LastIndexOf('}')
    if ($start -lt 0 -or $end -lt 0 -or $end -le $start) {
        throw "Could not locate JSON in output: $text"
    }
    $jsonText = $text.Substring($start, $end - $start + 1)
    return $jsonText | ConvertFrom-Json
}

function Invoke-ContractGate {
    param([string]$JsonPath)
    $cmd = "& '$gate' -ContractInputPath '$JsonPath'"
    $out = & powershell.exe -NoProfile -ExecutionPolicy Bypass -Command $cmd 2>&1
    return [PSCustomObject]@{
        ExitCode = $LASTEXITCODE
        Out      = $out
    }
}

function New-BaseContract {
    param(
        [string]$Mode = "MOCK",
        [string]$Environment = "DEV",
        [bool]$EnableLiveExecution = $false,
        [bool]$MaintenanceApproved = $true,
        [string]$RecoveryStatus = "PASS",
        [bool]$FinalSignOffApproved = $false,
        [object]$HumanApprovals = $null
    )
    if ($null -eq $HumanApprovals) {
        $HumanApprovals = @(
            @{ role = "dba"; approver = "a@x"; approvedAt = "2026-05-01T10:00:00Z"; signatureRef = "sig-1" },
            @{ role = "owner"; approver = "b@x"; approvedAt = "2026-05-01T11:00:00Z"; signatureRef = "sig-2" }
        )
    }
    return [ordered]@{
        mode                  = $Mode
        migrationFile         = "db/migrations/v5/sql/example.sql"
        environment           = $Environment
        enableLiveExecution   = $EnableLiveExecution
        maintenanceWindow     = @{
            approved     = $MaintenanceApproved
            windowStart  = "2026-05-10T01:00:00Z"
            windowEnd    = "2026-05-10T03:00:00Z"
            approvedBy   = "ops@x"
        }
        humanApprovals        = $HumanApprovals
        backupConfirmation    = @{
            backupFile  = "\\backup\db\demo.bak"
            createdAt   = "2026-05-09T02:00:00Z"
            verifiedBy  = "dba@x"
        }
        recoveryReadiness     = @{
            status     = $RecoveryStatus
            reportPath = "db/migrations/v5/tests/results/recovery.json"
        }
        finalSignOff          = @{
            approved    = $FinalSignOffApproved
            approvedBy  = "cab@x"
            approvedAt  = "2026-05-09T12:00:00Z"
            ticketId    = "CAB-1"
        }
        auditMetadata         = @{
            changeRequestId = "CR-9001"
            businessReason  = "contract test"
            submittedBy     = "ci@x"
        }
    }
}

$tmp = Join-Path ([System.IO.Path]::GetTempPath()) ("approval-gate-p5-" + [Guid]::NewGuid().ToString("n"))
New-Item -ItemType Directory -Path $tmp -Force | Out-Null

try {
    # Phase 4 legacy: unchanged behavior
    $cmd = @"
function Read-Host([string]`$Prompt) { return 'YES' }
& '$gate'
exit `$LASTEXITCODE
"@
    $legacyOut = & powershell.exe -NoProfile -ExecutionPolicy Bypass -Command $cmd 2>&1
    if ($LASTEXITCODE -ne 0) { throw "[LEGACY] expected exit 0, got $LASTEXITCODE : $legacyOut" }
    $o = Get-JsonFromGateOutput -Out $legacyOut
    if ($o.success -ne $true -or $o.approved -ne $true) { throw "[LEGACY] expected approved" }

    # Missing file
    $missing = Join-Path $tmp "nope.json"
    $r = Invoke-ContractGate -JsonPath $missing
    if ($r.ExitCode -ne 1) { throw "[MISSING_FILE] expected exit 1" }
    $jo = Get-JsonFromGateOutput -Out $r.Out
    if ($jo.approved -eq $true) { throw "[MISSING_FILE] must not approve" }

    # humanApprovals < 2
    $oneApproval = New-BaseContract -HumanApprovals @(
        @{ role = "dba"; approver = "a@x"; approvedAt = "2026-05-01T10:00:00Z"; signatureRef = "sig-1" }
    )
    $p = Join-Path $tmp "one_approval.json"
    ($oneApproval | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-ContractGate -JsonPath $p
    if ($r.ExitCode -ne 1) { throw "[HUMAN_LT2] expected exit 1" }
    $jo = Get-JsonFromGateOutput -Out $r.Out
    if ($jo.reason -notmatch "at least 2") { throw "[HUMAN_LT2] unexpected reason: $($jo.reason)" }

    # LIVE_EXECUTE + enableLiveExecution false
    $liveBad = New-BaseContract -Mode "LIVE_EXECUTE" -Environment "STAGING" -EnableLiveExecution $false
    $p = Join-Path $tmp "live_exec_false.json"
    ($liveBad | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-ContractGate -JsonPath $p
    if ($r.ExitCode -ne 1) { throw "[LIVE_ENABLE_FALSE] expected exit 1" }
    $jo = Get-JsonFromGateOutput -Out $r.Out
    if ($jo.reason -notmatch "enableLiveExecution true") { throw "[LIVE_ENABLE_FALSE] reason: $($jo.reason)" }

    # LIVE_EXECUTE + enableLiveExecution true -> gate allows (Phase 5 Step 4-B); still not wrapper execution
    $liveTrue = New-BaseContract -Mode "LIVE_EXECUTE" -Environment "STAGING" -EnableLiveExecution $true -FinalSignOffApproved $true
    $p = Join-Path $tmp "live_exec_true.json"
    ($liveTrue | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-ContractGate -JsonPath $p
    if ($r.ExitCode -ne 0) { throw "[LIVE_ENABLE_TRUE_STAGING] expected exit 0: $($r.Out)" }
    $jo = Get-JsonFromGateOutput -Out $r.Out
    if ($jo.success -ne $true -or $jo.approved -ne $true) { throw "[LIVE_ENABLE_TRUE_STAGING] expected approved" }

    # PRODUCTION + finalSignOff false
    $prodBad = New-BaseContract -Mode "DRY_RUN" -Environment "PRODUCTION" -FinalSignOffApproved $false
    $p = Join-Path $tmp "prod_signoff_false.json"
    ($prodBad | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-ContractGate -JsonPath $p
    if ($r.ExitCode -ne 1) { throw "[PROD_SIGNOFF] expected exit 1" }
    $jo = Get-JsonFromGateOutput -Out $r.Out
    if ($jo.reason -notmatch "finalSignOff.approved true") { throw "[PROD_SIGNOFF] reason: $($jo.reason)" }

    # recovery not PASS
    $recBad = New-BaseContract -RecoveryStatus "FAIL"
    $p = Join-Path $tmp "recovery_fail.json"
    ($recBad | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-ContractGate -JsonPath $p
    if ($r.ExitCode -ne 1) { throw "[RECOVERY] expected exit 1" }
    $jo = Get-JsonFromGateOutput -Out $r.Out
    if ($jo.reason -notmatch "PASS") { throw "[RECOVERY] reason: $($jo.reason)" }

    # Qualified MOCK contract
    $mockOk = New-BaseContract -Mode "MOCK" -Environment "DEV"
    $p = Join-Path $tmp "mock_ok.json"
    ($mockOk | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-ContractGate -JsonPath $p
    if ($r.ExitCode -ne 0) { throw "[MOCK_OK] expected exit 0: $($r.Out)" }
    $jo = Get-JsonFromGateOutput -Out $r.Out
    if ($jo.success -ne $true -or $jo.approved -ne $true) { throw "[MOCK_OK] expected approved" }

    # Qualified DRY_RUN contract
    $dryOk = New-BaseContract -Mode "DRY_RUN" -Environment "STAGING"
    $p = Join-Path $tmp "dry_ok.json"
    ($dryOk | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-ContractGate -JsonPath $p
    if ($r.ExitCode -ne 0) { throw "[DRY_OK] expected exit 0: $($r.Out)" }
    $jo = Get-JsonFromGateOutput -Out $r.Out
    if ($jo.success -ne $true -or $jo.approved -ne $true) { throw "[DRY_OK] expected approved" }

    Write-Output "PASS: test_approval_gate_phase5_contract.ps1"
}
finally {
    Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue
}
