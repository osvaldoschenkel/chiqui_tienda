param([string]$Php = "php")
$ErrorActionPreference = "Stop"
Set-Location (Split-Path $PSScriptRoot -Parent)
function Invoke-Php { & $Php @args; if ($LASTEXITCODE -ne 0) { throw "El comando PHP falló. Revisá el mensaje anterior." } }
if (!(Test-Path .env)) {
    Copy-Item .env.example .env
    Write-Host 'Se creó .env local. Configurá SQLite o MySQL y volvé a ejecutar este script.'
    exit 1
}
Invoke-Php scripts/requirements.php
if (!(Test-Path vendor/autoload.php)) {
    & composer install --prefer-dist --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'No se pudieron instalar las dependencias.' }
}
# SQLite local needs a file before initial key/database checks.
if ((Get-Content .env | Where-Object { $_ -match '^DB_CONNECTION=sqlite$' }) -and !(Test-Path database/database.sqlite)) {
    New-Item database/database.sqlite -ItemType File | Out-Null
}
Invoke-Php artisan config:clear
Invoke-Php artisan route:clear
Invoke-Php artisan view:clear
Invoke-Php scripts/key-init.php
Invoke-Php artisan migrate
Invoke-Php artisan optimize:clear
if (!(Test-Path public/storage)) { Invoke-Php artisan storage:link }
Write-Host 'Listo. Creá el administrador con php artisan admin:create y luego iniciá php artisan serve.'
