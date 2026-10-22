import { $, escapeHtml, notice } from '../ui.js';
import { icon } from '../icons.js';
import {
  bootBarber, papi, q, toast, mins, citaCard, openCita, actionButtons, runAction, relative, dayLabel,
  toMin, toHHMM, telLink, dialogOpen, TIPOS,
} from '../barbero.js';

/**
 * Panel del barbero — Hoy (Fase 7): la cita en atención o la siguiente con sus botones,
 * y la agenda del día con jornada, huecos libres, descansos y ausencias.
 * Se actualiza cada 60 s mientras la pestaña está visible y no hay una hoja abierta.
 */
const params = new URLSearchParams(location.search);
const noticeEl = $('[data-notice]');
let fecha = params.get('fecha') || '';
let data = null;
let timer = null;

const FREE_MIN = 15; // huecos libres que vale la pena mostrar

function setFecha(f) {
  fecha = f;
  const p = new URLSearchParams(location.search);
  if (f && data && f !== data.hoy) p.set('fecha', f); else p.delete('fecha');
  history.replaceState(null, '', `${location.pathname}${p.toString() ? `?${p}` : ''}`);
}

function shift(ymd, days) {
  const d = new Date(`${ymd}T12:00:00`);
  d.setDate(d.getDate() + days);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Hora actual en la zona horaria de la sede del barbero. */
function nowIn(tz) {
  return new Date().toLocaleTimeString('en-GB', { timeZone: tz, hour: '2-digit', minute: '2-digit', hour12: false });
}

function renderStats(d) {
  const r = d.resumen;
  const porAtender = r.pendiente + r.confirmada;
  const occ = r.minutos_jornada > 0 ? Math.min(100, Math.round((r.minutos_reservados * 100) / r.minutos_jornada)) : null;
  const stat = (label, value, accent = false) => `<div class="bb-stat${accent ? ' bb-stat--accent' : ''}"><strong>${escapeHtml(String(value))}</strong><span>${escapeHtml(label)}</span></div>`;
  $('[data-stats]').innerHTML = stat('Citas', r.total, true) + stat('Por atender', porAtender) + stat('Atendidas', r.completada)
    + stat('No asistió', r.no_asistio) + stat('Ocupación', occ === null ? '—' : `${occ} %`);
}

function renderFocus(d) {
  const box = $('[data-focus]');
  const id = d.en_atencion_id || d.siguiente_id;
  const c = d.fecha === d.hoy && id ? d.citas.find((x) => x.id === id) : null;
  if (!c) { box.hidden = true; return; }
  const live = c.en_atencion;
  const tel = telLink(c.cliente.telefono);
  const iniciaPronto = !live && !c.acciones.iniciar && !c.acciones.completar;
  box.dataset.live = String(live);
  box.innerHTML = `
    <p class="bb-focus__label">${live ? '<span class="badge badge--live">En atención</span>' : `Siguiente · <span data-rel>${escapeHtml(relative(c.inicio_utc))}</span>`}</p>
    <button type="button" class="bb-focus__main" data-cita="${c.id}">
      <span class="bb-focus__time">${escapeHtml(c.hora_inicio)}<small>–${escapeHtml(c.hora_fin)}</small></span>
      <span class="bb-focus__who"><strong>${escapeHtml(c.cliente.nombre)}</strong>
        <span>${escapeHtml(c.servicios.map((s) => s.nombre).join(' + '))} · ${mins(c.duracion_total_minutos)}</span>
        ${c.notas_cliente ? `<em>“${escapeHtml(c.notas_cliente)}”</em>` : ''}
        ${c.notas_internas ? `<em>${icon('scissors')} ${escapeHtml(c.notas_internas)}</em>` : ''}</span>
    </button>
    <div class="bb-focus__actions">${actionButtons(c, { big: true })}
      ${tel ? `<a class="btn btn--ghost" href="tel:${escapeHtml(tel)}" aria-label="Llamar a ${escapeHtml(c.cliente.nombre)}">${icon('phone')}</a>` : ''}</div>
    ${iniciaPronto ? '<p class="hint">Podrás iniciar la atención desde 30 minutos antes de la hora.</p>' : ''}`;
  box.hidden = false;
  box.querySelectorAll('[data-act]').forEach((b) => {
    b.onclick = async () => { if (await runAction(c, b.dataset.act, b)) load({ silent: true }); };
  });
}

/** Une jornada, citas, descansos, ausencias, huecos libres y la hora actual en una línea de tiempo. */
function timelineItems(d) {
  const items = [];
  const busy = d.citas.filter((c) => c.estado !== 'cancelada').map((c) => [toMin(c.hora_inicio), c.hora_fin === '24:00' ? 1440 : toMin(c.hora_fin)]).sort((a, b) => a[0] - b[0]);
  const wins = d.jornada.map((w) => ({ ...w, s: toMin(w.inicio), e: w.fin === '24:00' ? 1440 : toMin(w.fin) })).sort((a, b) => a.s - b.s);
  const sedes = new Set(wins.map((w) => w.sucursal));
  const covered = (t) => d.descansos.some((b) => toMin(b.inicio) === t) || d.bloqueos.some((b) => toMin(b.inicio) <= t && (b.fin === '24:00' ? 1440 : toMin(b.fin)) > t);
  wins.forEach((w, i) => {
    const sede = sedes.size > 1 || w.sucursal !== d.barbero.sucursal.nombre ? ` · ${escapeHtml(w.sucursal || '')}` : '';
    if (i === 0) items.push({ t: w.s, order: 0, html: mark(w.inicio, `Inicio de jornada${sede}`) });
    else if (sedes.size > 1 && w.sucursal !== wins[i - 1].sucursal) items.push({ t: w.s, order: 0, html: mark(w.inicio, `Continúas en${sede.replace(' ·', '')}`) });
    if (i === wins.length - 1) items.push({ t: w.e, order: 3, html: mark(w.fin, 'Fin de jornada') });
    else if (!covered(w.e) && wins[i + 1].s > w.e) items.push({ t: w.e, order: 3, html: mark(w.fin, `Sin atención hasta las ${wins[i + 1].inicio}`) });
    // Huecos libres dentro de la jornada.
    let cur = w.s;
    busy.filter(([bs, be]) => be > w.s && bs < w.e).forEach(([bs, be]) => {
      if (bs - cur >= FREE_MIN) items.push({ t: cur, order: 1, html: free(cur, bs) });
      cur = Math.max(cur, be);
    });
    if (w.e - cur >= FREE_MIN) items.push({ t: cur, order: 1, html: free(cur, w.e) });
  });
  d.descansos.forEach((b) => items.push({ t: toMin(b.inicio), order: 1, html: `<li class="bb-tl bb-tl--rest"><span class="bb-tl__time">${escapeHtml(b.inicio)}</span><span>${icon('clock')} ${escapeHtml(b.motivo || 'Descanso')} hasta las ${escapeHtml(b.fin)}</span></li>` }));
  d.bloqueos.forEach((b) => items.push({
    t: toMin(b.inicio),
    order: 1,
    html: `<li class="bb-tl bb-tl--block" data-estado="${b.estado}"><span class="bb-tl__time">${escapeHtml(b.inicio)}</span><span>${icon('bell')} ${escapeHtml(TIPOS[b.tipo] || b.tipo)}${b.general ? ' de la barbería' : ''} hasta las ${escapeHtml(b.fin)}
      ${b.estado === 'solicitado' ? '<span class="badge badge--warn">Esperando aprobación</span>' : ''}${b.motivo ? ` · ${escapeHtml(b.motivo)}` : ''}</span></li>`,
  }));
  d.citas.forEach((c) => items.push({ t: toMin(c.hora_inicio), order: 2, html: `<li class="bb-tl bb-tl--cita">${citaCard(c)}</li>` }));
  if (d.fecha === d.hoy) {
    const now = nowIn(d.barbero.zona_horaria);
    items.push({ t: toMin(now), order: -1, html: `<li class="bb-tl bb-tl--now" aria-label="Hora actual ${now}"><span class="bb-tl__time">${now}</span><span class="bb-now"></span></li>` });
  }
  return items.sort((a, b) => a.t - b.t || a.order - b.order);
}

const mark = (time, text) => `<li class="bb-tl bb-tl--mark"><span class="bb-tl__time">${escapeHtml(time)}</span><span>${text}</span></li>`;
const free = (s, e) => `<li class="bb-tl bb-tl--free"><span class="bb-tl__time">${toHHMM(s)}</span><span>Libre hasta las ${toHHMM(e)} · ${mins(e - s)}</span></li>`;

function renderTimeline(d) {
  const tl = $('[data-timeline]');
  if (!d.jornada.length && !d.citas.length) {
    const ausente = d.bloqueos.find((b) => b.estado === 'aprobado');
    tl.innerHTML = `<li class="empty">${icon('calendar')}<h3>${ausente ? `${escapeHtml(TIPOS[ausente.tipo] || 'Ausencia')}` : 'No tienes jornada este día'}</h3>
      <p>${ausente ? 'Este día está bloqueado en tu agenda.' : 'No se te pueden reservar citas. Revisa tu horario semanal.'}</p>
      <a class="btn btn--ghost btn--sm" href="horario.html${q}">Ver mi horario</a></li>`;
    return;
  }
  tl.innerHTML = timelineItems(d).map((i) => i.html).join('');
}

function render(d) {
  data = d;
  if (!fecha) fecha = d.fecha;
  $('[data-hello]').textContent = `Hola, ${d.barbero.nombre.split(' ')[0]}`;
  $('[data-date-title]').textContent = dayLabel(d.fecha, d.hoy);
  $('[data-fecha]').value = d.fecha;
  $('[data-today]').hidden = d.fecha === d.hoy;
  renderStats(d);
  renderFocus(d);
  renderTimeline(d);
  const pend = d.solicitudes_pendientes;
  $('[data-pending]').hidden = !pend;
  $('[data-pending-text]').textContent = `${pend} solicitud${pend === 1 ? '' : 'es'} de ausencia esperando respuesta`;
  $('[data-updated]').textContent = `Actualizado ${new Date(d.actualizado_en).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })}`;
  $('[data-day-section]').hidden = false;
}

async function load({ silent = false } = {}) {
  if (!silent) { $('[data-loading]').hidden = false; $('[data-day-section]').hidden = true; $('[data-focus]').hidden = true; }
  try {
    const qs = fecha ? `?fecha=${encodeURIComponent(fecha)}` : '';
    render(await papi(`/barbero/hoy${qs}`));
    noticeEl.hidden = true;
  } catch (e) {
    if (silent) { $('[data-updated]').textContent = 'Sin conexión: se reintentará'; return; }
    notice(noticeEl, e.message, 'error');
  } finally {
    $('[data-loading]').hidden = true;
    $('[data-page]').removeAttribute('aria-busy');
  }
}

function go(f) { setFecha(f); load(); }

function schedule() {
  clearInterval(timer);
  timer = setInterval(() => {
    if (document.visibilityState === 'visible' && !dialogOpen()) load({ silent: true });
  }, 60000);
}

(async () => {
  const ctx = await bootBarber('index');
  if (!ctx) return;
  $('[data-prev]').onclick = () => go(shift(fecha || data.hoy, -1));
  $('[data-next]').onclick = () => go(shift(fecha || data.hoy, 1));
  $('[data-today]').onclick = () => go(data ? data.hoy : '');
  $('[data-fecha]').onchange = (e) => { if (e.target.value) go(e.target.value); };
  $('[data-refresh]').onclick = () => load({ silent: true }).then(() => toast('Agenda actualizada'));
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-cita]');
    if (b) openCita(Number(b.dataset.cita), () => load({ silent: true }));
  });
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && !dialogOpen()) load({ silent: true }); });
  await load();
  schedule();
  // Desde un aviso (Fase 8): ?cita=ID abre directamente el detalle de esa cita.
  const citaAviso = Number(new URLSearchParams(location.search).get('cita'));
  if (citaAviso > 0) openCita(citaAviso, () => load({ silent: true }));
})();
