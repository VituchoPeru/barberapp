import { $, clearErrors, showErrors, withLoading, escapeHtml, notice } from '../ui.js';
import { icon } from '../icons.js';
import { noticeList, today } from '../panel.js';
import {
  bootBarber, papi, toast, fmtDate, confirmAction, openSheet, TIPOS,
} from '../barbero.js';

/**
 * Panel del barbero — Ausencias (Fase 7): solicitar vacaciones, ausencias, descansos puntuales
 * o bloqueos de SU agenda. Según los ajustes de la barbería esperan aprobación del encargado
 * (y no quitan horarios hasta aprobarse) o se aplican al instante.
 */
const noticeEl = $('[data-notice]');
const sheet = $('[data-sheet]');
const form = $('[data-form]');
let ajustes = null;

function when(b) {
  if (b.dia_completo) {
    return b.fecha_inicio === b.fecha_fin ? fmtDate(b.fecha_inicio, { weekday: 'long', day: 'numeric', month: 'long' }) : `${fmtDate(b.fecha_inicio)} al ${fmtDate(b.fecha_fin)}`;
  }
  const [fi, hi] = b.inicio_local.split('T');
  const [ff, hf] = b.fin_local.split('T');
  return fi === ff ? `${fmtDate(fi)} · ${hi}–${hf}` : `${fmtDate(fi)} ${hi} al ${fmtDate(ff)} ${hf}`;
}

function row(b) {
  const general = b.alcance !== 'barbero';
  const titulo = `${TIPOS[b.tipo] || b.tipo}${general ? (b.alcance === 'sucursal' ? ` · sede ${b.sucursal || ''}` : ' · toda la barbería') : (b.sucursal ? ` · ${b.sucursal}` : '')}`;
  const resp = b.respondido_en && !general && b.estado !== 'solicitado'
    ? `<span>${b.estado === 'aprobado' ? 'Aprobada' : 'Respondida'}${b.aprobado_por ? ` por ${escapeHtml(b.aprobado_por)}` : ''} el ${escapeHtml(new Date(b.respondido_en).toLocaleDateString('es-PE', { day: 'numeric', month: 'short' }))}</span>` : '';
  return `<li class="row" data-inactive="${b.estado === 'rechazado'}"><span class="thumb">${icon(general ? 'store' : 'calendar')}</span>
    <div class="row__main"><p class="row__title">${escapeHtml(titulo)}</p>
      <p class="row__meta"><span>${escapeHtml(when(b))}</span>${b.motivo ? `<span>${escapeHtml(b.motivo)}</span>` : ''}${resp}</p>
      ${b.respuesta ? `<p class="bb-answer">${icon('chat')} ${escapeHtml(b.respuesta)}</p>` : ''}</div>
    <div class="row__actions">${b.estado === 'solicitado' && !general ? `<button class="btn btn--ghost btn--sm" type="button" data-withdraw="${b.id}">Retirar</button>` : ''}</div></li>`;
}

async function load() {
  try {
    const d = await papi('/barbero/ausencias');
    ajustes = d.ajustes;
    const now = Date.now();
    const groups = { solicitado: [], aprobado: [], general: [], rechazado: [] };
    d.items.forEach((b) => {
      if (b.alcance !== 'barbero') { if (new Date(b.fin_utc).getTime() > now) groups.general.push(b); return; }
      if (b.estado === 'aprobado' && new Date(b.fin_utc).getTime() <= now) return; // ya pasó
      groups[b.estado].push(b);
    });
    Object.entries(groups).forEach(([k, list]) => {
      $(`[data-sec="${k}"]`).hidden = !list.length;
      $(`[data-list="${k}"]`).innerHTML = list.map(row).join('');
    });
    const empty = !Object.values(groups).some((l) => l.length);
    $('[data-empty]').hidden = !empty;
    $('[data-empty]').innerHTML = empty ? `<div class="empty">${icon('calendar')}<h3>Sin ausencias</h3><p>Pide aquí tus vacaciones o un bloqueo de horario. Tu encargado recibirá el aviso.</p></div>` : '';
    $('[data-new]').hidden = !ajustes.puede_solicitar_ausencias;
    $('[data-rule]').textContent = ajustes.ausencias_requieren_aprobacion
      ? 'Tus solicitudes esperan la aprobación del encargado. Mientras tanto, tus horarios siguen disponibles para los clientes.'
      : 'Tus ausencias se aplican al instante: ese tiempo deja de ofrecerse a los clientes.';
    const sel = form.sucursal_id;
    if (sel.options.length === 1) sel.insertAdjacentHTML('beforeend', d.sucursales.map((s) => `<option value="${s.id}">${escapeHtml(s.nombre)}</option>`).join(''));
    $('[data-branch-field]').hidden = d.sucursales.length < 2;
  } catch (e) {
    notice(noticeEl, e.message, 'error');
  } finally {
    $('[data-loading]').hidden = true;
    $('[data-page]').removeAttribute('aria-busy');
  }
}

