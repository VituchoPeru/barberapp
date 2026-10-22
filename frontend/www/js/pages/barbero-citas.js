import { $, escapeHtml, notice } from '../ui.js';
import { icon } from '../icons.js';
import {
  bootBarber, papi, citaCard, openCita, dayLabel,
} from '../barbero.js';

/**
 * Panel del barbero — Citas (Fase 7): próximas citas agrupadas por día e historial de
 * las ya pasadas (solo las suyas) con totales por estado y tasa de inasistencia.
 */
const noticeEl = $('[data-notice]');
const params = new URLSearchParams(location.search);
let tab = params.get('ver') === 'historial' ? 'historial' : 'proximas';
let hoy = null;
let page = 1;
const loaded = { proximas: false, historial: false };

function ymd(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function grouped(citas, withToday) {
  const by = new Map();
  citas.forEach((c) => { if (!by.has(c.fecha)) by.set(c.fecha, []); by.get(c.fecha).push(c); });
  return [...by.entries()].map(([f, list]) => `<section class="bb-group"><h3 class="group-title">${escapeHtml(dayLabel(f, withToday ? hoy : null))}
    <span class="muted">· ${list.length} cita${list.length === 1 ? '' : 's'}</span></h3><div class="bb-list">${list.map((c) => citaCard(c)).join('')}</div></section>`).join('');
}

async function loadUpcoming() {
  const dias = $('[data-dias]').value;
  const d = await papi(`/barbero/proximas?dias=${dias}`);
  hoy = hoy || ymd(new Date());
  const n = d.citas.length;
  $('[data-up-summary]').textContent = n ? `${n} cita${n === 1 ? '' : 's'} en los próximos ${d.dias} días` : '';
  $('[data-upcoming]').innerHTML = n ? grouped(d.citas, true)
    : `<div class="empty">${icon('calendar')}<h3>Sin citas próximas</h3><p>Cuando un cliente reserve contigo aparecerá aquí.</p></div>`;
}

async function loadHistory(more = false) {
  page = more ? page + 1 : 1;
  const dias = Number($('[data-rango]').value);
  const hasta = new Date();
  const desde = new Date();
  desde.setDate(desde.getDate() - (dias - 1));
  const qs = new URLSearchParams({ desde: ymd(desde), hasta: ymd(hasta), page: String(page), per_page: '20' });
  if ($('[data-estado]').value) qs.set('estado', $('[data-estado]').value);
  const res = await papi(`/barbero/historial?${qs}`, { withMeta: true });
  const m = res.meta;
  const e = m.por_estado;
  const stat = (label, value) => `<div class="bb-stat"><strong>${escapeHtml(String(value))}</strong><span>${escapeHtml(label)}</span></div>`;
  $('[data-hist-stats]').innerHTML = stat('Atendidas', e.completada) + stat('No asistió', e.no_asistio) + stat('Canceladas', e.cancelada)
    + stat('Inasistencia', m.tasa_inasistencia === null ? '—' : `${m.tasa_inasistencia.toLocaleString('es-PE')} %`);
  const html = res.data.map((c) => citaCard(c, { showDate: true })).join('');
  const list = $('[data-history]');
  if (more) list.insertAdjacentHTML('beforeend', html);
  else list.innerHTML = html || `<div class="empty">${icon('clock')}<h3>Sin citas en este periodo</h3><p>Prueba con un periodo más largo u otro estado.</p></div>`;
  $('[data-more]').hidden = page * m.per_page >= m.total;
}

async function show(t, { force = false } = {}) {
  tab = t;
  document.querySelectorAll('[data-tab]').forEach((b) => {
    const on = b.dataset.tab === t;
    b.setAttribute('aria-selected', String(on));
    b.setAttribute('aria-pressed', String(on));
  });
  document.querySelectorAll('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== t; });
  const p = new URLSearchParams(location.search);
  if (t === 'historial') p.set('ver', 'historial'); else p.delete('ver');
  history.replaceState(null, '', `${location.pathname}${p.toString() ? `?${p}` : ''}`);
  if (loaded[t] && !force) return;
  $('[data-loading]').hidden = false;
  try {
    await (t === 'proximas' ? loadUpcoming() : loadHistory());
    loaded[t] = true;
    noticeEl.hidden = true;
  } catch (e) {
    notice(noticeEl, e.message, 'error');
  } finally {
    $('[data-loading]').hidden = true;
    $('[data-page]').removeAttribute('aria-busy');
  }
}

(async () => {
  const ctx = await bootBarber('citas');
  if (!ctx) return;
  document.querySelectorAll('[data-tab]').forEach((b) => { b.onclick = () => show(b.dataset.tab); });
  $('[data-dias]').onchange = () => show('proximas', { force: true });
  $('[data-rango]').onchange = () => show('historial', { force: true });
  $('[data-estado]').onchange = () => show('historial', { force: true });
  $('[data-more]').onclick = () => loadHistory(true).catch((e) => notice(noticeEl, e.message, 'error'));
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-cita]');
    if (b) openCita(Number(b.dataset.cita), () => { loaded.proximas = false; loaded.historial = false; show(tab, { force: true }); });
  });
  await show(tab);
})();
