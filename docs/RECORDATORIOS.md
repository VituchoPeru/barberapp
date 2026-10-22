# Avisos y recordatorios automáticos (Fase 8)

El servidor programa y envía los avisos de cada cita. No dependen de que la app esté abierta ni de temporizadores en JavaScript. La app solo consulta la bandeja y registra el teléfono para push.

## Canales

| Canal | Cómo llega | Estado |
|---|---|---|
| `interna` | Bandeja de la app (campana en la barra superior, `pages/notificaciones.html`). Siempre activa. | ✅ |
| `push` | Firebase Cloud Messaging (FCM HTTP v1) a los teléfonos registrados en `dispositivos_push`. | ✅ servidor listo; el token del teléfono lo registra la app nativa en la Fase 12 |
| `email` | `Mailer` (SMTP o archivo de log). Solo a correos verificados. | ✅ |
| `whatsapp`, `sms` | Valores ya previstos en los ENUM. | Fases posteriores |

## Cuándo se envía

| Momento | Tipo | Regla de la barbería |
|---|---|---|
| Al registrar una reserva (cliente o mostrador) | `reserva_confirmada` o `reserva_recibida` (si queda pendiente) | `aviso_confirmacion` |
| Cuando la barbería confirma una reserva pendiente | `reserva_confirmada` | `aviso_confirmacion` |
| X horas antes (por defecto 24 h y 2 h; hasta 3 valores entre 1 y 72) | `recordatorio` | `recordatorios_horas` |
| La barbería cancela | `reserva_cancelada` (con el motivo) | `aviso_cancelacion` |
| La barbería reprograma o cambia barbero | `reserva_modificada` | `aviso_modificacion` |

Lo que hace el propio cliente (cancelar o reprogramar) no le genera un aviso, porque ya lo vio en pantalla. El barbero recibe en su bandeja (y por push) las citas nuevas, cambios y cancelaciones de su agenda.

Las reglas se editan en **Panel → Avisos** (permiso `notificaciones.gestionar`: propietario y administrador). Se guardan en `configuraciones` con la clave `notificaciones.*`. Los valores globales (`tenant_id` NULL) se pueden cambiar para toda la plataforma.

Si `pedir_confirmacion` está activo, el recordatorio incluye **Confirmar asistencia**. **Nunca se cancela una cita porque el cliente no confirmó.**

## Arquitectura

```
Evento de reserva ──► BookingNotifier ──► NotificationCenter ──► notificaciones (cola, estado = programada)
                                                                         ▲
cron cada minuto ──► notificaciones:procesar ──► ReminderPlanner (respaldo) ─┘
                                           └──► QueueWorker ──► interna | Push (FCM) | Mailer
                                                     └──► notificacion_envios (cada intento)
```

- **Cola persistente:** `notificaciones` con `estado` (`programada → procesando → enviada | fallida | cancelada | omitida`), `programada_para_utc`, `intentos`, `max_intentos`, `ultimo_error` y `bloqueada_por`. El worker toma lotes con `SELECT … FOR UPDATE SKIP LOCKED`, así que dos procesos nunca envían el mismo aviso.
- **Sin duplicados:** `dedupe_key` única. Un recordatorio usa `rec:{reserva}:{reprogramaciones}:{horas}h:{canal}`, así que reprogramar crea recordatorios nuevos y los anteriores se anulan.
- **Programación inmediata y planificador de respaldo:** al reservar o reprogramar se encolan los recordatorios futuros. En cada pasada, `ReminderPlanner` busca citas activas de las próximas 72 h a las que les falte un recordatorio (por ejemplo, porque la barbería agregó una regla nueva) y lo crea si todavía está a tiempo. Nunca crea un recordatorio cuya hora sea anterior a la última modificación de la cita, para no mandar un "faltan 2 horas" justo después de reservar.
- **Validación al enviar:** antes de cada envío el worker verifica que la cita siga activa, que no haya cambiado de hora, que no haya empezado, que la barbería siga activa, que la regla siga vigente y que el usuario no haya apagado ese canal. Si algo falló, el aviso queda `cancelada` u `omitida` con el motivo. El texto se vuelve a generar con los datos del momento ("hoy a las 10:00").
- **Reintentos con espera creciente:** 1 min, 5 min, 15 min, 1 h y 3 h (más hasta un 10 % al azar), hasta `NOTIFY_MAX_ATTEMPTS` (5). Un recordatorio que ya no llegaría antes de la cita se marca `fallida` sin esperar más.
- **Bloqueos colgados:** si un worker muere a mitad de un lote, sus filas `procesando` vuelven a la cola después de 10 minutos. La entrega es "al menos una vez". En el peor caso, un aviso puede repetirse una vez, pero nunca se pierde.
- **Registro de errores:** cada intento queda en `notificacion_envios` (canal, proveedor, resultado, código como `UNREGISTERED` o `http_503`, detalle y duración).
- **Tokens vencidos:** si FCM responde `UNREGISTERED`, `SENDER_ID_MISMATCH` o "token inválido", el dispositivo se desactiva (`desactivado_en`, `motivo_desactivado`) y no se reintenta.

