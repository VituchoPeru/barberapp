import { api, ApiError } from './api.js';
import { escapeHtml } from './ui.js';
import { icon } from './icons.js';
import { mountBell } from './avisos.js';

/** Utilidades compartidas por las pantallas del marketplace (cliente). */

// Las páginas del cliente viven en www/pages/; las de cuenta en www/pages/auth/.
const PAGES = new URL('../pages/', import.meta.url);
export const page = (path) => new URL(path, PAGES).href;

export const money = (amount, currency = 'PEN') => {
  if (amount === null || amount === undefined) return '';
  const n = Number(amount);
  return currency === 'PEN' ? `S/ ${n.toFixed(2)}` : `${currency} ${n.toFixed(2)}`;
};

export const minutes = (m) => (m < 60 ? `${m} min` : `${Math.floor(m / 60)} h${m % 60 ? ` ${m % 60} min` : ''}`);

export const km = (d) => (d === null || d === undefined ? '' : d < 1 ? `${Math.round(d * 1000)} m` : `${d.toFixed(1).replace('.0', '')} km`);

/** Solo URLs http(s): evita javascript:, data: u otros esquemas en atributos. */
export function safeUrl(url) {
  if (typeof url !== 'string' || url === '') return null;
  try {
    const u = new URL(url, location.href);
    return u.protocol === 'https:' || u.protocol === 'http:' ? u.href : null;
  } catch { return null; }
}

export function initials(name) {
  const words = String(name || '').replace(/barber[ií]a/gi, '').trim().split(/\s+/).filter(Boolean);
  return ((words[0] || 'B')[0] + (words[1] ? words[1][0] : '')).toUpperCase();
}

/** Portada real o, si no hay foto, degradado con monograma (nunca imágenes genéricas). */
export function coverHtml(item, alt = '') {
  const url = safeUrl(item.portada_url);
  if (url) return `<img src="${escapeHtml(url)}" alt="${escapeHtml(alt)}" loading="lazy" decoding="async">`;
  return `<div class="cover-fallback" style="--brand:${escapeHtml(item.color || '#C9A45C')}" aria-hidden="true"><span>${escapeHtml(initials(item.nombre))}</span></div>`;
}

export function avatarHtml(name, url, cls = '') {
  const u = safeUrl(url);
  return `<span class="avatar ${cls}" aria-hidden="true">${u ? `<img src="${escapeHtml(u)}" alt="" loading="lazy">` : escapeHtml(initials(name))}</span>`;
}

/** Enlace a la ficha de una barbería; o = origen (marketplace, enlace, qr) para la Fase 5. */
export const profileUrl = (slug, origen = 'marketplace') => page(`barberia.html?slug=${encodeURIComponent(slug)}&o=${origen}`);

export function cardHtml(b, { mini = false } = {}) {
  const ubic = b.ubicacion ? `${b.ubicacion.distrito}${b.sucursales > 1 ? ` · ${b.sucursales} sedes` : ''}` : '';
  const fav = b.favorito === null || b.favorito === undefined ? false : b.favorito;
  return `<article class="card${mini ? ' card--mini' : ''}" data-slug="${escapeHtml(b.slug)}">
    <a class="card__link" href="${profileUrl(b.slug)}" aria-label="${escapeHtml(b.nombre)}"></a>
    <div class="card__cover">${coverHtml(b)}
      <span class="card__logo">${avatarHtml(b.nombre, b.logo_url)}</span>
    </div>
    <button class="icon-btn card__fav" type="button" data-fav="${escapeHtml(b.slug)}" aria-pressed="${fav}" aria-label="${fav ? 'Quitar de favoritas' : 'Guardar en favoritas'}">${icon('heart')}</button>
    <div class="card__body">
      <h3 class="card__name">${escapeHtml(b.nombre)}</h3>
      <p class="card__meta">
        ${ubic ? `<span>${icon('pin')}${escapeHtml(ubic)}</span>` : ''}
        ${b.distancia_km !== null && b.distancia_km !== undefined ? `<span>${icon('navigation')}a ${km(b.distancia_km)}</span>` : ''}
      </p>
      <p class="card__meta">
        <span class="status-dot" data-open="${b.abierto_ahora}">${b.abierto_ahora ? 'Abierto ahora' : 'Cerrado ahora'}</span>
        ${b.precio_desde ? `<span class="card__price">Desde ${money(b.precio_desde, b.moneda)}</span>` : ''}
      </p>
      ${!mini && b.tipos_servicio && b.tipos_servicio.length ? `<ul class="tags">${b.tipos_servicio.map((t) => `<li>${escapeHtml(t.nombre)}</li>`).join('')}</ul>` : ''}
    </div>
  </article>`;
}

export const skeletonCards = (n = 6, mini = false) => Array.from({ length: n }, () => `<div class="sk ${mini ? 'sk--mini' : 'sk--card'}" aria-hidden="true"></div>`).join('');

/** Estado vacío o de error. level = 1 cuando reemplaza a toda la pantalla (es su título principal). */
export function emptyHtml(iconName, title, text, actionHtml = '', level = 2) {
  const h = level === 1 ? 'h1' : 'h2';
  return `<div class="empty">${icon(iconName)}<${h}>${escapeHtml(title)}</${h}><p>${escapeHtml(text)}</p>${actionHtml}</div>`;
}

