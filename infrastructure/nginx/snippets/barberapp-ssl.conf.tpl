# BARBERAPP — TLS (generado por despliegue:config). Un solo certificado para los cuatro dominios
# (certbot --cert-name {{CERT_NOMBRE}}); certbot lo renueva solo y recarga Nginx.
ssl_certificate     {{CERT_DIR}}/fullchain.pem;
ssl_certificate_key {{CERT_DIR}}/privkey.pem;
ssl_protocols TLSv1.2 TLSv1.3;
ssl_prefer_server_ciphers off;
ssl_session_cache shared:barberapp_ssl:10m;
ssl_session_timeout 1d;
ssl_session_tickets off;
