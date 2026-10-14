#!/usr/bin/env bash
set -euo pipefail
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
if [[ ! -f .env ]]; then
  cp deploy/env.production.example .env
  chmod 600 .env
  echo 'Se creó .env de producción. Completá APP_URL y los datos de MySQL; luego volvé a ejecutar bash scripts/install.sh.'
  exit 1
fi
"$PHP_BIN" scripts/requirements.php
if [[ ! -f vendor/autoload.php ]]; then
  command -v composer >/dev/null || { echo 'Faltan vendor/ y Composer. Usá el paquete con dependencias o instalá Composer 2.'; exit 1; }
  composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
fi
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan route:clear
"$PHP_BIN" artisan view:clear
"$PHP_BIN" scripts/key-init.php
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan optimize:clear
if [[ ! -e public/storage && ! -L public/storage ]]; then "$PHP_BIN" artisan storage:link; fi
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
printf '%s\n' 'Instalación lista. Creá el administrador: php artisan admin:create tu-email --name="Administrador"' 'Configurá el cron, HTTPS e integraciones siguiendo docs/CARGAR_AL_HOSTING.md.'
