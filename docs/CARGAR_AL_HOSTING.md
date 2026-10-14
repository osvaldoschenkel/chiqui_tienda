# Chiqui Tienda: cargar, instalar y poner en funcionamiento

Esta guía corresponde al sistema Laravel integrado en la raíz del repositorio `osvaldoschenkel/chiqui_tienda`. El ZIP de hosting contiene la aplicación y sus dependencias PHP de producción. No hace falta Node.js ni npm para usar este portal. La carpeta `storefront` es la aplicación anterior y no se incluye en el ZIP de hosting.

## 1. Qué necesitás antes de cargarlo

- Un dominio o subdominio con certificado HTTPS.
- Hosting con PHP 8.3 o superior, MySQL/MariaDB y posibilidad de ejecutar comandos PHP (SSH o terminal del panel).
- Extensiones Ctype, cURL, DOM, Fileinfo, GD, Mbstring, OpenSSL, PDO MySQL, SOAP, Tokenizer, SimpleXML y XML.
- Ejecutable `openssl` y función `proc_open` permitida para firmar solicitudes ARCA.
- Cron para ejecutar una tarea por minuto.
- Base de datos y usuario con permisos sobre esa base. No usar la base de otro sistema.

El ZIP incluye `vendor/`, por lo que Composer no es necesario en el servidor para la primera instalación. Las dependencias incluidas fueron instaladas desde `composer.lock`. El hosting sigue necesitando PHP y las extensiones indicadas. No hay un instalador web ni un SQL para importar por phpMyAdmin: Laravel crea las tablas mediante migraciones.

## 2. Qué contiene el paquete

| Archivo o carpeta | Uso |
|---|---|
| `LEEME_PRIMERO.txt` | Pasos mínimos para comenzar |
| `public/` | Única carpeta que debe exponerse al dominio |
| `app/`, `routes/`, `resources/`, `config/` | Código de la aplicación |
| `vendor/` | Dependencias PHP de producción |
| `database/migrations/` | Estructura y actualizaciones de tablas |
| `deploy/env.production.example` | Plantilla sin credenciales para producción |
| `scripts/install.sh` | Instalación por terminal Linux |
| `scripts/update.sh` | Actualización conservando datos y clave |
| `scripts/install.ps1` | Preparación local en Windows / Laragon |
| `scripts/requirements.php` | Comprobación de PHP, extensiones y permisos |
| `docs/` | Guías de instalación, operación e integraciones |
| `deploy/` | Ejemplos de configuración Apache/Nginx |
| `VERSION.txt`, `MANIFEST.sha256` | Versión y sumas de comprobación |

El paquete no contiene contraseñas, certificado ARCA, clave privada, una base de datos, usuarios de prueba ni archivos del entorno de desarrollo.

## 3. Crear la base de datos

En el panel del hosting, crear una base y un usuario. Anotar nombre completo de base, usuario, contraseña, host y puerto. Algunos paneles agregan un prefijo al nombre: utilizar el nombre completo que muestra el panel. Asignar permisos al usuario sobre esa base.

No importar datos de demostración. En una actualización usar la base existente del sistema y conservar `.env` y `APP_KEY`.

## 4. Subir y extraer el ZIP

Subir el paquete a una carpeta privada del hosting, por ejemplo:

```text
/home/TU_USUARIO/apps/chiqui_tienda
```

Extraerlo ahí. En esa carpeta deben quedar `artisan`, `app`, `vendor`, `public`, `storage`, etc. No subir solamente `public/`, porque necesita el resto de la aplicación.

En la configuración del dominio seleccionar como raíz:

```text
/home/TU_USUARIO/apps/chiqui_tienda/public
```

Nunca seleccionar la raíz del proyecto como carpeta pública. `.env`, `vendor/` y los certificados privados deben quedar fuera del acceso web.

### Si el hosting exige `public_html`

Si el panel permite un enlace simbólico, configurar `public_html` como enlace a `apps/chiqui_tienda/public`. Si no lo permite, copiar **el contenido** de `public/` a `public_html/` y ajustar las rutas de `public_html/index.php` para apuntar al proyecto privado. Para el ejemplo anterior:

```php
if (file_exists($maintenance = __DIR__.'/../apps/chiqui_tienda/storage/framework/maintenance.php')) {
    require $maintenance;
}
require __DIR__.'/../apps/chiqui_tienda/vendor/autoload.php';
$app = require_once __DIR__.'/../apps/chiqui_tienda/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->handleRequest(Request::capture());
```

Conservar las demás líneas originales del archivo (`use Illuminate\Http\Request`, etc.). Las rutas dependen de la ubicación real: este ejemplo sólo vale si `public_html` y `apps` comparten la carpeta del usuario. Copiar también `.htaccess`; es necesario para las rutas de Laravel. Cada actualización deberá copiar los archivos públicos nuevamente. La instalación estándar con raíz en `public/` es preferible.

