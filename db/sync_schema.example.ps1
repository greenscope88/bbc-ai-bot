# EXAMPLE TEMPLATE ONLY.
# Do not commit real server names, database names, usernames, passwords, or connection strings.
# Copy this file to sync_schema.ps1 for local use.
# The local sync_schema.ps1 file is ignored by Git.
#
# Placeholders (documentation only — replace at runtime; never store real values in this repo):
#   YOUR_SQL_SERVER
#   YOUR_DATABASE
#   YOUR_USERNAME
#   YOUR_PASSWORD
#   YOUR_OUTPUT_DIR — output files are written next to this script under db/ (schema.json, schema.sql, tables.md, memory.lock.json)
# DO_NOT_COMMIT_REAL_CONNECTION_INFO — never paste live connection strings, secrets, or production endpoints into tracked files.

param(
    [Parameter(Mandatory = $true)]
    [string]$Server,

    [Parameter(Mandatory = $true)]
    [string]$Database,

    [string]$Username,
    [string]$Password,
    [switch]$UseTrustedConnection
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$dbDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$schemaJsonPath = Join-Path $dbDir 'schema.json'
$schemaSqlPath = Join-Path $dbDir 'schema.sql'
$tablesMdPath = Join-Path $dbDir 'tables.md'
$memoryLockPath = Join-Path $dbDir 'memory.lock.json'

function Get-ConnectionString {
    if ($UseTrustedConnection) {
        return "Server=$Server;Database=$Database;Integrated Security=True;Encrypt=True;TrustServerCertificate=True;"
    }

    if ([string]::IsNullOrWhiteSpace($Username) -or [string]::IsNullOrWhiteSpace($Password)) {
        throw 'For SQL login, provide -Username and -Password, or use -UseTrustedConnection.'
    }

    return "Server=$Server;Database=$Database;User ID=$Username;Password=$Password;Encrypt=True;TrustServerCertificate=True;"
}

function Invoke-Query {
    param(
        [string]$ConnectionString,
        [string]$Query
    )

    $connection = New-Object System.Data.SqlClient.SqlConnection($ConnectionString)
    try {
        $connection.Open()
        $command = $connection.CreateCommand()
        $command.CommandText = $Query
        $command.CommandTimeout = 120

        $adapter = New-Object System.Data.SqlClient.SqlDataAdapter($command)
        $table = New-Object System.Data.DataTable
        [void]$adapter.Fill($table)
        return ,$table
    }
    finally {
        if ($connection.State -ne [System.Data.ConnectionState]::Closed) {
            $connection.Close()
        }
        $connection.Dispose()
    }
}

function Next-Version {
    param([string]$CurrentVersion)
    if ([string]::IsNullOrWhiteSpace($CurrentVersion)) { return '1.0' }
    $parts = $CurrentVersion.Split('.')
    $major = [int]$parts[0]
    $minor = if ($parts.Count -gt 1) { [int]$parts[1] } else { 0 }
    return "$major.$($minor + 1)"
}

function To-JsonText {
    param($Object)
    return ($Object | ConvertTo-Json -Depth 100)
}

$connectionString = Get-ConnectionString

$tablesQuery = @"
SELECT s.name AS schema_name, t.name AS table_name
FROM sys.tables t
INNER JOIN sys.schemas s ON t.schema_id = s.schema_id
ORDER BY s.name, t.name;
"@

$columnsQuery = @"
SELECT
    s.name AS schema_name,
    t.name AS table_name,
    c.name AS column_name,
    ty.name AS data_type,
    c.max_length,
    c.precision,
    c.scale,
    c.is_nullable,
    CASE WHEN pk.column_id IS NOT NULL THEN 1 ELSE 0 END AS is_primary_key
FROM sys.columns c
INNER JOIN sys.tables t ON c.object_id = t.object_id
INNER JOIN sys.schemas s ON t.schema_id = s.schema_id
INNER JOIN sys.types ty ON c.user_type_id = ty.user_type_id
LEFT JOIN (
    SELECT ic.object_id, ic.column_id
    FROM sys.indexes i
    INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
    WHERE i.is_primary_key = 1
) pk ON pk.object_id = c.object_id AND pk.column_id = c.column_id
ORDER BY s.name, t.name, c.column_id;
"@

$fkQuery = @"
SELECT
    fk.name AS fk_name,
    sch_parent.name AS parent_schema,
    tab_parent.name AS parent_table,
    col_parent.name AS parent_column,
    sch_ref.name AS ref_schema,
    tab_ref.name AS ref_table,
    col_ref.name AS ref_column
FROM sys.foreign_key_columns fkc
INNER JOIN sys.foreign_keys fk ON fk.object_id = fkc.constraint_object_id
INNER JOIN sys.tables tab_parent ON tab_parent.object_id = fkc.parent_object_id
INNER JOIN sys.schemas sch_parent ON sch_parent.schema_id = tab_parent.schema_id
INNER JOIN sys.columns col_parent ON col_parent.object_id = fkc.parent_object_id AND col_parent.column_id = fkc.parent_column_id
INNER JOIN sys.tables tab_ref ON tab_ref.object_id = fkc.referenced_object_id
INNER JOIN sys.schemas sch_ref ON sch_ref.schema_id = tab_ref.schema_id
INNER JOIN sys.columns col_ref ON col_ref.object_id = fkc.referenced_object_id AND col_ref.column_id = fkc.referenced_column_id
ORDER BY fk.name, fkc.constraint_column_id;
"@

$tableRows = Invoke-Query -ConnectionString $connectionString -Query $tablesQuery
$columnRows = Invoke-Query -ConnectionString $connectionString -Query $columnsQuery
$fkRows = Invoke-Query -ConnectionString $connectionString -Query $fkQuery

$tablesMap = @{}
foreach ($row in $tableRows.Rows) {
    $fullName = "$($row.schema_name).$($row.table_name)"
    $tablesMap[$fullName] = [ordered]@{
        schema = $row.schema_name
        name = $row.table_name
        full_name = $fullName
        columns = @()
        primary_key = @()
    }
}

foreach ($row in $columnRows.Rows) {
    $fullName = "$($row.schema_name).$($row.table_name)"
    if (-not $tablesMap.ContainsKey($fullName)) { continue }

    $col = [ordered]@{
        name = $row.column_name
        data_type = $row.data_type
        max_length = $row.max_length
        precision = $row.precision
        scale = $row.scale
        nullable = ([int]$row.is_nullable -eq 1)
        is_primary_key = ([int]$row.is_primary_key -eq 1)
    }

    $tablesMap[$fullName].columns += $col
    if ($col.is_primary_key) {
        $tablesMap[$fullName].primary_key += $col.name
    }
}

$relations = @()
foreach ($row in $fkRows.Rows) {
    $relations += [ordered]@{
        name = $row.fk_name
        from = "$($row.parent_schema).$($row.parent_table).$($row.parent_column)"
        to = "$($row.ref_schema).$($row.ref_table).$($row.ref_column)"
        from_table = "$($row.parent_schema).$($row.parent_table)"
        to_table = "$($row.ref_schema).$($row.ref_table)"
    }
}

$tables = $tablesMap.GetEnumerator() | Sort-Object Name | ForEach-Object { $_.Value }

$existingLock = $null
if (Test-Path $memoryLockPath) {
    $rawLock = Get-Content -Raw -Path $memoryLockPath
    if (-not [string]::IsNullOrWhiteSpace($rawLock)) {
        $existingLock = $rawLock | ConvertFrom-Json
    }
}

$snapshot = [ordered]@{
    tables = $tables
    relations = $relations
}

$snapshotJson = To-JsonText -Object $snapshot
$hashBytes = [System.Security.Cryptography.SHA256]::Create().ComputeHash([System.Text.Encoding]::UTF8.GetBytes($snapshotJson))
$schemaHash = ([System.BitConverter]::ToString($hashBytes)).Replace('-', '').ToLowerInvariant()

$previousHash = if ($existingLock) { $existingLock.schema_hash } else { '' }
if ($previousHash -eq $schemaHash) {
    Write-Host 'No schema changes detected. Memory layer stays unchanged.'
    exit 0
}

$hasPreviousSnapshot = $existingLock -and -not [string]::IsNullOrWhiteSpace($existingLock.schema_hash)
$currentVersion = if ($existingLock) { [string]$existingLock.version } else { '1.0' }
$newVersion = if ($hasPreviousSnapshot) { Next-Version -CurrentVersion $currentVersion } else { '1.0' }
$generatedAt = (Get-Date).ToString('o')

$schema = [ordered]@{
    version = $newVersion
    source = [ordered]@{
        server = $Server
        database = $Database
        synced_at = $generatedAt
        mode = 'schema-only'
    }
    tables = $tables
    relations = $relations
}
Set-Content -Path $schemaJsonPath -Value (To-JsonText -Object $schema) -Encoding UTF8

$sqlLines = New-Object System.Collections.Generic.List[string]
$sqlLines.Add('-- Auto-generated schema description')
$sqlLines.Add("-- Version: $newVersion")
$sqlLines.Add("-- Synced at: $generatedAt")
$sqlLines.Add('')
foreach ($table in $tables) {
    $sqlLines.Add("-- Table: $($table.full_name)")
    $sqlLines.Add("CREATE TABLE [$($table.schema)].[$($table.name)] (")
    for ($i = 0; $i -lt $table.columns.Count; $i++) {
        $col = $table.columns[$i]
        $nullableText = if ($col.nullable) { 'NULL' } else { 'NOT NULL' }
        $needComma = ($i -lt $table.columns.Count - 1) -or ($table.primary_key.Count -gt 0)
        $comma = if ($needComma) { ',' } else { '' }
        $sqlLines.Add("    [$($col.name)] $($col.data_type) $nullableText$comma")
    }
    if ($table.primary_key.Count -gt 0) {
        $pkCols = ($table.primary_key | ForEach-Object { "[$_]" }) -join ', '
        $sqlLines.Add("    CONSTRAINT [PK_$($table.schema)_$($table.name)] PRIMARY KEY ($pkCols)")
    }
    $sqlLines.Add(');')
    $sqlLines.Add('')
}
foreach ($relation in $relations) {
    $fromParts = $relation.from.Split('.')
    $toParts = $relation.to.Split('.')
    $sqlLines.Add("ALTER TABLE [$($fromParts[0])].[$($fromParts[1])] WITH CHECK ADD CONSTRAINT [$($relation.name)] FOREIGN KEY([$($fromParts[2])]) REFERENCES [$($toParts[0])].[$($toParts[1])]([$($toParts[2])]);")
}
Set-Content -Path $schemaSqlPath -Value ($sqlLines -join [Environment]::NewLine) -Encoding UTF8

$mdLines = New-Object System.Collections.Generic.List[string]
$mdLines.Add('# SQL Memory Layer')
$mdLines.Add('')
$mdLines.Add("- Version: $newVersion")
$mdLines.Add("- Synced at: $generatedAt")
$mdLines.Add("- Source: $Server / $Database")
$mdLines.Add('')
$mdLines.Add('## Tables')
$mdLines.Add('')
foreach ($table in $tables) {
    $mdLines.Add("### $($table.full_name)")
    $mdLines.Add('')
    $mdLines.Add('| Column | Type | Null | PK |')
    $mdLines.Add('|---|---|---|---|')
    foreach ($col in $table.columns) {
        $pkFlag = if ($col.is_primary_key) { 'Y' } else { 'N' }
        $nullFlag = if ($col.nullable) { 'Y' } else { 'N' }
        $mdLines.Add("| $($col.name) | $($col.data_type) | $nullFlag | $pkFlag |")
    }
    $mdLines.Add('')
}
$mdLines.Add('## Foreign Keys')
$mdLines.Add('')
foreach ($relation in $relations) {
    $mdLines.Add("- $($relation.name): $($relation.from) -> $($relation.to)")
}
Set-Content -Path $tablesMdPath -Value ($mdLines -join [Environment]::NewLine) -Encoding UTF8

$lock = [ordered]@{
    version = $newVersion
    schema_hash = $schemaHash
    synced_at = $generatedAt
    changed = $true
    source = [ordered]@{
        server = $Server
        database = $Database
    }
    files = @(
        'db/schema.json',
        'db/schema.sql',
        'db/tables.md'
    )
}
Set-Content -Path $memoryLockPath -Value (To-JsonText -Object $lock) -Encoding UTF8

Write-Host "Schema memory updated to version $newVersion"
