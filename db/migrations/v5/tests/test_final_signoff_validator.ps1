$ErrorActionPreference = "Stop"

$v5Root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$validator = Join-Path $v5Root "final_signoff_validator.ps1"

function Get-ValidatorJson {
    param([object]$Out)
    $text = ($Out | Out-String)
    $start = $text.IndexOf('{')
    $end = $text.LastIndexOf('}')
    if ($start -lt 0 -or $end -lt 0 -or $end -le $start) {
        throw "Could not locate JSON in validator output: $text"
    }
    return ($text.Substring($start, $end - $start + 1) | ConvertFrom-Json)
}

function Invoke-Fsv {
    param(
        [string]$JsonPath,
        [string]$Mode = $null,
        [string]$Environment = $null
    )
    $args = @("-NoProfile", "-ExecutionPolicy", "Bypass", "-File", $validator, "-ContractInputPath", $JsonPath)
    if (-not [string]::IsNullOrWhiteSpace($Mode)) {
        $args += @("-Mode", $Mode)
    }
    if (-not [string]::IsNullOrWhiteSpace($Environment)) {
        $args += @("-Environment", $Environment)
    }
    $raw = & powershell.exe @args 2>&1 | Out-String
    return [PSCustomObject]@{
        ExitCode = $LASTEXITCODE
        Raw      = $raw
    }
}

function New-BaseContract {
    param(
        [bool]$Approved = $true,
        [string]$ApprovedBy = "cab.owner@example.com",
        [string]$ApprovedAt = "2026-05-12T10:00:00Z",
        [string]$TicketId = "CAB-FSV-001",
        [string]$ChangeRequestId = "CR-FSV-001",
        [string]$MigrationFile = "db/migrations/v5/sql/example.sql",
        [string]$Mode = "LIVE_EXECUTE",
        [string]$Environment = "PRODUCTION",
        [object]$FinalSignOff = $null
    )
    if ($null -eq $FinalSignOff) {
        $FinalSignOff = @{
            approved    = $Approved
            approvedBy  = $ApprovedBy
            approvedAt  = $ApprovedAt
            ticketId    = $TicketId
        }
    }
    return [ordered]@{
        contractVersion       = "5.0.0"
        mode                  = $Mode
        migrationFile         = $MigrationFile
        environment           = $Environment
        enableLiveExecution   = $false
        maintenanceWindow     = @{
            approved    = $true
            windowStart = "2026-05-10T01:00:00Z"
            windowEnd   = "2026-05-10T04:00:00Z"
            approvedBy  = "ops@example.com"
        }
        humanApprovals        = @(
            @{ role = "dba"; approver = "dba@x"; approvedAt = "2026-05-01T10:00:00Z"; signatureRef = "sig-1" },
            @{ role = "owner"; approver = "own@x"; approvedAt = "2026-05-01T11:00:00Z"; signatureRef = "sig-2" }
        )
        backupConfirmation    = @{
            backupFile  = "\\backup\db\demo.bak"
            createdAt   = "2026-05-09T02:00:00Z"
            verifiedBy  = "dba@x"
        }
        recoveryReadiness     = @{
            status     = "PASS"
            reportPath = "db/migrations/v5/tests/results/recovery.json"
        }
        finalSignOff          = $FinalSignOff
        auditMetadata         = @{
            changeRequestId = $ChangeRequestId
            businessReason  = "fsv test"
            submittedBy     = "ci@test"
        }
    }
}

$tmp = Join-Path ([System.IO.Path]::GetTempPath()) ("fsv-test-" + [Guid]::NewGuid().ToString("n"))
New-Item -ItemType Directory -Path $tmp -Force | Out-Null

