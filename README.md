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
| 16+ | Documentación final | Pendiente |

## Documentación

- [Arquitectura](docs/ARQUITECTURA.md)
- [Modelo entidad-relación](docs/MODELO_ER.md) (diagramas Mermaid; versiones SVG en `docs/diagramas/`)
- [Diccionario de datos](docs/DICCIONARIO_DATOS.md) (generado desde la base)
- [Autenticación, roles y multiempresa](docs/AUTENTICACION.md) (endpoints, sesiones, permisos)
- [Seguridad y protección de datos](docs/SEGURIDAD.md) (Ley 29733, derechos ARCO, eliminar cuenta, copias, incidentes)
- [Marketplace, perfil público y QR](docs/MARKETPLACE.md)
- [Servicios, barberos y horarios](docs/SERVICIOS_HORARIOS.md) (panel, reglas de jornada y bloqueos)
- [Motor de reservas](docs/RESERVAS.md) (disponibilidad, endpoints, concurrencia, idempotencia y política)
- [Panel administrativo](docs/PANEL.md) (agenda, cobros, clientes, reportes, permisos y cálculo de métricas)
- [Panel del barbero](docs/PANEL_BARBERO.md) (agenda propia, atención, horario, ausencias y ajustes del equipo)
- [Avisos y recordatorios](docs/RECORDATORIOS.md) (cola, worker y cron, FCM, preferencias, confirmar asistencia y métricas)
- [Panel del superadministrador SaaS](docs/SUPERADMIN.md) (módulos, privacidad, cálculo de indicadores, ciclo de vida de suscripciones)
- [Planes y monetización](docs/MONETIZACION.md) (límites, prueba, Mercado Pago, webhooks, vencimientos, restricción, SUNAT pendiente)
- [Reportes y analítica](docs/REPORTES.md) (demanda por hora, productividad, recurrencia, previsto frente a cobrado, CSV, Excel y PDF)
- [PWA, Android e iOS](docs/APPS_MOVILES.md) (service worker, instalación, compilación, firma, push, enlaces y reglas de pago de las tiendas)
- [Pruebas y control de calidad](docs/PRUEBAS.md) (cómo correrlas, matriz de pruebas, E2E por dispositivo, integración continua)
- [Despliegue y producción](docs/DESPLIEGUE.md) (VPS, dominios, HTTPS, cron y worker, copias fuera del servidor, monitoreo, actualizar y rollback)
- [Matriz de acceso por rol](docs/MATRIZ_ACCESO.md) (generada por las pruebas)
- [Registro de decisiones técnicas](docs/DECISIONES.md)
- Script SQL completo: [`backend/database/schema.sql`](backend/database/schema.sql)

---

## Instalación local con XAMPP

### Requisitos

| Componente | Versión | Nota |
|---|---|---|
| XAMPP | con PHP **8.0 o superior** | Se usan su Apache y su PHP. Extensiones necesarias (activas por defecto): `pdo_mysql`, `mbstring`, `json`. Para subir fotos: `gd` (con soporte WebP/JPEG) y, opcional, `exif`. En XAMPP activa `extension=gd` en `php.ini`. |
| MySQL Community Server | **8.0.16 o superior** | ⚠️ El "MySQL" que trae XAMPP es en realidad **MariaDB**, que no es compatible con este esquema (usa `REGEXP_LIKE`, colación `utf8mb4_0900_ai_ci` y restricciones `CHECK` de MySQL 8). La consola detecta MariaDB y se detiene con un aviso. |
| Composer | 2.x | Opcional: solo para ejecutar las pruebas. |

### 1. Instalar MySQL 8 junto a XAMPP

1. En el panel de XAMPP, **detén "MySQL"** (MariaDB) y desmarca su inicio automático, para liberar el puerto 3306.
2. Descarga **MySQL Community Server 8.x** desde https://dev.mysql.com/downloads/mysql/ (en Windows, *MySQL Installer*) e instálalo como servicio en el puerto 3306. Anota la contraseña de `root`.
3. Comprueba la versión:
   ```bash
   mysql -u root -p -e "SELECT VERSION();"
   ```
   Debe mostrar `8.0.x` o superior (sin la palabra *MariaDB*).

> Alternativa: usar MySQL 8 en Docker (`docker run -d -p 3306:3306 -e MYSQL_ROOT_PASSWORD=secreto mysql:8.0`).

### 2. Copiar el proyecto

Copia la carpeta `barberapp` a `htdocs`:

