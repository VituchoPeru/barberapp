import { isNative, initNativeShell } from './native.js';
import { mobileSession } from './api.js';

/**
 * Arranque común de todas las pantallas (Fase 12). Cada HTML lo carga con
 * <script type="module" src="…/js/shell.js">.
 *
 * - App nativa: barra de estado, botón Atrás, enlaces directos y avisos push.
 * - Web: registra el service worker (PWA) y ofrece instalar la app.
 * - Siempre: aviso de "sin conexión".
 */

// ================================================================ app nativa / service worker

if (isNative()) {
  initNativeShell();
  import('./push.js').then(async (push) => {
    push.setupPushListeners();
    if (await mobileSession.refreshToken()) await push.syncPush();
  }).catch(() => { /* los avisos no deben romper la pantalla */ });
} else if ('serviceWorker' in navigator && window.isSecureContext) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register(new URL('../sw.js', import.meta.url), { scope: new URL('../', import.meta.url).pathname })
      .catch(() => { /* sin PWA: la web sigue funcionando igual */ });
  });
}

// En la app, los enlaces de descarga de la API (p. ej. el QR) se guardan con "Compartir":
// la WebView no descarga archivos por sí sola.
document.addEventListener('click', (event) => {
  const link = event.target.closest('a[data-api-download]');
  if (!link || !isNative()) return;
  event.preventDefault();
  import('./descargas.js')
    .then(({ downloadFromApi }) => downloadFromApi(link.dataset.apiDownload, { fallbackName: link.dataset.filename || 'archivo', title: link.textContent.trim() || 'Compartir' }))
    .catch((e) => alert(e.message || 'No se pudo descargar el archivo.'));
});

// ================================================================ conexión

let banner;
function renderConnectivity() {
  const offline = navigator.onLine === false;
  document.documentElement.classList.toggle('is-offline', offline);
  if (!banner) {
    if (!offline) return;
    banner = document.createElement('div');
    banner.className = 'net-banner';
    banner.setAttribute('role', 'status');
    banner.innerHTML = '<strong>Sin conexión.</strong> Puedes ver lo que ya cargaste; para reservar o cambiar una cita necesitas internet.';
    document.body.prepend(banner);
  }
  banner.hidden = !offline;
}
window.addEventListener('online', renderConnectivity);
window.addEventListener('offline', renderConnectivity);
if (document.body) renderConnectivity();
else document.addEventListener('DOMContentLoaded', renderConnectivity);

// ================================================================ instalar la PWA

const DISMISS_KEY = 'barberapp.instalar.descartado';
const DISMISS_DAYS = 30;
let deferredPrompt = null;

/** Ya abierta como app instalada (PWA o Capacitor). */
export const isInstalled = () => isNative()
  || window.matchMedia('(display-mode: standalone)').matches
  || window.navigator.standalone === true;

const isIOS = () => /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

/**
 * 'instalada' | 'disponible' (el navegador ofrece instalar con un toque) |
 * 'ios' (Safari: Compartir → Agregar a inicio) | 'manual' (menú del navegador)
 */
export function installState() {
  if (isInstalled()) return 'instalada';
  if (deferredPrompt) return 'disponible';
  if (isIOS()) return 'ios';
  return 'manual';
}

/** Abre el diálogo de instalación del navegador. Devuelve true si la persona aceptó. */
export async function promptInstall() {
  if (!deferredPrompt) return false;
  const prompt = deferredPrompt;
  deferredPrompt = null;
  prompt.prompt();
  const { outcome } = await prompt.userChoice;
  renderInstallSlots();
  return outcome === 'accepted';
}

function dismissed() {
  try {
    const at = Number(localStorage.getItem(DISMISS_KEY) || 0);
    return at > Date.now() - DISMISS_DAYS * 86400000;
  } catch { return false; }
}

const pageRoot = new URL('../', import.meta.url);

/**
 * Tarjeta "Instala BARBERAPP" en los elementos [data-install] de la página (p. ej. Inicio).
 * Solo aparece si el navegador permite instalar o en iPhone, y se puede descartar por 30 días.
 */
function renderInstallSlots() {
  document.querySelectorAll('[data-install]').forEach((slot) => {
    const state = installState();
    if (state === 'instalada' || state === 'manual' || dismissed()) {
      slot.hidden = true;
      slot.innerHTML = '';
      return;
    }
    slot.hidden = false;
    slot.innerHTML = `<div class="install-card">
        <img src="${new URL('assets/icons/icon-192.png', pageRoot)}" alt="" width="48" height="48">
        <div><p class="install-card__title">Instala BARBERAPP</p>
          <p class="install-card__text">Ábrela desde tu pantalla de inicio, a pantalla completa y más rápido.</p></div>
        <div class="install-card__actions">
          ${state === 'disponible'
            ? '<button class="btn btn--sm" type="button" data-install-go>Instalar</button>'
            : `<a class="btn btn--sm" href="${new URL('pages/instalar.html', pageRoot)}">Cómo instalar</a>`}
          <button class="link-btn" type="button" data-install-dismiss>Ahora no</button>
        </div>
      </div>`;
  });
}

window.addEventListener('beforeinstallprompt', (event) => {
  event.preventDefault(); // la app muestra su propia invitación, en el momento oportuno
  deferredPrompt = event;
  renderInstallSlots();
  document.dispatchEvent(new CustomEvent('pwa:estado'));
});
window.addEventListener('appinstalled', () => {
  deferredPrompt = null;
  renderInstallSlots();
  document.dispatchEvent(new CustomEvent('pwa:estado'));
});
document.addEventListener('click', (event) => {
  if (event.target.closest('[data-install-go]')) promptInstall();
  if (event.target.closest('[data-install-dismiss]')) {
    try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch { /* nada */ }
    renderInstallSlots();
  }
});
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', renderInstallSlots);
else renderInstallSlots();
