import { api, ApiError } from '../api.js';
import { config } from '../config.js';
import { plugin } from '../native.js';
import { escapeHtml } from '../ui.js';
import { icon } from '../icons.js';
import {
  money, minutes, safeUrl, coverHtml, avatarHtml, emptyHtml, toast, toggleFavorite,
  mountTabbar, rememberOrigin, page,
} from '../marketplace.js';

/**
 * Perfil público de una barbería: servicios, equipo, fotos, ubicación y contacto.
 * Se abre desde el marketplace (?slug=…&o=marketplace) o por el enlace directo /b/{slug}
 * (el servidor redirige aquí; ?o=qr cuando se escaneó el código QR).
 */
document.querySelectorAll('[data-icon]').forEach((el) => { el.innerHTML = icon(el.dataset.icon); });
mountTabbar('explorar');

const $ = (s) => document.querySelector(s);
const params = new URLSearchParams(location.search);
const slug = (params.get('slug') || '').toLowerCase();
let data = null;

if (history.length > 1 && document.referrer && new URL(document.referrer).origin === location.origin) {
  $('[data-back]').addEventListener('click', (e) => { e.preventDefault(); history.back(); });
}

const bookUrl = (extra = '') => page(`reservar.html?slug=${encodeURIComponent(slug)}${extra}`);

function renderInfo(b) {
  document.title = `${b.nombre} · BARBERAPP`;
  $('[data-cover]').innerHTML = coverHtml({ ...b, color: b.colores.primario }, `Portada de ${b.nombre}`);
  const principal = b.sucursales.find((s) => s.es_principal) || b.sucursales[0];
  const abierta = b.sucursales.some((s) => s.estado.abierto);
  const precios = b.servicios.map((s) => Number(s.precio));
  const estado = principal ? statusText(principal.estado) : '';
  const wa = safeUrl(b.contacto.whatsapp_url);
  $('[data-info]').innerHTML = `
    ${avatarHtml(b.nombre, b.logo_url, 'avatar--lg')}
    <h1>${escapeHtml(b.nombre)}</h1>
    <p class="hero__meta">
      ${principal ? `<span>${icon('pin')}${escapeHtml(principal.distrito)}${b.sucursales.length > 1 ? ` · ${b.sucursales.length} sedes` : ''}</span>` : ''}
      <span class="status-dot" data-open="${abierta}">${abierta ? 'Abierto ahora' : escapeHtml(estado || 'Cerrado ahora')}</span>
      ${precios.length ? `<span>${icon('scissors')}Desde ${money(Math.min(...precios))}</span>` : ''}
    </p>
    ${b.descripcion ? `<p class="hero__desc">${escapeHtml(b.descripcion)}</p>` : ''}
    <div class="actions">
      ${b.servicios.length ? `<a class="btn" href="${bookUrl()}">${icon('calendar')}Reservar</a>` : ''}
      ${wa ? `<a class="btn btn--ghost" href="${escapeHtml(wa)}" target="_blank" rel="noopener noreferrer">${icon('chat')}WhatsApp</a>` : ''}
      <button class="btn btn--ghost" type="button" data-qr>${icon('qr')}<span class="sr-only">Ver </span>QR</button>
    </div>`;
  const favBtn = $('[data-fav-hero]');
  favBtn.setAttribute('aria-pressed', String(!!b.favorito));
  favBtn.setAttribute('aria-label', b.favorito ? 'Quitar de favoritas' : 'Guardar en favoritas');
}

function statusText(st) {
  if (st.abierto) return `Abierto · cierra ${st.cierra_a}`;
  if (!st.proxima_apertura) return 'Sin horario publicado';
  const today = new Date().toLocaleDateString('en-CA'); // AAAA-MM-DD local
  const day = st.proxima_apertura.fecha === today ? 'hoy' : st.proxima_apertura.dia_nombre;
  return `Cerrado · abre ${day} ${st.proxima_apertura.hora}`;
}

