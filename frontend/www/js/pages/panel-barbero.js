import { $, clearErrors, showErrors, withLoading, escapeHtml, notice } from '../ui.js';
import { icon } from '../icons.js';
import { safeUrl, initials } from '../marketplace.js';
import { bootPanel, papi, can, confirmAction, toast, money, mins, DIAS, nestedErrors, noticeList, today, fmtDate, q } from '../panel.js';

/**
 * Ficha del barbero: datos y foto, servicios que realiza (con duración/precio propios),
 * jornada semanal por sucursal con descansos y vista previa de la disponibilidad real.
 */
const id = Number(new URLSearchParams(location.search).get('id'));
const form = $('[data-form]');
const noticeEl = $('[data-notice]');
let ctx;
let barber;
let sucursales = [];
let catalog = null; // servicios de la barbería (si el rol puede verlos)
let week = {}; // dia → { tramos: [{hora_inicio, hora_fin, sucursal_id, vigente_desde, vigente_hasta}], descansos: [...] }
let maxUpload = 5 * 1024 * 1024;

const sel = (list, value, name, label) => `<select name="${name}" aria-label="${label}">${list.map((s) =>
  `<option value="${s.id}"${s.id === value ? ' selected' : ''}${s.activo ? '' : ' disabled'}>${escapeHtml(s.nombre)}${s.activo ? '' : ' (inactiva)'}</option>`).join('')}</select>`;

// ------------------------------------------------------------------ ficha
function renderProfile() {
  const foto = safeUrl(barber.foto_url);
  $('[data-portrait]').innerHTML = foto ? `<img src="${escapeHtml(foto)}" alt="Foto de ${escapeHtml(barber.nombre_publico)}">` : escapeHtml(initials(barber.nombre_publico));
  $('[data-name]').textContent = barber.nombre_publico;
  document.title = `${barber.nombre_publico} · Panel BARBERAPP`;
  $('[data-sub]').textContent = [barber.especialidad, barber.sucursal, barber.activo ? null : 'Inactivo'].filter(Boolean).join(' · ');
  $('[data-photo-delete]').hidden = !foto;
  form.nombre_publico.value = barber.nombre_publico;
  form.especialidad.value = barber.especialidad || '';
  form.biografia.value = barber.biografia || '';
  form.sucursal_id.innerHTML = sucursales.map((s) => `<option value="${s.id}"${s.activo ? '' : ' disabled'}>${escapeHtml(s.nombre)}</option>`).join('');
  form.sucursal_id.value = barber.sucursal_id;
  form.activo.checked = barber.activo;
  form.acepta_reservas_online.checked = barber.acepta_reservas_online;
}

function setAccounts(cuentas) {
  const actual = barber.usuario_id ? [{ usuario_id: barber.usuario_id, nombre: 'Cuenta vinculada', email: barber.usuario_email }] : [];
  form.usuario_id.innerHTML = '<option value="">Sin cuenta</option>' + [...actual, ...cuentas]
    .map((u) => `<option value="${u.usuario_id}">${escapeHtml(u.email)}</option>`).join('');
  form.usuario_id.value = barber.usuario_id || '';
}

form.addEventListener('submit', (e) => {
  e.preventDefault();
  clearErrors(form);
  const body = {
    nombre_publico: form.nombre_publico.value.trim(),
    especialidad: form.especialidad.value.trim() || null,
    biografia: form.biografia.value.trim() || null,
    sucursal_id: Number(form.sucursal_id.value),
    usuario_id: form.usuario_id.value ? Number(form.usuario_id.value) : null,
    activo: form.activo.checked,
    acepta_reservas_online: form.acepta_reservas_online.checked,
  };
  withLoading(form.querySelector('[type="submit"]'), async () => {
    try {
      barber = await papi(`/panel/barberos/${id}`, { method: 'PUT', body });
      renderProfile();
      toast('Ficha guardada');
    } catch (err) {
      showErrors(form, err);
      if (err.code === 'barber_has_bookings') form.activo.checked = true;
    }
  });
});

$('[data-delete]').addEventListener('click', async () => {
  if (!(await confirmAction(`¿Eliminar a ${barber.nombre_publico}? Se conservará su historial de reservas.`, { ok: 'Eliminar', danger: true }))) return;
  try {
    await papi(`/panel/barberos/${id}`, { method: 'DELETE' });
    location.href = `barberos.html${q}`;
  } catch (err) { notice(noticeEl, err.message, 'error'); }
});

