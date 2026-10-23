# BARBERAPP — rotación de registros (generado por despliegue:config).
# Destino: /etc/logrotate.d/barberapp
# Los registros de la aplicación (app-AAAA-MM-DD.log) ya son diarios: los borra logs:purge.
{{LOG_DIR}}/cron.log {{LOG_DIR}}/monitor.log {{LOG_DIR}}/php-error.log {{LOG_DIR}}/despliegue.log {
    su {{USUARIO}} {{USUARIO}}
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
