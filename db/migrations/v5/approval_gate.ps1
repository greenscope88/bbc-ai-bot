param()

Write-Warning "This operation requires explicit human approval."

$inputText = Read-Host "Type YES to continue"

if ($inputText -ne "YES") {
    $obj = [PSCustomObject]@{
        success  = $false
        approved = $false
        reason   = "User did not type YES"
    }
    $obj | ConvertTo-Json -Depth 3
    exit 1
}

$obj = [PSCustomObject]@{
    success   = $true
    approved  = $true
    timestamp = (Get-Date).ToString("o")
}
$obj | ConvertTo-Json -Depth 3
exit 0

