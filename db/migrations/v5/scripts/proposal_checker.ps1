param(
    [Parameter(Mandatory = $true)]
    [string]$ProposalPath
)

# Plan Mode only. This script must not execute SQL.
# This checker only reads proposal JSON and validates required fields.

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

$requiredFields = @(
    "requestId",
    "environment",
    "server",
    "database",
    "table",
    "action",
    "column",
    "dataType",
    "nullable",
    "reason",
    "tenantScope",
    "snoRequired",
    "affectedSystems",
    "riskLevel",
    "generatedBy",
    "createdAt",
    "requiresApproval",
    "rollbackPlanRequired"
)

$missingFields = @()
foreach ($field in $requiredFields) {
    if (-not ($proposal.PSObject.Properties.Name -contains $field)) {
        $missingFields += $field
    }
}

if ($missingFields.Count -gt 0) {
    Write-Output ("FAIL: Missing required fields: " + ($missingFields -join ", "))
    exit 1
}

Write-Output "PASS: Proposal required fields are complete."
exit 0
