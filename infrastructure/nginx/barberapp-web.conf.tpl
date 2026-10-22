# BARBERAPP — frontend web en {{DOMINIO}} ({{DESCRIPCION}}).
# Generado por: php bin/console despliegue:config  (plantilla infrastructure/nginx/barberapp-web.conf.tpl)
# Los tres frontends (clientes, panel de barberías y plataforma) sirven el mismo frontend/www;
# solo cambia la página de inicio.

server {
    listen 80;
{{LISTEN_80_IPV6}}
    server_name {{DOMINIO}};
    location ^~ /.well-known/acme-challenge/ { root {{ACME_DIR}}; default_type text/plain; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl http2;
{{LISTEN_443_IPV6}}
    server_name {{DOMINIO}};
    root {{ACTUAL}}/frontend/www;
    index index.html;
    include {{NGINX_SNIPPETS}}/barberapp-ssl.conf;
    include {{NGINX_SNIPPETS}}/barberapp-cabeceras-web.conf;

    access_log /var/log/nginx/barberapp-web-access.log;
    error_log  /var/log/nginx/barberapp-web-error.log warn;
    server_tokens off;
    gzip on;
    gzip_types text/css application/javascript application/manifest+json image/svg+xml application/json;

{{ACCESO_RESTRINGIDO}}
    # Página de inicio de este dominio.
    location = / {
{{INICIO}}
    }

    # Enlace directo de cada barbería y destino de su código QR: /b/{slug}[?o=qr]
    location ~ "^/b/([a-z0-9]+(-[a-z0-9]+)*)/?$" {
        return 302 /pages/barberia.html?slug=$1&$args;
    }

    # PWA: el service worker, el manifiesto y la configuración del sitio siempre se revalidan.
    # (Nginx no hereda add_header en un location con su propio add_header: se repiten las cabeceras.)
    location ~ ^/(sw\.js|manifest\.webmanifest|js/site-config\.js)$ {
        include {{NGINX_SNIPPETS}}/barberapp-cabeceras-web.conf;
        add_header Cache-Control "no-cache" always;
        types { application/manifest+json webmanifest; application/javascript js; }
        try_files $uri =404;
    }

    # Android App Links e iOS Universal Links (Fase 12): archivos con los datos reales en
    # {{COMPARTIDO}}/well-known (plantillas en infrastructure/well-known/).
    location = /.well-known/assetlinks.json {
        alias {{COMPARTIDO}}/well-known/assetlinks.json;
        default_type application/json;
    }
    location = /.well-known/apple-app-site-association {
        alias {{COMPARTIDO}}/well-known/apple-app-site-association;
        default_type application/json;
    }

    # CSS, JS, fuentes e imágenes: caché corta (las URL no cambian entre versiones; el service
    # worker cambia de versión en cada despliegue y vuelve a descargarlos).
    location ~* \.(css|js|woff2|png|svg|webp|jpg|ico)$ {
        include {{NGINX_SNIPPETS}}/barberapp-cabeceras-web.conf;
        add_header Cache-Control "public, max-age=3600" always;
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ /\.(?!well-known) { deny all; }
}
