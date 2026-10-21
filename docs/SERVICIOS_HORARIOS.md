# Servicios, barberos y horarios (Fase 4)

En la Fase 4 se arma el panel de la barbería, que gestiona lo que más adelante se podrá reservar: sucursales y su horario de atención, categorías y servicios, barberos (con foto, servicios y jornada semanal), y vacaciones, ausencias, feriados y bloqueos. Todo vive bajo `/api/v1/panel`, que exige sesión, la cabecera `X-Tenant: {slug}` y una membresía activa en esa barbería (si no la hay, responde 404, igual que para una barbería inexistente).

## Pantallas del panel

Están en `frontend/www/pages/panel/` y comparten `js/panel.js` y `css/panel.css`. Para abrirlas se usa `?tenant={slug}`; "Mi cuenta" muestra los accesos que corresponden al rol. La barra de módulos solo muestra lo que el rol permite, aunque la protección real está en el backend.

| Pantalla | Archivo | Qué hace |
|---|---|---|
| Servicios | `servicios.html` | Lista los servicios agrupados por categoría y permite activarlos o desactivarlos con un interruptor. Desde una hoja se crean y editan con nombre, categoría, tipo, duración, precio y descripción. Las categorías también se gestionan aquí. |
| Barberos | `barberos.html` | Lista los barberos con su foto, sede y estado, y permite registrar uno nuevo. |
| Ficha de barbero | `barbero.html?id=…` | Permite editar los datos y la foto, y elegir los servicios con duración o precio propio. Incluye el editor de jornada semanal con tramos por sede y descansos, un botón para copiar el lunes a martes–sábado y una vista previa de 7 días de la jornada efectiva. |
| Ausencias | `ausencias.html` | Muestra las solicitudes pendientes de aprobar y permite registrar vacaciones, ausencias, feriados y bloqueos por día completo o por horas, para un barbero, una sede o toda la barbería. Avisa cuando hay reservas en conflicto. |
| Sucursales | `sucursales.html` | Gestiona la dirección, las coordenadas, la zona horaria y la sede principal, y el horario de atención por día (hasta 3 tramos). Avisa si el nuevo horario deja fuera jornadas de barberos. |

## Permisos

| Permiso | Propietario | Administrador | Uso |
|---|---|---|---|
| `sucursales.gestionar` | ✔ | — | Escribir sucursales y su horario |
| `servicios.gestionar` | ✔ | ✔ | Servicios y categorías |
| `barberos.gestionar` | ✔ | — | Alta, edición, foto y servicios de barberos |
| `horarios.gestionar` | ✔ | ✔ | Jornada semanal y descansos de cualquier barbero |
| `bloqueos.gestionar` | ✔ | ✔ | Vacaciones, ausencias, feriados, bloqueos y aprobación |

La lectura de sucursales y de barberos se permite con cualquiera de los permisos del catálogo. Por eso el administrador ve la ficha del barbero en solo lectura, pero puede editar su jornada. El barbero que pide su propia ausencia o gestiona su propio horario llega en la Fase 7 (panel del barbero). La solicitud pendiente que traen los datos demo sirve para probar la aprobación.

## Reglas de negocio y dónde se aplican

