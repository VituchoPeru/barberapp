import { api, csrf, ApiError } from './api.js';

/** Utilidades de sesión compartidas por las pantallas de acceso. */

export async function login(email, password) {
  const data = await api('/auth/login', { method: 'POST', body: { email, password, canal: 'web' } });
  csrf.set(data.csrf_token);
  return data;
}

export async function register(payload) {
  const data = await api('/auth/register', { method: 'POST', body: { ...payload, canal: 'web' } });
  csrf.set(data.csrf_token);
  return data;
}

export async function logout(todas = false) {
  try {
    await api('/auth/logout', { method: 'POST', body: { todas } });
  } finally {
    csrf.set(null);
  }
}

/** Usuario actual o null si no hay sesión. */
export async function currentUser() {
  try {
    return await api('/me');
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) {
      csrf.set(null);
      return null;
    }
    throw e;
  }
}

/** Ruta a la que volver tras iniciar sesión (solo rutas internas, evita redirecciones abiertas). */
export function nextUrl(fallback = 'cuenta.html') {
  const next = new URLSearchParams(location.search).get('next');
  return next && /^[a-z0-9_\-/.]+\.html(\?[\w=&%-]*)?$/i.test(next) && !next.startsWith('//') ? next : fallback;
}
