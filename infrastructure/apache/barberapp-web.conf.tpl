# BARBERAPP — frontend web en {{DOMINIO}} ({{DESCRIPCION}}) con Apache 2.4.
# Generado por: php bin/console despliegue:config --servidor=apache
# Las reglas de frontend/www/.htaccess están aquí (AllowOverride None) con la CSP limitada a la API.

<VirtualHost *:80>
    ServerName {{DOMINIO}}
    Alias /.well-known/acme-challenge/ {{ACME_DIR}}/.well-known/acme-challenge/
    <Directory {{ACME_DIR}}>
        Require all granted
    </Directory>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
</VirtualHost>

<VirtualHost *:443>
    ServerName {{DOMINIO}}
    Protocols h2 http/1.1
    DocumentRoot {{ACTUAL}}/frontend/www
    DirectoryIndex index.html
    ServerSignature Off

    SSLEngine on
    SSLCertificateFile    {{CERT_DIR}}/fullchain.pem
    SSLCertificateKeyFile {{CERT_DIR}}/privkey.pem
    SSLProtocol -all +TLSv1.2 +TLSv1.3

    <Directory {{ACTUAL}}/frontend/www>
        AllowOverride None
        Options -Indexes +FollowSymLinks
{{ACCESO_RESTRINGIDO}}
    </Directory>

    RewriteEngine On
    # Página de inicio de este dominio.
{{INICIO}}
    # Enlace directo de cada barbería y destino de su código QR (QSA conserva ?o=qr).
    RewriteRule "^/b/([a-z0-9]+(?:-[a-z0-9]+)*)/?$" /pages/barberia.html?slug=$1 [R=302,L,QSA]

    # Android App Links e iOS Universal Links (Fase 12).
    Alias /.well-known/assetlinks.json {{COMPARTIDO}}/well-known/assetlinks.json
    Alias /.well-known/apple-app-site-association {{COMPARTIDO}}/well-known/apple-app-site-association
    <Directory {{COMPARTIDO}}/well-known>
        Require all granted
        ForceType application/json
    </Directory>

    AddType application/manifest+json .webmanifest
    AddType font/woff2 .woff2
    <FilesMatch "^(sw\.js|manifest\.webmanifest|site-config\.js)$">
        Header set Cache-Control "no-cache"
    </FilesMatch>
    <FilesMatch "\.(css|js|woff2|png|svg|webp|jpg|ico)$">
        Header setifempty Cache-Control "public, max-age=3600"
    </FilesMatch>
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    # Cabeceras de seguridad (Fase 13). connect-src e img-src: solo la API {{URL_API}}.
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "camera=(), microphone=(), payment=(), usb=(), geolocation=(self)"
    Header always set Cross-Origin-Opener-Policy "same-origin"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: {{URL_API}}; font-src 'self'; connect-src 'self' {{URL_API}}; worker-src 'self'; manifest-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-src 'none'; frame-ancestors 'none'"

    ErrorLog  ${APACHE_LOG_DIR}/barberapp-web-error.log
    CustomLog ${APACHE_LOG_DIR}/barberapp-web-access.log combined
</VirtualHost>
