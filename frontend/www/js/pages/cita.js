import { api } from '../api.js';
import { saveFile } from '../native.js';
import { escapeHtml, clearErrors, showErrors, withLoading } from '../ui.js';
import { icon } from '../icons.js';
import { money, minutes, safeUrl, avatarHtml, emptyHtml, mountTabbar, loginUrl, page, me, toast } from '../marketplace.js';
import { ESTADOS, statusChip, longDate, addDays, daysHtml, slotsHtml, icsFile, mapsUrl, idempotencyKey, forgetIdempotencyKey } from '../booking.js';

/**
 * Comprobante y detalle de una cita (Fase 5): código, datos, historial y acciones
 * (confirmar asistencia, reprogramar, cancelar, agregar al calendario, cómo llegar).
 * Cada acción la vuelve a validar el backend con la política de la barbería.
 */
document.querySelectorAll('[data-icon]').forEach((el) => { el.innerHTML = icon(el.dataset.icon); });
mountTabbar('citas');

const $ = (s, r = document) => r.querySelector(s);
const params = new URLSearchParams(location.search);
const id = Number(params.get('id'));
const nueva = params.get('nueva') === '1';
const root = $('[data-root]');
let r = null;

/** Fecha y hora en la zona horaria de la barbería (no la del teléfono), en formato de 24 horas. */
const fmtDateTime = (iso) => new Date(iso).toLocaleString('es-PE', {
  day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: (r && r.zona_horaria) || undefined,
});

function render() {
  const [titulo, sub] = ESTADOS[r.estado] || [r.estado, ''];
  const a = r.acciones;
  const wa = safeUrl(r.barberia.whatsapp ? `https://wa.me/${r.barberia.whatsapp.replace(/\D/g, '')}` : null);
  root.innerHTML = `
    ${nueva && r.acciones.activa ? `<div class="alert" data-type="ok" role="status">${icon('sparkle')} ${r.estado === 'confirmada' ? '¡Reserva confirmada!' : '¡Solicitud enviada! La barbería la confirmará pronto.'} Guarda tu código.</div>` : ''}
    <article class="ticket" aria-labelledby="t-title">
      <header class="ticket__head">
        ${avatarHtml(r.barberia.nombre, r.barberia.logo_url)}
        <div><h2 id="t-title">${escapeHtml(r.barberia.nombre)}</h2><p>${escapeHtml(r.sucursal.nombre)}</p></div>
        ${statusChip(r.estado)}
      </header>
      <div class="ticket__when">
        <p class="ticket__date">${escapeHtml(longDate(r.fecha))}</p>
        <p class="ticket__time">${escapeHtml(r.hora_inicio)} <span>– ${escapeHtml(r.hora_fin)}</span></p>
        <p class="hint">${escapeHtml(sub)}${r.asistencia_confirmada && a.activa ? ' · Confirmaste tu asistencia' : ''}</p>
      </div>
      <div class="ticket__code"><span>Código</span><strong>${escapeHtml(r.codigo)}</strong></div>
      <dl class="ticket__rows">
        <dt>Barbero</dt><dd>${escapeHtml(r.barbero.nombre)}${r.barbero_asignado_auto ? ' <small>(asignado)</small>' : ''}</dd>
        ${r.servicios.map((s) => `<dt>${escapeHtml(s.hora_inicio)}</dt><dd>${escapeHtml(s.nombre)} <small>${minutes(s.duracion_minutos)} · ${money(s.precio, r.moneda)}</small></dd>`).join('')}
        <dt>Dirección</dt><dd>${escapeHtml(r.sucursal.direccion)}, ${escapeHtml(r.sucursal.distrito)}${r.sucursal.referencia ? `<br><small>${escapeHtml(r.sucursal.referencia)}</small>` : ''}</dd>
        ${r.notas_cliente ? `<dt>Tu nota</dt><dd>${escapeHtml(r.notas_cliente)}</dd>` : ''}
        <dt class="total">Total a pagar en el local</dt><dd class="total">${money(r.precio_total, r.moneda)}</dd>
      </dl>
      ${r.cancelacion ? `<p class="alert" role="status">Cancelada ${r.cancelacion.por === 'cliente' ? 'por ti' : 'por la barbería'} el ${escapeHtml(fmtDateTime(r.cancelacion.fecha))}${r.cancelacion.motivo ? `: “${escapeHtml(r.cancelacion.motivo)}”` : '.'}</p>` : ''}
    </article>

    ${a.activa ? `<div class="actions actions--stack">
      ${a.puede_confirmar_asistencia ? `<button class="btn" type="button" data-attend>${icon('sparkle')}Confirmar que asistiré</button>` : ''}
      <button class="btn btn--ghost" type="button" data-ics>${icon('calendar')}Agregar a mi calendario</button>
      <a class="btn btn--ghost" href="${escapeHtml(mapsUrl(r.sucursal))}" target="_blank" rel="noopener noreferrer">${icon('navigation')}Cómo llegar</a>
      ${wa ? `<a class="btn btn--ghost" href="${escapeHtml(wa)}?text=${encodeURIComponent(`Hola, sobre mi cita ${r.codigo} del ${longDate(r.fecha)} a las ${r.hora_inicio}.`)}" target="_blank" rel="noopener noreferrer">${icon('chat')}Escribir a la barbería</a>` : ''}
    </div>
    <section class="step">
      <h2>Cambios</h2>
      <div class="actions">
        <button class="btn btn--ghost" type="button" data-resched ${a.puede_reprogramar ? '' : 'disabled'}>Reprogramar</button>
        <button class="btn btn--danger" type="button" data-cancel ${a.puede_cancelar ? '' : 'disabled'}>Cancelar cita</button>
      </div>
      <p class="hint">${policyHint()}</p>
    </section>` : `<div class="actions"><a class="btn" href="${page(`reservar.html?slug=${encodeURIComponent(r.barberia.slug)}`)}">Reservar de nuevo</a></div>`}

    ${r.historial && r.historial.length ? `<section class="step"><h2>Historial</h2><ol class="timeline">
      ${r.historial.map((h) => `<li><span>${escapeHtml(h.texto)}</span><time datetime="${h.fecha}">${escapeHtml(fmtDateTime(h.fecha))}</time></li>`).join('')}
    </ol></section>` : ''}`;
}

