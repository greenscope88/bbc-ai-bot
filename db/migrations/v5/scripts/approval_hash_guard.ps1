param(
    [Parameter(Mandatory = $true)]
    [string]$ProposalPath,
    [Parameter(Mandatory = $true)]
    [string]$PreflightReportPath
)

# Plan Mode only. This script must not connect to SQL Server.
# Plan Mode only. This script must not execute SQL.
# Plan Mode only. This script must not modify any database.
# Plan Mode only. This script only validates approval and file hashes.

$warnings = [System.Collections.Generic.List[string]]::new()

$resolvedProposal = $null
$resolvedPreflight = $null
try { $resolvedProposal = (Resolve-Path -LiteralPath $ProposalPath).Path } catch { $resolvedProposal = $null }
try { $resolvedPreflight = (Resolve-Path -LiteralPath $PreflightReportPath).Path } catch { $resolvedPreflight = $null }

$proposalHash = ""
$preflightReportHash = ""

if (-not $resolvedProposal -or -not (Test-Path -LiteralPath $resolvedProposal)) {
    $warnings.Add("proposal file not found")
}
else {
    $proposalHash = (Get-FileHash -LiteralPath $resolvedProposal -Algorithm SHA256).Hash
}

if (-not $resolvedPreflight -or -not (Test-Path -LiteralPath $resolvedPreflight)) {
    $warnings.Add("preflight report file not found")
}
else {
    $preflightReportHash = (Get-FileHash -LiteralPath $resolvedPreflight -Algorithm SHA256).Hash
}

$requestId = ""
$environment = ""
$riskLevel = ""
$requiresApproval = $false
$requiresApprovalIsBool = $false
$approvalCodeStr = ""
$approvalCodePresent = $false
$rollbackPlanRequired = $false
$rollbackIsBool = $false

if ($resolvedProposal -and (Test-Path -LiteralPath $resolvedProposal) -and $warnings -notcontains "proposal file not found") {
    try {
        $raw = Get-Content -LiteralPath $resolvedProposal -Raw -Encoding UTF8
        $proposal = $raw | ConvertFrom-Json
    }
    catch {
        $proposal = $null
        $warnings.Add("proposal JSON parse failed")
    }

    if ($null -ne $proposal) {
        $requestId = if ($null -ne $proposal.requestId) { [string]$proposal.requestId } else { "" }
        $environment = if ($null -ne $proposal.environment) { [string]$proposal.environment } else { "" }
        $riskLevel = if ($null -ne $proposal.riskLevel) { [string]$proposal.riskLevel } else { "" }

        $raProp = $proposal.PSObject.Properties["requiresApproval"]
        if ($null -eq $raProp) {
            $warnings.Add("requiresApproval must be boolean")
        }
        elseif ($proposal.requiresApproval -isnot [bool]) {
            $warnings.Add("requiresApproval must be boolean")
        }
        else {
            $requiresApprovalIsBool = $true
            $requiresApproval = [bool]$proposal.requiresApproval
        }

        $rbProp = $proposal.PSObject.Properties["rollbackPlanRequired"]
        if ($null -eq $rbProp) {
            $warnings.Add("rollbackPlanRequired must be boolean")
        }
        elseif ($proposal.rollbackPlanRequired -isnot [bool]) {
            $warnings.Add("rollbackPlanRequired must be boolean")
        }
        else {
            $rollbackIsBool = $true
            $rollbackPlanRequired = [bool]$proposal.rollbackPlanRequired
        }

        $ac = $proposal.approvalCode
        if ($null -ne $ac -and -not [string]::IsNullOrWhiteSpace([string]$ac)) {
            $approvalCodeStr = [string]$ac
            $approvalCodePresent = $true
        }
        else {
            $approvalCodeStr = ""
            $approvalCodePresent = $false
        }

        if ($requiresApprovalIsBool -and $rollbackIsBool) {
            if ($riskLevel -eq "High" -or $riskLevel -eq "Critical") {
                if (-not $requiresApproval) {
                    $warnings.Add("high risk proposal requires approval")
                }
                if (-not $approvalCodePresent) {
                    $warnings.Add("high risk proposal requires approvalCode")
                }
                if (-not $rollbackPlanRequired) {
                    $warnings.Add("high risk proposal requires rollback plan")
                }
            }

            if ($environment -eq "PROD") {
                if (-not $requiresApproval) {
                    $warnings.Add("PROD proposal requires approval")
                }
                if (-not $approvalCodePresent) {
                    $warnings.Add("PROD proposal requires approvalCode")
                }
                if (-not $rollbackPlanRequired) {
                    $warnings.Add("PROD proposal requires rollback plan")
                }
            }
        }

        if ($approvalCodePresent) {
            if (-not [regex]::IsMatch($approvalCodeStr, '^APPROVED-\d{8}-\d{4}$')) {
                $warnings.Add("approvalCode format invalid")
            }
        }
    }
}

$status = if ($warnings.Count -eq 0) { "PASS" } else { "FAIL" }

$conclusion = if ($status -eq "PASS") {
    "Approval / Hash Guard plan-only check passed. No SQL Server connection was made. No SQL was executed."
}
else {
    "Approval / Hash Guard plan-only check failed. No SQL Server connection was made. No SQL was executed."
}

$result = [PSCustomObject]@{
    requestId             = $requestId
    environment           = $environment
    riskLevel             = $riskLevel
    requiresApproval      = if ($requiresApprovalIsBool) { $requiresApproval } else { $false }
    approvalCodePresent   = $approvalCodePresent
    rollbackPlanRequired  = if ($rollbackIsBool) { $rollbackPlanRequired } else { $false }
    proposalPath          = if ($resolvedProposal) { $resolvedProposal } else { $ProposalPath }
    preflightReportPath   = if ($resolvedPreflight) { $resolvedPreflight } else { $PreflightReportPath }
    proposalHash          = $proposalHash
    preflightReportHash   = $preflightReportHash
    status                = $status
    warnings              = @($warnings)
    conclusion            = $conclusion
}

$result | ConvertTo-Json -Depth 5
exit 0