- Windows: `C:\xampp\htdocs\barberapp`
- macOS: `/Applications/XAMPP/htdocs/barberapp`
- Linux: `/opt/lampp/htdocs/barberapp`

### 3. Configurar el entorno

```bash
cd C:\xampp\htdocs\barberapp\backend      # o la ruta equivalente
copy .env.example .env                     # macOS/Linux: cp .env.example .env
```

Edita `backend/.env` y coloca tus credenciales de MySQL 8:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=barberapp
DB_USERNAME=root
DB_PASSWORD=tu_contraseña
```

Genera la clave secreta que firma las sesiones (una sola vez):

```bash
php bin/console key:generate
```

En local los correos (verificación, recuperación de contraseña, invitaciones) no se envían: se guardan como archivos `.eml` en `backend/storage/mail/` y se abren con cualquier cliente de correo. Para enviarlos de verdad configura `MAIL_DRIVER=smtp` y los datos `MAIL_*` (ver `docs/AUTENTICACION.md`).

### 4. Crear la base de datos, migrar y cargar datos

Usa el PHP de XAMPP (`C:\xampp\php\php.exe` en Windows si `php` no está en el PATH):

```bash
php bin/console db:create         # crea la base barberapp (utf8mb4)
php bin/console migrate           # aplica las 6 migraciones (32 tablas)
php bin/console db:seed --demo    # catálogo + datos de demostración
php bin/console migrate:status    # todas deben figurar como "aplicada"
```

> Sin consola: abre `backend/database/schema.sql` en MySQL Workbench y ejecútalo; crea la base, todas las tablas y registra las migraciones. Después ejecuta `php bin/console db:seed --demo` para cargar los datos.

### 5. Probar la API

Inicia **Apache** en el panel de XAMPP y abre:

- http://localhost/barberapp/backend/public/api/v1/health
- http://localhost/barberapp/frontend/www/ (splash: comprueba la API y abre el marketplace)
- http://localhost/barberapp/frontend/www/pages/explorar.html (buscar barberías demo)
- http://localhost/barberapp/frontend/www/b/demo-centro (enlace directo; necesita `AllowOverride FileInfo` en Apache, activo por defecto en XAMPP para `htdocs`)
- http://localhost/barberapp/frontend/www/pages/auth/login.html (inicia sesión con un usuario demo)

Respuesta esperada de `/health`:

```json
{
  "ok": true,
  "data": {
    "status": "ok",
    "app": "BARBERAPP",
    "version": "0.3.0",
    "environment": "local",
    "checks": {
      "database": { "status": "ok", "latency_ms": 2.7, "session_time_zone": "+00:00" },
      "migrations": { "aplicadas": 8, "pendientes": 0, "ultima": "0008_marketplace.sql" }
    }
  },
  "meta": { "request_id": "…" }
}
```

Si la base no responde o faltan migraciones, devuelve **HTTP 503** con `"status": "degraded"`.

También se puede probar sin Apache con el servidor de PHP:

```bash
php -S 127.0.0.1:8080 -t public public/index.php
# http://127.0.0.1:8080/api/v1/health
```

### 6. (Opcional) VirtualHost

Para usar `http://api.barberapp.local` en lugar de la ruta larga, sigue `infrastructure/xampp/httpd-vhosts.conf.example`.

### Problemas frecuentes

| Síntoma | Causa / solución |
|---|---|
| `El servidor es MariaDB…` | Estás conectado al MySQL de XAMPP. Detenlo y usa MySQL 8 (paso 1). |
| `Access denied for user` | Revisa `DB_USERNAME` / `DB_PASSWORD` en `backend/.env`. |
| 404 de Apache en `/api/v1/health` | Activa `mod_rewrite` y `AllowOverride All` en `httpd.conf` (XAMPP los trae activos por defecto). |
| 403 al abrir `backend/` | Es intencional: solo `backend/public` es accesible por web. |
| `APP_KEY no está configurada` | Ejecuta `php bin/console key:generate`. |
| El login funciona pero `/me` responde 401 en el navegador | Con `http://` deja `SESSION_SECURE_COOKIE=false`; con HTTPS ponlo en `true`. |
| `too_many_attempts` (429) | Límite de intentos; espera el tiempo indicado en `Retry-After` o, en local, `php bin/console migrate:fresh --demo`. |

---

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

