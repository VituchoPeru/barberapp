# BARBERAPP — API ({{DOMINIO_API}}) con Apache 2.4 + PHP-FPM (mod_proxy_fcgi).
# Generado por: php bin/console despliegue:config --servidor=apache
# Módulos: ssl, http2, rewrite, headers, proxy_fcgi, setenvif, expires.

<VirtualHost *:80>
    ServerName {{DOMINIO_API}}
    Alias /.well-known/acme-challenge/ {{ACME_DIR}}/.well-known/acme-challenge/
    <Directory {{ACME_DIR}}>
        Require all granted
    </Directory>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
</VirtualHost>

<VirtualHost *:443>
    ServerName {{DOMINIO_API}}
    Protocols h2 http/1.1
    DocumentRoot {{ACTUAL}}/backend/public
    ServerSignature Off

    SSLEngine on
    SSLCertificateFile    {{CERT_DIR}}/fullchain.pem
    SSLCertificateKeyFile {{CERT_DIR}}/privkey.pem
    SSLProtocol -all +TLSv1.2 +TLSv1.3

    # backend/public/.htaccess enruta todo a index.php y pasa la cabecera Authorization.
    <Directory {{ACTUAL}}/backend/public>
        AllowOverride All
        Options -Indexes +FollowSymLinks
        Require all granted
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:{{PHP_FPM_SOCKET}}|fcgi://barberapp"
    </FilesMatch>
    <Proxy "fcgi://barberapp">
        ProxySet timeout=90
    </Proxy>
    LimitRequestBody 8388608

    # Imágenes subidas (MEDIA_PATH): estáticos, nunca PHP.
    Alias /media/ {{MEDIA_DIR}}/
    <Directory {{MEDIA_DIR}}>
        AllowOverride None
        Options -Indexes
        Require all granted
        <FilesMatch "\.(php|phtml|phar)$">
            Require all denied
        </FilesMatch>
        SetHandler none
        Header always set X-Content-Type-Options "nosniff"
        Header always set Content-Security-Policy "default-src 'none'; img-src 'self'; sandbox"
        Header always set Cross-Origin-Resource-Policy "cross-origin"
        ExpiresActive On
        ExpiresDefault "access plus 30 days"
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/barberapp-api-error.log
    CustomLog ${APACHE_LOG_DIR}/barberapp-api-access.log combined
</VirtualHost>
