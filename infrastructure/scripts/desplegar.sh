#!/usr/bin/env bash
# BARBERAPP — publicar una versión nueva en el servidor (Fase 15). Ver docs/DESPLIEGUE.md.
#
#   sudo /var/www/barberapp/current/infrastructure/scripts/desplegar.sh [--ref=main] [opciones]
#
# Cada versión vive en su propia carpeta (releases/AAAAMMDDHHMMSS-commit) y "current" es un
# enlace a la que atiende. La versión nueva se arma y se revisa al lado de la que funciona; el
# cambio es un solo "mv" atómico y, si la API no responde bien después, se vuelve sola a la anterior.
#
#  1. Obtiene el código (espejo git en repo.git, o una carpeta con --origen=/ruta).
#  2. Enlaza lo compartido entre versiones: shared/.env y shared/storage.
#  3. Fija la URL de la API y la versión del service worker del frontend.
#  4. despliegue:verificar (PHP, extensiones, permisos, base, CORS, seguridad). Si falla, se descarta.
#  5. Si hay migraciones: mantenimiento, copia cifrada previa y migrate. db:seed (idempotente).
#  6. Cambia "current", recarga PHP-FPM, reinicia el worker y quita el mantenimiento.
#  7. Comprueba /api/v1/health; si falla, vuelve a la versión anterior.
#  8. Conserva las últimas CONSERVAR versiones.
#
# Opciones:
#   --ref=<rama|tag|commit>   qué publicar (por defecto REF del archivo de configuración)
#   --origen=<url|carpeta>    de dónde (por defecto ORIGEN)
#   --config=<archivo>        configuración (por defecto /etc/barberapp/despliegue.conf)
#   --sin-copia               no hacer la copia previa a las migraciones (NO recomendado)
set -Eeuo pipefail

CONF="${BARBERAPP_DESPLIEGUE_CONF:-/etc/barberapp/despliegue.conf}"
REF_ARG=""
ORIGEN_ARG=""
SIN_COPIA=0
for arg in "$@"; do
  case "$arg" in
    --ref=*) REF_ARG="${arg#*=}" ;;
    --origen=*) ORIGEN_ARG="${arg#*=}" ;;
    --config=*) CONF="${arg#*=}" ;;
    --sin-copia) SIN_COPIA=1 ;;
    -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
    *) echo "Opción desconocida: $arg" >&2; exit 2 ;;
  esac
done

[ -r "$CONF" ] || { echo "No se puede leer $CONF (lo crea instalar-vps.sh; ver docs/DESPLIEGUE.md)." >&2; exit 2; }
# shellcheck source=/dev/null
. "$CONF"
: "${RAIZ:?Falta RAIZ en $CONF}" "${USUARIO:?Falta USUARIO}" "${PHP_BIN:?Falta PHP_BIN}"
ORIGEN="${ORIGEN_ARG:-${ORIGEN:-}}"
REF="${REF_ARG:-${REF:-main}}"
CONSERVAR="${CONSERVAR:-5}"
RECARGAR_PHP="${RECARGAR_PHP:-}"
REINICIAR_WORKER="${REINICIAR_WORKER:-}"
URL_SALUD="${URL_SALUD:-}"
[ "${BARBERAPP_SIN_SALUD:-0}" = 1 ] && URL_SALUD=""
CURL_SALUD="${CURL_SALUD:-}"
LOG_DIR="${LOG_DIR:-/var/log/barberapp}"
[ -n "$ORIGEN" ] || { echo "Indica ORIGEN en $CONF o --origen=." >&2; exit 2; }