En esta alternativa, `storage:link` crea el enlace dentro del `public/` privado: crear además `public_html/storage` hacia `apps/chiqui_tienda/storage/app/public`. No enlazar `storage/app/private`, donde están los certificados ARCA.

## 5. Completar `.env`

Copiar `deploy/env.production.example` a `.env` en la raíz privada del proyecto. Completar los valores:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tu-dominio.com
APP_KEY=
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nombre_completo_de_la_base
DB_USERNAME=usuario_completo_de_la_base
DB_PASSWORD="contraseña_de_la_base"
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
CACHE_STORE=database
```

En una instalación nueva dejar `APP_KEY` vacía: el script la genera cuando comprueba que la base aún no contiene el sistema. Si la base ya existe y falta la clave, el script se detiene. Restaurar la clave original; no generar otra. Conservar esa clave en los respaldos porque cifra las integraciones.

No publicar `.env` ni enviarlo por mensajes. La guía y la plantilla contienen ejemplos, no claves del comercio.

## 6. Instalar por terminal

Abrir la terminal del hosting en la raíz del proyecto:

```bash
cd /home/TU_USUARIO/apps/chiqui_tienda
php scripts/requirements.php
bash scripts/install.sh
php artisan admin:create tu-email@dominio.com --name="Administrador"
```

La contraseña se ingresa oculta y debe tener al menos 12 caracteres. No hay contraseña predeterminada. Si el hosting utiliza otro ejecutable PHP:

```bash
PHP_BIN=/ruta/al/php83 bash scripts/install.sh
/ruta/al/php83 artisan admin:create tu-email@dominio.com --name="Administrador"
```

El script comprueba requisitos, conserva la clave existente, aplica migraciones, enlaza imágenes y prepara los caches. Si falta `.env`, crea la plantilla y se detiene para que la completes. No crea el administrador ni carga productos de ejemplo automáticamente.

### Comandos manuales equivalentes

Si no podés ejecutar Bash, usar la consola PHP del panel:

```bash
php scripts/requirements.php
php artisan config:clear
php artisan route:clear
php artisan view:clear
php scripts/key-init.php
php artisan migrate --force
php artisan optimize:clear
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan admin:create tu-email@dominio.com --name="Administrador"
```

`storage:link` se ejecuta sólo si el enlace no existe. Si el panel no tiene ninguna forma de ejecutar PHP en consola, pedirle al proveedor que ejecute estos comandos; subir el ZIP por sí solo no crea las tablas ni el administrador. No dejar scripts públicos de instalación o ejecución de comandos.

## 7. Permisos y HTTPS

El usuario PHP del hosting necesita escribir en `storage/` y `bootstrap/cache/`. Ajustar propiedad o grupo según el servidor. No aplicar permisos 777 a todo el proyecto.

Para un VPS donde PHP y despliegue comparten un grupo, una referencia habitual es:

```bash
chmod -R ug+rwX storage bootstrap/cache
chmod 600 .env
```

Esto no corrige un propietario/grupo incorrecto: verificarlo en el panel. La carpeta pública de imágenes debe ser legible por el servidor web. Los certificados y claves privadas deben mantenerse fuera de `public/`.

Activar HTTPS en el panel y redirigir HTTP a HTTPS. Si se cambia `APP_URL`, ejecutar `php artisan config:cache` otra vez. Con `SESSION_SECURE_COOKIE=true`, el acceso por HTTP no mantiene la sesión: usar el dominio HTTPS.

## 8. Configurar el cron

Agregar una tarea **cada minuto**. Usar la ruta real del proyecto y del ejecutable PHP:

```cron
* * * * * cd /home/TU_USUARIO/apps/chiqui_tienda && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Si el panel tiene campos separados, elegir cada minuto en todos los campos y pegar solamente el comando. La conciliación se programa internamente cada cinco minutos; consulta pagos antes de liberar reservas vencidas. Sin cron, esas reservas no se liberan de forma periódica.

Para una comprobación manual:

```bash
php artisan schedule:list
php artisan commerce:reconcile
```

## 9. Entrar y cargar la tienda

- Administración: `https://tu-dominio.com/login`.
- Catálogo para clientes: `/tienda`.
- Pedidos del administrador: `/pedidos-clientes`.
- Integraciones: `/integraciones`.
- Personalización del nombre y logo: `/mi-tienda`.

Primero cargar marcas y proveedores. Luego cargar productos con SKU único, precio final, IVA incluido, stock, marca y proveedor. Para envíos completar peso embalado y medidas. El sistema dispone de formularios de alta y edición; no incluye un importador masivo CSV/Excel en esta versión.

La tienda requiere cuentas de cliente. Crear el acceso con:

```bash
php artisan customer:create cliente@correo.com --name="Nombre del cliente"
```

El registro de contacto en Clientes administra la cuenta corriente; por sí solo no crea un usuario de acceso. No hay registro público de clientes en esta versión.

## 10. Configurar pagos, envíos y ARCA

En `/integraciones`:

