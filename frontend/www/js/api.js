import { config } from './config.js';

/** Error de la API con el código estable devuelto por el backend. */
export class ApiError extends Error {
  constructor(status, code, message, details) {
    super(message);
    this.status = status;
    this.code = code;
    this.details = details;
  }

  /** Errores por campo (422): { campo: ['mensaje'] } */
  get fields() {
    return (this.details && this.details.fields) || {};
  }
}

// Token CSRF de la sesión web. No es una credencial (la sesión vive en una cookie HttpOnly
// que JavaScript no puede leer); se guarda en sessionStorage para sobrevivir a recargas.
const CSRF_KEY = 'barberapp.csrf';
export const csrf = {
  get: () => { try { return sessionStorage.getItem(CSRF_KEY); } catch { return null; } },
  set: (v) => { try { v ? sessionStorage.setItem(CSRF_KEY, v) : sessionStorage.removeItem(CSRF_KEY); } catch { /* modo privado */ } },
};

const SAFE = ['GET', 'HEAD', 'OPTIONS'];

async function request(path, { method = 'GET', body, headers = {}, signal, withMeta = false, raw, timeout } = {}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeout || config.REQUEST_TIMEOUT_MS);
  // raw: cuerpo binario (p. ej. un File de imagen) enviado tal cual con su tipo MIME.
  const h = { Accept: 'application/json', ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}), ...(raw ? { 'Content-Type': raw.type } : {}), ...headers };
  if (!SAFE.includes(method) && csrf.get()) h['X-CSRF-Token'] = csrf.get();
  try {
    const res = await fetch(config.API_BASE_URL + path, {
      method,
      credentials: 'include',
      headers: h,
      body: raw || (body !== undefined ? JSON.stringify(body) : undefined),
      signal: signal || controller.signal,
    });
    if (res.status === 204) return null;
    const payload = await res.json().catch(() => null);
    if (!res.ok || !payload || payload.ok !== true) {
      const err = payload && payload.error ? payload.error : {};
      throw new ApiError(res.status, err.code || 'network_error', err.message || 'No se pudo completar la solicitud.', err.details);
    }
    return withMeta ? { data: payload.data, meta: payload.meta || {} } : payload.data;
  } catch (e) {
    if (e instanceof ApiError) throw e;
    throw new ApiError(0, 'network_error', 'Sin conexión con el servidor. Verifica tu internet e intenta de nuevo.');
  } finally {
    clearTimeout(timer);
  }
}

/**
 * Cliente HTTP mínimo sobre Fetch API.
 * Respuestas del backend: { ok: true, data, meta } | { ok: false, error: { code, message, details } }
 * Si el token CSRF se perdió (otra pestaña, recarga), lo recupera una vez y reintenta.
 */
export async function api(path, options = {}) {
  try {
    return await request(path, options);
  } catch (e) {
    if (e.code === 'csrf_mismatch' && !options._retried) {
      const data = await request('/auth/csrf');
      csrf.set(data.csrf_token);
      return request(path, { ...options, _retried: true });
    }
    throw e;
  }
}
