# Panel administrativo de la barbería (Fase 6)

Pantallas del panel (`frontend/www/pages/panel/`) y la API que las alimenta (`/api/v1/panel/*`). Todas las rutas exigen sesión, barbería activa (`X-Tenant` o la elegida en la sesión) y el permiso indicado. Ocultar un botón en la interfaz es solo comodidad: el backend vuelve a verificar cada permiso y cada estado.

| Pantalla | Archivo | Qué hace |
|---|---|---|
| Inicio | `inicio.html` | Indicadores del día, avisos (citas por confirmar, ausencias por aprobar), próximas citas, ocupación por barbero y citas de los próximos 7 días |
| Agenda | `agenda.html` | Vista diaria por barbero y semanal, filtros, alta manual, confirmar, atender, no asistió, cancelar, reprogramar, notas, cobros e historial |
| Clientes | `clientes.html` | Búsqueda, ficha con próximas citas e historial, observaciones internas, bloqueo de reserva en línea, alta de clientes de mostrador |
| Reportes | `reportes.html` | Reservas por día, servicios más solicitados, ocupación, cancelaciones, inasistencias, ingresos registrados, evolución mensual y CSV |
| Configuración | `configuracion.html` | Nombre comercial y colores, enlace de reservas, reglas de reserva y política de cancelación (general o por sucursal), accesos a sucursales, horarios, equipo, logo y QR |

Barberos, servicios, sucursales, ausencias y perfil público existen desde la Fase 4 y se enlazan desde el mismo menú. El menú solo muestra los módulos que permite el rol; *Mi cuenta* tiene los botones **Abrir panel** y **Agenda**.

## Permisos

| Permiso | Propietario | Administrador | Barbero |
|---|:-:|:-:|:-:|
| `reservas.ver_todas` (agenda de toda la barbería, inicio) | ✓ | ✓ | |
| `reservas.ver_propias` (solo sus citas) | | | ✓ |
| `reservas.crear` (alta manual, notas, catálogo) | ✓ | ✓ | |
| `reservas.asistencia` (iniciar, atendida, no asistió) | ✓ | ✓ | ✓ |
| `reservas.cancelar`, `reservas.reprogramar` | ✓ | ✓ | |
| `reservas.cobrar` (nuevo, migración 0011) | ✓ | ✓ | |
| `clientes.ver`, `clientes.notas` | ✓ | ✓ | |
| `reportes.operativos` | ✓ | ✓ | |
| `reportes.financieros` (ingresos) | ✓ | | |
| `negocio.perfil.gestionar`, `politicas.gestionar` | ✓ | | |

El barbero ve solo su columna; si pide una cita de otro barbero recibe `404`. Si su usuario no tiene ficha de barbero vinculada, `403 no_barber_profile`.

## Endpoints

```text
GET  /panel/dashboard?fecha=&sucursal_id=                    reservas.ver_todas | reportes.operativos
GET  /panel/reportes?desde=&hasta=&sucursal_id=&barbero_id=  reportes.operativos (máx. 366 días; por defecto últimos 30)

GET  /panel/agenda?fecha=|desde=&hasta=&vista=dia|semana&sucursal_id=&barbero_id=   (máx. 7 días)
GET  /panel/agenda/catalogo                    servicios activos y barberos (servicio_ids, sucursal_ids)
GET  /panel/disponibilidad?sucursal_id=&servicio_ids=&barbero_id=&fecha=
POST /panel/reservas                           alta manual (Idempotency-Key obligatoria)
GET  /panel/reservas/{id}                      detalle + historial + cobros
POST /panel/reservas/{id}/confirmar | iniciar | completar | no-asistio
POST /panel/reservas/{id}/cancelar             { motivo } obligatorio
GET  /panel/reservas/{id}/disponibilidad?fecha=&barbero_id=
POST /panel/reservas/{id}/reprogramar          { fecha, hora, barbero_id } (Idempotency-Key)
PUT  /panel/reservas/{id}/notas                { notas_internas }
POST /panel/reservas/{id}/pagos                { monto, metodo, referencia?, confirmar_excedente? }
POST /panel/reservas/{id}/pagos/{pago}/anular  { motivo }

GET  /panel/clientes?q=&orden=recientes|nombre|reservas&page=&per_page=
POST /panel/clientes                           cliente de mostrador (200 + ya_existia si el contacto existe)
GET  /panel/clientes/{id}                      ficha + resumen (por estado, servicios y barbero frecuentes, total cobrado)
GET  /panel/clientes/{id}/reservas?tipo=proximas|pasadas
PUT  /panel/clientes/{id}                      notas_internas, bloqueado; teléfono/correo solo si es de mostrador

GET  /panel/negocio                            nombre, logo, colores, enlace directo
PUT  /panel/negocio                            nombre_comercial, color_primario, color_secundario (#RRGGBB)
```

Cada cita del panel trae `acciones` (`confirmar`, `iniciar`, `completar`, `no_asistio`, `cancelar`, `reprogramar`, `cobrar`, `notas`) calculadas con su estado, la hora y los permisos de quien consulta. La interfaz muestra solo esos botones.

## Reglas de la agenda