| Regla | Dónde |
|---|---|
| La duración va de 5 a 600 minutos, en múltiplos de 5. **Nunca es cero.** | `ServiceCatalog::durationError` y `CHECK` en la base. También aplica a la duración propia de cada barbero. |
| El precio va de 0 a 99 999.99 con 2 decimales. **Nunca es negativo.** | `ServiceCatalog::priceError` y `CHECK` en la base. También aplica al precio propio de cada barbero. |
| **Un servicio inactivo no se muestra.** | El marketplace filtra `activo = 1` (prueba `ServicesApiTest`). Eliminar un servicio es un borrado lógico que además quita sus asignaciones a barberos. |
| No puede haber dos servicios activos con el mismo nombre en la barbería. | `ServiceCatalog`. |
| Los tramos de la jornada caen dentro del horario de atención de su sede, y esa sede debe estar activa. | `WeeklySchedule::normalize`. |
| **Un barbero no puede atender en dos sucursales a la vez.** Esto se cumple porque sus tramos de un mismo día no se cruzan, aunque sean de sedes distintas, siempre que sus vigencias coincidan. | `WeeklySchedule::normalize`, con el mensaje "…dos sucursales…". |
| Los descansos caen dentro de un tramo del mismo día y no se superponen entre sí. | `WeeklySchedule::normalize`. |
| **No se ofrecen horarios fuera de la jornada ni intervalos bloqueados.** | `WorkingTime::windows` calcula la jornada efectiva: (jornada ∩ horario de atención) − descansos − bloqueos aprobados. La Fase 5 generará los horarios disponibles solo dentro de estas ventanas. |
| **Una combinación de servicios la realiza el mismo barbero, de forma consecutiva.** | En la Fase 4 se define qué servicios hace cada barbero (`barbero_servicios`), y el marketplace solo ofrece barberos que hacen todos los servicios elegidos. La reserva consecutiva se valida en la Fase 5 sumando las duraciones, propias del barbero si las tiene. |
| Un bloqueo no puede terminar en el pasado ni durar más de 366 días. Si se cruza con reservas activas, responde `409 block_conflicts_bookings` con la lista de esas reservas, y no se crea. | `AgendaBlockService`. |
| Solo una solicitud pendiente se puede aprobar o rechazar (si no, `409 block_not_pending`). Al aprobarla también se comprueba que no haya reservas en conflicto. | `AgendaBlockService`. |
| Un barbero con reservas futuras activas no se puede desactivar ni eliminar (`409 barber_has_bookings`). Sí se le puede apagar "acepta reservas online". | `BarberService`. |
| Una sede con barberos o reservas no se puede desactivar ni eliminar (`409 branch_has_barbers` / `branch_has_bookings`). Tampoco se puede eliminar si figura en jornadas (`409 branch_in_schedules`). La primera sede registrada pasa a ser la principal. | `BranchService`. |
| Una categoría en uso no se elimina (`409 category_in_use`). | `ServiceCatalog`. |
| Una cuenta vinculada a un barbero debe ser miembro de la barbería con rol barbero y no estar vinculada a otro barbero. | `BarberService`. |

Al cambiar la jornada, los descansos o los bloqueos se bloquea la fila del barbero con `SELECT … FOR UPDATE` (decisión 009). Así, una reserva de la Fase 5 que tome el mismo candado siempre valida contra datos actualizados. Cambiar la jornada no cancela reservas existentes: la respuesta trae `advertencias.reservas_fuera_de_jornada` para que la barbería las reprograme.

### Zonas horarias

Las jornadas, los descansos y los horarios de atención se guardan en hora local (`TIME`) de cada sede. Los bloqueos se guardan en UTC. Cuando un bloqueo es de día completo o se escribe con hora local, se convierte a UTC con esta zona:

1. la zona de la sede del bloqueo;
2. si no tiene sede, la de la sede principal del barbero;
3. si no hay ninguna de las dos, la de la barbería.

`GET /panel/barberos/{id}/jornada?desde=&hasta=` (máximo 62 días) devuelve las ventanas efectivas con su hora local y su hora UTC.

## API (`/api/v1/panel`)

