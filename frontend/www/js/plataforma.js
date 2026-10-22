import { api } from './api.js';
import { $, escapeHtml, notice, clearErrors, showErrors, withLoading } from './ui.js';
import { icon } from './icons.js';

/**
 * Panel del superadministrador SaaS (Fase 9): navegación, permisos y formatos comunes.
 *
 * Solo para el equipo de BARBERAPP (superadmin u operador). Ocultar un módulo aquí es solo
 * comodidad: el backend verifica el permiso de plataforma en cada petición. Este panel nunca
 * muestra clientes ni citas de las barberías, solo cifras agregadas.
 */
import { openSheet } from './panel.js';

export { openSheet, confirmAction, askText, hbars, toast, noticeList } from './panel.js';

export const papi = (path, options = {}) => api(`/plataforma${path}`, options);

const MODULES = [
  ['index', 'Dashboard', 'chart', 'plataforma.reportes.ver'],
  ['barberias', 'Barberías', 'store', 'plataforma.tenants.gestionar'],
  ['suscripciones', 'Suscripciones', 'calendar', 'plataforma.suscripciones.gestionar'],
  ['pagos', 'Pagos', 'cash', 'plataforma.pagos.gestionar'],
  ['planes', 'Planes', 'sparkle', ['plataforma.planes.gestionar', 'plataforma.suscripciones.gestionar']],
  ['usuarios', 'Usuarios', 'user', 'plataforma.usuarios.gestionar'],
  ['avisos', 'Avisos', 'bell', 'plataforma.plantillas.gestionar'],
  ['reportes', 'Reportes', 'download', 'plataforma.reportes.ver'],
  ['auditoria', 'Auditoría', 'check', 'plataforma.auditoria.ver'],
  ['privacidad', 'Privacidad', 'user', 'plataforma.privacidad.gestionar'],
  ['configuracion', 'Configuración', 'settings', 'plataforma.configuracion.gestionar'],
];

export const can = (ctx, perm) => (Array.isArray(perm) ? perm.some((p) => ctx.permisos.includes(p)) : ctx.permisos.includes(perm));

/** Prepara una página: iconos, menú según permisos y sesión. Devuelve el contexto o null. */
export async function bootPlatform(active) {
  document.querySelectorAll('[data-icon]').forEach((el) => { el.innerHTML = icon(el.dataset.icon); });
  try {
    const ctx = await papi('/contexto');
    const allowed = MODULES.filter(([, , , perm]) => can(ctx, perm));
    const nav = $('[data-panel-nav]');
    if (nav) {
      nav.innerHTML = allowed.map(([key, label, ic]) =>
        `<a href="${key}.html"${key === active ? ' aria-current="page"' : ''}>${icon(ic)}<span>${label}</span></a>`).join('');
      const cur = nav.querySelector('[aria-current]');
      if (cur) nav.scrollLeft = cur.offsetLeft - (nav.clientWidth - cur.offsetWidth) / 2;
    }
    document.querySelectorAll('[data-role-name]').forEach((el) => { el.textContent = `· ${ctx.rol.nombre || 'Equipo'}`; });
    const mod = MODULES.find(([key]) => key === active);
    if (mod && !can(ctx, mod[3])) {
      // Sin permiso para este módulo: ir al primero permitido (el backend también lo negaría).
      if (allowed.length) { location.replace(`${allowed[0][0]}.html`); return null; }
      fail('Tu rol de plataforma no tiene módulos asignados.');
      return null;
    }
    return ctx;
  } catch (e) {
    if (e.status === 401) {
      location.replace(`../auth/login.html?next=${encodeURIComponent(`../plataforma/${active}.html${location.search}`)}`);
      return null;
    }
    fail(e.status === 403 ? 'Este panel es solo para el equipo de BARBERAPP.' : e.message);
    return null;
  }
}

/** Muestra un error de página y quita los esqueletos de carga. */
export function fail(message) {
  const n = $('[data-notice]');
  if (n) notice(n, message, 'error');
  done();
}

export function done() {
  document.querySelectorAll('[data-loading]:not(button)').forEach((el) => { el.hidden = true; });
  const page = $('[data-page]');
  if (page) page.removeAttribute('aria-busy');
}

// ---------------------------------------------------------------- datos de demostración

