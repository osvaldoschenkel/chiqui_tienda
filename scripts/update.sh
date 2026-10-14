#!/usr/bin/env bash
set -euo pipefail
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
[[ -f .env ]] || { echo 'Falta .env: restaurá el archivo original antes de actualizar.'; exit 1; }
"$PHP_BIN" scripts/requirements.php
if command -v composer >/dev/null; then
  composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
elif [[ ! -f vendor/autoload.php ]]; then
  echo 'Falta vendor/. Cargá el paquete con dependencias o instalá Composer 2.'; exit 1
fi
# Enter maintenance only after dependencies and platform checks succeed.
"$PHP_BIN" artisan down --retry=60
trap 'echo "La actualización no terminó. Conservamos mantenimiento; revisá el error y docs/CARGAR_AL_HOSTING.md." >&2' ERR
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" scripts/key-init.php
"$PHP_BIN" artisan migrate --force
if [[ ! -e public/storage && ! -L public/storage ]]; then "$PHP_BIN" artisan storage:link; fi
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan up
trap - ERR
printf '%s\n' 'Actualización lista. Se conservaron APP_KEY, configuración y datos. Revisá /up y una compra de prueba.'
