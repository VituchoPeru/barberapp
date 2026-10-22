import { $, escapeHtml, notice, withLoading } from '../ui.js';
import { icon } from '../icons.js';
import { DIAS, noticeList, openSheet } from '../panel.js';
import {
  bootBarber, papi, toast, mins, fmtDate, toMin, TIPOS,
} from '../barbero.js';

/**
 * Panel del barbero — Horario (Fase 7): jornada semanal con descansos, tiempo reservable de
 * los próximos 14 días y ausencias. Si la barbería lo permite, el barbero edita su jornada
 * con las mismas reglas que el panel (horario de la sede, sin cruces entre sedes).
 */
const noticeEl = $('[data-notice]');
const editSheet = $('[data-edit-sheet]');
const editForm = $('[data-edit-form]');
const START = 6 * 60; // la barra de cada día va de 06:00 a 22:00
const SPAN = 16 * 60;
let data = null;
let week = {};

function renderWeekView(d) {
  const { tramos, descansos } = d.semanal;
  $('[data-week]').innerHTML = [1, 2, 3, 4, 5, 6, 7].map((dia) => {
    const t = tramos.filter((x) => x.dia_semana === dia);
    const b = descansos.filter((x) => x.dia_semana === dia);
    const sedes = new Set(t.map((x) => x.sucursal));
    return `<li class="bb-weekday" data-off="${!t.length}"><span class="bb-weekday__name">${DIAS[dia]}</span>
      <span class="bb-weekday__slots">${t.length ? t.map((x) => `<span class="bb-pill">${escapeHtml(x.hora_inicio)}–${escapeHtml(x.hora_fin)}${sedes.size > 1 || d.sucursales.filter((s) => s.activo).length > 1 ? ` · ${escapeHtml(x.sucursal || '')}` : ''}</span>`).join('')
        + b.map((x) => `<span class="bb-pill bb-pill--rest">${escapeHtml(x.motivo || 'Descanso')} ${escapeHtml(x.hora_inicio)}–${escapeHtml(x.hora_fin)}</span>`).join('')
        : '<span class="muted">Descansas</span>'}</span></li>`;
  }).join('');
  const editable = d.ajustes.puede_editar_horario;
  $('[data-edit]').hidden = !editable;
  $('[data-week-hint]').textContent = editable
    ? 'Puedes ajustar tu jornada. Las citas ya reservadas se mantienen aunque queden fuera.'
    : 'Tu jornada la configura el encargado de la barbería. Para no atender un día, solicita una ausencia.';
}

function renderDays(d) {
  $('[data-total]').textContent = mins(d.minutos_totales);
  $('[data-days]').innerHTML = d.dias.map((day) => {
    const bar = day.ventanas.map((w) => {
      const s = Math.max(0, toMin(w.inicio) - START);
      const e = Math.min(SPAN, (w.fin === '24:00' ? 1440 : toMin(w.fin)) - START);
      return e > s ? `<div class="tl-seg" style="left:${(s / SPAN) * 100}%;width:${((e - s) / SPAN) * 100}%" title="${escapeHtml(w.sucursal || '')} ${w.inicio}–${w.fin}"><span>${w.inicio}–${w.fin}</span></div>` : '';
    }).join('');
    const label = day.ventanas.length ? day.ventanas.map((w) => `${w.inicio}–${w.fin}`).join(', ') : 'Sin atención';
    return `<div class="tl-day"><span class="tl-day__label">${escapeHtml(fmtDate(day.fecha))}</span>
      <div class="tl-track" role="img" aria-label="${escapeHtml(fmtDate(day.fecha, { weekday: 'long', day: 'numeric', month: 'long' }))}: ${escapeHtml(label)}">${bar || '<span class="tl-empty" style="position:absolute;left:8px;top:4px">Sin atención</span>'}</div></div>`;
  }).join('');
}

function blockWhen(b) {
  if (b.dia_completo) {
    return b.fecha_inicio === b.fecha_fin ? fmtDate(b.fecha_inicio, { weekday: 'long', day: 'numeric', month: 'long' }) : `${fmtDate(b.fecha_inicio)} al ${fmtDate(b.fecha_fin)}`;
  }
  const [fi, hi] = b.inicio_local.split('T');
  const [ff, hf] = b.fin_local.split('T');
  return fi === ff ? `${fmtDate(fi)} · ${hi}–${hf}` : `${fmtDate(fi)} ${hi} al ${fmtDate(ff)} ${hf}`;
}

