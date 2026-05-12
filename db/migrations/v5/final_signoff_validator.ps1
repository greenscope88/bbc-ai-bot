param(
    [Parameter(Mandatory = $true)]
    [string] $ContractInputPath,
    [Parameter(Mandatory = $false)]
    [string] $Mode,
    [Parameter(Mandatory = $false)]
    [string] $Environment
)

# Plan-only: no SQL Server connection and no SQL execution. Does not enable LIVE_EXECUTE.

$ErrorActionPreference = "Stop"

$reasons = New-Object System.Collections.Generic.List[string]

function Add-Reason([string]$Text) {
    if (-not [string]::IsNullOrWhiteSpace($Text)) {
        [void]$reasons.Add($Text)
    }
}

function Test-ModeValue([string]$Value) {
    return ($Value -eq "MOCK" -or $Value -eq "DRY_RUN" -or $Value -eq "LIVE_EXECUTE")
}

function Test-EnvironmentValue([string]$Value) {
    return ($Value -eq "DEV" -or $Value -eq "STAGING" -or $Value -eq "PRODUCTION")
}

function Test-ApprovedAtParseable([string]$Raw) {
    if ([string]::IsNullOrWhiteSpace($Raw)) { return $false }
    try {
        [void][DateTimeOffset]::Parse(
            $Raw.Trim(),
            [System.Globalization.CultureInfo]::InvariantCulture,
            [System.Globalization.DateTimeStyles]::RoundtripKind
        )
        return $true
    } catch {
        return $false
    }
}

function Write-ValidatorResult {
    param(
        [bool]$Pass,
        [string]$EffectiveMode,
        [string]$EffectiveEnvironment,
        [string]$TicketIdOut,
        [string]$ChangeRequestIdOut,
        [string]$ApprovedByOut
    )
    $obj = [PSCustomObject]@{
        component               = "final_signoff_validator"
        pass                    = [bool]$Pass
        mode                    = $EffectiveMode
        environment             = $EffectiveEnvironment
        checkedAt               = ([DateTimeOffset]::UtcNow).ToString("o")
        reasons                 = @($reasons.ToArray())
        ticketId                = $TicketIdOut
        changeRequestId         = $ChangeRequestIdOut
        approvedBy              = $ApprovedByOut
        liveExecutionEnabled    = $false
        note                    = "final_signoff_validator performs plan-only governance checks; it does not enable LIVE_EXECUTE or SQL execution."
    }
    $obj | ConvertTo-Json -Depth 10
    if ($Pass) { exit 0 } else { exit 1 }
}

$resolved = $null
$doc = $null
$effectiveMode = ""
$effectiveEnvironment = ""
$ticketIdOut = ""
$changeRequestIdOut = ""
$approvedByOut = ""

try {
    $resolved = (Resolve-Path -LiteralPath $ContractInputPath -ErrorAction Stop).Path
} catch {
    Add-Reason "ContractInputPath not found or not accessible"
    Write-ValidatorResult -Pass $false -EffectiveMode "" -EffectiveEnvironment "" -TicketIdOut "" -ChangeRequestIdOut "" -ApprovedByOut ""
}

try {
    $raw = Get-Content -LiteralPath $resolved -Raw -Encoding UTF8
    $doc = $raw | ConvertFrom-Json
} catch {
    Add-Reason "Invalid JSON in contract input"
    Write-ValidatorResult -Pass $false -EffectiveMode "" -EffectiveEnvironment "" -TicketIdOut "" -ChangeRequestIdOut "" -ApprovedByOut ""
}

if (-not [string]::IsNullOrWhiteSpace($Mode)) {
    $effectiveMode = $Mode.Trim()
} elseif ($null -ne $doc.mode -and -not [string]::IsNullOrWhiteSpace([string]$doc.mode)) {
    $effectiveMode = [string]$doc.mode
} else {
    $effectiveMode = ""
}

if (-not [string]::IsNullOrWhiteSpace($Environment)) {
    $effectiveEnvironment = $Environment.Trim()
} elseif ($null -ne $doc.environment -and -not [string]::IsNullOrWhiteSpace([string]$doc.environment)) {
    $effectiveEnvironment = [string]$doc.environment
} else {
    $effectiveEnvironment = ""
}

