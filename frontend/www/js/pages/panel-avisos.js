import { icon } from '../icons.js';
import { $, escapeHtml, notice, clearErrors, showErrors, withLoading } from '../ui.js';
import { bootPanel, papi, can, today, hbars, toast } from '../panel.js';

/**
 * Avisos y recordatorios del panel (Fase 8): reglas de envío de la barbería y resultados
 * reales (programados, enviados, fallos, confirmaciones de asistencia e inasistencia).
 */
const noticeEl = $('[data-notice]');
const form = $('[data-rules]');
const SWITCHES = ['pedir_confirmacion', 'aviso_confirmacion', 'aviso_cancelacion', 'aviso_modificacion', 'canal_push', 'canal_email'];
const CANALES = { interna: 'Bandeja de la app', push: 'Push', email: 'Correo' };
let limites = { max_recordatorios: 3, horas_min: 1, horas_max: 72 };
let editable = false;
let seq = 0;

const pct = (v) => (v === null || v === undefined ? '—' : `${v} %`);
const kpi = (label, value, hint = '', accent = false) => `<div class="kpi${accent ? ' kpi--accent' : ''}"><span class="kpi__label">${escapeHtml(label)}</span>
  <span class="kpi__value">${escapeHtml(String(value))}</span>${hint ? `<span class="kpi__hint">${escapeHtml(hint)}</span>` : ''}</div>`;

function horaRow(h = '') {
  return `<div class="av-hora"><input class="input" type="number" inputmode="numeric" min="${limites.horas_min}" max="${limites.horas_max}" step="1" value="${escapeHtml(String(h))}" data-hora aria-label="Horas antes de la cita"${editable ? '' : ' disabled'}>
    <span>horas antes</span>
    ${editable ? `<button class="icon-btn" type="button" data-del-hora aria-label="Quitar este recordatorio">${icon('trash')}</button>` : ''}</div>`;
}

function paintHoras(horas) {
  const box = $('[data-horas]');
  box.innerHTML = horas.length ? horas.map(horaRow).join('') : '<p class="muted" data-sin-horas>Sin recordatorios: solo se enviarán los avisos de cambios.</p>';
  $('[data-add-hora]').hidden = !editable || horas.length >= limites.max_recordatorios;
}

function currentHoras() {
  return [...document.querySelectorAll('[data-hora]')].map((i) => i.value.trim()).filter((v) => v !== '').map(Number);
}

function fillRules(r) {
  limites = r.limites;
  paintHoras(r.recordatorios_horas);
  SWITCHES.forEach((k) => { form.elements[k].checked = !!r[k]; form.elements[k].disabled = !editable; });
}

async function loadRules() {
  fillRules(await papi('/panel/notificaciones/reglas'));
}

form.addEventListener('click', (e) => {
  if (e.target.closest('[data-add-hora]')) {
    if (document.querySelectorAll('[data-hora]').length >= limites.max_recordatorios) return;
    $('[data-sin-horas]')?.remove();
    $('[data-horas]').insertAdjacentHTML('beforeend', horaRow(''));
    $('[data-horas]').lastElementChild.querySelector('input').focus();
    $('[data-add-hora]').hidden = document.querySelectorAll('[data-hora]').length >= limites.max_recordatorios;
  }
  const del = e.target.closest('[data-del-hora]');
  if (del) {
    del.closest('.av-hora').remove();
    if (!document.querySelector('[data-hora]')) paintHoras([]);
    $('[data-add-hora]').hidden = document.querySelectorAll('[data-hora]').length >= limites.max_recordatorios;
  }
});

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  clearErrors(form);
  const body = { recordatorios_horas: currentHoras() };
  SWITCHES.forEach((k) => { body[k] = form.elements[k].checked; });
  try {
    const r = await withLoading(form.querySelector('[type="submit"]'), () => papi('/panel/notificaciones/reglas', { method: 'PUT', body }));
    fillRules(r);
    toast('Reglas de avisos guardadas');
  } catch (err) {
    showErrors(form, err);
  }
});

function range() {
  const dias = Number($('[data-rango]').value);
  return [today(-(dias - 1)), today()];
}

