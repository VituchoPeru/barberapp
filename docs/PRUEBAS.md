# Pruebas y control de calidad (Fase 14)

Cómo se prueba BARBERAPP, qué cubre cada prueba y cómo correrlas. Regla del proyecto: **ninguna fase se da por terminada ni un PR se fusiona con pruebas críticas en rojo**.

## 1. Cómo correrlas

### Backend (PHPUnit 9.6, PHP 8.0+ y MySQL 8)

```bash
cd backend
composer install
vendor/bin/phpunit                         # todo: unitarias + integración
vendor/bin/phpunit --testsuite unit        # solo unitarias (sin base de datos)
vendor/bin/phpunit --group seguridad       # acceso, CSRF, inyección, IDOR, HTTPS, copias
vendor/bin/phpunit --group concurrencia    # carreras reales entre procesos
composer lint                              # php -l en todos los archivos
```

Las pruebas de integración crean y borran `DB_TEST_DATABASE` (por defecto `barberapp_test`); nunca tocan `DB_DATABASE`. Cada prueba corre dentro de una transacción que se revierte.

### Frontend y E2E (Playwright)

```bash
cd frontend
npm install
npx playwright install chromium            # una vez (para Safari y Firefox: chromium webkit firefox)
npm run verificar                          # PWA: manifest, service worker, sin scripts en línea
npm run e2e                                # Chromium en 5 dispositivos
npm run e2e:todos                          # + Safari (iPhone, iPad, Mac) y Firefox
npx playwright test --project=android-pixel-7 -g reserva   # un dispositivo / una prueba
```

`npm run e2e` necesita PHP en el PATH, MySQL encendido y `backend/.env` con `APP_KEY` (el mismo de desarrollo). Levanta solo el servidor embebido de PHP en `http://127.0.0.1:8090` con `frontend/e2e/router.php` (mismas cabeceras y CSP que `.htaccess`), y prepara una base **propia** `barberapp_e2e` (variable `BARBERAPP_E2E_DB`): la borra, migra, carga los datos demo y agrega una carga XSS a los textos visibles. Tu base de desarrollo no se toca.

### Integración continua

`.github/workflows/calidad.yml` corre en cada push a `main` y en cada PR:

| Trabajo | Qué hace |
|---|---|
| Backend PHP 8.0 | `php -l`, unitarias, integración (incluye seguridad y concurrencia) sobre MySQL 8.0 |
| Backend PHP 8.3 | lo mismo con la versión de producción recomendada |
| Frontend y E2E | `npm run verificar` y `npm run e2e:todos` (Chromium, WebKit y Firefox); si falla, sube capturas y trazas como artefacto `informe-e2e` |
| Despliegue (Fase 15) | `shellcheck` de los scripts; `instalar-vps.sh` completo en el runner limpio (Nginx, PHP-FPM, MySQL, systemd, certificado propio), salud, `desplegar.sh` y `rollback.sh` |

## 2. Matriz de pruebas

Lo que pide la Fase 14 y dónde se prueba. Rutas relativas a `backend/tests/` o `frontend/e2e/`.

### Unitarias

| Requisito | Pruebas |
|---|---|
| Cálculo de duración | `Unit/AvailabilityUnitTest`: servicio de 30 y de 45 min en la grilla, varios servicios con la duración y precio propios del barbero; `Unit/ScheduleUnitTest`: duración y precio válidos |
| Cálculo de disponibilidad | `Unit/AvailabilityUnitTest`: descansos, margen entre citas, anticipación, solapamiento parcial, la cita que se reprograma no se bloquea a sí misma; `Unit/ScheduleUnitTest`: jornadas, bloqueos de día completo y de sucursal, horario de la sede, zona horaria |
| Validación de políticas | `Unit/BookingRulesTest`: plazo de cancelación (justo en el límite, antes, después, cita empezada), plazo y límite de reprogramaciones, el personal sin plazo, anticipación mínima y horizonte contado en la fecha de la sede; `Unit/AvailabilityUnitTest`: valores por defecto de la política |
| Control de estados | `Unit/BookingRulesTest`: matriz completa estado × acción (confirmar, iniciar, completar, inasistencia) para los 5 estados, demasiado temprano, atención iniciada, cobros, correcciones dentro de 7 días, reintentos idempotentes |
| Otras | validador, router, entorno, tokens y CSRF, política de contraseñas, correo (inyección de cabeceras), QR (Reed-Solomon y decodificación real), PDF, CSV/Excel (fórmulas neutralizadas), FCM, rangos CIDR, días hábiles |