function renderServices(b) {
  const el = $('[data-servicios]');
  if (!b.servicios.length) {
    el.innerHTML = emptyHtml('scissors', 'Sin servicios publicados', 'Esta barbería todavía no publicó sus servicios.');
    return;
  }
  const groups = new Map();
  b.servicios.forEach((s) => {
    const k = s.categoria || 'Servicios';
    if (!groups.has(k)) groups.set(k, []);
    groups.get(k).push(s);
  });
  el.innerHTML = [...groups].map(([cat, list]) => `
    <div class="svc-group">
      <h3>${escapeHtml(cat)}</h3>
      ${list.map((s) => `
        <article class="svc">
          <div>
            <p class="svc__name">${escapeHtml(s.nombre)}</p>
            ${s.descripcion ? `<p class="svc__desc">${escapeHtml(s.descripcion)}</p>` : ''}
            <p class="svc__meta"><span>${icon('clock')}${minutes(s.duracion_minutos)}</span>${s.barberos.length ? `<span>${icon('user')}${s.barberos.length} ${s.barberos.length === 1 ? 'barbero' : 'barberos'}</span>` : ''}</p>
          </div>
          <div class="svc__side">
            <span class="svc__price">${money(s.precio, s.moneda)}</span>
            ${s.barberos.length ? `<a class="btn btn--sm" href="${bookUrl(`&servicio=${s.id}`)}" aria-label="Reservar ${escapeHtml(s.nombre)}">Reservar</a>` : '<small class="muted">No disponible en línea</small>'}
          </div>
        </article>`).join('')}
    </div>`).join('') + (b.servicios.some((s) => b.barberos.some((x) => x.servicios.some((y) => y.id === s.id && y.precio !== s.precio)))
    ? '<p class="hint">Algunos barberos tienen un precio distinto para ciertos servicios; lo verás al elegirlo.</p>' : '');
}

function renderTeam(b) {
  const el = $('[data-equipo]');
  if (!b.barberos.length) {
    el.innerHTML = emptyHtml('user', 'Equipo por publicar', 'Esta barbería aún no presenta a su equipo.');
    return;
  }
  const servName = new Map(b.servicios.map((s) => [s.id, s.nombre]));
  const sede = new Map(b.sucursales.map((s) => [s.id, s.nombre]));
  el.innerHTML = `<div class="team">${b.barberos.map((x) => `
    <article class="barber">
      ${avatarHtml(x.nombre, x.foto_url)}
      <div>
        <h3>${escapeHtml(x.nombre)}</h3>
        ${x.especialidad ? `<p class="barber__specialty">${escapeHtml(x.especialidad)}</p>` : ''}
        ${b.sucursales.length > 1 ? `<p>${icon('pin')} ${escapeHtml(sede.get(x.sucursal_id) || '')}</p>` : ''}
        ${x.biografia ? `<p>${escapeHtml(x.biografia)}</p>` : ''}
        ${x.servicios.length ? `<ul class="tags">${x.servicios.map((s) => `<li>${escapeHtml(servName.get(s.id) || '')}</li>`).join('')}</ul>` : ''}
        ${x.reservas_online && x.servicios.length
          ? `<a class="btn btn--sm btn--ghost" href="${bookUrl(`&barbero=${x.id}`)}">Reservar con ${escapeHtml(x.nombre.split(' ')[0])}</a>`
          : '<p class="muted">Atiende solo en el local</p>'}
      </div>
    </article>`).join('')}</div>`;
}

function renderPhotos(b) {
  const el = $('[data-fotos]');
  const fotos = b.fotos.filter((f) => safeUrl(f.url));
  el.innerHTML = fotos.length
    ? `<div class="gallery">${fotos.map((f, i) => `<button type="button" data-photo="${i}" aria-label="Ampliar: ${escapeHtml(f.alt)}"><img src="${escapeHtml(safeUrl(f.url))}" alt="${escapeHtml(f.alt)}" width="${f.ancho}" height="${f.alto}" loading="lazy" decoding="async"></button>`).join('')}</div>`
    : emptyHtml('camera', 'Sin fotos todavía', 'La barbería aún no ha subido fotos de su local o sus trabajos.');
  el.dataset.photos = JSON.stringify(fotos.map((f) => ({ url: safeUrl(f.url), alt: f.alt })));
}

function mapsLinks(s) {
  const q = s.coordenadas ? `${s.coordenadas.lat},${s.coordenadas.lng}` : `${s.direccion}, ${s.distrito}, ${s.provincia}, Perú`;
  const links = [`<a class="btn btn--sm" href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(q)}" target="_blank" rel="noopener noreferrer">${icon('navigation')}Cómo llegar</a>`];
  if (s.coordenadas) links.push(`<a class="btn btn--sm btn--ghost" href="https://waze.com/ul?ll=${encodeURIComponent(q)}&navigate=yes" target="_blank" rel="noopener noreferrer">Waze</a>`);
  return links.join('');
}

