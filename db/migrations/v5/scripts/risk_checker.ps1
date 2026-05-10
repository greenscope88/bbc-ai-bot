param(
    [Parameter(Mandatory = $true)]
    [string]$ProposalPath
)

# Plan Mode only. This script must not execute SQL.
# This script only reads proposal JSON and calculates risk.

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
$declaredRiskLevel = [string]$proposal.riskLevel
$affectedSystems = @()
if ($null -ne $proposal.affectedSystems) {
    $affectedSystems = @($proposal.affectedSystems)
}

$risk = "Low"
$reasons = @()
$riskWarning = ""

switch ($action) {
    "ADD_COLUMN" {
        if ($nullable -eq $true) {
            $risk = "Low"
            $reasons += "ADD_COLUMN with nullable=true defaults to Low."
        }
        else {
            $risk = "Medium"
            $reasons += "ADD_COLUMN with nullable=false is at least Medium."
        }
    }
    "ADD_INDEX" {
        $risk = "Low"
        $reasons += "ADD_INDEX defaults to Low and may be raised by conditions."
    }
    "ADD_FOREIGN_KEY" {
        $risk = "Medium"
        $reasons += "ADD_FOREIGN_KEY is at least Medium."
    }
    "ALTER_COLUMN" {
        $risk = "High"
        $reasons += "ALTER_COLUMN is High."
    }
    "DROP_COLUMN" {
        $risk = "High"
        $reasons += "DROP_COLUMN is High."
    }
    "DATA_MIGRATION" {
        $risk = "High"
        $reasons += "DATA_MIGRATION is High."
    }
    "DROP_TABLE" {
        $risk = "Critical"
        $reasons += "DROP_TABLE is Critical."
    }
    "DROP_DATABASE" {
        $risk = "Critical"
        $reasons += "DROP_DATABASE is Critical."
    }
    "TRUNCATE_TABLE" {
        $risk = "Critical"
        $reasons += "TRUNCATE_TABLE action is Critical."
    }
    "DELETE" {
        $risk = "Critical"
        $reasons += "DELETE action is Critical."
    }
    "UPDATE" {
        $risk = "Critical"
        $reasons += "UPDATE action is Critical."
    }
    "MERGE" {
        $risk = "Critical"
        $reasons += "MERGE action is Critical."
    }
    default {
        $risk = "Medium"
        $reasons += "Unknown action defaults to Medium."
    }
}

$tenantScopeUnclear = ($tenantScope -eq "unclear")
if ($snoRequired -and $tenantScopeUnclear) {
    $risk = Max-Risk $risk "High"
    $reasons += "snoRequired=true with unclear tenantScope raises risk to at least High."
}

$tenantScopeAllTenants = ($tenantScope -eq "all_tenants")
if ($tenantScopeAllTenants -and @("UPDATE", "DELETE", "MERGE", "DATA_MIGRATION") -contains $action) {
    $risk = "Critical"
    $reasons += "all_tenants with UPDATE/DELETE/MERGE/DATA_MIGRATION is Critical."
}

$coreSystems = @("Old ASP Frontend", "Old ASP Backend", "API", "AI Query")
$hitsCoreSystem = $false
foreach ($s in $affectedSystems) {
    if ($coreSystems -contains [string]$s) {
        $hitsCoreSystem = $true
        break
    }
}

if ($hitsCoreSystem) {
    if ($action -eq "ADD_COLUMN" -and $nullable -eq $true) {
        $risk = Max-Risk $risk "Low"
        $reasons += "Core systems with nullable ADD_COLUMN can remain Low."
    }
    elseif ($action -eq "ADD_COLUMN" -and $nullable -ne $true) {
        $risk = Max-Risk $risk "Medium"
        $reasons += "Core systems with NOT NULL ADD_COLUMN are at least Medium."
    }
    elseif (@("ALTER_COLUMN", "DROP_COLUMN") -contains $action) {
        $risk = Max-Risk $risk "High"
        $reasons += "Core systems with ALTER_COLUMN/DROP_COLUMN are at least High."
    }
    elseif (@("UPDATE", "DELETE", "MERGE", "DROP_TABLE", "TRUNCATE_TABLE") -contains $action) {
        $risk = "Critical"
        $reasons += "Core systems with UPDATE/DELETE/MERGE/DROP_TABLE/TRUNCATE_TABLE are Critical."
    }
}

$autoExecutable = $true
if ($risk -eq "Low") {
    $autoExecutable = $true
    $reasons += "Low can be auto-executable."
}
elseif ($risk -eq "Medium") {
    $autoExecutable = $true
    $reasons += "Medium may be auto-executable by current rule set."
}
elseif ($risk -eq "High" -or $risk -eq "Critical") {
    $autoExecutable = $false
    if ($risk -eq "Critical") {
        $reasons += "Critical is blocked by default."
    }
    else {
        $reasons += "High is not auto-executable."
    }
}

$riskUnderestimated = $false
if ((Get-RiskScore $declaredRiskLevel) -lt (Get-RiskScore $risk)) {
    $riskUnderestimated = $true
    $riskWarning = "Declared riskLevel is lower than calculatedRiskLevel."
    $reasons += "Declared riskLevel is lower than calculatedRiskLevel."
}

$result = [PSCustomObject]@{
    requestId           = [string]$proposal.requestId
    action              = $action
    declaredRiskLevel   = $declaredRiskLevel
    calculatedRiskLevel = $risk
    riskUnderestimated  = $riskUnderestimated
    autoExecutable      = $autoExecutable
    riskWarning         = $riskWarning
    reason              = ($reasons -join " ")
}

$result | ConvertTo-Json -Depth 3
exit 0
