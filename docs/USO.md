# Uso de Chiqui Tienda

## Orden recomendado

1. Crear proveedores y clientes.
2. Dar de alta productos con SKU único, costo, precio, categoría, imagen, stock inicial y mínimo.
3. Registrar compras para ingresar mercadería y ventas para descontarla.
4. Registrar cobranzas o pagos desde la cuenta corriente correspondiente.

## Productos

El stock se expresa en unidades enteras. Los importes se cargan en pesos con hasta dos decimales, sin separador de miles. El stock mínimo permite detectar faltantes en el panel. Los productos inactivos se conservan para el historial y quedan fuera de nuevas operaciones.

Los ajustes manuales de stock generan un movimiento de auditoría. Una compra aumenta stock; una venta lo disminuye. No se permiten ventas sin stock suficiente.

## Clientes y cuenta corriente

El saldo positivo significa que el cliente debe dinero a la tienda. Un cargo incrementa la deuda y un cobro la disminuye. Un saldo negativo representa crédito a favor del cliente. Una venta con medio «Cuenta corriente» registra automáticamente el cargo.

El límite de crédito se carga en pesos; cero permite operar sin límite. La validación se realiza en el servidor y las operaciones que exceden un límite positivo se rechazan.

## Proveedores

El saldo positivo significa que la tienda le debe dinero al proveedor. Una compra en cuenta corriente aumenta la deuda y un pago la reduce. Las compras pagadas al contado quedan registradas sin generar deuda.

## Ventas y compras

Seleccionar los artículos, las cantidades y el medio de pago. Para una venta en cuenta corriente se exige cliente; para una compra se exige proveedor. El servidor calcula los totales desde los productos y valida existencias.

Efectivo, tarjeta y transferencia son registros de un pago realizado por otro medio. La aplicación no cobra tarjetas ni verifica transferencias bancarias. El comprobante de una operación es un documento interno y puede imprimirse desde el navegador.

## Anulación e historial

Las operaciones confirmadas se anulan mediante una acción explícita; no se reescriben sus importes. Anular una venta restaura el stock y revierte su cargo en cuenta corriente cuando corresponde. Anular una compra retira su stock y revierte su deuda; se rechaza si ese stock ya no está disponible.

Cada venta, compra y movimiento manual de cuenta incluye un identificador que evita duplicarlo al reenviar el mismo formulario. Si se reenvía con datos diferentes el servidor la rechaza.

Los movimientos financieros quedan registrados y no tienen edición ni eliminación. Los clientes, proveedores y productos con historial deben desactivarse en lugar de eliminarse.

## Celular

El panel adapta navegación, formularios, listados y caja a pantallas pequeñas. Los cambios se guardan en el servidor: requieren conexión. No incluye sincronización sin conexión ni aplicación nativa Android/iOS.
