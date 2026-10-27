$ErrorActionPreference = 'Stop'

$backendRoot = $PSScriptRoot
$databasePath = Join-Path $backendRoot 'database\local-demo.sqlite'

if (-not (Test-Path -LiteralPath $databasePath)) {
    throw "No existe la base local de demostración: $databasePath"
}

Set-Location $backendRoot

# Usa una base SQLite aislada; no conecta con Supabase ni Render.
$env:APP_ENV = 'local'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $databasePath
$env:SESSION_DRIVER = 'file'
$env:CACHE_STORE = 'file'
$env:QUEUE_CONNECTION = 'sync'
$env:FILESYSTEM_DISK = 'local'
$env:PRODUCT_IMAGES_DISK = 'public'
$env:PAYMENTS_MODE = 'simulated'

# Carga las extensiones PHP que Laravel necesita en esta instalación local.
$laravelRouter = Join-Path $backendRoot 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
Set-Location (Join-Path $backendRoot 'public')
php -d extension=mbstring -d extension=pdo_sqlite -S 127.0.0.1:8000 -t . $laravelRouter

if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}
