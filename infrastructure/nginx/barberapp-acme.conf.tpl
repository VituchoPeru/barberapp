# BARBERAPP — configuración provisional SOLO por HTTP para obtener el primer certificado.
# La usa instalar-vps.sh antes de que exista el certificado; después se reemplaza por la completa.
server {
    listen 80;
{{LISTEN_80_IPV6}}
    server_name {{DOMINIOS}};
    location ^~ /.well-known/acme-challenge/ { root {{ACME_DIR}}; default_type text/plain; }
    location / { return 503; }
}
