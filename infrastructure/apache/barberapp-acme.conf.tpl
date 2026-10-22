# BARBERAPP — configuración provisional SOLO por HTTP para obtener el primer certificado.
<VirtualHost *:80>
    ServerName {{DOMINIO_API}}
    ServerAlias {{DOMINIOS}}
    DocumentRoot {{ACME_DIR}}
    <Directory {{ACME_DIR}}>
        Require all granted
    </Directory>
</VirtualHost>
