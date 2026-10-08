# Instalación de Chiqui Tienda

## Requisitos

- PHP 8.2 o superior con BCMath, Ctype, cURL, DOM, Fileinfo, Mbstring, OpenSSL, PDO, Tokenizer y XML. Activar `pdo_mysql` para MySQL o `pdo_sqlite` para SQLite.
- Composer 2.
- MySQL 8/MariaDB compatible con Laravel, o SQLite para pruebas locales.
- Apache o Nginx para producción; Laragon funciona para desarrollo en Windows.

La interfaz incluye sus archivos CSS y JavaScript en `public/`. No necesita instalar Node ni compilar con npm.

## Inicio local con SQLite

```bash
git clone https://github.com/osvaldoschenkel/chiqui_tienda.git
cd chiqui_tienda
composer install
```

Copiar `.env.example` como `.env`. En Windows PowerShell:

```powershell
Copy-Item .env.example .env
New-Item database/database.sqlite -ItemType File -Force
```

En Linux o macOS:

```bash
cp .env.example .env
touch database/database.sqlite
```

Luego, en cualquiera de los sistemas:

```bash
php artisan key:generate
php artisan migrate
php artisan storage:link
php artisan admin:create admin@tutienda.com --name="Administrador"
php artisan serve
```

La contraseña se solicita oculta y debe tener al menos 12 caracteres. No existe una contraseña predeterminada ni registro público de administradores. Abrir `http://127.0.0.1:8000` e ingresar con el usuario creado.

`composer setup` automatiza dependencias, copia del entorno, clave, archivo SQLite, migraciones y enlace de imágenes para una instalación nueva. Después se debe ejecutar `admin:create`.

## MySQL / Laragon

Crear una base vacía `chiqui_tienda` y configurar en `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=chiqui_tienda
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_clave
```

Ejecutar `php artisan config:clear` y `php artisan migrate`. En Laragon ubicar el proyecto en `C:\laragon\www\chiqui_tienda` y configurar el sitio para servir desde su carpeta `public`. El resto del proyecto debe quedar fuera de la raíz pública.

## Uso desde el celular en una red local

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

Desde un teléfono en la misma red, abrir `http://IP-DE-LA-PC:8000`. Permitir el puerto en el firewall local. Para uso fuera de la red configurar HTTPS en el servidor y utilizar su dominio. El servidor de Artisan es para desarrollo.

## Producción

Configurar `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://tu-dominio.com` y `SESSION_SECURE_COOKIE=true`; utilizar HTTPS. Establecer permisos de escritura para el usuario del servidor en `storage/` y `bootstrap/cache/`.

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

No regenerar `APP_KEY` en una instalación existente. Respaldar la base de datos, `.env` y `storage/app/public` antes de actualizar. Al subir una nueva versión ejecutar las migraciones pendientes y volver a ejecutar `php artisan optimize`.

## Verificación

```bash
php artisan test
php artisan route:list
php artisan view:cache
```

GitHub Actions ejecuta la suite en PHP 8.3 al hacer push o abrir un pull request. Para verificar concurrencia en producción se recomienda MySQL; SQLite no tiene el mismo mecanismo de bloqueo por fila.