function policyHint() {
  const a = r.acciones;
  const parts = [];
  parts.push(a.puede_cancelar ? `Puedes cancelar hasta el ${fmtDateTime(a.cancelar_hasta)}.` : 'El plazo para cancelar en línea ya venció; comunícate con la barbería.');
  if (a.puede_reprogramar) parts.push(`Puedes reprogramar hasta el ${fmtDateTime(a.reprogramar_hasta)} (${a.reprogramaciones_restantes} ${a.reprogramaciones_restantes === 1 ? 'cambio disponible' : 'cambios disponibles'}).`);
  else if (a.reprogramaciones_restantes === 0) parts.push('Ya usaste los cambios de horario permitidos.');
  if (r.politica.texto) parts.push(r.politica.texto);
  return escapeHtml(parts.join(' '));
}

async function load() {
  if (!id) {
    root.innerHTML = emptyHtml('calendar', 'Cita no encontrada', 'El enlace no es válido.', `<a class="btn" href="${page('mis-citas.html')}">Ver mis citas</a>`);
    return;
  }
  try {
    const user = await me();
    if (!user) { location.replace(loginUrl()); return; }
    r = await api(`/mis-reservas/${id}`);
    document.title = `Cita ${r.codigo} · BARBERAPP`;
    render();
  } catch (e) {
    root.innerHTML = emptyHtml('calendar', e.status === 404 ? 'Cita no encontrada' : 'No pudimos cargar la cita', e.message,
      `<a class="btn" href="${page('mis-citas.html')}">Ver mis citas</a>`);
  }
}

// ------------------------------------------------------------------ acciones simples

root.addEventListener('click', async (e) => {
  const attend = e.target.closest('[data-attend]');
  if (attend) {
    try {
      await withLoading(attend, () => api(`/mis-reservas/${id}/confirmar-asistencia`, { method: 'POST', body: {} }));
      toast('¡Gracias! Avisamos a la barbería');
      load();
    } catch (err) { toast(err.message, 'error'); }
    return;
  }
  if (e.target.closest('[data-ics]')) {
    // Web: descarga el .ics. App: "Compartir" permite abrirlo con el calendario del teléfono.
    saveFile(icsFile(r), `cita-${r.codigo}.ics`, 'Agregar al calendario').catch((err) => toast(err.message, 'error'));
    return;
  }
  if (e.target.closest('[data-cancel]')) { openCancel(); return; }
  if (e.target.closest('[data-resched]')) openResched();
});

// ------------------------------------------------------------------ cancelar

