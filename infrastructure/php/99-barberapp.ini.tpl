; BARBERAPP — ajustes de PHP para producción (generado por despliegue:config).
; Destino: /etc/php/{{PHP_VERSION}}/fpm/conf.d/99-barberapp.ini y /etc/php/{{PHP_VERSION}}/cli/conf.d/99-barberapp.ini
expose_php = Off
display_errors = Off
log_errors = On
date.timezone = UTC
; OPcache: el despliegue recarga PHP-FPM al cambiar de versión.
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 128
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
realpath_cache_ttl = 120
