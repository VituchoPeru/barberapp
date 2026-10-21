import { $, clearErrors, showErrors, withLoading, escapeHtml, notice } from '../ui.js';
import { icon } from '../icons.js';
import { bootPanel, papi, openSheet, confirmAction, toast, today, fmtDate, noticeList } from '../panel.js';

/** Panel: vacaciones, ausencias, feriados y bloqueos de agenda; aprobación de solicitudes (Fase 4). */
const params = new URLSearchParams(location.search);
const listEl = $('[data-list]');
const pendingEl = $('[data-pending]');
const sheet = $('[data-sheet]');
const form = $('[data-form]');
const noticeEl = $('[data-notice]');
const TIPOS = { vacaciones: 'Vacaciones', ausencia: 'Ausencia', descanso: 'Descanso', feriado: 'Feriado', bloqueo: 'Bloqueo' };
const ESTADOS = { solicitado: ['Pendiente', 'badge--warn'], aprobado: ['Aprobado', 'badge--ok'], rechazado: ['Rechazado', 'badge--off'] };
const filters = { barbero_id: params.get('barbero_id') || '', estado: '' };

function when(b) {
  if (b.dia_completo) {
    return b.fecha_inicio === b.fecha_fin ? fmtDate(b.fecha_inicio, { weekday: 'long', day: 'numeric', month: 'long' })
      : `${fmtDate(b.fecha_inicio)} al ${fmtDate(b.fecha_fin)}`;
  }
  const [fi, hi] = b.inicio_local.split('T');
  const [ff, hf] = b.fin_local.split('T');
  return fi === ff ? `${fmtDate(fi)} · ${hi}–${hf}` : `${fmtDate(fi)} ${hi} al ${fmtDate(ff)} ${hf}`;
}

function row(b) {
  const quien = b.alcance === 'barbero' ? escapeHtml(b.barbero || 'Barbero') : b.alcance === 'sucursal' ? `Toda la sede ${escapeHtml(b.sucursal || '')}` : 'Toda la barbería';
  const [estado, cls] = ESTADOS[b.estado];
  const actions = b.estado === 'solicitado'
    ? `<button class="btn btn--sm" type="button" data-approve="${b.id}">Aprobar</button><button class="btn btn--ghost btn--sm" type="button" data-reject="${b.id}">Rechazar</button>`
    : `<button class="icon-btn" type="button" data-delete="${b.id}" aria-label="Eliminar">${icon('trash')}</button>`;
  return `<li class="row" data-inactive="${b.estado === 'rechazado'}"><span class="thumb">${icon('calendar')}</span>
    <div class="row__main"><p class="row__title">${TIPOS[b.tipo]} · ${quien}</p>
      <p class="row__meta"><span>${escapeHtml(when(b))}</span><span class="badge ${cls}">${estado}</span>
      ${b.motivo ? `<span>${escapeHtml(b.motivo)}</span>` : ''}${b.creado_por && b.estado === 'solicitado' ? `<span>Pidió: ${escapeHtml(b.creado_por)}</span>` : ''}</p></div>
    <div class="row__actions">${actions}</div></li>`;
}

async function load() {
  const qs = new URLSearchParams(Object.entries(filters).filter(([, v]) => v));
  const items = await papi(`/panel/bloqueos?${qs}`);
  const pend = items.filter((b) => b.estado === 'solicitado');
  const rest = items.filter((b) => b.estado !== 'solicitado');
  $('[data-pending-title]').hidden = !pend.length;
  pendingEl.innerHTML = pend.map(row).join('');
  listEl.innerHTML = rest.length ? rest.map(row).join('')
    : `<li class="empty">${icon('calendar')}<h2>Sin ausencias registradas</h2><p>Registra vacaciones, feriados o bloqueos para que ese tiempo no se ofrezca a los clientes.</p></li>`;
}

function syncForm() {
  const alcance = form.alcance.value;
  document.querySelectorAll('[data-for-alcance]').forEach((el) => { el.hidden = el.dataset.forAlcance !== alcance; });
  const full = form.dia_completo.checked;
  $('[data-days]').hidden = !full;
  $('[data-hours]').hidden = full;
}
form.alcance.addEventListener('change', syncForm);
form.dia_completo.addEventListener('change', syncForm);
form.tipo.addEventListener('change', () => {
  if (form.tipo.value === 'feriado' && form.alcance.value === 'barbero') { form.alcance.value = 'barberia'; syncForm(); }
});