let toastTimer;
export function toast(message, type = 'ok') {
  let el = document.querySelector('.toast');
  if (!el) {
    el = document.createElement('div');
    el.className = 'toast';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    document.body.appendChild(el);
  }
  el.textContent = message;
  el.dataset.type = type;
  el.dataset.show = 'true';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { el.dataset.show = 'false'; }, 2800);
}

/** Usuario actual (o null) cacheado por página. */
let mePromise;
export function me() {
  if (!mePromise) {
    mePromise = api('/me').catch((e) => {
      if (e instanceof ApiError && e.status === 401) return null;
      throw e;
    });
  }
  return mePromise;
}

export function loginUrl(next = location.pathname.split('/').pop() + location.search) {
  return page(`auth/login.html?next=${encodeURIComponent('../' + next)}`);
}

/**
 * Guarda o quita una favorita. Sin sesión, lleva a iniciar sesión y vuelve a la misma página.
 * Devuelve el nuevo estado (true/false) o null si se redirigió.
 */
export async function toggleFavorite(slug, current) {
  const user = await me();
  if (!user) {
    location.assign(loginUrl());
    return null;
  }
  if (current) {
    await api(`/me/favoritos/${encodeURIComponent(slug)}`, { method: 'DELETE' });
    toast('Quitada de tus favoritas');
    return false;
  }
  await api(`/me/favoritos/${encodeURIComponent(slug)}`, { method: 'PUT', body: {} });
  toast('Guardada en tus favoritas');
  return true;
}

/** Delegación: corazones de las tarjetas. */
export function bindFavoriteButtons(root = document) {
  root.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-fav]');
    if (!btn) return;
    ev.preventDefault();
    const current = btn.getAttribute('aria-pressed') === 'true';
    btn.disabled = true;
    try {
      const next = await toggleFavorite(btn.dataset.fav, current);
      if (next !== null) {
        // Sincroniza todos los corazones de la misma barbería en la página.
        document.querySelectorAll(`[data-fav="${CSS.escape(btn.dataset.fav)}"]`).forEach((b) => {
          b.setAttribute('aria-pressed', String(next));
          b.setAttribute('aria-label', next ? 'Quitar de favoritas' : 'Guardar en favoritas');
        });
        btn.dispatchEvent(new CustomEvent('favorite-change', { bubbles: true, detail: { slug: btn.dataset.fav, favorito: next } }));
      }
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      btn.disabled = false;
    }
  });
}

/** Barra de navegación inferior del cliente. */
export function mountTabbar(active) {
  const items = [
    ['inicio', 'Inicio', 'home', page('inicio.html')],
    ['explorar', 'Explorar', 'compass', page('explorar.html')],
    ['favoritos', 'Favoritas', 'heart', page('favoritos.html')],
    ['citas', 'Citas', 'calendar', page('mis-citas.html')],
    ['cuenta', 'Cuenta', 'user', page('auth/cuenta.html')],
  ];
  const nav = document.createElement('nav');
  nav.className = 'tabbar';
  nav.setAttribute('aria-label', 'Navegación principal');
  nav.innerHTML = items.map(([key, label, ic, href]) =>
    `<a href="${href}"${key === active ? ' aria-current="page"' : ''}>${icon(ic)}<span>${label}</span></a>`).join('');
  document.body.appendChild(nav);
  // Sin sesión, "Cuenta" lleva a iniciar sesión. Con sesión, campana de avisos (Fase 8).
  me().then((u) => {
    if (!u) nav.lastElementChild.href = page('auth/login.html');
    else if (active !== '') mountBell();
  }).catch(() => {});
}

/**
 * Ubicación del usuario SOLO con su autorización. Se redondea a ~100 m y no se guarda
 * en la URL (si comparte el enlace, no comparte su ubicación). Se recuerda en la pestaña.
 */
const GEO_KEY = 'barberapp.geo';
export function savedLocation() {
  try { return JSON.parse(sessionStorage.getItem(GEO_KEY) || 'null'); } catch { return null; }
}
export function forgetLocation() {
  try { sessionStorage.removeItem(GEO_KEY); } catch { /* sin almacenamiento */ }
}
export function requestLocation() {
  return new Promise((resolve, reject) => {
    if (!('geolocation' in navigator)) {
      reject(new Error('Tu navegador no permite compartir la ubicación. Elige un distrito.'));
      return;
    }
    navigator.geolocation.getCurrentPosition((pos) => {
      const loc = { lat: Number(pos.coords.latitude.toFixed(3)), lng: Number(pos.coords.longitude.toFixed(3)) };
      try { sessionStorage.setItem(GEO_KEY, JSON.stringify(loc)); } catch { /* modo privado */ }
      resolve(loc);
    }, (err) => {
      reject(new Error(err.code === 1
        ? 'No diste permiso para usar tu ubicación. Puedes elegir un distrito en los filtros.'
        : 'No pudimos obtener tu ubicación. Intenta de nuevo o elige un distrito.'));
    }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
  });
}

/** Recuerda cómo llegó el cliente a una barbería (marketplace, enlace o QR) para la reserva. */
export function rememberOrigin(slug, origen) {
  const valid = ['marketplace', 'enlace', 'qr', 'app'];
  try { sessionStorage.setItem(`barberapp.origen.${slug}`, valid.includes(origen) ? origen : 'enlace'); } catch { /* sin almacenamiento */ }
}
export function originFor(slug) {
  try { return sessionStorage.getItem(`barberapp.origen.${slug}`) || 'marketplace'; } catch { return 'marketplace'; }
}
