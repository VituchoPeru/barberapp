import { api } from './api.js';
import { isNative, platform, plugin, appUrl } from './native.js';

/**
 * Notificaciones push en la app nativa (Fase 12) con @capacitor/push-notifications.
 *
 * - Android: el plugin entrega el token de Firebase Cloud Messaging.
 * - iOS: AppDelegate convierte el token de APNs en token de FCM con Firebase Messaging
 *   (ver docs/APPS_MOVILES.md), así el backend usa un solo transporte (FCM HTTP v1).
 * - El token se registra en POST /me/dispositivos y se da de baja al cerrar sesión.
 * - Tocar un aviso abre la pantalla de la cita o la de confirmar asistencia.
 *
 * En web no hace nada: los avisos llegan a la bandeja y por correo.
 */

const TOKEN_KEY = 'barberapp.push.token';
const SYNC_KEY = 'barberapp.push.sync';
export const CHANNEL_ID = 'citas'; // el mismo que envía FcmTransport (android.notification.channel_id)

const push = () => plugin('PushNotifications');
const lastToken = {
  get: () => { try { return localStorage.getItem(TOKEN_KEY); } catch { return null; } },
  set: (v) => { try { v ? localStorage.setItem(TOKEN_KEY, v) : localStorage.removeItem(TOKEN_KEY); } catch { /* nada */ } },
};

/** 'unsupported' | 'prompt' | 'granted' | 'denied' */
export async function pushPermission() {
  const p = push();
  if (!p) return 'unsupported';
  try {
    const { receive } = await p.checkPermissions();
    return receive === 'granted' ? 'granted' : receive === 'denied' ? 'denied' : 'prompt';
  } catch {
    return 'unsupported';
  }
}

/** Pantalla local para los datos de un aviso (ver QueueWorker::sendPush). */
export function routeForNotification(data = {}, actionId = 'tap') {
  if (data.accion === 'confirmar_asistencia' && data.token) {
    return appUrl(`pages/asistencia.html#t=${encodeURIComponent(data.token)}${actionId === 'CONFIRMAR' ? '&confirmar=1' : ''}`);
  }
  const url = String(data.url || '');
  if (/^pages\/[a-z0-9\-/]+\.html(\?[\w=&%-]*)?$/i.test(url) && !url.includes('..')) return appUrl(url);
  return appUrl('pages/notificaciones.html');
}

let listening = false;

/** Listeners del plugin. Se instala en cada pantalla, antes de cualquier otra llamada. */
export function setupPushListeners() {
  const p = push();
  if (!p || listening) return;
  listening = true;
  if (platform() === 'android') {
    p.createChannel({ id: CHANNEL_ID, name: 'Citas y recordatorios', description: 'Recordatorios, cambios en tus citas y avisos de la agenda', importance: 4, visibility: 0, vibration: true }).catch(() => {});
  }
  p.addListener('pushNotificationActionPerformed', ({ actionId, notification }) => {
    location.href = routeForNotification((notification && notification.data) || {}, actionId);
  });
  p.addListener('pushNotificationReceived', () => {
    // Con la app abierta, Android no muestra el aviso: se actualiza la campana de la app.
    document.dispatchEvent(new CustomEvent('avisos:nuevo'));
  });
}

function waitForToken(p) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('El teléfono no entregó el token de notificaciones. Intenta de nuevo más tarde.')), 20000);
    p.addListener('registration', ({ value }) => { clearTimeout(timer); resolve(value); });
    p.addListener('registrationError', ({ error }) => { clearTimeout(timer); reject(new Error(error || 'No se pudieron activar las notificaciones.')); });
    p.register().catch((e) => { clearTimeout(timer); reject(e); });
  });
}

/**
 * Activa los avisos en este teléfono y registra el token en la cuenta.
 * Con ask = false solo renueva el registro si el permiso ya estaba concedido (al abrir la app).
 * Devuelve el estado final del permiso.
 */
export async function enablePush({ ask = true } = {}) {
  const p = push();
  if (!p) return 'unsupported';
  setupPushListeners();
  let state = await pushPermission();
  if (state === 'prompt' && ask) {
    const { receive } = await p.requestPermissions();
    state = receive === 'granted' ? 'granted' : 'denied';
  }
  if (state !== 'granted') return state;
  const token = await waitForToken(p);
  // En iOS sin Firebase llega el token de APNs (64 hex): FCM no lo acepta, no se registra.
  if (platform() === 'ios' && /^[0-9a-f]{64}$/i.test(token)) {
    throw new Error('Esta versión de la app no tiene configurados los avisos push.');
  }
  let version = null;
  try { version = (await plugin('App').getInfo()).version; } catch { /* sin versión */ }
  await api('/me/dispositivos', { method: 'POST', body: { token, plataforma: platform(), app_version: version ? String(version).slice(0, 20) : null } });
  lastToken.set(token);
  try { sessionStorage.setItem(SYNC_KEY, '1'); } catch { /* nada */ }
  return 'granted';
}

/** Al abrir la app con sesión: renueva el token una vez por arranque si ya hay permiso. */
export async function syncPush() {
  if (!isNative()) return;
  setupPushListeners();
  try { if (sessionStorage.getItem(SYNC_KEY)) return; } catch { /* nada */ }
  if ((await pushPermission()) !== 'granted') return;
  await enablePush({ ask: false });
}

/** Al cerrar sesión: este teléfono deja de recibir los avisos de la cuenta. */
export async function unregisterPush() {
  const token = lastToken.get();
  try {
    if (token) await api('/me/dispositivos', { method: 'DELETE', body: { token } });
  } catch { /* sin conexión: el backend lo desactiva cuando FCM rechace el token */ }
  lastToken.set(null);
  try { sessionStorage.removeItem(SYNC_KEY); } catch { /* nada */ }
  const p = push();
  if (p) { try { await p.unregister(); } catch { /* nada */ } }
}
