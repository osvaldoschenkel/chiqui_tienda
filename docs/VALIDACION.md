# Validación de la entrega

Fecha: 7 de octubre de 2026.

Entorno utilizado: PHP 8.3.6, Laravel 12.69.3, SQLite en memoria para pruebas. Las versiones de dependencias se resuelven con `composer.lock` incluido.

## Resultado

**57 pruebas aprobadas, 818 comprobaciones, sin fallos.**

- Autenticación, límites de intentos, regeneración de sesión y cierre de sesión.
- Acceso protegido a las rutas y creación del administrador con contraseña oculta.
- CRUD de productos, clientes y proveedores; filtros y búsqueda.
- Validación real de imágenes y limpieza de archivos ante errores.
- Conservación de registros vinculados al historial.
- Venta con precios calculados por el servidor, stock, crédito y comprobante.
- Compra con costo, stock y cuenta corriente del proveedor.
- Anulaciones, reversiones e insuficiencia de stock.
- Identificadores de operación para evitar duplicados y rechazo de datos diferentes con el mismo identificador.
- Rechazo de ediciones de stock desde formularios desactualizados.
- Escape de texto aportado por el usuario y renderizado de las páginas principales.
- Dashboard: fechas inclusivas, comparación de períodos, anulaciones, días sin ventas, importes históricos, promedio con centavos enteros y saldos actuales.
- Límites diarios con almacenamiento en UTC y Buenos Aires, incluido un cambio horario histórico.
- Identidad de tienda: persistencia, paleta permitida, escape de texto, validación real de logos y reemplazo/eliminación de imágenes.

También se verificaron `composer validate`, sintaxis de JavaScript, carga de las rutas de la aplicación y compilación de Blade.

En Chromium headless se comprobó la interfaz en 1440 y 360 píxeles: filtros sin recarga del documento, cambio de métrica del gráfico, detalle por teclado, conservación de datos ante error de conexión, recuperación, rechazo de fechas futuras, menú móvil y vista previa/guardado de identidad. Dashboard, personalización y formulario de venta no presentan desbordamiento horizontal en esos anchos. No se registraron errores de JavaScript en ese recorrido.

Las capturas de `docs/images/` usan una base aislada con datos sintéticos para mostrar la interfaz. Esos registros no se instalan ni forman parte de la base de la tienda.

## Límites de la verificación

No se ejecutó una prueba con base MySQL ni concurrencia real de múltiples conexiones. La inspección visual se hizo en un navegador automatizado; no se probó un teléfono físico. La adaptación móvil está implementada mediante CSS responsive y se comprobó en un ancho de 360 píxeles.

Repositorio del proyecto: [osvaldoschenkel/chiqui_tienda](https://github.com/osvaldoschenkel/chiqui_tienda), rama `main`, con código, documentación y flujo de GitHub Actions. La entrega no se desplegó en un servidor PHP. El resultado de pruebas indicado arriba corresponde a la ejecución local; los resultados de GitHub Actions se consultan en la pestaña Actions del repositorio.
