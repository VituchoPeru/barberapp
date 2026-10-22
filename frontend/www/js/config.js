import { isNative } from './native.js';
import runtime from './runtime-config.js';

// Configuración del frontend. API_BASE_URL apunta al backend (backend/public).
// - Web (XAMPP): el valor por defecto funciona sin cambios con el proyecto en htdocs/barberapp;
//   se calcula desde la ubicación de este archivo (frontend/www/js/) y no de la página actual.
//   En otro despliegue define window.BARBERAPP_API_URL antes de cargar los módulos.
// - App nativa (Capacitor): la WebView sirve www/ desde https://localhost (Android) o
//   capacitor://localhost (iOS), así que la URL de la API se fija al compilar en
//   js/runtime-config.js (npm run configurar, ver docs/APPS_MOVILES.md).
export const config = {
  API_BASE_URL: (window.BARBERAPP_API_URL || (isNative() ? runtime.apiUrl : new URL('../../../backend/public/api/v1', import.meta.url).href) || '').replace(/\/$/, ''),
  REQUEST_TIMEOUT_MS: 15000,
};
