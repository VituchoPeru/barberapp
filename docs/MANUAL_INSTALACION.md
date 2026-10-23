# Manual de instalación

Cómo instalar BARBERAPP en una computadora para desarrollar, probar o hacer una demostración. Hay dos caminos:

- **XAMPP** (Windows, macOS o Linux): Apache y PHP de XAMPP, con **MySQL 8** aparte.
- **Servidor embebido de PHP**, sin Apache: el más rápido si ya tienes PHP y MySQL 8.

Para un servidor de producción (VPS con Nginx o Apache, HTTPS, cron y worker) usa el [Manual de despliegue](DESPLIEGUE.md). Para compilar las apps: [Android](guias/ANDROID.md) e [iOS](guias/IOS.md).

## Contenido

1. [Requisitos](#1-requisitos)
2. [MySQL 8 (no MariaDB)](#2-mysql-8-no-mariadb)
3. [Copiar el proyecto](#3-copiar-el-proyecto)
4. [Configurar `backend/.env`](#4-configurar-backendenv)
5. [Base de datos y datos de demostración](#5-base-de-datos-y-datos-de-demostración)
6. [Abrir la aplicación](#6-abrir-la-aplicación)
7. [Usuarios de demostración](#7-usuarios-de-demostración)
8. [Tareas programadas en local](#8-tareas-programadas-en-local)
9. [Herramientas de desarrollo](#9-herramientas-de-desarrollo)
10. [Actualizar una instalación existente](#10-actualizar-una-instalación-existente)
11. [Problemas frecuentes](#11-problemas-frecuentes)

## 1. Requisitos

| Componente | Versión | Para qué |
|---|---|---|
| PHP | **8.0 o superior** (probado en 8.0 y 8.3) | Backend. Extensiones: `pdo_mysql`, `mbstring`, `json`, `openssl`. Para fotos: `gd` con WebP y JPEG (en XAMPP activa `extension=gd` en `php.ini`). Opcionales: `exif`, `intl` y `zip` (solo la usan las pruebas que abren el Excel exportado). |
| MySQL Community Server | **8.0.16 o superior** | Base de datos. **MariaDB no sirve** (ver el paso 2). |
| Apache (XAMPP) | 2.4 con `mod_rewrite` | Opcional: también se puede usar el servidor embebido de PHP. |
| Composer | 2.x | Solo para correr las pruebas y para generar la documentación. |
| Node.js | **22** o superior | Solo para las pruebas E2E, las capturas de los manuales y las apps (Capacitor). |

El frontend es HTML, CSS y JavaScript sin compilar: no necesita Node para funcionar.

## 2. MySQL 8 (no MariaDB)

El "MySQL" que trae XAMPP es **MariaDB**, que no es compatible con este esquema: usa `REGEXP_LIKE`, la colación `utf8mb4_0900_ai_ci` y restricciones `CHECK` de MySQL 8. La consola detecta MariaDB y se detiene con un aviso.

1. En el panel de XAMPP **detén "MySQL"** y desmarca su inicio automático, para liberar el puerto 3306.
2. Descarga **MySQL Community Server 8.x** desde https://dev.mysql.com/downloads/mysql/ (en Windows, *MySQL Installer*) e instálalo como servicio en el puerto 3306. Anota la contraseña de `root`.
3. Comprueba la versión:

   ```bash
   mysql -u root -p -e "SELECT VERSION();"
   ```

   Debe mostrar `8.0.x` o superior, sin la palabra *MariaDB*.

**Alternativa con Docker:** `docker run -d --name barberapp-mysql -p 3306:3306 -e MYSQL_ROOT_PASSWORD=secreto mysql:8.0`

**Recomendado:** crea un usuario propio en lugar de usar `root`:

```sql
CREATE USER 'barberapp'@'localhost' IDENTIFIED BY 'una_clave_local';
GRANT ALL PRIVILEGES ON `barberapp`.* TO 'barberapp'@'localhost';
GRANT ALL PRIVILEGES ON `barberapp\_%`.* TO 'barberapp'@'localhost';  -- bases de pruebas, E2E y restauración
```

## 3. Copiar el proyecto

Con XAMPP, clona o copia la carpeta `barberapp` dentro de `htdocs`:

| Sistema | Ruta |
|---|---|
| Windows | `C:\xampp\htdocs\barberapp` |
| macOS | `/Applications/XAMPP/htdocs/barberapp` |
| Linux | `/opt/lampp/htdocs/barberapp` |

Con el servidor embebido de PHP, la carpeta puede estar en cualquier lugar.

Solo `backend/public` y `frontend/www` se sirven por web. El resto (código, `.env`, copias, registros) queda fuera.

## 4. Configurar `backend/.env`

```bash
cd backend
cp .env.example .env            # Windows: copy .env.example .env
```

Edita `backend/.env` con tus datos de MySQL:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=barberapp
DB_USERNAME=barberapp
DB_PASSWORD=una_clave_local
```

Genera la clave que firma las sesiones y los tokens (una sola vez):

```bash
php bin/console key:generate
```

Con XAMPP, si `php` no está en el PATH, usa `C:\xampp\php\php.exe bin\console …` en Windows.

Valores útiles en local (ya vienen así en `.env.example`):

| Variable | Valor en local | Qué hace |
|---|---|---|
| `APP_ENV` | `local` | Permite los datos de demostración y `migrate:fresh`. |
| `MAIL_DRIVER` | `log` | Los correos (verificación, recuperación, avisos) no se envían: quedan como archivos `.eml` en `backend/storage/mail/`. Se abren con cualquier cliente de correo. |
| `PUSH_DRIVER` | `log` | Los push quedan en `backend/storage/push/`. |
| `PAYMENT_DRIVER` | `sandbox` | Pago en línea con el simulador local, sin dinero real. |
| `SESSION_SECURE_COOKIE` | `false` | Necesario con `http://`. |

Todas las variables están comentadas en `.env.example`. Las de producción se explican en [DESPLIEGUE.md](DESPLIEGUE.md#6-variables-de-entorno-de-producción).

## 5. Base de datos y datos de demostración

```bash
php bin/console db:create         # crea la base (utf8mb4, utf8mb4_0900_ai_ci)
php bin/console migrate           # aplica las 16 migraciones (44 tablas)
php bin/console db:seed --demo    # catálogo (roles, permisos, planes, plantillas) + datos demo
php bin/console migrate:status    # todas deben figurar como "aplicada"
```

`db:seed` sin `--demo` carga solo el catálogo, que es lo que se usa en producción. Los datos de demostración solo se cargan con `APP_ENV=local` o `testing`.

Para empezar de cero: `php bin/console migrate:fresh --demo` (borra todas las tablas; solo en local).

**Sin consola:** abre `backend/database/schema.sql` en MySQL Workbench y ejecútalo. Crea la base, todas las tablas y registra las migraciones. Después ejecuta `php bin/console db:seed --demo`.

## 6. Abrir la aplicación

### Con XAMPP

Inicia **Apache** en el panel de XAMPP y abre:

| Dirección | Qué es |
|---|---|
| http://localhost/barberapp/backend/public/api/v1/health | Salud de la API |
| http://localhost/barberapp/frontend/www/ | La app: comprueba la API y abre el marketplace |
| http://localhost/barberapp/frontend/www/b/demo-centro | Enlace directo de una barbería (necesita `AllowOverride FileInfo`, activo por defecto en XAMPP) |
| http://localhost/barberapp/frontend/www/pages/auth/login.html | Iniciar sesión con un usuario demo |

Opcional: para usar `http://api.barberapp.local` en lugar de la ruta larga, sigue `infrastructure/xampp/httpd-vhosts.conf.example`.

### Sin Apache (servidor embebido de PHP)

Desde la raíz del repositorio, con el mismo enrutador que usan las pruebas E2E (sirve la web, la API y los enlaces directos con las mismas cabeceras de seguridad que Apache):

```bash
php -S 127.0.0.1:8090 -t . frontend/e2e/router.php
# http://127.0.0.1:8090/frontend/www/
# http://127.0.0.1:8090/backend/public/api/v1/health
```

Solo la API, desde `backend/`:

```bash
php -S 127.0.0.1:8080 -t public public/index.php
# http://127.0.0.1:8080/api/v1/health
```

El frontend calcula solo la dirección de la API a partir de su carpeta (`backend/public` del mismo proyecto). No hace falta configurar nada mientras ambos se sirvan desde el mismo origen. `frontend/www/js/site-config.js` queda vacío en local; solo el despliegue lo completa.

### Respuesta esperada de `/health`

```json
{
  "ok": true,
  "data": {
    "status": "ok",
    "maintenance": false,
    "app": "BARBERAPP",
    "version": "1.0.0-rc.1",
    "environment": "local",
    "php": "8.3.6",
    "checks": {
      "database": { "status": "ok", "latency_ms": 2.14, "session_time_zone": "+00:00", "version": "8.0.46" },
      "migrations": { "aplicadas": 16, "pendientes": 0, "ultima": "0016_seguridad_datos.sql" }
    }
  },
  "meta": { "request_id": "…" }
}
```

Respuesta real de esta instalación de prueba (Ubuntu 24.04, PHP 8.3.6, MySQL 8.0.46), resumida. Si la base no responde o faltan migraciones, `/health` devuelve **HTTP 503** con `"status": "degraded"`.

## 7. Usuarios de demostración

Los crea `php bin/console db:seed --demo`. La contraseña es `DEMO_PASSWORD` de `.env` (por defecto `Demo2026!`). Todos tienen el correo verificado.

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

Barberías: `demo-centro` (activa, plan Profesional), `demo-norte` (activa, en prueba), `demo-sur` (activa, sede sin coordenadas), `demo-privada` (activa, fuera del marketplace), `demo-suspendida` y `demo-pendiente` (esperando aprobación). Todas están marcadas como demostración: el panel de la plataforma lo avisa y permite excluirlas de los indicadores. Incluyen sucursales, horarios con descanso, servicios (uno inactivo), reservas en varios estados, un recordatorio en cola y vacaciones de un barbero. Las citas de demostración se crean para el día siguiente (de lunes a sábado), contado desde el momento de cargar los datos.

Qué puede hacer cada uno: [manual del cliente](manuales/CLIENTE.md), [del propietario](manuales/PROPIETARIO.md), [del barbero](manuales/BARBERO.md) y [del superadministrador](manuales/SUPERADMIN.md).

## 8. Tareas programadas en local

La aplicación funciona sin ellas, pero los **recordatorios** solo se envían si corre el worker, y los vencimientos de suscripciones solo se aplican con su tarea.

Lo mínimo para probar avisos, en una terminal aparte:

```bash
php bin/console notificaciones:procesar --continuo     # Ctrl+C para detenerlo
```

En Linux o macOS, con cron:

```cron
* * * * * cd /ruta/barberapp/backend && php bin/console notificaciones:procesar >> storage/logs/cron.log 2>&1
5 * * * * cd /ruta/barberapp/backend && php bin/console suscripciones:vencer >> storage/logs/cron.log 2>&1
```

En **Windows**, crea una tarea por comando en el *Programador de tareas*:

- Programa: `C:\xampp\php\php.exe`
- Argumentos: `bin\console notificaciones:procesar`
- Iniciar en: `C:\xampp\htdocs\barberapp\backend`
- Repetición: cada minuto para el worker y cada hora para `suscripciones:vencer`.

La lista completa de tareas de producción está en [DESPLIEGUE.md](DESPLIEGUE.md#9-cron-y-worker) y la de copias en [BACKUPS.md](BACKUPS.md). `php bin/console monitor:revisar` también funciona en local: avisa de las tareas que nunca corrieron.

## 9. Herramientas de desarrollo

### Pruebas del backend

```bash
cd backend
composer install
vendor/bin/phpunit                         # todo (unitarias e integración)
vendor/bin/phpunit --testsuite unit        # solo unitarias, sin base de datos
vendor/bin/phpunit --group seguridad       # matriz de acceso, CSRF, inyección, XSS
```

Las pruebas de integración **borran y recrean** la base `DB_TEST_DATABASE` (por defecto `barberapp_test`). Nunca tocan `DB_DATABASE`. Detalle y matriz: [PRUEBAS.md](PRUEBAS.md) y [MATRIZ_PRUEBAS.md](MATRIZ_PRUEBAS.md).

### Pruebas E2E del frontend

```bash
cd frontend
npm ci
npx playwright install chromium            # una vez
npm run e2e                                # Chromium: escritorio, Android, 320 px y tablets
```

Usan su propia base, `barberapp_e2e`, y levantan solas el servidor embebido.

### Documentación generada

```bash
cd backend
php bin/console docs:generar     # OpenAPI, diccionario de datos, diagramas ER, endpoints, errores y matriz de pruebas
php bin/console docs:verificar   # código 1 si algo quedó desactualizado (lo corre la CI)
```

Se generan desde la base migrada (`DB_DATABASE`), las rutas reales y los archivos de pruebas. Más detalle en el [índice de la documentación](README.md).

Capturas de los manuales (con MySQL y Node; crea la base `barberapp_manual`):

```bash
cd frontend && node scripts/capturas-manuales.mjs
```

### Otros comandos

```bash
php bin/console routes            # lista las 195 rutas con su nombre
php bin/console schema:build      # regenera database/schema.sql desde las migraciones
php bin/console help              # todos los comandos
```

## 10. Actualizar una instalación existente

```bash
git pull
cd backend
composer install --no-dev         # si cambió composer.json
php bin/console migrate           # aplica las migraciones nuevas
php bin/console db:seed           # catálogo: es idempotente (agrega roles, permisos y plantillas nuevas)
```

Las notas de cada migración están en [MODELO_ER.md](MODELO_ER.md#migraciones). En producción no se hace a mano: `desplegar.sh` respalda la base, activa el modo mantenimiento y vuelve atrás solo si algo falla (ver [DESPLIEGUE.md](DESPLIEGUE.md#13-actualizar-a-una-versión-nueva)).

## 11. Problemas frecuentes

| Síntoma | Causa y solución |
|---|---|
| `El servidor es MariaDB…` | Estás conectado al MySQL de XAMPP. Detenlo y usa MySQL 8 (paso 2). |
| `Access denied for user` | Revisa `DB_USERNAME` y `DB_PASSWORD` en `backend/.env`. |
| 404 de Apache en `/api/v1/health` | Activa `mod_rewrite` y `AllowOverride All` en `httpd.conf` (XAMPP los trae activos por defecto). |
| 403 al abrir `backend/` | Es intencional: solo `backend/public` es accesible por web. |
| `APP_KEY no está configurada` | Ejecuta `php bin/console key:generate`. |
| El login funciona, pero `/me` responde 401 en el navegador | Con `http://` deja `SESSION_SECURE_COOKIE=false`; con HTTPS ponlo en `true`. |
| `too_many_attempts` (429) | Límite de intentos: espera el tiempo de `Retry-After` o, en local, `php bin/console migrate:fresh --demo`. |
| No llegan los correos | En local no se envían: búscalos en `backend/storage/mail/`. Para enviarlos usa `MAIL_DRIVER=smtp` y los datos `MAIL_*`. |
| Los recordatorios no salen | El worker no está corriendo (paso 8). `php bin/console notificaciones:estado` muestra la cola. |
| No se pueden subir fotos | Falta la extensión `gd` de PHP. |
| No hay horarios libres en la demo | Las citas y jornadas de la demo dependen del día en que se cargaron. Vuelve a cargarlas con `php bin/console migrate:fresh --demo`. |
| `docs:verificar` dice que la documentación está desactualizada | Cambió una ruta, una tabla o una prueba: ejecuta `php bin/console docs:generar` y revisa el diff. |
