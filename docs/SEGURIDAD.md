# Seguridad y protección de datos (Fase 13)

Este documento resume cómo BARBERAPP protege la plataforma y los datos personales, qué debe configurarse antes de publicar y cómo se cumplen la **Ley N.º 29733, Ley de Protección de Datos Personales**, y su reglamento vigente (**D. S. N.º 016-2024-JUS**, que reemplazó al D. S. 003-2013-JUS).

> Los textos legales de `frontend/www/pages/legal/` son un borrador técnico coherente con lo que la plataforma hace. **Deben ser revisados por un abogado** antes de publicar, y los datos de la empresa se completan en *Plataforma → Configuración → Datos legales* (mientras falten, las páginas lo indican; nunca se inventan).

## 1. Lista de verificación antes de publicar

```bash
php bin/console seguridad:revisar     # termina con código 1 si hay algo crítico en producción
```

| Tema | Qué hacer |
|---|---|
| HTTPS | Certificado (Let's Encrypt) en la API y el frontend. `FORCE_HTTPS=true`, `SESSION_SECURE_COOKIE=true`, `APP_URL` y `FRONTEND_URL` con `https://`. |
| Proxy | Si hay Cloudflare o un balanceador delante, `TRUSTED_PROXIES` con sus IP o rangos (si no, todas las peticiones parecen venir del proxy y los límites por IP no sirven). |
| Secretos | `APP_KEY` propia (`key:generate`), `BACKUP_KEY` (`backup:clave`), claves de Mercado Pago y Firebase solo en `backend/.env` o en archivos fuera del repositorio. `chmod 640 backend/.env`. |
| Depuración | `APP_ENV=production`, `APP_DEBUG=false`. |
| MySQL | Usuario propio con permisos solo sobre la base de BARBERAPP (y `CREATE`/`DROP` sobre la base temporal de verificación de copias). Nunca `root`. |
| CORS | `CORS_ALLOWED_ORIGINS` solo con el dominio del frontend y los de la app (`https://localhost`, `capacitor://localhost`). Quita `http://localhost`. |
| Datos demo | No cargar `db:seed --demo` en producción (usuarios con contraseña conocida). |
| Cron | Programar `backup:crear`, `backup:verificar` y `privacidad:retencion` (sección 7). |
| Legal | Datos del responsable en el panel, revisión legal de los textos e **inscripción del banco de datos** en el Registro Nacional de Protección de Datos Personales (sección 9). |

## 2. Transporte y cabeceras

- **HTTPS obligatorio** (`HttpsMiddleware`): con `FORCE_HTTPS=true`, un GET por `http` se redirige con 308 a `https` y una escritura por `http` se rechaza con `403 https_required` (no se redirige un POST con datos). Detrás de un proxy, `X-Forwarded-Proto` solo cuenta si viene de `TRUSTED_PROXIES`. La API envía `Strict-Transport-Security` (1 año).
- **Cabeceras de la API** (`SecurityHeadersMiddleware`): `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'` (la API solo devuelve JSON), `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, `X-Permitted-Cross-Domain-Policies` y `Cache-Control: no-store` salvo que la respuesta declare su propia caché.
- **Frontend web**: `frontend/www/.htaccess` (Apache/XAMPP) e `infrastructure/nginx/barberapp-app.conf.example` aplican una CSP sin scripts en línea ni `eval` (`script-src 'self'`), sin marcos (`frame-ancestors 'none'`) y con `connect-src` limitado; `npm run verificar` comprueba que ninguna pantalla tenga `<script>` en línea ni atributos `on…=`. En producción reemplaza `https:` por el dominio de la API en `connect-src` e `img-src`.
- **App nativa**: no lleva CSP en `<meta>` porque Capacitor inyecta su puente como script en línea; la app solo carga archivos propios empaquetados y la API por HTTPS.

## 3. Entradas, salidas y base de datos

- **SQL**: todas las consultas usan PDO con sentencias preparadas y `ATTR_EMULATE_PREPARES=false`. Los pocos valores interpolados son enteros convertidos con `(int)` o nombres de columnas de listas cerradas.
- **Validación**: cada endpoint valida tipos, longitudes y formatos en el servidor (`Validator` y servicios). Textos de perfil y legales rechazan `<` y `>`; los correos se normalizan; los teléfonos se limpian.
- **XSS**: el frontend escribe datos con `textContent` o con `escapeHtml()` en todas las plantillas. Las pruebas E2E guardan nombres con `&`, `'` y `<b>` y comprueban que se muestran literales.
- **CSRF**: cookie `HttpOnly; Secure; SameSite=Lax` + token `X-CSRF-Token` por sesión. Además, desde la Fase 13 una escritura con cabecera `Origin` que no es de BARBERAPP (lista CORS, `FRONTEND_URL`, `APP_URL` o el mismo host) se rechaza con `403 origin_not_allowed`. La app usa tokens Bearer (sin cookie), así que no es vulnerable a CSRF.
- **Tamaño**: cuerpos JSON limitados por `MAX_BODY_BYTES` (1 MB).
- **Archivos subidos** (fotos, Fase 3): tamaño máximo, tipo detectado del contenido (no de la extensión), dimensiones máximas, y la imagen se **vuelve a codificar** con GD (se descartan metadatos EXIF y cualquier contenido extra). Se guardan con nombre aleatorio y se sirven sin PHP (`media/` no ejecuta scripts).
- **Exportaciones**: CSV con fórmulas neutralizadas (Fase 11).

## 4. Autenticación, sesiones y autorización

Detalle en [AUTENTICACION.md](AUTENTICACION.md). Resumen:

- Contraseñas con bcrypt (mínimo 8 caracteres, letras y números; máximo 72 bytes).
- Sesión web con cookie `HttpOnly` y expiración por inactividad; en la app, access token de 15 minutos y refresh token rotativo en el llavero (Keychain/Keystore). Reutilizar un refresh token revoca toda la sesión.
- **Revocación**: el usuario ve sus sesiones y cierra una, las demás (`POST /me/sessions/cerrar-otras`) o todas. Cambiar o restablecer la contraseña cierra las demás sesiones. Bloquear una cuenta o cambiar su rol cierra todas.
- **Reautenticación**: eliminar la cuenta, descargar los datos y cambiar correo o contraseña piden la contraseña actual (límite de 5 intentos fallidos cada 15 minutos por usuario).
- **Autorización por recurso**: cada ruta exige permiso (`PermissionMiddleware`) y cada consulta filtra por `tenant_id` del contexto (`TenantContextMiddleware`); las FK compuestas `(tenant_id, id)` impiden mezclar datos de dos barberías aunque haya un error de código. Los intentos denegados quedan en la auditoría.
- **Plataforma**: el equipo de BARBERAPP no tiene permisos sobre los datos de las barberías; ve cifras agregadas y lo necesario para soporte, con cada consulta auditada.

## 5. Límites de uso (rate limiting)

Contadores en la tabla `limites_intentos` (sirven aunque haya varios servidores PHP). Al superar un límite la API responde `429` con `Retry-After`.

| Límite | Valor por defecto |
|---|---|
| Login fallido por correo + IP / por IP | 5 / 20 cada 15 min |
| Registro por IP | 10 por hora |
| Recuperar contraseña por correo / por IP | 3 / 10 por hora |
| Enlaces inválidos (restablecer, cambio de correo) por IP | 10 cada 15 min |
| **Escrituras por IP (general, Fase 13)** | `RATE_LIMIT_WRITES` = 120 por minuto (sin contar webhooks) |
| Contraseña incorrecta en acciones sensibles por usuario | 5 cada 15 min |
| Descargar mis datos por usuario | 5 por hora |
| Solicitudes ARCO por usuario | 5 por día (y máximo 5 abiertas) |
| Cambio de correo por usuario | 3 por hora |

## 6. Auditoría y registros

- `auditoria` guarda acción, resultado (éxito, denegado, error), usuario, barbería, entidad, IP, navegador e ID de la petición. Es de solo lectura en el panel de la plataforma.
- Las acciones de privacidad quedan registradas: `privacidad.cuenta_eliminada`, `privacidad.datos_exportados`, `privacidad.solicitud_creada`, `privacidad.solicitud_respondida`, `privacidad.textos_aceptados`, `privacidad.reautenticacion_fallida`, `cuenta.*`.
- Los rechazos por origen o por límite general van al log de la aplicación (no a la auditoría), para que un ataque no llene la base.
- Los logs no guardan contraseñas, tokens ni cuerpos de petición.

## 7. Copias de seguridad

Copias **lógicas, cifradas y verificadas**, sin depender de `mysqldump` (funciona en hosting compartido).

```bash
php bin/console backup:clave        # una vez: genera BACKUP_KEY en .env
php bin/console backup:crear        # copia cifrada (cron diario)
php bin/console backup:listar
php bin/console backup:verificar    # restaura la última en una base temporal, compara filas y la borra (cron semanal)
php bin/console backup:restaurar <archivo> --base=barberapp_recuperada            # a una base nueva
php bin/console backup:restaurar <archivo> --base=barberapp --sobrescribir        # sobre la base actual
php bin/console backup:restaurar <archivo> --solo-descifrar=/ruta/copia.sql.gz    # para importar con mysql
```

- Instantánea consistente (`START TRANSACTION WITH CONSISTENT SNAPSHOT`), sin bloquear la operación.
- Formato: SQL comprimido con gzip y cifrado con **AES-256-GCM** en bloques de 1 MiB (cada bloque autenticado; un archivo alterado o truncado no se restaura). Lleva un manifiesto con el número de filas por tabla, que la verificación compara.
- Se guardan en `BACKUP_PATH` (por defecto `backend/storage/backups`, fuera de `public/` y del repositorio) con permisos `600` y un `.sha256`. Se conservan `BACKUP_KEEP` (14) copias.
- **Copia fuera del servidor**: sincroniza `BACKUP_PATH` a otro lugar (otro servidor, almacenamiento de objetos) y guarda `BACKUP_KEY` en un gestor de contraseñas: sin la clave no se puede restaurar. Como las copias están cifradas, el almacenamiento externo no ve datos personales.
- **Prueba de restauración**: `backup:verificar` cada semana. Su código de salida es distinto de 0 si falla, para que el cron avise.

Cron sugerido (además de los de fases anteriores):

```cron
# Copia diaria 03:15, verificación los domingos 04:00, retención diaria 03:45 (hora del servidor)
15 3 * * *  cd /var/www/barberapp/backend && php bin/console backup:crear
0  4 * * 0  cd /var/www/barberapp/backend && php bin/console backup:verificar
45 3 * * *  cd /var/www/barberapp/backend && php bin/console privacidad:retencion
```

## 8. Retención y eliminación

| Dato | Plazo | Cómo |
|---|---|---|
| Cuenta y perfil | Mientras exista | Eliminación inmediata a pedido (sección 9) |
| Auditoría (incluye IP) | `RETENTION_AUDIT_DAYS` = 365 días | `privacidad:retencion` |
| Webhooks de pago procesados | `RETENTION_WEBHOOK_DAYS` = 180 días | `privacidad:retencion` |
| Cuentas sin verificar y sin uso | `RETENTION_UNVERIFIED_DAYS` = 90 días | `privacidad:retencion` (se eliminan igual que a pedido) |
| Constancias de solicitudes ARCO | `RETENTION_REQUESTS_DAYS` = 5 años, sin correo (solo hash) | `privacidad:retencion` |
| Avisos enviados | `NOTIFY_RETENTION_DAYS` = 180 días | `notificaciones:purge` (Fase 8) |
| Sesiones y tokens vencidos | Al vencer | `auth:purge` |
| Copias de seguridad | `BACKUP_KEEP` = 14 copias | Rotación al crear |
| Citas pasadas y cobros | Mientras exista la barbería | Se anonimizan al eliminar la cuenta del cliente (obligación contable) |

## 9. Derechos de las personas (ARCO) y eliminación de cuenta

Todo desde **Mi cuenta → Privacidad y mis datos** (web y app):

| Derecho | Cómo se ejerce | Plazo legal (días hábiles desde el día siguiente) |
|---|---|---|
| Información | Política de privacidad pública, enlazada en el registro, Mi cuenta e inicio | 8 |
| Acceso (y portabilidad) | **Descargar mis datos**: JSON inmediato (`barberapp.datos-personales.v1`) con cuenta y consentimientos, perfil de cliente, barberías donde es cliente, citas, favoritos, equipos, sesiones, dispositivos, preferencias, avisos, actividad de seguridad de los últimos 12 meses y solicitudes. Sin hashes ni notas internas de la barbería | 20 |
| Rectificación | Editar nombre, apellidos y teléfono; cambiar correo (con confirmación en el correo nuevo y aviso al anterior) | 10 |
| Cancelación | **Eliminar mi cuenta** (inmediata) o solicitud escrita para un dato concreto | 10 |
| Oposición | Solicitud escrita; preferencias de avisos y marketing | 10 |

- Las solicitudes escritas generan un código `DP…`, calculan el vencimiento en días hábiles (feriados nacionales del Perú incluidos) y llegan a la bandeja *Plataforma → Privacidad* (permiso `plataforma.privacidad.gestionar`, superadmin y operador). La respuesta llega por correo y a Mi cuenta, con la mención de que puede reclamar ante la **Autoridad Nacional de Protección de Datos Personales (ANPD)**. El reglamento permite ampliar el plazo una sola vez (art. 71): hazlo respondiendo "En proceso" y explicando el motivo.
- **Eliminar la cuenta** (`POST /me/eliminar` con contraseña y la palabra `ELIMINAR`):
  1. Cancela las citas futuras con el motor de reservas (libera el horario y avisa a la barbería).
  2. Borra sesiones, refresh tokens, dispositivos push, favoritos, avisos, preferencias, tokens y claves de idempotencia.
  3. Anonimiza la cuenta y sus perfiles de cliente (sin nombre, correo, teléfono, contraseña ni notas); el correo queda libre para registrarse otra vez.
  4. Revoca las membresías y desvincula el perfil de barbero.
  5. Deja una constancia (solicitud `cancelacion` atendida, con hash del correo) y envía un correo de confirmación.
  - No se permite si es el **único propietario** de una barbería vigente o el **único superadministrador** (se explica qué hacer).
  - En la app, además, borra el refresh token del llavero y deja de recibir notificaciones.
- **Requisito de las tiendas**: Apple (Guideline 5.1.1(v)) y Google Play (política de eliminación de cuentas) exigen eliminar la cuenta desde la app y Google Play pide además un **enlace web**: usa `https://app.barberapp.pe/pages/legal/eliminar-cuenta.html` en la ficha de Play Console (*Seguridad de los datos*).
- **Textos legales versionados**: `LEGAL_TERMINOS_VERSION` y `LEGAL_PRIVACIDAD_VERSION`. Se guarda qué versión aceptó cada persona y cuándo; al publicar una versión nueva, la app la pide al iniciar sesión (Mi cuenta muestra un diálogo que no se puede cerrar sin aceptar, cerrar sesión o eliminar la cuenta).

### Obligaciones que dependen del titular de BARBERAPP

- **Inscribir el banco de datos** de usuarios en el Registro Nacional de Protección de Datos Personales (art. 42 del reglamento; trámite gratuito ante la ANPD) y anotar el código en *Configuración → Datos legales*.
- **Flujo transfronterizo**: Firebase Cloud Messaging (Google, EE. UU.) recibe el token del teléfono y el texto del aviso. Está informado en la política; comunícalo a la ANPD si corresponde según el reglamento vigente.
- **Encargados**: hosting, correo, Firebase y Mercado Pago. Conserva sus contratos o condiciones de tratamiento.
- **Barberías**: son responsables de los datos de sus clientes en su panel; BARBERAPP actúa como encargado. Los términos lo indican; un contrato de encargo formal con cada barbería es recomendable.
- **Oficial de datos personales**: el reglamento lo exige en ciertos casos (gran volumen o datos sensibles); evalúalo al crecer.
- No se tratan datos sensibles ni de tarjetas: el pago de suscripciones ocurre en la página de Mercado Pago y BARBERAPP solo guarda el identificador y el estado del pago.

## 10. Incidentes de seguridad

El reglamento (art. 34) obliga a notificar a la ANPD un incidente que afecte datos personales **dentro de las 48 horas** de conocerlo, y a las personas afectadas cuando el incidente pueda afectarlas significativamente; los incidentes digitales se reportan también al **Centro Nacional de Seguridad Digital**.

1. **Contener**: revoca las sesiones afectadas (o todas: `UPDATE sesiones SET revocada_en = UTC_TIMESTAMP(), motivo_revocacion = 'incidente' WHERE revocada_en IS NULL`), rota `APP_KEY` si se filtró (invalida tokens), cambia las credenciales de MySQL, Mercado Pago y Firebase que pudieron exponerse.
2. **Evaluar**: usa `auditoria` (IP, acción, `request_id`) y los logs para saber qué datos y qué personas se afectaron y desde cuándo.
3. **Notificar** dentro de 48 h: ANPD y, si corresponde, a las personas (correo con qué pasó, qué datos, qué hicimos y qué deben hacer).
4. **Recuperar**: si hay que volver a un estado anterior, `backup:restaurar` a una base nueva, compara y cambia.
5. **Registrar**: deja constancia del incidente, las medidas y las notificaciones.

## 11. Pruebas

- `tests/Integration/Privacy/`: eliminación de cuenta (citas canceladas y horario liberado, anonimización, bloqueos, sesión cerrada, correo reutilizable), exportación (contenido, sin datos de otros ni hashes, contraseña y límite), perfil, contraseña, correo, aceptación de textos, solicitudes ARCO (plazos en días hábiles, bandeja, respuesta, permisos) y retención.
- `tests/Integration/Security/`: HTTPS forzado, proxies de confianza, origen no autorizado, límite general de escrituras, cabeceras; copias (cifrado, alteración detectada, restauración idéntica, rotación, verificación).
- `tests/Unit/SecurityUnitTest.php`: rangos CIDR, días hábiles y feriados.
- Desde la Fase 14: matriz de acceso, CSRF, inyección SQL, XSS y recursos ajenos en todas las rutas; ver [PRUEBAS.md](PRUEBAS.md).
- E2E en Chromium (web y app simulada): páginas legales con datos faltantes y completos, edición de perfil y XSS, cambio de contraseña y correo, descarga de datos (descarga en web, hoja Compartir en la app), solicitudes y respuesta del equipo, aceptación de textos nuevos, bloqueo del único propietario y eliminación de la cuenta.

## 12. Limitaciones conocidas

- Los textos legales necesitan revisión de un abogado y los datos reales del titular.
- La CSP del frontend permite estilos en línea (`'unsafe-inline'` en `style-src`) porque algunas pantallas usan atributos `style`; los scripts en línea sí están prohibidos.
- Las copias se guardan en el mismo servidor; enviarlas fuera (y probar la restauración ahí) es parte del despliegue (Fase 15).
- No hay autenticación en dos pasos (2FA) para el equipo de la plataforma; es la mejora de seguridad recomendada siguiente.
- No se compiló el APK/AAB ni el proyecto de iOS en este entorno; la eliminación de cuenta en la app se probó con Capacitor simulado.
