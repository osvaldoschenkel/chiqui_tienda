# Arquitectura

Laravel 12, Blade, CSS y JavaScript propios. Base de datos relacional mediante Eloquent. Un negocio por instalación y usuarios administradores creados por consola. Todos los administradores tienen acceso a los mismos registros.

## Capas

- `routes/web.php`: rutas con sesión, autenticación y protección CSRF.
- `app/Http/Controllers`: validación HTTP, consultas, formularios y redirecciones.
- `app/Models`: productos, contactos, operaciones, líneas y movimientos.
- `app/Services/CommerceService.php`: transacciones, stock, cuentas e idempotencia.
- `app/Services/Money.php`: conversión de importes decimales a centavos y formato.
- `database/migrations`: estructura de tablas y claves foráneas.
- `resources/views`: páginas renderizadas por Blade.
- `public/css` y `public/js`: interfaz sin dependencias externas.

## Importes y consistencia

Los importes se almacenan como centavos enteros. No se aceptan totales calculados por el navegador. Los cambios de stock y los movimientos financieros asociados se ejecutan dentro de la misma transacción. Las filas relevantes se bloquean durante la operación en bases que soportan bloqueo por fila.

Cada venta o compra conserva una fotografía del nombre, SKU y precio/costo del artículo. Modificar un producto no altera comprobantes previos. Las anulaciones crean movimientos inversos y no se pueden ejecutar dos veces.

## Entidades

| Tabla | Función |
| --- | --- |
| users | Administradores con contraseña cifrada mediante hash |
| products | Catálogo, precios y stock |
| customers | Clientes y límite de crédito |
| suppliers | Proveedores |
| sales / sale_items | Ventas y detalle histórico |
| purchases / purchase_items | Compras y detalle histórico |
| ledger_entries | Cargos, cobros, pagos y reversiones |
| stock_movements | Ingresos, egresos y ajustes de unidades |

## Alcance

Incluye gestión interna de tienda y caja, cuentas corrientes, proveedores y stock. Pasarela de pago, factura fiscal ARCA, catálogo público con checkout, múltiples empresas, permisos por rol y sincronización sin conexión son integraciones posteriores. La documentación distingue registros internos de comprobantes fiscales y cobros electrónicos reales.

## Seguridad

Las rutas de administración requieren sesión. El login limita intentos y regenera la sesión; logout invalida la sesión. Los formularios incluyen CSRF y las vistas escapan texto. Las imágenes se validan por tipo y tamaño y se almacenan en el disco público; no se permite subir PHP. `.env`, bases de datos, imágenes de usuarios, dependencias y logs quedan excluidos de Git.

No se incluyen tokens GitHub ni credenciales de producción. Mantener el DocumentRoot apuntando a `public/` y `APP_DEBUG=false` en producción.