1. Mercado Pago: Access Token y secreto del webhook. Registrar `https://tu-dominio.com/commerce/mercadopago/webhook` para notificaciones de pagos. Usar primero credenciales de prueba. La vuelta del navegador no acredita un pago.
2. Contrarreembolso: habilitar pago al retirar y, por separado, pago contra entrega si el comercio coordinó la recaudación con el transportista. Registrar el cobro antes de entregar.
3. Zipnova/Zippin: token, secreto, ID de cuenta y origen. Cotizar con productos embalados, crear envío y comprobar seguimiento.
4. ARCA: homologación → certificado y clave PEM → autorización `wsfe` → CUIT, razón social, domicilio e inicio de actividades → punto de venta → emitir prueba y revisar PDF → certificado y habilitación de producción.

La explicación de cada integración, sus fuentes oficiales y los límites del flujo están en `docs/COMERCIO_ONLINE.md`. Los campos secretos vacíos conservan el secreto guardado. Los certificados son privados. No activar producción con comprobantes de homologación sin verificar la configuración real.

## 11. Comprobación final

- `/up` responde correctamente y `/login` abre por HTTPS.
- No se puede descargar `.env` ni navegar carpetas privadas desde el dominio.
- Administrador creado; una cuenta cliente no accede a administración.
- Imagen de producto visible; nombre, marca, IVA, precio y stock correctos.
- Pedido de prueba: reserva stock una vez; la confirmación de pago crea una sola venta.
- Mercado Pago de prueba: notificación recibida y pago acreditado contra API.
- Cotización Zipnova: precio incluido una sola vez; envío y seguimiento verificables.
- Contrarreembolso: permite preparar antes del cobro y bloquea entrega sin cobro.
- ARCA en homologación: PDF, letra, importe, CAE y QR revisados por el comercio.
- Cron activo y copias de seguridad configuradas.

Las pruebas automáticas del repositorio usan respuestas simuladas de proveedores; no sustituyen estas comprobaciones con la cuenta del comercio.

## 12. Actualizar una instalación existente

Antes de reemplazar código, respaldar **la base**, `.env` y **todo** `storage/app` (público y privado). Hacer una copia privada fuera del dominio; conservar permisos y cifrado. No guardar el respaldo en `public/`.

Poner el sitio en mantenimiento desde el código anterior:

```bash
php artisan down --retry=60
```

Subir el nuevo código y sus dependencias conservando `.env`, `storage/app` y las rutas del servidor. No extraer un ZIP encima de la carpeta completa si el panel reemplaza o vacía directorios. Separar los archivos de la nueva versión y copiar sólo código/dependencias; preservar `storage/` y la configuración existente.

Ejecutar:

```bash
bash scripts/update.sh
```

Si actualizás por Git y disponés de Composer:

```bash
git pull --ff-only origin main
bash scripts/update.sh
```

El script no ejecuta `git pull` por su cuenta. Si hay Composer, instala las versiones del lock. Si no lo hay, requiere que hayas cargado el `vendor/` del paquete nuevo. No regenerar `APP_KEY`, no usar `migrate:fresh` y no usar `db:wipe`.

Si falla, el sitio permanece en mantenimiento. Revisar el error y `storage/logs/laravel.log`, corregirlo y repetir el script. Sólo ejecutar `php artisan up` cuando se haya resuelto. Revertir código no revierte las migraciones: una restauración completa debe usar el respaldo coherente de código, base y archivos.

## 13. Errores frecuentes

| Problema | Revisar |
|---|---|
| 403 / listado de carpetas | Dominio apuntando exactamente a `public/`; permisos y `index.php` |
| 404 en todas las rutas salvo inicio | `.htaccess`/mod_rewrite en Apache o `try_files` en Nginx |
| 500 | Versión PHP, extensiones, permisos, log de Laravel y variables de base |
| No hay tablas / error SQL | Ejecutar migraciones sobre la base correcta |
| Login vuelve a pedir acceso | HTTPS, `APP_URL`, cookie segura y tablas de sesiones |
| Imagen no aparece | Enlace `public/storage` y permisos de `storage/app/public` |
| No corre conciliación | Cron, ruta PHP, ruta proyecto y `schedule:list` |
| No llegan pagos | Webhook público sin bloqueo del hosting, secreto y Access Token correctos |
| ARCA no firma | OpenSSL ejecutable, `proc_open`, certificado/clave coincidentes y reloj sincronizado |
| ARCA rechaza permisos | Ambiente correcto, CUIT, asociación `wsfe` y punto de venta habilitado |
| Datos de integraciones no descifran | Restaurar la `APP_KEY` original |

## 14. Tienda anterior en Cloudflare

Si usás la aplicación antigua, configurar en su hosting:

```dotenv
LARAVEL_STORE_URL=https://tu-dominio.com
```

La tienda, cuenta y administración redirigen al portal Laravel. La base D1, pedidos y archivos R2 anteriores no se importan automáticamente: conservarlos y revisar su exportación antes de retirar ese hosting. Los webhooks de pedidos antiguos siguen atendiendo su base anterior.
