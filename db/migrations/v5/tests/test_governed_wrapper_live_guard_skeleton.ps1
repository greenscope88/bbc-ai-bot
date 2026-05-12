$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot
$wrapper = Join-Path $root "invoke_governed_migration.ps1"

function Get-WrapperJson {
    param([object]$Out)
    $text = ($Out | Out-String)
    $start = $text.IndexOf('{')
    $end = $text.LastIndexOf('}')
    if ($start -lt 0 -or $end -lt 0 -or $end -le $start) {
        throw "Could not locate JSON in wrapper output: $text"
    }
    return ($text.Substring($start, $end - $start + 1) | ConvertFrom-Json)
}

function New-Phase4Payload {
    param([string]$Mode)
    return [ordered]@{
        migrationId       = "MIG-LIVE-SKEL-001"
        proposalId        = "DBCR-LIVE-SKEL-001"
        environment       = "TEST"
        mode              = $Mode
        operator          = "skeleton-test"
        approval          = @{ approved = $true; by = "human" }
        riskSummary       = @{ allowed = $true; level = "Low" }
        recoveryReadiness = @{ ready = $true; recoveryMode = "A" }
        schemaDiffSummary = @{ safe = $true; finalStatus = "PASS" }
        executionPlan     = @{ steps = @("validate", "report") }
    }
}

function New-Phase5Contract {
    param(
        [bool]$EnableLiveExecution = $true,
        [bool]$FinalSignOffApproved = $true,
        [object]$MaintenanceWindow,
        [object]$RecoveryReadinessChecker
    )
    $mid = [DateTimeOffset]::UtcNow
    if ($null -eq $MaintenanceWindow) {
        $MaintenanceWindow = @{
            approved    = $true
            windowStart = $mid.AddMinutes(-45).ToString("o")
            windowEnd   = $mid.AddMinutes(90).ToString("o")
            approvedBy  = "ops.window@test"
        }
    }
    return [ordered]@{
        contractVersion           = "5.0.0"
        mode                      = "LIVE_EXECUTE"
        migrationFile             = "db/migrations/v5/sql/example.sql"
        environment                 = "PRODUCTION"
        enableLiveExecution         = $EnableLiveExecution
        maintenanceWindow           = $MaintenanceWindow
        humanApprovals              = @(
            @{ role = "dba"; approver = "dba@test"; approvedAt = "2026-05-01T10:00:00Z"; signatureRef = "sig-1" },
            @{ role = "owner"; approver = "own@test"; approvedAt = "2026-05-01T11:00:00Z"; signatureRef = "sig-2" }
        )
        backupConfirmation          = @{
            backupFile  = "\\backup\db\demo.bak"
            createdAt   = "2026-05-09T02:00:00Z"
            verifiedBy  = "dba@x"
        }
        recoveryReadiness           = @{
            status     = "PASS"
            reportPath = "db/migrations/v5/tests/results/recovery.json"
        }
        finalSignOff                = @{
            approved    = $FinalSignOffApproved
            approvedBy  = "cab@test"
            approvedAt  = "2026-05-09T12:00:00Z"
            ticketId    = "CAB-SKEL-1"
        }
        auditMetadata               = @{
            changeRequestId = "CR-SKEL-1"
            businessReason  = "skeleton test"
            submittedBy     = "ci@test"
        }
        recoveryReadinessChecker    = $RecoveryReadinessChecker
    }
}

$tmp = Join-Path $PSScriptRoot ("tmp_live_skel_" + [Guid]::NewGuid().ToString("n"))
New-Item -ItemType Directory -Path $tmp -Force | Out-Null

