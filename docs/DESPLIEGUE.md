# Despliegue y producción (Fase 15)

Cómo publicar BARBERAPP en un servidor propio (VPS), mantenerlo y actualizarlo, y cómo seguir trabajando en local con XAMPP. Todo lo que se describe aquí se probó en un servidor Ubuntu 24.04 de prueba con Nginx y con Apache (sección 17).

**En resumen:** en un VPS limpio se ejecuta un solo script (`instalar-vps.sh`) que deja HTTPS, PHP-FPM, MySQL, cron, worker, copias y monitoreo funcionando. Cada actualización es `desplegar.sh`, que se puede deshacer con `rollback.sh`.

## Índice

1. [Cómo queda en producción](#1-cómo-queda-en-producción)
2. [Requisitos del servidor](#2-requisitos-del-servidor)
3. [Dominios y DNS](#3-dominios-y-dns)
4. [Instalación en un VPS](#4-instalación-en-un-vps)
5. [Después de instalar](#5-después-de-instalar)
6. [Variables de entorno de producción](#6-variables-de-entorno-de-producción)
7. [Servidor web, PHP-FPM y configuración generada](#7-servidor-web-php-fpm-y-configuración-generada)
8. [Certificados HTTPS](#8-certificados-https)
9. [Cron y worker](#9-cron-y-worker)
10. [Copias de seguridad](#10-copias-de-seguridad)
11. [Registros (logs)](#11-registros-logs)
12. [Monitoreo](#12-monitoreo)
13. [Actualizar a una versión nueva](#13-actualizar-a-una-versión-nueva)
14. [Rollback](#14-rollback)
15. [Local con XAMPP](#15-local-con-xampp)
16. [Lista de salida a producción](#16-lista-de-salida-a-producción)
17. [Qué se probó y qué no](#17-qué-se-probó-y-qué-no)

---

## 1. Cómo queda en producción

| Dominio (referencial) | Qué sirve | Raíz en el servidor |
|---|---|---|
| `app.barberapp.pe` | Clientes, marketplace, enlace directo `/b/{slug}` y QR, PWA | `current/frontend/www` |
| `admin.barberapp.pe` | Panel de cada barbería (`/` → `pages/panel/inicio.html`) | `current/frontend/www` |
| `platform.barberapp.pe` | Panel de la plataforma (`/` → `pages/plataforma/index.html`), opcionalmente solo desde ciertas IP | `current/frontend/www` |
| `api.barberapp.pe` | API REST (`/api/v1/…`) y fotos (`/media/…`) | `current/backend/public` |

Los tres frontends son el mismo `frontend/www` (un solo frontend, como en la Fase 12); solo cambia la página de inicio. La sesión web es una cookie `HttpOnly` y `Secure` de `api.barberapp.pe`; como los cuatro dominios son del mismo sitio (`barberapp.pe`), el navegador la envía desde cualquiera de los frontends, y la API solo acepta los orígenes de `CORS_ALLOWED_ORIGINS`.

```text
/var/www/barberapp/
├── current → releases/20261022233412-ab12cd3     ← versión que atiende (enlace)
├── releases/                                      ← últimas 5 versiones, de root y solo lectura para PHP
│   └── 20261022233412-ab12cd3/
│       ├── backend/.env → ../../shared/.env
│       ├── backend/storage → ../../shared/storage
│       ├── REVISION, .despliegue                   ← commit, copia previa, migraciones aplicadas
│       └── …
├── shared/
│   ├── .env                                        ← 640 root:barberapp, nunca dentro de una carpeta pública
│   ├── storage/{cache,mail,push}                   ← latidos, mantenimiento, bloqueos del worker
│   ├── media/                                      ← fotos de las barberías (MEDIA_PATH)
│   └── well-known/                                 ← assetlinks.json y apple-app-site-association (Fase 12)
├── repo.git                                        ← espejo del repositorio
└── despliegues.log
/var/backups/barberapp/   ← copias cifradas (700, usuario barberapp)
/var/log/barberapp/       ← registros de la aplicación, cron, monitoreo, PHP y despliegues
/etc/barberapp/           ← despliegue.conf y credenciales fuera del repositorio (Firebase)
```

PHP corre con su propio usuario (`barberapp`) en un pool de PHP-FPM propio; el servidor web (`www-data`) solo habla con él por un socket. El código es de `root`: aunque alguien lograra ejecutar algo en PHP, no podría modificarlo.

## 2. Requisitos del servidor

| | Mínimo | Recomendado para empezar |
|---|---|---|
| Sistema | Ubuntu 22.04 o Debian 12 | **Ubuntu 24.04 LTS** (PHP 8.3, MySQL 8.0, Nginx 1.24) |
| CPU / memoria | 1 vCPU, 2 GB | 2 vCPU, 4 GB |
| Disco | 20 GB SSD | 40 GB SSD (las copias se guardan 14 días en el servidor) |
| PHP | 8.0 (compatibilidad del código) | **8.3** o superior: PHP 8.0 y 8.1 ya no reciben parches de seguridad |
| MySQL | 8.0.16 | 8.0 o 8.4 LTS. **No MariaDB** (el esquema usa funciones de MySQL 8) |
| Servidor web | Apache 2.4 con `mod_proxy_fcgi` | **Nginx** |

Proveedores con centro de datos cercano a Perú (São Paulo o Santiago) dan menos latencia; cualquier VPS con Ubuntu sirve. `instalar-vps.sh` instala todo lo demás.

## 3. Dominios y DNS

Los dominios de este documento son **referenciales**: antes de usarlos, comprueba que `barberapp.pe` esté disponible o sea tuyo (registro en [punto.pe](https://punto.pe), NIC Perú) y crea cuatro registros DNS hacia la IP del servidor:

```text
app.barberapp.pe       A     203.0.113.10     (y AAAA si el servidor tiene IPv6)
admin.barberapp.pe     A     203.0.113.10
platform.barberapp.pe  A     203.0.113.10
api.barberapp.pe       A     203.0.113.10
```

Comprueba que respondan antes de instalar (Let's Encrypt los valida):

```bash
dig +short app.barberapp.pe admin.barberapp.pe platform.barberapp.pe api.barberapp.pe
```

Con otros nombres basta cambiarlos en `vps.conf`: nada del código tiene los dominios escritos. Si usas **Cloudflare** con el proxy activado, pon sus rangos de IP en `TRUSTED_PROXIES` (sección 6) para que la API vea la IP real y detecte HTTPS.

## 4. Instalación en un VPS

```bash
# En el servidor, como root
apt-get update && apt-get install -y git
git clone https://github.com/VituchoPeru/barberapp.git /root/barberapp
cp /root/barberapp/infrastructure/scripts/vps.conf.example /root/barberapp-vps.conf
nano /root/barberapp-vps.conf        # dominios, correo, nginx/apache, versión de PHP
bash /root/barberapp/infrastructure/scripts/instalar-vps.sh --config=/root/barberapp-vps.conf
```

Qué hace, en orden (se puede volver a ejecutar; no reemplaza claves ni datos existentes):

1. Instala Nginx (o Apache), PHP-FPM con `pdo_mysql`, `mbstring`, `gd`, `curl`, `xml`, `zip` e `intl`, MySQL 8, certbot, cron y logrotate.
2. Pone el servidor en UTC, crea el usuario `barberapp` y las carpetas de la sección 1.
3. Crea la base y un usuario de MySQL propio con contraseña aleatoria (con permiso sobre `barberapp_restore_check` para la prueba de restauración).
4. Crea `shared/.env` desde [`backend/.env.production.example`](../backend/.env.production.example) con los dominios, `APP_KEY`, `BACKUP_KEY` y `MONITOR_TOKEN` generados.
5. Publica la primera versión con `desplegar.sh` (migraciones y catálogo).
6. Genera e instala la configuración de Nginx/Apache, PHP-FPM, cron, worker y logrotate (sección 7).
7. Pide el certificado a Let's Encrypt para los cuatro dominios.
8. Activa el worker (`barberapp-worker`), hace la primera copia cifrada y la verifica restaurándola.
9. Comprueba `https://api…/api/v1/health` y el frontend, y ejecuta `seguridad:revisar`.

Opciones: `--certificado=propio` (autofirmado de 30 días, para un staging sin DNS), `--certificado=existente`, `--sin-paquetes`, `--activar-firewall` (activa `ufw` con 22, 80 y 443; sin esta opción solo deja las reglas listas, para no cortar un SSH en otro puerto).

> ⚠ Al final el script muestra `BACKUP_KEY`. **Guárdala en tu gestor de contraseñas, fuera del servidor.** Sin ella ninguna copia se puede restaurar, ni siquiera las guardadas fuera.

## 5. Después de instalar

1. **Tu cuenta de superadministrador** (en producción no hay usuarios de demostración):
   ```bash
   cd /var/www/barberapp/current/backend
   sudo -u barberapp php bin/console plataforma:superadmin tu@correo.pe --nombre="Victor"
   ```
   Muestra una contraseña temporal una sola vez; cámbiala en *Mi cuenta*. Si la cuenta ya existe, solo le asigna el rol.
2. **Completa `shared/.env`**: SMTP (`MAIL_*`), Firebase (`FCM_CREDENTIALS=/etc/barberapp/firebase-adminsdk.json`, archivo `640 root:barberapp`), copias fuera del servidor (`BACKUP_S3_*`, sección 10) y, cuando corresponda, Mercado Pago (`PAYMENT_DRIVER=mercadopago`; antes resolver SUNAT e IGV, ver [MONETIZACION.md](MONETIZACION.md)). Después, `sudo /var/www/barberapp/current/infrastructure/scripts/desplegar.sh` para que PHP lea los valores nuevos.
3. **Datos legales del responsable** en *Plataforma → Configuración* ([SEGURIDAD.md](SEGURIDAD.md#9-derechos-de-las-personas-arco-y-eliminación-de-cuenta)) y la inscripción del banco de datos ante la ANPD.
4. **Enlaces de las apps**: copia `infrastructure/well-known/*.example` a `/var/www/barberapp/shared/well-known/` con la huella de Play y el Team ID de Apple ([APPS_MOVILES.md](APPS_MOVILES.md)), y compila las apps con `BARBERAPP_API_URL=https://api.barberapp.pe/api/v1 BARBERAPP_WEB_URL=https://app.barberapp.pe`.
5. **Revisión final**: `sudo -u barberapp php bin/console seguridad:revisar` y `monitor:revisar` sin errores.

## 6. Variables de entorno de producción

El archivo completo, comentado, es [`backend/.env.production.example`](../backend/.env.production.example). Las nuevas de esta fase:

| Variable | Para qué |
|---|---|
| `ADMIN_URL`, `PLATFORM_URL` | Dominios del panel de barberías y de la plataforma. Vacías = se entra por `FRONTEND_URL`. |
| `LOG_PATH`, `LOG_RETENTION_DAYS` | Carpeta de registros fuera de la versión (`/var/log/barberapp`) y días que se guardan (30). |
| `BACKUP_S3_ENDPOINT`, `_REGION`, `_BUCKET`, `_KEY`, `_SECRET` | Almacenamiento externo de las copias (sección 10). |
| `BACKUP_S3_PREFIX`, `BACKUP_S3_PATH_STYLE`, `BACKUP_REMOTE_KEEP`, `BACKUP_S3_MEDIA` | Carpeta dentro del bucket, estilo de URL (MinIO), copias que conserva el servidor en el bucket (0 = las borra la regla del bucket) y si también sube las fotos. |
| `MONITOR_EMAIL`, `MONITOR_TOKEN` | A quién avisar si algo falla y token de `/api/v1/health/operacion`. |
| `MONITOR_MAX_WORKER_MIN`, `MONITOR_MIN_DISCO_LIBRE`, `MONITOR_MAX_ERRORES_HORA`, `MONITOR_MIN_DIAS_CERTIFICADO`, `MONITOR_REPETIR_HORAS` | Umbrales del monitoreo (5 min, 10 %, 20 errores, 14 días, 6 h). |

Valores obligatorios en producción (los revisa `despliegue:verificar` antes de cada publicación y `seguridad:revisar`): `APP_ENV=production`, `APP_DEBUG=false`, `FORCE_HTTPS=true`, `SESSION_SECURE_COOKIE=true`, URLs `https://` sin ruta, `APP_KEY` y `BACKUP_KEY` propias, usuario de MySQL distinto de `root` y con contraseña, `CORS_ALLOWED_ORIGINS` con los tres frontends más `capacitor://localhost` y `https://localhost`, y sin datos de demostración.

## 7. Servidor web, PHP-FPM y configuración generada

La configuración del servidor no se edita a mano: se genera desde las plantillas de `infrastructure/` con los valores de `.env`, así el servidor web, PHP, el cron y el worker usan siempre las mismas rutas y dominios que la aplicación.

```bash
cd /var/www/barberapp/current/backend
php bin/console despliegue:config --salida=/tmp/barberapp-config [--servidor=nginx|apache] [--php=8.3] \
    [--worker=systemd|cron] [--ips-plataforma=203.0.113.7,198.51.100.0/24] [--ipv6=auto|si|no]
```

| Archivo generado | Destino | Contenido |
|---|---|---|
| `nginx/barberapp-api.conf` | `/etc/nginx/sites-available/` | API con PHP-FPM; `/media/` como estático sin PHP; nada más que `index.php` se ejecuta; archivos ocultos denegados |
| `nginx/barberapp-{app,admin,plataforma}.conf` | ídem | Frontend con CSP limitada a la API, `/b/{slug}`, service worker y manifiesto sin caché, `.well-known` de las apps, página de inicio de cada dominio |
| `nginx/snippets/*.conf` | `/etc/nginx/snippets/` | TLS 1.2/1.3 y cabeceras de seguridad |
| `apache/*.conf` | `/etc/apache2/sites-available/` | Lo mismo para Apache 2.4 (`mod_proxy_fcgi`, `mod_headers`, `mod_rewrite`) |
| `php/barberapp-fpm.conf` | `/etc/php/8.3/fpm/pool.d/barberapp.conf` | Pool propio: usuario `barberapp`, `open_basedir`, sin `exec`/`shell_exec`, subidas de 6 MB |
| `php/99-barberapp.ini` | `/etc/php/8.3/{fpm,cli}/conf.d/` | `expose_php=Off`, OPcache, UTC |
| `systemd/barberapp-worker.service` | `/etc/systemd/system/` | Worker de avisos permanente |
| `cron/barberapp` | `/etc/cron.d/barberapp` | Tareas programadas (sección 9) |
| `logrotate/barberapp` | `/etc/logrotate.d/barberapp` | Rotación de registros |

`despliegue:config` se niega a generar con valores peligrosos: URLs sin `https`, con ruta o repetidas, o carpetas de fotos, registros o copias dentro de una versión (se perderían al actualizar). Con `--ips-plataforma`, el panel de la plataforma solo abre desde esas direcciones; la API sigue protegiéndose por permisos, así que es una capa más, no la única.

Para cambiar un dominio después de instalar: edita `shared/.env`, vuelve a generar, copia los archivos, `nginx -t && systemctl reload nginx`, y despliega (el frontend toma la URL de la API en cada despliegue).

## 8. Certificados HTTPS

- Un solo certificado de Let's Encrypt para los cuatro dominios (`certbot --cert-name barberapp`), pedido por webroot (`/var/www/letsencrypt`), sin detener el servidor.
- certbot lo renueva solo (temporizador del sistema); el gancho `/etc/letsencrypt/renewal-hooks/deploy/barberapp-recargar.sh` recarga Nginx/Apache.
- `monitor:revisar` avisa si a un certificado le quedan menos de 14 días (renovación rota).
- HTTP redirige a HTTPS (301), el frontend envía HSTS de un año y la API rechaza escrituras por HTTP (`FORCE_HTTPS`, Fase 13).

Comprobar la renovación: `certbot renew --dry-run`.

## 9. Cron y worker

El servidor está en **UTC**; Lima es UTC−5. Las tareas que molestan menos van de madrugada en Lima.

| Tarea | Cuándo (UTC → Lima) | Qué hace |
|---|---|---|
| `barberapp-worker` (systemd) | siempre | Procesa la cola de avisos sin esperar al minuto (`notificaciones:procesar --continuo`). Se reinicia solo cada hora y en cada despliegue. Sin systemd: cron cada minuto. |
| `suscripciones:vencer` | cada hora, minuto 5 | Vencimientos, restricción, cambios programados y avisos (Fase 10) |
| `monitor:revisar --alertar` | cada 5 min | Correo a `MONITOR_EMAIL` si algo falla (sección 12) |
| `backup:crear` | 08:15 → 03:15 | Copia cifrada |
| `backup:enviar` | 08:45 → 03:45 | Copia y fotos fuera del servidor |
| `backup:verificar` | domingo 09:30 → 04:30 | Prueba de restauración en una base temporal |
| `privacidad:retencion` | 09:00 → 04:00 | Retención de datos (Ley 29733) |
| `auth:purge`, `reservas:purge`, `notificaciones:purge`, `logs:purge` | 09:10–09:40 | Limpieza |

Cada tarea, al terminar bien, deja un **latido** (`shared/storage/cache/latidos/*.json`). Así el monitoreo detecta un cron que dejó de correr aunque no haya escrito ningún error.

```bash
systemctl status barberapp-worker      # estado del worker
journalctl -u barberapp-worker -f      # su salida
tail -f /var/log/barberapp/cron.log    # salida del cron
```

## 10. Copias de seguridad

Hay tres capas:

1. **En el servidor** (Fase 13): `backup:crear` cada día, cifrada con AES-256-GCM, 14 copias en `/var/backups/barberapp`.
2. **Fuera del servidor** (esta fase): `backup:enviar` sube cada copia cifrada (con su `.sha256`) y las fotos de las barberías a un bucket compatible con S3. Si se pierde el servidor entero, los datos siguen ahí.
3. **Prueba de restauración**: `backup:verificar` cada semana restaura la última copia en una base temporal y compara las filas de cada tabla.

### Configurar el almacenamiento externo

Cualquier proveedor compatible con S3 sirve. Ejemplos de `BACKUP_S3_ENDPOINT` y `BACKUP_S3_REGION`:

| Proveedor | Endpoint | Región |
|---|---|---|
| Amazon S3 (São Paulo) | `https://s3.sa-east-1.amazonaws.com` | `sa-east-1` |
| Cloudflare R2 | `https://<id-de-cuenta>.r2.cloudflarestorage.com` | `auto` |
| Backblaze B2 | `https://s3.us-west-004.backblazeb2.com` | `us-west-004` |
| DigitalOcean Spaces | `https://nyc3.digitaloceanspaces.com` | `nyc3` |
| MinIO propio | `https://minio.tu-dominio.pe` (con `BACKUP_S3_PATH_STYLE=true`) | `us-east-1` |

Recomendado, y así viene configurado (`BACKUP_REMOTE_KEEP=0`):

- **Bucket privado** solo para BARBERAPP, en otra cuenta o proveedor distinto del VPS.
- **Clave de acceso con permiso solo de leer, listar y escribir** en ese bucket, **sin borrar**. Si alguien toma el servidor no puede eliminar las copias externas.
- **Versionado** del bucket y una **regla de ciclo de vida** que borre los objetos de más de 30 días (y las versiones anteriores de más de 30 días). Así se cumple la minimización de datos de la Ley 29733 sin que el servidor tenga permiso de borrar. Con `BACKUP_REMOTE_KEEP=N` (y permiso de borrado) el servidor conserva él mismo las N más recientes.

Las fotos se sincronizan de forma incremental (solo las nuevas o cambiadas); una foto borrada en BARBERAPP (por ejemplo, la de un barbero que se fue) se borra también del bucket. Con una clave sin permiso de borrado, `backup:enviar` lo informa y la regla de ciclo de vida la elimina con el tiempo.

```bash
sudo -u barberapp php bin/console backup:enviar     # subir ahora
sudo -u barberapp php bin/console backup:remotas    # listar lo que hay fuera
```

Cada subida envía el SHA-256 del archivo y el proveedor la rechaza si llega distinta; después se comprueba el tamaño guardado. Solo salen copias **cifradas**: el proveedor nunca ve datos personales y la clave (`BACKUP_KEY`) no se sube.

### Recuperar en un servidor nuevo (desastre)

1. Instala el servidor nuevo con `instalar-vps.sh` (mismos dominios). Copia en su `shared/.env` la **`BACKUP_KEY` guardada** y las variables `BACKUP_S3_*`.
2. Trae la última copia (verifica su SHA-256) y pruébala:
   ```bash
   cd /var/www/barberapp/current/backend
   sudo -u barberapp php bin/console backup:traer              # o backup:traer barberapp-AAAAMMDD-HHMMSS.sql.gz.enc
   sudo -u barberapp php bin/console backup:verificar barberapp-AAAAMMDD-HHMMSS.sql.gz.enc
   ```
3. Restaura con la API en mantenimiento:
   ```bash
   sudo -u barberapp php bin/console mantenimiento:activar
   sudo -u barberapp php bin/console backup:restaurar barberapp-….sql.gz.enc --base=barberapp --sobrescribir --limpiar
   sudo -u barberapp php bin/console migrate
   sudo -u barberapp php bin/console mantenimiento:desactivar
   ```
4. Recupera las fotos: `aws s3 sync s3://<bucket>/barberapp/produccion/media/ /var/www/barberapp/shared/media/` (o la herramienta del proveedor) y `chown -R barberapp:barberapp /var/www/barberapp/shared/media`.
5. Cambia el DNS a la IP nueva.

Practica este procedimiento en un servidor de prueba al menos una vez antes de abrir al público y cada vez que cambie el proveedor.

## 11. Registros (logs)

| Qué | Dónde | Rotación |
|---|---|---|
| Aplicación (JSON, un archivo por día, sin datos sensibles) | `/var/log/barberapp/app-AAAA-MM-DD.log` | `logs:purge` borra los de más de `LOG_RETENTION_DAYS` (30) |
| Cron | `/var/log/barberapp/cron.log` | logrotate semanal, 8 semanas |
| Monitoreo | `/var/log/barberapp/monitor.log` | ídem |
| Errores de PHP | `/var/log/barberapp/php-error.log` | ídem |
| Despliegues y rollbacks | `/var/log/barberapp/despliegue.log` y `/var/www/barberapp/despliegues.log` | ídem |
| Worker | `journalctl -u barberapp-worker` | journald |
| Nginx / Apache | `/var/log/nginx/barberapp-*.log` o `/var/log/apache2/barberapp-*.log` | logrotate del sistema |

Cada respuesta de la API lleva `X-Request-Id`; ese mismo id aparece en el registro de la aplicación y en la auditoría, para seguir una petición concreta. El panel de la plataforma muestra los errores de las últimas 24 h (Fase 9).

## 12. Monitoreo

```bash
sudo -u barberapp php bin/console monitor:revisar          # lista legible; código 1 si hay problemas
sudo -u barberapp php bin/console monitor:revisar --json   # para otras herramientas
```

Revisa: base de datos, migraciones pendientes, cola de avisos atrasada, el latido de cada tarea (worker cada 5 min, suscripciones cada 2 h, copias diarias, verificación semanal), copias fuera configuradas, antigüedad de la última copia, espacio libre en disco, errores de la última hora, mantenimiento olvidado y días que le quedan a cada certificado.

- **Alertas por correo**: el cron lo ejecuta cada 5 minutos con `--alertar`. Si aparece un problema envía un correo a `MONITOR_EMAIL`, lo repite cada 6 horas mientras siga y avisa cuando se resuelve. Necesita el SMTP configurado.
- **Monitor externo** (por si el servidor entero se cae, porque entonces no puede avisar él mismo): da de alta en UptimeRobot, Better Stack u otro:
  - `https://api.barberapp.pe/api/v1/health/live` cada minuto (PHP responde);
  - `https://api.barberapp.pe/api/v1/health` (base de datos y migraciones; 503 si fallan);
  - `https://api.barberapp.pe/api/v1/health/operacion` con la cabecera `Authorization: Bearer <MONITOR_TOKEN>`: el mismo resultado de `monitor:revisar` en JSON, 503 si hay algún problema. Sin token configurado, responde 404. No incluye datos de personas.
  - `https://app.barberapp.pe/` (frontend).

## 13. Actualizar a una versión nueva

```bash
sudo /var/www/barberapp/current/infrastructure/scripts/desplegar.sh              # la rama de despliegue.conf (main)
sudo /var/www/barberapp/current/infrastructure/scripts/desplegar.sh --ref=v1.2.0  # una etiqueta o commit
```

Qué hace (ver el propio script):

1. Baja el código del repositorio a una carpeta nueva en `releases/` (la versión actual sigue atendiendo).
2. Enlaza `.env` y `storage` compartidos; fija en el frontend la URL de la API y la versión del service worker (el navegador descarga los archivos nuevos en la siguiente visita).
3. **Revisión previa** (`despliegue:verificar`): PHP y extensiones, permisos, MySQL 8, CORS de los tres dominios y la configuración de seguridad. Si algo falla, la versión se descarta y no cambia nada.
4. Si la versión trae migraciones: **modo mantenimiento** (la API responde 503 con `Retry-After`, el frontend muestra "BARBERAPP se está actualizando"), **copia cifrada previa** y `migrate`. Siempre `db:seed`, que es idempotente (agrega permisos o plantillas nuevos y no toca los planes ni la configuración editados en el panel).
5. Cambia `current` con un `mv` atómico, recarga PHP-FPM, reinicia el worker y quita el mantenimiento. Sin migraciones no hay corte.
6. Comprueba `/api/v1/health`. **Si no responde "ok", vuelve sola a la versión anterior.**
7. Conserva las últimas 5 versiones.

### Migraciones compatibles

Para que un rollback del código nunca necesite tocar la base, cada migración debe dejar el esquema usable por la versión anterior:

- Agregar tablas, columnas (con valor por defecto o `NULL`) e índices: sí.
- Renombrar o borrar una columna o tabla: en dos versiones. Primero una versión que deja de usarla; en una versión posterior, la migración que la quita.
- Cambiar el tipo o el significado de una columna: columna nueva, copiar datos y retirar la vieja más adelante.
- Nunca editar una migración ya aplicada (el estado `aplicada (¡archivo modificado!)` lo delata).

### Mantenimiento manual

```bash
sudo -u barberapp php bin/console mantenimiento:activar --motivo="cambio de servidor" --reintentar=120
sudo -u barberapp php bin/console mantenimiento:desactivar
```

`/api/v1/health/live` sigue respondiendo durante el mantenimiento; `monitor:revisar` avisa si quedó activo más de 30 minutos.

## 14. Rollback

```bash
sudo /var/www/barberapp/current/infrastructure/scripts/rollback.sh --listar          # versiones disponibles
sudo /var/www/barberapp/current/infrastructure/scripts/rollback.sh                   # volver a la anterior (solo código)
sudo /var/www/barberapp/current/infrastructure/scripts/rollback.sh --a=20261020…     # a una versión concreta
sudo /var/www/barberapp/current/infrastructure/scripts/rollback.sh --restaurar-base  # código + base anterior
```

- **Solo código** (lo normal): cambia `current` a la versión anterior, recarga PHP y el worker. Con migraciones compatibles (sección 13), la versión anterior funciona con el esquema nuevo y **no se pierde ninguna reserva**.
- **Con `--restaurar-base`**: para cuando una migración salió mal. Activa el mantenimiento, hace una copia del estado actual (por si hay que rescatar algo), restaura la copia previa a las migraciones de la versión actual (anotada en `releases/<versión>/.despliegue`) borrando antes las tablas que no estaban, y vuelve al código anterior. **Se pierde lo registrado desde esa copia**; úsalo solo si el rollback de código no basta. `--copia=/var/backups/barberapp/…` elige otra copia.

Si `desplegar.sh` falla en una migración, la API queda en mantenimiento con la versión anterior y el mensaje indica el comando exacto de rollback.

## 15. Local con XAMPP

La instalación local sigue siendo la del [README](../README.md#instalación-local-con-xampp): no necesita nada de esta fase. Notas:

- `frontend/www/js/site-config.js` está vacío en el repositorio: en XAMPP el frontend calcula la API a partir de su propia carpeta (`backend/public` del mismo proyecto). Solo `desplegar.sh` lo completa en un servidor. No lo edites a mano para producción.
- Las tareas programadas en Windows se crean con el *Programador de tareas*, una por comando, ejecutando `C:\xampp\php\php.exe` con argumentos `bin\console notificaciones:procesar` e "Iniciar en" `C:\xampp\htdocs\barberapp\backend` (cada minuto para el worker; ver [RECORDATORIOS.md](RECORDATORIOS.md#puesta-en-marcha)).
- `php bin/console monitor:revisar` funciona también en local (avisará de tareas que nunca corrieron).
- Para probar la configuración de producción en tu equipo sin un VPS: Ubuntu en WSL2 o una máquina virtual con `instalar-vps.sh --certificado=propio` y los dominios en `/etc/hosts`.

## 16. Lista de salida a producción

| | Comprobación | Cómo |
|---|---|---|
| ☐ | Dominios propios y DNS hacia el servidor | `dig +short …` (sección 3) |
| ☐ | HTTPS válido en los cuatro dominios | navegador; `monitor:revisar` |
| ☐ | `seguridad:revisar` sin errores | sección 5 |
| ☐ | `monitor:revisar` sin errores | sección 12 |
| ☐ | Cuenta de superadministrador propia y contraseña cambiada | `plataforma:superadmin` |
| ☐ | SMTP enviando (recuperar contraseña de prueba) | `MAIL_*` |
| ☐ | Push de prueba en un teléfono | `push:probar correo` |
| ☐ | `BACKUP_KEY` guardada fuera del servidor | gestor de contraseñas |
| ☐ | Copias fuera del servidor y restauración probada en otro servidor | sección 10 |
| ☐ | Alertas: correo de prueba recibido (detener el worker 6 minutos) | `MONITOR_EMAIL` |
| ☐ | Monitor externo de `/health/live` | sección 12 |
| ☐ | Datos legales del responsable e inscripción ante la ANPD | [SEGURIDAD.md](SEGURIDAD.md) |
| ☐ | Facturación electrónica e IGV resueltos antes de cobrar en línea | [MONETIZACION.md](MONETIZACION.md) |
| ☐ | Un despliegue y un rollback de práctica | secciones 13 y 14 |

## 17. Qué se probó y qué no

Probado en esta fase, en un servidor Ubuntu 24.04 de prueba (sin systemd, con MySQL 8.0.46, PHP 8.3 y dominios `*.barberapp.test` en `/etc/hosts`):

- `instalar-vps.sh` completo sobre una base vacía, con **Nginx** y con **Apache**, con certificado autofirmado: 16 migraciones, catálogo, configuración generada válida (`nginx -t`, `apache2ctl configtest`, `php-fpm -t`), primera copia y su restauración, `/health` "ok". Volver a ejecutarlo conserva `.env` y datos.
- En el navegador (Chromium): el frontend en `app.` usa la API de `api.` con la CSP limitada; el inicio de sesión en `platform.` deja la cookie `Secure`/`HttpOnly` de `api.` y `/me` responde 200 entre dominios; `/` de `admin.` y `platform.` abre su panel; `http` redirige a `https`; `.env`, `.htaccess`, `.git` y PHP dentro de `/media` devuelven 403; las fotos se sirven desde `shared/media`.
- `desplegar.sh` con una migración nueva (mantenimiento, copia previa, migración, salud) y sin migraciones (sin corte); una versión cuya salud falla vuelve sola a la anterior; `rollback.sh --restaurar-base` deja la base exactamente como antes (la tabla nueva desaparece) y luego `/health` "ok".
- El worker permanente se detiene limpio con SIGTERM; el cron y los latidos; `monitor:revisar` con certificados.
- Copias fuera del servidor contra **moto** (simulador de S3) por HTTP, con curl y con el cliente de PHP, y la firma contra los ejemplos oficiales de AWS.
- La integración continua agrega un trabajo que instala BARBERAPP en un servidor limpio de GitHub Actions (con systemd) en cada PR.

No probado aquí (hay que hacerlo en el servidor real): Let's Encrypt con dominios públicos, el servicio systemd del worker fuera de CI, un proveedor S3 real (R2, B2, S3) con una clave sin permiso de borrado, el correo de alertas por SMTP real y la recuperación completa en un segundo servidor.