$('[data-photo]').addEventListener('change', async (e) => {
  const file = e.target.files[0];
  e.target.value = '';
  if (!file) return;
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { toast('Elige una imagen JPG, PNG o WebP.', 'error'); return; }
  if (file.size > maxUpload) { toast(`La imagen supera ${(maxUpload / 1048576).toFixed(0)} MB.`, 'error'); return; }
  try {
    barber = await papi(`/panel/barberos/${id}/foto`, { method: 'POST', raw: file, timeout: 60000 });
    renderProfile();
    toast('Foto actualizada');
  } catch (err) { toast((err.fields.archivo && err.fields.archivo[0]) || err.message, 'error'); }
});
$('[data-photo-delete]').addEventListener('click', async () => {
  try {
    barber = await papi(`/panel/barberos/${id}/foto`, { method: 'DELETE' });
    renderProfile();
    toast('Foto quitada');
  } catch (err) { toast(err.message, 'error'); }
});

// ------------------------------------------------------------------ servicios
function renderServices() {
  const assigned = new Map(barber.servicios.map((s) => [s.servicio_id, s]));
  const editable = can(ctx, 'barberos.gestionar') && catalog;
  const list = catalog || barber.servicios.map((s) => ({ id: s.servicio_id, nombre: s.nombre, duracion_minutos: s.duracion_estandar, precio: s.precio_estandar, activo: s.activo }));
  $('[data-svc-list]').innerHTML = list.length ? list.map((s) => {
    const a = assigned.get(s.id);
    return `<label class="svc" data-svc="${s.id}">
      <input type="checkbox" ${a ? 'checked' : ''} ${editable ? '' : 'disabled'} aria-label="${escapeHtml(s.nombre)}">
      <span><strong>${escapeHtml(s.nombre)}</strong> ${s.activo ? '' : '<span class="badge badge--off">Inactivo</span>'}
        <small class="muted"> · estándar ${mins(s.duracion_minutos)} · ${money(s.precio)}</small>
        <span class="svc__overrides">
          <input class="input" type="number" min="5" max="600" step="5" inputmode="numeric" data-dur placeholder="${s.duracion_minutos} min" value="${a && a.duracion_minutos_override !== null ? a.duracion_minutos_override : ''}" aria-label="Duración propia (minutos)" ${editable ? '' : 'disabled'}>
          <input class="input" type="number" min="0" step="0.10" inputmode="decimal" data-price placeholder="S/ ${Number(s.precio).toFixed(2)}" value="${a && a.precio_override !== null ? Number(a.precio_override).toFixed(2) : ''}" aria-label="Precio propio" ${editable ? '' : 'disabled'}>
        </span></span></label>`;
  }).join('') : '<p class="muted">La barbería aún no tiene servicios. Créalos en Servicios.</p>';
  $('[data-svc-save]').hidden = !editable;
}

$('[data-svc-save]').addEventListener('click', (e) => {
  const alert = $('[data-svc-alert]');
  alert.hidden = true;
  const servicios = [...document.querySelectorAll('[data-svc]')].filter((el) => el.querySelector('input[type="checkbox"]').checked).map((el) => ({
    servicio_id: Number(el.dataset.svc),
    duracion_minutos: el.querySelector('[data-dur]').value === '' ? null : Number(el.querySelector('[data-dur]').value),
    precio: el.querySelector('[data-price]').value === '' ? null : el.querySelector('[data-price]').value,
  }));
  withLoading(e.currentTarget, async () => {
    try {
      barber = await papi(`/panel/barberos/${id}/servicios`, { method: 'PUT', body: { servicios } });
      renderServices();
      toast('Servicios guardados');
    } catch (err) {
      noticeList(alert, err.message, nestedErrors(err, { servicios: 'Servicio' }));
    }
  });
});

// ------------------------------------------------------------------ jornada semanal
function loadWeek() {
  week = {};
  for (let d = 1; d <= 7; d++) week[d] = { tramos: [], descansos: [] };
  barber.horario.forEach((t) => week[t.dia_semana].tramos.push({ ...t }));
  barber.descansos.forEach((b) => week[b.dia_semana].descansos.push({ ...b }));
}

function principal() {
  const p = sucursales.find((s) => s.id === barber.sucursal_id && s.activo) || sucursales.find((s) => s.activo);
  return p ? p.id : null;
}

