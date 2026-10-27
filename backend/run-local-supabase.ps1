$ErrorActionPreference = 'Stop'

$backendRoot = $PSScriptRoot
$envFile = Join-Path $backendRoot '.env'

if (-not (Test-Path -LiteralPath $envFile)) {
    throw "No existe la configuración local: $envFile"
}

$envValues = @{}
foreach ($line in Get-Content -LiteralPath $envFile) {
    if ($line -match '^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$') {
        $value = $matches[2].Trim()
        if ($value.Length -ge 2 -and $value[0] -eq '"' -and $value[$value.Length - 1] -eq '"') {
            $value = $value.Substring(1, $value.Length - 2)
        } elseif ($value.Length -ge 2 -and $value[0] -eq "'" -and $value[$value.Length - 1] -eq "'") {
            $value = $value.Substring(1, $value.Length - 2)
        }
        $envValues[$matches[1]] = $value
    }
}

$productImagesDisk = $envValues['PRODUCT_IMAGES_DISK']
if (-not $productImagesDisk) {
    $requiredStorageSettings = @(
        'SUPABASE_S3_ACCESS_KEY_ID',
        'SUPABASE_S3_SECRET_ACCESS_KEY',
        'SUPABASE_S3_REGION',
        'SUPABASE_S3_BUCKET',
        'SUPABASE_S3_ENDPOINT',
        'SUPABASE_S3_PUBLIC_URL'
    )
    $storageIsConfigured = @($requiredStorageSettings | Where-Object { -not $envValues[$_] }).Count -eq 0
    $productImagesDisk = if ($storageIsConfigured) { 'supabase' } else { 'public' }
}

if ($productImagesDisk -eq 'supabase') {
    $requiredStorageSettings = @(
        'SUPABASE_S3_ACCESS_KEY_ID',
        'SUPABASE_S3_SECRET_ACCESS_KEY',
        'SUPABASE_S3_REGION',
        'SUPABASE_S3_BUCKET',
        'SUPABASE_S3_ENDPOINT',
        'SUPABASE_S3_PUBLIC_URL'
    )
    $missingStorageSettings = @($requiredStorageSettings | Where-Object { -not $envValues[$_] })
    if ($missingStorageSettings.Count -gt 0) {
        throw "Supabase Storage está seleccionado, pero faltan variables en backend/.env: $($missingStorageSettings -join ', ')"
    }
}

Set-Location $backendRoot

# Usa la conexión PostgreSQL ya configurada en backend/.env; no ejecuta migraciones.
$env:APP_ENV = 'local'
$env:DB_CONNECTION = 'pgsql'
$env:SESSION_DRIVER = 'file'
$env:CACHE_STORE = 'file'
$env:QUEUE_CONNECTION = 'sync'
$env:FILESYSTEM_DISK = 'local'
$env:PRODUCT_IMAGES_DISK = $productImagesDisk
$env:PAYMENTS_MODE = 'simulated'

Write-Output 'La API local usará la base PostgreSQL configurada en backend/.env.'
Write-Output 'No ejecutará migraciones y los pagos estarán simulados.'
Write-Output "Disco para imágenes: $productImagesDisk."

php -d extension=mbstring -d extension=pdo_pgsql artisan migrate:status --no-interaction
if ($LASTEXITCODE -ne 0) {
    throw 'No fue posible verificar la conexión o el estado de las migraciones de PostgreSQL.'
}

$laravelRouter = Join-Path $backendRoot 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
Set-Location (Join-Path $backendRoot 'public')
php -d extension=mbstring -d extension=pdo_pgsql -S 127.0.0.1:8000 -t . $laravelRouter
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}
