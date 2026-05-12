$ErrorActionPreference = "Stop"

$testDir = $PSScriptRoot
$v5Root = Resolve-Path (Join-Path $testDir "..")
$wrapper = Join-Path $v5Root "invoke_governed_migration.ps1"
$reportGenerator = Join-Path $v5Root "report_generator.ps1"

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}

function Run-TestScript([string]$ScriptPath) {
    $raw = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ScriptPath 2>&1 | Out-String
    $code = $LASTEXITCODE
    if ($code -ne 0) {
        throw "Unit test failed: $ScriptPath`n$raw"
    }
}

function Invoke-ReportGeneratorDirect([string]$InputJsonPath, [string]$OutputDir) {
    $raw = & $reportGenerator -InputJsonPath $InputJsonPath -OutputDir $OutputDir 2>&1 | Out-String
    $code = $LASTEXITCODE
    $txt = $raw.Trim()
    $s = $txt.IndexOf('{')
    $e = $txt.LastIndexOf('}')
    if ($s -lt 0 -or $e -lt 0 -or $e -le $s) {
        throw "Cannot parse JSON from report_generator output. Raw: $raw"
    }
    $jsonText = $txt.Substring($s, $e - $s + 1)
    $obj = $jsonText | ConvertFrom-Json
    return [PSCustomObject]@{ ExitCode = $code; Object = $obj; Raw = $raw }
}

function Invoke-Wrapper([string]$InputJsonPath, [string]$OutputDir) {
    $cmd = "& '$wrapper' -InputJsonPath '$InputJsonPath' -OutputDir '$OutputDir'"
    $raw = & powershell.exe -NoProfile -ExecutionPolicy Bypass -Command $cmd 2>&1 | Out-String
    $code = $LASTEXITCODE

    $txt = $raw.Trim()
    $s = $txt.IndexOf('{')
    $e = $txt.LastIndexOf('}')
    if ($s -lt 0 -or $e -lt 0 -or $e -le $s) {
        throw "Cannot parse JSON from wrapper output. Raw: $raw"
    }
    $jsonText = $txt.Substring($s, $e - $s + 1)
    $obj = $jsonText | ConvertFrom-Json

    return [PSCustomObject]@{
        ExitCode = $code
        Object   = $obj
        Raw      = $raw
    }
}

function New-InputPayload([string]$Mode) {
    return [ordered]@{
        migrationId       = "MIG-ACT-001"
        proposalId        = "DBCR-ACT-001"
        environment       = "TEST"
        mode              = $Mode
        operator          = "activation-suite"
        approval          = @{ approved = $true; by = "human" }
        riskSummary       = @{ allowed = $true; level = "Low" }
        recoveryReadiness = @{ ready = $true; recoveryMode = "A" }
        schemaDiffSummary = @{ safe = $true; finalStatus = "PASS" }
        executionPlan     = @{ steps = @("validate", "report") }
    }
}

$tmpRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("activation-suite-" + [Guid]::NewGuid().ToString("n"))
New-Item -ItemType Directory -Path $tmpRoot -Force | Out-Null