| Método y ruta | Permiso | Descripción |
|---|---|---|
| `GET /sucursales` · `GET /sucursales/{id}` | cualquiera del catálogo | Sucursales con horario y nº de barberos activos; `meta.zonas_horarias` |
| `POST /sucursales` · `PUT /sucursales/{id}` · `DELETE /sucursales/{id}` | `sucursales.gestionar` | Alta, edición, activación, principal, baja |
| `PUT /sucursales/{id}/horario` | `sucursales.gestionar` | `{horario: [{dia_semana, hora_apertura, hora_cierre}]}`, hasta 21 tramos; `meta.advertencias.jornadas_fuera_de_horario` |
| `GET/POST /servicios` · `GET/PUT/DELETE /servicios/{id}` | `servicios.gestionar` | Servicios; la edición devuelve `meta.reservas_futuras` |
| `GET/POST /categorias` · `PUT/DELETE /categorias/{id}` | `servicios.gestionar` | Categorías |
| `GET /barberos` · `GET /barberos/{id}` | `barberos.*`, `horarios.*` o `bloqueos.*` | Barberos, servicios, jornada y descansos; el listado trae `meta.cuentas_vinculables` |
| `POST /barberos` · `PUT /barberos/{id}` · `DELETE /barberos/{id}` | `barberos.gestionar` | Alta, edición, activación, baja |
| `PUT /barberos/{id}/servicios` | `barberos.gestionar` | `{servicios: [{servicio_id, duracion_minutos?, precio?}]}` |
| `POST /barberos/{id}/foto` · `DELETE /barberos/{id}/foto` | `barberos.gestionar` | Imagen binaria (`image/*`). Se recorta al cuadrado (mínimo 200 px, máximo 800 px) y se recodifica a WebP. |
| `PUT /barberos/{id}/horario` | `horarios.gestionar` | `{tramos: [{dia_semana, sucursal_id, hora_inicio, hora_fin, vigente_desde?, vigente_hasta?}], descansos: [{dia_semana, hora_inicio, hora_fin, motivo?}]}` |
| `GET /barberos/{id}/jornada?desde=&hasta=` | `horarios.gestionar` | Jornada efectiva por fechas |
| `GET /bloqueos?desde=&hasta=&barbero_id=&sucursal_id=&estado=&tipo=` · `POST /bloqueos` | `bloqueos.gestionar` | `{tipo, barbero_id?, sucursal_id?, dia_completo, fecha_inicio, fecha_fin}` o `{…, inicio, fin}` (`AAAA-MM-DDTHH:MM` local) |
| `POST /bloqueos/{id}/aprobar` · `POST /bloqueos/{id}/rechazar` · `DELETE /bloqueos/{id}` | `bloqueos.gestionar` | Aprobación y baja |

Los errores de validación devuelven `422` con `details.fields`. En la jornada las claves llevan índice (`tramos.2`, `descansos.0`) para que el panel señale el tramo exacto. Toda alta, cambio o baja queda registrada en `auditoria`.

## Base de datos (migración 0009)

- `descansos_barberos`: descansos semanales recurrentes (día 1–7, rango horario y motivo) con FK compuesta a `barberos` y borrado en cascada. Reemplaza la idea inicial de modelar los descansos como huecos entre tramos (ver decisión 032).
- `barberos.especialidad` (texto corto que se muestra en el perfil público).
- `CHECK` de longitud mínima en los nombres de barberos y servicios, e índice `idx_bloqueos_tenant_rango` para buscar bloqueos por fechas.

## Pruebas

- `tests/Unit/ScheduleUnitTest.php` (11 pruebas): operaciones con intervalos, validación de la jornada semanal (fuera de horario, dos sedes a la vez, descansos fuera de tramo, vigencias) y cálculo de la jornada efectiva con bloqueos y zona horaria.
- `tests/Integration/Catalog/` cubre servicios, barberos, sucursales y bloqueos de punta a punta por HTTP:
  - validaciones de duración y precio;
  - que un servicio inactivo no aparezca en el marketplace;
  - permisos por rol y aislamiento entre barberías;
  - conflictos con reservas;
  - aprobación de solicitudes;
  - fotos;
  - auditoría.

Resultado al cerrar la Fase 4:

- **173 pruebas OK en PHP 8.3** (1 omitida porque no está `zbarimg`).
- **En PHP 8.0.30**: 166 OK y 7 omitidas, porque la imagen no tenía GD ni zbar.
- Recorrido E2E en Chromium móvil (390×844) con Playwright: **21/21 comprobaciones OK**. Cubre servicios, categorías, alta de barbero con foto, servicios con precio propio, jornada con descanso, el rechazo de dos sedes a la vez, la aprobación de una ausencia, el conflicto con reservas, sucursal con horario, el reflejo en el marketplace y los permisos del administrador.
