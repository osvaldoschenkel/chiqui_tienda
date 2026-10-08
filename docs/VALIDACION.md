# Validación de la entrega

Fecha: 7 de octubre de 2026.

Entorno utilizado: PHP 8.3.6, Laravel 12.69.3, SQLite en memoria para pruebas. Las versiones de dependencias se resuelven con `composer.lock` incluido.

## Resultado

**38 pruebas aprobadas, 425 comprobaciones, sin fallos.**

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

También se verificaron `composer validate`, sintaxis de JavaScript, carga de 37 rutas de la aplicación y compilación de Blade.

## Límites de la verificación

No se ejecutó una prueba con base MySQL ni concurrencia real de múltiples conexiones. No se hizo inspección visual en navegador o teléfono físico. La adaptación móvil está implementada mediante CSS responsive; el renderizado HTTP y las plantillas sí fueron verificados.

Repositorio del proyecto: [osvaldoschenkel/chiqui_tienda](https://github.com/osvaldoschenkel/chiqui_tienda), rama `main`, con código, documentación y flujo de GitHub Actions. La entrega no se desplegó en un servidor PHP. El resultado de pruebas indicado arriba corresponde a la ejecución local; los resultados de GitHub Actions se consultan en la pestaña Actions del repositorio.