try {
    $confirm = "I_UNDERSTAND_THIS_IS_PRODUCTION_LIVE_EXECUTION"

    # 1) LIVE without ContractInputPath
    $p4 = Join-Path $tmp "p4_no_contract.json"
    (New-Phase4Payload "LIVE_EXECUTE" | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p4 -Encoding UTF8
    $outDir = Join-Path $tmp "out1"
    $raw = & $wrapper -InputJsonPath $p4 -OutputDir $outDir 2>&1 | Out-String
    if ($LASTEXITCODE -ne 1) { throw "[1] expected exit 1" }
    $o = Get-WrapperJson -Out $raw
    if ($o.reason -notmatch "ContractInputPath") { throw "[1] bad reason: $($o.reason)" }

    # 2) LIVE with contract path but without -EnableLiveExecution switch
    $p4 = Join-Path $tmp "p4_live2.json"
    (New-Phase4Payload "LIVE_EXECUTE" | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p4 -Encoding UTF8
    $bak = Join-Path $tmp "stub.bak"
    $sch = Join-Path $tmp "stub_schema.sql"
    $gde = Join-Path $tmp "stub_guide.md"
    "" | Set-Content -LiteralPath $bak
    "" | Set-Content -LiteralPath $sch
    "" | Set-Content -LiteralPath $gde
    $rrc = @{
        backupPath         = $bak
        schemaSnapshotPath = $sch
        restoreGuidePath   = $gde
        recoveryMode       = "A"
    }
    $cPath = Join-Path $tmp "contract2.json"
    (New-Phase5Contract -RecoveryReadinessChecker $rrc | ConvertTo-Json -Depth 25) | Set-Content -LiteralPath $cPath -Encoding UTF8
    $outDir = Join-Path $tmp "out2"
    $raw = & $wrapper -InputJsonPath $p4 -OutputDir $outDir -ContractInputPath $cPath 2>&1 | Out-String
    if ($LASTEXITCODE -ne 1) { throw "[2] expected exit 1" }
    $o = Get-WrapperJson -Out $raw
    if ($o.reason -notmatch "EnableLiveExecution") { throw "[2] bad reason: $($o.reason)" }

    # 3) contract.enableLiveExecution = false
    $p4 = Join-Path $tmp "p4_live3.json"
    (New-Phase4Payload "LIVE_EXECUTE" | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p4 -Encoding UTF8
    $cPath = Join-Path $tmp "contract3.json"
    (New-Phase5Contract -EnableLiveExecution $false -RecoveryReadinessChecker $rrc | ConvertTo-Json -Depth 25) | Set-Content -LiteralPath $cPath -Encoding UTF8
    $outDir = Join-Path $tmp "out3"
    $raw = & $wrapper -InputJsonPath $p4 -OutputDir $outDir -ContractInputPath $cPath -EnableLiveExecution 2>&1 | Out-String
    if ($LASTEXITCODE -ne 1) { throw "[3] expected exit 1" }
    $o = Get-WrapperJson -Out $raw
    if ($o.reason -notmatch "contract.enableLiveExecution") { throw "[3] bad reason: $($o.reason)" }

    # 4) finalSignOff.approved = false
    $p4 = Join-Path $tmp "p4_live4.json"
    (New-Phase4Payload "LIVE_EXECUTE" | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p4 -Encoding UTF8
    $cPath = Join-Path $tmp "contract4.json"
    (New-Phase5Contract -FinalSignOffApproved $false -RecoveryReadinessChecker $rrc | ConvertTo-Json -Depth 25) | Set-Content -LiteralPath $cPath -Encoding UTF8
    $outDir = Join-Path $tmp "out4"
    $raw = & $wrapper -InputJsonPath $p4 -OutputDir $outDir -ContractInputPath $cPath -EnableLiveExecution 2>&1 | Out-String
    if ($LASTEXITCODE -ne 1) { throw "[4] expected exit 1" }
    $o = Get-WrapperJson -Out $raw
    if ($o.reason -notmatch "approval_gate") { throw "[4] expected gate failure, got: $($o.reason)" }

    # 5) FinalManualConfirm wrong
    $p4 = Join-Path $tmp "p4_live5.json"
    (New-Phase4Payload "LIVE_EXECUTE" | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p4 -Encoding UTF8
    $cPath = Join-Path $tmp "contract5.json"
    (New-Phase5Contract -RecoveryReadinessChecker $rrc | ConvertTo-Json -Depth 25) | Set-Content -LiteralPath $cPath -Encoding UTF8
    $outDir = Join-Path $tmp "out5"
    $raw = & $wrapper -InputJsonPath $p4 -OutputDir $outDir -ContractInputPath $cPath -EnableLiveExecution -FinalManualConfirm "WRONG" 2>&1 | Out-String
    if ($LASTEXITCODE -ne 1) { throw "[5] expected exit 1" }
    $o = Get-WrapperJson -Out $raw
    if ($o.reason -notmatch "FinalManualConfirm") { throw "[5] bad reason: $($o.reason)" }

    # 6) Full skeleton pass -> still fail with executed=false
    $p4 = Join-Path $tmp "p4_live6.json"
    (New-Phase4Payload "LIVE_EXECUTE" | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p4 -Encoding UTF8
    $cPath = Join-Path $tmp "contract6.json"
    (New-Phase5Contract -RecoveryReadinessChecker $rrc | ConvertTo-Json -Depth 25) | Set-Content -LiteralPath $cPath -Encoding UTF8
    $outDir = Join-Path $tmp "out6"
    $raw = & $wrapper -InputJsonPath $p4 -OutputDir $outDir -ContractInputPath $cPath -EnableLiveExecution -FinalManualConfirm $confirm 2>&1 | Out-String
    if ($LASTEXITCODE -ne 1) { throw "[6] expected exit 1: $raw" }
    $o = Get-WrapperJson -Out $raw
    if ($o.pass -ne $false) { throw "[6] pass must be false" }
    if ($o.executed -ne $false) { throw "[6] executed must be false" }
    if ($o.liveExecutionEnabled -ne $false) { throw "[6] liveExecutionEnabled must be false" }
    $expectedReason = "LIVE_EXECUTE skeleton guard passed, but production execution is not enabled in Phase 5 Step 4-B"
    if ($o.reason -ne $expectedReason) { throw "[6] bad reason: $($o.reason)" }

    # 7) MOCK unchanged
    $p4 = Join-Path $tmp "p4_mock.json"
    (New-Phase4Payload "MOCK" | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p4 -Encoding UTF8
    $outDir = Join-Path $tmp "out_mock"
    $raw = & $wrapper -InputJsonPath $p4 -OutputDir $outDir 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0) { throw "[MOCK] expected 0: $raw" }
    $o = Get-WrapperJson -Out $raw
    if ($o.success -ne $true -or $o.executed -ne $false) { throw "[MOCK] bad result" }

    # 8) DRY_RUN unchanged
    $p4 = Join-Path $tmp "p4_dry.json"
    (New-Phase4Payload "DRY_RUN" | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p4 -Encoding UTF8
    $outDir = Join-Path $tmp "out_dry"
    $raw = & $wrapper -InputJsonPath $p4 -OutputDir $outDir 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0) { throw "[DRY] expected 0: $raw" }
    $o = Get-WrapperJson -Out $raw
    if ($o.success -ne $true) { throw "[DRY] bad result" }

    Write-Output "PASS: test_governed_wrapper_live_guard_skeleton.ps1"
}
finally {
    Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue
}
