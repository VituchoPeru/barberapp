# Frontend BARBERAPP

Frontend único (HTML5 + CSS3 + JavaScript ES6 modular, sin frameworks) compartido por web, PWA, Android e iOS.

- `www/` es la carpeta que se publica y que Capacitor empaqueta (`webDir`).
- `www/css/tokens.css` contiene la paleta y tokens de diseño.
- `www/js/api.js` es el cliente HTTP sobre Fetch API que entiende el formato de respuesta del backend.

- `www/js/auth.js` y `www/js/ui.js`: sesión (cookie HttpOnly + token CSRF) y ayudas de formularios.

Pantallas disponibles:

- `www/index.html`: verifica la conexión con `GET /api/v1/health` y da acceso a la cuenta.
- `www/pages/auth/`: iniciar sesión, crear cuenta (cliente o barbería), recuperar contraseña, nueva contraseña, verificar correo y "Mi cuenta" (barberías, invitaciones, sesiones abiertas).

`API_BASE_URL` se calcula desde `js/config.js`; en otro despliegue define `window.BARBERAPP_API_URL` antes de cargar los módulos.
`package.json`, `capacitor.config.json`, `android/` e `ios/` se crean en la Fase 12, tras verificar versiones compatibles de Capacitor.