if (-not [string]::IsNullOrWhiteSpace($Mode) -and -not (Test-ModeValue $effectiveMode)) {
    Add-Reason "mode must be MOCK, DRY_RUN, or LIVE_EXECUTE (from -Mode)"
}

if (-not [string]::IsNullOrWhiteSpace($Environment) -and -not (Test-EnvironmentValue $effectiveEnvironment)) {
    Add-Reason "environment must be DEV, STAGING, or PRODUCTION (from -Environment)"
}

if (-not (Test-ModeValue $effectiveMode)) {
    Add-Reason "effective mode must be MOCK, DRY_RUN, or LIVE_EXECUTE (from contract or -Mode)"
}

if (-not (Test-EnvironmentValue $effectiveEnvironment)) {
    Add-Reason "effective environment must be DEV, STAGING, or PRODUCTION (from contract or -Environment)"
}

$names = @()
try {
    $names = @($doc.PSObject.Properties.Name)
} catch {
    $names = @()
}

if (-not ($names -contains "finalSignOff")) {
    Add-Reason "finalSignOff is required"
}

if ($reasons.Count -gt 0) {
    Write-ValidatorResult -Pass $false -EffectiveMode $effectiveMode -EffectiveEnvironment $effectiveEnvironment -TicketIdOut "" -ChangeRequestIdOut "" -ApprovedByOut ""
}

$fs = $null
try {
    $fs = $doc.finalSignOff
} catch {
    $fs = $null
}

if ($null -eq $fs) {
    Add-Reason "finalSignOff must not be null"
}

if ($reasons.Count -gt 0) {
    Write-ValidatorResult -Pass $false -EffectiveMode $effectiveMode -EffectiveEnvironment $effectiveEnvironment -TicketIdOut "" -ChangeRequestIdOut "" -ApprovedByOut ""
}

if ($fs.approved -ne $true) {
    Add-Reason "finalSignOff.approved must be true"
}

try { $approvedByOut = [string]$fs.approvedBy } catch { $approvedByOut = "" }
if ([string]::IsNullOrWhiteSpace($approvedByOut)) {
    Add-Reason "finalSignOff.approvedBy must not be blank"
}

$approvedAtRaw = ""
try { $approvedAtRaw = [string]$fs.approvedAt } catch { $approvedAtRaw = "" }
if (-not (Test-ApprovedAtParseable $approvedAtRaw)) {
    Add-Reason "finalSignOff.approvedAt must be a valid date-time (ISO-8601 / round-trip)"
}

try { $ticketIdOut = [string]$fs.ticketId } catch { $ticketIdOut = "" }
if ([string]::IsNullOrWhiteSpace($ticketIdOut)) {
    Add-Reason "finalSignOff.ticketId must not be blank"
}

$meta = $null
try {
    $meta = $doc.auditMetadata
} catch {
    $meta = $null
}

if ($null -eq $meta) {
    Add-Reason "auditMetadata is required"
} else {
    try { $changeRequestIdOut = [string]$meta.changeRequestId } catch { $changeRequestIdOut = "" }
    if ([string]::IsNullOrWhiteSpace($changeRequestIdOut)) {
        Add-Reason "auditMetadata.changeRequestId must not be blank"
    }
}

$migrationFile = ""
try { $migrationFile = [string]$doc.migrationFile } catch { $migrationFile = "" }
if ([string]::IsNullOrWhiteSpace($migrationFile)) {
    Add-Reason "migrationFile must not be blank"
}

if ($reasons.Count -gt 0) {
    Write-ValidatorResult -Pass $false -EffectiveMode $effectiveMode -EffectiveEnvironment $effectiveEnvironment -TicketIdOut $ticketIdOut -ChangeRequestIdOut $changeRequestIdOut -ApprovedByOut $approvedByOut
}

Write-ValidatorResult -Pass $true -EffectiveMode $effectiveMode -EffectiveEnvironment $effectiveEnvironment -TicketIdOut $ticketIdOut -ChangeRequestIdOut $changeRequestIdOut -ApprovedByOut $approvedByOut
