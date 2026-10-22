# Motor de reservas (Fase 5)

La Fase 5 convierte la elección de sede, servicios y barbero de la Fase 3 en una reserva real. El cliente ve horarios calculados con la jornada efectiva de la Fase 4, confirma y recibe un comprobante con código. Desde "Mis citas" puede reprogramar, cancelar o confirmar su asistencia. El servidor vuelve a validar todo dentro de una transacción con candados, así que **nunca se crean dos citas cruzadas para el mismo barbero**, aunque lleguen solicitudes en el mismo milisegundo o la red repita la petición.

## Flujo del cliente

```text
Sucursal → Servicios (uno o varios) → Barbero o "cualquiera" → Fecha → Hora → Resumen → Confirmar → Comprobante
```

| Pantalla | Archivo | Qué hace |
|---|---|---|
| Reservar | `pages/reservar.html?slug=…` | Los pasos 1 a 3 vienen de la Fase 3. El paso 4 muestra 14 días con la cantidad de horarios libres de cada uno, y el botón "Ver más fechas" carga más días hasta el horizonte. El paso 5 agrupa las horas en mañana, tarde y noche. El paso 6 resume la cita, acepta una nota de hasta 500 caracteres y explica la política. Si el usuario no ha iniciado sesión, la selección se guarda durante 6 horas y se retoma después del login. |
| Comprobante | `pages/cita.html?id=…` | Muestra el código, la fecha y hora (en la zona de la barbería), el barbero, los servicios con su hora, la dirección y el total. Tiene botones para confirmar asistencia, agregar al calendario (`.ics` generado en el teléfono), ver cómo llegar, escribir por WhatsApp, reprogramar, cancelar y ver el historial de cambios. |
| Mis citas | `pages/mis-citas.html` | Tiene dos pestañas, "Próximas" e "Historial", con paginación. Está en la barra inferior como "Citas". |

Si el horario se ocupa mientras el cliente decide, la API responde `409 slot_unavailable` con hasta 3 horarios cercanos libres (`details.alternativas`). La pantalla los muestra y recarga los horarios. Si se corta la red, el botón se puede volver a tocar sin crear una segunda reserva (ver *Idempotencia*).

## Endpoints

| Método y ruta | Acceso | Uso |
|---|---|---|
| `GET /api/v1/disponibilidad` | Público (sesión opcional) | `slug`, `sucursal_id`, `servicio_ids` (lista o `1,2`, hasta 5), `barbero_id` opcional (sin él significa "cualquiera"). Con `fecha` devuelve `horarios[{hora, inicio_utc, barberos}]`. Sin `fecha` devuelve `dias[{fecha, reservable, disponibles, primera_hora}]` entre `desde` y `hasta` (por defecto 14 días, máximo 31). Siempre incluye zona horaria, hoy, `reservable_hasta`, intervalo, anticipación, rango de duración y precio, y barberos candidatos. |
| `POST /api/v1/reservas` | Sesión + `cliente.reservar` + correo verificado | Cuerpo: `slug, sucursal_id, servicio_ids, barbero_id\|null, fecha, hora, notas?, canal?` (`marketplace`, `enlace`, `qr`, `app`). Requiere la cabecera `Idempotency-Key`. Responde `201` con la reserva presentada. |
| `GET /api/v1/mis-reservas` | Sesión | `tipo=proximas\|pasadas`, `page`, `per_page` (hasta 50). Incluye las citas de todas las barberías del usuario. |
| `GET /api/v1/mis-reservas/{id}` | Sesión, solo el dueño | Detalle con `acciones` (qué puede hacer y hasta cuándo), `politica` e `historial`. |
| `GET /api/v1/mis-reservas/{id}/disponibilidad` | Sesión + `cliente.reservas.gestionar` | Horarios para reprogramar la misma combinación. La propia cita no cuenta como ocupada. |
| `POST /api/v1/mis-reservas/{id}/cancelar` | Sesión + `cliente.reservas.gestionar` | `motivo` opcional. Cancelar una cita ya cancelada responde `200` sin cambios. |
| `POST /api/v1/mis-reservas/{id}/reprogramar` | Sesión + `cliente.reservas.gestionar` + `Idempotency-Key` | `fecha`, `hora` y `barbero_id` opcional (solo si el barbero se había asignado automáticamente). |
| `POST /api/v1/mis-reservas/{id}/confirmar-asistencia` | Sesión + `cliente.reservas.gestionar` | Es idempotente y avisa al barbero. |
| `GET/PUT /api/v1/panel/politicas-reserva`, `DELETE …/{sucursal_id}` | Panel + `politicas.gestionar` (propietario) | Lee y guarda la política general o la de una sucursal. Al borrar la de una sucursal, esa sede vuelve a usar la general. |

La cita de otra persona responde `404` (igual que si no existiera) y queda registrada en la auditoría como `reserva.acceso_ajeno`.