function syncForm() {
  const full = form.dia_completo.checked;
  $('[data-days]').hidden = !full;
  $('[data-hours]').hidden = full;
}

function openForm() {
  form.reset();
  clearErrors(form);
  form.fecha_inicio.min = today();
  form.fecha_fin.min = today();
  form.fecha_inicio.value = today(1);
  const req = ajustes.ausencias_requieren_aprobacion;
  $('[data-approval-hint]').textContent = req
    ? 'El encargado revisará tu solicitud. Si ya tienes citas en esas fechas, deberán reprogramarse antes de aprobarla.'
    : 'Se aplicará al instante. No se puede bloquear un horario donde ya tienes citas reservadas.';
  $('[data-motivo-req]').textContent = req ? '(obligatorio)' : '(opcional)';
  $('[data-submit]').textContent = req ? 'Enviar solicitud' : 'Bloquear agenda';
  $('#req-title').textContent = req ? 'Solicitar ausencia' : 'Bloquear mi agenda';
  syncForm();
  openSheet(sheet);
}

form.dia_completo.addEventListener('change', syncForm);
form.fecha_inicio.addEventListener('change', () => { if (!form.fecha_fin.value || form.fecha_fin.value < form.fecha_inicio.value) form.fecha_fin.value = form.fecha_inicio.value; });
form.inicio.addEventListener('change', () => {
  if (form.inicio.value && (!form.fin.value || form.fin.value <= form.inicio.value)) {
    const d = new Date(form.inicio.value);
    d.setHours(d.getHours() + 1);
    form.fin.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  }
});

form.addEventListener('submit', (e) => {
  e.preventDefault();
  clearErrors(form);
  const full = form.dia_completo.checked;
  const body = {
    tipo: form.tipo.value,
    dia_completo: full,
    sucursal_id: form.sucursal_id.value ? Number(form.sucursal_id.value) : null,
    motivo: form.motivo.value.trim() || null,
    ...(full ? { fecha_inicio: form.fecha_inicio.value || null, fecha_fin: form.fecha_fin.value || form.fecha_inicio.value || null }
      : { inicio: form.inicio.value || null, fin: form.fin.value || null }),
  };
  withLoading($('[data-submit]'), async () => {
    try {
      const b = await papi('/barbero/ausencias', { method: 'POST', body });
      sheet.close();
      toast(b.estado === 'solicitado' ? 'Solicitud enviada al encargado' : 'Agenda bloqueada');
      const conf = (b.advertencias && b.advertencias.reservas_en_conflicto) || [];
      if (conf.length) {
        noticeList(noticeEl, `Ojo: tienes ${conf.length} cita(s) en ese periodo. El encargado deberá reprogramarlas antes de aprobar tu solicitud:`,
          conf.map((r) => `${r.codigo} · ${fmtDate(r.inicio.slice(0, 10))} ${r.inicio.slice(11)}`), 'info');
      } else noticeEl.hidden = true;
      await load();
    } catch (err) {
      if (err.code === 'block_conflicts_bookings') {
        const al = $('[data-form-alert]', form);
        noticeList(al, err.message, err.details.reservas.map((r) => `${r.codigo} · ${new Date(r.inicio_utc.replace(' ', 'T') + 'Z').toLocaleString('es-PE', { dateStyle: 'medium', timeStyle: 'short' })}`));
      } else showErrors(form, err);
    }
  });
});

document.addEventListener('click', async (e) => {
  const w = e.target.closest('[data-withdraw]');
  if (!w) return;
  if (!(await confirmAction('¿Retirar esta solicitud? El encargado ya no la verá.', { ok: 'Retirar solicitud', danger: true }))) return;
  try {
    await papi(`/barbero/ausencias/${w.dataset.withdraw}`, { method: 'DELETE' });
    toast('Solicitud retirada');
    await load();
  } catch (err) { toast(err.message, 'error'); }
});

(async () => {
  const ctx = await bootBarber('ausencias');
  if (!ctx) return;
  $('[data-new]').addEventListener('click', openForm);
  await load();
})();