const DEMO_KEY = 'barberapp.plataforma.excluir_demo';
export const excludeDemo = {
  get: () => { try { return localStorage.getItem(DEMO_KEY) === '1'; } catch { return false; } },
  set: (v) => { try { localStorage.setItem(DEMO_KEY, v ? '1' : '0'); } catch { /* modo privado */ } },
};

/**
 * Interruptor «Excluir barberías de demostración» y aviso cuando las cifras las incluyen.
 * Los indicadores se calculan siempre desde la base de datos; las barberías demo se marcan
 * con es_demo y aquí se dice explícitamente cuándo cuentan.
 */
export function mountDemoToggle(onChange) {
  const box = $('[data-demo-toggle]');
  if (!box) return;
  box.innerHTML = `<label class="switch switch--sm"><span>Excluir datos de demostración</span><input type="checkbox" role="switch" data-demo-input${excludeDemo.get() ? ' checked' : ''}></label>`;
  box.querySelector('input').addEventListener('change', (e) => { excludeDemo.set(e.target.checked); onChange(); });
}

export function demoBanner(data) {
  const el = $('[data-demo-banner]');
  if (!el) return;
  const n = data.demo ? data.demo.barberias : 0;
  if (data.excluye_demo) {
    el.innerHTML = `${icon('check')}<span>Cifras solo de barberías reales${n ? ` (se excluyen ${n} de demostración)` : ''}.</span>`;
    el.dataset.kind = 'real';
  } else if (n > 0) {
    el.innerHTML = `${icon('sparkle')}<span><strong>Incluye datos de demostración:</strong> ${n} barbería${n === 1 ? '' : 's'} demo cuenta${n === 1 ? '' : 'n'} en estas cifras. Activa «Excluir datos de demostración» para ver solo las reales.</span>`;
    el.dataset.kind = 'demo';
  } else {
    el.innerHTML = '';
    el.dataset.kind = '';
  }
  el.hidden = !el.innerHTML;
}

// ---------------------------------------------------------------- formatos

export function money(amount, currency = 'PEN') {
  const n = Number(amount || 0);
  return currency === 'USD' ? `US$ ${n.toFixed(2)}` : `S/ ${n.toFixed(2)}`;
}

/** Montos en varias monedas (MRR, ingresos): «S/ 79.00 · US$ 10.00». */
export const moneyList = (rows, key = 'total') => (rows && rows.length ? rows.map((r) => money(r[key], r.moneda)).join(' · ') : money(0));