### Errores propios

| Código | HTTP | Cuándo |
|---|---|---|
| `slot_unavailable` | 409 | El horario ya no está libre (incluye `alternativas`). |
| `client_overlap` | 409 | El cliente ya tiene otra cita que se cruza, aunque sea en otra barbería. |
| `booking_not_active` / `booking_started` | 409 | La cita ya terminó, se canceló o ya empezó. |
| `same_slot` | 422 | Se intentó reprogramar al mismo horario. |
| `tenant_unavailable` | 409 | La barbería está suspendida. |
| `too_soon` / `too_far` | 422 | Fuera de la anticipación mínima o del horizonte. |
| `cancellation_window_closed` / `reschedule_window_closed` | 422 | Ya pasó el plazo (el mensaje lo dice en horas). |
| `reschedule_limit` | 422 | Se agotaron los cambios permitidos. |
| `active_bookings_limit` | 422 | Se alcanzó el máximo de citas activas en esa barbería. |
| `client_blocked` | 403 | La barbería bloqueó al cliente. |
| `idempotency_key_required` / `idempotency_key_invalid` | 428 / 422 | Falta la cabecera `Idempotency-Key` o tiene un formato inválido. |
| `idempotency_key_reused` | 422 | Se usó la misma clave con un cuerpo distinto. |

## Cómo se calculan los horarios

1. **Ventanas**: se usa la jornada efectiva de cada barbero candidato (`WorkingTime::windows`, decisión 033): tramos ∩ horario de atención de la sede − descansos − vacaciones, ausencias y bloqueos aprobados.
2. **Ocupado**: se restan las reservas activas (`pendiente` o `confirmada`, columna `ocupa_agenda`), ampliadas por el margen entre citas configurado.
3. **Duración**: es la suma de las duraciones de los servicios elegidos, usando la duración propia del barbero si la tiene. Un servicio de 45 minutos nunca se ofrece a una hora en que cruzaría un descanso o el cierre.
4. **Grilla**: las horas se ofrecen cada `intervalo_minutos` contando desde la medianoche local. También se ofrece el inicio exacto de cada hueco libre (alineado a 5 minutos), así una cita de 45 minutos no deja perdidos 15 minutos.
5. **Límites**: la primera hora posible es ahora + `anticipacion_minima_minutos`, y la última fecha es hoy + `horizonte_maximo_dias` en la zona horaria de la sede.
6. **"Cualquiera disponible"**: una hora se ofrece si al menos un barbero la tiene libre. Al confirmar se asigna el barbero con menos citas ese día, y el comprobante indica `barbero_asignado_auto`. Si empatan, se usa el orden configurado en el panel y luego el id.

Todo se calcula en la zona horaria de la sucursal y se guarda en UTC. El navegador muestra las horas tal como las da el servidor, sin convertirlas a la zona del teléfono.

## Por qué no puede haber doble reserva

Crear o reprogramar sigue siempre el mismo orden dentro de **una** transacción con aislamiento `READ COMMITTED`:

1. Se inserta la clave de idempotencia. Un duplicado espera aquí, en el índice único, y después devuelve la respuesta guardada.
2. Se toma con `SELECT … FOR UPDATE` la fila `cliente_tenants` del cliente. Así se serializan sus propias reservas y se respeta el límite de citas activas.
3. Se toman con `SELECT … FOR UPDATE` las filas `barberos` de **todos** los candidatos, ordenadas por id para evitar bloqueos cruzados. Es el mismo candado que usa el panel al cambiar jornadas y bloqueos (decisión 009).
4. Con los candados tomados se **recalcula la disponibilidad desde cero** y se verifica el solapamiento. Recién entonces se escribe.

Se usa `READ COMMITTED` porque con `REPEATABLE READ` la consulta del paso 4 podría leer una foto tomada antes de esperar el candado y no ver la cita que otra transacción acaba de confirmar. `Database::transaction($fn, Database::READ_COMMITTED)` aplica el nivel solo a la transacción exterior.

`ConcurrencyTest` lanza procesos PHP reales, cada uno con su propia conexión, que arrancan al mismo tiempo. Entre la validación y la escritura se inserta una pausa de 400 ms (`BookingService::$beforeWrite`) para forzar la carrera. Se probó que, al quitar el candado del barbero, estas pruebas fallan.

## Idempotencia

`POST /reservas` y `POST /mis-reservas/{id}/reprogramar` exigen `Idempotency-Key` (8 a 100 caracteres `A-Z a-z 0-9 _ - : .`). La clave pertenece al usuario y al recurso, y se guarda 24 horas en `idempotency_keys` con el hash del cuerpo y la respuesta:

