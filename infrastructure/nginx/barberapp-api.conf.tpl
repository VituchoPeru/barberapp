# BARBERAPP — API ({{DOMINIO_API}}) con Nginx + PHP-FPM.
# Generado por: php bin/console despliegue:config  (plantilla infrastructure/nginx/barberapp-api.conf.tpl)
# No edites el archivo generado: cambia la plantilla o backend/.env y vuelve a generarlo.

server {
    listen 80;
{{LISTEN_80_IPV6}}
    server_name {{DOMINIO_API}};
    # Validación de Let's Encrypt (renovación de certificados).
    location ^~ /.well-known/acme-challenge/ { root {{ACME_DIR}}; default_type text/plain; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl http2;
{{LISTEN_443_IPV6}}
    server_name {{DOMINIO_API}};

    # La raíz es SIEMPRE backend/public: .env, app/, database/ y storage/ quedan fuera de la web.
    root {{ACTUAL}}/backend/public;
    include {{NGINX_SNIPPETS}}/barberapp-ssl.conf;

    access_log /var/log/nginx/barberapp-api-access.log;
    error_log  /var/log/nginx/barberapp-api-error.log warn;

    # Fotos de hasta MEDIA_MAX_UPLOAD_BYTES (5 MB) más el resto de la petición.
    client_max_body_size 8m;
    server_tokens off;
    gzip on;
    gzip_types application/json text/csv;

    # Imágenes subidas por las barberías (MEDIA_PATH): estáticos, sin PHP, compartidos entre versiones.
    location ^~ /media/ {
        alias {{MEDIA_DIR}}/;
        location ~ \.(php|phtml|phar)$ { deny all; }
        add_header X-Content-Type-Options "nosniff" always;
        add_header Content-Security-Policy "default-src 'none'; img-src 'self'; sandbox" always;
        add_header Cross-Origin-Resource-Policy "cross-origin" always;
        expires 30d;
        try_files $uri =404;
    }

    # Todo lo demás lo atiende index.php (el router de la API).
    location / {
        try_files $uri /index.php$is_args$args;
    }

    location = /index.php {
        fastcgi_pass unix:{{PHP_FPM_SOCKET}};
        include fastcgi_params;
        # $realpath_root resuelve el enlace "current": cada petición usa una sola versión completa.
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_param HTTPS on;
        fastcgi_param HTTP_PROXY "";
        fastcgi_read_timeout 90s;
    }

    # Ningún otro .php ni archivo oculto (.htaccess, .env) se sirve.
    location ~ \.php$ { return 404; }
    location ~ /\. { deny all; }
}