function renderLocation(b) {
  const el = $('[data-ubicacion]');
  const hoy = ((new Date().getDay() + 6) % 7) + 1;
  const c = b.contacto;
  const contact = [
    c.telefono ? `<a class="pill" href="tel:${escapeHtml(c.telefono)}">${icon('phone')}${escapeHtml(c.telefono)}</a>` : '',
    safeUrl(c.whatsapp_url) ? `<a class="pill" href="${escapeHtml(safeUrl(c.whatsapp_url))}" target="_blank" rel="noopener noreferrer">${icon('chat')}WhatsApp</a>` : '',
    safeUrl(c.instagram_url) ? `<a class="pill" href="${escapeHtml(safeUrl(c.instagram_url))}" target="_blank" rel="noopener noreferrer">${icon('instagram')}Instagram</a>` : '',
    safeUrl(c.facebook_url) ? `<a class="pill" href="${escapeHtml(safeUrl(c.facebook_url))}" target="_blank" rel="noopener noreferrer">${icon('external')}Facebook</a>` : '',
    safeUrl(c.tiktok_url) ? `<a class="pill" href="${escapeHtml(safeUrl(c.tiktok_url))}" target="_blank" rel="noopener noreferrer">${icon('external')}TikTok</a>` : '',
    safeUrl(c.sitio_web) ? `<a class="pill" href="${escapeHtml(safeUrl(c.sitio_web))}" target="_blank" rel="noopener noreferrer">${icon('globe')}Sitio web</a>` : '',
  ].filter(Boolean).join('');

  el.innerHTML = b.sucursales.map((s) => `
    <article class="branch">
      <div class="branch__head">
        <h3>${escapeHtml(s.nombre)}</h3>
        <span class="status-dot" data-open="${s.estado.abierto}">${escapeHtml(statusText(s.estado))}</span>
      </div>
      <address>${escapeHtml(s.direccion)}, ${escapeHtml(s.distrito)}
        ${s.referencia ? `<small>Referencia: ${escapeHtml(s.referencia)}</small>` : ''}
        <small>${escapeHtml(s.provincia)}, ${escapeHtml(s.departamento)}</small></address>
      <div class="contact-list">
        ${mapsLinks(s)}
        ${s.telefono ? `<a class="btn btn--sm btn--ghost" href="tel:${escapeHtml(s.telefono)}">${icon('phone')}Llamar</a>` : ''}
      </div>
      <table class="hours"><caption class="sr-only">Horario de atención de ${escapeHtml(s.nombre)}</caption><tbody>
        ${s.horarios.map((h) => `<tr data-today="${h.dia === hoy}"><th scope="row">${escapeHtml(h.dia_nombre)}</th><td>${h.tramos.length ? h.tramos.map((t) => `${t.apertura} – ${t.cierre}`).join(', ') : 'Cerrado'}</td></tr>`).join('')}
      </tbody></table>
    </article>`).join('')
    + (contact ? `<article class="branch"><h3>Contacto</h3><div class="contact-list">${contact}</div></article>` : '')
    + (b.politica && b.politica.texto ? `<article class="branch"><h3>Política de cancelación</h3><p class="muted">${escapeHtml(b.politica.texto)}</p></article>` : '');
}

// ---------- pestañas accesibles (flechas izquierda/derecha)
const tabs = [...document.querySelectorAll('[role="tab"]')];
function selectTab(tab, focus = false) {
  tabs.forEach((t) => {
    const on = t === tab;
    t.setAttribute('aria-selected', String(on));
    t.tabIndex = on ? 0 : -1;
    document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
  });
  if (focus) tab.focus();
  history.replaceState(null, '', `${location.pathname}${location.search}#${tab.id.replace('tab-', '')}`);
}
tabs.forEach((t, i) => {
  t.addEventListener('click', () => selectTab(t));
  t.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight') selectTab(tabs[(i + 1) % tabs.length], true);
    if (e.key === 'ArrowLeft') selectTab(tabs[(i - 1 + tabs.length) % tabs.length], true);
  });
});