/** Lee del DOM los valores editados (antes de volver a dibujar o guardar). */
function syncWeek() {
  document.querySelectorAll('[data-day]').forEach((dayEl) => {
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

function renderWeek() {
  const editable = can(ctx, 'horarios.gestionar');
  const dis = editable ? '' : 'disabled';
  $('[data-week]').innerHTML = [1, 2, 3, 4, 5, 6, 7].map((d) => {
    const day = week[d];
    const on = day.tramos.length > 0;
    return `<div class="day" data-day="${d}" data-off="${!on}">
      <label class="day__name"><input type="checkbox" data-day-on ${on ? 'checked' : ''} ${dis}>${DIAS[d]}</label>
      <div class="day__slots">
        ${day.tramos.map((t) => `<div class="slot" data-tramo>
          <input type="time" name="ini" value="${escapeHtml(t.hora_inicio)}" step="300" aria-label="${DIAS[d]}: inicio" ${dis}>
          <span aria-hidden="true">a</span>
          <input type="time" name="fin" value="${escapeHtml(t.hora_fin)}" step="300" aria-label="${DIAS[d]}: fin" ${dis}>
          ${sel(sucursales, t.sucursal_id, 'suc', `${DIAS[d]}: sucursal`)}
          ${editable ? `<button class="icon-btn" type="button" data-remove aria-label="Quitar tramo">${icon('close')}</button>` : ''}</div>`).join('')}
        ${day.descansos.map((b) => `<div class="slot slot--break" data-descanso>
          <span class="slot__label">Descanso</span>
          <input type="time" name="ini" value="${escapeHtml(b.hora_inicio)}" step="300" aria-label="${DIAS[d]}: inicio del descanso" ${dis}>
          <span aria-hidden="true">a</span>
          <input type="time" name="fin" value="${escapeHtml(b.hora_fin)}" step="300" aria-label="${DIAS[d]}: fin del descanso" ${dis}>
          <input class="input" name="motivo" value="${escapeHtml(b.motivo || '')}" maxlength="80" placeholder="Almuerzo" aria-label="Motivo" style="max-width:140px;min-height:40px" ${dis}>
          ${editable ? `<button class="icon-btn" type="button" data-remove aria-label="Quitar descanso">${icon('close')}</button>` : ''}</div>`).join('')}
        ${editable ? `<div class="day__add"><button class="link-btn" type="button" data-add-tramo>+ Tramo</button><button class="link-btn" type="button" data-add-descanso>+ Descanso</button></div>` : ''}
      </div></div>`;
  }).join('');
  $('[data-week-save]').hidden = !editable;
  $('[data-week-copy]').hidden = !editable;
}

$('[data-week]').addEventListener('change', (e) => {
  if (!e.target.matches('[data-day-on]')) return;
  syncWeek();
  const d = Number(e.target.closest('[data-day]').dataset.day);
  if (e.target.checked && !week[d].tramos.length) week[d].tramos.push({ hora_inicio: '09:00', hora_fin: '18:00', sucursal_id: principal() });
  renderWeek();
});
$('[data-week]').addEventListener('click', (e) => {
  const dayEl = e.target.closest('[data-day]');
  if (!dayEl) return;
  const d = Number(dayEl.dataset.day);
  if (e.target.closest('[data-add-tramo]')) {
    syncWeek();
    const last = week[d].tramos[week[d].tramos.length - 1];
    week[d].tramos.push({ hora_inicio: last ? last.hora_fin : '09:00', hora_fin: last ? '20:00' : '18:00', sucursal_id: last ? last.sucursal_id : principal() });
    renderWeek();
  } else if (e.target.closest('[data-add-descanso]')) {
    syncWeek();
    week[d].descansos.push({ hora_inicio: '13:00', hora_fin: '14:00', motivo: 'Almuerzo' });
    renderWeek();
  } else if (e.target.closest('[data-remove]')) {
    e.target.closest('.slot').remove();
    syncWeek();
    renderWeek();
  }
});
$('[data-week-copy]').addEventListener('click', () => {
  syncWeek();
  for (let d = 2; d <= 6; d++) week[d] = JSON.parse(JSON.stringify(week[1]));
  renderWeek();
  toast('Lunes copiado de martes a sábado. Recuerda guardar.');
});

$('[data-week-save]').addEventListener('click', (e) => {
  syncWeek();
  const alert = $('[data-week-alert]');
  alert.hidden = true;
  const tramos = [];
  const descansos = [];
  for (let d = 1; d <= 7; d++) {
    week[d].tramos.forEach((t) => tramos.push({ dia_semana: d, hora_inicio: t.hora_inicio, hora_fin: t.hora_fin, sucursal_id: t.sucursal_id, vigente_desde: t.vigente_desde || null, vigente_hasta: t.vigente_hasta || null }));
    week[d].descansos.forEach((b) => descansos.push({ dia_semana: d, ...b }));
  }
  withLoading(e.currentTarget, async () => {
    try {
      const { data, meta } = await papi(`/panel/barberos/${id}/horario`, { method: 'PUT', body: { tramos, descansos }, withMeta: true });
      barber = data;
      loadWeek();
      renderWeek();
      toast('Jornada guardada');
      const fuera = meta.advertencias.reservas_fuera_de_jornada;
      if (fuera.length) {
        noticeList(alert, `Atención: ${fuera.length} reserva(s) ya tomadas quedan fuera de la nueva jornada. Se mantienen; reprográmalas si es necesario:`,
          fuera.map((r) => `${r.codigo} · ${new Date(r.inicio_utc.replace(' ', 'T') + 'Z').toLocaleString('es-PE', { dateStyle: 'medium', timeStyle: 'short' })}`), 'info');
      }
      await renderTimeline();
    } catch (err) {
      // Las claves de error usan el índice del tramo dentro de la lista enviada.
      const describe = (list, k) => { const t = list[Number(k)]; return t ? `${DIAS[t.dia_semana]} ${t.hora_inicio}–${t.hora_fin}` : ''; };
      const items = Object.entries(err.fields || {}).map(([k, msgs]) => {
        const [base, idx] = k.split('.');
        const where = base === 'tramos' ? describe(tramos, idx) : base === 'descansos' ? `Descanso ${describe(descansos, idx)}` : '';
        return `${where ? where + ': ' : ''}${msgs.join(' ')}`;
      });
      noticeList(alert, err.message, items);
    }
  });
});

// ------------------------------------------------------------------ vista previa
async function renderTimeline() {
  if (!can(ctx, 'horarios.gestionar')) { $('[data-timeline]').closest('section').hidden = true; return; }
  const desde = today();
  const hasta = today(6);
  const j = await papi(`/panel/barberos/${id}/jornada?desde=${desde}&hasta=${hasta}`);
  const START = 6 * 60;
  const SPAN = 17 * 60;
  const toMin = (hhmm) => { const [h, m] = hhmm.split(':').map(Number); return h * 60 + m; };
  const days = [];
  for (let i = 0; i <= 6; i++) days.push(today(i));
  $('[data-timeline]').innerHTML = days.map((f) => {
    const segs = j.ventanas.filter((w) => w.fecha === f);
    const bar = segs.map((w) => {
      const s = Math.max(0, toMin(w.inicio_local) - START);
      const e = Math.min(SPAN, toMin(w.fin_local) - START);
      return `<div class="tl-seg" style="left:${(s / SPAN) * 100}%;width:${((e - s) / SPAN) * 100}%" title="${escapeHtml(w.sucursal || '')} ${w.inicio_local}–${w.fin_local}"><span>${w.inicio_local}–${w.fin_local}</span></div>`;
    }).join('');
    const label = segs.length ? segs.map((w) => `${w.inicio_local}–${w.fin_local}`).join(', ') : 'Sin atención';
    return `<div class="tl-day"><span class="tl-day__label">${escapeHtml(fmtDate(f))}</span>
      <div class="tl-track" role="img" aria-label="${escapeHtml(fmtDate(f, { weekday: 'long', day: 'numeric', month: 'long' }))}: ${escapeHtml(label)}">${bar || '<span class="tl-empty" style="position:absolute;left:8px;top:4px">Sin atención</span>'}</div></div>`;
  }).join('');
}

// ------------------------------------------------------------------ arranque
async function load() {
  const [b, sucs, list] = await Promise.all([
    papi(`/panel/barberos/${id}`),
    papi('/panel/sucursales'),
    papi('/panel/barberos', { withMeta: true }),
  ]);
  barber = b;
  sucursales = sucs;
  if (can(ctx, 'servicios.gestionar')) {
    try { catalog = (await papi('/panel/servicios')).map((s) => ({ id: s.id, nombre: s.nombre, duracion_minutos: s.duracion_minutos, precio: s.precio, activo: s.activo })); } catch { catalog = null; }
  }
  setAccounts(list.meta.cuentas_vinculables);
  renderProfile();
  const canEdit = can(ctx, 'barberos.gestionar');
  $('[data-photo-actions]').hidden = !canEdit;
  form.querySelectorAll('input, select, textarea, button').forEach((el) => { el.disabled = !canEdit; });
  $('[data-absences]').href = `ausencias.html${q}${q ? '&' : '?'}barbero_id=${id}`;
  $('[data-absences]').hidden = !can(ctx, 'bloqueos.gestionar');
  renderServices();
  loadWeek();
  renderWeek();
  document.querySelectorAll('[data-section]').forEach((s) => { s.hidden = false; });
  await renderTimeline();
}

$('[data-back]').href = `barberos.html${q}`;
bootPanel('barberos').then(async (c) => {
  ctx = c;
  if (!ctx) { $('[data-loading]').hidden = true; return; }
  try {
    await load();
  } catch (e) {
    notice(noticeEl, e.status === 404 ? 'Barbero no encontrado.' : e.message, 'error');
  } finally {
    $('[data-loading]').hidden = true;
    $('[data-page]').removeAttribute('aria-busy');
  }
});
