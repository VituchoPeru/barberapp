import { config } from './config.js';
import { isNative, secureStore } from './native.js';

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

// ======================================================================= sesión móvil
//
// En la app nativa no hay cookie: la API entrega un access token de 15 minutos y un refresh
// token rotativo (docs/AUTENTICACION.md). El refresh token vive en el llavero del sistema
// (Keychain / Keystore). El access token se guarda en sessionStorage de la WebView para no
// renovarlo en cada cambio de pantalla; se borra al cerrar la app y caduca a los 15 minutos.

const ACCESS_KEY = 'barberapp.access';
const REFRESH_KEY = 'barberapp.refresh';
const EXPIRY_MARGIN_MS = 30000;
let refreshCache; // undefined = aún no leído del llavero
let refreshing = null;

export const mobileSession = {
  enabled: () => isNative(),

  access() {
    try {
      const raw = JSON.parse(sessionStorage.getItem(ACCESS_KEY) || 'null');
      return raw && raw.exp > Date.now() ? raw.token : null;
    } catch { return null; }
  },

  async refreshToken() {
    if (refreshCache === undefined) refreshCache = await secureStore.get(REFRESH_KEY);
    return refreshCache;
  },

  /** Guarda los tokens de login, registro o refresh. Primero el refresh (el anterior ya no vale). */
  async store(data) {
    if (!data || !data.access_token) return;
    if (data.refresh_token) {
      refreshCache = data.refresh_token;
      await secureStore.set(REFRESH_KEY, data.refresh_token);
    }
    try {
      sessionStorage.setItem(ACCESS_KEY, JSON.stringify({ token: data.access_token, exp: Date.now() + (Number(data.expires_in) || 900) * 1000 - EXPIRY_MARGIN_MS }));
    } catch { /* sin sessionStorage: se renueva cuando haga falta */ }
  },

  async clear() {
    refreshCache = null;
    try { sessionStorage.removeItem(ACCESS_KEY); } catch { /* nada */ }
    await secureStore.remove(REFRESH_KEY);
  },

  /**
   * Renueva el access token. Una sola renovación a la vez: el refresh token se puede usar una
   * única vez y presentarlo dos veces cierra la sesión por seguridad.
   * Devuelve el token nuevo o null si la sesión terminó.
   */
  renew() {
    if (refreshing) return refreshing;
    refreshing = (async () => {
      const token = await mobileSession.refreshToken();
      if (!token) return null;
      let res;
      try {
        res = await fetchWithTimeout(config.API_BASE_URL + '/auth/refresh', {
          method: 'POST', credentials: 'omit',
          headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
          body: JSON.stringify({ refresh_token: token }),
        });
      } catch {
        throw new ApiError(0, 'network_error', 'Sin conexión con el servidor. Verifica tu internet e intenta de nuevo.');
      }
      const payload = await res.json().catch(() => null);
      if (res.ok && payload && payload.ok) {
        await mobileSession.store(payload.data);
        return payload.data.access_token;
      }
      if (res.status === 401) {
        await mobileSession.clear();
        return null;
      }
      const err = (payload && payload.error) || {};
      throw new ApiError(res.status, err.code || 'network_error', err.message || 'No se pudo renovar la sesión.');
    })().finally(() => { refreshing = null; });
    return refreshing;
  },
};

/** Cierra la sesión local (web y app). */
export async function clearSession() {
  csrf.set(null);
  if (isNative()) await mobileSession.clear();
}

// ======================================================================= peticiones

const SAFE = ['GET', 'HEAD', 'OPTIONS'];

async function fetchWithTimeout(url, init, timeout) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeout || config.REQUEST_TIMEOUT_MS);
  try {
    return await fetch(url, { ...init, signal: init.signal || controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

/** Petición HTTP con las credenciales de la plataforma (cookie + CSRF en web, Bearer en la app). */
async function send(path, { method = 'GET', body, headers = {}, signal, raw, timeout, accept = 'application/json' } = {}, retried = false) {
  if (!config.API_BASE_URL) {
    throw new ApiError(0, 'not_configured', 'La app no tiene configurada la dirección del servidor. Reinstálala o contacta a soporte.');
  }
  const native = isNative();
  // raw: cuerpo binario (p. ej. un File de imagen) enviado tal cual con su tipo MIME.
  const h = { Accept: accept, ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}), ...(raw ? { 'Content-Type': raw.type } : {}), ...headers };
  if (native) {
    let token = mobileSession.access();
    if (!token && (await mobileSession.refreshToken())) token = await mobileSession.renew();
    if (token) h.Authorization = `Bearer ${token}`;
  } else if (!SAFE.includes(method) && csrf.get()) {
    h['X-CSRF-Token'] = csrf.get();
  }
  let res;
  try {
    res = await fetchWithTimeout(config.API_BASE_URL + path, {
      method,
      credentials: native ? 'omit' : 'include',
      headers: h,
      body: raw || (body !== undefined ? JSON.stringify(body) : undefined),
      signal,
    }, timeout);
  } catch {
    throw new ApiError(0, 'network_error', 'Sin conexión con el servidor. Verifica tu internet e intenta de nuevo.');
  }
  // Access token vencido o revocado: se renueva una vez y se repite la petición.
  if (native && res.status === 401 && h.Authorization && !retried) {
    const payload = await res.clone().json().catch(() => null);
    if (payload && payload.error && payload.error.code === 'invalid_token') {
      try { sessionStorage.removeItem(ACCESS_KEY); } catch { /* nada */ }
      if (await mobileSession.renew()) return send(path, { method, body, headers, signal, raw, timeout, accept }, true);
    }
  }
  return res;
}

async function request(path, options = {}) {
  const res = await send(path, options);
  if (res.status === 204) return null;
  const payload = await res.json().catch(() => null);
  if (!res.ok || !payload || payload.ok !== true) {
    const err = payload && payload.error ? payload.error : {};
    throw new ApiError(res.status, err.code || 'network_error', err.message || 'No se pudo completar la solicitud.', err.details);
  }
  return options.withMeta ? { data: payload.data, meta: payload.meta || {} } : payload.data;
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

/**
 * Descarga un archivo de la API (CSV, Excel, PDF, QR…) con la sesión actual.
 * Acepta method/body para descargas que piden datos (p. ej. la contraseña al exportar).
 * Devuelve { blob, filename }; los errores llegan como ApiError con el mensaje del backend.
 */
export async function apiFile(path, { method = 'GET', body, headers = {}, accept = '*/*', fallbackName = 'archivo', timeout = 60000 } = {}) {
  let res = await send(path, { method, body, headers, accept, timeout });
  if (res.status === 419 || res.status === 403) {
    // Token CSRF perdido (recarga, otra pestaña): se recupera una vez, como en api().
    const p = await res.clone().json().catch(() => null);
    if (p && p.error && p.error.code === 'csrf_mismatch') {
      const data = await request('/auth/csrf');
      csrf.set(data.csrf_token);
      res = await send(path, { method, body, headers, accept, timeout });
    }
  }
  if (!res.ok) {
    const p = await res.json().catch(() => null);
    const err = (p && p.error) || {};
    throw new ApiError(res.status, err.code || 'download_failed', err.message || 'No se pudo generar el archivo.', err.details);
  }
  const cd = res.headers.get('Content-Disposition') || '';
  const m = cd.match(/filename="([^"]+)"/);
  return { blob: await res.blob(), filename: m ? m[1] : fallbackName };
}
