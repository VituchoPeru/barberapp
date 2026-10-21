# Frontend BARBERAPP

Frontend único (HTML5 + CSS3 + JavaScript ES6 modular, sin frameworks) compartido por web, PWA, Android e iOS.

- `www/` es la carpeta que se publica y que Capacitor empaqueta (`webDir`).
- `www/css/tokens.css` contiene la paleta y tokens de diseño.
- `www/js/api.js` es el cliente HTTP sobre Fetch API que entiende el formato de respuesta del backend.

- `www/css/app.css`: estilos de las pantallas del marketplace (mobile first). `www/js/icons.js`: iconos SVG en línea. `www/js/marketplace.js`: tarjetas, favoritos, tabbar y ubicación (redondeada, solo en `sessionStorage`).
- `www/.htaccess`: redirige el enlace directo `/b/{slug}` al perfil de la barbería.
- `www/js/auth.js` y `www/js/ui.js`: sesión (cookie HttpOnly + token CSRF) y ayudas de formularios.

Pantallas disponibles:

- `www/index.html`: splash; verifica `GET /api/v1/health` y lleva al onboarding (primera vez) o a Inicio.
- `www/pages/onboarding.html`, `inicio.html`, `explorar.html`, `barberia.html`, `favoritos.html`, `reservar.html`: marketplace (Fase 3, ver `docs/MARKETPLACE.md`).
- `www/pages/panel/perfil-publico.html`: perfil público, fotos, enlace y QR de la barbería.
- `www/pages/auth/`: iniciar sesión, crear cuenta (cliente o barbería), recuperar contraseña, nueva contraseña, verificar correo y "Mi cuenta" (barberías, invitaciones, sesiones abiertas).

`API_BASE_URL` se calcula desde `js/config.js`; en otro despliegue define `window.BARBERAPP_API_URL` antes de cargar los módulos.
`package.json`, `capacitor.config.json`, `android/` e `ios/` se crean en la Fase 12, tras verificar versiones compatibles de Capacitor.