try {
    # 1) Missing contract file
    $missing = Join-Path $tmp "nope.json"
    $r = Invoke-Fsv -JsonPath $missing
    if ($r.ExitCode -ne 1) { throw "[1] expected exit 1" }
    $o = Get-ValidatorJson -Out $r.Raw
    if ($o.pass -eq $true) { throw "[1] expected pass false" }
    if ($o.liveExecutionEnabled -ne $false) { throw "[1] liveExecutionEnabled must stay false" }

    # 2) finalSignOff key missing — remove property by rebuilding without it
    $c = New-BaseContract
    $orderedNoFs = [ordered]@{}
    foreach ($k in $c.Keys) {
        if ($k -ne "finalSignOff") { $orderedNoFs[$k] = $c[$k] }
    }
    $p = Join-Path $tmp "no_fs.json"
    ($orderedNoFs | ConvertTo-Json -Depth 25) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-Fsv -JsonPath $p
    if ($r.ExitCode -ne 1) { throw "[2] expected exit 1" }
    $o = Get-ValidatorJson -Out $r.Raw
    if ($o.reasons -join ";" -notmatch "finalSignOff") { throw "[2] expected finalSignOff reason" }

    # 3) approved = false
    $p = Join-Path $tmp "ap_false.json"
    (New-BaseContract -Approved $false | ConvertTo-Json -Depth 25) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-Fsv -JsonPath $p
    if ($r.ExitCode -ne 1) { throw "[3] expected exit 1" }
    $o = Get-ValidatorJson -Out $r.Raw
    if (($o.reasons | Out-String) -notmatch "approved") { throw "[3] expected approved failure" }

    # 4) approvedBy blank
    $p = Join-Path $tmp "by_blank.json"
    (New-BaseContract -ApprovedBy "   " | ConvertTo-Json -Depth 25) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-Fsv -JsonPath $p
    if ($r.ExitCode -ne 1) { throw "[4] expected exit 1" }

    # 5) approvedAt invalid
    $p = Join-Path $tmp "at_bad.json"
    (New-BaseContract -ApprovedAt "not-a-date" | ConvertTo-Json -Depth 25) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-Fsv -JsonPath $p
    if ($r.ExitCode -ne 1) { throw "[5] expected exit 1" }

    # 6) ticketId blank
    $p = Join-Path $tmp "tid_blank.json"
    (New-BaseContract -TicketId "" | ConvertTo-Json -Depth 25) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-Fsv -JsonPath $p
    if ($r.ExitCode -ne 1) { throw "[6] expected exit 1" }

    # 7) changeRequestId blank
    $p = Join-Path $tmp "cr_blank.json"
    (New-BaseContract -ChangeRequestId "" | ConvertTo-Json -Depth 25) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-Fsv -JsonPath $p
    if ($r.ExitCode -ne 1) { throw "[7] expected exit 1" }

    # 8) migrationFile blank
    $p = Join-Path $tmp "mig_blank.json"
    (New-BaseContract -MigrationFile "" | ConvertTo-Json -Depth 25) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-Fsv -JsonPath $p
    if ($r.ExitCode -ne 1) { throw "[8] expected exit 1" }

    # 9) PRODUCTION + LIVE_EXECUTE + complete signoff -> PASS
    $p = Join-Path $tmp "full_pass.json"
    (New-BaseContract | ConvertTo-Json -Depth 25) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-Fsv -JsonPath $p
    if ($r.ExitCode -ne 0) { throw "[9] expected exit 0: $($r.Raw)" }
    $o = Get-ValidatorJson -Out $r.Raw
    if ($o.pass -ne $true) { throw "[9] pass must be true" }
    if ($o.component -ne "final_signoff_validator") { throw "[9] bad component" }
    if ($o.liveExecutionEnabled -ne $false) { throw "[9] must not imply live enabled" }
    if ($o.ticketId -ne "CAB-FSV-001") { throw "[9] ticketId echo" }
    if ($o.changeRequestId -ne "CR-FSV-001") { throw "[9] changeRequestId echo" }
    if ($o.approvedBy -ne "cab.owner@example.com") { throw "[9] approvedBy echo" }

    # 10) MOCK / DRY_RUN format correct -> PASS
    $p = Join-Path $tmp "mock_ok.json"
    (New-BaseContract -Mode "MOCK" -Environment "STAGING" | ConvertTo-Json -Depth 25) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-Fsv -JsonPath $p
    if ($r.ExitCode -ne 0) { throw "[10a] expected exit 0: $($r.Raw)" }
    $o = Get-ValidatorJson -Out $r.Raw
    if ($o.pass -ne $true) { throw "[10a] pass must be true" }
    if ($o.liveExecutionEnabled -ne $false) { throw "[10a] MOCK must not imply live" }

    $p = Join-Path $tmp "dry_ok.json"
    (New-BaseContract -Mode "DRY_RUN" -Environment "DEV" | ConvertTo-Json -Depth 25) | Set-Content -LiteralPath $p -Encoding UTF8
    $r = Invoke-Fsv -JsonPath $p
    if ($r.ExitCode -ne 0) { throw "[10b] expected exit 0: $($r.Raw)" }
    $o = Get-ValidatorJson -Out $r.Raw
    if ($o.pass -ne $true) { throw "[10b] pass must be true" }
    if ($o.liveExecutionEnabled -ne $false) { throw "[10b] DRY_RUN must not imply live" }

    Write-Output "PASS: test_final_signoff_validator.ps1"
}
finally {
    Remove-Item -LiteralPath $tmp -Recurse -Force -ErrorAction SilentlyContinue
}
