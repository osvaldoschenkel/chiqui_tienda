# Portal cliente de Laravel

La aplicación Laravel de la raíz utiliza correo y contraseña. El directorio `storefront/` conserva una aplicación independiente con acceso mediante ChatGPT; sus integraciones no se activan en Laravel por copiar ese directorio.

## Cuentas y permisos

```sh
php artisan migrate
php artisan admin:create admin@tutienda.com --name="Administrador"
php artisan customer:create cliente@tutienda.com --name="Cliente"
php artisan serve --host=127.0.0.1 --port=8000
```

Los comandos solicitan una contraseña de al menos 12 caracteres y su confirmación de forma oculta. No contienen usuarios ni contraseñas predeterminados. El rol no puede asignarse mediante datos de formularios. La migración conserva como administradores a los operadores anteriores; el rol predeterminado de cuentas nuevas es cliente.

Un administrador administra productos, clientes, proveedores, ventas, compras, configuración y pedidos de clientes. El cliente utiliza el catálogo, el carrito y su historial; el servidor deniega las rutas administrativas y no permite consultar pedidos de otras cuentas.

## Recorrido

1. El administrador crea o edita productos en `/products`.
2. El cliente ingresa a `/tienda`, busca y agrega productos activos al carrito.
3. En `/tienda/carrito` modifica cantidades, quita productos y confirma un pedido con retiro a coordinar.
4. El servidor vuelve a comprobar precios y stock. Guarda importes en centavos y una copia de cada producto; reserva existencias dentro de una transacción y registra el movimiento.
5. El pedido aparece en `/mis-compras` del cliente y en `/pedidos-clientes` del administrador. La repetición del mismo formulario no crea otra reserva.
6. El administrador registra explícitamente un pago recibido fuera del sistema y avanza el pedido: pendiente, preparando, listo para retirar y entregado.
7. Una cancelación de un pedido sin pago confirmado repone las existencias una sola vez. Un pedido cancelado no puede reabrirse. Los pedidos con pago confirmado requieren resolver la devolución fuera de este flujo.

Confirmar un pedido no cobra dinero. El pago comienza pendiente; el registro manual guarda el administrador y la fecha. Los pedidos se mantienen separados de las ventas POS y de las cuentas corrientes para evitar contabilizar como venta un pedido sin cobro. Las reservas pendientes permanecen hasta que se procesa o cancela el pedido.

## Alcance de pagos y logística

En Laravel, este recorrido permite retiro y pago coordinados con la tienda. No contiene todavía Checkout Pro, cotización logística, despacho por API ni devolución automática. Las integraciones Mercado Pago y Zipnova implementadas en `storefront/` pertenecen a esa aplicación y requieren sus credenciales y una publicación adecuada.

## Verificación

```sh
php artisan test
php artisan view:cache
```

Las pruebas utilizan SQLite en memoria e incluyen permisos, aislamiento por cuenta, precios del servidor, stock insuficiente, formularios repetidos, cancelación y registro manual del pago. Las cuentas y los datos usados para revisar el servidor local se guardan únicamente en archivos y bases ignorados por Git.