// ---------- acciones
async function share() {
  const url = data.enlace;
  try {
    const nativeShare = plugin('Share');
    if (nativeShare) {
      await nativeShare.share({ title: data.nombre, text: `Reserva en ${data.nombre} con BARBERAPP`, url, dialogTitle: 'Compartir barbería' });
    } else if (navigator.share) {
      await navigator.share({ title: data.nombre, text: `Reserva en ${data.nombre} con BARBERAPP`, url });
    } else {
      await navigator.clipboard.writeText(url);
      toast('Enlace copiado');
    }
  } catch (e) {
    if (e && e.name !== 'AbortError' && !/cancel/i.test(String(e.message || ''))) toast('No se pudo compartir. Copia el enlace: ' + url, 'error');
  }
}

function openQr() {
  const base = `${config.API_BASE_URL}/marketplace/barberias/${encodeURIComponent(slug)}/qr`;
  $('[data-qr-body]').innerHTML = `
    <img src="${escapeHtml(base)}?formato=svg" alt="Código QR que abre ${escapeHtml(data.nombre)} en BARBERAPP" width="260" height="260">
    <p class="muted">Escanéalo con la cámara del celular para abrir esta barbería, aunque no tengas la app.</p>
    <div class="contact-list">
      <a class="btn btn--sm" href="${escapeHtml(base)}?formato=png&tam=16&descargar=1" data-api-download="${escapeHtml(base.slice(config.API_BASE_URL.length))}?formato=png&tam=16&descargar=1" data-filename="qr-barberia.png">${icon('download')}Descargar PNG</a>
      <a class="btn btn--sm btn--ghost" href="${escapeHtml(base)}?formato=svg&descargar=1" data-api-download="${escapeHtml(base.slice(config.API_BASE_URL.length))}?formato=svg&descargar=1" data-filename="qr-barberia.svg">${icon('download')}SVG</a>
    </div>`;
  $('[data-qr-dialog]').showModal();
}

document.addEventListener('click', async (e) => {
  if (e.target.closest('[data-reload]')) location.reload();
  if (e.target.closest('[data-share]')) share();
  if (e.target.closest('[data-qr]')) openQr();
  if (e.target.closest('[data-close-qr]')) $('[data-qr-dialog]').close();
  const photo = e.target.closest('[data-photo]');
  if (photo) {
    const list = JSON.parse($('[data-fotos]').dataset.photos || '[]');
    const f = list[Number(photo.dataset.photo)];
    const lb = $('[data-lightbox]');
    lb.querySelector('img').src = f.url;
    lb.querySelector('img').alt = f.alt;
    lb.showModal();
  }
  if (e.target.closest('[data-close-lightbox]') || e.target === $('[data-lightbox]')) $('[data-lightbox]').close();
  const fav = e.target.closest('[data-fav-hero]');
  if (fav && data) {
    fav.disabled = true;
    try {
      const next = await toggleFavorite(slug, fav.getAttribute('aria-pressed') === 'true');
      if (next !== null) {
        fav.setAttribute('aria-pressed', String(next));
        fav.setAttribute('aria-label', next ? 'Quitar de favoritas' : 'Guardar en favoritas');
      }
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      fav.disabled = false;
    }
  }
});

async function load() {
  if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(slug)) {
    showError('Enlace inválido', 'Revisa el enlace o busca la barbería en el marketplace.');
    return;
  }
  try {
    data = await api(`/marketplace/barberias/${encodeURIComponent(slug)}`);
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) showError('Barbería no disponible', e.message);
    else showError('No pudimos cargar la barbería', e.message, true);
    return;
  }
  rememberOrigin(slug, params.get('o') || 'enlace');
  renderInfo(data);
  renderServices(data);
  renderTeam(data);
  renderPhotos(data);
  renderLocation(data);
  $('[data-content]').hidden = false;
  const initial = tabs.find((t) => t.id === `tab-${location.hash.slice(1)}`);
  if (initial) selectTab(initial);
}

function showError(title, text, retry = false) {
  $('[data-cover]').innerHTML = '<div class="cover-fallback"><span>B</span></div>';
  $('[data-info]').innerHTML = '';
  $('[data-error]').hidden = false;
  $('[data-error]').innerHTML = `<div class="section">${emptyHtml('store', title, text,
    retry ? '<button class="btn" type="button" data-reload>Reintentar</button>' : `<a class="btn" href="${page('explorar.html')}">Explorar barberías</a>`)}</div>`;
}

load();
