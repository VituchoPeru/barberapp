// Configuración del frontend. API_BASE_URL apunta al backend (backend/public).
// En XAMPP, con el proyecto en htdocs/barberapp, el valor por defecto funciona sin cambios.
export const config = {
  API_BASE_URL: window.BARBERAPP_API_URL || new URL('../../backend/public/api/v1', window.location.href).href.replace(/\/$/, ''),
  REQUEST_TIMEOUT_MS: 15000,
};
