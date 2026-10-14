# Comercio online: catálogo, pagos, entregas y facturación

## Una sola fuente de información

La instalación Laravel es el portal operativo: `/tienda`, `/mis-compras` y `/pedidos-clientes`. Productos, proveedores, marcas, compras, ventas, cuenta corriente y pedidos utilizan la misma base. Confirmar el pago crea una venta con los precios originales del pedido y no vuelve a descontar el stock reservado. El dashboard cuenta estas ventas como el resto de las ventas comerciales.

La carpeta `storefront` conserva la aplicación anterior y sus pedidos históricos. No se migra ni elimina su base D1 automáticamente. Configurar `LARAVEL_STORE_URL=https://dominio-del-laravel` en su hosting redirige tienda, cuenta y administración al portal Laravel. Las nuevas compras reales en D1 están deshabilitadas; los webhooks anteriores siguen disponibles para conciliar pagos ya creados. Exportar y revisar pedidos, catálogo y archivos antiguos antes de retirar aquella aplicación. No activar ambos catálogos para nuevas compras.

## Instalación y actualización

1. Respaldar la base y almacenamiento. Conservar `APP_KEY`: las credenciales se cifran con esa clave.
2. Instalar PHP 8.3 con SOAP, OpenSSL, DOM, GD, mbstring, cURL y PDO; instalar el ejecutable OpenSSL. Servir solamente `public/` por HTTPS.
3. Ejecutar `composer install --no-dev --prefer-dist`, `php artisan migrate --force`, `php artisan storage:link` si aún no existe, `php artisan optimize:clear` y `php artisan view:cache`.
4. Agregar `* * * * * cd /ruta/chiqui_tienda && php artisan schedule:run >> /dev/null 2>&1` al cron. La conciliación corre cada cinco minutos. También puede ejecutarse con `php artisan commerce:reconcile`.
5. Si se utiliza más de un servidor, compartir base, almacenamiento privado y un cache que soporte locks (database o Redis). Los locks de pagos, envíos y emisión deben ser comunes a todas las instancias. No usar cache `array` en producción.

## Catálogo

Administrar productos y proveedores desde sus secciones. Marcas tiene alta, listado, edición y eliminación cuando no tiene productos asociados; una marca usada se desactiva. Los productos conservan sus operaciones e historial de stock: se desactivan cuando tienen historial en lugar de eliminarse.

Seleccionar marca e IVA incluido en cada producto. Se admiten 0% (exento), 10,5%, 21% y 27%. No usar 0% para una operación gravada a tasa cero: ese tratamiento fiscal requiere un código diferente. El precio de venta final no aumenta al facturar. Ingresar peso embalado en gramos y medidas en centímetros para logística.

## Configuración de integraciones

El administrador entra a `/integraciones`. Los campos secretos vacíos conservan el valor anterior y nunca se muestran. Los certificados y claves PEM se guardan en almacenamiento privado. También pueden definirse mediante las variables de `config/commerce.php`.

### Mercado Pago

Configurar Access Token y secreto de webhook. Registrar la URL pública `/commerce/mercadopago/webhook` para eventos de pagos. Ningún retorno del navegador confirma el pago: se valida HMAC y se consulta el pago por API, comparando pedido, moneda e importe. El botón de pago del detalle reutiliza la preferencia. Si falla la conexión, el pedido conserva la reserva para reintentar.

Las reservas de Mercado Pago vencen a los 30 minutos. La tarea de conciliación verifica pagos antes de reponer stock. Si no puede consultar al proveedor, conserva la reserva y vuelve a intentar. Un pago tardío en un pedido cancelado se registra para revisar devolución; nunca reabre el pedido. Los reembolsos totales y contracargos informados por el proveedor quedan registrados y anulan la venta comercial, sin reponer automáticamente mercadería. Las devoluciones físicas y notas de crédito requieren revisión administrativa; no se ordenan reembolsos financieros desde el panel.