async function loadMetrics() {
  const [desde, hasta] = range();
  const my = ++seq;
  try {
    const m = await papi(`/panel/notificaciones/metricas?${new URLSearchParams({ desde, hasta })}`);
    if (my !== seq) return;
    render(m);
    $('[data-metrics]').hidden = false;
  } catch (err) {
    notice(noticeEl, err.message, 'error');
  } finally {
    $('[data-metrics-loading]').hidden = true;
  }
}

function render(m) {
  const rec = m.recordatorios;
  const av = m.avisos;
  $('[data-kpis]').innerHTML = [
    kpi('Recordatorios enviados', rec.enviados, `${rec.programados} programados · ${rec.pendientes} en cola`, true),
    kpi('Entrega', pct(rec.tasa_entrega), `${rec.fallidos} fallidos tras reintentos`),
    kpi('Confirmaron asistencia', pct(m.citas.tasa_confirmacion), `${m.citas.confirmadas_tras_recordatorio} de ${m.citas.con_recordatorio} citas con recordatorio`),
    kpi('Inasistencia', pct(m.inasistencia.tasa), `${m.inasistencia.no_asistio} de ${m.inasistencia.atendidas + m.inasistencia.no_asistio} citas atendidas o perdidas`),
    kpi('Avisos de cambios', av.enviados, `${av.fallidos} fallidos · ${av.omitidos} omitidos`),
  ].join('');

  const canales = Object.entries(rec.por_canal);
  $('[data-canales]').innerHTML = canales.length
    ? `<thead><tr><th>Canal</th><th>Enviados</th><th>En cola</th><th>Fallidos</th><th>Omitidos</th><th>Anulados</th></tr></thead>
      <tbody>${canales.map(([c, v]) => `<tr><td>${escapeHtml(CANALES[c] || c)}</td><td>${v.enviados}</td><td>${v.pendientes}</td><td>${v.fallidos}</td><td>${v.omitidos}</td><td>${v.cancelados}</td></tr>`).join('')}</tbody>`
    : '<tbody><tr><td>Todavía no hay recordatorios en este periodo.</td></tr></tbody>';

  const i = m.inasistencia;
  const rows = [
    ['Todas las citas', i.tasa], ['Confirmaron asistencia', i.tasa_confirmaron], ['No confirmaron', i.tasa_no_confirmaron],
    ['Recibieron recordatorio', i.tasa_con_recordatorio], ['Sin recordatorio', i.tasa_sin_recordatorio],
  ];
  // Sin citas en un grupo la tasa es null: se muestra "—", no un 0 % inventado.
  $('[data-noshow]').innerHTML = hbars(rows.map(([label, value]) => ({ label, value })), 100, pct);

  $('[data-errores]').innerHTML = m.errores_recientes.length
    ? m.errores_recientes.map((e) => `<li class="item"><div>
        <p class="item__title">${escapeHtml(CANALES[e.canal] || e.canal)} · ${e.resultado === 'error_permanente' ? 'falló' : `reintento ${e.intento}`}${e.reserva_codigo ? ` · cita ${escapeHtml(e.reserva_codigo)}` : ''}</p>
        <p class="item__meta">${escapeHtml(new Date(e.fecha).toLocaleString('es-PE', { dateStyle: 'medium', timeStyle: 'short' }))} · ${escapeHtml(e.codigo || '')} ${escapeHtml(e.detalle || '')}</p></div></li>`).join('')
    : '<li class="muted">Sin errores de envío en este periodo.</li>';
}

(async () => {
  const ctx = await bootPanel('avisos');
  if (!ctx) return;
  editable = can(ctx, 'notificaciones.gestionar');
  $('[data-readonly]').hidden = editable;
  form.querySelector('[type="submit"]').closest('.toolbar').hidden = !editable;
  try {
    await Promise.all([loadRules(), loadMetrics()]);
  } catch (err) {
    notice(noticeEl, err.message, 'error');
  } finally {
    $('[data-page]').removeAttribute('aria-busy');
  }
  $('[data-rango]').addEventListener('change', loadMetrics);
})();
