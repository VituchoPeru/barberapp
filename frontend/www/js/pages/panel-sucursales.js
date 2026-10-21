import { $, clearErrors, showErrors, withLoading, escapeHtml, notice } from '../ui.js';
import { icon } from '../icons.js';
import { bootPanel, papi, openSheet, confirmAction, toast, DIAS, noticeList } from '../panel.js';

/** Panel: sucursales, ubicación y horario de atención (Fase 4). */
const listEl = $('[data-list]');
const sheet = $('[data-sheet]');
const form = $('[data-form]');
const noticeEl = $('[data-notice]');
const FIELDS = ['nombre', 'direccion', 'referencia', 'distrito', 'provincia', 'departamento', 'telefono', 'email', 'latitud', 'longitud', 'zona_horaria'];
let items = [];
let editing = null;
let hours = {}; // dia → [{hora_apertura, hora_cierre}]

function summary(h) {
  if (!h.length) return 'Sin horario de atención';
  const byDay = {};
  h.forEach((t) => { (byDay[t.dia_semana] = byDay[t.dia_semana] || []).push(`${t.hora_apertura}–${t.hora_cierre}`); });
  const groups = [];
  for (let d = 1; d <= 7; d++) {
    const v = (byDay[d] || []).join(', ') || 'Cerrado';
    const last = groups[groups.length - 1];
    if (last && last.v === v) last.to = d; else groups.push({ from: d, to: d, v });
  }
  return groups.filter((g) => g.v !== 'Cerrado').map((g) => `${DIAS[g.from].slice(0, 3)}${g.to !== g.from ? '–' + DIAS[g.to].slice(0, 3) : ''} ${g.v}`).join(' · ');
}

function render() {
  listEl.innerHTML = items.length ? items.map((s) => `
    <li class="row row--link" data-id="${s.id}" data-inactive="${!s.activo}" tabindex="0" role="button" aria-label="Editar ${escapeHtml(s.nombre)}">
      <span class="thumb">${icon('store')}</span>
      <div class="row__main"><p class="row__title">${escapeHtml(s.nombre)} ${s.es_principal ? '<span class="badge badge--gold">Principal</span>' : ''} ${s.activo ? '' : '<span class="badge badge--off">Inactiva</span>'}</p>
        <p class="row__meta"><span>${escapeHtml(s.direccion)}, ${escapeHtml(s.distrito)}</span><span>${s.barberos_activos} barbero(s)</span>
        <span>${escapeHtml(summary(s.horario))}</span>${s.latitud === null ? '<span class="badge">Sin coordenadas</span>' : ''}</p></div>
      <div class="row__actions">${icon('back', 'icon--flip')}</div></li>`).join('')
    : `<li class="empty">${icon('store')}<h2>Registra tu primera sucursal</h2><p>Con su dirección y horario de atención tu barbería podrá publicarse y recibir reservas.</p></li>`;
}

async function load() {
  const { data, meta } = await papi('/panel/sucursales', { withMeta: true });
  items = data;
  if (!form.zona_horaria.options.length) {
    form.zona_horaria.innerHTML = meta.zonas_horarias.map((z) => `<option value="${escapeHtml(z)}">${escapeHtml(z.replace(/_/g, ' '))}</option>`).join('');
  }
  render();
}

