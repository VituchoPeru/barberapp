#!/usr/bin/env bash
# BARBERAPP — instalación en un VPS Ubuntu 24.04 / Debian 12 (Fase 15). Ver docs/DESPLIEGUE.md.
#
#   sudo bash instalar-vps.sh --config=/root/barberapp-vps.conf [opciones]
#
# Deja funcionando, en un servidor limpio: Nginx (o Apache) con HTTPS para los cuatro dominios,
# PHP-FPM con un pool propio, MySQL 8 con un usuario propio, la primera versión publicada
# (migraciones y catálogo), el worker de avisos, el cron, la rotación de registros, la primera
# copia cifrada verificada y el monitoreo. Se puede volver a ejecutar: no reemplaza claves,
# contraseñas ni datos que ya existan.
#
# Opciones:
#   --config=<archivo>          datos de la instalación (ver vps.conf.example)          [obligatorio]
#   --origen=<url|carpeta>      código a instalar (por defecto ORIGEN del archivo)
#   --certificado=letsencrypt   (por defecto) pide el certificado a Let's Encrypt; el DNS debe apuntar aquí
#   --certificado=propio        certificado autofirmado de 30 días (solo pruebas o staging sin DNS)
#   --certificado=existente     ya hay un certificado en /etc/letsencrypt/live/barberapp/
#   --sin-paquetes              no instala paquetes (ya están)
#   --activar-firewall          activa ufw con SSH (22), HTTP y HTTPS
set -Eeuo pipefail

CONF=""
ORIGEN_ARG=""
CERT_MODO="letsencrypt"
SIN_PAQUETES=0
FIREWALL=0
for arg in "$@"; do
  case "$arg" in
    --config=*) CONF="${arg#*=}" ;;
    --origen=*) ORIGEN_ARG="${arg#*=}" ;;
    --certificado=*) CERT_MODO="${arg#*=}" ;;
    --sin-paquetes) SIN_PAQUETES=1 ;;
    --activar-firewall) FIREWALL=1 ;;
    -h|--help) sed -n '2,22p' "$0"; exit 0 ;;
    *) echo "Opción desconocida: $arg" >&2; exit 2 ;;
  esac
done

