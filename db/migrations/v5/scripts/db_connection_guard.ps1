param(
    [Parameter(Mandatory = $true)]
    [string]$ProposalPath
)

# Plan Mode only. This script must not connect to SQL Server.
# Plan Mode only. This script must not execute SQL.

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
$server = if ($null -ne $proposal.server) { [string]$proposal.server } else { "" }
$databaseRaw = $proposal.database
$database = if ($null -ne $databaseRaw) { [string]$databaseRaw } else { "" }

$requiresApproval = $false
if ($null -ne $proposal.PSObject.Properties["requiresApproval"]) {
    $requiresApproval = [bool]$proposal.requiresApproval
}

$allowedEnvironments = @("DEV", "TEST", "PROD")
if ($allowedEnvironments -notcontains $environment) {
    $warnings.Add("environment invalid")
}

$allowedServer = "HostB-SQLServer"
if ($server -ne $allowedServer) {
    $warnings.Add("server is not in allowed server list")
}

$dbTrimmed = $database.Trim()
if ([string]::IsNullOrEmpty($dbTrimmed)) {
    $warnings.Add("database is required")
}
else {
    $systemDatabases = @("master", "tempdb", "model", "msdb")
    foreach ($sys in $systemDatabases) {
        if ($dbTrimmed.Equals($sys, [System.StringComparison]::OrdinalIgnoreCase)) {
            $warnings.Add("system database is not allowed")
            break
        }
    }
}

if ($environment -eq "PROD" -and -not $requiresApproval) {
    $warnings.Add("PROD proposal requires approval")
}

$status = if ($warnings.Count -eq 0) { "PASS" } else { "FAIL" }

$conclusion = if ($status -eq "PASS") {
    "DB Connection Guard plan-only check passed. No SQL Server connection was made."
}
else {
    "DB Connection Guard plan-only check failed. No SQL Server connection was made."
}

$result = [PSCustomObject]@{
    requestId        = $requestId
    environment      = $environment
    server           = $server
    database         = $dbTrimmed
    requiresApproval = $requiresApproval
    status           = $status
    warnings         = @($warnings)
    conclusion       = $conclusion
}

$result | ConvertTo-Json -Depth 4
exit 0
