param(
    [Parameter(Mandatory = $true)]
    [string]$ProposalPath,
    [Parameter(Mandatory = $true)]
    [string]$OutputPath,
    [Parameter(Mandatory = $false)]
    [string]$PreflightReportPath = "",
    [Parameter(Mandatory = $false)]
    [string]$ApprovalHashResultPath = ""
)

# Plan Mode only. This script must not connect to SQL Server.
# Plan Mode only. This script must not execute SQL.
# Plan Mode only. This script must not modify any production database.
# This script reads proposal JSON (and optional reports) and generates a Markdown plan report only.

$scriptDir = $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($scriptDir)) {
    $scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
}

function Resolve-FilePathOrNull([string]$p) {
    if ([string]::IsNullOrWhiteSpace($p)) { return $null }
    try {
        $r = (Resolve-Path -LiteralPath $p -ErrorAction Stop).Path
        if (Test-Path -LiteralPath $r) { return $r }
    }
    catch { }
    return $null
}

function Get-Sha256Hex([string]$filePath) {
    if (-not $filePath -or -not (Test-Path -LiteralPath $filePath)) { return "Not provided" }
    try {
        return (Get-FileHash -LiteralPath $filePath -Algorithm SHA256).Hash
    }
    catch {
        return "Unknown"
    }
}

function Invoke-ProposalCheckerStatus([string]$proposalResolved) {
    $checker = Join-Path $scriptDir "proposal_checker.ps1"
    if (-not (Test-Path -LiteralPath $checker)) { return "Unknown" }
    $tmpOut = Join-Path ([System.IO.Path]::GetTempPath()) ("planr-pc-out-" + [Guid]::NewGuid().ToString("n") + ".txt")
    $tmpErr = Join-Path ([System.IO.Path]::GetTempPath()) ("planr-pc-err-" + [Guid]::NewGuid().ToString("n") + ".txt")
    try {
        $proc = Start-Process -FilePath "powershell.exe" `
            -ArgumentList @("-NoProfile", "-ExecutionPolicy", "Bypass", "-File", $checker, "-ProposalPath", $proposalResolved) `
            -Wait -PassThru -NoNewWindow `
            -RedirectStandardOutput $tmpOut `
            -RedirectStandardError $tmpErr
        if ($proc.ExitCode -eq 0) { return "PASS" }
        return "FAIL"
    }
    catch {
        return "Unknown"
    }
    finally {
        Remove-Item -LiteralPath $tmpOut, $tmpErr -ErrorAction SilentlyContinue
    }
}

function Invoke-ScriptJsonOutput([string]$scriptName, [string]$proposalResolved) {
    $scriptPath = Join-Path $scriptDir $scriptName
    if (-not (Test-Path -LiteralPath $scriptPath)) { return $null }
    try {
        $stdout = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $scriptPath -ProposalPath $proposalResolved 2>&1 | Out-String
        if ([string]::IsNullOrWhiteSpace($stdout)) { return $null }
        return ($stdout | ConvertFrom-Json -ErrorAction Stop)
    }
    catch {
        return $null
    }
}

function Read-PreflightMarkdownFields([string]$mdText) {
    $final = "Unknown"
    $reasons = [System.Collections.Generic.List[string]]::new()
    if ([string]::IsNullOrWhiteSpace($mdText)) {
        return [PSCustomObject]@{ finalStatus = $final; blockingReasons = $reasons }
    }
    $m = [regex]::Match($mdText, '(?ms)##\s*8\)\s*finalStatus\s*\r?\n\s*-\s*`([^`]+)`')
    if ($m.Success) { $final = $m.Groups[1].Value.Trim() }

    $m2 = [regex]::Match($mdText, '(?ms)##\s*9\)\s*blockingReasons\s*\r?\n(.*?)(?=^##\s|\z)')
    if ($m2.Success) {
        $block = $m2.Groups[1].Value
        foreach ($line in ($block -split '\r?\n')) {
            $t = $line.Trim()
            if ($t -match '^-\s*(.+)$') {
                $item = $Matches[1].Trim()
                if (-not [string]::IsNullOrWhiteSpace($item) -and $item -ne '(none)') {
                    $reasons.Add($item)
                }
            }
        }
    }
    return [PSCustomObject]@{ finalStatus = $final; blockingReasons = $reasons }
}