### Integración (API completa en proceso, mismos middleware que producción)

| Requisito | Pruebas |
|---|---|
| Registro | `Integration/Auth/RegisterTest` (cliente y propietario, slugs, validación, duplicados, límite por IP) |
| Login | `Integration/Auth/LoginSessionTest` (web y móvil, CSRF, rotación de refresh, expiración, bloqueo, cerrar sesiones) y `PasswordRecoveryTest` |
| Creación de barbería | `RegisterTest` (barbería pendiente), `Platform/PlatformTenantsTest` (aprobar, rechazar, suspender), `FullJourneyTest` |
| Creación de servicios | `Catalog/ServicesApiTest`, `Catalog/BarbersApiTest`, `Catalog/BranchesApiTest`, `Catalog/AgendaBlocksApiTest` |
| Creación de reservas | `Booking/BookingApiTest`, `Panel/PanelAgendaTest` (alta manual), `FullJourneyTest` |
| Cancelaciones | `BookingApiTest` (el horario se libera y otro cliente lo toma), `PanelAgendaTest`, `Privacy/AccountDeletionTest` |
| Reprogramaciones | `BookingApiTest` (conserva el horario anterior hasta confirmar), `PanelAgendaTest` (a otro barbero) |
| Notificaciones | `Notifications/ReminderQueueTest` (programación, worker, reintentos, deduplicación), `NotificationsApiTest` |
| Flujo completo | `FullJourneyTest`: registro → aprobación → sucursal, servicio y barbero → cliente verificado → disponibilidad → reserva → agenda → reprogramar → cancelar → el horario vuelve a estar libre |

### Concurrencia (procesos PHP reales contra MySQL; `--group concurrencia`)

| Requisito | Prueba en `Integration/Booking/ConcurrencyTest` |
|---|---|
| Reservas simultáneas | `testTwoClientsBookTheSameSlotAtTheSameTime`, `testPartiallyOverlappingRequestsAtTheSameTime`, `testThreeClientsChooseAnyBarberForTwoFreeBarbers` |
| Cancelación y nueva reserva simultáneas | `testCancellationAndNewBookingAtTheSameTime` |
| Reintentos duplicados | `testNetworkRetryWithTheSameKeyArrivingTwiceAtOnce` |

### Seguridad (`--group seguridad`)

