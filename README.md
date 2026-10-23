# BARBERAPP SaaS

Plataforma SaaS multiempresa de reservas para barberías: marketplace, enlace directo / QR, panel de barbería, panel de barbero y panel de superadministrador. Web, PWA, Android e iOS (Capacitor).

**Stack:** PHP 8.0+ (sin frameworks) · MySQL 8.0+ · HTML5 / CSS3 / JavaScript ES6 · Capacitor.

## Estado

| Fase | Contenido | Estado |
|---|---|---|
| 1 | Estructura, base de datos, núcleo PHP, health check | ✅ Terminada |
| 2 | Autenticación, roles, permisos y multiempresa | ✅ Terminada |
| 3 | Marketplace: explorar barberías, perfil público, favoritos, enlace directo y QR | ✅ Terminada |
| 4 | Panel: sucursales, servicios, categorías, barberos, fotos, jornadas, descansos, vacaciones y bloqueos | ✅ Terminada |
| 5 | Motor de reservas: disponibilidad, reservar, comprobante, mis citas, cancelar y reprogramar sin doble reserva | ✅ Terminada |
| 6 | Panel administrativo: inicio, agenda diaria/semanal, alta manual, asistencia, cobros, clientes, reportes y configuración | ✅ Terminada |
| 7 | Panel del barbero: agenda del día, siguiente cliente, atención, historial, horario y solicitudes de ausencia con aprobación | ✅ Terminada |
| 8 | Recordatorios automáticos: cola persistente, worker por cron, push FCM, correo, bandeja, preferencias, confirmar asistencia y métricas | ✅ Terminada |
| 9 | Superadministrador SaaS: dashboard con MRR y churn reales, barberías, planes, suscripciones, pagos, usuarios, avisos, auditoría, configuración y reportes | ✅ Terminada |
| 10 | Planes y monetización: límites por plan, prueba gratuita automática, pago en línea con Mercado Pago (y simulador local), webhooks firmados, vencimientos, avisos y restricción | ✅ Terminada |
| 11 | Reportes y analítica: confirmación, demanda por hora, productividad, recurrencia, previsto frente a cobrado y exportaciones | ✅ Terminada |
| 12 | PWA, Android e iOS: instalación, service worker, modo sin conexión, app con Capacitor (sesión segura, push, descargas, enlaces directos) y guía de compilación y tiendas | ✅ Terminada |
| 13 | Seguridad y protección de datos: HTTPS, CORS y CSP, límites, eliminar cuenta, descargar mis datos, derechos ARCO, textos legales, copias cifradas con restauración probada y retención (Ley 29733) | ✅ Terminada |
| 14 | Pruebas y control de calidad: reglas de estado unitarias, matriz de acceso rol × ruta, CSRF, inyección SQL, XSS y acceso a recursos ajenos en todas las rutas, flujo de punta a punta, E2E en 9 dispositivos/navegadores e integración continua | ✅ Terminada |
| 15 | Despliegue y producción: instalador para VPS (Nginx o Apache, PHP-FPM, MySQL, HTTPS), configuración generada desde `.env`, cron y worker permanente, copias fuera del servidor (S3 compatible), registros, monitoreo con alertas, despliegue sin corte con mantenimiento y rollback automático | ✅ Terminada |
| 16 | Documentación final: manuales por rol con capturas, manual de instalación, OpenAPI, diccionario de datos y diagramas ER generados desde el código y la base, arquitectura, guías Android e iOS, despliegue, procedimiento de copias, matriz de pruebas y registro de decisiones | ✅ Terminada |

## Documentación

Índice completo, con los 15 entregables de la documentación final y todos los comandos de la consola: **[docs/README.md](docs/README.md)**.