- Si llega la misma clave con el mismo cuerpo, se devuelve la misma respuesta con la cabecera `Idempotent-Replayed: true`, aunque las dos peticiones lleguen a la vez.
- Si llega la misma clave con otro cuerpo, se responde `422 idempotency_key_reused`.
- Si la operación falla, la clave se descarta junto con la transacción, así que se puede reintentar.

El navegador guarda la clave en `sessionStorage` mientras el intento sigue abierto. La descarta cuando el servidor responde (con éxito o con un error definitivo) y la conserva solo si hubo un error de red. `php bin/console reservas:purge` borra las claves vencidas.

## Estados, cancelación y reprogramación

| Estado | Ocupa agenda | Cómo se llega |
|---|---|---|
| `pendiente` | Sí | La política exige confirmación manual (la confirmación desde el panel llega en la Fase 6). |
| `confirmada` | Sí | Confirmación automática (por defecto). |
| `completada` / `no_asistio` | No | Lo marca la barbería (Fase 6). |
| `cancelada` | No | El cliente, el personal o el sistema. Queda en `cancelada_por_tipo`. |

**Cancelar** sigue estos pasos: valida los permisos (solo el dueño), la política (plazo), el estado (activa y no iniciada) y la versión de la fila. Luego cambia el estado, registra el evento y la auditoría (`reserva.cancelada`), lo que libera el horario en ese mismo momento, anula los recordatorios programados y avisa al cliente y al barbero.

**Reprogramar** conserva el horario anterior hasta que el nuevo queda confirmado, porque todo ocurre en la misma transacción. Si el nuevo horario ya no está libre, la cita sigue intacta. Al reprogramar se suma una reprogramación, se reinicia la confirmación de asistencia, se guarda el evento `reprogramada` con la fecha anterior y la nueva, y se audita. Si el barbero no cambia, se mantienen los precios acordados.

Cada cambio queda en `reserva_eventos` (el historial que ve el cliente) y en `auditoria`.

## Política de reserva

Se guarda en `politicas_reserva` (una general por barbería y, si hace falta, una por sucursal). Si no existe ninguna, se usan estos valores por defecto:

| Campo | Defecto | Límite |
|---|---|---|
| `intervalo_minutos` | 15 | 5–120, múltiplo de 5 |
| `margen_entre_citas_minutos` | 0 | 0–120 |
| `anticipacion_minima_minutos` | 60 | 0–10 080 |
| `horizonte_maximo_dias` | 30 | 1–365 |
| `cancelacion_limite_minutos` / `reprogramacion_limite_minutos` | 120 | 0–10 080 |
| `max_reprogramaciones` | 2 | 0–10 |
| `max_reservas_activas_cliente` | 3 | 1–20 |
| `confirmacion_automatica` | sí | — |
| `permite_cualquier_barbero` | sí | — |
| `texto_politica_cancelacion` | — | hasta 1000 caracteres |

## Notificaciones

Por ahora los avisos (creada, reprogramada, cancelada, asistencia confirmada) se guardan como notificaciones `interna` para el cliente y el barbero. Cada aviso tiene una `dedupe_key` única, así que un reintento nunca lo duplica. El envío por correo, push o WhatsApp y los recordatorios automáticos llegan en la Fase 8. Al cancelar ya se anulan los recordatorios que estaban en cola.

## Clientes de mostrador

Si la barbería registró antes a un cliente sin cuenta con el mismo correo, ese registro se vincula a la cuenta del usuario la primera vez que reserva o abre "Mis citas". Esto solo pasa si el correo de la cuenta está **verificado** y hay un único registro sin dueño con ese correo. La vinculación queda en la auditoría como `cliente.vinculado_mostrador`.

## Pruebas

- `tests/Unit/AvailabilityUnitTest.php`: cálculo de horarios sin base de datos (45 minutos y descansos, huecos, solapamiento parcial, margen, anticipación, servicios combinados, política).
- `tests/Integration/Booking/BookingApiTest.php`: servicio de 45 minutos, varios servicios, cancelar y que otro reserve el horario liberado, bloqueo de día completo, cambio de jornada, reintento por red (idempotencia), otra barbería, "cualquiera", ventanas y límites de la política, reprogramación que conserva el horario anterior, asistencia, notificaciones y recordatorios, listas, correo sin verificar y vinculación de cliente de mostrador, y política desde el panel.
- `tests/Integration/Booking/ConcurrencyTest.php`: carreras reales entre procesos (dos clientes en el mismo horario, solapamiento parcial simultáneo, la misma clave dos veces a la vez, tres clientes con "cualquiera" para dos barberos, y cancelación y reserva simultáneas).

## Pendiente en fases siguientes

- Agenda del panel, confirmación manual, marcar como atendida o no asistió, y cancelar o reprogramar desde el panel (Fase 6).
- Panel del barbero (Fase 7).
- Recordatorios y envíos reales por correo, push o WhatsApp (Fase 8).