paso() { printf '\n\033[1;33m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
die() { printf '\n\033[1;31m✖ %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || die "Ejecuta como root (sudo)."
[ -n "$CONF" ] && [ -r "$CONF" ] || die "Indica el archivo de datos: --config=/ruta (copia infrastructure/scripts/vps.conf.example)."
# shellcheck source=/dev/null
. "$CONF"
ORIGEN="${ORIGEN_ARG:-${ORIGEN:-}}"
REF="${REF:-main}"
SERVIDOR_WEB="${SERVIDOR_WEB:-nginx}"
PHP_VERSION="${PHP_VERSION:-8.3}"
RAIZ="${RAIZ:-/var/www/barberapp}"
USUARIO="${USUARIO:-barberapp}"
IPS_PLATAFORMA="${IPS_PLATAFORMA:-}"
CORREO_ADMIN="${CORREO_ADMIN:-}"
case "$CERT_MODO" in letsencrypt|propio|existente) ;; *) die "--certificado debe ser letsencrypt, propio o existente." ;; esac
case "$SERVIDOR_WEB" in nginx|apache) ;; *) die "SERVIDOR_WEB debe ser nginx o apache." ;; esac
[ -n "$ORIGEN" ] || die "Falta ORIGEN (URL del repositorio o carpeta con el código)."
for d in "$DOMINIO_APP" "$DOMINIO_ADMIN" "$DOMINIO_PLATAFORMA" "$DOMINIO_API"; do
  [[ "$d" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || die "Dominio inválido: '$d'."
done
[ "$CERT_MODO" != "letsencrypt" ] || [ -n "$CORREO_ADMIN" ] || die "Let's Encrypt necesita CORREO_ADMIN."

PHP_BIN="/usr/bin/php$PHP_VERSION"
FPM_SERVICE="php$PHP_VERSION-fpm"
WEB_SERVICE="nginx"; [ "$SERVIDOR_WEB" = "apache" ] && WEB_SERVICE="apache2"
SOCKET="/run/php/barberapp.sock"
COMPARTIDO="$RAIZ/shared"
LOG_DIR="/var/log/barberapp"
BACKUP_DIR="/var/backups/barberapp"
ACME_DIR="/var/www/letsencrypt"
CERT_DIR="/etc/letsencrypt/live/barberapp"
DESPLIEGUE_CONF="/etc/barberapp/despliegue.conf"
SCRIPTS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HAY_SYSTEMD=0; [ -d /run/systemd/system ] && HAY_SYSTEMD=1
svc() { # svc <acción> <servicio>
  if [ "$HAY_SYSTEMD" = 1 ]; then systemctl "$1" "$2"; else service "$2" "$1"; fi
}

# ------------------------------------------------------------------ 1. paquetes
if [ "$SIN_PAQUETES" = 0 ]; then
  paso "Instalando paquetes"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  WEB_PKGS="nginx"; [ "$SERVIDOR_WEB" = "apache" ] && WEB_PKGS="apache2"
  # shellcheck disable=SC2086
  apt-get install -y -qq $WEB_PKGS "php$PHP_VERSION-fpm" "php$PHP_VERSION-cli" "php$PHP_VERSION-mysql" "php$PHP_VERSION-mbstring" \
    "php$PHP_VERSION-gd" "php$PHP_VERSION-curl" "php$PHP_VERSION-xml" "php$PHP_VERSION-zip" "php$PHP_VERSION-intl" \
    mysql-server git curl openssl cron logrotate certbot ufw >/dev/null
fi
command -v "$PHP_BIN" >/dev/null || die "No se encontró $PHP_BIN (¿PHP_VERSION=$PHP_VERSION es la del sistema?)."
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);' || die "Se requiere PHP 8.0 o superior."
info "$("$PHP_BIN" -v | head -n 1)"

# ------------------------------------------------------------------ 2. sistema
paso "Usuario, zona horaria y carpetas"
if [ "$(date +%Z)" != "UTC" ]; then
  if [ "$HAY_SYSTEMD" = 1 ]; then timedatectl set-timezone UTC; else ln -sf /usr/share/zoneinfo/UTC /etc/localtime; fi
  info "Zona horaria del servidor: UTC (las fechas de BARBERAPP están en UTC; cada sucursal tiene la suya)."
fi
id "$USUARIO" >/dev/null 2>&1 || useradd --system --home-dir "$RAIZ" --no-create-home --shell /usr/sbin/nologin "$USUARIO"
install -d -m 755 -o root -g root "$RAIZ" "$RAIZ/releases" "$COMPARTIDO" "$COMPARTIDO/well-known" "$ACME_DIR"
install -d -m 750 -o "$USUARIO" -g "$USUARIO" "$COMPARTIDO/storage" "$COMPARTIDO/storage/logs" "$COMPARTIDO/storage/cache" \
  "$COMPARTIDO/storage/mail" "$COMPARTIDO/storage/push" "$COMPARTIDO/storage/backups" "$LOG_DIR"
install -d -m 755 -o "$USUARIO" -g "$USUARIO" "$COMPARTIDO/media"
install -d -m 700 -o "$USUARIO" -g "$USUARIO" "$BACKUP_DIR"
install -d -m 750 -o root -g "$USUARIO" /etc/barberapp

# ------------------------------------------------------------------ 3. MySQL
paso "MySQL"
svc start mysql >/dev/null 2>&1 || true
mysql -e 'SELECT 1' >/dev/null || die "No se pudo entrar a MySQL como root (por socket)."
VERSION_MYSQL="$(mysql -N -e 'SELECT VERSION()')"
info "MySQL $VERSION_MYSQL"
[[ "$VERSION_MYSQL" != *MariaDB* ]] || die "El servidor es MariaDB: BARBERAPP necesita MySQL 8."

ENV="$COMPARTIDO/.env"
set_env() { # set_env CLAVE valor  (reemplaza la línea CLAVE=… del .env)
  local k="$1" v="$2" tmp
  tmp="$(mktemp)"
  awk -v k="$k" -v v="$v" 'BEGIN{d=0} $0 ~ "^"k"=" {print k"="v; d=1; next} {print} END{if(!d) print k"="v}' "$ENV" > "$tmp"
  cat "$tmp" > "$ENV"; rm -f "$tmp"
}
get_env() { sed -n "s/^$1=//p" "$ENV" | tail -n 1; }
aleatorio() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c "${1:-32}"; }