## Preferencias del usuario

En *Mi cuenta → Avisos* cada persona enciende o apaga push y correo por categoría:

| Categoría | Incluye |
|---|---|
| `recordatorios` | Recordatorios antes de la cita |
| `cambios` | Confirmación, cancelación y cambios hechos por la barbería |
| `agenda` | Solo personal: citas nuevas o cambios en su agenda (solo push) |

La bandeja de la app recibe todo. En el mismo lugar se ven y desactivan los teléfonos registrados. Cada correo incluye un enlace a estas preferencias.

## Confirmar asistencia desde el aviso

El push, el correo y la bandeja llevan un enlace a `pages/asistencia.html#t=…`. El token está firmado con HMAC-SHA256 y `APP_KEY`, e incluye barbería, reserva y hora de inicio. Va en el fragmento (`#`), que no viaja en el `Referer` ni queda en los logs del servidor, y la página lo borra de la barra de direcciones.

- No requiere iniciar sesión.
- Si la cita cambió de hora, el enlace responde `410` y pide revisar la app.
- Si la cita ya no está activa, responde `409`.
- Los intentos tienen límite por IP (`asistencia_ip`: 30 cada 15 min).
- Confirmar usa el mismo `BookingService::confirmAttendance` de *Mis citas* y queda en auditoría.

## Métricas

**Panel → Avisos → Resultados** (`GET /panel/notificaciones/metricas?desde&hasta`, máximo 366 días):

- **Recordatorios y avisos:** programados, enviados, en cola, fallidos, omitidos, anulados, por canal, y tasa de entrega (enviados ÷ (enviados + fallidos)).
- **Confirmaciones:** citas con recordatorio, citas confirmadas y citas confirmadas después del recordatorio.
- **Inasistencia:** no asistió ÷ (atendidas + no asistió), separada por confirmó / no confirmó y con / sin recordatorio. Si un grupo no tiene citas se muestra "—", no 0 %.
- **Últimos 20 errores de envío.**

Los recordatorios se cuentan por la fecha en que se programaron. Las citas se cuentan por su fecha, en la zona horaria de la barbería.

## Endpoints

| Método | Ruta | Uso |
|---|---|---|
| GET | `/api/v1/me/notificaciones?solo_no_leidas=1` (paginada) | Bandeja propia |
| GET | `/api/v1/me/notificaciones/resumen` | Número sin leer (campana) |
| POST | `/api/v1/me/notificaciones/{id}/leer`, `/me/notificaciones/leer-todas` | Marcar como leído |
| GET/PUT | `/api/v1/me/preferencias-notificacion` | Preferencias por categoría y canal |
| GET/POST/DELETE | `/api/v1/me/dispositivos` | Listar, registrar (`token`, `plataforma`, `modelo`, `app_version`) o dar de baja un token push |
| DELETE | `/api/v1/me/dispositivos/{id}` | Dejar de avisar en ese teléfono |
| POST | `/api/v1/asistencia/consultar`, `/asistencia/confirmar` | Enlace firmado `{token}` |
| GET/PUT | `/api/v1/panel/notificaciones/reglas` | Reglas de la barbería |
| GET | `/api/v1/panel/notificaciones/metricas` | Métricas (`notificaciones.gestionar` o `reportes.operativos`) |

