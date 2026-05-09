param(
    [Parameter(Mandatory = $true)]
    [string]$ProposalPath,
    [Parameter(Mandatory = $true)]
    [string]$OutputPath
)

# Plan Mode only. This script must not execute SQL.
# This script reads proposal JSON and generates a Markdown plan report only.

if (-not (Test-Path -Path $ProposalPath)) {
    Write-Output "FAIL: Proposal file not found: $ProposalPath"
    exit 1
}

try {
    $raw = Get-Content -Path $ProposalPath -Raw -Encoding UTF8
    $proposal = $raw | ConvertFrom-Json
}
catch {
    Write-Output "FAIL: Invalid JSON format."
    exit 1
}

function Get-RiskScore([string]$risk) {
    switch ($risk) {
        "Low" { return 1 }
        "Medium" { return 2 }
        "High" { return 3 }
        "Critical" { return 4 }
        default { return 0 }
    }
}

function Max-Risk([string]$a, [string]$b) {
    if ((Get-RiskScore $a) -ge (Get-RiskScore $b)) { return $a }
    return $b
}

$action = [string]$proposal.action
$nullable = $proposal.nullable
$tenantScope = [string]$proposal.tenantScope
$snoRequired = [bool]$proposal.snoRequired
$affectedSystems = @()
if ($null -ne $proposal.affectedSystems) {
    $affectedSystems = @($proposal.affectedSystems)
}

$risk = "Low"
$riskReasons = @()

switch ($action) {
    "ADD_COLUMN" {
        if ($nullable -eq $true) {
            $risk = "Low"
            $riskReasons += "ADD_COLUMN with nullable=true defaults to Low."
        }
        else {
            $risk = "Medium"
            $riskReasons += "ADD_COLUMN with nullable=false is at least Medium."
        }
    }
    "ADD_INDEX" {
        $risk = "Low"
        $riskReasons += "ADD_INDEX defaults to Low and may be raised by conditions."
    }
    "ADD_FOREIGN_KEY" {
        $risk = "Medium"
        $riskReasons += "ADD_FOREIGN_KEY is at least Medium."
    }
    "ALTER_COLUMN" {
        $risk = "High"
        $riskReasons += "ALTER_COLUMN is High."
    }
    "DROP_COLUMN" {
        $risk = "High"
        $riskReasons += "DROP_COLUMN is High."
    }
    "DATA_MIGRATION" {
        $risk = "High"
        $riskReasons += "DATA_MIGRATION is High."
    }
    "DROP_TABLE" {
        $risk = "Critical"
        $riskReasons += "DROP_TABLE is Critical."
    }
    "DROP_DATABASE" {
        $risk = "Critical"
        $riskReasons += "DROP_DATABASE is Critical."
    }
    "TRUNCATE_TABLE" {
        $risk = "Critical"
        $riskReasons += "Truncate-table action is Critical."
    }
    "DELETE" {
        $risk = "Critical"
        $riskReasons += "DELETE action is Critical."
    }
    "UPDATE" {
        $risk = "Critical"
        $riskReasons += "UPDATE action is Critical."
    }
    "MERGE" {
        $risk = "Critical"
        $riskReasons += "MERGE action is Critical."
    }
    default {
        $risk = "Medium"
        $riskReasons += "Unknown action defaults to Medium."
    }
}

$tenantScopeUnclear = [string]::IsNullOrWhiteSpace($tenantScope) -or @("unknown", "unspecified", "unclear") -contains $tenantScope.ToLowerInvariant()
if ($snoRequired -and $tenantScopeUnclear) {
    $risk = Max-Risk $risk "High"
    $riskReasons += "snoRequired=true with unclear tenantScope raises risk to at least High."
}

$coreSystems = @("Old ASP Frontend", "Old ASP Backend", "API", "AI Query")
$hitsCoreSystem = $false
foreach ($s in $affectedSystems) {
    if ($coreSystems -contains [string]$s) {
        $hitsCoreSystem = $true
        break
    }
}

$isNullableAddColumn = ($action -eq "ADD_COLUMN" -and $nullable -eq $true)
if ($hitsCoreSystem -and -not $isNullableAddColumn) {
    $risk = Max-Risk $risk "Medium"
    $riskReasons += "Affected core systems require risk not lower than Medium unless nullable ADD_COLUMN."
}

$autoExecutable = $true
if ($risk -eq "High" -or $risk -eq "Critical") {
    $autoExecutable = $false
}

$requiredArtifacts = @(
    "DB Change Request",
    "proposal JSON",
    "Plan Report",
    "risk classification",
    "migration SQL",
    ".bak backup",
    "before schema-only.sql",
    "after schema-only.sql",
    "schema diff report",
    "audit log",
    "approval code",
    "rollback plan",
    "Git commit record"
)

$safetyWarnings = @(
    "Plan Mode only: Do not execute any SQL in this step.",
    "Do not connect to production database from this script.",
    "High/Critical changes are not auto-executable.",
    "Critical is blocked by default.",
    "Actions like UPDATE, DELETE, and MERGE are high-risk data operations and require strict review.",
    "Do not bypass Migration 5.x governance controls."
)

$affectedSystemsText = if ($affectedSystems.Count -gt 0) { $affectedSystems -join ", " } else { "(none)" }
$requiredArtifactsLines = ($requiredArtifacts | ForEach-Object { "- $_" }) -join "`r`n"
$safetyWarningsLines = ($safetyWarnings | ForEach-Object { "- $_" }) -join "`r`n"
$riskReasonText = $riskReasons -join " "
$timestamp = (Get-Date).ToString("yyyy-MM-ddTHH:mm:ss")

$report = @"
# Plan Report

- generatedAt: $timestamp
- requestId: $($proposal.requestId)
- environment: $($proposal.environment)
- server: $($proposal.server)
- database: $($proposal.database)
- table: $($proposal.table)
- action: $action
- column: $($proposal.column)
- dataType: $($proposal.dataType)
- nullable: $nullable
- reason: $($proposal.reason)
- affectedSystems: $affectedSystemsText
- snoRequired: $snoRequired
- calculatedRiskLevel: $risk
- autoExecutable: $autoExecutable

## requiredArtifacts
$requiredArtifactsLines

## safetyWarnings
$safetyWarningsLines

## conclusion
This is Plan Mode only. No SQL was executed.

## notes
$riskReasonText
"@

$outputDir = Split-Path -Path $OutputPath -Parent
if (-not [string]::IsNullOrWhiteSpace($outputDir) -and -not (Test-Path -Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

Set-Content -Path $OutputPath -Value $report -Encoding UTF8
Write-Output "PASS: Plan report generated at $OutputPath"
exit 0