function Read-ApprovalHashResult([string]$pathResolved, [string]$rawTextFallback) {
    $status = "Unknown"
    $warnings = [System.Collections.Generic.List[string]]::new()
    $proposalHash = "Unknown"
    $preflightReportHash = "Unknown"

    $text = $rawTextFallback
    if ($pathResolved -and (Test-Path -LiteralPath $pathResolved)) {
        try { $text = Get-Content -LiteralPath $pathResolved -Raw -Encoding UTF8 } catch { $text = $rawTextFallback }
    }
    if ([string]::IsNullOrWhiteSpace($text)) {
        return [PSCustomObject]@{
            status              = $status
            warnings            = $warnings
            proposalHash        = $proposalHash
            preflightReportHash = $preflightReportHash
        }
    }

    try {
        $obj = $text | ConvertFrom-Json -ErrorAction Stop
        if ($null -ne $obj.status) { $status = [string]$obj.status }
        if ($null -ne $obj.warnings) {
            foreach ($w in @($obj.warnings)) { if ($null -ne $w) { $warnings.Add([string]$w) } }
        }
        if ($null -ne $obj.proposalHash -and -not [string]::IsNullOrWhiteSpace([string]$obj.proposalHash)) {
            $proposalHash = [string]$obj.proposalHash
        }
        if ($null -ne $obj.preflightReportHash -and -not [string]::IsNullOrWhiteSpace([string]$obj.preflightReportHash)) {
            $preflightReportHash = [string]$obj.preflightReportHash
        }
    }
    catch {
        $status = "Unknown"
    }

    return [PSCustomObject]@{
        status              = $status
        warnings            = $warnings
        proposalHash        = $proposalHash
        preflightReportHash = $preflightReportHash
    }
}

