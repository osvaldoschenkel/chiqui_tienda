# Dashboard y personalización

## Períodos y actualización

El dashboard abre en «Este mes»: desde el primer día del mes hasta hoy. También permite «Hoy», «Ayer», «7 días», «30 días» y un período personalizado de hasta 366 días, con ambas fechas incluidas. No admite fechas posteriores a hoy ni anteriores al 01/01/2000.

Los filtros, gráficos, productos más vendidos y ventas recientes consultan registros guardados en el servidor. La vista inicial y el formulario de fechas funcionan sin JavaScript; con JavaScript los filtros actualizan el contenido sin recargar toda la página. Un fallo de conexión conserva los últimos resultados y muestra un mensaje para reintentar.

La consulta automática se realiza cada 60 segundos mientras la página está visible y puede desactivarse. También se puede solicitar una actualización manual. Los datos reflejan la última consulta; no se utiliza WebSocket.

El gráfico permite alternar importe y cantidad de ventas. Al tocar un punto, pasar el puntero o explorar con las flechas del teclado se muestran su fecha, valor y día equivalente anterior. «Ver datos del gráfico» ofrece una tabla del período.

## Definición de las métricas

| Métrica | Criterio |
| --- | --- |
| Ventas | Suma de los totales históricos de ventas completadas en el período |
| Operaciones | Cantidad de ventas completadas |
| Ticket promedio | Total vendido dividido por operaciones, redondeado al centavo |
| Unidades vendidas | Suma de cantidades de las líneas de esas ventas |
| Compras | Total de compras completadas en el período |
| Medios de pago | Distribución del total vendido por efectivo, tarjeta, transferencia y cuenta corriente |
| Productos más vendidos | Hasta cinco productos, ordenados por unidades vendidas y luego importe; cantidades e importes históricos, nombre y SKU actuales del catálogo |
| Saldos de clientes y proveedores | Saldo contable neto actual de todos los movimientos, independiente del período consultado |
| Stock bajo | Productos activos cuyo stock actual es menor o igual al mínimo |

Las operaciones anuladas no suman ventas, compras, unidades, rankings ni medios de pago. Una venta en cuenta corriente representa deuda registrada; su inclusión en el total vendido no significa que se haya cobrado. El dashboard no calcula utilidad ni margen porque los costos de venta no se conservan como una fotografía histórica.

La comparación toma la misma cantidad de días inmediatamente anterior. Por ejemplo, el 1 al 7 de octubre se compara con el 24 al 30 de septiembre. Si el período anterior no tiene ventas, se indica que no hay base para calcular una variación porcentual. Los días sin movimientos figuran con cero y el gráfico alinea los días de ambos períodos por su posición.

Las fechas de negocio utilizan `America/Argentina/Buenos_Aires`. Se convierte cada límite diario a `APP_TIMEZONE` para consultar los registros, incluso cuando el almacenamiento usa UTC. Cambiar `APP_TIMEZONE` en una base ya existente requiere preservar la interpretación de sus fechas anteriores.

## Mi tienda

Todos los usuarios administradores de la instalación pueden guardar:

- Nombre de hasta 80 caracteres y frase de hasta 160.
- Color terracota, violeta, bosque o azul.
- Logo JPEG, PNG o WebP de hasta 2 MB, con opciones de reemplazarlo o quitarlo.

El formulario ofrece una vista previa antes de guardar. La marca se aplica a la navegación, la pantalla de ingreso, los títulos, el pie de página y los comprobantes internos. Estos últimos conservan sus números, productos y valores históricos; la marca refleja la identidad actual de la tienda.

Ejecutá `php artisan storage:link` para que se vean los logos guardados. Las imágenes de los usuarios y los valores de identidad de la base de datos no se incluyen en el repositorio. La apariencia inicial conserva «Chiqui Tienda» y el color terracota.

## Actualizar una instalación existente

Después de obtener los cambios del repositorio, ejecutá las migraciones para crear la tabla de identidad y los índices del dashboard:

```bash
git pull --ff-only
composer install --no-interaction
php artisan migrate --force
php artisan optimize:clear
php artisan storage:link
```

En producción, volvé a generar las cachés de configuración y vistas según [Instalación](INSTALACION.md). Conservá una copia de la base y las imágenes antes de actualizar.