// ---------- Horario de atención (editor por día, hasta 3 tramos)
function syncHours() {
  document.querySelectorAll('[data-hday]').forEach((el) => {
    const d = Number(el.dataset.hday);
    hours[d] = el.querySelector('[data-day-on]').checked
      ? [...el.querySelectorAll('.slot')].map((s) => ({ hora_apertura: s.querySelector('[name="a"]').value, hora_cierre: s.querySelector('[name="c"]').value }))
      : [];
  });
}
function renderHours() {
  $('[data-hours]').innerHTML = [1, 2, 3, 4, 5, 6, 7].map((d) => {
    const list = hours[d] || [];
    return `<div class="day" data-hday="${d}" data-off="${!list.length}">
      <label class="day__name"><input type="checkbox" data-day-on ${list.length ? 'checked' : ''}>${DIAS[d]}</label>
      <div class="day__slots">${list.map((t) => `<div class="slot">
        <input type="time" name="a" value="${escapeHtml(t.hora_apertura)}" step="300" aria-label="${DIAS[d]}: apertura"><span aria-hidden="true">a</span>
        <input type="time" name="c" value="${escapeHtml(t.hora_cierre)}" step="300" aria-label="${DIAS[d]}: cierre">
        <button class="icon-btn" type="button" data-remove aria-label="Quitar tramo">${icon('close')}</button></div>`).join('')}
        ${list.length < 3 ? '<div class="day__add"><button class="link-btn" type="button" data-add>+ Tramo</button></div>' : ''}</div></div>`;
  }).join('');
}
$('[data-hours]').addEventListener('change', (e) => {
  if (!e.target.matches('[data-day-on]')) return;
  syncHours();
  const d = Number(e.target.closest('[data-hday]').dataset.hday);
  if (e.target.checked && !hours[d].length) hours[d] = [{ hora_apertura: '09:00', hora_cierre: '20:00' }];
  renderHours();
});
$('[data-hours]').addEventListener('click', (e) => {
  const day = e.target.closest('[data-hday]');
  if (!day) return;
  if (e.target.closest('[data-add]')) {
    syncHours();
    const d = Number(day.dataset.hday);
    const last = hours[d][hours[d].length - 1];
    hours[d].push({ hora_apertura: last ? last.hora_cierre : '09:00', hora_cierre: '21:00' });
    renderHours();
  } else if (e.target.closest('[data-remove]')) {
    e.target.closest('.slot').remove();
    syncHours();
    renderHours();
  }
});
$('[data-hours-save]').addEventListener('click', (e) => {
  syncHours();
  const alert = $('[data-hours-alert]');
  alert.hidden = true;
  const horario = [];
  for (let d = 1; d <= 7; d++) (hours[d] || []).forEach((t) => horario.push({ dia_semana: d, ...t }));
  withLoading(e.currentTarget, async () => {
    try {
      const { data, meta } = await papi(`/panel/sucursales/${editing.id}/horario`, { method: 'PUT', body: { horario }, withMeta: true });
      editing = data;
      toast('Horario guardado');
      const w = meta.advertencias.jornadas_fuera_de_horario;
      if (w.length) {
        noticeList(alert, 'Estas jornadas de barberos quedan fuera del nuevo horario y esa parte ya no se ofrecerá. Ajústalas en la ficha de cada barbero:',
          w.map((x) => `${x.barbero}: ${DIAS[x.dia_semana]} ${x.hora_inicio}–${x.hora_fin}`), 'info');
      }
      await load();
    } catch (err) {
      noticeList(alert, err.message, Object.entries(err.fields || {}).map(([k, m]) => {
        const t = horario[Number(k.split('.')[1])];
        return `${t ? `${DIAS[t.dia_semana]} ${t.hora_apertura}–${t.hora_cierre}: ` : ''}${m.join(' ')}`;
      }));
    }
  });
});

// ---------- Datos de la sucursal
function edit(s) {
  editing = s;
  form.reset();
  clearErrors(form);
  $('[data-sheet-title]').textContent = s ? `Editar ${s.nombre}` : 'Nueva sucursal';
  $('[data-delete]').hidden = !s;
  $('[data-hours-box]').hidden = !s;
  $('[data-hours-alert]').hidden = true;
  if (s) {
    FIELDS.forEach((k) => { form[k].value = s[k] === null || s[k] === undefined ? '' : s[k]; });
    form.es_principal.checked = s.es_principal;
    form.activo.checked = s.activo;
    hours = {};
    s.horario.forEach((t) => { (hours[t.dia_semana] = hours[t.dia_semana] || []).push({ hora_apertura: t.hora_apertura, hora_cierre: t.hora_cierre }); });
    renderHours();
  } else {
    form.zona_horaria.value = 'America/Lima';
    form.provincia.value = 'Lima';
    form.departamento.value = 'Lima';
  }
  openSheet(sheet);
}

form.addEventListener('submit', (e) => {
  e.preventDefault();
  clearErrors(form);
  const body = Object.fromEntries(FIELDS.map((k) => [k, form[k].value.trim() || null]));
  body.es_principal = form.es_principal.checked;
  body.activo = form.activo.checked;
  withLoading(form.querySelector('[type="submit"]'), async () => {
    try {
      const saved = await papi(editing ? `/panel/sucursales/${editing.id}` : '/panel/sucursales', { method: editing ? 'PUT' : 'POST', body });
      toast(editing ? 'Sucursal guardada' : 'Sucursal creada: ahora define su horario de atención');
      await load();
      edit(items.find((s) => s.id === saved.id) || saved);
    } catch (err) { showErrors(form, err); }
  });
});

$('[data-delete]').addEventListener('click', async () => {
  if (!editing || !(await confirmAction(`¿Eliminar ${editing.nombre}? Esta acción no se puede deshacer.`, { ok: 'Eliminar', danger: true }))) return;
  try {
    await papi(`/panel/sucursales/${editing.id}`, { method: 'DELETE' });
    sheet.close();
    toast('Sucursal eliminada');
    await load();
  } catch (err) { showErrors(form, err); }
});

listEl.addEventListener('click', (e) => {
  const row = e.target.closest('[data-id]');
  if (row) edit(items.find((s) => s.id === Number(row.dataset.id)));
});
listEl.addEventListener('keydown', (e) => {
  if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('[data-id]')) { e.preventDefault(); e.target.click(); }
});
$('[data-new]').addEventListener('click', () => edit(null));

bootPanel('sucursales').then(async (ctx) => {
  if (!ctx) { $('[data-loading]').hidden = true; return; }
  try { await load(); } catch (e) {
    notice(noticeEl, e.status === 403 ? 'Tu rol no permite gestionar sucursales.' : e.message, 'error');
  } finally {
    $('[data-loading]').hidden = true;
    $('[data-page]').removeAttribute('aria-busy');
  }
});
