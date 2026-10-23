# BARBERAPP — cabeceras de seguridad del frontend web (Fase 13, ver docs/SEGURIDAD.md).
# Generado por despliegue:config: connect-src e img-src permiten solo la API {{URL_API}}.
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), payment=(), usb=(), geolocation=(self)" always;
add_header Cross-Origin-Opener-Policy "same-origin" always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: {{URL_API}}; font-src 'self'; connect-src 'self' {{URL_API}}; worker-src 'self'; manifest-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-src 'none'; frame-ancestors 'none'" always;