if [ ! -f "$ENV" ]; then
  paso "Creando $ENV"
  EJEMPLO="$(mktemp)"
  if [ -d "$ORIGEN" ]; then
    cp "$ORIGEN/backend/.env.production.example" "$EJEMPLO"
  else
    git archive --remote="$ORIGEN" "$REF" backend/.env.production.example 2>/dev/null | tar -xO > "$EJEMPLO" \
      || { tmpd="$(mktemp -d)"; git clone --quiet --depth 1 --branch "$REF" "$ORIGEN" "$tmpd"; cp "$tmpd/backend/.env.production.example" "$EJEMPLO"; rm -rf "$tmpd"; }
  fi
  install -m 640 -o root -g "$USUARIO" "$EJEMPLO" "$ENV"; rm -f "$EJEMPLO"
  DB_PASS="$(aleatorio 32)"
  set_env APP_URL "https://$DOMINIO_API"
  set_env FRONTEND_URL "https://$DOMINIO_APP"
  set_env ADMIN_URL "https://$DOMINIO_ADMIN"
  set_env PLATFORM_URL "https://$DOMINIO_PLATAFORMA"
  set_env PUBLIC_BOOKING_URL "https://$DOMINIO_APP"
  set_env CORS_ALLOWED_ORIGINS "https://$DOMINIO_APP,https://$DOMINIO_ADMIN,https://$DOMINIO_PLATAFORMA,capacitor://localhost,https://localhost"
  set_env MEDIA_PATH "$COMPARTIDO/media"
  set_env MEDIA_URL "https://$DOMINIO_API/media"
  set_env LOG_PATH "$LOG_DIR"
  set_env BACKUP_PATH "$BACKUP_DIR"
  set_env DB_PASSWORD "$DB_PASS"
  set_env APP_KEY "base64:$(openssl rand -base64 32)"
  set_env BACKUP_KEY "base64:$(openssl rand -base64 32)"
  set_env MONITOR_TOKEN "$(aleatorio 40)"
  [ -n "$CORREO_ADMIN" ] && set_env MONITOR_EMAIL "$CORREO_ADMIN"
  NUEVA_CLAVE_COPIAS=1
else
  info "$ENV ya existe: se conserva (claves y contraseñas intactas)."
  NUEVA_CLAVE_COPIAS=0
