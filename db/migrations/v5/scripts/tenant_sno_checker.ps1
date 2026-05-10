param(
    [Parameter(Mandatory = $true)]
    [string]$ProposalPath
)

# Plan Mode only. This script must not connect to SQL Server.
# Plan Mode only. This script must not execute SQL.
# Plan Mode only. This script must not modify any tenant data.

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

$warnings = [System.Collections.Generic.List[string]]::new()

$requestId = if ($null -ne $proposal.requestId) { [string]$proposal.requestId } else { "" }
$environment = if ($null -ne $proposal.environment) { [string]$proposal.environment } else { "" }
$action = if ($null -ne $proposal.action) { [string]$proposal.action } else { "" }
$tenantScope = if ($null -ne $proposal.tenantScope) { [string]$proposal.tenantScope } else { "" }
$affectedSystems = @()
if ($null -ne $proposal.affectedSystems) {
    $affectedSystems = @($proposal.affectedSystems | ForEach-Object { [string]$_ })
}

$allowedScopes = @("single_or_multi_tenant", "all_tenants", "unclear")
if ($allowedScopes -notcontains $tenantScope) {
    $warnings.Add("tenantScope invalid")
}

$snoProp = $proposal.PSObject.Properties["snoRequired"]
if ($null -eq $snoProp) {
    $warnings.Add("snoRequired must be boolean")
    $snoIsBool = $false
    $snoFalse = $false
}
else {
    $snoVal = $proposal.snoRequired
    if ($snoVal -isnot [bool]) {
        $warnings.Add("snoRequired must be boolean")
        $snoIsBool = $false
        $snoFalse = $false
    }
    else {
        $snoIsBool = $true
        $snoFalse = (-not [bool]$snoVal)
    }
}

if ($tenantScope -eq "unclear") {
    $warnings.Add("tenantScope unclear requires manual review")
}

$highRiskForAllTenants = @(
    "UPDATE",
    "DELETE",
    "MERGE",
    "DATA_MIGRATION",
    "DROP_TABLE",
    "DROP_DATABASE",
    "TRUNCATE_TABLE",
    "ALTER_COLUMN",
    "DROP_COLUMN"
)
if ($tenantScope -eq "all_tenants" -and $highRiskForAllTenants -contains $action) {
    $warnings.Add("all_tenants with high-risk action is not allowed in plan-only validation")
}

$dataChangingActions = @("UPDATE", "DELETE", "MERGE", "DATA_MIGRATION")
if ($snoIsBool -and $snoFalse -and $dataChangingActions -contains $action) {
    $warnings.Add("data-changing action requires sno guard")
}

if ($environment -eq "PROD" -and $tenantScope -ne "single_or_multi_tenant") {
    $warnings.Add("PROD proposal must use explicit tenant scope")
}

$coreAffected = @("API", "AI Query", "Old ASP Frontend", "Old ASP Backend")
$hitsCoreAffected = $false
foreach ($s in $affectedSystems) {
    if ($coreAffected -contains $s) {
        $hitsCoreAffected = $true
        break
    }
}
if ($tenantScope -eq "unclear" -and $hitsCoreAffected) {
    $warnings.Add("tenant scope must be clear when affected systems include API or legacy systems")
}

$status = if ($warnings.Count -eq 0) { "PASS" } else { "FAIL" }

$conclusion = if ($status -eq "PASS") {
    "Tenant / sno plan-only check passed. No SQL Server connection was made. No SQL was executed."
}
else {
    "Tenant / sno plan-only check failed. No SQL Server connection was made. No SQL was executed."
}

$result = [PSCustomObject]@{
    requestId        = $requestId
    environment      = $environment
    action           = $action
    tenantScope      = $tenantScope
    snoRequired      = if ($snoIsBool) { [bool]$proposal.snoRequired } else { $false }
    affectedSystems  = $affectedSystems
    status           = $status
    warnings         = @($warnings)
    conclusion       = $conclusion
}

$result | ConvertTo-Json -Depth 5
exit 0
