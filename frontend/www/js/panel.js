import { api } from './api.js';
import { $, escapeHtml, notice } from './ui.js';
import { icon } from './icons.js';
import { toast } from './marketplace.js';

/**
 * Utilidades del panel de la barbería (Fase 4): barbería activa, navegación entre módulos,
 * hojas modales con formularios y formatos comunes.
 *
 * La barbería se indica con ?tenant=slug (o la seleccionada en la sesión) y se envía en la
 * cabecera X-Tenant. El backend verifica la membresía y los permisos en cada petición: ocultar
 * un botón aquí es solo comodidad, nunca la protección.
 */
export const tenant = (new URLSearchParams(location.search).get('tenant') || '').toLowerCase();
export const H = tenant ? { 'X-Tenant': tenant } : {};
export const q = tenant ? `?tenant=${encodeURIComponent(tenant)}` : '';
export { toast };

export const DIAS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
export const DIAS_CORTOS = ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

/** Petición al panel con la barbería activa. */
export const papi = (path, options = {}) => api(path, { ...options, headers: { ...H, ...(options.headers || {}) } });

const MODULES = [
  ['servicios', 'Servicios', 'scissors', 'servicios.gestionar'],
  ['barberos', 'Barberos', 'user', ['barberos.gestionar', 'horarios.gestionar']],
  ['ausencias', 'Ausencias', 'calendar', 'bloqueos.gestionar'],
  ['sucursales', 'Sucursales', 'store', 'sucursales.gestionar'],
  ['perfil-publico', 'Perfil público', 'globe', 'negocio.perfil.gestionar'],
];

let ctxPromise;
/** Barbería, roles y permisos del usuario en ella (GET /panel/contexto). */
export function context() {
  ctxPromise = ctxPromise || papi('/panel/contexto');
  return ctxPromise;
}

export const can = (ctx, perm) => (Array.isArray(perm) ? perm.some((p) => ctx.permisos.includes(p)) : ctx.permisos.includes(perm));

/**
 * Prepara una página del panel: iconos, nombre de la barbería y navegación entre módulos
 * según permisos. Redirige al login si no hay sesión. Devuelve el contexto o null.
 */
export async function bootPanel(active) {
  document.querySelectorAll('[data-icon]').forEach((el) => { el.innerHTML = icon(el.dataset.icon); });
  try {
    const ctx = await context();
    document.querySelectorAll('[data-tenant-name]').forEach((el) => { el.textContent = `· ${ctx.tenant.nombre_comercial}`; });
    const nav = $('[data-panel-nav]');
    if (nav) {
      nav.innerHTML = MODULES.filter(([, , , perm]) => can(ctx, perm)).map(([key, label, ic]) =>
        `<a href="${key}.html${q}"${key === active ? ' aria-current="page"' : ''}>${icon(ic)}<span>${label}</span></a>`).join('');
    }
    return ctx;
  } catch (e) {
    if (e.status === 401) {
      location.replace(`../auth/login.html?next=${encodeURIComponent(`../panel/${active}.html${location.search}`)}`);
      return null;
    }
    const n = $('[data-notice]');
    if (n) notice(n, e.status === 404 ? 'No perteneces a esta barbería o no existe. Elige una desde Mi cuenta.' : e.message, 'error');
    return null;
  }
}

/** Abre una hoja modal y devuelve una promesa que se resuelve al cerrarla. */
export function openSheet(dialog) {
  dialog.querySelectorAll('[data-close]').forEach((b) => { b.onclick = () => dialog.close(); });
  dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); }, { once: true });
  if (!dialog.open) dialog.showModal();
  return new Promise((resolve) => dialog.addEventListener('close', () => resolve(dialog.returnValue), { once: true }));
}

/** Pide confirmación con un diálogo accesible (no usa confirm() del navegador). */
export function confirmAction(message, { ok = 'Confirmar', danger = false } = {}) {
  let dlg = document.getElementById('confirm-dialog');
  if (!dlg) {
    dlg = document.createElement('dialog');
    dlg.id = 'confirm-dialog';
    dlg.className = 'sheet sheet--confirm';
    dlg.setAttribute('aria-labelledby', 'confirm-text');
    document.body.appendChild(dlg);
  }
  dlg.innerHTML = `<form method="dialog" class="sheet__body">
      <p id="confirm-text" class="confirm-text">${escapeHtml(message)}</p>
      <div class="sheet__actions"><button class="btn btn--ghost" value="no">Cancelar</button>
      <button class="btn${danger ? ' btn--danger' : ''}" value="si">${escapeHtml(ok)}</button></div></form>`;
  dlg.returnValue = '';
  dlg.showModal();
  return new Promise((resolve) => dlg.addEventListener('close', () => resolve(dlg.returnValue === 'si'), { once: true }));
}

/** Errores 422 con claves anidadas (tramos.2) → mensajes legibles en lista. */
export function nestedErrors(err, labels = {}) {
  const fields = err.fields || {};
  return Object.entries(fields).map(([k, msgs]) => {
    const [base, idx] = k.split('.');
    const label = labels[base] ? `${labels[base]}${idx !== undefined ? ` ${Number(idx) + 1}` : ''}: ` : '';
    return `${label}${msgs.join(' ')}`;
  });
}

export const money = (v) => `S/ ${Number(v).toFixed(2)}`;
export const mins = (m) => (m < 60 ? `${m} min` : `${Math.floor(m / 60)} h${m % 60 ? ` ${m % 60} min` : ''}`);

/** Fecha local AAAA-MM-DD de hoy (+n días) en la zona del navegador. */
export function today(plus = 0) {
  const d = new Date();
  d.setDate(d.getDate() + plus);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function fmtDate(ymd, opts = { weekday: 'short', day: 'numeric', month: 'short' }) {
  const [y, m, d] = ymd.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('es-PE', opts);
}

/** Muestra un listado de mensajes en un aviso. */
export function noticeList(el, title, items, type = 'error') {
  el.innerHTML = `${escapeHtml(title)}${items.length ? `<ul>${items.map((i) => `<li>${escapeHtml(i)}</li>`).join('')}</ul>` : ''}`;
  el.dataset.type = type;
  el.hidden = false;
}