mkdir -p "$RAIZ/releases" "$LOG_DIR"
LOG="$LOG_DIR/despliegue.log"
log() { printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" | tee -a "$LOG"; }
die() { log "✖ $*"; exit 1; }

# Los comandos de PHP corren con el usuario de la aplicación; el código queda de root (solo lectura para PHP).
as_app() {
  if [ "$(id -u)" = "0" ] && [ "$USUARIO" != "root" ]; then runuser -u "$USUARIO" -- "$@"; else "$@"; fi
}
console() { local rel="$1"; shift; (cd "$rel/backend" && as_app "$PHP_BIN" bin/console "$@"); }
run_hook() { [ -z "$1" ] && return 0; log "  $1"; bash -c "$1"; }

# Un solo despliegue a la vez.
exec 9>"$RAIZ/.despliegue.lock"
flock -n 9 || die "Ya hay un despliegue o un rollback en curso."

[ -f "$RAIZ/shared/.env" ] || die "Falta $RAIZ/shared/.env (instalar-vps.sh lo crea desde backend/.env.production.example)."
PREVIA="$(readlink -f "$RAIZ/current" 2>/dev/null || true)"
STAMP="$(date -u +%Y%m%d%H%M%S)"

# ------------------------------------------------------------------ 1. código
log "Desplegando $REF desde $ORIGEN"
if [ -d "$ORIGEN" ] && [ ! -d "$ORIGEN/.git" ] && [ ! -f "$ORIGEN/HEAD" ]; then
  SHA="carpeta"
  ID="$STAMP-local"
  REL="$RAIZ/releases/$ID"
  mkdir -p "$REL"
  tar -C "$ORIGEN" --exclude=.git --exclude=node_modules --exclude=vendor --exclude=backend/.env \
      --exclude='backend/storage/*' --exclude='backend/public/media/tenants' -cf - . | tar -C "$REL" -xf -
else
  REPO="$RAIZ/repo.git"
  if [ ! -d "$REPO" ]; then
    git clone --quiet --mirror "$ORIGEN" "$REPO"
  else
    git -C "$REPO" remote set-url origin "$ORIGEN"
    git -C "$REPO" remote update --prune >/dev/null
  fi
  SHA="$(git -C "$REPO" rev-parse --verify --quiet "$REF^{commit}")" || die "No existe $REF en $ORIGEN."
  ID="$STAMP-${SHA:0:7}"
  REL="$RAIZ/releases/$ID"
  mkdir -p "$REL"
  git -C "$REPO" archive "$SHA" | tar -C "$REL" -xf -
fi
# Si algo falla antes de publicarla, la versión a medio armar se borra (salvo que convenga revisarla).
descartar() {
  local rc=$?
  if [ "$rc" -ne 0 ] && [ -n "${REL:-}" ] && [ "$(readlink -f "$RAIZ/current" 2>/dev/null)" != "$REL" ] && [ "${CONSERVAR_FALLIDA:-0}" != 1 ]; then
    rm -rf "$REL"; log "Versión $ID descartada."
  fi
}
trap descartar EXIT
log "Versión $ID (commit $SHA)"

# ------------------------------------------------------------------ 2. compartido
rm -rf "$REL/backend/storage" "$REL/backend/.env"
ln -s "$RAIZ/shared/storage" "$REL/backend/storage"
ln -s "$RAIZ/shared/.env" "$REL/backend/.env"
printf '%s\n' "$SHA" > "$REL/REVISION"
# Código de root y solo lectura para la aplicación; carpetas legibles por el servidor web.
chown -R root:root "$REL" 2>/dev/null || true
chmod -R u=rwX,go=rX "$REL"

# ------------------------------------------------------------------ 3. frontend
(cd "$REL/backend" && "$PHP_BIN" bin/console despliegue:frontend --version="$ID") | tee -a "$LOG"

# ------------------------------------------------------------------ 4. revisión previa
log "Revisión previa"
if ! console "$REL" despliegue:verificar --sin-dns 2>&1 | tee -a "$LOG"; then
  die "La revisión previa encontró errores: la versión actual sigue atendiendo."
fi

# ------------------------------------------------------------------ 5. base de datos
PENDIENTES="$(console "$REL" migrate:status | grep -c 'pendiente' || true)"
MANT=0
COPIA=""
if [ "$PENDIENTES" -gt 0 ]; then
  log "$PENDIENTES migración(es) pendiente(s): mantenimiento activado"
  console "$REL" mantenimiento:activar --motivo="actualizacion $ID" --reintentar=60 | tee -a "$LOG"
  MANT=1
  if [ "$SIN_COPIA" = 0 ]; then
    SALIDA="$(console "$REL" backup:crear)" || die "No se pudo hacer la copia previa. La API sigue en mantenimiento con la versión anterior: revisa y ejecuta php bin/console mantenimiento:desactivar."
    printf '%s\n' "$SALIDA" | tee -a "$LOG"
    COPIA="$(printf '%s\n' "$SALIDA" | sed -n 's/^Copia creada: \([^ ]*\) .*/\1/p')"
  fi
  if ! console "$REL" migrate 2>&1 | tee -a "$LOG"; then
    CONSERVAR_FALLIDA=1
    die "Falló una migración. La API queda EN MANTENIMIENTO con la versión anterior. Para volver al estado previo:
   sudo $(dirname "$(readlink -f "$0")")/rollback.sh --restaurar-base --copia=${COPIA:-<copia>}   (o corrige y vuelve a desplegar)"
  fi
fi
console "$REL" db:seed | tee -a "$LOG"
{
  echo "ID=$ID"; echo "SHA=$SHA"; echo "FECHA=$(date -u +%Y-%m-%dT%H:%M:%SZ)"; echo "PREVIA=$(basename "${PREVIA:-ninguna}")"
  echo "MIGRACIONES=$PENDIENTES"; echo "COPIA_PREVIA=$COPIA"
} > "$REL/.despliegue"

# ------------------------------------------------------------------ 6. publicar
ln -sfn "$REL" "$RAIZ/current.nuevo"
mv -Tf "$RAIZ/current.nuevo" "$RAIZ/current"
log "current → $ID"
run_hook "$RECARGAR_PHP"
run_hook "$REINICIAR_WORKER"
[ "$MANT" = 1 ] && console "$REL" mantenimiento:desactivar | tee -a "$LOG"

# ------------------------------------------------------------------ 7. salud
if [ -n "$URL_SALUD" ]; then
  OK=0
  for _ in 1 2 3 4 5 6; do
    # shellcheck disable=SC2086
    if curl -fsS --max-time 10 $CURL_SALUD "$URL_SALUD" 2>/dev/null | grep -q '"status":"ok"'; then OK=1; break; fi
    sleep 2
  done
  if [ "$OK" != 1 ]; then
    if [ -n "$PREVIA" ] && [ -d "$PREVIA" ]; then
      ln -sfn "$PREVIA" "$RAIZ/current.nuevo" && mv -Tf "$RAIZ/current.nuevo" "$RAIZ/current"
      run_hook "$RECARGAR_PHP"; run_hook "$REINICIAR_WORKER"
      CONSERVAR_FALLIDA=1
      die "$URL_SALUD no respondió bien: se volvió a $(basename "$PREVIA").$( [ "$PENDIENTES" -gt 0 ] && echo " Las migraciones de $ID ya se aplicaron; si la versión anterior falla con ellas: rollback.sh --restaurar-base --copia=$COPIA")"
    fi
    die "$URL_SALUD no respondió bien y no hay versión anterior a la que volver."
  fi
  log "Salud OK ($URL_SALUD)"
fi

# ------------------------------------------------------------------ 8. limpieza
ACTUAL_REAL="$(readlink -f "$RAIZ/current")"
mapfile -t VIEJAS < <(find "$RAIZ/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r | tail -n +"$((CONSERVAR + 1))")
for v in "${VIEJAS[@]}"; do
  [ "$RAIZ/releases/$v" = "$ACTUAL_REAL" ] && continue
  [ "$RAIZ/releases/$v" = "$PREVIA" ] && continue
  rm -rf "${RAIZ:?}/releases/$v" && log "Borrada versión antigua $v"
done
printf '%s %s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$ID" "$SHA" >> "$RAIZ/despliegues.log"
log "✔ Publicada $ID"
