param(
    [Parameter(Mandatory = $true)]
    [string] $ContractInputPath,
    [Parameter(Mandatory = $false)]
    [string] $Mode,
    [Parameter(Mandatory = $false)]
    [string] $Environment
)

# Plan-only: no SQL Server connection and no SQL execution.

$ErrorActionPreference = "Stop"

$reasons = New-Object System.Collections.Generic.List[string]

function Add-Reason([string]$Text) {
    if (-not [string]::IsNullOrWhiteSpace($Text)) {
        [void]$reasons.Add($Text)
    }
}

function Write-ValidatorResult {
    param(
        [bool]$Pass,
        [string]$EffectiveMode,
        [string]$EffectiveEnvironment,
        [string]$WindowStartStr,
        [string]$WindowEndStr
    )
    $obj = [PSCustomObject]@{
        component    = "maintenance_window_validator"
        pass         = [bool]$Pass
        mode         = $EffectiveMode
        environment  = $EffectiveEnvironment
        checkedAt    = ([DateTimeOffset]::UtcNow).ToString("o")
        reasons      = @($reasons.ToArray())
        windowStart  = $WindowStartStr
        windowEnd    = $WindowEndStr
    }
    $obj | ConvertTo-Json -Depth 10
    if ($Pass) { exit 0 } else { exit 1 }
}

function Test-ModeValue([string]$Value) {
    return ($Value -eq "MOCK" -or $Value -eq "DRY_RUN" -or $Value -eq "LIVE_EXECUTE")
}

function Test-EnvironmentValue([string]$Value) {
    return ($Value -eq "DEV" -or $Value -eq "STAGING" -or $Value -eq "PRODUCTION")
}

$windowStartOut = ""
$windowEndOut = ""
$effectiveMode = ""
$effectiveEnvironment = ""

try {
    $resolved = (Resolve-Path -LiteralPath $ContractInputPath -ErrorAction Stop).Path
} catch {
    Add-Reason "ContractInputPath not found or not accessible"
    Write-ValidatorResult -Pass $false -EffectiveMode "" -EffectiveEnvironment "" -WindowStartStr "" -WindowEndStr ""
}

try {
    $raw = Get-Content -LiteralPath $resolved -Raw -Encoding UTF8
    $doc = $raw | ConvertFrom-Json
} catch {
    Add-Reason "Invalid JSON in contract input"
    Write-ValidatorResult -Pass $false -EffectiveMode "" -EffectiveEnvironment "" -WindowStartStr "" -WindowEndStr ""
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

if (-not (Test-ModeValue $effectiveMode)) {
    Add-Reason "mode must be MOCK, DRY_RUN, or LIVE_EXECUTE (from input or -Mode)"
}

if (-not (Test-EnvironmentValue $effectiveEnvironment)) {
    Add-Reason "environment must be DEV, STAGING, or PRODUCTION (from input or -Environment)"
}

$mw = $null
try {
    $mw = $doc.maintenanceWindow
} catch {
    $mw = $null
}

if ($null -eq $mw) {
    Add-Reason "maintenanceWindow is required"
}

if ($reasons.Count -gt 0) {
    Write-ValidatorResult -Pass $false -EffectiveMode $effectiveMode -EffectiveEnvironment $effectiveEnvironment -WindowStartStr $windowStartOut -WindowEndStr $windowEndOut
}

if ($mw.approved -ne $true) {
    Add-Reason "maintenanceWindow.approved must be true"
}

$wsRaw = $null
$weRaw = $null
try { $wsRaw = $mw.windowStart } catch { $wsRaw = $null }
try { $weRaw = $mw.windowEnd } catch { $weRaw = $null }

$windowStartOut = if ($null -ne $wsRaw) { [string]$wsRaw } else { "" }
$windowEndOut = if ($null -ne $weRaw) { [string]$weRaw } else { "" }

$startDto = $null
$endDto = $null
$parsedStart = $false
$parsedEnd = $false
try {
    if (-not [string]::IsNullOrWhiteSpace([string]$wsRaw)) {
        $startDto = [DateTimeOffset]::Parse(
            [string]$wsRaw,
            [System.Globalization.CultureInfo]::InvariantCulture,
            [System.Globalization.DateTimeStyles]::RoundtripKind
        )
        $parsedStart = $true
    }
} catch {
    $parsedStart = $false
}
try {
    if (-not [string]::IsNullOrWhiteSpace([string]$weRaw)) {
        $endDto = [DateTimeOffset]::Parse(
            [string]$weRaw,
            [System.Globalization.CultureInfo]::InvariantCulture,
            [System.Globalization.DateTimeStyles]::RoundtripKind
        )
        $parsedEnd = $true
    }
} catch {
    $parsedEnd = $false
}

if (-not $parsedStart) {
    Add-Reason "maintenanceWindow.windowStart is not a valid date-time"
}
if (-not $parsedEnd) {
    Add-Reason "maintenanceWindow.windowEnd is not a valid date-time"
}

if ($parsedStart -and $parsedEnd) {
    if ($endDto -le $startDto) {
        Add-Reason "maintenanceWindow.windowEnd must be later than windowStart"
    }
}

$approvedBy = ""
try { $approvedBy = [string]$mw.approvedBy } catch { $approvedBy = "" }
if ([string]::IsNullOrWhiteSpace($approvedBy)) {
    Add-Reason "maintenanceWindow.approvedBy must not be blank"
}

if ($reasons.Count -gt 0) {
    Write-ValidatorResult -Pass $false -EffectiveMode $effectiveMode -EffectiveEnvironment $effectiveEnvironment -WindowStartStr $windowStartOut -WindowEndStr $windowEndOut
}

$requireNowInWindow = ($effectiveMode -eq "LIVE_EXECUTE" -and $effectiveEnvironment -eq "PRODUCTION")

if ($requireNowInWindow) {
    $now = [DateTimeOffset]::UtcNow
    if ($now -lt $startDto -or $now -gt $endDto) {
        Add-Reason "current UTC time is outside maintenanceWindow (required for PRODUCTION + LIVE_EXECUTE)"
    }
}

if ($reasons.Count -gt 0) {
    Write-ValidatorResult -Pass $false -EffectiveMode $effectiveMode -EffectiveEnvironment $effectiveEnvironment -WindowStartStr $windowStartOut -WindowEndStr $windowEndOut
}

Write-ValidatorResult -Pass $true -EffectiveMode $effectiveMode -EffectiveEnvironment $effectiveEnvironment -WindowStartStr $windowStartOut -WindowEndStr $windowEndOut