```text
php bin/console db:create          Crea la base de datos si no existe
php bin/console migrate            Aplica migraciones pendientes
php bin/console migrate:status     Estado de cada migración
php bin/console migrate:fresh      Borra todo y migra de nuevo (solo local/testing; --seed o --demo)
php bin/console db:seed [--demo]   Catálogo (roles, permisos, planes, plantillas) [+ demo]
php bin/console schema:build       Regenera database/schema.sql
php bin/console docs:dictionary    Regenera docs/DICCIONARIO_DATOS.md
php bin/console routes             Lista las rutas
php bin/console key:generate       Genera APP_KEY en .env (--force para reemplazarla)
php bin/console auth:purge         Borra sesiones, tokens y contadores vencidos
php bin/console reservas:purge     Borra claves de idempotencia vencidas (reservas)
php bin/console notificaciones:procesar   Envía los avisos pendientes (cron cada minuto)
php bin/console notificaciones:estado     Cola por estado y canal, atrasados y errores de las últimas 24 h
php bin/console notificaciones:purge      Borra avisos terminados antiguos (NOTIFY_RETENTION_DAYS)
php bin/console push:probar correo        Push de prueba a los teléfonos de esa cuenta
php bin/console suscripciones:vencer      Cambios programados, vencimientos, restricción tras la gracia, avisos y órdenes expiradas (cron cada hora)
```

Programa `auth:purge` y `reservas:purge` una vez al día (cron en Linux o el Programador de tareas de Windows):

```cron
15 3 * * * cd /ruta/barberapp/backend && php bin/console auth:purge >> storage/logs/cron.log 2>&1
20 3 * * * cd /ruta/barberapp/backend && php bin/console reservas:purge >> storage/logs/cron.log 2>&1
30 3 * * * cd /ruta/barberapp/backend && php bin/console notificaciones:purge >> storage/logs/cron.log 2>&1
```