function Invoke-ApprovalHashGuard([string]$proposalResolved, [string]$preflightResolved) {
    $guard = Join-Path $scriptDir "approval_hash_guard.ps1"
    if (-not (Test-Path -LiteralPath $guard)) { return $null }
    try {
        $stdout = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $guard `
            -ProposalPath $proposalResolved -PreflightReportPath $preflightResolved 2>&1 | Out-String
        if ([string]::IsNullOrWhiteSpace($stdout)) { return $null }
        return ($stdout | ConvertFrom-Json -ErrorAction Stop)
    }
    catch {
        return $null
    }
}

function Approval-Code-Present($proposal) {
    $ac = $proposal.approvalCode
    if ($null -eq $ac) { return $false }
    return -not [string]::IsNullOrWhiteSpace([string]$ac)
}

function Risk-Needs-ApprovalCode($proposal, [string]$calcRisk) {
    $env = if ($null -ne $proposal.environment) { [string]$proposal.environment } else { "" }
    if ($env -eq "PROD") { return $true }
    if ($calcRisk -eq "High" -or $calcRisk -eq "Critical") { return $true }
    return $false
}

# --- Proposal load ---
$resolvedProposal = Resolve-FilePathOrNull $ProposalPath
if (-not $resolvedProposal) {
    Write-Output "FAIL: Proposal file not found: $ProposalPath"
    exit 1
}

try {
    $rawProposal = Get-Content -LiteralPath $resolvedProposal -Raw -Encoding UTF8
    $proposal = $rawProposal | ConvertFrom-Json
}
catch {
    Write-Output "FAIL: Invalid JSON format."
    exit 1
}

$generatedAt = (Get-Date).ToString("yyyy-MM-ddTHH:mm:ssK")
$resolvedPreflight = Resolve-FilePathOrNull $PreflightReportPath
$resolvedApprovalPath = Resolve-FilePathOrNull $ApprovalHashResultPath

$preflightNote = if ($resolvedPreflight) { $resolvedPreflight } else { "Preflight report not provided." }
$approvalPathNote = if ($resolvedApprovalPath) { $resolvedApprovalPath } else { "Approval / Hash result not provided." }

$preflightMdText = $null
if ($resolvedPreflight) {
    try { $preflightMdText = Get-Content -LiteralPath $resolvedPreflight -Raw -Encoding UTF8 } catch { $preflightMdText = $null }
}

$pfFields = Read-PreflightMarkdownFields $preflightMdText
$preflightFinal = $pfFields.finalStatus
$blockingReasons = $pfFields.blockingReasons

# --- Run checkers (plan-only subprocess) ---
$proposalCheckerStatus = Invoke-ProposalCheckerStatus $resolvedProposal
$riskObj = Invoke-ScriptJsonOutput "risk_checker.ps1" $resolvedProposal
$dbObj = Invoke-ScriptJsonOutput "db_connection_guard.ps1" $resolvedProposal
$tenantObj = Invoke-ScriptJsonOutput "tenant_sno_checker.ps1" $resolvedProposal

$calculatedRiskLevel = if ($riskObj -and $null -ne $riskObj.calculatedRiskLevel) { [string]$riskObj.calculatedRiskLevel } else { "Unknown" }
$riskUnderestimated = $false
if ($riskObj -and $null -ne $riskObj.riskUnderestimated) {
    $riskUnderestimated = [bool]$riskObj.riskUnderestimated
}
$autoExecutable = $true
if ($riskObj -and $null -ne $riskObj.autoExecutable) {
    $autoExecutable = [bool]$riskObj.autoExecutable
}

$dbStatus = if ($dbObj -and $null -ne $dbObj.status) { [string]$dbObj.status } else { "Unknown" }
$tenantStatus = if ($tenantObj -and $null -ne $tenantObj.status) { [string]$tenantObj.status } else { "Unknown" }

# --- Approval / hash ---
$approvalGuardObj = $null
$approvalFromFile = $null
if ($resolvedApprovalPath) {
    $approvalFromFile = Read-ApprovalHashResult $resolvedApprovalPath $null
}
elseif ($resolvedPreflight) {
    $approvalGuardObj = Invoke-ApprovalHashGuard $resolvedProposal $resolvedPreflight
}

$approvalStatus = "Not provided"
$approvalWarnings = [System.Collections.Generic.List[string]]::new()
$hashProposal = "Unknown"
$hashPreflight = "Unknown"

if ($null -ne $approvalFromFile) {
    $approvalStatus = $approvalFromFile.status
    foreach ($w in $approvalFromFile.warnings) { $approvalWarnings.Add($w) }
    $hashProposal = $approvalFromFile.proposalHash
    $hashPreflight = $approvalFromFile.preflightReportHash
}
elseif ($null -ne $approvalGuardObj) {
    if ($null -ne $approvalGuardObj.status) { $approvalStatus = [string]$approvalGuardObj.status }
    if ($null -ne $approvalGuardObj.warnings) {
        foreach ($w in @($approvalGuardObj.warnings)) { if ($null -ne $w) { $approvalWarnings.Add([string]$w) } }
    }
    if ($null -ne $approvalGuardObj.proposalHash -and -not [string]::IsNullOrWhiteSpace([string]$approvalGuardObj.proposalHash)) {
        $hashProposal = [string]$approvalGuardObj.proposalHash
    }
    if ($null -ne $approvalGuardObj.preflightReportHash -and -not [string]::IsNullOrWhiteSpace([string]$approvalGuardObj.preflightReportHash)) {
        $hashPreflight = [string]$approvalGuardObj.preflightReportHash
    }
}

if ($hashProposal -eq "Unknown" -or [string]::IsNullOrWhiteSpace($hashProposal)) {
    $hashProposal = Get-Sha256Hex $resolvedProposal
}
if ($hashPreflight -eq "Unknown" -or [string]::IsNullOrWhiteSpace($hashPreflight)) {
    if ($resolvedPreflight) {
        $h = Get-Sha256Hex $resolvedPreflight
        if ($h -ne "Not provided") { $hashPreflight = $h }
    }
    else {
        $hashPreflight = "Not provided"
    }
}

$hasPreflightInput = [bool]$resolvedPreflight
$hasApprovalHashResult = ($null -ne $approvalFromFile) -or ($null -ne $approvalGuardObj)

# --- Proposal summary fields ---
$reqId = if ($null -ne $proposal.requestId) { [string]$proposal.requestId } else { "" }
$environment = if ($null -ne $proposal.environment) { [string]$proposal.environment } else { "" }
$server = if ($null -ne $proposal.server) { [string]$proposal.server } else { "" }
$database = if ($null -ne $proposal.database) { [string]$proposal.database } else { "" }
$table = if ($null -ne $proposal.table) { [string]$proposal.table } else { "" }
$action = if ($null -ne $proposal.action) { [string]$proposal.action } else { "" }
$column = if ($null -ne $proposal.column) { [string]$proposal.column } else { "" }
$dataType = if ($null -ne $proposal.dataType) { [string]$proposal.dataType } else { "" }
$nullable = $proposal.nullable
$tenantScope = if ($null -ne $proposal.tenantScope) { [string]$proposal.tenantScope } else { "" }
$snoRequired = if ($proposal.PSObject.Properties["snoRequired"] -and $proposal.snoRequired -is [bool]) { [bool]$proposal.snoRequired } else { $proposal.snoRequired }
$affectedSystems = @()
if ($null -ne $proposal.affectedSystems) { $affectedSystems = @($proposal.affectedSystems) }
$affectedText = if ($affectedSystems.Count -gt 0) { ($affectedSystems | ForEach-Object { [string]$_ }) -join ", " } else { "(none)" }
$reason = if ($null -ne $proposal.reason) { [string]$proposal.reason } else { "" }

$declaredRisk = if ($null -ne $proposal.riskLevel) { [string]$proposal.riskLevel } else { "Unknown" }
$requiresApproval = $false
if ($proposal.PSObject.Properties["requiresApproval"] -and $proposal.requiresApproval -is [bool]) {
    $requiresApproval = [bool]$proposal.requiresApproval
}
$rollbackPlanRequired = "Unknown"
if ($proposal.PSObject.Properties["rollbackPlanRequired"] -and $proposal.rollbackPlanRequired -is [bool]) {
    $rollbackPlanRequired = [string]$proposal.rollbackPlanRequired
}
$approvalPresentLabel = if (Approval-Code-Present $proposal) { "present" } else { "missing" }

# --- Safety warnings ---
$safetyWarnings = [System.Collections.Generic.List[string]]::new()
if ($preflightFinal -ne "PASS" -and $preflightFinal -ne "Unknown") {
    $safetyWarnings.Add("Preflight finalStatus is not PASS ($preflightFinal).")
}
if ($riskUnderestimated) {
    $safetyWarnings.Add("riskUnderestimated is true (declared risk lower than calculated).")
}
if (-not $autoExecutable) {
    $safetyWarnings.Add("autoExecutable is false; manual governance required.")
}
if ($approvalStatus -eq "FAIL") {
    $safetyWarnings.Add("approval_hash_guard status is FAIL.")
}
foreach ($w in $approvalWarnings) {
    if (-not [string]::IsNullOrWhiteSpace($w)) { $safetyWarnings.Add("approval_hash_guard: $w") }
}
if ((Risk-Needs-ApprovalCode $proposal $calculatedRiskLevel) -and -not (Approval-Code-Present $proposal)) {
    $safetyWarnings.Add("approvalCode is missing but High/Critical/PROD rules require an approval code.")
}

# --- Final conclusion (FAIL overrides BLOCKED) ---
$finalConclusion = "PLAN_INCOMPLETE"
if (-not $hasPreflightInput -or -not $hasApprovalHashResult) {
    $finalConclusion = "PLAN_INCOMPLETE"
}
else {
    $pf = $preflightFinal.ToUpperInvariant()
    $ap = $approvalStatus.ToUpperInvariant()
    $isFail = ($pf -eq "FAIL") -or ($ap -eq "FAIL")
    if ($isFail) {
        $finalConclusion = "PLAN_FAIL"
    }
    elseif (($pf -eq "BLOCKED") -or (-not $autoExecutable)) {
        $finalConclusion = "PLAN_BLOCKED"
    }
    elseif ($pf -eq "PASS" -and $ap -eq "PASS" -and $autoExecutable) {
        $finalConclusion = "PLAN_PASS"
    }
    else {
        $finalConclusion = "PLAN_INCOMPLETE"
    }
}

$blockingLines = if ($blockingReasons.Count -gt 0) {
    ($blockingReasons | ForEach-Object { "- $_" }) -join "`n"
} else {
    "- (none)"
}

$approvalWarnLines = if ($approvalWarnings.Count -gt 0) {
    ($approvalWarnings | ForEach-Object { "- $_" }) -join "`n"
} else {
    "- (none)"
}

$safetyLines = if ($safetyWarnings.Count -gt 0) {
    ($safetyWarnings | ForEach-Object { "- $_" }) -join "`n"
} else {
    "- (none)"
}

$requiredArtifacts = @(
    "DB Change Request",
    "proposal JSON",
    "Plan Report",
    "Preflight Report",
    "Approval / Hash result",
    ".bak backup before execution",
    "before schema-only.sql",
    "after schema-only.sql",
    "schema diff report",
    "rollback plan",
    "audit log"
)
$requiredArtifactLines = ($requiredArtifacts | ForEach-Object { "- $_" }) -join "`n"

$report = @"
# Plan Report (SQL Safe Migration 5.0)

## 1) Report Metadata

- generatedAt: $generatedAt
- proposalPath: ``$resolvedProposal``
- preflightReportPath: ``$preflightNote``
- approvalHashResultPath: ``$approvalPathNote``
- generatorMode: Plan Mode / Dry-run

## 2) Proposal Summary

- requestId: ``$reqId``
- environment: ``$environment``
- server: ``$server``
- database: ``$database``
- table: ``$table``
- action: ``$action``
- column: ``$column``
- dataType: ``$dataType``
- nullable: ``$nullable``
- tenantScope: ``$tenantScope``
- snoRequired: ``$snoRequired``
- affectedSystems: $affectedText
- reason: $reason

## 3) Declared Risk

- riskLevel: ``$declaredRisk``
- requiresApproval: ``$requiresApproval``
- approvalCode: **$approvalPresentLabel**
- rollbackPlanRequired: ``$rollbackPlanRequired``

## 4) Checker Integration Summary

- proposal_checker status: ``$proposalCheckerStatus``
- risk_checker calculatedRiskLevel: ``$calculatedRiskLevel``
- risk_checker riskUnderestimated: ``$riskUnderestimated``
- risk_checker autoExecutable: ``$autoExecutable``
- db_connection_guard status: ``$dbStatus``
- tenant_sno_checker status: ``$tenantStatus``
- preflight finalStatus: ``$preflightFinal``
- preflight blockingReasons:

$blockingLines

- approval_hash_guard status: ``$approvalStatus``
- approval_hash_guard warnings:

$approvalWarnLines

## 5) Hash Summary

- proposalHash: ``$hashProposal``
- preflightReportHash: ``$hashPreflight``

## 6) Safety Warnings

$safetyLines

## 7) Required Artifacts

$requiredArtifactLines

## 8) Final Conclusion

- **$finalConclusion**

## 9) Explicit safety statement

This is Plan Mode only. No SQL Server connection was made. No SQL was executed. No production database was modified.
"@

$outputDir = Split-Path -Path $OutputPath -Parent
if (-not [string]::IsNullOrWhiteSpace($outputDir) -and -not (Test-Path -Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

Set-Content -LiteralPath $OutputPath -Value $report -Encoding UTF8
Write-Output "PASS: Plan report generated at $OutputPath"
exit 0
