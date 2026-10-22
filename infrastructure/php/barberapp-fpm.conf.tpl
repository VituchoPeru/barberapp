; BARBERAPP — pool de PHP-FPM propio (generado por despliegue:config).
; Destino: /etc/php/{{PHP_VERSION}}/fpm/pool.d/barberapp.conf
; La API corre con su propio usuario ({{USUARIO}}): no puede leer otros sitios del servidor
; y el servidor web solo habla con ella por el socket.
[barberapp]
user = {{USUARIO}}
group = {{USUARIO}}
listen = {{PHP_FPM_SOCKET}}
listen.owner = {{USUARIO_WEB}}
listen.group = {{USUARIO_WEB}}
listen.mode = 0660

; Ajusta pm.max_children a la memoria del servidor (~40 MB por proceso): 2 GB → 20.
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500
request_terminate_timeout = 90s

; Estado del pool solo desde el propio servidor (curl --unix-socket …) para el monitoreo.
pm.status_path = /fpm-estado
ping.path = /fpm-ping

catch_workers_output = yes
decorate_workers_output = no
clear_env = yes

php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = {{LOG_DIR}}/php-error.log
php_admin_value[memory_limit] = 256M
php_admin_value[max_execution_time] = 60
php_admin_value[upload_max_filesize] = 6M
php_admin_value[post_max_size] = 8M
php_admin_value[max_input_vars] = 2000
; PHP solo puede leer y escribir dentro de BARBERAPP, sus copias, sus registros y /etc/barberapp
; (credenciales de Firebase u otras fuera del repositorio).
php_admin_value[open_basedir] = {{RAIZ}}/:{{BACKUP_DIR}}/:{{LOG_DIR}}/:/etc/barberapp/:/tmp/
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec
