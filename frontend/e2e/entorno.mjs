// Valores compartidos por la configuración, el arranque y las pruebas E2E.
export const PORT = Number(process.env.E2E_PORT || 8090);
export const BASE_URL = `http://127.0.0.1:${PORT}`;
export const WEB = '/frontend/www';
export const API_URL = `${BASE_URL}/backend/public/api/v1`;
export const PASSWORD = 'Demo2026!';

/** Una cuenta demo por rol (database/seeds). */
export const CUENTAS = {
  cliente: 'cliente1@barberapp.test',
  cliente2: 'cliente2@barberapp.test',
  propietario: 'propietario.centro@barberapp.test',
  administrador: 'admin.centro@barberapp.test',
  barbero: 'barbero1.centro@barberapp.test',
  superadmin: 'superadmin@barberapp.test',
};

/** Variables del backend durante las pruebas: base propia, correo a archivo y sin límite general de escrituras. */
export const serverEnv = {
  APP_ENV: 'local',
  APP_DEBUG: 'false',
  DB_DATABASE: process.env.BARBERAPP_E2E_DB || 'barberapp_e2e',
  APP_URL: `${BASE_URL}/backend/public`,
  FRONTEND_URL: `${BASE_URL}${WEB}`,
  PUBLIC_BOOKING_URL: `${BASE_URL}${WEB}`,
  MAIL_DRIVER: 'log',
  PUSH_DRIVER: 'log',
  FORCE_HTTPS: 'false',
  SESSION_SECURE_COOKIE: 'false',
  RATE_LIMIT_WRITES: '0',
  CORS_ALLOWED_ORIGINS: BASE_URL,
};