const toDate = (iso) => new Date(iso.includes('T') ? iso : `${iso.replace(' ', 'T')}Z`);
export const fmtDateTime = (iso) => (iso ? toDate(iso).toLocaleString('es-PE', { dateStyle: 'medium', timeStyle: 'short' }) : '—');
export const fmtDay = (iso) => (iso ? (iso.length === 10 ? new Date(`${iso}T12:00:00`) : toDate(iso)).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
export const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

export const kpi = (label, value, hint = '', href = '', accent = false) => {
  const tag = href ? 'a' : 'div';
  return `<${tag} class="kpi${accent ? ' kpi--accent' : ''}"${href ? ` href="${href}"` : ''}><span class="kpi__label">${escapeHtml(label)}</span>
    <span class="kpi__value">${escapeHtml(String(value))}</span>${hint ? `<span class="kpi__hint">${escapeHtml(hint)}</span>` : ''}</${tag}>`;
};

/** Etiquetas de estado: siempre texto (nunca solo color). */
export const TENANT_ESTADOS = {
  pendiente: ['Pendiente', 'warn'], activo: ['Activa', 'ok'], suspendido: ['Suspendida', 'off'], cancelado: ['De baja', 'off'],
};
export const SUB_ESTADOS = {
  prueba: ['En prueba', 'gold'], activa: ['Activa', 'ok'], vencida: ['Vencida', 'warn'], suspendida: ['Suspendida', 'off'], cancelada: ['Cancelada', 'off'],
};
export const PAGO_ESTADOS = {
  pendiente: ['Por confirmar', 'warn'], confirmado: ['Confirmado', 'ok'], rechazado: ['Rechazado', 'off'], reembolsado: ['Reembolsado', 'off'],
};
export const METODOS = { manual: 'Manual', transferencia: 'Transferencia', yape: 'Yape', plin: 'Plin', efectivo: 'Efectivo', pasarela: 'Pasarela' };

export const badge = (map, key) => {
  const [label, kind] = map[key] || [key, ''];
  return `<span class="badge${kind ? ` badge--${kind}` : ''}">${escapeHtml(label)}</span>`;
};
export const demoBadge = (b) => (b && b.es_demo ? '<span class="badge">Demo</span>' : '');

/** Lee los filtros de un formulario como parámetros de consulta (omite los vacíos). */
export function formQuery(form, extra = {}) {
  const p = new URLSearchParams();
  new FormData(form).forEach((v, k) => { if (String(v).trim() !== '') p.set(k, String(v).trim()); });
  Object.entries(extra).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') p.set(k, v); });
  return p.toString();
}

/** Ejecuta fn y retrasa las llamadas repetidas (búsquedas mientras se escribe). */
export function debounce(fn, ms = 300) {
  let t;
  return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}

/** Explica un error 409/422 de la API en una línea. */
export function errorText(e) {
  const fields = e.fields || {};
  const first = Object.values(fields)[0];
  return first ? first.join(' ') : e.message;
}

export function pager(meta, onPage) {
  const el = $('[data-pager]');
  if (!el) return;
  const pages = Math.max(1, Math.ceil(meta.total / meta.per_page));
  el.hidden = pages <= 1;
  el.innerHTML = `<button class="btn btn--ghost btn--sm" type="button" data-p="${meta.page - 1}"${meta.page <= 1 ? ' disabled' : ''}>Anterior</button>
    <span class="muted">Página ${meta.page} de ${pages}</span>
    <button class="btn btn--ghost btn--sm" type="button" data-p="${meta.page + 1}"${meta.page >= pages ? ' disabled' : ''}>Siguiente</button>`;
  el.querySelectorAll('[data-p]').forEach((b) => { b.onclick = () => onPage(Number(b.dataset.p)); });
}

// ---------------------------------------------------------------- formularios en hoja modal

/** Campo con etiqueta y espacio para el error del backend (422). */
export function field(name, label, control, hint = '') {
  return `<div class="field"><label for="pf-${name}">${escapeHtml(label)}</label>${control.replace('<input', `<input id="pf-${name}" name="${name}"`).replace('<select', `<select id="pf-${name}" name="${name}"`).replace('<textarea', `<textarea id="pf-${name}" name="${name}"`)}
    ${hint ? `<p class="hint">${escapeHtml(hint)}</p>` : ''}<p class="field__error" data-error-for="${name}"></p></div>`;
}

export const options = (items, selected = '') => items.map(([v, l]) => `<option value="${escapeHtml(v)}"${String(v) === String(selected) ? ' selected' : ''}>${escapeHtml(l)}</option>`).join('');

/**
 * Abre un formulario en una hoja modal. onSubmit(form) guarda; si lanza un ApiError, los
 * errores por campo se muestran junto a cada campo y la hoja sigue abierta.
 */
export function formSheet({ title, html, ok = 'Guardar', danger = false, onSubmit }) {
  let dlg = document.getElementById('pl-form');
  if (!dlg) {
    dlg = document.createElement('dialog');
    dlg.id = 'pl-form';
    dlg.className = 'sheet';
    dlg.setAttribute('aria-labelledby', 'pl-form-title');
    document.body.appendChild(dlg);
  }
  dlg.innerHTML = `<form class="form" novalidate>
      <div class="sheet__head"><h2 id="pl-form-title">${escapeHtml(title)}</h2><button class="icon-btn" type="button" data-close aria-label="Cerrar">${icon('close')}</button></div>
      <div class="sheet__body"><p class="alert" data-form-alert role="alert" hidden></p>${html}</div>
      <div class="sheet__foot"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn${danger ? ' btn--danger' : ''}" type="submit" data-loading="Guardando…">${escapeHtml(ok)}</button></div>
    </form>`;
  const form = dlg.querySelector('form');
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    clearErrors(form);
    withLoading(form.querySelector('[type="submit"]'), async () => {
      try {
        await onSubmit(form);
        dlg.close();
      } catch (err) {
        showErrors(form, err);
      }
    });
  });
  openSheet(dlg);
  const first = form.querySelector('input:not([type="hidden"]), select, textarea');
  if (first) first.focus();
  return form;
}
