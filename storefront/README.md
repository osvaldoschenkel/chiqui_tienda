# Chiqui tienda · Storefront

Tienda de tecnología inspirada en la estructura visual de [Teracomp](https://www.teracomp.com.ar/). Esta carpeta es una aplicación independiente; el proyecto Laravel existente en la raíz del repositorio se mantiene separado.

## Qué incluye

- Catálogo responsive, búsqueda, filtros por categorías y carrito.
- Fichas con galería ordenada de imágenes y videos; imágenes normalizadas a **600 × 600** sin recortar el producto.
- Panel `/cuenta` con compras de la cuenta autenticada, detalle, pago y seguimiento.
- Panel `/admin` para productos, stock, galerías, categorías anidadas, pedidos e integraciones.
- Peso del paquete en gramos y alto, ancho y largo en centímetros.
- Persistencia en Cloudflare D1 y archivos en R2. El carrito se guarda solamente en el navegador.
- Checkout Pro de Mercado Pago y cotización/creación de envíos con Zipnova (antes Zippin).

Los productos iniciales son ejemplos. Un **pedido de prueba** no cobra dinero, no descuenta stock ni contrata un envío. Los botones de cobro y cotización real requieren las credenciales correspondientes.

## Desarrollo

Se requiere Node.js 24 o una versión compatible con el starter (≥22.13).

```sh
npm ci
npm run db:generate
npm run build
node --import ./scripts/sites-env.mjs ./node_modules/wrangler/bin/wrangler.js d1 execute DB --local --config dist/server/wrangler.json --persist-to .wrangler/state --file drizzle/0000_overrated_mauler.sql
npm run dev
```

Abrir la URL local que imprime el servidor. En el perfil portable, `/signin-with-chatgpt?return_to=/admin` establece la identidad **local_seedy** para probar los paneles. Esta simulación no se incluye en producción. No repetir migraciones ya aplicadas; cambios nuevos deben generar migraciones adicionales.

```sh
node --test tests/integrations.test.mjs
npx tsc --noEmit
npm run build
```

## Configuración

Copiar `.env.example` a `.dev.vars` para el entorno local. En Sites, configurar las mismas claves como variables de producción. Marcar los tokens y secretos como secretos; nunca agregarlos al código ni a Git.

| Clave | Uso |
|---|---|
| `ADMIN_EMAILS` | Emails de administradores, separados por coma. Sin esta lista, el admin de producción queda denegado. |
| `SITE_URL` | Origen HTTPS de la tienda publicada. |
| `MERCADOPAGO_ACCESS_TOKEN` | Access Token privado de Mercado Pago. |
| `MERCADOPAGO_WEBHOOK_SECRET` | Secreto para validar notificaciones. |
| `ZIPNOVA_API_TOKEN`, `ZIPNOVA_API_SECRET` | Credenciales privadas de Zipnova. |
| `ZIPNOVA_ACCOUNT_ID` | Cuenta logística. |
| `ZIPNOVA_ORIGIN_ID` | Dirección de origen del address book. |
| `ZIPNOVA_ORIGIN_POSTCODE` | CP de origen para mostrar en la tienda. |

Los visitantes se identifican con Sign in with ChatGPT; cada compra se consulta por el ID autenticado y los permisos admin se verifican en el servidor. Las políticas de acceso de Sites determinan quién puede visitar la publicación. La publicación inicial sigue privada. Para una tienda abierta a clientes, configurar explícitamente su audiencia antes del lanzamiento.

## Mercado Pago

Se crea una preferencia con snapshots de precio calculados en el servidor. La vuelta del navegador a `/cuenta` nunca acredita el pago. El webhook `/api/payments/webhook` valida HMAC y consulta el pago por API; verifica pedido, moneda e importe antes de aprobar. Las consultas autenticadas de compras también reconcilian los pagos pendientes.

Las notificaciones externas necesitan alcanzar el endpoint: una publicación privada puede bloquearlas en el acceso de Sites. Antes de activar cobros de producción, habilitar una publicación adecuada para clientes y verificar el webhook con credenciales de prueba. No cambiar la audiencia automáticamente. Reservas de stock vencen a los 30 minutos y se verifican y liberan al consultar las compras o antes de un nuevo checkout; si llega un pago a un pedido cancelado, se marca para revisión de devolución.

## Zipnova / Zippin

Las cotizaciones se calculan con peso y medidas reales de cada unidad, se guardan del lado servidor y vencen a los 15 minutos. Para enviar los datos a Zipnova, las dimensiones se redondean hacia arriba al centímetro y el peso mínimo del paquete es 10 g; las medidas originales del producto se conservan. Un cambio de carrito o destino exige recotizar. El admin puede generar el envío solamente después de un pago aprobado; no se despacha automáticamente. Retiro en el local permanece disponible sin la integración.

Fuentes: [API de Zipnova](https://docs.zipnova.com/envios/principios/urls-y-autenticacion), [cotización](https://docs.zipnova.com/envios/recursos-api/envios/cotizar-envios), [Mercado Pago Checkout Pro](https://www.mercadopago.com.ar/developers/en/reference/online-payments/checkout-pro-preferences/create-preference/post).

## Publicación

El proyecto usa el starter Vinext y la integración Sites de `vite.config.ts`. El manifiesto `.openai/hosting.json` declara las vinculaciones `DB` y `BUCKET`; las migraciones se aplican durante la publicación. Mantener la identidad y los permisos del sitio al actualizarlo. No subir `node_modules`, `dist`, `.sites-runtime`, `.wrangler`, `.dev.vars` ni credenciales.