| Requisito | Pruebas |
|---|---|
| Acceso entre tenants | `Security/AccessMatrixTest` (personal, cliente y equipo de la plataforma contra el panel de otra barbería → 404), `Security/ForeignResourceTest` (propietario y barbero de demo-norte contra **cada** recurso real de demo-centro), `TenantContextMiddlewareTest`, `ConstraintsTest` (FK compuestas en la base) |
| Escalada de privilegios | `Security/AccessMatrixTest`: 7 roles × todas las rutas comparados con la matriz aprobada ([MATRIZ_ACCESO.md](MATRIZ_ACCESO.md)), más reglas fijas (el barbero no ve dinero ni personal, el administrador no toca la suscripción ni el equipo, el operador no administra planes ni usuarios); `Auth/AuthorizationTest` (no se puede invitar propietarios ni roles de plataforma) |
| SQL Injection | `Security/InjectionTest`: 9 cargas (comillas, UNION, apilado, SLEEP, LIKE, comentarios) en ~60 campos de query y cuerpo de **todas** las rutas, con cada cuenta que las alcanza (más de 1 500 peticiones): sin 500, sin mensajes de MySQL, sin hashes, sin demoras y sin cambios en las tablas; la cabecera `X-Tenant` y el buscador tratan la carga como texto |
| XSS | API: `InjectionTest::testStoredMarkupIsReturnedAsJsonDataNotHtml` (nombres rechazan `<>`, textos libres se devuelven como dato JSON con `nosniff` y `<`); correo: `NotificationsUnitTest` (escape HTML); navegador: `e2e/pantallas.spec.mjs` y `reserva.spec.mjs` revisan que ningún texto con etiquetas sembrado en la base se convierta en HTML en ninguna pantalla |
| CSRF | `AccessMatrixTest::testEveryWriteWithCookieSessionRequiresCsrf` (todas las rutas de escritura sin token o con token falso → 403 y auditoría), token de otra sesión rechazado, `HttpHardeningTest` (Origin ajeno), `LoginSessionTest` |
| Acceso a reservas ajenas | `ForeignResourceTest` (cliente2 contra cada ruta de `/mis-reservas/{id}` de cliente1; barbero contra citas de otro), `Barber/BarberPanelTest`, `BookingApiTest` |
| Además | HTTPS forzado, proxies de confianza, límites de escritura, cabeceras, copias cifradas (`HttpHardeningTest`, `BackupTest`) |

### Multiplataforma (E2E)

Cada dispositivo recorre **todas las pantallas** (58 visitas, con la cuenta que las usa: visitante, cliente, propietario, administrador, barbero y superadministrador) y el **flujo de reserva** del cliente (elegir servicio, barbero, día y hora → comprobante → mis citas → cancelar). En cada pantalla se exige: sin errores de JavaScript, sin respuestas 5xx, sin recursos faltantes, sin desborde horizontal, sin esqueletos de carga pendientes, con título principal y sin XSS.

| Plataforma | Proyecto de Playwright | Dónde corre |
|---|---|---|
| Chrome escritorio (1440×900) | `chrome-escritorio` | local y CI |
| Android (Pixel 7, táctil) | `android-pixel-7` | local y CI |
| Pantalla pequeña (320×568) | `pantalla-pequena-320` | local y CI |
| Tablet vertical y horizontal | `tablet-galaxy-tab`, `tablet-horizontal` | local y CI |
| Safari iPhone (iOS) | `safari-iphone-13` (WebKit) | CI (`npm run e2e:todos`) |
| Safari iPad | `safari-ipad` (WebKit) | CI |
| Safari macOS | `safari-mac` (WebKit) | CI |
| Firefox escritorio | `firefox-escritorio` | CI |

La app nativa (Android/iOS con Capacitor) usa el mismo frontend dentro de la WebView del sistema (Chromium en Android, WebKit en iOS). Su prueba en dispositivos reales está en [APPS_MOVILES.md](APPS_MOVILES.md): requiere Android Studio y Xcode.

### Despliegue y operación (Fase 15)

| Qué | Dónde |
|---|---|
| Plantillas: marcadores desconocidos, URLs sin https o con ruta, dominios repetidos, carpetas dentro de una versión, IPv6, IPs de la plataforma, Apache y Nginx | `Unit/DeployUnitTest.php` |
| Firma SigV4 contra los ejemplos oficiales de AWS, codificación de claves y consultas | `Unit/DeployUnitTest.php` |
| Frontend: URL de la API y versión del service worker por despliegue | `Unit/DeployUnitTest.php` |
| Copias fuera: solo cifradas, SHA-256, rotación con `BACKUP_REMOTE_KEEP`, fotos incrementales y borradas, clave sin permiso de borrar, traer y verificar | `Integration/Ops/OffsiteBackupTest.php` (doble de S3; con `BARBERAPP_S3_PRUEBA_ENDPOINT` también contra un S3 real o simulado) |
| Mantenimiento (503 y `/health/live`), `/health/operacion` con y sin token, latidos, alertas con repetición y resolución, purga de registros, superadministrador, `db:seed` que no pisa planes | `Integration/Ops/OperationsTest.php` |

