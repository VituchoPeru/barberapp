/**
 * Adaptador de funciones nativas (Capacitor) para el frontend compartido.
 *
 * El mismo HTML, CSS y JavaScript corre en el navegador, como PWA y dentro de las apps de
 * Android e iOS. Todo lo que depende del sistema operativo pasa por aquí: en web cada función
 * usa la API del navegador o no hace nada, y en la app nativa llama al plugin de Capacitor.
 *
 * Los plugins se usan sin empaquetador: Capacitor inyecta en la WebView `window.Capacitor.Plugins`
 * con un objeto por plugin instalado (ver docs/APPS_MOVILES.md).
 */

const cap = () => window.Capacitor;

/** true dentro de la app de Android o iOS. */
export const isNative = () => !!(cap() && typeof cap().isNativePlatform === 'function' && cap().isNativePlatform());

/** 'android' | 'ios' | 'web' */
export const platform = () => (isNative() ? cap().getPlatform() : 'web');

/** Objeto del plugin o null si no está instalado (o si estamos en web). */
export function plugin(name) {
  if (!isNative()) return null;
  const c = cap();
  return (c.Plugins && c.Plugins[name]) || null;
}

/** Raíz de la app (carpeta www/), calculada desde este archivo y no desde la página actual. */
export const appRoot = new URL('../', import.meta.url);

/** URL absoluta de una ruta relativa a www/ (p. ej. 'pages/cita.html?id=3'). */
export function appUrl(path) {
  return new URL(String(path).replace(/^\/+/, ''), appRoot).href;
}

// ================================================================ almacenamiento seguro

/**
 * Llavero del sistema (Keychain en iOS, Keystore en Android) mediante
 * capacitor-secure-storage-plugin. Solo guarda el refresh token de la sesión móvil.
 */
export const secureStore = {
  async get(key) {
    const p = plugin('SecureStoragePlugin');
    if (!p) return null;
    try {
      const { value } = await p.get({ key });
      return value || null;
    } catch {
      return null; // la clave no existe
    }
  },
  async set(key, value) {
    const p = plugin('SecureStoragePlugin');
    if (!p) throw new Error('El almacenamiento seguro no está disponible.');
    await p.set({ key, value });
  },
  async remove(key) {
    const p = plugin('SecureStoragePlugin');
    if (!p) return;
    try { await p.remove({ key }); } catch { /* no existía */ }
  },
};

// ================================================================ archivos

function blobToBase64(blob) {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(String(r.result).split(',')[1] || '');
    r.onerror = () => reject(r.error);
    r.readAsDataURL(blob);
  });
}

/** Nombre de archivo seguro para el sistema de archivos del teléfono. */
export function safeFileName(name, fallback = 'archivo') {
  const clean = String(name || '').replace(/[^\w.\-]+/g, '_').replace(/^\.+/, '').slice(0, 120);
  return clean || fallback;
}

/**
 * Entrega un archivo al usuario.
 * - Web y PWA: descarga con un enlace temporal.
 * - App nativa: lo guarda en la caché de la app y abre la hoja de compartir del sistema
 *   (guardar en Archivos, enviar por WhatsApp o correo, abrir con Excel o un lector de PDF…).
 * Devuelve 'descargado', 'compartido' o 'cancelado'.
 */
export async function saveFile(blob, filename, title = 'Compartir archivo') {
  const name = safeFileName(filename);
  if (!isNative()) {
    const url = URL.createObjectURL(blob);
    const a = Object.assign(document.createElement('a'), { href: url, download: name });
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1500);
    return 'descargado';
  }
  const fs = plugin('Filesystem');
  const share = plugin('Share');
  if (!fs || !share) throw new Error('Esta versión de la app no puede guardar archivos. Actualízala desde la tienda.');
  const { uri } = await fs.writeFile({ path: `exportaciones/${name}`, data: await blobToBase64(blob), directory: 'CACHE', recursive: true });
  try {
    await share.share({ title, files: [uri], dialogTitle: title });
    return 'compartido';
  } catch (e) {
    // Cerrar la hoja de compartir no es un error para el usuario.
    if (/cancel/i.test(String(e && (e.message || e.errorMessage || e)))) return 'cancelado';
    throw e;
  }
}

// ================================================================ arranque de la app nativa

let started = false;

/**
 * Ajustes de la app nativa al cargar cada pantalla: barra de estado oscura, oculta la
 * pantalla de inicio nativa, botón Atrás de Android y enlaces universales (/b/{slug}).
 */
export function initNativeShell() {
  if (started || !isNative()) return;
  started = true;
  document.documentElement.classList.add('is-native', `is-${platform()}`);

  // SystemBars viene en el núcleo de Capacitor 8: texto claro sobre el fondo negro de la app.
  // Los márgenes del notch y la barra de gestos los da CSS con env(safe-area-inset-*).
  const bars = plugin('SystemBars');
  if (bars && typeof bars.setStyle === 'function') bars.setStyle({ style: 'DARK' }).catch(() => {});
  const splash = plugin('SplashScreen');
  if (splash) splash.hide({ fadeOutDuration: 200 }).catch(() => {});

  const app = plugin('App');
  if (app) {
    // Atrás en Android: vuelve en el historial; en la pantalla de inicio cierra la app.
    app.addListener('backButton', ({ canGoBack }) => {
      const sheet = document.querySelector('dialog[open]');
      if (sheet) { sheet.close(); return; }
      if (canGoBack && history.length > 1) history.back();
      else app.exitApp();
    });
    app.addListener('appUrlOpen', ({ url }) => {
      const target = routeFromLink(url);
      if (target) location.href = target;
    });
  }
}

/**
 * Traduce un enlace web de BARBERAPP (enlace directo, aviso, verificación de correo…) a la
 * pantalla local de la app. Solo acepta rutas conocidas; el resto se ignora.
 */
export function routeFromLink(link) {
  let u;
  try { u = new URL(link); } catch { return null; }
  const slug = u.pathname.match(/\/b\/([a-z0-9]+(?:-[a-z0-9]+)*)\/?$/i);
  if (slug) {
    const q = new URLSearchParams(u.search);
    q.set('slug', slug[1].toLowerCase());
    return appUrl(`pages/barberia.html?${q}`);
  }
  const page = u.pathname.match(/\/(pages\/[a-z0-9\-/]+\.html)$/i);
  if (page && !page[1].includes('..')) return appUrl(page[1] + u.search + u.hash);
  return null;
}
