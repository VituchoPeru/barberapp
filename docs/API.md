# API REST de BARBERAPP

La especificación completa está en **[`docs/api/openapi.json`](api/openapi.json)** (OpenAPI 3.1). Para leerla con buscador y ejemplos abre [`docs/api/index.html`](api/index.html) desde un servidor (por ejemplo `php -S 127.0.0.1:8090 -t docs` y luego `http://127.0.0.1:8090/api/`). También se puede importar en Postman, Insomnia o Bruno.

La especificación **se genera desde las rutas reales** (`backend/routes/api.php`) y desde las fichas de `docs/fuente/api/*.php`, que describen cada ruta con la misma notación de reglas del `Validator`. `php bin/console docs:generar` la vuelve a escribir. La prueba `DocumentationTest` y el trabajo de CI fallan cuando:

- hay una ruta sin documentar o una ficha sin ruta;
- un código de error citado no existe en el código;
- un campo documentado no aparece en el código;
- el archivo publicado quedó desactualizado.

Este documento resume las convenciones y lista todos los endpoints. La guía por tema está en [AUTENTICACION.md](AUTENTICACION.md), [MARKETPLACE.md](MARKETPLACE.md), [RESERVAS.md](RESERVAS.md), [PANEL.md](PANEL.md), [PANEL_BARBERO.md](PANEL_BARBERO.md), [RECORDATORIOS.md](RECORDATORIOS.md), [SUPERADMIN.md](SUPERADMIN.md), [MONETIZACION.md](MONETIZACION.md), [REPORTES.md](REPORTES.md) y [SEGURIDAD.md](SEGURIDAD.md).

## 1. Convenciones

