$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$outputDir = Join-Path $projectRoot 'dist'
New-Item -ItemType Directory -Force -Path $outputDir | Out-Null
$archivePath = Join-Path $outputDir 'forus-woocommerce-v1.1.0.zip'
$paths = @('forus-woocommerce.php', 'README.md', 'CHANGELOG.md', 'LICENSE')
foreach ($dir in @('includes', 'assets', 'docs')) {
    $paths += Get-ChildItem -LiteralPath (Join-Path $projectRoot $dir) -Recurse -File | ForEach-Object { [IO.Path]::GetRelativePath($projectRoot, $_.FullName).Replace('\', '/') }
}
Add-Type -AssemblyName System.IO.Compression
$stream = [IO.File]::Open($archivePath, [IO.FileMode]::Create)
$zip = [IO.Compression.ZipArchive]::new($stream, [IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($relative in ($paths | Sort-Object)) {
        $entry = $zip.CreateEntry('forus-woocommerce/' + $relative, [IO.Compression.CompressionLevel]::Optimal)
        $entry.LastWriteTime = [DateTimeOffset]::new(2020, 1, 1, 0, 0, 0, [TimeSpan]::Zero)
        $destination = $entry.Open()
        try { $bytes = [IO.File]::ReadAllBytes((Join-Path $projectRoot $relative)); $destination.Write($bytes, 0, $bytes.Length) } finally { $destination.Dispose() }
    }
} finally { $zip.Dispose(); $stream.Dispose() }
$read = [IO.Compression.ZipFile]::OpenRead($archivePath)
try {
    if ($read.Entries.Count -ne $paths.Count) { throw 'Archive entry count mismatch' }
    foreach ($entry in $read.Entries) {
        if (-not $entry.FullName.StartsWith('forus-woocommerce/') -or $entry.FullName.Contains('..') -or $entry.FullName -match '(^|/)(\.git|tests|vendor|node_modules|\.env)(/|$)') { throw 'Unsafe package entry' }
        $original = [IO.File]::ReadAllBytes((Join-Path $projectRoot $entry.FullName.Substring(18)))
        $itemStream = $entry.Open()
        $copy = [IO.MemoryStream]::new()
        try { $itemStream.CopyTo($copy); if ([Convert]::ToBase64String($original) -cne [Convert]::ToBase64String($copy.ToArray())) { throw 'Archive content mismatch' } } finally { $itemStream.Dispose(); $copy.Dispose() }
    }
} finally { $read.Dispose() }
$digest = (Get-FileHash -Algorithm SHA256 -LiteralPath $archivePath).Hash.ToLowerInvariant()
Set-Content -LiteralPath ($archivePath + '.sha256') -Value ($digest + '  forus-woocommerce-v1.1.0.zip') -Encoding utf8
Write-Output ('Validated ' + $paths.Count + ' package files; SHA256 ' + $digest)
