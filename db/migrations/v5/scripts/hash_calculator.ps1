param(
    [Parameter(Mandatory = $true)]
    [string]$FilePath
)

# Hash only. This script must not execute SQL.
# This script calculates SHA256 for a file and prints metadata.

if (-not (Test-Path -Path $FilePath)) {
    Write-Output "FAIL: File not found: $FilePath"
    exit 1
}

try {
    $hash = Get-FileHash -Path $FilePath -Algorithm SHA256
}
catch {
    Write-Output "FAIL: Unable to calculate SHA256."
    exit 1
}

$result = [PSCustomObject]@{
    filePath     = (Resolve-Path -Path $FilePath).Path
    sha256       = $hash.Hash.ToLowerInvariant()
    calculatedAt = (Get-Date).ToString("yyyy-MM-ddTHH:mm:ss")
}

$result | ConvertTo-Json -Depth 2
exit 0
