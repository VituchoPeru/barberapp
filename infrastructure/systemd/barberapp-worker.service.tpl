# BARBERAPP — worker de avisos y recordatorios (Fase 8) como servicio (generado por despliegue:config).
# Destino: /etc/systemd/system/barberapp-worker.service
#   systemctl enable --now barberapp-worker
#   journalctl -u barberapp-worker -f
# El worker procesa la cola sin esperar al minuto siguiente del cron y sale solo cada hora (o al
# recibir SIGTERM, terminando el lote en curso); systemd lo vuelve a iniciar con el código nuevo.
# desplegar.sh lo reinicia en cada versión.
[Unit]
Description=BARBERAPP worker de avisos y recordatorios
After=network-online.target mysql.service
Wants=network-online.target

[Service]
Type=simple
User={{USUARIO}}
Group={{USUARIO}}
WorkingDirectory={{ACTUAL}}/backend
ExecStart={{PHP_BIN}} {{ACTUAL}}/backend/bin/console notificaciones:procesar --continuo --max-minutos=60
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=90
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ProtectHome=true
ReadWritePaths={{COMPARTIDO}} {{LOG_DIR}} {{BACKUP_DIR}}

[Install]
WantedBy=multi-user.target