fi
DB_NAME="$(get_env DB_DATABASE)"; DB_USER="$(get_env DB_USERNAME)"; DB_PASS="$(get_env DB_PASSWORD)"
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] && [[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die "DB_DATABASE o DB_USERNAME inválidos en $ENV."
DB_PASS_SQL="${DB_PASS//\'/\'\'}"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS_SQL';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS_SQL';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}_restore_check\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
info "Base $DB_NAME y usuario $DB_USER listos (la prueba de restauración usa ${DB_NAME}_restore_check)."

# ------------------------------------------------------------------ 4. configuración del despliegue
if [ "$HAY_SYSTEMD" = 1 ]; then
  RECARGAR_PHP="systemctl reload $FPM_SERVICE"; WORKER=systemd
  # El servicio se instala después de la primera publicación (paso 8): hasta entonces no hay nada que reiniciar.
  REINICIAR_WORKER="if systemctl cat barberapp-worker >/dev/null 2>&1; then systemctl restart barberapp-worker; fi"
else
  RECARGAR_PHP="service $FPM_SERVICE reload"; REINICIAR_WORKER=""; WORKER=cron
fi
CURL_SALUD="--noproxy $DOMINIO_API --resolve $DOMINIO_API:443:127.0.0.1"
[ "$CERT_MODO" = "propio" ] && CURL_SALUD="$CURL_SALUD --insecure"
cat > "$DESPLIEGUE_CONF" <<CFG
# BARBERAPP — configuración de desplegar.sh y rollback.sh (generada por instalar-vps.sh).
RAIZ=$RAIZ
USUARIO=$USUARIO
PHP_BIN=$PHP_BIN
ORIGEN=$ORIGEN
REF=$REF
CONSERVAR=5
LOG_DIR=$LOG_DIR
RECARGAR_PHP="$RECARGAR_PHP"
REINICIAR_WORKER="$REINICIAR_WORKER"
URL_SALUD=https://$DOMINIO_API/api/v1/health
CURL_SALUD="$CURL_SALUD"
CFG
chmod 640 "$DESPLIEGUE_CONF"; chown root:"$USUARIO" "$DESPLIEGUE_CONF"

# ------------------------------------------------------------------ 5. primera versión
paso "Publicando la primera versión"
svc start "$FPM_SERVICE" >/dev/null 2>&1 || true
if [ -L "$RAIZ/current" ]; then
  info "Ya hay una versión publicada ($(basename "$(readlink -f "$RAIZ/current")")): se publica $REF encima."
fi
# Sin comprobación de salud: el servidor web todavía no está configurado.
BARBERAPP_SIN_SALUD=1 BARBERAPP_DESPLIEGUE_CONF="$DESPLIEGUE_CONF" bash "$SCRIPTS/desplegar.sh" --origen="$ORIGEN" --ref="$REF" \
  || die "Falló la primera publicación (detalle en $LOG_DIR/despliegue.log)."
ACTUAL="$RAIZ/current"
CONSOLE() { (cd "$ACTUAL/backend" && runuser -u "$USUARIO" -- "$PHP_BIN" bin/console "$@"); }

# ------------------------------------------------------------------ 6. configuración del servidor
paso "Generando configuración ($SERVIDOR_WEB, PHP-FPM, cron, worker, logrotate)"
GEN="$(mktemp -d)"
(cd "$ACTUAL/backend" && "$PHP_BIN" bin/console despliegue:config --salida="$GEN" --servidor="$SERVIDOR_WEB" --raiz="$RAIZ" \
  --usuario="$USUARIO" --php="$PHP_VERSION" --socket="$SOCKET" --worker="$WORKER" --ips-plataforma="$IPS_PLATAFORMA") | sed 's/^/    /'

install -m 644 "$GEN/php/barberapp-fpm.conf" "/etc/php/$PHP_VERSION/fpm/pool.d/barberapp.conf"
install -m 644 "$GEN/php/99-barberapp.ini" "/etc/php/$PHP_VERSION/fpm/conf.d/99-barberapp.ini"
install -m 644 "$GEN/php/99-barberapp.ini" "/etc/php/$PHP_VERSION/cli/conf.d/99-barberapp.ini"
"/usr/sbin/php-fpm$PHP_VERSION" -t >/dev/null 2>&1 || die "La configuración de PHP-FPM no es válida (php-fpm$PHP_VERSION -t)."
svc restart "$FPM_SERVICE"

instalar_sitios() { # instalar_sitios <archivo>...  (copia y habilita sitios del servidor web)
  for f in "$@"; do
    if [ "$SERVIDOR_WEB" = "nginx" ]; then
      install -m 644 "$f" "/etc/nginx/sites-available/$(basename "$f")"
      ln -sfn "/etc/nginx/sites-available/$(basename "$f")" "/etc/nginx/sites-enabled/$(basename "$f")"
    else
      install -m 644 "$f" "/etc/apache2/sites-available/$(basename "$f")"
      a2ensite -q "$(basename "$f" .conf)" >/dev/null
    fi
  done
}
recargar_web() { # arranca el servidor web si estaba detenido y recarga la configuración
  svc start "$WEB_SERVICE" >/dev/null 2>&1 || true
  svc reload "$WEB_SERVICE" >/dev/null 2>&1 || svc restart "$WEB_SERVICE"
}
probar_web() {
  if [ "$SERVIDOR_WEB" = "nginx" ]; then nginx -t 2>&1 | sed 's/^/    /'; else apache2ctl configtest 2>&1 | sed 's/^/    /'; fi
}
if [ "$SERVIDOR_WEB" = "nginx" ]; then
  rm -f /etc/nginx/sites-enabled/default
  install -m 644 "$GEN"/nginx/snippets/*.conf /etc/nginx/snippets/
else
  a2dismod -q php"$PHP_VERSION" mpm_prefork >/dev/null 2>&1 || true
  a2enmod -q mpm_event ssl http2 rewrite headers proxy proxy_fcgi setenvif expires alias >/dev/null
  a2dissite -q 000-default >/dev/null 2>&1 || true
fi

# ------------------------------------------------------------------ 7. certificado
paso "Certificado HTTPS ($CERT_MODO)"
DOMINIOS=("$DOMINIO_API" "$DOMINIO_APP" "$DOMINIO_ADMIN" "$DOMINIO_PLATAFORMA")
if [ "$CERT_MODO" = "letsencrypt" ] && [ ! -f "$CERT_DIR/fullchain.pem" ]; then
  for d in "${DOMINIOS[@]}"; do
    getent ahosts "$d" >/dev/null || die "$d no resuelve todavía. Crea los registros DNS (A/AAAA) hacia este servidor y vuelve a ejecutar."
  done
  instalar_sitios "$GEN/$SERVIDOR_WEB/barberapp-acme.conf"
  probar_web; recargar_web
  ARGS=(); for d in "${DOMINIOS[@]}"; do ARGS+=(-d "$d"); done
  certbot certonly --webroot -w "$ACME_DIR" --cert-name barberapp "${ARGS[@]}" --email "$CORREO_ADMIN" --agree-tos --non-interactive --no-eff-email
  if [ "$SERVIDOR_WEB" = "nginx" ]; then rm -f /etc/nginx/sites-enabled/barberapp-acme.conf; else a2dissite -q barberapp-acme >/dev/null; fi
elif [ "$CERT_MODO" = "propio" ] && [ ! -f "$CERT_DIR/fullchain.pem" ]; then
  install -d -m 755 "$CERT_DIR"
  SAN="$(printf 'DNS:%s,' "${DOMINIOS[@]}")"
  openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=$DOMINIO_API" -addext "subjectAltName=${SAN%,}" \
    -keyout "$CERT_DIR/privkey.pem" -out "$CERT_DIR/fullchain.pem" 2>/dev/null
  chmod 600 "$CERT_DIR/privkey.pem"
  info "Certificado autofirmado (30 días): los navegadores lo marcarán como no seguro. Solo para pruebas."
fi
[ -f "$CERT_DIR/fullchain.pem" ] && [ -f "$CERT_DIR/privkey.pem" ] || die "No hay certificado en $CERT_DIR."
# Renovación: el temporizador de certbot renueva; este gancho recarga el servidor web.
install -d -m 755 /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\n# BARBERAPP: recarga el servidor web tras renovar el certificado.\n%s\n' \
  "$( [ "$HAY_SYSTEMD" = 1 ] && echo "systemctl reload $WEB_SERVICE" || echo "service $WEB_SERVICE reload")" > /etc/letsencrypt/renewal-hooks/deploy/barberapp-recargar.sh
chmod 755 /etc/letsencrypt/renewal-hooks/deploy/barberapp-recargar.sh

paso "Servidor web"
SITIOS=("$GEN/$SERVIDOR_WEB/barberapp-api.conf" "$GEN/$SERVIDOR_WEB/barberapp-app.conf")
[ -f "$GEN/$SERVIDOR_WEB/barberapp-admin.conf" ] && SITIOS+=("$GEN/$SERVIDOR_WEB/barberapp-admin.conf")
[ -f "$GEN/$SERVIDOR_WEB/barberapp-plataforma.conf" ] && SITIOS+=("$GEN/$SERVIDOR_WEB/barberapp-plataforma.conf")
instalar_sitios "${SITIOS[@]}"
probar_web
if [ "$SERVIDOR_WEB" = "nginx" ]; then nginx -t >/dev/null 2>&1 || die "nginx -t falló."; else apache2ctl configtest >/dev/null 2>&1 || die "apache2ctl configtest falló."; fi
recargar_web

# ------------------------------------------------------------------ 8. cron, worker, logrotate
paso "Tareas programadas, worker y registros"
install -m 644 "$GEN/cron/barberapp" /etc/cron.d/barberapp
install -m 644 "$GEN/logrotate/barberapp" /etc/logrotate.d/barberapp
if [ "$HAY_SYSTEMD" = 1 ]; then
  install -m 644 "$GEN/systemd/barberapp-worker.service" /etc/systemd/system/barberapp-worker.service
  systemctl daemon-reload
  systemctl enable --now barberapp-worker >/dev/null 2>&1
  systemctl restart barberapp-worker
  info "Worker: systemctl status barberapp-worker"
else
  info "Sin systemd: el worker corre por cron cada minuto."
fi
svc start cron >/dev/null 2>&1 || true

if command -v ufw >/dev/null; then
  ufw allow OpenSSH >/dev/null 2>&1 || ufw allow 22/tcp >/dev/null
  ufw allow 80/tcp >/dev/null; ufw allow 443/tcp >/dev/null
  if [ "$FIREWALL" = 1 ]; then ufw --force enable >/dev/null && info "Firewall activo: 22, 80 y 443."; else info "Reglas de ufw listas (22, 80, 443). Actívalo con: ufw enable"; fi
fi
rm -rf "$GEN"

# ------------------------------------------------------------------ 9. primera copia y revisión
paso "Primera copia cifrada y prueba de restauración"
CONSOLE backup:crear | sed 's/^/    /'
CONSOLE backup:verificar | sed 's/^/    /'
CONSOLE suscripciones:vencer >/dev/null
CONSOLE privacidad:retencion >/dev/null
CONSOLE auth:purge >/dev/null
CONSOLE notificaciones:procesar >/dev/null

paso "Comprobación"
# shellcheck disable=SC2086
if curl -fsS --max-time 10 $CURL_SALUD "https://$DOMINIO_API/api/v1/health" | grep -q '"status":"ok"'; then
  info "✔ https://$DOMINIO_API/api/v1/health responde ok"
else
  die "La API no responde en https://$DOMINIO_API/api/v1/health (revisa /var/log/$WEB_SERVICE y $LOG_DIR)."
fi
# shellcheck disable=SC2086
curl -fsS --max-time 10 ${CURL_SALUD//$DOMINIO_API/$DOMINIO_APP} "https://$DOMINIO_APP/" -o /dev/null && info "✔ https://$DOMINIO_APP/ responde"
CONSOLE seguridad:revisar | sed 's/^/    /' || true

paso "Listo"
cat <<FIN
    BARBERAPP quedó publicado en:
      https://$DOMINIO_APP        clientes y marketplace
      https://$DOMINIO_ADMIN      panel de barberías
      https://$DOMINIO_PLATAFORMA panel de la plataforma
      https://$DOMINIO_API        API

    Siguientes pasos (docs/DESPLIEGUE.md, "Después de instalar"):
      1. Crea tu cuenta de superadministrador:
           cd $ACTUAL/backend && sudo -u $USUARIO $PHP_BIN bin/console plataforma:superadmin tu@correo
      2. Completa en $ENV: correo SMTP (MAIL_*), Firebase (FCM_*), copias fuera del servidor
         (BACKUP_S3_*) y, cuando corresponda, Mercado Pago. Luego: sudo $ACTUAL/infrastructure/scripts/desplegar.sh
      3. Carga los datos legales del responsable en Plataforma → Configuración.
      4. Revisa: cd $ACTUAL/backend && sudo -u $USUARIO $PHP_BIN bin/console monitor:revisar
FIN
if [ "$NUEVA_CLAVE_COPIAS" = 1 ]; then
  cat <<FIN

    ⚠ GUARDA AHORA, FUERA DEL SERVIDOR (gestor de contraseñas), la clave de las copias:
      $(get_env BACKUP_KEY)
    Sin ella ninguna copia se puede restaurar, ni siquiera las guardadas fuera del servidor.
FIN
fi