Fuentes oficiales: [Checkout Pro](https://www.mercadopago.com.ar/developers/en/reference/online-payments/checkout-pro-preferences/create-preference/post), [webhooks](https://www.mercadopago.com.ar/developers/en/docs/checkout-pro-preferences/additional-content/notifications/webhooks).

### Contrarreembolso

Activar pago al retirar. Activar por separado pago contra entrega si el comercio cuenta con una modalidad de recaudación coordinada. El pedido puede prepararse y despacharse antes del cobro, pero sólo se entrega al registrar pago confirmado. El sistema no afirma que Zipnova o cualquier transportista contratado recaude automáticamente: esa capacidad debe acordarse con la cuenta logística. Los pedidos a coordinar y contrarreembolso no se vencen automáticamente como los de Mercado Pago.

### Zipnova / Zippin

Configurar token, secreto, ID de cuenta e ID de origen. Cotizar desde el carrito; cada opción se guarda asociada a usuario, precios, cantidades, medidas y destino. Vence a los 15 minutos y cualquier cambio exige recotizar. Al confirmar se suma el costo guardado, sin aceptar importes del navegador.

Desde pedidos generar el envío después de cobrar, o antes para contrarreembolso habilitado. Se almacena el ID, seguimiento y selección de servicio. Si la creación tiene una respuesta incierta se bloquea otro intento. Buscar el `external_id` `CHIQUI-ID_DEL_PEDIDO` en Zipnova, ingresar el ID existente y usar **Verificar envío y actualizar seguimiento**; sólo se acepta un envío de esa cuenta y pedido. El botón también consulta el estado de un envío ya guardado. Los movimientos de paquetes no acreditan dinero.

Fuentes oficiales: [autenticación](https://docs.zipnova.com/envios/principios/urls-y-autenticacion), [cotización](https://docs.zipnova.com/envios/recursos-api/envios/cotizar-envios), [creación](https://docs.zipnova.com/envios/recursos-api/envios/crear-envios).

### ARCA

1. Configurar homologación, cargar certificado y clave privada coincidentes. Autorizar `wsfe` en WSASS para la CUIT del emisor. El servidor debe tener la hora sincronizada.
2. Cargar razón social, domicilio, CUIT, inicio de actividades, Ingresos Brutos y punto de venta habilitado para Web Services.
3. Completar datos fiscales del comprador en el checkout: nombre, DNI/CUIT y condición IVA. Responsable inscripto y monotributista requieren CUIT válida.
4. Confirmar el pago y usar **Emitir / consultar factura ARCA**. Verificar en homologación el PDF, importe, letra, CAE y vencimiento.
5. Cargar el certificado para producción, comprobar autorizaciones y punto de venta real, elegir producción y confirmar que se realizaron las pruebas. Cargar archivos y seleccionar un ambiente sólo marca preparación local; la primera consulta/emisión verifica permisos reales ante ARCA.

El emisor monotributista o exento utiliza C. El responsable inscripto utiliza A para receptores responsables inscriptos o monotributistas, y B para los demás tipos admitidos. Los importes se calculan en centavos; el desglose de IVA conserva el total final. El costo de envío se trata al 21% para emisores RI. Revisar ese tratamiento si el negocio necesita uno diferente.

Se usa WSAA y WSFEv1. El número se reserva antes de emitir y el reintento consulta ese mismo comprobante. Una emisión pendiente bloquea la siguiente hasta resolverla. Si está autorizada se devuelve la misma factura. El comprador descarga el PDF en Mis compras, con QR y CAE. Un comprobante de homologación se identifica como **sin validez fiscal**. Las notas de crédito, percepción de tributos, exportación y regímenes especiales no forman parte de este flujo de facturación de compras comunes.

Fuentes oficiales: [WSAA](https://www.afip.gob.ar/ws/documentacion/wsaa.asp), [Web Services de factura electrónica](https://www.afip.gob.ar/ws/documentacion/ws-factura-electronica.asp), [manual WSFEv1](https://www.afip.gob.ar/ws/documentacion/manuales/manual-desarrollador-ARCA-COMPG-v4-0.pdf).

## Validación antes de operar

Las pruebas del repositorio simulan las respuestas de proveedores. Ejecutar una compra de prueba con las credenciales del comercio, comprobar alcance del webhook desde Internet, verificar numeración y certificado de ARCA en homologación, generar un envío de prueba en la cuenta logística y revisar el PDF. No se incluyen credenciales reales ni se activan cobros por defecto.
