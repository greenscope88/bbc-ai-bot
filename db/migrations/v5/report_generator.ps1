param(
    [Parameter(Mandatory = $true)]
    [string]$InputJsonPath,
    [Parameter(Mandatory = $true)]
    [string]$OutputDir
)

# Safety: This generator must not connect to SQL Server, execute SQL, or read .env.

function Fail([string]$Reason) {
    $obj = [PSCustomObject]@{
        success         = $false
        reportGenerated = $false
        reason          = $Reason
    }
    $obj | ConvertTo-Json -Depth 5
    exit 1
}

try {
    $resolvedInput = (Resolve-Path -LiteralPath $InputJsonPath -ErrorAction Stop).Path
} catch {
    Fail "InputJsonPath not found"
}

if (-not (Test-Path -LiteralPath $resolvedInput)) {
    Fail "InputJsonPath not found"
}

try {
    $raw = Get-Content -LiteralPath $resolvedInput -Raw -Encoding UTF8
    $input = $raw | ConvertFrom-Json
} catch {
    Fail "Invalid JSON input"
}

$required = @(
    "migrationId",
    "proposalId",
    "environment",
    "operator",
    "approval",
    "riskSummary",
    "recoveryReadiness",
    "schemaDiffSummary",
    "executionResult"
)

$missing = @()
foreach ($k in $required) {
    if (-not ($input.PSObject.Properties.Name -contains $k)) {
        $missing += $k
    }
}
if ($missing.Count -gt 0) {
    Fail ("Missing required fields: " + ($missing -join ", "))
}

try {
    if (-not (Test-Path -LiteralPath $OutputDir)) {
        New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
    }
    $resolvedOut = (Resolve-Path -LiteralPath $OutputDir -ErrorAction Stop).Path
} catch {
    Fail "Failed to create OutputDir"
}

function To-PrettyText($value) {
    if ($null -eq $value) { return "(null)" }
    if ($value -is [string]) { return $value }
    try { return ($value | ConvertTo-Json -Depth 20) } catch { return ([string]$value) }
}

$files = @(
    "execution_report.json",
    "execution_report.md",
    "risk_summary.md",
    "schema_diff_summary.md",
    "recovery_readiness_summary.md"
)

try {
    $executionReportObj = [PSCustomObject]@{
        migrationId        = [string]$input.migrationId
        proposalId         = [string]$input.proposalId
        environment        = [string]$input.environment
        operator           = [string]$input.operator
        approval           = $input.approval
        riskSummary        = $input.riskSummary
        recoveryReadiness  = $input.recoveryReadiness
        schemaDiffSummary  = $input.schemaDiffSummary
        executionResult    = $input.executionResult
        generatedAt        = (Get-Date).ToString("o")
    }

    $jsonPath = Join-Path $resolvedOut "execution_report.json"
    $executionReportObj | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath $jsonPath -Encoding UTF8

    $mdMain = @"
# Phase 4 Execution Report (Governance)

## Metadata

- migrationId: `$($input.migrationId)`
- proposalId: `$($input.proposalId)`
- environment: `$($input.environment)`
- operator: `$($input.operator)`
- generatedAt: `$(Get-Date -Format o)`

## Approval

```
$(To-PrettyText $input.approval)
```

## Risk Summary

```
$(To-PrettyText $input.riskSummary)
```

## Schema Diff Summary

```
$(To-PrettyText $input.schemaDiffSummary)
```

## Recovery Readiness

```
$(To-PrettyText $input.recoveryReadiness)
```

## Execution Result

```
$(To-PrettyText $input.executionResult)
```

> Safety: This report is generated offline. No SQL was executed. No SQL Server connection was made.
"@

    Set-Content -LiteralPath (Join-Path $resolvedOut "execution_report.md") -Value $mdMain -Encoding UTF8
    Set-Content -LiteralPath (Join-Path $resolvedOut "risk_summary.md") -Value ("# Risk Summary`n`n``````n" + (To-PrettyText $input.riskSummary) + "`n`````n") -Encoding UTF8
    Set-Content -LiteralPath (Join-Path $resolvedOut "schema_diff_summary.md") -Value ("# Schema Diff Summary`n`n``````n" + (To-PrettyText $input.schemaDiffSummary) + "`n`````n") -Encoding UTF8
    Set-Content -LiteralPath (Join-Path $resolvedOut "recovery_readiness_summary.md") -Value ("# Recovery Readiness Summary`n`n``````n" + (To-PrettyText $input.recoveryReadiness) + "`n`````n") -Encoding UTF8
} catch {
    Fail "Failed to write reports"
}

$result = [PSCustomObject]@{
    success         = $true
    reportGenerated = $true
    outputDir       = $resolvedOut
    files           = $files
    timestamp       = (Get-Date).ToString("o")
}
$result | ConvertTo-Json -Depth 5
exit 0