| Para | Documento |
|---|---|
| Usar la aplicación | Manuales del [cliente](docs/manuales/CLIENTE.md), [propietario](docs/manuales/PROPIETARIO.md), [barbero](docs/manuales/BARBERO.md) y [superadministrador](docs/manuales/SUPERADMIN.md) |
| Instalarla en tu computadora | [Manual de instalación](docs/MANUAL_INSTALACION.md) (XAMPP o servidor embebido de PHP) |
| Publicarla en un servidor | [Manual de despliegue](docs/DESPLIEGUE.md) y [procedimiento de copias](docs/BACKUPS.md) |
| Entender cómo está hecha | [Arquitectura](docs/ARQUITECTURA.md), [modelo ER](docs/MODELO_ER.md), [diccionario de datos](docs/DICCIONARIO_DATOS.md) y [decisiones técnicas](docs/DECISIONES.md) |
| Integrar con la API | [Guía de la API](docs/API.md) y [OpenAPI](docs/api/openapi.json) |
| Compilar las apps | Guías de [Android](docs/guias/ANDROID.md) e [iOS](docs/guias/IOS.md) |
| Probar | [Pruebas](docs/PRUEBAS.md) y [matriz de pruebas](docs/MATRIZ_PRUEBAS.md) |

## Instalación local

Resumen (requisitos, XAMPP en Windows, problemas frecuentes y tareas programadas en el [manual de instalación](docs/MANUAL_INSTALACION.md)):

```bash
cd backend
cp .env.example .env              # completa DB_USERNAME y DB_PASSWORD
php bin/console key:generate
php bin/console db:create
php bin/console migrate
php bin/console db:seed --demo    # catálogo y datos de demostración
php -S 127.0.0.1:8080 -t public public/index.php   # API en http://127.0.0.1:8080/api/v1/health
```


## Usuarios de demostración

Creados por `php bin/console db:seed --demo` (solo con `APP_ENV=local` o `testing`). Contraseña: el valor de `DEMO_PASSWORD` en `.env` (por defecto `Demo2026!`). Todos tienen el correo ya verificado.

| Correo | Rol |
|---|---|
| superadmin@barberapp.test | Superadministrador de la plataforma |
| operador@barberapp.test | Operador de la plataforma (soporte y cobranza) |
| propietario.pendiente@barberapp.test | Propietario de *Demo Pendiente* (por aprobar) |
| propietario.centro@barberapp.test | Propietario de *Barbería Demo Centro* |
| admin.centro@barberapp.test | Administrador de *Demo Centro* |
| barbero1.centro@barberapp.test | Barbero en *Demo Centro* |
| barbero2.centro@barberapp.test | Barbero en *Demo Centro* **y** administrador en *Demo Norte* |
| propietario.norte@barberapp.test | Propietario de *Barbería Demo Norte* |
| barbero1.norte@barberapp.test | Barbero en *Demo Norte* |
| cliente1@barberapp.test, cliente2@barberapp.test | Clientes |