const cancelDialog = $('[data-cancel-dialog]');
const cancelForm = $('[data-cancel-form]');
function openCancel() {
  clearErrors(cancelForm);
  cancelForm.reset();
  $('[data-cancel-text]').textContent = `${longDate(r.fecha)} a las ${r.hora_inicio} en ${r.barberia.nombre}. El horario quedará libre para otra persona.`;
  cancelDialog.showModal();
}
cancelForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  clearErrors(cancelForm);
  const btn = cancelForm.querySelector('[type="submit"]');
  try {
    await withLoading(btn, () => api(`/mis-reservas/${id}/cancelar`, { method: 'POST', body: { motivo: cancelForm.motivo.value.trim() || null } }));
    cancelDialog.close();
    toast('Cita cancelada');
    load();
  } catch (err) { showErrors(cancelForm, err); }
});

// ------------------------------------------------------------------ reprogramar

const rd = $('[data-resched-dialog]');
const rbody = $('[data-resched-body]');
const rbtn = $('[data-resched-confirm]');
const rs = { dias: [], fecha: null, hora: null, horarios: null, info: null, error: null };

function renderResched() {
  rbody.innerHTML = `
    <p class="hint">Tu cita actual (${escapeHtml(longDate(r.fecha))}, ${escapeHtml(r.hora_inicio)}) se mantiene hasta que confirmes el cambio.</p>
    ${rs.error ? `<p class="alert" role="alert">${escapeHtml(rs.error)}</p>` : ''}
    ${rs.info ? daysHtml(rs.dias, rs.fecha) : '<div class="sk" style="height:84px"></div>'}
    ${rs.fecha ? `<h3 class="sheet__sub">${escapeHtml(longDate(rs.fecha))}</h3>${rs.horarios ? slotsHtml(rs.horarios.filter((h) => !(rs.fecha === r.fecha && h.hora === r.hora_inicio)), rs.hora) : '<div class="sk" style="height:96px"></div>'}` : ''}`;
  rbtn.disabled = !rs.hora;
  rbtn.textContent = rs.hora ? `Cambiar a las ${rs.hora}` : 'Elige una hora';
}

async function reschedDays() {
  try {
    const desde = new Date().toISOString().slice(0, 10);
    rs.info = await api(`/mis-reservas/${id}/disponibilidad?desde=${rs.info?.hoy || desde}&hasta=${addDays(rs.info?.hoy || desde, 20)}`);
    rs.dias = rs.info.dias.filter((d) => d.reservable);
    rs.fecha = rs.dias.some((d) => d.fecha === r.fecha && d.disponibles) ? r.fecha : (rs.dias.find((d) => d.disponibles) || {}).fecha || null;
    renderResched();
    if (rs.fecha) reschedSlots();
  } catch (e) { rs.error = e.message; renderResched(); }
}

async function reschedSlots() {
  rs.horarios = null; rs.hora = null; renderResched();
  try {
    const res = await api(`/mis-reservas/${id}/disponibilidad?fecha=${rs.fecha}`);
    rs.horarios = res.horarios;
  } catch (e) { rs.error = e.message; rs.horarios = []; }
  renderResched();
}

function openResched() {
  Object.assign(rs, { dias: [], fecha: null, hora: null, horarios: null, info: null, error: null });
  renderResched();
  rd.showModal();
  reschedDays();
}

rbody.addEventListener('click', (e) => {
  const d = e.target.closest('.day');
  if (d && d.getAttribute('aria-disabled') !== 'true') { rs.fecha = d.dataset.fecha; rs.error = null; reschedSlots(); return; }
  const s = e.target.closest('.slot');
  if (s) { rs.hora = s.dataset.hora; rs.error = null; renderResched(); rbody.querySelector(`.slot[data-hora="${rs.hora}"]`)?.focus(); }
});

rbtn.addEventListener('click', async () => {
  const body = { fecha: rs.fecha, hora: rs.hora };
  const key = idempotencyKey(`reprogramar.${id}`, body);
  try {
    await withLoading(rbtn, () => api(`/mis-reservas/${id}/reprogramar`, { method: 'POST', body, headers: { 'Idempotency-Key': key }, timeout: 25000 }));
    forgetIdempotencyKey(`reprogramar.${id}`);
    rd.close();
    toast('Cita reprogramada');
    load();
  } catch (e) {
    if (e.code !== 'network_error') forgetIdempotencyKey(`reprogramar.${id}`);
    rs.error = e.code === 'network_error' ? 'Problema de conexión. Intenta otra vez: el cambio no se duplicará.' : e.message;
    if (e.code === 'slot_unavailable') reschedSlots(); else renderResched();
  }
});

document.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));
[cancelDialog, rd].forEach((d) => d.addEventListener('click', (e) => { if (e.target === d) d.close(); }));
load();
