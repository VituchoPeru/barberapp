# Frontend BARBERAPP

Frontend único (HTML5 + CSS3 + JavaScript ES6 modular, sin frameworks) compartido por web, PWA, Android e iOS.

- `www/` es la carpeta que se publica y que Capacitor empaqueta (`webDir`).
- `www/css/tokens.css` contiene la paleta y tokens de diseño.
- `www/js/api.js` es el cliente HTTP sobre Fetch API que entiende el formato de respuesta del backend.

Estado en la Fase 1: solo una pantalla que verifica la conexión con `GET /api/v1/health`.
`package.json`, `capacitor.config.json`, `android/` e `ios/` se crean en la Fase 12, tras verificar versiones compatibles de Capacitor.
