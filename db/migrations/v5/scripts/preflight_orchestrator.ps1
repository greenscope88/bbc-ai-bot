param(
    [Parameter(Mandatory = $true)]
    [string]$ProposalPath,
    [Parameter(Mandatory = $true)]
    [string]$OutputPath
)

# Plan Mode only. This script must not connect to SQL Server.
# Plan Mode only. This script must not execute SQL.
# Plan Mode only. This script must not modify any database.
# Plan Mode only. This script only orchestrates checker scripts.

$resolvedProposal = (Resolve-Path -LiteralPath $ProposalPath).Path
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$proposalChecker = Join-Path $scriptDir "proposal_checker.ps1"
$riskChecker = Join-Path $scriptDir "risk_checker.ps1"
$dbGuard = Join-Path $scriptDir "db_connection_guard.ps1"
$tenantSno = Join-Path $scriptDir "tenant_sno_checker.ps1"

function Invoke-CheckerText {
    param([string]$ScriptPath, [string]$PropPath)
    $lines = & $ScriptPath -ProposalPath $PropPath 2>&1
    $code = $LASTEXITCODE
    $text = if ($null -eq $lines) { "" } else { ($lines | Out-String).Trim() }
    return @{ ExitCode = $code; Text = $text }
}

function Invoke-CheckerJson {
    param([string]$ScriptPath, [string]$PropPath)
    $lines = & $ScriptPath -ProposalPath $PropPath 2>&1
    $code = $LASTEXITCODE
    $text = if ($null -eq $lines) { "" } else { ($lines | Out-String).Trim() }
    $obj = $null
    if ($code -eq 0 -and $text) {
        try { $obj = $text | ConvertFrom-Json } catch { $obj = $null }
    }
    return @{ ExitCode = $code; Text = $text; Object = $obj }
}

$testTime = Get-Date -Format "yyyy-MM-dd HH:mm:ss K"
$bt = [char]0x60
$fence = "$bt$bt$bt"
$fenceJson = "${fence}json"

$pc = Invoke-CheckerText -ScriptPath $proposalChecker -PropPath $resolvedProposal
$proposalPass = ($pc.ExitCode -eq 0)

$rc = Invoke-CheckerJson -ScriptPath $riskChecker -PropPath $resolvedProposal
$dc = Invoke-CheckerJson -ScriptPath $dbGuard -PropPath $resolvedProposal
$tc = Invoke-CheckerJson -ScriptPath $tenantSno -PropPath $resolvedProposal

$blockingReasons = [System.Collections.Generic.List[string]]::new()
$hasFail = $false
$hasBlocked = $false

if (-not $proposalPass) {
    $blockingReasons.Add("proposal_checker failed")
    $hasFail = $true
}

$riskObj = $rc.Object
$dbObj = $dc.Object
$tnObj = $tc.Object

if ($null -ne $dbObj -and [string]$dbObj.status -eq "FAIL") {
    $blockingReasons.Add("db_connection_guard failed")
    $hasFail = $true
}

if ($null -ne $tnObj -and [string]$tnObj.status -eq "FAIL") {
    $blockingReasons.Add("tenant_sno_checker failed")
    $hasFail = $true
}

if ($null -ne $riskObj) {
    if ($riskObj.riskUnderestimated -eq $true) {
        $blockingReasons.Add("risk underestimated")
        $hasFail = $true
    }
    $calc = [string]$riskObj.calculatedRiskLevel
    if ($calc -eq "High" -or $calc -eq "Critical") {
        $blockingReasons.Add("high or critical risk requires manual governance")
        $hasBlocked = $true
    }
    if ($riskObj.autoExecutable -eq $false) {
        $blockingReasons.Add("autoExecutable is false")
        $hasBlocked = $true
    }
}

$finalStatus = if ($hasFail) { "FAIL" } elseif ($hasBlocked) { "BLOCKED" } else { "PASS" }

$outDir = Split-Path -Parent $OutputPath
if (-not (Test-Path -LiteralPath $outDir)) {
    New-Item -ItemType Directory -Path $outDir -Force | Out-Null
}

$riskSection = if ($null -eq $riskObj) {
    @"
- (could not parse JSON; exit code: $($rc.ExitCode))

$fence
$($rc.Text)
$fence
"@
}
else {
    @"
- declaredRiskLevel: ``$($riskObj.declaredRiskLevel)``
- calculatedRiskLevel: ``$($riskObj.calculatedRiskLevel)``
- riskUnderestimated: ``$($riskObj.riskUnderestimated)``
- autoExecutable: ``$($riskObj.autoExecutable)``
- riskWarning: ``$($riskObj.riskWarning)``

$fenceJson
$($rc.Text)
$fence
"@
}

$dbSection = if ($null -eq $dbObj) {
    @"
- (could not parse JSON; exit code: $($dc.ExitCode))

$fence
$($dc.Text)
$fence
"@
}
else {
    @"
$fenceJson
$($dc.Text)
$fence
"@
}

$tnSection = if ($null -eq $tnObj) {
    @"
- (could not parse JSON; exit code: $($tc.ExitCode))

$fence
$($tc.Text)
$fence
"@
}
else {
    @"
$fenceJson
$($tc.Text)
$fence
"@
}

$reasonsMd = if ($blockingReasons.Count -eq 0) {
    "- (none)"
}
else {
    ($blockingReasons | ForEach-Object { "- $_" }) -join "`n"
}

$md = @"
# Step 2-G Preflight Orchestrator Report

## 1) Test Time

- $testTime

## 2) ProposalPath

- ``$resolvedProposal``

## 3) Checkers Executed

- proposal_checker.ps1
- risk_checker.ps1
- db_connection_guard.ps1
- tenant_sno_checker.ps1

## 4) proposal_checker Result

- Exit code: $($pc.ExitCode)
- Status: $(if ($proposalPass) { "PASS" } else { "FAIL" })

$fence
$($pc.Text)
$fence

## 5) risk_checker Result

$riskSection

## 6) db_connection_guard Result

$dbSection

## 7) tenant_sno_checker Result

$tnSection

## 8) finalStatus

- ``$finalStatus``

## 9) blockingReasons

$reasonsMd

## 10) Conclusion

This is Step 2-G Preflight Orchestrator plan-only validation. No SQL Server connection was made. No SQL was executed.
"@

Set-Content -LiteralPath $OutputPath -Value $md -Encoding UTF8
exit 0
