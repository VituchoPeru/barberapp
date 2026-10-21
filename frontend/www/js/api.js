import { config } from './config.js';

/** Error de la API con el código estable devuelto por el backend. */
export class ApiError extends Error {
  constructor(status, code, message, details) {
    super(message);
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

/**
 * Cliente HTTP mínimo sobre Fetch API.
 * Respuestas del backend: { ok: true, data, meta } | { ok: false, error: { code, message, details } }
 */
export async function api(path, { method = 'GET', body, headers = {}, signal } = {}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), config.REQUEST_TIMEOUT_MS);
  try {
    const res = await fetch(config.API_BASE_URL + path, {
      method,
      credentials: 'include',
      headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}), ...headers },
      body: body ? JSON.stringify(body) : undefined,
      signal: signal || controller.signal,
    });
    const payload = await res.json().catch(() => null);
    if (!res.ok || !payload || payload.ok !== true) {
      const err = payload && payload.error ? payload.error : {};
      throw new ApiError(res.status, err.code || 'network_error', err.message || 'No se pudo completar la solicitud.', err.details);
    }
    return payload.data;
  } catch (e) {
    if (e instanceof ApiError) throw e;
    throw new ApiError(0, 'network_error', 'Sin conexión con el servidor. Verifica tu internet e intenta de nuevo.');
  } finally {
    clearTimeout(timer);
  }
}