try {
    # 1) Run unit tests
    Run-TestScript (Join-Path $testDir "test_approval_gate.ps1")
    Run-TestScript (Join-Path $testDir "test_approval_gate_phase5_contract.ps1")
    Run-TestScript (Join-Path $testDir "test_recovery_readiness_checker.ps1")
    Run-TestScript (Join-Path $testDir "test_maintenance_window_validator.ps1")
    Run-TestScript (Join-Path $testDir "test_final_signoff_validator.ps1")
    Run-TestScript (Join-Path $testDir "test_governed_wrapper_live_guard_skeleton.ps1")
    # Equivalent report_generator validation (direct invocation, no subprocess hang).
    $rgInputOk = Join-Path $tmpRoot "rg_input_ok.json"
    $rgOut1 = Join-Path $tmpRoot "rg_out1"
    @{"migrationId"="MIG-RG-001";"proposalId"="DBCR-RG-001";"environment"="TEST";"operator"="activation-suite";"approval"=@{"approved"=$true};"riskSummary"=@{"allowed"=$true};"recoveryReadiness"=@{"ready"=$true};"schemaDiffSummary"=@{"safe"=$true};"executionResult"=@{"executed"=$false}} |
        ConvertTo-Json -Depth 10 | Set-Content -LiteralPath $rgInputOk -Encoding UTF8
    $rRg = Invoke-ReportGeneratorDirect -InputJsonPath $rgInputOk -OutputDir $rgOut1
    Assert-True ($rRg.ExitCode -eq 0) "[REPORT_EQ] expected report generator success"
    Assert-True (Test-Path -LiteralPath (Join-Path $rgOut1 "execution_report.json")) "[REPORT_EQ] execution_report.json missing"

    $rRg = Invoke-ReportGeneratorDirect -InputJsonPath (Join-Path $tmpRoot "missing.json") -OutputDir $rgOut1
    Assert-True ($rRg.ExitCode -eq 1) "[REPORT_EQ] missing input should fail"

    $rgInputBad = Join-Path $tmpRoot "rg_input_bad.json"
    @{"migrationId"="MIG-RG-002"} | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $rgInputBad -Encoding UTF8
    $rRg = Invoke-ReportGeneratorDirect -InputJsonPath $rgInputBad -OutputDir $rgOut1
    Assert-True ($rRg.ExitCode -eq 1) "[REPORT_EQ] missing fields should fail"

    Run-TestScript (Join-Path $testDir "test_invoke_governed_migration.ps1")

    # 2) MOCK activation flow
    $mockInput = Join-Path $tmpRoot "mock_input.json"
    $mockOut = Join-Path $tmpRoot "out_mock"
    (New-InputPayload "MOCK" | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $mockInput -Encoding UTF8
    $r = Invoke-Wrapper -InputJsonPath $mockInput -OutputDir $mockOut
    Assert-True ($r.ExitCode -eq 0) "[MOCK] expected exit 0"
    Assert-True ($r.Object.success -eq $true) "[MOCK] expected success=true"
    Assert-True ($r.Object.executed -eq $false) "[MOCK] expected executed=false"
    Assert-True ($r.Object.reportGenerated -eq $true) "[MOCK] expected reportGenerated=true"
    Assert-True (Test-Path -LiteralPath (Join-Path $mockOut "execution_report.json")) "[MOCK] missing execution_report.json"
    Assert-True (Test-Path -LiteralPath (Join-Path $mockOut "execution_report.md")) "[MOCK] missing execution_report.md"
    Assert-True (Test-Path -LiteralPath (Join-Path $mockOut "risk_summary.md")) "[MOCK] missing risk_summary.md"
    Assert-True (Test-Path -LiteralPath (Join-Path $mockOut "schema_diff_summary.md")) "[MOCK] missing schema_diff_summary.md"
    Assert-True (Test-Path -LiteralPath (Join-Path $mockOut "recovery_readiness_summary.md")) "[MOCK] missing recovery_readiness_summary.md"

    # 3) DRY_RUN activation flow
    $dryInput = Join-Path $tmpRoot "dry_input.json"
    $dryOut = Join-Path $tmpRoot "out_dry"
    (New-InputPayload "DRY_RUN" | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $dryInput -Encoding UTF8
    $r = Invoke-Wrapper -InputJsonPath $dryInput -OutputDir $dryOut
    Assert-True ($r.ExitCode -eq 0) "[DRY_RUN] expected exit 0"
    Assert-True ($r.Object.success -eq $true) "[DRY_RUN] expected success=true"
    Assert-True ($r.Object.executed -eq $false) "[DRY_RUN] expected executed=false"

    # 4) Safety negative tests
    $bad = New-InputPayload "LIVE_EXECUTE"
    $p = Join-Path $tmpRoot "bad_live.json"
    ($bad | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-Wrapper -InputJsonPath $p -OutputDir (Join-Path $tmpRoot "out_bad_live")
    Assert-True ($r.ExitCode -eq 1) "[LIVE_EXECUTE] expected fail"

    $bad = New-InputPayload "MOCK"; $bad.approval = @{ approved = $false }
    $p = Join-Path $tmpRoot "bad_approval.json"
    ($bad | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-Wrapper -InputJsonPath $p -OutputDir (Join-Path $tmpRoot "out_bad_approval")
    Assert-True ($r.ExitCode -eq 1) "[approval=false] expected fail"

    $bad = New-InputPayload "MOCK"; $bad.recoveryReadiness = @{ ready = $false }
    $p = Join-Path $tmpRoot "bad_recovery.json"
    ($bad | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-Wrapper -InputJsonPath $p -OutputDir (Join-Path $tmpRoot "out_bad_recovery")
    Assert-True ($r.ExitCode -eq 1) "[recoveryReadiness=false] expected fail"

    $bad = New-InputPayload "MOCK"; $bad.riskSummary = @{ allowed = $false }
    $p = Join-Path $tmpRoot "bad_risk.json"
    ($bad | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-Wrapper -InputJsonPath $p -OutputDir (Join-Path $tmpRoot "out_bad_risk")
    Assert-True ($r.ExitCode -eq 1) "[riskSummary.allowed=false] expected fail"

    $bad = New-InputPayload "MOCK"; $bad.schemaDiffSummary = @{ safe = $false }
    $p = Join-Path $tmpRoot "bad_schema.json"
    ($bad | ConvertTo-Json -Depth 20) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-Wrapper -InputJsonPath $p -OutputDir (Join-Path $tmpRoot "out_bad_schema")
    Assert-True ($r.ExitCode -eq 1) "[schemaDiffSummary.safe=false] expected fail"

    # 5) No tenant_service_limits dependency check
    Assert-True ($r.Raw -notmatch "tenant_service_limits") "[safety] unexpected tenant_service_limits dependency mention"

    Write-Output "PASS: activation_test_suite.ps1"
    exit 0
}
catch {
    Write-Error $_.Exception.Message
    exit 1
}
finally {
    Remove-Item -LiteralPath $tmpRoot -Recurse -Force -ErrorAction SilentlyContinue
}