- **Alta manual** usa el mismo motor de la Fase 5 (candados, recálculo, idempotencia, jornada, bloqueos, margen y grilla). La diferencia es la política del personal: sin anticipación mínima y horizonte de 365 días (`BookingPolicy::forStaff()`). Nunca en el pasado. Canal `panel`; estado `confirmada` por defecto o `pendiente`.
- **Cliente**: se elige uno existente o se registra de mostrador (sin cuenta). Si el teléfono o el correo ya existen en la barbería se reutiliza esa ficha. Si el cliente tiene otra cita a la misma hora en otra barbería, el mensaje no revela cuál.
- **Confirmar**: solo pendientes que aún no terminan. Avisa al cliente.
- **Iniciar atención**: desde 30 minutos antes del inicio. **Atendida**: desde ese mismo momento. **No asistió**: desde la hora de inicio, si no se inició la atención.
- **Corrección**: durante 7 días se puede pasar de atendida a no asistió (y al revés), salvo que la cita tenga cobros (`409 booking_has_payments`).
- Las acciones repetidas son idempotentes (confirmar una confirmada devuelve 200 sin duplicar eventos). Cada cambio queda en `reserva_eventos` y en auditoría; las estadísticas del cliente (primera y última visita, inasistencias) se recalculan desde las reservas.
- **Cancelar** exige motivo (queda en el historial, el cliente recibe aviso y se anulan sus recordatorios). Si la cita tenía cobros, la interfaz recuerda anularlos si se devolvió el dinero.
- **Reprogramar** conserva la cita actual hasta que el nuevo horario queda confirmado (decisión 042).
- **Actualización sin recarga**: la agenda se consulta cada 60 s mientras la pestaña está visible y no hay un formulario abierto, al volver a la pestaña y después de cada acción.

## Cobros e ingresos

Tabla `reserva_pagos` (migración 0011): monto (0.01–99 999.99), método (`efectivo`, `tarjeta`, `yape`, `plin`, `transferencia`, `otro`), referencia, quién y cuándo. Un cobro nunca se borra: se **anula** con motivo. Cobrar más que el importe de la cita pide confirmación (`422 payment_exceeds_total` → `confirmar_excedente: true`). No se cobra una cita cancelada.

**Ingresos = suma de cobros no anulados.** El precio de la cita se muestra como "importe previsto", nunca como ingreso. Solo `reportes.financieros` ve ingresos en Inicio y Reportes.

## Cómo se calcula cada métrica

| Métrica | Cálculo |
|---|---|
| Citas del día / reservas | Reservas cuyo inicio cae en la fecha local de la sede (sin contar canceladas en el total) |
| Clientes atendidos | Clientes distintos con cita `completada` ese día |
| Ocupación | Minutos reservados (todo salvo canceladas) ÷ minutos de jornada efectiva (`WorkingTime`: tramos ∩ horario de sede − descansos − bloqueos aprobados), máximo 100 % |
| Tasa de cancelación | Canceladas ÷ reservas del periodo |
| Tasa de inasistencia | No asistió ÷ (atendidas + no asistió); las citas que aún no llegaron no cuentan |
| Clientes nuevos | Clientes cuya primera visita atendida cae en el periodo (solo sin filtro de sucursal o barbero) |
| Servicios más solicitados | Servicios de reservas no canceladas |
| Evolución mensual | Últimos 12 meses hasta el mes final del rango |

Las fechas se agrupan en PHP con la zona de la barbería (no se depende de las tablas de zonas horarias de MySQL).

## Configuración

- **Nombre y colores** (`PUT /panel/negocio`): nombre de 2 a 120 caracteres; colores `#RRGGBB` (se guardan en mayúsculas). Se audita `negocio.actualizado`.
- **Logo, portada, enlace directo y QR**: siguen en *Perfil público* (Fase 3); Configuración enlaza allí y permite copiar el enlace.
- **Reglas de reserva y política de cancelación**: la API de la Fase 5 (`/panel/politicas-reserva`). Se editan la general o las propias de una sucursal, y una sucursal puede volver a las generales.
- **Sucursales y horarios**: accesos a las pantallas de la Fase 4.

## Pruebas

`tests/Integration/Panel/` (PanelAgendaTest y PanelInsightsTest): agenda diaria con jornadas y estados, barbero que solo ve lo suyo, otra barbería sin acceso, alta manual con cliente de mostrador por el mismo motor (y reintento idempotente), anticipación del personal sin permitir el pasado, confirmar → iniciar → atendida → corrección a no asistió, cancelar y reprogramar a otro barbero, notas y cobros (excedente, anulación, cita cancelada), catálogo, búsqueda de clientes (comodines escapados), bloqueo de reserva en línea, dashboard con conteos reales e ingresos solo para el rol financiero, reportes (por día, servicios, ocupación, cancelaciones, mensual, filtros, rango máximo) y configuración del negocio.

Recorrido E2E con Playwright (Chromium, escritorio 1280×900 y móvil 390×844): inicio, agenda diaria y semanal, detalle, alta manual con cliente nuevo, cobro, cancelación, búsqueda y ficha de cliente con notas, reportes con descarga CSV, configuración (negocio y reglas) y agenda del barbero (solo su columna, sin alta manual). Sin desbordes horizontales en móvil.