function renderBlocks(d) {
  const now = Date.now();
  const list = d.bloqueos.filter((b) => b.estado !== 'rechazado' && new Date(b.fin_utc).getTime() > now);
  $('[data-blocks]').innerHTML = list.length ? list.map((b) => `<li class="row"><span class="thumb">${icon('calendar')}</span>
    <div class="row__main"><p class="row__title">${escapeHtml(TIPOS[b.tipo] || b.tipo)}${b.alcance !== 'barbero' ? ' · toda la ' + (b.alcance === 'sucursal' ? 'sede' : 'barbería') : ''}</p>
    <p class="row__meta"><span>${escapeHtml(blockWhen(b))}</span>${b.estado === 'solicitado' ? '<span class="badge badge--warn">Esperando aprobación</span>' : '<span class="badge badge--ok">Aprobada</span>'}</p></div><span></span></li>`).join('')
    : '<li class="muted">No tienes ausencias próximas.</li>';
}

async function load() {
  try {
    data = await papi('/barbero/horario');
    renderWeekView(data);
    renderDays(data);
    renderBlocks(data);
    ['[data-week-box]', '[data-next-box]', '[data-blocks-box]'].forEach((s) => { $(s).hidden = false; });
  } catch (e) {
    notice(noticeEl, e.message, 'error');
  } finally {
    $('[data-loading]').hidden = true;
    $('[data-page]').removeAttribute('aria-busy');
  }
}

// ================================================================== editor de jornada

const activeBranches = () => data.sucursales.filter((s) => s.activo);
const sel = (value, label) => `<select name="suc" aria-label="${label}">${activeBranches().map((s) => `<option value="${s.id}"${s.id === value ? ' selected' : ''}>${escapeHtml(s.nombre)}</option>`).join('')}</select>`;
const openHours = (sucId, dia) => (data.sucursales.find((s) => s.id === sucId) || { horario: [] }).horario.filter((h) => h.dia_semana === dia);

function loadWeek() {
  week = {};
  for (let d = 1; d <= 7; d++) week[d] = { tramos: [], descansos: [] };
  data.semanal.tramos.forEach((t) => week[t.dia_semana].tramos.push({ ...t }));
  data.semanal.descansos.forEach((b) => week[b.dia_semana].descansos.push({ ...b }));
}

function syncWeek() {
  editForm.querySelectorAll('[data-day]').forEach((dayEl) => {
    const d = Number(dayEl.dataset.day);
    const on = dayEl.querySelector('[data-day-on]').checked;
    const tramos = [...dayEl.querySelectorAll('[data-tramo]')].map((el, i) => ({
      ...(week[d].tramos[i] || {}),
      hora_inicio: el.querySelector('[name="ini"]').value,
      hora_fin: el.querySelector('[name="fin"]').value,
      sucursal_id: Number(el.querySelector('[name="suc"]').value),
    }));
    const descansos = [...dayEl.querySelectorAll('[data-descanso]')].map((el) => ({
      hora_inicio: el.querySelector('[name="ini"]').value,
      hora_fin: el.querySelector('[name="fin"]').value,
      motivo: el.querySelector('[name="motivo"]').value.trim() || null,
    }));
    week[d] = on ? { tramos, descansos } : { tramos: [], descansos: [] };
  });
}

function renderEditor() {
  $('[data-editor]').innerHTML = [1, 2, 3, 4, 5, 6, 7].map((d) => {
    const day = week[d];
    const on = day.tramos.length > 0;
    const hours = day.tramos.length ? openHours(day.tramos[0].sucursal_id, d) : openHours((activeBranches()[0] || {}).id, d);
    const hint = hours.length ? `Sede abierta ${hours.map((h) => `${h.apertura}–${h.cierre}`).join(', ')}` : 'Sede cerrada';
    return `<div class="day" data-day="${d}" data-off="${!on}">
      <label class="day__name"><input type="checkbox" data-day-on ${on ? 'checked' : ''}>${DIAS[d]}</label>
      <div class="day__slots"><small class="muted">${escapeHtml(hint)}</small>
        ${day.tramos.map((t) => `<div class="slot" data-tramo>
          <input type="time" name="ini" value="${escapeHtml(t.hora_inicio)}" step="300" aria-label="${DIAS[d]}: inicio">
          <span aria-hidden="true">a</span>
          <input type="time" name="fin" value="${escapeHtml(t.hora_fin)}" step="300" aria-label="${DIAS[d]}: fin">
          ${activeBranches().length > 1 ? sel(t.sucursal_id, `${DIAS[d]}: sede`) : `<input type="hidden" name="suc" value="${t.sucursal_id}">`}
          <button class="icon-btn" type="button" data-remove aria-label="Quitar tramo">${icon('close')}</button></div>`).join('')}
        ${day.descansos.map((b) => `<div class="slot slot--break" data-descanso>
          <span class="slot__label">Descanso</span>
          <input type="time" name="ini" value="${escapeHtml(b.hora_inicio)}" step="300" aria-label="${DIAS[d]}: inicio del descanso">
          <span aria-hidden="true">a</span>
          <input type="time" name="fin" value="${escapeHtml(b.hora_fin)}" step="300" aria-label="${DIAS[d]}: fin del descanso">
          <input class="input" name="motivo" value="${escapeHtml(b.motivo || '')}" maxlength="80" placeholder="Almuerzo" aria-label="Motivo del descanso" style="max-width:130px;min-height:40px">
          <button class="icon-btn" type="button" data-remove aria-label="Quitar descanso">${icon('close')}</button></div>`).join('')}
        <div class="day__add"><button class="link-btn" type="button" data-add-tramo>+ Tramo</button><button class="link-btn" type="button" data-add-descanso>+ Descanso</button></div>
      </div></div>`;
  }).join('');
}

