param(
    [Parameter(Mandatory = $true)]
    [string]$InputJsonPath,
    [Parameter(Mandatory = $true)]
    [string]$OutputDir
)

function Fail([string]$Reason) {
    [PSCustomObject]@{
        success  = $false
        executed = $false
        reason   = $Reason
    } | ConvertTo-Json -Depth 10
    exit 1
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

$required = @("migrationId","proposalId","environment","mode","operator","approval","riskSummary","recoveryReadiness","schemaDiffSummary","executionPlan")
$missing = @()
foreach ($k in $required) {
    if (-not ($input.PSObject.Properties.Name -contains $k)) { $missing += $k }
}
if ($missing.Count -gt 0) { Fail ("Missing required fields: " + ($missing -join ", ")) }

$mode = [string]$input.mode
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