## Puesta en marcha

### 1. Cron

El worker termina solo a los 50 s y usa un candado de archivo, así que se puede programar cada minuto sin que se superpongan dos procesos:

```cron
* * * * * cd /ruta/barberapp/backend && php bin/console notificaciones:procesar >> storage/logs/cron.log 2>&1
30 3 * * * cd /ruta/barberapp/backend && php bin/console notificaciones:purge >> storage/logs/cron.log 2>&1
```

En Windows/XAMPP, crea una tarea en el Programador de tareas que ejecute `C:\xampp\php\php.exe C:\xampp\htdocs\barberapp\backend\bin\console notificaciones:procesar` cada minuto.

`php bin/console notificaciones:estado` muestra la cola por estado y canal, los avisos atrasados y los intentos de las últimas 24 h. Así se detecta un cron detenido.

`notificaciones:purge` borra los avisos terminados de más de `NOTIFY_RETENTION_DAYS` (180 por defecto; mínimo 30) y los teléfonos desactivados hace tiempo.

### 2. Push con Firebase

1. En la consola de Firebase: *Configuración del proyecto → Cuentas de servicio → Generar nueva clave privada*.
2. Guarda el JSON **fuera de `public/` y fuera del repositorio** (por ejemplo `/etc/barberapp/firebase.json`, con permisos 600). `.gitignore` ya ignora `*firebase-adminsdk*.json`.
3. En `.env`:

   ```ini
   PUSH_DRIVER=fcm
   FCM_CREDENTIALS=/etc/barberapp/firebase.json
   # FCM_PROJECT_ID=  (opcional; por defecto el del JSON)
   ```
4. Prueba con `php bin/console push:probar correo@dominio`.

Sin credenciales, el driver por defecto es `log`: escribe cada push en `storage/push/push-AAAA-MM-DD.log` y no envía nada. Con `fcm` y credenciales faltantes, los push quedan en reintento (`fcm_config`) en lugar de perderse. El access token de OAuth se guarda en `storage/cache/` (permisos 600) y se renueva solo.

No hace falta la extensión curl: se usan los streams de PHP con `openssl`.

### 3. Correo

Usa la configuración de `MAIL_*` de las fases anteriores. Con `MAIL_DRIVER=log` los correos quedan en `storage/mail/`.

## Pruebas

- `tests/Integration/Notifications/ReminderQueueTest.php` (9): comprobante inmediato y recordatorios a las horas configuradas, envío por el worker con la app cerrada, recordatorio vencido que nunca se envía (y no confirmar no cancela), cancelar o reprogramar anula recordatorios y avisa al cliente, la cancelación del propio cliente no le genera push, reintentos con espera creciente y fallo definitivo, token rechazado que desactiva el teléfono, bloqueos colgados de un worker caído y sin duplicados en reintentos.
- `tests/Integration/Notifications/NotificationsApiTest.php` (9): reglas del panel con permisos y validación, quitar una regla (anula pendientes) y planificador de respaldo, preferencias, enlace de asistencia (firma, cambio de hora, cita cancelada), límite por IP, privacidad de la bandeja, dispositivos, push al barbero y métricas.
- `tests/Unit/NotificationsUnitTest.php` (6): FCM HTTP v1 con una clave RSA real (JWT RS256 verificado, caché del access token, 401), clasificación de errores, credenciales faltantes, tokens firmados y plantillas con HTML escapado.

Además se probó el envío real por HTTP contra un servidor que imita FCM: un envío correcto y uno con `UNREGISTERED`, que desactiva el teléfono.

## Pendiente para otras fases

- **Fase 10:** limitar canales según el plan de la barbería (`planes.caracteristicas`).
- **Fase 12 (Capacitor):** obtener el token FCM en Android e iOS, enviarlo a `POST /me/dispositivos` y mostrar el botón nativo "Confirmar asistencia". El servidor ya envía `accion`, `token`, la categoría APNs `CITA_CONFIRMAR` y `tag` por cita.
- WhatsApp y SMS: se agregan como nuevos transportes del worker. La cola, los reintentos, las preferencias y las métricas ya los contemplan.
