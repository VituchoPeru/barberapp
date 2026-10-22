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
## PWA y apps (Fase 12)

- `www/manifest.webmanifest`, `www/sw.js` (solo estáticos, nunca la API), `www/offline.html` y `www/pages/instalar.html`.
- `www/js/shell.js` se carga en todas las pantallas: registra el service worker, muestra el aviso sin conexión y la tarjeta de instalación, y en la app inicia lo nativo.
- `www/js/native.js` (adaptador de Capacitor), `www/js/push.js` (notificaciones), `www/js/descargas.js` (descargas y Compartir).
- `package.json` (versiones fijas), `capacitor.config.json`, `android/` e `ios/`.
- `npm run configurar` escribe la URL de la API en `www/js/runtime-config.js` (vacío en el repositorio); `npm run recursos` genera iconos y splash desde `resources/emblema.svg`; `npm run verificar` comprueba la PWA.

Guía completa (compilar, firmar, push, enlaces y tiendas): [docs/APPS_MOVILES.md](../docs/APPS_MOVILES.md).
