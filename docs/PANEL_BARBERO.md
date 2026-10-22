# Panel del barbero (Fase 7)

Interfaz móvil y simplificada para quien atiende (`frontend/www/pages/barbero/`) y la API que la alimenta (`/api/v1/barbero/*`). Todo se limita al **barbero vinculado a la cuenta** (`barberos.usuario_id`): aquí nunca se ve a otros trabajadores, aunque quien entra sea el propietario que también corta.

| Pantalla | Archivo | Qué hace |
|---|---|---|
| Hoy | `index.html` | La cita **en atención** o la **siguiente** (con cuánto falta) y sus botones grandes; indicadores del día; agenda del día con jornada, huecos libres, descansos, ausencias y la hora actual; navegación por días |
| Citas | `citas.html` | **Próximas** (7, 14 o 31 días, agrupadas por día) e **Historial** (7 a 365 días, por estado) con atendidas, inasistencias, canceladas y tasa de inasistencia |
| Horario | `horario.html` | Jornada semanal con descansos, tiempo reservable de los próximos 14 días (barras por día) y ausencias próximas. Editor de la jornada si la barbería lo permite |
| Ausencias | `ausencias.html` | Solicitar vacaciones, ausencias, descansos puntuales o bloqueos; ver pendientes, aprobadas, rechazadas (con el comentario del encargado) y feriados de la barbería; retirar una solicitud pendiente |

Al tocar una cita se abre su ficha: servicios con hora de cada uno, botones **Llamar** y **WhatsApp**, nota del cliente, notas internas editables, **visitas anteriores del cliente con este barbero** e historial de la cita. Las acciones (Iniciar atención, Marcar atendido, Confirmar, No asistió) aparecen solo cuando la API las permite.

**Accesos.** En *Mi cuenta*, quien tiene rol barbero ve **Mi agenda**. Si un barbero abre el panel de la barbería, `inicio.html` lo lleva a su agenda. El menú del panel muestra **Mi agenda** a cualquier cuenta con ficha de barbero, y el panel del barbero muestra **Panel** a quien además gestiona la barbería. Sin ficha vinculada, la pantalla lo explica (`403 no_barber_profile`).

La agenda de Hoy se vuelve a consultar cada 60 s con la pestaña visible y sin hojas abiertas, y al volver a la pestaña (decisión 053).

## Qué ve y qué no ve el barbero

| Ve | No ve |
|---|---|
| Sus citas de todas sus sedes | Citas de otros barberos (`404`, no se revela que existen) |
| Nombre y teléfono del cliente, nota del cliente, notas internas | Correo del cliente |
| Servicios con duración y precio de lista | Cobrado, saldo, cobros e ingresos |
| Sus visitas anteriores con ese cliente | Visitas del cliente con otros barberos |
| Su jornada, descansos y ausencias; feriados de la barbería | Reportes, dashboard, clientes, equipo, ajustes (`403`) |

## Endpoints

Todas exigen sesión, barbería activa (`X-Tenant` o la de la sesión) y ficha de barbero vinculada.

```text
GET    /barbero/hoy?fecha=                      reservas.ver_propias | reservas.ver_todas
GET    /barbero/proximas?dias=1..31             (por defecto 14)
GET    /barbero/historial?desde=&hasta=&estado=&page=&per_page=   (máx. 366 días; por defecto últimos 30; hasta ahora)
GET    /barbero/citas/{id}                      detalle + historial + visitas_previas
POST   /barbero/citas/{id}/iniciar | completar | no-asistio        reservas.asistencia
POST   /barbero/citas/{id}/confirmar            reservas.asistencia | reservas.crear
PUT    /barbero/citas/{id}/notas                { notas_internas }
GET    /barbero/horario?desde=&dias=1..31       jornada semanal, días con ventanas, sedes y su horario, ausencias
PUT    /barbero/horario                         { tramos, descansos }  horarios.propios + ajuste del equipo (o horarios.gestionar)
GET    /barbero/ausencias                       sus solicitudes y bloqueos + feriados aprobados
POST   /barbero/ausencias                       bloqueos.solicitar | bloqueos.gestionar
DELETE /barbero/ausencias/{id}                  retirar una solicitud propia aún pendiente

GET    /panel/equipo/ajustes                    personal.gestionar
PUT    /panel/equipo/ajustes                    { ausencias_requieren_aprobacion?, barbero_edita_horario? }
POST   /panel/bloqueos/{id}/aprobar | rechazar  { respuesta? } (nuevo: comentario que ve el barbero)
GET    /panel/contexto                          ahora incluye barbero: {id, nombre, foto_url} | null
```

`GET /barbero/hoy` devuelve `jornada` (ventanas efectivas en hora local), `descansos`, `bloqueos` del día (propios aprobados o pendientes y generales aprobados), `citas`, `en_atencion_id`, `siguiente_id`, `resumen` (por estado, `minutos_reservados`, `minutos_jornada`), `solicitudes_pendientes` y `ajustes` (`puede_solicitar_ausencias`, `ausencias_requieren_aprobacion`, `puede_editar_horario`).

