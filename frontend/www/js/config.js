// Configuración del frontend. API_BASE_URL apunta al backend (backend/public).
// En XAMPP, con el proyecto en htdocs/barberapp, el valor por defecto funciona sin cambios:
// se calcula desde la ubicación de este archivo (frontend/www/js/) y no de la página actual.
export const config = {
  API_BASE_URL: window.BARBERAPP_API_URL || new URL('../../../backend/public/api/v1', import.meta.url).href.replace(/\/$/, ''),
  REQUEST_TIMEOUT_MS: 15000,
};
