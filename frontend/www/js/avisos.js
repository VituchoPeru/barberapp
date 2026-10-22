import { api } from './api.js';
import { icon } from './icons.js';
import { escapeHtml } from './ui.js';

/**
 * Avisos (Fase 8): campana con el número de avisos sin leer en la barra superior y utilidades
 * de la bandeja. Los avisos los programa y envía el servidor (cola + worker por cron), aunque
 * la app esté cerrada; aquí solo se consulta la bandeja cada 60 s con la pestaña visible.
 */
const PAGES = new URL('../pages/', import.meta.url);
const POLL_MS = 60000;

/** URL de una pantalla a partir de la ruta que guarda el aviso ("pages/cita.html?id=5"). */
export function avisoUrl(path, tenantSlug) {
  if (!path || !/^pages\/[a-z0-9/_-]+\.html(\?[a-z0-9=&_-]*)?$/i.test(path)) return null;
  const url = new URL(path.replace(/^pages\//, ''), PAGES);
  // Panel y agenda del barbero trabajan con la barbería en ?tenant=.
  if (tenantSlug && /\/pages\/(panel|barbero)\//.test(url.pathname)) url.searchParams.set('tenant', tenantSlug);
  return url.href;
}

export function inboxUrl(tenantSlug) {
  const url = new URL('notificaciones.html', PAGES);
  if (tenantSlug) url.searchParams.set('tenant', tenantSlug);
  return url.href;
}

/** "hace 5 min", "ayer 18:30", "12 oct" */
export function cuando(iso) {
  const d = new Date(iso);
  const diff = Math.round((Date.now() - d.getTime()) / 60000);
  if (diff < 1) return 'ahora';
  if (diff < 60) return `hace ${diff} min`;
  const hoy = new Date();
  const hora = d.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' });
  if (d.toDateString() === hoy.toDateString()) return `hoy ${hora}`;
  const ayer = new Date(hoy); ayer.setDate(hoy.getDate() - 1);
  if (d.toDateString() === ayer.toDateString()) return `ayer ${hora}`;
  return d.toLocaleDateString('es-PE', { day: 'numeric', month: 'short' });
}

function label(n) {
  return n > 0 ? `Avisos, ${n} sin leer` : 'Avisos';
}

/**
 * Agrega la campana a la barra superior (.topbar) si hay sesión. tenantSlug mantiene la
 * barbería activa al volver desde la bandeja al panel.
 */
export async function mountBell(tenantSlug = '') {
  const bar = document.querySelector('.topbar');
  if (!bar || bar.querySelector('[data-bell]')) return;
  let data;
  try {
    data = await api('/me/notificaciones/resumen');
  } catch {
    return; // sin sesión: no hay bandeja
  }
  const a = document.createElement('a');
  a.className = 'icon-btn bell';
  a.dataset.bell = '';
  a.href = inboxUrl(tenantSlug);
  a.innerHTML = `${icon('bell')}<span class="bell__badge" data-bell-count hidden></span>`;
  bar.appendChild(a);
  const paint = (n) => {
    const b = a.querySelector('[data-bell-count]');
    b.textContent = n > 99 ? '99+' : String(n);
    b.hidden = n === 0;
    a.setAttribute('aria-label', label(n));
  };
  paint(data.no_leidas);
  setInterval(async () => {
    if (document.visibilityState !== 'visible') return;
    try { paint((await api('/me/notificaciones/resumen')).no_leidas); } catch { /* sin red: se reintenta en el siguiente ciclo */ }
  }, POLL_MS);
  document.addEventListener('avisos:leidos', (e) => paint(e.detail.no_leidas));
}

export function itemHtml(n) {
  const url = avisoUrl(n.url, n.barberia && n.barberia.slug) || (n.reserva_id ? new URL(`cita.html?id=${n.reserva_id}`, PAGES).href : null);
  return `<li class="aviso${n.leida ? '' : ' aviso--nuevo'}" data-id="${n.id}">
    <span class="aviso__icon" aria-hidden="true">${icon(n.tipo === 'recordatorio' ? 'clock' : n.tipo.startsWith('bloqueo') ? 'calendar' : 'bell')}</span>
    <div class="aviso__body">
      <p class="aviso__title">${n.leida ? '' : '<span class="sr-only">Nuevo: </span>'}${escapeHtml(n.titulo || 'Aviso')}</p>
      <p class="aviso__text">${escapeHtml(n.cuerpo)}</p>
      <p class="aviso__meta">${n.barberia ? `${escapeHtml(n.barberia.nombre)} · ` : ''}<time datetime="${escapeHtml(n.fecha)}">${escapeHtml(cuando(n.fecha))}</time></p>
      <div class="aviso__actions">
        ${n.accion === 'confirmar_asistencia' ? `<button class="btn btn--sm" type="button" data-confirmar="${n.reserva_id}">${icon('check')}Confirmar asistencia</button>` : ''}
        ${url ? `<a class="btn btn--ghost btn--sm" href="${escapeHtml(url)}" data-open>${n.reserva_id ? 'Ver cita' : 'Abrir'}</a>` : ''}
        ${n.leida ? '' : '<button class="btn btn--ghost btn--sm" type="button" data-leer>Marcar como leído</button>'}
      </div>
    </div>
  </li>`;
}
