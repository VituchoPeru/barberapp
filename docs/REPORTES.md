# Reportes y analítica (Fase 11)

Reportes operativos de cada barbería, calculados solo con datos registrados: reservas, jornadas efectivas y cobros. Amplía la pantalla **Reportes** del panel (Fase 6, ver [PANEL.md](PANEL.md#cómo-se-calcula-cada-métrica)) y agrega exportación a CSV y Excel y una vista de impresión para guardar en PDF.

## Qué muestra

| Bloque | Contenido | Plan |
|---|---|---|
| Indicadores | Reservas, atendidas, confirmadas, cancelaciones, inasistencias, ocupación, clientes recurrentes e ingresos registrados | Todos (ocupación: avanzados) |
| Reservas por día | Barras por día y tabla | Todos |
| Servicios más solicitados | Top 10 de reservas no canceladas | Todos |
| Confirmación y asistencia | Confirmadas por la barbería, asistencia confirmada por el cliente, pendientes sin confirmar | Todos |
| Demanda por día y hora | Mapa de calor día de la semana × hora de inicio, franjas pico y promedio por día de la semana | Avanzados |
| Productividad por barbero | Ocupación, atendidas, clientes, tiempo atendido, atendidas por hora de jornada, inasistencias, duración real, importe previsto y (con permiso financiero) cobrado y ticket promedio | Avanzados |
| Clientes recurrentes | Atendidos, nuevos, recurrentes, visitas por cliente, distribución 1 / 2 / 3+ visitas y los que más vuelven (con `clientes.ver`) | Todos |
| Cancelaciones e inasistencias | Por quién se canceló y tasa de inasistencia | Todos |
| Ingresos registrados | Cobros por día, por método y por barbero; previsto frente a cobrado y saldo por cobrar de las citas atendidas | Todos, con `reportes.financieros` |
| Evolución mensual | 12 meses | Avanzados |

Filtros: periodo (7, 30 o 90 días, este mes, mes anterior o personalizado), sucursal y barbero. El filtro por barbero está en todos los planes. Sin reportes avanzados el periodo máximo es de 31 días (Fase 10); con ellos, 366.

## Endpoints

```text
GET /api/v1/panel/reportes?desde=&hasta=&sucursal_id=&barbero_id=
    reportes.operativos. Devuelve también confirmacion, clientes, demanda, productividad,
    barberos (para el filtro) e ingresos.{por_dia, por_barbero, atendidas}.

GET /api/v1/panel/reportes/exportar?tipo=resumen|reservas&formato=csv|xlsx&desde=&hasta=&sucursal_id=&barbero_id=
    reportes.operativos. Descarga el archivo (Content-Disposition: attachment) y registra
    reportes.exportado en la auditoría con los filtros y el tamaño.
```

- `tipo=resumen`: los mismos bloques del reporte, una tabla por bloque (en Excel, una hoja por bloque).
- `tipo=reservas`: una fila por reserva del periodo (código, fecha y hora local, sucursal, barbero, cliente, servicios, estado, canal, duración, importe previsto, cobrado, saldo, confirmación, reprogramaciones y quién canceló). Máximo 20 000 filas; si hay más se pide un periodo más corto o un filtro (`422`).
- Sin `reportes.financieros` no se exporta ninguna columna de ingresos o cobros. Sin `clientes.ver` no se exporta el nombre del cliente.
- Un barbero de otra barbería o una sucursal ajena responden `404`.

## Cómo se calcula cada indicador nuevo

| Indicador | Cálculo |
|---|---|
| Confirmadas | Reservas no canceladas con `confirmada_en` (la barbería la confirmó, inició o atendió). Tasa = confirmadas ÷ reservas no canceladas |
| Asistencia confirmada por el cliente | Reservas no canceladas con `asistencia_confirmada_en` (enlace o app, Fase 8) |
| Demanda | Reservas no canceladas por día de la semana (lunes = 1) y hora local de inicio. Promedio = reservas ÷ veces que ese día aparece en el periodo. Franja visible 08–20 h, ampliada si hay citas fuera |
| Productividad | Por barbero de la cita. Tiempo atendido = suma de la duración de las atendidas. Atendidas por hora = atendidas ÷ horas de jornada efectiva. Duración real = promedio entre inicio y fin de atención registrados (entre 1 y 600 min) |
| Ticket promedio | Lo cobrado por las citas atendidas del periodo ÷ atendidas que tienen al menos un cobro |
| Clientes nuevos (bloque Clientes) | Atendidos en el periodo sin ninguna visita atendida antes del periodo, con los mismos filtros |
| Clientes recurrentes | Atendidos en el periodo con una visita atendida anterior o con dos o más visitas en el periodo. Con filtro de barbero o sucursal se mide con ese barbero o sede |
| Ingresos registrados | Cobros no anulados (`reserva_pagos`) por fecha de cobro. Nunca el precio de las citas |
| Cobrado y saldo de atendidas | Para las citas atendidas del periodo: previsto = suma de `precio_total`; cobrado = sus cobros no anulados (de cualquier fecha); saldo = suma de lo que falta por cita (un cobro mayor al precio no resta saldo de otra) |

El indicador "Clientes nuevos" de la tarjeta superior de la Fase 6 (primera visita en la barbería según `cliente_tenants`) se mantiene en la API por compatibilidad; la pantalla usa el bloque Clientes, que respeta los filtros.

Todo se agrupa en PHP con la zona horaria de la sucursal o de la barbería. Los cálculos viven en `app/Services/Reports/ReportAnalytics.php` (funciones puras, probadas sin base de datos) y las consultas en `ReportRepository`.

## Exportación

- **CSV** (`Services/Reports/Export/CsvWriter`): UTF-8 con BOM (Excel muestra bien las tildes), coma y CRLF. Los números van sin formato (punto decimal). Un texto que empieza con `=`, `+`, `-`, `@`, tabulación o retorno se antepone con `'` para que Excel o Google Sheets no lo ejecuten como fórmula (inyección CSV).
- **Excel** (`Services/Reports/Export/XlsxWriter`): `.xlsx` real (Office Open XML) generado sin dependencias ni `ext-zip`: el ZIP se arma en PHP con DEFLATE (`zlib`) o sin compresión si no hay zlib. Texto como cadena en línea (nunca fórmula), números como números, encabezado en negrita con la fila fija y ancho de columnas ajustado. Validado al abrirlo con openpyxl (Python) en las pruebas manuales; no se probó en Excel de escritorio.
- **PDF**: botón "Imprimir o guardar en PDF" con una hoja de estilos de impresión (fondo claro, sin menús ni filtros, tablas desplegadas y encabezado con el periodo y los filtros). No se genera en el servidor. En la app nativa (Capacitor) la opción se oculta; la descarga de archivos en Android/iOS se revisa en la Fase 12.

## Pruebas

- `tests/Unit/ReportsUnitTest.php`: celdas CSV (comillas, fórmulas, números negativos), columnas y hojas de Excel, demanda con cambio de día por zona horaria, recurrencia, confirmaciones y saldo por cobrar.
- `tests/Integration/Panel/PanelAnalyticsTest.php`: semana controlada con atendidas, cobro parcial, inasistencia, cancelada, pendiente y visita previa. Comprueba confirmación, demanda, recurrencia (también con filtro de barbero), productividad, previsto frente a cobrado, permisos (administrador sin ingresos, barbero `403`, barbero ajeno `404`), CSV del resumen y del detalle, fórmulas neutralizadas, auditoría y que el `.xlsx` abre con `ZipArchive` y trae las hojas esperadas.
- `tests/Integration/Billing/PlanLimitsTest.php`: en el plan Básico la demanda y la productividad no se calculan, el resto sí, y exportar está disponible.

Resultado al cerrar la Fase 11: 325 pruebas OK en PHP 8.3 (1 omitida); en PHP 8.0.30 pasan 317 y se omiten 8 (sin GD, zbar ni zip para leer el libro en la prueba). Recorrido E2E en Chromium (escritorio 1280×900 y móvil 390×844) con unas 240 reservas extra en la base de desarrollo: indicadores, mapa de demanda, productividad, clientes, ingresos, filtros de barbero y mes anterior, las cuatro descargas, estilos de impresión y PDF, administrador sin ingresos y plan Básico con secciones bloqueadas; 33/33 comprobaciones sin errores de consola ni desbordes.

## Limitaciones

- La exportación se arma en memoria; por eso el detalle se limita a 20 000 reservas por archivo.
- El PDF depende del diálogo de impresión del navegador; no hay PDF generado en el servidor.
- No hay migraciones nuevas: la Fase 11 usa columnas que ya existían (`confirmada_en`, `asistencia_confirmada_en`, `atencion_*`, `reserva_pagos`).
