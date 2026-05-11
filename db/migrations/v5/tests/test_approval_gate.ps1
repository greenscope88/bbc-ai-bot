$ErrorActionPreference = "Stop"

$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$gate = Join-Path $root "approval_gate.ps1"

function Invoke-GateCase {
    param(
        [Parameter(Mandatory = $true)][string]$CaseName,
        [Parameter(Mandatory = $true)][AllowEmptyString()][string]$MockInput,
        [Parameter(Mandatory = $true)][int]$ExpectedExitCode,
        [Parameter(Mandatory = $true)][ScriptBlock]$Assert
    )

    $cmd = @"
function Read-Host([string]`$Prompt) { return '$MockInput' }
& '$gate'
exit `$LASTEXITCODE
"@

    $out = & powershell.exe -NoProfile -ExecutionPolicy Bypass -Command $cmd 2>&1
    $exitCode = $LASTEXITCODE

    if ($exitCode -ne $ExpectedExitCode) {
        throw "[$CaseName] expected exit code $ExpectedExitCode, got $exitCode. Output: $out"
    }

    # Extract JSON object from mixed output (warnings + JSON).
    $text = ($out | Out-String)
    $start = $text.IndexOf('{')
    $end = $text.LastIndexOf('}')
    if ($start -lt 0 -or $end -lt 0 -or $end -le $start) {
        throw "[$CaseName] could not locate JSON braces in output. Output: $out"
    }
    $jsonText = $text.Substring($start, $end - $start + 1)

    try { $obj = $jsonText | ConvertFrom-Json } catch { throw "[$CaseName] output is not valid JSON. Output: $out" }
    & $Assert $obj
}

Invoke-GateCase -CaseName "YES" -MockInput "YES" -ExpectedExitCode 0 -Assert {
    param($o)
    if ($o.success -ne $true) { throw "[YES] expected success=true" }
    if ($o.approved -ne $true) { throw "[YES] expected approved=true" }
    if (-not $o.timestamp) { throw "[YES] expected timestamp present" }
}

Invoke-GateCase -CaseName "NO" -MockInput "NO" -ExpectedExitCode 1 -Assert {
    param($o)
    if ($o.success -ne $false) { throw "[NO] expected success=false" }
    if ($o.approved -ne $false) { throw "[NO] expected approved=false" }
    if ($o.reason -ne "User did not type YES") { throw "[NO] expected reason 'User did not type YES'" }
}

Invoke-GateCase -CaseName "BLANK" -MockInput "" -ExpectedExitCode 1 -Assert {
    param($o)
    if ($o.success -ne $false) { throw "[BLANK] expected success=false" }
    if ($o.approved -ne $false) { throw "[BLANK] expected approved=false" }
    if ($o.reason -ne "User did not type YES") { throw "[BLANK] expected reason 'User did not type YES'" }
}

Write-Output "PASS: test_approval_gate.ps1"