form.addEventListener('submit', (e) => {
  e.preventDefault();
  clearErrors(form);
  const alcance = form.alcance.value;
  const full = form.dia_completo.checked;
  const body = {
    tipo: form.tipo.value,
    barbero_id: alcance === 'barbero' ? Number(form.barbero_id.value) || null : null,
    sucursal_id: alcance === 'sucursal' ? Number(form.sucursal_id.value) || null : null,
    dia_completo: full,
    motivo: form.motivo.value.trim() || null,
    ...(full ? { fecha_inicio: form.fecha_inicio.value || null, fecha_fin: form.fecha_fin.value || form.fecha_inicio.value || null }
      : { inicio: form.inicio.value || null, fin: form.fin.value || null }),
  };
  withLoading(form.querySelector('[type="submit"]'), async () => {
    try {
      await papi('/panel/bloqueos', { method: 'POST', body });
      sheet.close();
      toast('Ausencia registrada');
      await load();
    } catch (err) {
      showErrors(form, err);
      if (err.code === 'block_conflicts_bookings') {
        const alert = form.querySelector('[data-form-alert]');
        noticeList(alert, err.message, err.details.reservas.map((r) => `${r.codigo} · ${r.barbero} · ${new Date(r.inicio_utc.replace(' ', 'T') + 'Z').toLocaleString('es-PE', { dateStyle: 'medium', timeStyle: 'short' })}`));
      }
    }
  });
});

async function act(fn, ok) {
  try { await fn(); toast(ok); await load(); } catch (err) {
    if (err.code === 'block_conflicts_bookings') noticeList(noticeEl, err.message, err.details.reservas.map((r) => `${r.codigo} · ${r.barbero}`));
    else toast(err.message, 'error');
  }
}
document.addEventListener('click', async (e) => {
  const ap = e.target.closest('[data-approve]');
  const rj = e.target.closest('[data-reject]');
  const del = e.target.closest('[data-delete]');
  if (ap) act(() => papi(`/panel/bloqueos/${ap.dataset.approve}/aprobar`, { method: 'POST' }), 'Solicitud aprobada');
  if (rj) act(() => papi(`/panel/bloqueos/${rj.dataset.reject}/rechazar`, { method: 'POST' }), 'Solicitud rechazada');
  if (del && await confirmAction('¿Eliminar este registro? Ese tiempo volverá a estar disponible para reservas.', { ok: 'Eliminar', danger: true })) {
    act(() => papi(`/panel/bloqueos/${del.dataset.delete}`, { method: 'DELETE' }), 'Eliminado');
  }
});

document.querySelectorAll('[data-filter]').forEach((el) => el.addEventListener('change', () => {
  filters[el.dataset.filter] = el.value;
  load().catch((err) => toast(err.message, 'error'));
}));

bootPanel('ausencias').then(async (ctx) => {
  if (!ctx) { $('[data-loading]').hidden = true; return; }
  try {
    const [barberos, sucursales] = await Promise.all([papi('/panel/barberos'), papi('/panel/sucursales')]);
    const opts = barberos.filter((b) => b.activo).map((b) => `<option value="${b.id}">${escapeHtml(b.nombre_publico)}</option>`).join('');
    form.barbero_id.innerHTML = opts;
    $('#f-barbero').innerHTML = '<option value="">Todos</option>' + opts;
    $('#f-barbero').value = filters.barbero_id;
    form.sucursal_id.innerHTML = sucursales.filter((s) => s.activo).map((s) => `<option value="${s.id}">${escapeHtml(s.nombre)}</option>`).join('');
    $('[data-new]').addEventListener('click', () => {
      form.reset();
      clearErrors(form);
      if (filters.barbero_id) form.barbero_id.value = filters.barbero_id;
      form.fecha_inicio.min = today();
      form.fecha_fin.min = today();
      syncForm();
      openSheet(sheet);
    });
    await load();
  } catch (e) {
    notice(noticeEl, e.status === 403 ? 'Tu rol no permite gestionar ausencias.' : e.message, 'error');
  } finally {
    $('[data-loading]').hidden = true;
    $('[data-page]').removeAttribute('aria-busy');
  }
});
