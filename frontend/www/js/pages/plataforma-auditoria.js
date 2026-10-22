import { $, escapeHtml } from '../ui.js';
import { icon } from '../icons.js';
import { bootPlatform, papi, fail, done, openSheet, fmtDateTime, formQuery, debounce } from '../plataforma.js';

/** Bitácora de auditoría (Fase 9): solo lectura, filtros y paginación por cursor. */
const form = $('[data-filters]');
const sheet = $('[data-detail]');
let items = [];
let next = null;
const RES = { exito: ['Éxito', 'ok'], denegado: ['Denegado', 'off'], error: ['Error', 'warn'] };
const params = new URLSearchParams(location.search);
['accion', 'resultado', 'tenant', 'usuario', 'desde', 'hasta'].forEach((k) => { if (params.get(k) !== null) form[k].value = params.get(k); });

const row = (a) => `<li class="row row--link" tabindex="0" role="button" data-id="${a.id}" aria-label="Ver evento ${escapeHtml(a.accion)}">
    <span class="thumb">${icon(a.resultado === 'exito' ? 'check' : 'close')}</span>
    <div class="row__main"><p class="row__title audit-code">${escapeHtml(a.accion)}</p>
      <p class="row__meta"><span>${fmtDateTime(a.fecha)}</span><span>${escapeHtml(a.usuario ? a.usuario.email : 'Sistema o sin sesión')}</span>
        ${a.barberia ? `<span>${escapeHtml(a.barberia.nombre)}</span>` : ''}${a.ip ? `<span>IP ${escapeHtml(a.ip)}</span>` : ''}</p></div>
    <div class="row__actions"><span class="badge badge--${RES[a.resultado][1]}">${RES[a.resultado][0]}</span></div></li>`;

async function load(more = false) {
  try {
    const { data, meta } = await papi(`/auditoria?${formQuery(form, { antes_de: more ? next : '' })}`, { withMeta: true });
    items = more ? items.concat(data) : data;
    next = meta.siguiente;
    $('[data-list]').innerHTML = items.length ? items.map(row).join('')
      : `<li class="empty">${icon('check')}<h3>Sin eventos</h3><p>No hay eventos con esos filtros.</p></li>`;
    $('[data-more]').hidden = !next;
  } catch (e) { fail(e.message); return; }
  done();
}

function open(id) {
  const a = items.find((x) => String(x.id) === String(id));
  if (!a) return;
  const json = (v) => `<pre class="json">${escapeHtml(JSON.stringify(v, null, 2))}</pre>`;
  $('[data-det-title]').textContent = a.accion;
  $('[data-det-body]').innerHTML = `<dl class="kv">
      <dt>Fecha</dt><dd>${fmtDateTime(a.fecha)}</dd>
      <dt>Resultado</dt><dd>${RES[a.resultado][0]}</dd>
      <dt>Cuenta</dt><dd>${escapeHtml(a.usuario ? a.usuario.email : '—')}</dd>
      <dt>Barbería</dt><dd>${a.barberia ? `${escapeHtml(a.barberia.nombre)} (${escapeHtml(a.barberia.slug)})` : '—'}</dd>
      <dt>Entidad</dt><dd>${escapeHtml(a.entidad || '—')}${a.entidad_id ? ` #${a.entidad_id}` : ''}</dd>
      <dt>IP</dt><dd>${escapeHtml(a.ip || '—')}</dd>
      <dt>Navegador</dt><dd>${escapeHtml(a.user_agent || '—')}</dd>
      <dt>Solicitud</dt><dd class="audit-code">${escapeHtml(a.request_id || '—')}</dd></dl>
    ${a.datos_anteriores ? `<h3 class="section-sub">Antes</h3>${json(a.datos_anteriores)}` : ''}
    ${a.datos_nuevos ? `<h3 class="section-sub">Después / detalle</h3>${json(a.datos_nuevos)}` : ''}`;
  openSheet(sheet);
}

(async () => {
  if (!await bootPlatform('auditoria')) return;
  try {
    const fam = await papi('/auditoria/acciones');
    form.accion.insertAdjacentHTML('beforeend', fam.map((f) => `<option value="${escapeHtml(f.accion)}">${escapeHtml(f.accion)} (${f.total})</option>`).join(''));
    if (params.get('accion')) form.accion.value = params.get('accion');
  } catch { /* el filtro por acción es opcional */ }
  const reload = () => load(false);
  form.addEventListener('submit', (e) => { e.preventDefault(); reload(); });
  ['tenant', 'usuario'].forEach((k) => form[k].addEventListener('input', debounce(reload, 500)));
  ['accion', 'resultado', 'desde', 'hasta'].forEach((k) => form[k].addEventListener('change', reload));
  $('[data-more]').addEventListener('click', () => load(true));
  $('[data-list]').addEventListener('click', (e) => { const li = e.target.closest('[data-id]'); if (li) open(li.dataset.id); });
  $('[data-list]').addEventListener('keydown', (e) => { const li = e.target.closest('[data-id]'); if (li && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(li.dataset.id); } });
  load();
})();
