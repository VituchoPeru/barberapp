#!/usr/bin/env bash
# BARBERAPP — volver a una versión anterior (Fase 15). Ver docs/DESPLIEGUE.md, "Rollback".
#
#   sudo …/rollback.sh                      vuelve a la versión anterior a la actual (solo código)
#   sudo …/rollback.sh --a=<versión>        vuelve a esa versión (carpeta de releases/)
#   sudo …/rollback.sh --listar             muestra las versiones disponibles
#   sudo …/rollback.sh --restaurar-base [--copia=/ruta/copia.sql.gz.enc]
#        además devuelve la base de datos a la copia hecha antes de las migraciones de la versión
#        actual (o a la indicada). BORRA lo que se haya registrado después de esa copia: antes
#        guarda una copia del estado actual, por si hay que recuperar algo.
#
# Las migraciones se escriben para que la versión anterior siga funcionando con el esquema nuevo
# (solo agregan tablas y columnas: docs/DESPLIEGUE.md, "Migraciones compatibles"), así que casi
# siempre basta con volver el código, sin perder ninguna reserva.
set -Eeuo pipefail

CONF="${BARBERAPP_DESPLIEGUE_CONF:-/etc/barberapp/despliegue.conf}"
DESTINO=""
RESTAURAR=0
COPIA=""
LISTAR=0
for arg in "$@"; do
  case "$arg" in
    --a=*) DESTINO="${arg#*=}" ;;
    --restaurar-base) RESTAURAR=1 ;;
    --copia=*) COPIA="${arg#*=}" ;;
    --listar) LISTAR=1 ;;
    --config=*) CONF="${arg#*=}" ;;
    -h|--help) sed -n '2,16p' "$0"; exit 0 ;;
    *) echo "Opción desconocida: $arg" >&2; exit 2 ;;
  esac
done
[ -r "$CONF" ] || { echo "No se puede leer $CONF." >&2; exit 2; }
# shellcheck source=/dev/null
. "$CONF"
: "${RAIZ:?Falta RAIZ}" "${USUARIO:?Falta USUARIO}" "${PHP_BIN:?Falta PHP_BIN}"
LOG_DIR="${LOG_DIR:-/var/log/barberapp}"
mkdir -p "$LOG_DIR"
LOG="$LOG_DIR/despliegue.log"
log() { printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" | tee -a "$LOG"; }
die() { log "✖ $*"; exit 1; }
as_app() { if [ "$(id -u)" = "0" ] && [ "$USUARIO" != "root" ]; then runuser -u "$USUARIO" -- "$@"; else "$@"; fi; }
console() { local rel="$1"; shift; (cd "$rel/backend" && as_app "$PHP_BIN" bin/console "$@"); }
run_hook() { [ -z "$1" ] && return 0; log "  $1"; bash -c "$1"; }

ACTUAL="$(readlink -f "$RAIZ/current" 2>/dev/null || true)"
[ -n "$ACTUAL" ] && [ -d "$ACTUAL" ] || die "No hay versión actual en $RAIZ/current."
mapfile -t VERSIONES < <(find "$RAIZ/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r)

if [ "$LISTAR" = 1 ]; then
  for v in "${VERSIONES[@]}"; do
    marca="  "; [ "$RAIZ/releases/$v" = "$ACTUAL" ] && marca="→ "
    info=""; [ -f "$RAIZ/releases/$v/.despliegue" ] && info="$(grep -E '^(MIGRACIONES|ESTADO)=' "$RAIZ/releases/$v/.despliegue" | tr '\n' ' ')"
    echo "$marca$v $info"
  done
  exit 0
fi

exec 9>"$RAIZ/.despliegue.lock"
flock -n 9 || die "Hay un despliegue o rollback en curso."

if [ -z "$DESTINO" ]; then
  # La versión publicada justo antes de la actual (la anotada en su .despliegue o la siguiente más antigua).
  PREV="$(sed -n 's/^PREVIA=//p' "$ACTUAL/.despliegue" 2>/dev/null || true)"
  if [ -n "$PREV" ] && [ "$PREV" != "ninguna" ] && [ -d "$RAIZ/releases/$PREV" ]; then
    DESTINO="$PREV"
  else
    for v in "${VERSIONES[@]}"; do
      if [[ "$v" < "$(basename "$ACTUAL")" ]]; then DESTINO="$v"; break; fi
    done
  fi
fi
[ -n "$DESTINO" ] || die "No hay una versión anterior a $(basename "$ACTUAL")."
DESTINO="$(basename "$DESTINO")"
OBJ="$RAIZ/releases/$DESTINO"
[ -d "$OBJ/backend" ] || die "No existe la versión $DESTINO (usa --listar)."
[ "$OBJ" != "$ACTUAL" ] || die "$DESTINO ya es la versión actual."
log "Rollback: $(basename "$ACTUAL") → $DESTINO"

if [ "$RESTAURAR" = 1 ]; then
  [ -n "$COPIA" ] || COPIA="$(sed -n 's/^COPIA_PREVIA=//p' "$ACTUAL/.despliegue" 2>/dev/null || true)"
  [ -n "$COPIA" ] && [ -f "$COPIA" ] || die "No se encontró la copia previa a $(basename "$ACTUAL"). Indícala con --copia= (php bin/console backup:listar)."
  DB="$(sed -n 's/^DB_DATABASE=//p' "$RAIZ/shared/.env" | tail -n 1 | tr -d '"'"'"' ')"
  [ -n "$DB" ] || die "No se pudo leer DB_DATABASE de shared/.env."
  console "$OBJ" mantenimiento:activar --motivo="rollback a $DESTINO" | tee -a "$LOG"
  log "Copia de seguridad del estado actual antes de restaurar"
  console "$ACTUAL" backup:crear | tee -a "$LOG" || die "No se pudo copiar el estado actual: no se restaura nada. La API sigue en mantenimiento."
  log "Restaurando $COPIA en $DB"
  console "$OBJ" backup:restaurar "$COPIA" --base="$DB" --sobrescribir --limpiar | tee -a "$LOG" || die "Falló la restauración. La API sigue en mantenimiento."
fi

ln -sfn "$OBJ" "$RAIZ/current.nuevo"
mv -Tf "$RAIZ/current.nuevo" "$RAIZ/current"
log "current → $DESTINO"
run_hook "${RECARGAR_PHP:-}"
run_hook "${REINICIAR_WORKER:-}"
echo "ESTADO=revertida $(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "$ACTUAL/.despliegue"
[ "$RESTAURAR" = 1 ] && console "$OBJ" mantenimiento:desactivar | tee -a "$LOG"

if [ -n "${URL_SALUD:-}" ]; then
  # shellcheck disable=SC2086
  if curl -fsS --max-time 10 ${CURL_SALUD:-} "$URL_SALUD" 2>/dev/null | grep -q '"status":"ok"'; then
    log "Salud OK ($URL_SALUD)"
  else
    log "! $URL_SALUD no responde \"ok\": revisa php bin/console monitor:revisar (puede faltar una migración: rollback.sh --restaurar-base)."
    exit 1
  fi
fi
printf '%s rollback %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$DESTINO" >> "$RAIZ/despliegues.log"
log "✔ Ahora atiende $DESTINO"