Barberías: `demo-centro` (activa, plan Profesional), `demo-norte` (activa, en prueba), `demo-sur` (activa, sede sin coordenadas), `demo-privada` (activa, fuera del marketplace) `demo-suspendida` (suspendida) y `demo-pendiente` (esperando aprobación). Todas están marcadas como demostración (`es_demo`): el panel de la plataforma lo avisa y permite excluirlas de los indicadores. Ver [MARKETPLACE.md](docs/MARKETPLACE.md#datos-demo). Incluye sucursales, horarios con descanso, servicios (uno inactivo), reservas en varios estados, un recordatorio en cola y vacaciones de un barbero.

## Comandos de consola

`php bin/console` (desde `backend/`) muestra todos los comandos con sus opciones. La tabla completa está en [docs/README.md](docs/README.md#comandos-de-la-consola) y las tareas programadas en el [manual de instalación](docs/MANUAL_INSTALACION.md) (local) y en [DESPLIEGUE.md](docs/DESPLIEGUE.md) (servidor).


## Pruebas

```bash
cd backend
composer install
vendor/bin/phpunit
```

Las pruebas de integración crean y borran la base `DB_TEST_DATABASE` (por defecto `barberapp_test`); nunca tocan `DB_DATABASE`. Cubren: estructura del esquema, restricciones de integridad, aislamiento entre barberías (FK compuestas y middleware), catálogo de roles/permisos, datos demo, router, validador, el endpoint de salud y, desde la Fase 2, registro, login/logout web y móvil, CSRF, rotación y reutilización de refresh tokens, vencimiento por inactividad, recuperación de contraseña, verificación de correo, límites de intentos, permisos por rol, invitaciones de personal, acceso entre barberías y registro en auditoría de los intentos no autorizados.

Las pruebas que se agregaron en cada fase y sus resultados al cerrarla están en [PRUEBAS.md](docs/PRUEBAS.md#5-historial-por-fase).

Resultado al cerrar la Fase 16: **418 pruebas OK en PHP 8.3** (2 omitidas: lectura real de QR sin `zbar` y S3 real sin servidor configurado). `docs:verificar` al día y OpenAPI válido con Redocly (0 errores, 5 avisos). PHP 8.0 no está instalado en este entorno: lo comprueba la CI. No se ejecutó de nuevo el E2E porque no cambió ninguna pantalla; el APK/AAB y la app de iOS siguen sin compilar aquí (ver las guías).


> **Al actualizar una instalación existente:** ejecuta `php bin/console migrate`. Aplica `0011_panel_barberia.sql` (cobros y permiso `reservas.cobrar`, ya asignado a propietarios y administradores), `0012_panel_barbero.sql` (comentario y fecha de respuesta de las solicitudes de ausencia) `0013_recordatorios.sql` (registro de envíos, permiso `notificaciones.gestionar` y preferencias por categoría) `0014_superadmin_saas.sql` (barberías demo, bloqueo de cuentas, historial de suscripciones) `0015_planes_monetizacion.sql` (órdenes de pago, cambios programados, restricción y permiso `negocio.suscripcion.gestionar`) y `0016_seguridad_datos.sql` (solicitudes de derechos ARCO, versión de los textos aceptados, cambio de correo y permiso `plataforma.privacidad.gestionar`). Para el pago en línea configura `PAYMENT_DRIVER` y las claves de Mercado Pago (ver [MONETIZACION.md](docs/MONETIZACION.md#configuración-backendenv)). Desde la Fase 9 ejecuta también `php bin/console db:seed` (agrega el rol `operador`; es idempotente). Programa el cron de `notificaciones:procesar`, `suscripciones:vencer` y, desde la Fase 13, `backup:crear`, `backup:verificar` y `privacidad:retencion` (ver [SEGURIDAD.md](docs/SEGURIDAD.md#7-copias-de-seguridad)).

**Panel de la plataforma:** el equipo de BARBERAPP entra desde *Mi cuenta → Abrir panel de la plataforma* (`pages/plataforma/index.html`). Con los datos demo: `superadmin@barberapp.test` u `operador@barberapp.test`.

**Panel del barbero:** cada barbero entra con su cuenta en *Mi cuenta → Mi agenda* (`pages/barbero/index.html?tenant=slug`). Su cuenta debe estar vinculada a su ficha en *Panel → Barberos*. Con los datos demo: `barbero1.centro@barberapp.test`.

## Seguridad

- Nunca subas `backend/.env` (está en `.gitignore`).
- El servidor web debe apuntar a `backend/public`. En producción: `APP_ENV=production`, `APP_DEBUG=false`, `FORCE_HTTPS=true`, `SESSION_SECURE_COOKIE=true` y una `APP_KEY` propia.
- Antes de publicar ejecuta `php bin/console seguridad:revisar` y sigue la lista de [SEGURIDAD.md](docs/SEGURIDAD.md#1-lista-de-verificación-antes-de-publicar): `TRUSTED_PROXIES`, `BACKUP_KEY`, cron de copias y retención, datos legales del titular e inscripción del banco de datos.
