# BARBERAPP — tareas programadas (generado por despliegue:config).
# Destino: /etc/cron.d/barberapp   (formato cron.d: incluye el usuario)
# Horas en la zona horaria del servidor, que debe ser UTC (Lima = UTC−5): 08:15 UTC = 03:15 en Lima.
# Cada tarea deja un latido; monitor:revisar avisa si alguna deja de correr.
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
MAILTO=""

{{LINEA_WORKER}}
# Suscripciones: cambios programados, vencimientos, restricción y avisos (cada hora).
5 * * * *     {{USUARIO}} cd {{ACTUAL}}/backend && {{PHP_BIN}} bin/console suscripciones:vencer >> {{LOG_DIR}}/cron.log 2>&1
# Monitoreo: correo a MONITOR_EMAIL si algo falla (cada 5 minutos).
*/5 * * * *   {{USUARIO}} cd {{ACTUAL}}/backend && {{PHP_BIN}} bin/console monitor:revisar --alertar --silencioso >> {{LOG_DIR}}/monitor.log 2>&1
# Copia cifrada diaria, envío fuera del servidor y prueba de restauración semanal (domingo).
15 8 * * *    {{USUARIO}} cd {{ACTUAL}}/backend && {{PHP_BIN}} bin/console backup:crear >> {{LOG_DIR}}/cron.log 2>&1
45 8 * * *    {{USUARIO}} cd {{ACTUAL}}/backend && {{PHP_BIN}} bin/console backup:enviar >> {{LOG_DIR}}/cron.log 2>&1
30 9 * * 0    {{USUARIO}} cd {{ACTUAL}}/backend && {{PHP_BIN}} bin/console backup:verificar >> {{LOG_DIR}}/cron.log 2>&1
# Limpieza y retención de datos (Ley 29733).
0 9 * * *     {{USUARIO}} cd {{ACTUAL}}/backend && {{PHP_BIN}} bin/console privacidad:retencion >> {{LOG_DIR}}/cron.log 2>&1
10 9 * * *    {{USUARIO}} cd {{ACTUAL}}/backend && {{PHP_BIN}} bin/console auth:purge >> {{LOG_DIR}}/cron.log 2>&1
20 9 * * *    {{USUARIO}} cd {{ACTUAL}}/backend && {{PHP_BIN}} bin/console reservas:purge >> {{LOG_DIR}}/cron.log 2>&1
30 9 * * *    {{USUARIO}} cd {{ACTUAL}}/backend && {{PHP_BIN}} bin/console notificaciones:purge >> {{LOG_DIR}}/cron.log 2>&1
40 9 * * *    {{USUARIO}} cd {{ACTUAL}}/backend && {{PHP_BIN}} bin/console logs:purge >> {{LOG_DIR}}/cron.log 2>&1