function principal() {
  const p = activeBranches().find((s) => s.id === data.barbero.sucursal.id) || activeBranches()[0];
  return p ? p.id : null;
}

$('[data-editor]').addEventListener('change', (e) => {
  if (!e.target.matches('[data-day-on]')) return;
  syncWeek();
  const d = Number(e.target.closest('[data-day]').dataset.day);
  if (e.target.checked && !week[d].tramos.length) {
    const h = openHours(principal(), d)[0];
    week[d].tramos.push({ hora_inicio: h ? h.apertura : '09:00', hora_fin: h ? h.cierre : '18:00', sucursal_id: principal() });
  }
  renderEditor();
});
$('[data-editor]').addEventListener('click', (e) => {
  const dayEl = e.target.closest('[data-day]');
  if (!dayEl) return;
  const d = Number(dayEl.dataset.day);
  if (e.target.closest('[data-add-tramo]')) {
    syncWeek();
    const last = week[d].tramos[week[d].tramos.length - 1];
    week[d].tramos.push({ hora_inicio: last ? last.hora_fin : '09:00', hora_fin: last ? '20:00' : '18:00', sucursal_id: last ? last.sucursal_id : principal() });
    renderEditor();
  } else if (e.target.closest('[data-add-descanso]')) {
    syncWeek();
    week[d].descansos.push({ hora_inicio: '13:00', hora_fin: '14:00', motivo: 'Almuerzo' });
    renderEditor();
  } else if (e.target.closest('[data-remove]')) {
    e.target.closest('.slot').remove();
    syncWeek();
    renderEditor();
  }
});

$('[data-edit]').addEventListener('click', () => {
  loadWeek();
  renderEditor();
  $('[data-form-alert]', editForm).hidden = true;
  openSheet(editSheet);
});

editForm.addEventListener('submit', (e) => {
  e.preventDefault();
  syncWeek();
  const alert = $('[data-form-alert]', editForm);
  alert.hidden = true;
  const tramos = [];
  const descansos = [];
  for (let d = 1; d <= 7; d++) {
    week[d].tramos.forEach((t) => tramos.push({ dia_semana: d, hora_inicio: t.hora_inicio, hora_fin: t.hora_fin, sucursal_id: t.sucursal_id, vigente_desde: t.vigente_desde || null, vigente_hasta: t.vigente_hasta || null }));
    week[d].descansos.forEach((b) => descansos.push({ dia_semana: d, ...b }));
  }
  withLoading(editForm.querySelector('[type="submit"]'), async () => {
    try {
      const res = await papi('/barbero/horario', { method: 'PUT', body: { tramos, descansos } });
      data = res.horario;
      renderWeekView(data);
      renderDays(data);
      renderBlocks(data);
      editSheet.close();
      toast('Jornada guardada');
      const fuera = res.advertencias.reservas_fuera_de_jornada;
      if (fuera.length) {
        noticeList(noticeEl, `Tienes ${fuera.length} cita(s) ya reservadas fuera de tu nueva jornada. Se mantienen; atiéndelas o pide al encargado que las reprograme:`,
          fuera.map((r) => `${r.codigo} · ${new Date(r.inicio_utc.replace(' ', 'T') + 'Z').toLocaleString('es-PE', { dateStyle: 'medium', timeStyle: 'short' })}`), 'info');
      }
    } catch (err) {
      const describe = (list, k) => { const t = list[Number(k)]; return t ? `${DIAS[t.dia_semana]} ${t.hora_inicio}–${t.hora_fin}` : ''; };
      const items = Object.entries(err.fields || {}).map(([k, msgs]) => {
        const [base, idx] = k.split('.');
        const where = base === 'tramos' ? describe(tramos, idx) : base === 'descansos' ? `Descanso ${describe(descansos, idx)}` : '';
        return `${where ? where + ': ' : ''}${msgs.join(' ')}`;
      });
      noticeList(alert, err.message, items);
      alert.scrollIntoView({ block: 'nearest' });
    }
  });
});

(async () => {
  const ctx = await bootBarber('horario');
  if (!ctx) return;
  await load();
})();
