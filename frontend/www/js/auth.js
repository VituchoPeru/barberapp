import { api, csrf, ApiError, mobileSession, clearSession } from './api.js';
import { isNative, platform } from './native.js';

/** Utilidades de sesión compartidas por las pantallas de acceso. */

/** Canal de la sesión: cookie + CSRF en web, tokens en la app nativa. */
function channel() {
  return isNative()
    ? { canal: 'movil', dispositivo: `App BARBERAPP (${platform() === 'ios' ? 'iOS' : 'Android'})` }
    : { canal: 'web' };
}

async function keep(data) {
  if (isNative()) {
    await mobileSession.store(data);
    // Recién iniciada la sesión es el momento de pedir permiso para los recordatorios.
    // Si la persona no acepta, la app sigue igual (los avisos quedan en la bandeja).
    try { await (await import('./push.js')).enablePush(); } catch { /* sin push */ }
  } else {
    csrf.set(data.csrf_token);
  }
  return data;
}

export async function login(email, password) {
  return keep(await api('/auth/login', { method: 'POST', body: { email, password, ...channel() } }));
}

export async function register(payload) {
  return keep(await api('/auth/register', { method: 'POST', body: { ...payload, ...channel() } }));
}

export async function logout(todas = false) {
  try {
    if (isNative()) {
      const { unregisterPush } = await import('./push.js');
      await unregisterPush(); // este teléfono deja de recibir avisos de la cuenta
    }
    await api('/auth/logout', { method: 'POST', body: { todas } });
  } finally {
    await clearSession();
  }
}

/** Usuario actual o null si no hay sesión. */
export async function currentUser() {
  try {
    return await api('/me');
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) {
      await clearSession();
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