Las acciones de atención reutilizan `AgendaService` con el alcance fijado al barbero: mismas reglas de la Fase 6 (iniciar y atendida desde 30 min antes, no asistió desde la hora de inicio, corrección durante 7 días sin cobros), mismos candados, eventos y auditoría.

## Ausencias: solicitud y aprobación

1. El barbero elige tipo (`vacaciones`, `ausencia`, `descanso`, `bloqueo`; los feriados los define la barbería), día completo o rango de horas, sede (o todas sus sedes) y motivo.
2. **Si la barbería exige aprobación** (por defecto), queda `solicitado`:
   - El motivo es obligatorio.
   - **No cambia la disponibilidad** hasta que se apruebe.
   - Si ya hay citas en ese rango, la respuesta las incluye en `advertencias.reservas_en_conflicto`: el encargado deberá reprogramarlas antes de aprobar (aprobar encima de citas sigue dando `409 block_conflicts_bookings`, decisión 035).
   - Se avisa a quienes pueden aprobar (`bloqueos.gestionar`) con una notificación interna.
3. **Si no exige aprobación**, o quien pide puede aprobar (el propietario que también atiende), se registra `aprobado` con las mismas reglas del panel: `409` si pisa citas activas.
4. Se rechaza con `409 block_overlaps` una solicitud que se cruce con otra suya pendiente o aprobada, y con `429` si ya tiene 10 sin responder.
5. El encargado aprueba o rechaza en *Ausencias* con un **comentario opcional** que el barbero ve; se guarda quién y cuándo (`respuesta`, `respondido_en`) y el barbero recibe un aviso interno.
6. El barbero puede **retirar** una solicitud mientras está pendiente (`bloqueo.retirado` en auditoría).

Todas las escrituras toman el candado de la agenda del barbero (`barberos … FOR UPDATE`) y, al aprobar o rechazar, el de la solicitud, así una aprobación y una reserva nunca se validan a la vez con datos viejos.

## Ajustes del equipo

En *Configuración → Equipo: panel del barbero* (permiso `personal.gestionar`, propietario). Se guardan en `configuraciones` por barbería (`equipo.*`); sin fila se usan los valores prudentes:

| Ajuste | Por defecto | Efecto |
|---|---|---|
| `ausencias_requieren_aprobacion` | Sí | Las ausencias de los barberos esperan aprobación |
| `barbero_edita_horario` | No | El barbero puede cambiar su jornada semanal (permiso `horarios.propios`, que ya tenía el rol barbero desde la Fase 2) |

El editor de jornada del barbero usa las mismas reglas que el panel (`WeeklySchedule`): cada tramo dentro del horario de la sede, sin cruces entre sedes, descansos dentro de un tramo. Las citas ya tomadas que queden fuera **se respetan** y se informan (`advertencias.reservas_fuera_de_jornada`, decisión 035). Cada cambio queda en auditoría (`barbero.horario_actualizado`, `equipo.ajustes_actualizados`).

## Base de datos

Migración `0012_panel_barbero.sql`:

- `bloqueos_agenda.respuesta` (comentario del encargado) y `bloqueos_agenda.respondido_en` (UTC).
- Índices `idx_bloqueos_estado (tenant_id, estado, inicio_utc)` e `idx_bloqueos_solicitante (tenant_id, creado_por, estado)`.
- Rellena `respondido_en` de las solicitudes ya atendidas.

Avisos internos nuevos en `notificaciones` (canal `interna`, `dedupe_key` única): `bloqueo_solicitado`, `bloqueo_aprobado`, `bloqueo_rechazado`. Su envío por push o correo llega con la Fase 8.

## Pruebas

`tests/Integration/Barber/BarberPanelTest.php` (11 pruebas): agenda del día solo con citas propias, jornada sin el almuerzo y sin datos de caja ni correo; siguiente y en atención según la hora; iniciar, atendida, notas y no asistió (con la hora mínima), 404 en citas ajenas y 403 en reportes, dashboard, barberos, personal y ajustes; visitas anteriores solo con el mismo barbero; accesos (sin ficha `403 no_barber_profile`, cliente `404`, sin sesión `401`, contexto con `barbero`); propietario que también atiende (solo su columna y aprobación propia); próximas e historial con totales y tasa de inasistencia; horario en solo lectura hasta que el propietario lo habilita (y solo en su barbería); solicitud con motivo obligatorio, sin feriados, sin cambiar la disponibilidad hasta aprobar, avisos a propietario y administrador, cruce `409`, aprobación con comentario y aviso al barbero; rechazo con comentario, aprobación imposible encima de citas, retirar y aislamiento entre barberías; barbería sin aprobación previa.

Recorrido E2E con Playwright (Chromium, móvil 390×844 y escritorio 1280×900): *Mi cuenta → Mi agenda*, redirección desde el panel, Hoy y día siguiente (citas, huecos libres, almuerzo), ficha con notas, Citas (próximas e historial), Horario, solicitud de ausencia con motivo obligatorio, rechazo con comentario desde el panel del propietario, ajuste del equipo, comentario visible para el barbero y edición de su jornada; administrador sin ficha. Sin errores de consola ni desbordes horizontales.
