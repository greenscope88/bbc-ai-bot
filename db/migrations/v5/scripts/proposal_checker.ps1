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

$invalidFields = @()

function Add-InvalidField([string]$FieldName, [string]$Reason) {
    $script:invalidFields += "${FieldName}: $Reason"
}

if ($missingFields.Count -eq 0) {
    $environmentWhitelist = @("DEV", "TEST", "PROD")
    $actionWhitelist = @(
        "ADD_COLUMN",
        "ADD_INDEX",
        "ADD_FOREIGN_KEY",
        "ALTER_COLUMN",
        "DROP_COLUMN",
        "DATA_MIGRATION",
        "DROP_TABLE",
        "DROP_DATABASE",
        "TRUNCATE_TABLE",
        "DELETE",
        "UPDATE",
        "MERGE"
    )
    $riskLevelWhitelist = @("Low", "Medium", "High", "Critical")
    $tableLevelActions = @("DROP_TABLE", "DROP_DATABASE", "TRUNCATE_TABLE")

    $environment = [string]$proposal.environment
    if (-not ($environmentWhitelist -contains $environment)) {
        Add-InvalidField "environment" "environment invalid (allowed: DEV, TEST, PROD)"
    }

    $action = [string]$proposal.action
    if (-not ($actionWhitelist -contains $action)) {
        Add-InvalidField "action" "action invalid (not in whitelist)"
    }

    $riskLevel = [string]$proposal.riskLevel
    if (-not ($riskLevelWhitelist -contains $riskLevel)) {
        Add-InvalidField "riskLevel" "riskLevel invalid (allowed: Low, Medium, High, Critical)"
    }

    if ($proposal.nullable -eq $null) {
        if (-not ($tableLevelActions -contains $action)) {
            Add-InvalidField "nullable" "must be boolean for non-table-level action"
        }
    }
    elseif ($proposal.nullable -isnot [bool]) {
        Add-InvalidField "nullable" "must be boolean"
    }

    if ($proposal.snoRequired -isnot [bool]) {
        Add-InvalidField "snoRequired" "must be boolean"
    }

    if ($proposal.requiresApproval -isnot [bool]) {
        Add-InvalidField "requiresApproval" "must be boolean"
    }

    if ($proposal.rollbackPlanRequired -isnot [bool]) {
        Add-InvalidField "rollbackPlanRequired" "must be boolean"
    }

    if ($proposal.affectedSystems -isnot [System.Array]) {
        Add-InvalidField "affectedSystems" "must be array"
    }

    if ($environment -eq "PROD" -and $proposal.requiresApproval -ne $true) {
        Add-InvalidField "requiresApproval" "must be true when environment is PROD"
    }
}

if ($missingFields.Count -gt 0 -or $invalidFields.Count -gt 0) {
    Write-Output "FAIL: Proposal validation failed."
    Write-Output "Missing fields:"
    if ($missingFields.Count -gt 0) {
        foreach ($field in $missingFields) {
            Write-Output "- $field"
        }
    }
    else {
        Write-Output "- (none)"
    }

    Write-Output "Invalid fields:"
    if ($invalidFields.Count -gt 0) {
        foreach ($field in $invalidFields) {
            Write-Output "- $field"
        }
    }
    else {
        Write-Output "- (none)"
    }
    exit 1
}

Write-Output "PASS: Proposal validation passed."
exit 0
