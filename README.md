# BARBERAPP SaaS

Plataforma SaaS multiempresa de reservas para barberías: marketplace, enlace directo / QR, panel de barbería, panel de barbero y panel de superadministrador. Web, PWA, Android e iOS (Capacitor).

**Stack:** PHP 8.0+ (sin frameworks) · MySQL 8.0+ · HTML5 / CSS3 / JavaScript ES6 · Capacitor.

## Estado

| Fase | Contenido | Estado |
|---|---|---|
| 1 | Estructura, base de datos, núcleo PHP, health check | ✅ Terminada |
| 2 | Autenticación y multitenancy | Pendiente |
| 3+ | Marketplace, agenda, reservas, paneles, notificaciones, apps… | Pendiente |

## Documentación

- [Arquitectura](docs/ARQUITECTURA.md)
- [Modelo entidad-relación](docs/MODELO_ER.md) (diagramas Mermaid; versiones SVG en `docs/diagramas/`)
- [Diccionario de datos](docs/DICCIONARIO_DATOS.md) (generado desde la base)
- [Registro de decisiones técnicas](docs/DECISIONES.md)
- Script SQL completo: [`backend/database/schema.sql`](backend/database/schema.sql)

---

## Instalación local con XAMPP

### Requisitos

| Componente | Versión | Nota |
|---|---|---|
| XAMPP | con PHP **8.0 o superior** | Se usan su Apache y su PHP. Extensiones necesarias (activas por defecto): `pdo_mysql`, `mbstring`, `json`. |
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
- http://localhost/barberapp/frontend/www/ (debe mostrar "API conectada")

Respuesta esperada de `/health`:

```json
{
  "ok": true,
  "data": {
    "status": "ok",
    "app": "BARBERAPP",
    "version": "0.1.0",
    "environment": "local",
    "checks": {
      "database": { "status": "ok", "latency_ms": 2.7, "session_time_zone": "+00:00" },
      "migrations": { "aplicadas": 6, "pendientes": 0, "ultima": "0006_sistema.sql" }
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

---

## Usuarios de demostración

Creados por `php bin/console db:seed --demo` (solo con `APP_ENV=local` o `testing`). Contraseña: el valor de `DEMO_PASSWORD` en `.env` (por defecto `Demo2026!`). El inicio de sesión se implementa en la Fase 2.

| Correo | Rol |
|---|---|
| superadmin@barberapp.test | Superadministrador de la plataforma |
| propietario.centro@barberapp.test | Propietario de *Barbería Demo Centro* |
| admin.centro@barberapp.test | Administrador de *Demo Centro* |
| barbero1.centro@barberapp.test | Barbero en *Demo Centro* |
| barbero2.centro@barberapp.test | Barbero en *Demo Centro* **y** administrador en *Demo Norte* |
| propietario.norte@barberapp.test | Propietario de *Barbería Demo Norte* |
| barbero1.norte@barberapp.test | Barbero en *Demo Norte* |
| cliente1@barberapp.test, cliente2@barberapp.test | Clientes |

Barberías: `demo-centro` (activa, plan Profesional), `demo-norte` (activa, en prueba), `demo-suspendida` (suspendida). Incluye sucursales, horarios con descanso, servicios (uno inactivo), reservas en varios estados, un recordatorio en cola y vacaciones de un barbero.

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
```

## Pruebas

```bash
cd backend
composer install
vendor/bin/phpunit
```

Las pruebas de integración crean y borran la base `DB_TEST_DATABASE` (por defecto `barberapp_test`); nunca tocan `DB_DATABASE`. Cubren: estructura del esquema, restricciones de integridad, aislamiento entre barberías (FK compuestas y middleware), catálogo de roles/permisos, datos demo, router, validador y el endpoint de salud (en proceso y por HTTP real).

## Seguridad

- Nunca subas `backend/.env` (está en `.gitignore`).
- El servidor web debe apuntar a `backend/public`. En producción: `APP_ENV=production`, `APP_DEBUG=false`, `FORCE_HTTPS=true`.