## 3. Hallazgos de la Fase 14

Problemas que encontraron estas pruebas y quedaron corregidos:

1. **Reservar en el teléfono**: al aparecer la tira de fechas, la página medía 1 066 px de ancho en un móvil de 412 px; el navegador alejaba la vista y los botones quedaban diminutos o tapados (`.steps` sin límite de ancho de columna). Corregido en `css/app.css`.
2. **Pantallas de 320 px y tablets**: las opciones de «Reservar» (un `fieldset` crecía con su contenido), el comprobante de la cita (el código ensanchaba la tarjeta), la agenda del panel (la fecha se salía por la derecha), «Mi cuenta» (la tarjeta se ensanchaba con la tabla de avisos) y «Pagos» de la plataforma (el interruptor «Solo en revisión» se estiraba al 100 % y desbordaba en móviles y tablets).
3. **Accesibilidad**: la página de una barbería inexistente no tenía título principal (`h1`) para lectores de pantalla.
4. **Respuesta engañosa**: borrar la política de reserva de una sucursal de **otra** barbería respondía 204 (sin efecto, pero como si hubiera funcionado). Ahora responde 404.
5. **Defensa adicional**: las respuestas JSON ya no contienen `<` ni `>` literales (`JSON_HEX_TAG`).
6. **Safari en iPhone y iPad** (solo lo vio la CI con WebKit): la agenda y la ficha del barbero quedaban más anchas que la pantalla. Safari suma al ancho de la página el texto interno de un `<select>` o un `<input>` (la opción más larga, un valor largo) aunque el control lo recorte. Cada `.field` y cada tramo de la jornada recortan ahora en horizontal (`overflow-x: clip`). La prueba E2E, si vuelve a pasar, nombra el elemento cuyo contenido desborda.

## 4. Qué no cubren (y cómo cubrirlo)

- **Dispositivos físicos**: los E2E emulan tamaños, toque y motor del navegador; no reemplazan una pasada en un iPhone y un Android reales con la app compilada (lista de verificación en [APPS_MOVILES.md](APPS_MOVILES.md)).
- **Carga y rendimiento**: no hay pruebas de carga sostenida; se recomienda medir en el servidor de la Fase 15 antes de abrir al público.
- **Accesibilidad completa**: solo se exige un título principal por pantalla; una auditoría con lector de pantalla y de contraste queda pendiente.
- **Servicios externos reales**: FCM, Mercado Pago y SMTP se prueban con dobles y con la API simulada; sus credenciales de producción se prueban al desplegar.

## 5. Historial por fase

Qué pruebas se agregaron en cada fase y el resultado real al cerrarla, tal como se registró en ese momento (movido desde el README en la Fase 16).

Desde la Fase 3 cubren también el marketplace: reglas de visibilidad, búsqueda y filtros (nombre sin tildes, distrito, tipo, precio, distancia), paginación, perfil público, favoritos, perfil público del panel con publicación condicionada, subida de fotos (tipo real, tamaño, reemplazo, límite de galería, aislamiento entre barberías) y el generador de QR, cuyos códigos se decodifican con `zbarimg` si está instalado (`apt-get install zbar-tools`). Las pruebas de fotos se omiten si PHP no tiene GD.

Resultado al cerrar la Fase 3: **135 pruebas OK en PHP 8.3**; en **PHP 8.0.30** pasan 129 y se omiten 6 (la imagen de prueba no tenía GD ni zbar). Además se recorrieron todas las pantallas del marketplace en un navegador móvil emulado (Chromium, 390×844) con Playwright.

Desde la Fase 4 cubren el panel: sucursales y horario de atención, servicios y categorías (duración y precio válidos, inactivos ocultos), barberos (foto, servicios con precio propio, cuentas vinculadas), jornada semanal con descansos (fuera de horario, dos sedes a la vez), jornada efectiva con bloqueos y zona horaria, y vacaciones/ausencias/bloqueos con conflicto de reservas y aprobación.

