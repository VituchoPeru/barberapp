/* BARBERAPP — service worker de la PWA (Fase 12).
 *
 * Qué guarda:   solo archivos estáticos del frontend (HTML, CSS, JS, fuentes, iconos).
 * Qué NO guarda: nada de la API (/api/…): ni citas, ni datos personales, ni respuestas de
 *                reservar. Las peticiones POST/PUT/DELETE nunca pasan por la caché, así que
 *                una reserva solo se confirma con la respuesta real del servidor.
 *
 * Estrategias:
 *   - Páginas (navegación): red primero; sin red, la copia guardada; si no hay, offline.html.
 *   - CSS, JS, fuentes e imágenes del frontend: la copia guardada al instante y se actualiza
 *     en segundo plano (stale-while-revalidate).
 *
 * Al publicar una versión nueva, sube VERSION: el navegador instala el worker nuevo, vuelve a
 * descargar el núcleo y borra las cachés anteriores.
 */
const VERSION = 'fase14-v4';
const CORE = `barberapp-core-${VERSION}`;
const RUNTIME = `barberapp-runtime-${VERSION}`;
const RUNTIME_MAX = 120;

// Núcleo: lo necesario para abrir la app y mostrar la pantalla sin conexión, y los textos
// legales (Fase 13), que se pueden leer sin conexión.
const CORE_FILES = [
  'index.html',
  'offline.html',
  'manifest.webmanifest',
  'css/tokens.css', 'css/base.css', 'css/auth.css', 'css/app.css',
  'js/app.js', 'js/api.js', 'js/config.js', 'js/runtime-config.js', 'js/native.js', 'js/shell.js',
  'js/ui.js', 'js/icons.js', 'js/auth.js', 'js/marketplace.js', 'js/avisos.js',
  'assets/fonts/manrope-latin-wght-normal.woff2', 'assets/fonts/manrope-latin-ext-wght-normal.woff2',
  'assets/icons/icon-192.png', 'assets/icons/icon-512.png', 'assets/icons/favicon.svg',
  'pages/inicio.html', 'pages/explorar.html', 'pages/mis-citas.html',
  'css/legal.css', 'js/pages/legal.js', 'pages/legal/privacidad.html', 'pages/legal/terminos.html', 'pages/legal/eliminar-cuenta.html',
];

const SCOPE = new URL(self.registration.scope);

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CORE);
    await cache.addAll(CORE_FILES.map((f) => new Request(new URL(f, SCOPE), { cache: 'reload' })));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keep = [CORE, RUNTIME];
    for (const key of await caches.keys()) {
      if (key.startsWith('barberapp-') && !keep.includes(key)) await caches.delete(key);
    }
    await self.clients.claim();
  })());
});

/** Solo GET del mismo origen, dentro del alcance del frontend y fuera de la API. */
function cacheable(request) {
  if (request.method !== 'GET') return false;
  const url = new URL(request.url);
  if (url.origin !== SCOPE.origin || !url.pathname.startsWith(SCOPE.pathname)) return false;
  if (/\/api\//.test(url.pathname) || /\/backend\//.test(url.pathname)) return false;
  if (request.headers.get('range')) return false;
  return true;
}

async function trim(cache) {
  const keys = await cache.keys();
  for (let i = 0; i < keys.length - RUNTIME_MAX; i++) await cache.delete(keys[i]);
}

async function fromCache(request) {
  // ignoreSearch: pages/cita.html?id=8 usa la misma página que pages/cita.html?id=3.
  return (await caches.match(request)) || caches.match(request, { ignoreSearch: true });
}

async function networkFirst(request) {
  try {
    const response = await fetch(request);
    if (response.ok && response.type === 'basic') {
      const cache = await caches.open(RUNTIME);
      await cache.put(request, response.clone());
      trim(cache);
    }
    return response;
  } catch {
    return (await fromCache(request)) || caches.match(new URL('offline.html', SCOPE));
  }
}

async function staleWhileRevalidate(event) {
  const { request } = event;
  const cached = await caches.match(request);
  const update = fetch(request).then(async (response) => {
    if (response.ok && response.type === 'basic') {
      const cache = await caches.open(RUNTIME);
      await cache.put(request, response.clone());
      trim(cache);
    }
    return response;
  });
  if (cached) {
    event.waitUntil(update.catch(() => {}));
    return cached;
  }
  return update;
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (!cacheable(request)) return; // la API y todo lo demás van directo a la red
  if (request.mode === 'navigate') {
    event.respondWith(networkFirst(request));
  } else {
    event.respondWith(staleWhileRevalidate(event));
  }
});

// La página pide activar una versión nueva sin esperar a cerrar todas las pestañas.
self.addEventListener('message', (event) => {
  if (event.data === 'skipWaiting') self.skipWaiting();
});