**Los recordatorios necesitan el worker cada minuto** (ver [RECORDATORIOS.md](docs/RECORDATORIOS.md#puesta-en-marcha), también para Windows/XAMPP y la configuración de Firebase):

```cron
* * * * * cd /ruta/barberapp/backend && php bin/console notificaciones:procesar >> storage/logs/cron.log 2>&1
5 * * * * cd /ruta/barberapp/backend && php bin/console suscripciones:vencer >> storage/logs/cron.log 2>&1
```

## Pruebas

```bash
cd backend
composer install
vendor/bin/phpunit
```

Las pruebas de integración crean y borran la base `DB_TEST_DATABASE` (por defecto `barberapp_test`); nunca tocan `DB_DATABASE`. Cubren: estructura del esquema, restricciones de integridad, aislamiento entre barberías (FK compuestas y middleware), catálogo de roles/permisos, datos demo, router, validador, el endpoint de salud y, desde la Fase 2, registro, login/logout web y móvil, CSRF, rotación y reutilización de refresh tokens, vencimiento por inactividad, recuperación de contraseña, verificación de correo, límites de intentos, permisos por rol, invitaciones de personal, acceso entre barberías y registro en auditoría de los intentos no autorizados.

Desde la Fase 3 cubren también el marketplace: reglas de visibilidad, búsqueda y filtros (nombre sin tildes, distrito, tipo, precio, distancia), paginación, perfil público, favoritos, perfil público del panel con publicación condicionada, subida de fotos (tipo real, tamaño, reemplazo, límite de galería, aislamiento entre barberías) y el generador de QR, cuyos códigos se decodifican con `zbarimg` si está instalado (`apt-get install zbar-tools`). Las pruebas de fotos se omiten si PHP no tiene GD.

Resultado al cerrar la Fase 3: **135 pruebas OK en PHP 8.3**; en **PHP 8.0.30** pasan 129 y se omiten 6 (la imagen de prueba no tenía GD ni zbar). Además se recorrieron todas las pantallas del marketplace en un navegador móvil emulado (Chromium, 390×844) con Playwright.

Desde la Fase 4 cubren el panel: sucursales y horario de atención, servicios y categorías (duración y precio válidos, inactivos ocultos), barberos (foto, servicios con precio propio, cuentas vinculadas), jornada semanal con descansos (fuera de horario, dos sedes a la vez), jornada efectiva con bloqueos y zona horaria, y vacaciones/ausencias/bloqueos con conflicto de reservas y aprobación.

Resultado al cerrar la Fase 4: **173 pruebas OK en PHP 8.3** (1 omitida, sin zbar); en **PHP 8.0.30** pasan 166 y se omiten 7 (sin GD ni zbar). Recorrido E2E del panel en Chromium móvil: 21/21 comprobaciones OK.

Desde la Fase 5 cubren el motor de reservas: cálculo de horarios (servicio de 45 min, varios servicios, descansos, margen, anticipación y horizonte), reservar, cancelar y que otro tome el horario liberado, bloqueo de día completo, cambio de jornada, otra barbería, reintento con la misma `Idempotency-Key`, reprogramación que conserva el horario anterior, límites de la política y **carreras reales entre procesos** (dos clientes al mismo horario, solapamiento parcial simultáneo, "cualquier barbero", cancelación y reserva a la vez). Ver [RESERVAS.md](docs/RESERVAS.md#pruebas).

Resultado al cerrar la Fase 5: **204 pruebas OK en PHP 8.3** (1 omitida, sin zbar); en **PHP 8.0.30** pasan 197 y se omiten 7 (sin GD ni zbar), incluidas las 5 de concurrencia. Recorrido E2E en Chromium móvil (390×844, zona horaria del teléfono distinta a la de la barbería): elegir sin sesión → login → confirmar → comprobante → reprogramar → confirmar asistencia → mis citas → cancelar → historial.

Desde la Fase 6 cubren el panel administrativo: agenda por barbero y por barbería, alta manual con el mismo motor de reservas, estados de atención y correcciones, cancelar y reprogramar desde el panel, notas, cobros y anulaciones, clientes (búsqueda, ficha, bloqueo), dashboard y reportes con valores reales, y configuración del negocio. Ver [PANEL.md](docs/PANEL.md#pruebas).

Resultado al cerrar la Fase 6: **217 pruebas OK en PHP 8.3** (1 omitida, sin zbar); en **PHP 8.0.30** pasan 210 y se omiten 7 (sin GD ni zbar), con `php -l` limpio en todos los archivos. Recorrido E2E del panel en Chromium (escritorio y móvil 390×844) sin errores de consola ni desbordes.

Desde la Fase 7 cubren el panel del barbero: agenda propia sin datos de caja, siguiente cliente y en atención, atención y correcciones, citas ajenas inaccesibles, historial y visitas anteriores autorizadas, horario editable solo si la barbería lo permite, y solicitudes de ausencia con aprobación, comentario, retiro y avisos. Ver [PANEL_BARBERO.md](docs/PANEL_BARBERO.md#pruebas).

Resultado al cerrar la Fase 7: **228 pruebas OK en PHP 8.3** (1 omitida, sin zbar); en **PHP 8.0.30** pasan 221 y se omiten 7 (sin GD ni zbar). Recorrido E2E del panel del barbero en Chromium (móvil 390×844 y escritorio) sin errores de consola ni desbordes.

Desde la Fase 8 cubren los avisos y recordatorios: programación a las horas configuradas, envío por el worker con la app cerrada, anulación al cancelar o reprogramar, reintentos con espera creciente, tokens push rechazados, workers caídos, preferencias, enlace firmado para confirmar asistencia, bandeja privada, reglas del panel, métricas y FCM HTTP v1 con una clave RSA real. Ver [RECORDATORIOS.md](docs/RECORDATORIOS.md#pruebas).

Resultado al cerrar la Fase 8: **252 pruebas OK en PHP 8.3** (1 omitida, sin zbar); en **PHP 8.0.30** pasan 245 y se omiten 7 (sin GD ni zbar). La migración 0013 se probó sobre una base en 0012 con preferencias existentes. Recorrido E2E en Chromium (móvil 390×844 y escritorio): worker → bandeja y campana → confirmar asistencia desde el enlace → preferencias → reglas y métricas del panel, sin errores de consola ni desbordes.

Desde la Fase 9 cubren el panel del superadministrador: acceso por rol de plataforma sin permisos sobre los datos de las barberías, aprobar/rechazar/suspender/reactivar con avisos y auditoría, suscripciones y pagos manuales, métricas contra valores calculados a mano (MRR, churn, meses en la zona horaria, exclusión de demo, CSV), bloqueo de cuentas y roles, plantillas, configuración y auditoría. Ver [SUPERADMIN.md](docs/SUPERADMIN.md#pruebas).

Resultado al cerrar la Fase 9: **282 pruebas OK en PHP 8.3** (1 omitida, sin zbar); en **PHP 8.0.30** pasan 275 y se omiten 7 (sin GD ni zbar). La migración 0014 se probó sobre una base de la Fase 8 con datos demo. Recorrido E2E en Chromium (escritorio y móvil 390×844) de los 10 módulos con superadmin, operador y cliente, sin errores de consola ni desbordes.

Desde la Fase 10 cubren planes y monetización: límites y funciones por plan, prueba automática, cotización y pago (contratación, renovación, mejora con prorrateo, cambios programados), Mercado Pago con la API simulada (firma, consulta del pago, duplicados, reembolsos, caídas) y el cron de vencimientos y restricción. Ver [MONETIZACION.md](docs/MONETIZACION.md#pruebas).

Resultado al cerrar la Fase 10: **316 pruebas OK en PHP 8.3** (1 omitida, sin zbar); en **PHP 8.0.30** pasan 309 y se omiten 7 (sin GD ni zbar). La migración 0015 se probó sobre una base de la Fase 9 con datos demo. Recorrido E2E en Chromium (escritorio y móvil 390×844): renovar con el simulador y volver con el pago confirmado, cotizar varios meses, programar y anular un cambio, mejora con prorrateo, funciones bloqueadas del plan Básico (reportes, colores, correo), configuración de cobros y pagos en línea de la plataforma; 56/56 comprobaciones sin errores de consola ni desbordes.

Desde la Fase 11 cubren reportes y analítica: confirmación, demanda por día y hora en la zona horaria de la sede, productividad por barbero, clientes nuevos y recurrentes, previsto frente a cobrado y saldo por cobrar, permisos de las exportaciones, CSV con fórmulas neutralizadas y libro `.xlsx` válido. Ver [REPORTES.md](docs/REPORTES.md#pruebas).

Resultado al cerrar la Fase 11: **325 pruebas OK en PHP 8.3** (1 omitida, sin zbar); en **PHP 8.0.30** pasan 317 y se omiten 8 (sin GD, zbar ni zip). Sin migraciones nuevas. Recorrido E2E en Chromium (escritorio y móvil 390×844): indicadores, mapa de demanda, productividad, clientes, ingresos, filtros, las cuatro descargas (CSV y Excel, resumen y detalle), impresión a PDF, administrador sin ingresos y plan Básico; 33/33 comprobaciones sin errores de consola ni desbordes.

Desde la Fase 12 cubren el PDF de reportes generado en el servidor (estructura, acentos, paginación y permisos). Ver [APPS_MOVILES.md](docs/APPS_MOVILES.md#7-pruebas).

Resultado al cerrar la Fase 12: **330 pruebas OK en PHP 8.3** (1 omitida); en **PHP 8.0** pasan todas y se omiten 8 (sin GD, zbar ni zip). `npm run verificar` sin problemas. Recorrido E2E en Chromium con la PWA y con Capacitor simulado: 46/46 comprobaciones. **El APK/AAB y el proyecto de iOS no se compilaron en este entorno** (sin Android SDK ni Xcode); ver la guía.

Desde la Fase 13 cubren seguridad y protección de datos: HTTPS forzado, proxies de confianza, origen no autorizado, límite general de escrituras, eliminación de cuenta (citas canceladas, anonimización, bloqueos), descarga de datos, rectificación, cambio de correo y contraseña, solicitudes ARCO con plazos en días hábiles, retención y copias cifradas (alteración detectada, restauración idéntica, verificación). Ver [SEGURIDAD.md](docs/SEGURIDAD.md#11-pruebas).

Resultado al cerrar la Fase 13: **362 pruebas OK en PHP 8.3** (1 omitida); en **PHP 8.0** pasan todas y se omiten 8 (sin GD, zbar ni zip). La migración 0016 se probó sobre una base de la Fase 12 con datos demo. Recorrido E2E en Chromium (web móvil y app con Capacitor simulado): páginas legales, datos legales del panel, perfil y XSS, contraseña, correo, descarga de datos, solicitudes y respuesta del equipo, aceptación de textos nuevos, bloqueo del único propietario y eliminación de la cuenta; 49/49 comprobaciones sin errores de consola.

Desde la Fase 14 hay una suite de control de calidad completa (guía y matriz en [PRUEBAS.md](docs/PRUEBAS.md)): reglas de estado y políticas de reserva como unitarias puras, flujo de punta a punta solo por la API, y pruebas de seguridad que recorren **todas** las rutas (matriz de acceso de 7 roles aprobada en [MATRIZ_ACCESO.md](docs/MATRIZ_ACCESO.md), CSRF, más de 1 500 peticiones con cargas de inyección SQL y acceso a recursos ajenos por id). `vendor/bin/phpunit --group seguridad` y `--group concurrencia` las corren por separado. El frontend tiene E2E con Playwright (`cd frontend && npm run e2e`, ver la guía) y GitHub Actions corre todo en cada PR.

Resultado al cerrar la Fase 14: **384 pruebas OK en PHP 8.3**; en **PHP 8.0** pasan todas y se omiten 8 (sin GD, zbar ni zip). E2E: **300/300** en Chromium (escritorio, Android, pantalla de 320 px, tablet vertical y horizontal: 58 pantallas con cada rol más el flujo de reserva). Safari (WebKit) y Firefox están configurados y corren en GitHub Actions; en este entorno no hay esos navegadores instalados, así que **no se ejecutaron aquí**.

Desde la Fase 15 hay un camino completo a producción ([DESPLIEGUE.md](docs/DESPLIEGUE.md)): `infrastructure/scripts/instalar-vps.sh` deja un VPS Ubuntu listo con HTTPS, `desplegar.sh` publica cada versión (revisión previa, mantenimiento y copia si hay migraciones, vuelta atrás automática si falla la salud) y `rollback.sh` vuelve a la anterior. Comandos nuevos: `despliegue:verificar`, `despliegue:config`, `monitor:revisar`, `backup:enviar`, `backup:remotas`, `backup:traer`, `mantenimiento:activar|desactivar|estado`, `logs:purge` y `plataforma:superadmin`.

Resultado al cerrar la Fase 15: **412 pruebas OK en PHP 8.3** (1 omitida). Instalación completa, actualización con migración, rollback de código y de base, y vuelta atrás automática probadas en un servidor Ubuntu 24.04 de prueba con Nginx y con Apache; copias externas probadas contra un simulador de S3. Let's Encrypt real, un proveedor S3 real y systemd fuera de la CI quedan para el servidor de producción (ver [DESPLIEGUE.md](docs/DESPLIEGUE.md#17-qué-se-probó-y-qué-no)).

> **Al actualizar una instalación existente:** ejecuta `php bin/console migrate`. Aplica `0011_panel_barberia.sql` (cobros y permiso `reservas.cobrar`, ya asignado a propietarios y administradores), `0012_panel_barbero.sql` (comentario y fecha de respuesta de las solicitudes de ausencia) `0013_recordatorios.sql` (registro de envíos, permiso `notificaciones.gestionar` y preferencias por categoría) `0014_superadmin_saas.sql` (barberías demo, bloqueo de cuentas, historial de suscripciones) `0015_planes_monetizacion.sql` (órdenes de pago, cambios programados, restricción y permiso `negocio.suscripcion.gestionar`) y `0016_seguridad_datos.sql` (solicitudes de derechos ARCO, versión de los textos aceptados, cambio de correo y permiso `plataforma.privacidad.gestionar`). Para el pago en línea configura `PAYMENT_DRIVER` y las claves de Mercado Pago (ver [MONETIZACION.md](docs/MONETIZACION.md#configuración-backendenv)). Desde la Fase 9 ejecuta también `php bin/console db:seed` (agrega el rol `operador`; es idempotente). Programa el cron de `notificaciones:procesar`, `suscripciones:vencer` y, desde la Fase 13, `backup:crear`, `backup:verificar` y `privacidad:retencion` (ver [SEGURIDAD.md](docs/SEGURIDAD.md#7-copias-de-seguridad)).

**Panel de la plataforma:** el equipo de BARBERAPP entra desde *Mi cuenta → Abrir panel de la plataforma* (`pages/plataforma/index.html`). Con los datos demo: `superadmin@barberapp.test` u `operador@barberapp.test`.

**Panel del barbero:** cada barbero entra con su cuenta en *Mi cuenta → Mi agenda* (`pages/barbero/index.html?tenant=slug`). Su cuenta debe estar vinculada a su ficha en *Panel → Barberos*. Con los datos demo: `barbero1.centro@barberapp.test`.

## Seguridad

- Nunca subas `backend/.env` (está en `.gitignore`).
- El servidor web debe apuntar a `backend/public`. En producción: `APP_ENV=production`, `APP_DEBUG=false`, `FORCE_HTTPS=true`, `SESSION_SECURE_COOKIE=true` y una `APP_KEY` propia.
- Antes de publicar ejecuta `php bin/console seguridad:revisar` y sigue la lista de [SEGURIDAD.md](docs/SEGURIDAD.md#1-lista-de-verificación-antes-de-publicar): `TRUSTED_PROXIES`, `BACKUP_KEY`, cron de copias y retención, datos legales del titular e inscripción del banco de datos.