Resultado al cerrar la Fase 4: **173 pruebas OK en PHP 8.3** (1 omitida, sin zbar); en **PHP 8.0.30** pasan 166 y se omiten 7 (sin GD ni zbar). Recorrido E2E del panel en Chromium móvil: 21/21 comprobaciones OK.

Desde la Fase 5 cubren el motor de reservas: cálculo de horarios (servicio de 45 min, varios servicios, descansos, margen, anticipación y horizonte), reservar, cancelar y que otro tome el horario liberado, bloqueo de día completo, cambio de jornada, otra barbería, reintento con la misma `Idempotency-Key`, reprogramación que conserva el horario anterior, límites de la política y **carreras reales entre procesos** (dos clientes al mismo horario, solapamiento parcial simultáneo, "cualquier barbero", cancelación y reserva a la vez). Ver [RESERVAS.md](RESERVAS.md#pruebas).

Resultado al cerrar la Fase 5: **204 pruebas OK en PHP 8.3** (1 omitida, sin zbar); en **PHP 8.0.30** pasan 197 y se omiten 7 (sin GD ni zbar), incluidas las 5 de concurrencia. Recorrido E2E en Chromium móvil (390×844, zona horaria del teléfono distinta a la de la barbería): elegir sin sesión → login → confirmar → comprobante → reprogramar → confirmar asistencia → mis citas → cancelar → historial.

Desde la Fase 6 cubren el panel administrativo: agenda por barbero y por barbería, alta manual con el mismo motor de reservas, estados de atención y correcciones, cancelar y reprogramar desde el panel, notas, cobros y anulaciones, clientes (búsqueda, ficha, bloqueo), dashboard y reportes con valores reales, y configuración del negocio. Ver [PANEL.md](PANEL.md#pruebas).

Resultado al cerrar la Fase 6: **217 pruebas OK en PHP 8.3** (1 omitida, sin zbar); en **PHP 8.0.30** pasan 210 y se omiten 7 (sin GD ni zbar), con `php -l` limpio en todos los archivos. Recorrido E2E del panel en Chromium (escritorio y móvil 390×844) sin errores de consola ni desbordes.

Desde la Fase 7 cubren el panel del barbero: agenda propia sin datos de caja, siguiente cliente y en atención, atención y correcciones, citas ajenas inaccesibles, historial y visitas anteriores autorizadas, horario editable solo si la barbería lo permite, y solicitudes de ausencia con aprobación, comentario, retiro y avisos. Ver [PANEL_BARBERO.md](PANEL_BARBERO.md#pruebas).

Resultado al cerrar la Fase 7: **228 pruebas OK en PHP 8.3** (1 omitida, sin zbar); en **PHP 8.0.30** pasan 221 y se omiten 7 (sin GD ni zbar). Recorrido E2E del panel del barbero en Chromium (móvil 390×844 y escritorio) sin errores de consola ni desbordes.

Desde la Fase 8 cubren los avisos y recordatorios: programación a las horas configuradas, envío por el worker con la app cerrada, anulación al cancelar o reprogramar, reintentos con espera creciente, tokens push rechazados, workers caídos, preferencias, enlace firmado para confirmar asistencia, bandeja privada, reglas del panel, métricas y FCM HTTP v1 con una clave RSA real. Ver [RECORDATORIOS.md](RECORDATORIOS.md#pruebas).

Resultado al cerrar la Fase 8: **252 pruebas OK en PHP 8.3** (1 omitida, sin zbar); en **PHP 8.0.30** pasan 245 y se omiten 7 (sin GD ni zbar). La migración 0013 se probó sobre una base en 0012 con preferencias existentes. Recorrido E2E en Chromium (móvil 390×844 y escritorio): worker → bandeja y campana → confirmar asistencia desde el enlace → preferencias → reglas y métricas del panel, sin errores de consola ni desbordes.

Desde la Fase 9 cubren el panel del superadministrador: acceso por rol de plataforma sin permisos sobre los datos de las barberías, aprobar/rechazar/suspender/reactivar con avisos y auditoría, suscripciones y pagos manuales, métricas contra valores calculados a mano (MRR, churn, meses en la zona horaria, exclusión de demo, CSV), bloqueo de cuentas y roles, plantillas, configuración y auditoría. Ver [SUPERADMIN.md](SUPERADMIN.md#pruebas).

Resultado al cerrar la Fase 9: **282 pruebas OK en PHP 8.3** (1 omitida, sin zbar); en **PHP 8.0.30** pasan 275 y se omiten 7 (sin GD ni zbar). La migración 0014 se probó sobre una base de la Fase 8 con datos demo. Recorrido E2E en Chromium (escritorio y móvil 390×844) de los 10 módulos con superadmin, operador y cliente, sin errores de consola ni desbordes.

Desde la Fase 10 cubren planes y monetización: límites y funciones por plan, prueba automática, cotización y pago (contratación, renovación, mejora con prorrateo, cambios programados), Mercado Pago con la API simulada (firma, consulta del pago, duplicados, reembolsos, caídas) y el cron de vencimientos y restricción. Ver [MONETIZACION.md](MONETIZACION.md#pruebas).

Resultado al cerrar la Fase 10: **316 pruebas OK en PHP 8.3** (1 omitida, sin zbar); en **PHP 8.0.30** pasan 309 y se omiten 7 (sin GD ni zbar). La migración 0015 se probó sobre una base de la Fase 9 con datos demo. Recorrido E2E en Chromium (escritorio y móvil 390×844): renovar con el simulador y volver con el pago confirmado, cotizar varios meses, programar y anular un cambio, mejora con prorrateo, funciones bloqueadas del plan Básico (reportes, colores, correo), configuración de cobros y pagos en línea de la plataforma; 56/56 comprobaciones sin errores de consola ni desbordes.

Desde la Fase 11 cubren reportes y analítica: confirmación, demanda por día y hora en la zona horaria de la sede, productividad por barbero, clientes nuevos y recurrentes, previsto frente a cobrado y saldo por cobrar, permisos de las exportaciones, CSV con fórmulas neutralizadas y libro `.xlsx` válido. Ver [REPORTES.md](REPORTES.md#pruebas).

Resultado al cerrar la Fase 11: **325 pruebas OK en PHP 8.3** (1 omitida, sin zbar); en **PHP 8.0.30** pasan 317 y se omiten 8 (sin GD, zbar ni zip). Sin migraciones nuevas. Recorrido E2E en Chromium (escritorio y móvil 390×844): indicadores, mapa de demanda, productividad, clientes, ingresos, filtros, las cuatro descargas (CSV y Excel, resumen y detalle), impresión a PDF, administrador sin ingresos y plan Básico; 33/33 comprobaciones sin errores de consola ni desbordes.

Desde la Fase 12 cubren el PDF de reportes generado en el servidor (estructura, acentos, paginación y permisos). Ver [APPS_MOVILES.md](APPS_MOVILES.md#7-pruebas).

Resultado al cerrar la Fase 12: **330 pruebas OK en PHP 8.3** (1 omitida); en **PHP 8.0** pasan todas y se omiten 8 (sin GD, zbar ni zip). `npm run verificar` sin problemas. Recorrido E2E en Chromium con la PWA y con Capacitor simulado: 46/46 comprobaciones. **El APK/AAB y el proyecto de iOS no se compilaron en este entorno** (sin Android SDK ni Xcode); ver la guía.

Desde la Fase 13 cubren seguridad y protección de datos: HTTPS forzado, proxies de confianza, origen no autorizado, límite general de escrituras, eliminación de cuenta (citas canceladas, anonimización, bloqueos), descarga de datos, rectificación, cambio de correo y contraseña, solicitudes ARCO con plazos en días hábiles, retención y copias cifradas (alteración detectada, restauración idéntica, verificación). Ver [SEGURIDAD.md](SEGURIDAD.md#11-pruebas).

Resultado al cerrar la Fase 13: **362 pruebas OK en PHP 8.3** (1 omitida); en **PHP 8.0** pasan todas y se omiten 8 (sin GD, zbar ni zip). La migración 0016 se probó sobre una base de la Fase 12 con datos demo. Recorrido E2E en Chromium (web móvil y app con Capacitor simulado): páginas legales, datos legales del panel, perfil y XSS, contraseña, correo, descarga de datos, solicitudes y respuesta del equipo, aceptación de textos nuevos, bloqueo del único propietario y eliminación de la cuenta; 49/49 comprobaciones sin errores de consola.

Desde la Fase 14 hay una suite de control de calidad completa (guía y matriz en [PRUEBAS.md](PRUEBAS.md)): reglas de estado y políticas de reserva como unitarias puras, flujo de punta a punta solo por la API, y pruebas de seguridad que recorren **todas** las rutas (matriz de acceso de 7 roles aprobada en [MATRIZ_ACCESO.md](MATRIZ_ACCESO.md), CSRF, más de 1 500 peticiones con cargas de inyección SQL y acceso a recursos ajenos por id). `vendor/bin/phpunit --group seguridad` y `--group concurrencia` las corren por separado. El frontend tiene E2E con Playwright (`cd frontend && npm run e2e`, ver la guía) y GitHub Actions corre todo en cada PR.

Resultado al cerrar la Fase 14: **384 pruebas OK en PHP 8.3**; en **PHP 8.0** pasan todas y se omiten 8 (sin GD, zbar ni zip). E2E: **300/300** en Chromium (escritorio, Android, pantalla de 320 px, tablet vertical y horizontal: 58 pantallas con cada rol más el flujo de reserva). Safari (WebKit) y Firefox están configurados y corren en GitHub Actions; en este entorno no hay esos navegadores instalados, así que **no se ejecutaron aquí**.

Desde la Fase 15 hay un camino completo a producción ([DESPLIEGUE.md](DESPLIEGUE.md)): `infrastructure/scripts/instalar-vps.sh` deja un VPS Ubuntu listo con HTTPS, `desplegar.sh` publica cada versión (revisión previa, mantenimiento y copia si hay migraciones, vuelta atrás automática si falla la salud) y `rollback.sh` vuelve a la anterior. Comandos nuevos: `despliegue:verificar`, `despliegue:config`, `monitor:revisar`, `backup:enviar`, `backup:remotas`, `backup:traer`, `mantenimiento:activar|desactivar|estado`, `logs:purge` y `plataforma:superadmin`.

Resultado al cerrar la Fase 15: **412 pruebas OK en PHP 8.3** (1 omitida). Instalación completa, actualización con migración, rollback de código y de base, y vuelta atrás automática probadas en un servidor Ubuntu 24.04 de prueba con Nginx y con Apache; copias externas probadas contra un simulador de S3. Let's Encrypt real, un proveedor S3 real y systemd fuera de la CI quedan para el servidor de producción (ver [DESPLIEGUE.md](DESPLIEGUE.md#17-qué-se-probó-y-qué-no)).

Desde la Fase 16, `DocumentationTest` comprueba la documentación (generada al día, OpenAPI con todas las rutas, enlaces, comandos y entregables) y `BackupTest` cubre las copias nombradas sin su carpeta.

Resultado al cerrar la Fase 16: **418 pruebas OK en PHP 8.3** (2 omitidas: lectura real de QR sin `zbar` y S3 real sin servidor configurado). `docs:verificar` al día y OpenAPI válido con Redocly (0 errores, 5 avisos). PHP 8.0 no está instalado en este entorno: lo comprueba la CI. No se ejecutó de nuevo el E2E porque no cambió ninguna pantalla; el APK/AAB y la app de iOS siguen sin compilar aquí (ver las guías).