| Tema | Regla |
|---|---|
| Base | `https://<dominio-api>/api/v1`. En XAMPP: `http://localhost/barberapp/backend/public/api/v1`. Con `php -S 127.0.0.1:8080 -t public`: `http://127.0.0.1:8080/api/v1`. |
| Formato | JSON UTF-8 en el cuerpo y en la respuesta. Excepciones: la subida de fotos (`multipart/form-data`) y las exportaciones CSV/XLSX. |
| Sobre de respuesta | Éxito: `{"ok": true, "data": …, "meta": {…}}`. Error: `{"ok": false, "error": {"code", "message", "details"}, "meta": {…}}`. `meta.request_id` coincide con la cabecera `X-Request-Id` y con los registros del servidor. |
| Errores | `error.code` es estable y es lo que el programa debe evaluar; `error.message` está en español y se puede mostrar a la persona. Con `validation_failed` (422), `details.fields` trae `{campo: [mensajes]}`. La tabla completa está en la [sección 4](#4-códigos-de-error). |
| Sesión web | Cookie `HttpOnly` `barberapp_session`. Toda petición que modifica datos con esa cookie debe enviar `X-CSRF-Token` (se obtiene al iniciar sesión o con `GET /auth/csrf`). |
| App móvil | `Authorization: Bearer <access_token>` (15 minutos). Al vencer, `POST /auth/refresh` con el refresh token rotativo: cada uno sirve una sola vez. Con bearer no se pide CSRF. |
| Barbería (tenant) | Las rutas `/panel/*` y `/barbero/*` trabajan sobre la barbería elegida con `POST /me/context` o enviada en `X-Tenant: <slug>`. La API nunca toma la barbería de un id del cliente: siempre comprueba la membresía activa. |
| Permisos | Cada operación indica en la especificación `x-permisos` (por ejemplo `reservas.gestionar`), `x-plataforma` (solo superadministrador u operador) y `x-requiere-correo-verificado`. La matriz completa por rol está en [MATRIZ_ACCESO.md](MATRIZ_ACCESO.md). |
| Idempotencia | Crear y reprogramar una reserva exige `Idempotency-Key` (8 a 100 caracteres). Si la red falla, se reintenta con la misma clave y se recibe la misma respuesta sin duplicar la cita. La clave dura 24 horas. Reutilizarla con otros datos responde 422 `idempotency_key_reused`. |
| Paginación | `?page=1&per_page=20`. La respuesta trae `meta.page`, `meta.per_page` y `meta.total`. Cada ruta indica su valor por defecto y su máximo. |
| Límites | Las escrituras están limitadas por IP (`RATE_LIMIT_WRITES`, 120 por minuto por defecto; los webhooks no cuentan), y hay límites propios para inicio de sesión, recuperación, registro y exportaciones. Al superarlos se responde 429 `too_many_requests` con `Retry-After`. Durante un despliegue la API responde 503 `maintenance` con `Retry-After`. |
| Fechas | Los instantes van en UTC ISO 8601 (`2026-10-23T15:00:00Z`). Las fechas de agenda (`YYYY-MM-DD`) y las horas (`HH:MM`) son locales de la sucursal, con su zona horaria (`America/Lima`). |
| Dinero | Los importes se envían como `DECIMAL(10,2)`, ya sea número o texto (`"35.00"`), con `moneda` ISO 4217 (`PEN`). El precio de una reserva es el **previsto**; lo cobrado de verdad está en los cobros de la cita. |
| Versiones | Un cambio incompatible irá en `/api/v2`. Dentro de `v1` solo se agregan campos y rutas. |

## 2. Primeros pasos con curl

```bash
API=http://127.0.0.1:8080/api/v1

# Iniciar sesión como app móvil (usuarios del seeder de demostración, ver README)
curl -s $API/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"cliente1@barberapp.test","password":"Demo2026!","canal":"movil","dispositivo":"curl"}'
TOKEN=...   # data.access_token

# Buscar barberías y ver horarios libres (fecha local de la sucursal)
curl -s "$API/barberias?q=demo"
curl -s "$API/disponibilidad?slug=demo-centro&sucursal_id=1&servicio_ids=1&fecha=2026-10-24"

# Reservar (Idempotency-Key nueva por intento; repetirla al reintentar). Requiere correo verificado.
curl -s $API/reservas -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"slug":"demo-centro","sucursal_id":1,"servicio_ids":[1],"fecha":"2026-10-24","hora":"15:00"}'
```

Los nombres exactos de los campos y las respuestas de cada ruta están en la especificación. Los datos de arriba son los del seeder de demostración (`php bin/console db:seed --demo`) y no son de clientes reales.

## 3. Endpoints

Columna **Acceso**:

- **pública**: sin sesión.
- **sesión**: cualquier cuenta con sesión iniciada.
- **sesión opcional**: la ruta funciona sin sesión, pero si la hay agrega datos.
- **plataforma**: superadministrador u operador de la plataforma.
- **MONITOR_TOKEN**: token del monitoreo externo.
- Permiso entre comillas invertidas (por ejemplo `reservas.gestionar`): permiso de la barbería que se exige.

<!-- generado:endpoints -->
### Salud y sistema

Verificación de salud, textos legales y webhooks de la pasarela de pago.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/health` | Estado de la aplicación, la base de datos y las migraciones | pública |
| GET | `/health/live` | Confirma que PHP responde (sin consultar la base) | pública |
| GET | `/health/operacion` | Revisión operativa completa para monitores externos | MONITOR_TOKEN |
| GET | `/legal` | Versión vigente de términos y privacidad y datos del responsable del tratamiento | pública |
| POST | `/webhooks/pagos/{proveedor}` | Notificación de la pasarela de pago (Mercado Pago o simulador) | pública |

### Autenticación

Registro, inicio y cierre de sesión, renovación de tokens, verificación de correo y recuperación de contraseña.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| POST | `/auth/register` | Crear una cuenta de cliente o de propietario de barbería | pública |
| POST | `/auth/login` | Iniciar sesión | pública |
| POST | `/auth/refresh` | Renovar el token de acceso de la app | pública |
| POST | `/auth/forgot-password` | Pedir el correo para restablecer la contraseña | pública |
| POST | `/auth/reset-password` | Restablecer la contraseña con el enlace del correo | pública |
| POST | `/auth/verify-email` | Verificar el correo con el enlace recibido | pública |
| POST | `/auth/confirm-email` | Confirmar el cambio de correo desde el correo nuevo | pública |
| POST | `/auth/logout` | Cerrar la sesión actual (o todas) | sesión |
| GET | `/auth/csrf` | Obtener el token CSRF de la sesión web | sesión |
| POST | `/auth/verify-email/resend` | Reenviar el correo de verificación | sesión |

### Mi cuenta

Perfil, barberías donde trabaja, sesiones, seguridad y derechos sobre los datos personales (Ley 29733).

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/me` | Mi perfil, rol de plataforma, sesión y barbería seleccionada | sesión |
| GET | `/me/memberships` | Barberías donde trabajo (roles, permisos e invitaciones pendientes) | sesión |
| POST | `/me/memberships/{id}/aceptar` | Aceptar una invitación para trabajar en una barbería | sesión |
| POST | `/me/memberships/{id}/rechazar` | Rechazar una invitación | sesión |
| POST | `/me/context` | Seleccionar la barbería de trabajo de la sesión | sesión |
| GET | `/me/sessions` | Mis sesiones abiertas (dispositivos) | sesión |
| DELETE | `/me/sessions/{id}` | Cerrar la sesión de otro dispositivo | sesión |
| PUT | `/me/perfil` | Corregir mis nombres, apellidos y teléfono (derecho de rectificación) | sesión |
| POST | `/me/password` | Cambiar mi contraseña | sesión |
| POST | `/me/email` | Cambiar mi correo | sesión |
| POST | `/me/sessions/cerrar-otras` | Cerrar todas mis sesiones salvo la actual | sesión |
| POST | `/me/legal/aceptar` | Aceptar la versión vigente de términos y política de privacidad | sesión |
| POST | `/me/datos/exportar` | Descargar todos mis datos (derecho de acceso) | sesión |
| GET | `/me/eliminacion` | Qué pasará al eliminar mi cuenta y si algo lo impide | sesión |
| POST | `/me/eliminar` | Eliminar mi cuenta | sesión |
| GET | `/me/solicitudes-datos` | Mis solicitudes de derechos sobre datos personales (ARCO) | sesión |
| POST | `/me/solicitudes-datos` | Presentar una solicitud de acceso, rectificación, cancelación u oposición | sesión |

### Mis avisos

Bandeja de avisos, preferencias por canal y dispositivos para notificaciones push.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/me/notificaciones` | Bandeja de avisos | sesión |
| GET | `/me/notificaciones/resumen` | Cantidad de avisos no leídos (insignia de la campana) | sesión |
| POST | `/me/notificaciones/leer-todas` | Marcar todos los avisos como leídos | sesión |
| POST | `/me/notificaciones/{id}/leer` | Marcar un aviso como leído | sesión |
| GET | `/me/preferencias-notificacion` | Qué avisos recibo por push y por correo | sesión |
| PUT | `/me/preferencias-notificacion` | Cambiar mis preferencias de avisos | sesión |
| GET | `/me/dispositivos` | Teléfonos con avisos push activados | sesión |
| POST | `/me/dispositivos` | Registrar el token FCM de la app | sesión |
| DELETE | `/me/dispositivos` | Desactivar un token push (al cerrar sesión en la app) | sesión |
| DELETE | `/me/dispositivos/{id}` | Desactivar un dispositivo desde Mi cuenta | sesión |

### Favoritos

Barberías favoritas del cliente.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/me/favoritos` | Mis barberías favoritas | `cliente.favoritos` |
| PUT | `/me/favoritos/{slug}` | Guardar una barbería en favoritos | `cliente.favoritos` |
| DELETE | `/me/favoritos/{slug}` | Quitar una barbería de favoritos | `cliente.favoritos` |

### Marketplace

Búsqueda pública de barberías, perfil público y código QR del enlace directo.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/marketplace/filtros` | Opciones reales para los filtros del buscador | sesión opcional |
| GET | `/marketplace/barberias` | Buscar barberías publicadas | sesión opcional |
| GET | `/marketplace/barberias/{slug}` | Perfil público de una barbería | sesión opcional |
| GET | `/marketplace/barberias/{slug}/qr` | Código QR del enlace directo /b/{slug} | sesión opcional |

### Reservas del cliente

Disponibilidad, reservar, mis citas, cancelar, reprogramar y confirmar asistencia (también desde el enlace del recordatorio).

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| POST | `/asistencia/consultar` | Ver la cita del enlace de un recordatorio | pública |
| POST | `/asistencia/confirmar` | Confirmar asistencia desde el enlace del recordatorio | pública |
| GET | `/disponibilidad` | Horarios disponibles de una barbería | sesión opcional |
| POST | `/reservas` | Reservar una cita | `cliente.reservar` + correo verificado |
| GET | `/mis-reservas` | Mis citas en todas las barberías | sesión |
| GET | `/mis-reservas/{id}` | Detalle de mi cita con su historial | sesión |
| GET | `/mis-reservas/{id}/disponibilidad` | Horarios para reprogramar mi cita | `cliente.reservas.gestionar` |
| POST | `/mis-reservas/{id}/cancelar` | Cancelar mi cita | `cliente.reservas.gestionar` |
| POST | `/mis-reservas/{id}/reprogramar` | Reprogramar mi cita | `cliente.reservas.gestionar` |
| POST | `/mis-reservas/{id}/confirmar-asistencia` | Confirmar que asistiré | `cliente.reservas.gestionar` |

### Panel · Equipo y negocio

Contexto de la barbería, personal, ajustes del equipo y datos comerciales.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/panel/contexto` | Barbería de trabajo, mis roles, permisos, plan y ficha de barbero | sesión |
| GET | `/panel/personal` | Equipo de la barbería (propietarios, administradores y barberos) | `personal.gestionar` |
| POST | `/panel/personal` | Invitar a un administrador o barbero | `personal.gestionar` + correo verificado |
| DELETE | `/panel/personal/{id}` | Quitar el acceso de una persona del equipo | `personal.gestionar` |
| GET | `/panel/equipo/ajustes` | Ajustes del equipo | `personal.gestionar` |
| PUT | `/panel/equipo/ajustes` | Cambiar los ajustes del equipo | `personal.gestionar` |
| GET | `/panel/negocio` | Nombre comercial, colores, enlace y zona horaria | `negocio.perfil.gestionar` |
| PUT | `/panel/negocio` | Cambiar el nombre comercial o los colores | `negocio.perfil.gestionar` |

### Panel · Perfil público

Datos del marketplace, fotos, logo y portada.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/panel/perfil-publico` | Perfil del marketplace, fotos, enlace directo y requisitos para publicarse | `negocio.perfil.gestionar` |
| PUT | `/panel/perfil-publico` | Cambiar descripción, contacto, redes y publicación en el marketplace | `negocio.perfil.gestionar` |
| POST | `/panel/fotos` | Subir logo, portada o foto de galería | `negocio.perfil.gestionar` |
| DELETE | `/panel/fotos/{id}` | Eliminar una foto | `negocio.perfil.gestionar` |

### Panel · Catálogo

Sucursales, servicios, categorías, barberos, sus servicios, jornadas y fotos.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/panel/sucursales` | Sucursales con su horario comercial | `sucursales.gestionar` o `servicios.gestionar` o `barberos.gestionar` o `horarios.gestionar` o `bloqueos.gestionar` |
| POST | `/panel/sucursales` | Registrar una sucursal | `sucursales.gestionar` |
| GET | `/panel/sucursales/{id}` | Detalle de una sucursal | `sucursales.gestionar` o `servicios.gestionar` o `barberos.gestionar` o `horarios.gestionar` o `bloqueos.gestionar` |
| PUT | `/panel/sucursales/{id}` | Editar, activar, desactivar o marcar como principal | `sucursales.gestionar` |
| DELETE | `/panel/sucursales/{id}` | Eliminar una sucursal sin barberos ni reservas | `sucursales.gestionar` |
| PUT | `/panel/sucursales/{id}/horario` | Reemplazar el horario comercial de la sucursal | `sucursales.gestionar` |
| GET | `/panel/servicios` | Todos los servicios (activos e inactivos) | `servicios.gestionar` |
| POST | `/panel/servicios` | Crear un servicio | `servicios.gestionar` |
| GET | `/panel/servicios/{id}` | Detalle de un servicio | `servicios.gestionar` |
| PUT | `/panel/servicios/{id}` | Editar un servicio | `servicios.gestionar` |
| DELETE | `/panel/servicios/{id}` | Eliminar un servicio (lógico) | `servicios.gestionar` |
| GET | `/panel/categorias` | Categorías de servicios | `servicios.gestionar` |
| POST | `/panel/categorias` | Crear una categoría | `servicios.gestionar` |
| PUT | `/panel/categorias/{id}` | Editar una categoría | `servicios.gestionar` |
| DELETE | `/panel/categorias/{id}` | Eliminar una categoría vacía | `servicios.gestionar` |
| GET | `/panel/barberos` | Barberos y cuentas que se pueden vincular | `barberos.gestionar` o `horarios.gestionar` o `bloqueos.gestionar` |
| POST | `/panel/barberos` | Registrar un barbero | `barberos.gestionar` |
| GET | `/panel/barberos/{id}` | Ficha del barbero con servicios, jornada y descansos | `barberos.gestionar` o `horarios.gestionar` o `bloqueos.gestionar` |
| PUT | `/panel/barberos/{id}` | Editar, activar o desactivar un barbero | `barberos.gestionar` |
| DELETE | `/panel/barberos/{id}` | Eliminar un barbero sin reservas futuras | `barberos.gestionar` |
| PUT | `/panel/barberos/{id}/servicios` | Reemplazar los servicios que realiza el barbero | `barberos.gestionar` |
| PUT | `/panel/barberos/{id}/horario` | Reemplazar la jornada semanal y los descansos | `horarios.gestionar` |
| GET | `/panel/barberos/{id}/jornada` | Jornada efectiva por fechas (con bloqueos y vacaciones aplicados) | `horarios.gestionar` |
| POST | `/panel/barberos/{id}/foto` | Subir la foto del barbero | `barberos.gestionar` |
| DELETE | `/panel/barberos/{id}/foto` | Quitar la foto del barbero | `barberos.gestionar` |

### Panel · Reglas y bloqueos

Reglas de reserva por barbería o sucursal; vacaciones, ausencias, feriados y bloqueos de agenda.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/panel/politicas-reserva` | Reglas de reserva: la general y las propias de cada sucursal | `politicas.gestionar` |
| PUT | `/panel/politicas-reserva` | Guardar las reglas de reserva (general o de una sucursal) | `politicas.gestionar` |
| DELETE | `/panel/politicas-reserva/{sucursal_id}` | Quitar la regla propia de una sucursal (vuelve a la general) | `politicas.gestionar` |
| GET | `/panel/bloqueos` | Vacaciones, ausencias, feriados y bloqueos | `bloqueos.gestionar` |
| POST | `/panel/bloqueos` | Registrar un bloqueo (queda aprobado) | `bloqueos.gestionar` |
| POST | `/panel/bloqueos/{id}/aprobar` | Aprobar la ausencia que pidió un barbero | `bloqueos.gestionar` |
| POST | `/panel/bloqueos/{id}/rechazar` | Rechazar la ausencia que pidió un barbero | `bloqueos.gestionar` |
| DELETE | `/panel/bloqueos/{id}` | Eliminar un bloqueo (el intervalo vuelve a ofrecerse) | `bloqueos.gestionar` |

### Panel · Agenda

Agenda diaria y semanal, alta manual, estados de la cita, reprogramación, notas y cobros.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/panel/agenda` | Agenda del día o de la semana | `reservas.ver_todas` o `reservas.ver_propias` |
| GET | `/panel/agenda/catalogo` | Servicios y barberos activos para el alta manual | `reservas.crear` o `reservas.reprogramar` |
| GET | `/panel/disponibilidad` | Horarios libres para una cita registrada por el personal | `reservas.crear` o `reservas.reprogramar` |
| POST | `/panel/reservas` | Registrar una cita desde el panel (mostrador o teléfono) | `reservas.crear` |
| GET | `/panel/reservas/{id}` | Detalle de la cita con historial y cobros | `reservas.ver_todas` o `reservas.ver_propias` |
| POST | `/panel/reservas/{id}/confirmar` | Confirmar la cita | `reservas.ver_todas` o `reservas.ver_propias` + `reservas.crear` o `reservas.asistencia` |
| POST | `/panel/reservas/{id}/iniciar` | Iniciar la atención | `reservas.ver_todas` o `reservas.ver_propias` + `reservas.asistencia` |
| POST | `/panel/reservas/{id}/completar` | Marcar como atendida | `reservas.ver_todas` o `reservas.ver_propias` + `reservas.asistencia` |
| POST | `/panel/reservas/{id}/no-asistio` | Marcar inasistencia | `reservas.ver_todas` o `reservas.ver_propias` + `reservas.asistencia` |
| POST | `/panel/reservas/{id}/cancelar` | Cancelar la cita | `reservas.ver_todas` o `reservas.ver_propias` + `reservas.cancelar` |
| GET | `/panel/reservas/{id}/disponibilidad` | Horarios para reprogramar la cita | `reservas.ver_todas` o `reservas.ver_propias` + `reservas.reprogramar` |
| POST | `/panel/reservas/{id}/reprogramar` | Reprogramar la cita | `reservas.ver_todas` o `reservas.ver_propias` + `reservas.reprogramar` |
| PUT | `/panel/reservas/{id}/notas` | Guardar las notas internas de la cita | `reservas.ver_todas` o `reservas.ver_propias` + `reservas.crear` o `reservas.asistencia` |
| POST | `/panel/reservas/{id}/pagos` | Registrar un cobro de la cita | `reservas.ver_todas` o `reservas.ver_propias` + `reservas.cobrar` |
| POST | `/panel/reservas/{id}/pagos/{pago}/anular` | Anular un cobro registrado | `reservas.ver_todas` o `reservas.ver_propias` + `reservas.cobrar` |

### Panel · Clientes

Clientes vinculados a la barbería, fichas de mostrador e historial.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/panel/clientes` | Clientes vinculados a la barbería | `clientes.ver` |
| POST | `/panel/clientes` | Registrar un cliente de mostrador | `reservas.crear` |
| GET | `/panel/clientes/{id}` | Ficha del cliente con resumen de visitas | `clientes.ver` |
| PUT | `/panel/clientes/{id}` | Observaciones, bloqueo y datos de un cliente de mostrador | `clientes.ver` + `clientes.notas` |
| GET | `/panel/clientes/{id}/reservas` | Citas del cliente en esta barbería | `clientes.ver` |

### Panel · Reportes

Indicadores del día, reportes por periodo y exportación CSV, Excel o PDF.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/panel/dashboard` | Indicadores del día | `reservas.ver_todas` o `reportes.operativos` |
| GET | `/panel/reportes` | Reporte de un periodo | `reportes.operativos` |
| GET | `/panel/reportes/exportar` | Exportar el reporte o el detalle de reservas | `reportes.operativos` |

### Panel · Avisos

Reglas de recordatorios y métricas de envío.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/panel/notificaciones/reglas` | Reglas de recordatorios y avisos de la barbería | `notificaciones.gestionar` o `reportes.operativos` |
| PUT | `/panel/notificaciones/reglas` | Cambiar las reglas de recordatorios y avisos | `notificaciones.gestionar` |
| GET | `/panel/notificaciones/metricas` | Métricas de avisos: programados, enviados, fallos, confirmaciones e inasistencia | `notificaciones.gestionar` o `reportes.operativos` |

### Panel · Suscripción

Plan, uso, pago en línea y cambios de plan de la barbería.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/panel/suscripcion` | Plan actual, uso, planes disponibles, pagos y órdenes | `negocio.suscripcion.ver` |
| POST | `/panel/suscripcion/cotizar` | Calcular el importe de un plan antes de pagar | `negocio.suscripcion.gestionar` |
| POST | `/panel/suscripcion/pagar` | Crear una orden de pago en línea y obtener la URL de pago | `negocio.suscripcion.gestionar` + correo verificado |
| POST | `/panel/suscripcion/cambiar-plan` | Cambiar de plan | `negocio.suscripcion.gestionar` |
| DELETE | `/panel/suscripcion/cambio-programado` | Anular el cambio de plan programado | `negocio.suscripcion.gestionar` |
| GET | `/panel/suscripcion/ordenes/{codigo}` | Estado de una orden de pago (al volver de la pasarela) | `negocio.suscripcion.ver` |
| POST | `/panel/suscripcion/ordenes/{codigo}/simular` | Simulador local de pago (solo PAYMENT_DRIVER=sandbox) | `negocio.suscripcion.gestionar` |

### Panel del barbero

Agenda propia del barbero vinculado a la cuenta, atención, horario y ausencias.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/barbero/hoy` | Mi agenda del día: jornada, cita en atención y siguiente cliente | `reservas.ver_propias` o `reservas.ver_todas` |
| GET | `/barbero/proximas` | Mis próximas citas activas | `reservas.ver_propias` o `reservas.ver_todas` |
| GET | `/barbero/historial` | Mis citas pasadas | `reservas.ver_propias` o `reservas.ver_todas` |
| GET | `/barbero/citas/{id}` | Detalle de una cita y visitas anteriores del cliente conmigo | `reservas.ver_propias` o `reservas.ver_todas` |
| POST | `/barbero/citas/{id}/confirmar` | Confirmar la cita | `reservas.ver_propias` o `reservas.ver_todas` + `reservas.crear` o `reservas.asistencia` |
| POST | `/barbero/citas/{id}/iniciar` | Iniciar la atención | `reservas.ver_propias` o `reservas.ver_todas` + `reservas.asistencia` |
| POST | `/barbero/citas/{id}/completar` | Marcar como atendido | `reservas.ver_propias` o `reservas.ver_todas` + `reservas.asistencia` |
| POST | `/barbero/citas/{id}/no-asistio` | Marcar inasistencia | `reservas.ver_propias` o `reservas.ver_todas` + `reservas.asistencia` |
| PUT | `/barbero/citas/{id}/notas` | Guardar notas internas de la cita | `reservas.ver_propias` o `reservas.ver_todas` + `reservas.crear` o `reservas.asistencia` |
| GET | `/barbero/horario` | Mi jornada semanal, descansos y jornada efectiva por fecha | `reservas.ver_propias` o `reservas.ver_todas` o `horarios.propios` o `horarios.gestionar` |
| PUT | `/barbero/horario` | Cambiar mi jornada semanal | `horarios.propios` o `horarios.gestionar` |
| GET | `/barbero/ausencias` | Mis solicitudes de ausencia y bloqueos | `reservas.ver_propias` o `reservas.ver_todas` o `horarios.propios` o `horarios.gestionar` |
| POST | `/barbero/ausencias` | Pedir una ausencia o bloqueo de mi agenda | `bloqueos.solicitar` o `bloqueos.gestionar` |
| DELETE | `/barbero/ausencias/{id}` | Retirar una solicitud pendiente | `bloqueos.solicitar` o `bloqueos.gestionar` |

### Plataforma · General

Panel del superadministrador: indicadores globales, estado del sistema, configuración y auditoría.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/plataforma/contexto` | Mi rol y permisos de plataforma | plataforma |
| GET | `/plataforma/dashboard` | Indicadores globales del SaaS | plataforma + `plataforma.reportes.ver` |
| GET | `/plataforma/reportes` | Serie mensual de indicadores (o CSV) | plataforma + `plataforma.reportes.ver` |
| GET | `/plataforma/sistema` | Estado del sistema: cola de avisos, envíos fallidos y errores de integraciones | plataforma + `plataforma.reportes.ver` |
| GET | `/plataforma/auditoria` | Registro de auditoría | plataforma + `plataforma.auditoria.ver` |
| GET | `/plataforma/auditoria/acciones` | Acciones registradas (para el filtro) | plataforma + `plataforma.auditoria.ver` |
| GET | `/plataforma/configuracion` | Parámetros generales y avisos por defecto | plataforma + `plataforma.configuracion.gestionar` |
| PUT | `/plataforma/configuracion` | Cambiar parámetros generales y avisos por defecto | plataforma + `plataforma.configuracion.gestionar` |

### Plataforma · Barberías

Aprobar, rechazar, suspender y reactivar barberías.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/plataforma/barberias` | Barberías registradas | plataforma + `plataforma.tenants.gestionar` |
| GET | `/plataforma/barberias/{id}` | Ficha de una barbería: propietario, suscripción, uso y eventos | plataforma + `plataforma.tenants.gestionar` |
| PUT | `/plataforma/barberias/{id}` | Marcar como demostración o guardar una nota interna | plataforma + `plataforma.tenants.gestionar` |
| POST | `/plataforma/barberias/{id}/aprobar` | Aprobar una barbería pendiente | plataforma + `plataforma.tenants.gestionar` |
| POST | `/plataforma/barberias/{id}/rechazar` | Rechazar una barbería pendiente | plataforma + `plataforma.tenants.gestionar` |
| POST | `/plataforma/barberias/{id}/suspender` | Suspender una barbería | plataforma + `plataforma.tenants.gestionar` |
| POST | `/plataforma/barberias/{id}/reactivar` | Reactivar una barbería suspendida | plataforma + `plataforma.tenants.gestionar` |

### Plataforma · Planes y cobros

Planes SaaS, suscripciones, pagos manuales y órdenes de pago en línea.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/plataforma/planes` | Planes SaaS con su uso | plataforma + `plataforma.planes.gestionar` o `plataforma.suscripciones.gestionar` |
| POST | `/plataforma/planes` | Crear un plan | plataforma + `plataforma.planes.gestionar` |
| PUT | `/plataforma/planes/{id}` | Modificar un plan (precios, límites, funciones) | plataforma + `plataforma.planes.gestionar` |
| GET | `/plataforma/suscripciones` | Suscripciones | plataforma + `plataforma.suscripciones.gestionar` |
| POST | `/plataforma/suscripciones` | Activar una suscripción manualmente | plataforma + `plataforma.suscripciones.gestionar` |
| GET | `/plataforma/suscripciones/{id}` | Detalle de una suscripción | plataforma + `plataforma.suscripciones.gestionar` |
| POST | `/plataforma/suscripciones/{id}/cambiar-plan` | Cambiar el plan de una suscripción | plataforma + `plataforma.suscripciones.gestionar` |
| POST | `/plataforma/suscripciones/{id}/renovar` | Renovar por N periodos | plataforma + `plataforma.suscripciones.gestionar` |
| POST | `/plataforma/suscripciones/{id}/suspender` | Suspender la suscripción | plataforma + `plataforma.suscripciones.gestionar` |
| POST | `/plataforma/suscripciones/{id}/reactivar` | Reactivar una suscripción suspendida (con el tiempo que le quedaba) | plataforma + `plataforma.suscripciones.gestionar` |
| POST | `/plataforma/suscripciones/{id}/cancelar` | Cancelar la suscripción | plataforma + `plataforma.suscripciones.gestionar` |
| GET | `/plataforma/pagos` | Pagos de suscripciones | plataforma + `plataforma.pagos.gestionar` |
| GET | `/plataforma/ordenes` | Órdenes de pago en línea | plataforma + `plataforma.pagos.gestionar` |
| POST | `/plataforma/suscripciones/{id}/pagos` | Registrar un pago manual (transferencia, Yape, Plin, efectivo) | plataforma + `plataforma.pagos.gestionar` |
| POST | `/plataforma/pagos/{id}/confirmar` | Confirmar un pago pendiente | plataforma + `plataforma.pagos.gestionar` |
| POST | `/plataforma/pagos/{id}/rechazar` | Rechazar un pago pendiente | plataforma + `plataforma.pagos.gestionar` |
| POST | `/plataforma/pagos/{id}/reembolsar` | Registrar el reembolso de un pago confirmado | plataforma + `plataforma.pagos.gestionar` |

### Plataforma · Usuarios y avisos

Equipo de plataforma, bloqueo de cuentas, plantillas de avisos y solicitudes de derechos ARCO.

| Método | Ruta | Qué hace | Acceso |
|---|---|---|---|
| GET | `/plataforma/usuarios` | Equipo de BARBERAPP (superadministradores y operadores) | plataforma + `plataforma.usuarios.gestionar` |
| GET | `/plataforma/usuarios/buscar` | Buscar cuentas por correo o nombre | plataforma + `plataforma.usuarios.gestionar` |
| GET | `/plataforma/usuarios/{id}` | Ficha de una cuenta (sin reservas ni datos de clientes) | plataforma + `plataforma.usuarios.gestionar` |
| POST | `/plataforma/usuarios/{id}/bloquear` | Bloquear una cuenta | plataforma + `plataforma.usuarios.gestionar` |
| POST | `/plataforma/usuarios/{id}/desbloquear` | Desbloquear una cuenta | plataforma + `plataforma.usuarios.gestionar` |
| PUT | `/plataforma/usuarios/{id}/rol` | Dar o quitar un rol de plataforma | plataforma + `plataforma.usuarios.gestionar` |
| GET | `/plataforma/plantillas` | Plantillas de avisos por código y canal | plataforma + `plataforma.plantillas.gestionar` |
| PUT | `/plataforma/plantillas/{codigo}/{canal}` | Cambiar el texto de una plantilla | plataforma + `plataforma.plantillas.gestionar` |
| DELETE | `/plataforma/plantillas/{codigo}/{canal}` | Volver al texto por defecto | plataforma + `plataforma.plantillas.gestionar` |
| POST | `/plataforma/plantillas/{codigo}/{canal}/vista-previa` | Vista previa con datos de ejemplo (no guarda) | plataforma + `plataforma.plantillas.gestionar` |
| GET | `/plataforma/solicitudes-datos` | Solicitudes de derechos sobre datos personales | plataforma + `plataforma.privacidad.gestionar` |
| POST | `/plataforma/solicitudes-datos/{id}/responder` | Responder una solicitud | plataforma + `plataforma.privacidad.gestionar` |
<!-- /generado:endpoints -->

## 4. Códigos de error

La tabla se arma leyendo el código fuente: cada `HttpException` y `Response::error`. Un mismo código puede usar más de un estado HTTP según el caso.

<!-- generado:errores -->
| Código | HTTP | Mensaje de referencia |
|---|---|---|
| `account_already_deleted` | 409 | La cuenta ya fue eliminada. |
| `account_deletion_blocked` | 409 | (mensaje armado en tiempo de ejecución) |
| `account_disabled` | 403 | Tu cuenta está bloqueada. Contacta con soporte. |
| `account_not_eligible` | 409 | Solo una cuenta activa y con correo verificado puede formar parte del equipo de la plataforma. |
| `active_bookings_limit` | 422 | Ya tienes … citas activas en esta barbería. Cancela o espera a completar una antes de reservar otra. |
| `already_blocked` | 409 | La cuenta ya está bloqueada. |
| `already_member` | 409 | Esta persona ya tiene ese rol en tu barbería. |
| `attention_started` | 409 | La atención ya se inició: marca la cita como atendida. |
| `barber_has_bookings` | 409 | No puedes … al barbero: tiene … reserva(s) futuras activas. Reprográmalas o cancélalas primero. |
| `block_conflicts_bookings` | 409 | Hay … |
| `block_not_pending` | 409 | Esta solicitud ya fue atendida: pide al encargado que la modifique. |
| `block_overlaps` | 409 | (mensaje armado en tiempo de ejecución) |
| `booking_changed` | 409, 410 | Tu cita cambió de horario después de este aviso. Revisa los datos actualizados en la app. |
| `booking_has_payments` | 409 | La cita tiene cobros registrados. Anúlalos antes de marcar la inasistencia. |
| `booking_not_active` | 409 | Esta cita ya no está activa o ya pasó: no se puede confirmar la asistencia. |
| `booking_not_payable` | 409 | No se registran cobros de citas canceladas o con inasistencia. |
| `booking_started` | 409 | La cita ya comenzó o pasó: no se puede cancelar en línea. |
| `branch_has_barbers` | 409 | No puedes … la sucursal: tiene … barbero(s) activos asignados. Muévelos a otra sucursal o desactívalos primero. |
| `branch_has_bookings` | 409 | No puedes … la sucursal: tiene … reserva(s) futuras activas. |
| `branch_in_schedules` | 409 | Hay barberos con jornada en esta sucursal. Quítala de sus horarios antes de eliminarla. |
| `cancellation_window_closed` | 422 | Ya no puedes cancelar en línea: el plazo vence … |
| `cannot_block_self` | 409 | No puedes bloquear tu propia cuenta. |
| `cannot_change_own_role` | 409 | No puedes cambiar tu propio rol de plataforma. |
| `cannot_revoke_self` | 422 | No puedes quitarte tu propio rol de propietario. |
| `category_in_use` | 409 | La categoría tiene … servicio(s). Muévelos a otra categoría antes de eliminarla. |
| `client_blocked` | 403 | Esta barbería no admite nuevas reservas en línea desde tu cuenta. Comunícate directamente con ella. |
| `client_overlap` | 409 | (mensaje armado en tiempo de ejecución) |
| `code_generation_failed` | 500 | No se pudo generar el código de la reserva. Intenta de nuevo. |
| `csrf_mismatch` | 403 | La solicitud no pudo verificarse. Recarga la página e inténtalo de nuevo. |
| `duplicate_reference` | 409 | Ya existe un pago con ese método y número de operación. |
| `email_not_verified` | 403 | Confirma tu correo electrónico para continuar. Revisa tu bandeja de entrada. |
| `email_taken` | 409 | Ya existe una cuenta con este correo. Inicia sesión o recupera tu contraseña. |
| `email_unavailable` | 409 | Este correo no puede usarse. |
| `favorites_limit` | 422 | Alcanzaste el máximo de … |
| `forbidden` | 403 | No tienes permiso para esta acción. |
| `gallery_full` | 422 | La galería admite hasta … fotos. Elimina alguna para subir otra. |
| `gateway_error` | 502 | No pudimos conectar con la pasarela de pago. Intenta de nuevo en unos minutos. |
| `https_required` | 308, 403 | Usa la dirección segura (https). |
| `idempotency_in_progress` | 409 | Tu solicitud anterior aún se está procesando. Espera unos segundos y revisa tus reservas. |
| `idempotency_key_invalid` | 422 | Idempotency-Key inválida: usa de 8 a 100 caracteres (letras, números, guion, punto o dos puntos). |
| `idempotency_key_required` | 428 | Falta la cabecera Idempotency-Key: genera un identificador único por intento de reserva y reutilízalo al reintentar. |
| `idempotency_key_reused` | 422 | Esta Idempotency-Key ya se usó con otros datos. Genera una nueva para una reserva distinta. |
| `image_too_large` | 413 | La imagen supera el tamaño máximo de … |
| `images_unavailable` | 503 | El servidor no tiene habilitada la extensión GD para procesar imágenes. |
| `internal_error` | 500 | Error inesperado. El request_id permite ubicarlo en los registros. |
| `invalid_credentials` | 401 | Correo o contraseña incorrectos. |
| `invalid_json` | 400 | El cuerpo JSON no es válido. |
| `invalid_link` | 404 | El enlace no es válido. Abre tu cita desde la app para confirmar tu asistencia. |
| `invalid_payment_state` | 409 | Solo se puede confirmar un pago pendiente. |
| `invalid_refresh_token` | 401 | La sesión expiró. Inicia sesión nuevamente. |
| `invalid_subscription_state` | 409 | Solo se puede reactivar una suscripción suspendida. |
| `invalid_tenant_state` | 409 | (mensaje armado en tiempo de ejecución) |
| `invalid_token` | 400 | El enlace no es válido o ya venció. Solicita uno nuevo. |
| `last_active_plan` | 409 | Debe quedar al menos un plan activo. |
| `last_owner` | 422 | La barbería debe conservar al menos un propietario. |
| `last_superadmin` | 409 | No puedes … el único superadministrador activo. |
| `maintenance` | 503 | BARBERAPP se está actualizando. Vuelve a intentarlo en un momento. |
| `method_not_allowed` | 405 | La ruta existe con otro método (cabecera Allow). |
| `no_barber_for_services` | 422 | Ningún barbero de esta sucursal realiza esa combinación de servicios. Elige otros servicios o reserva por separado. |
| `no_barber_profile` | 403 | Tu cuenta no está vinculada a un barbero de esta barbería. Pide al propietario que la vincule. |
| `no_changes` | 409 | Ya estás probando ese plan. |
| `no_scheduled_change` | 409 | No hay un cambio programado. |
| `no_subscription` | 409 | No tienes una suscripción vigente: elige el plan al pagar. |
| `not_a_web_session` | 400 | El token CSRF solo aplica a sesiones web con cookie. |
| `not_blocked` | 409 | La cuenta no está bloqueada. |
| `not_found` | 404 | Recurso no encontrado (o no pertenece a tu cuenta o barbería). |
| `order_closed` | 409 | Esta orden ya no admite pagos. |
| `origin_not_allowed` | 403 | Solicitud rechazada: el origen no está autorizado. |
| `payload_too_large` | 413 | El cuerpo de la petición excede el tamaño permitido. |
| `payment_exceeds_total` | 422 | El cobro supera el importe de la cita (S/ … |
| `payment_required` | 409 | Subir de plan se paga: elige el plan y pulsa «Pagar». |
| `payments_disabled` | 503 | El pago en línea no está disponible. Escríbenos a soporte para renovar tu plan. |
| `period_ended` | 409 | El periodo de esta suscripción ya terminó: renuévala para reactivarla. |
| `plan_feature` | 403 | … no están incluidos en tu plan …. Puedes subir de plan desde Suscripción. |
| `plan_limit` | 409 | Tu plan … permite … … y ya tienes …. Sube de plan desde Suscripción o desactiva … |
| `plan_limit_exceeded` | 409 | El plan … permite … |
| `profile_incomplete` | 422 | Completa tu barbería antes de publicarla en el marketplace. |
| `request_closed` | 409 | Esta solicitud ya fue respondida. |
| `reschedule_limit` | 422 | Esta cita ya se reprogramó … … |
| `reschedule_window_closed` | 422 | Ya no puedes reprogramar en línea: el plazo vence … |
| `role_missing` | 500 | El rol de plataforma no existe. Ejecuta php bin/console db:seed. |
| `route_not_found` | 404 | La ruta solicitada no existe. |
| `same_slot` | 422 | Elige una fecha u hora distinta a la actual. |
| `schedule_change` | 409 | Bajar de plan o cambiar el ciclo se programa para el fin de tu periodo pagado ( … |
| `schedule_locked` | 403 | Tu barbería no permite que los barberos cambien su jornada. Pide el cambio al encargado o solicita una ausencia. |
| `slot_unavailable` | 409 | Ese horario acaba de ser tomado o ya no está disponible. Elige otro. |
| `subscription_changed` | 409 | La suscripción cambió después de crear la orden de pago. |
| `subscription_exists` | 409 | La barbería ya tiene una suscripción vigente. Cambia su plan o renuévala. |
| `subscription_required` | 402 | La suscripción de la barbería venció. Renueva desde Suscripción para volver a recibir reservas nuevas y ampliar tu equipo; las citas ya registradas se mantienen. |
| `subscription_suspended` | 409 | Tu suscripción está suspendida por BARBERAPP. Escríbenos a soporte para resolverlo. |
| `tenant_cancelled` | 409 | La barbería está dada de baja. |
| `tenant_context_missing` | 500 | La ruta no tiene contexto de barbería. |
| `tenant_inactive` | 403 | La barbería no está disponible en este momento. |
| `tenant_pending` | 409 | Tu barbería aún está en revisión. La prueba gratuita empieza cuando BARBERAPP la apruebe. |
| `tenant_required` | 400 | Debe indicar la barbería (tenant). |
| `tenant_unavailable` | 409 | La barbería no está recibiendo reservas en este momento. |
| `too_early` | 422 | Aún falta para esta cita: puedes iniciar la atención desde … |
| `too_far` | 422 | Puedes reservar con hasta … días de anticipación. |
| `too_many_attempts` | 429 | Demasiados intentos. Espera unos minutos e inténtalo de nuevo. |
| `too_many_open_requests` | 409 | Ya tienes 5 solicitudes en curso. Espera la respuesta antes de enviar otra. |
| `too_many_requests` | 429 | Tienes … |
| `too_soon` | 422 | (mensaje armado en tiempo de ejecución) |
| `unauthenticated` | 401 | Falta la sesión, venció o el token no es válido: inicia sesión de nuevo. |
| `unsupported_media_type` | 415 | Envía la imagen como JPG, PNG o WebP. |
| `use_logout` | 422 | Para cerrar la sesión actual usa "Cerrar sesión". |
| `validation_failed` | 422 | Los datos enviados no son válidos. details.fields trae los mensajes por campo. |
<!-- /generado:errores -->
