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

## 3. Hallazgos de la Fase 14

Problemas que encontraron estas pruebas y quedaron corregidos:

1. **Reservar en el teléfono**: al aparecer la tira de fechas, la página medía 1 066 px de ancho en un móvil de 412 px; el navegador alejaba la vista y los botones quedaban diminutos o tapados (`.steps` sin límite de ancho de columna). Corregido en `css/app.css`.
2. **Pantallas de 320 px y tablets**: las opciones de «Reservar» (un `fieldset` crecía con su contenido), el comprobante de la cita (el código ensanchaba la tarjeta), la agenda del panel (la fecha se salía por la derecha), «Mi cuenta» (la tarjeta se ensanchaba con la tabla de avisos) y «Pagos» de la plataforma (el interruptor «Solo en revisión» se estiraba al 100 % y desbordaba en móviles y tablets).
3. **Accesibilidad**: la página de una barbería inexistente no tenía título principal (`h1`) para lectores de pantalla.
4. **Respuesta engañosa**: borrar la política de reserva de una sucursal de **otra** barbería respondía 204 (sin efecto, pero como si hubiera funcionado). Ahora responde 404.
5. **Defensa adicional**: las respuestas JSON ya no contienen `<` ni `>` literales (`JSON_HEX_TAG`).

## 4. Qué no cubren (y cómo cubrirlo)

- **Dispositivos físicos**: los E2E emulan tamaños, toque y motor del navegador; no reemplazan una pasada en un iPhone y un Android reales con la app compilada (lista de verificación en [APPS_MOVILES.md](APPS_MOVILES.md)).
- **Carga y rendimiento**: no hay pruebas de carga sostenida; se recomienda medir en el servidor de la Fase 15 antes de abrir al público.
- **Accesibilidad completa**: solo se exige un título principal por pantalla; una auditoría con lector de pantalla y de contraste queda pendiente.
- **Servicios externos reales**: FCM, Mercado Pago y SMTP se prueban con dobles y con la API simulada; sus credenciales de producción se prueban al desplegar.
