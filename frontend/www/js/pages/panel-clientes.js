import { $, escapeHtml, notice, clearErrors, showErrors, withLoading } from '../ui.js';
import { bootPanel, papi, q, can, toast, money, fmtDate, openSheet, estadoBadge } from '../panel.js';

/**
 * Clientes del panel (Fase 6): búsqueda, ficha con historial, próximas citas y observaciones
 * internas. Los datos de contacto de un cliente con cuenta solo los cambia él; los de mostrador,
 * el personal.
 */
const params = new URLSearchParams(location.search);
const noticeEl = $('[data-notice]');
const list = $('[data-list]');
const detail = $('[data-detail]');
const newSheet = $('[data-new-sheet]');
const newForm = $('[data-new-form]');
const PER_PAGE = 25;

let ctx;
let page = 1;
let seq = 0;

const fmtIso = (iso, opts = { day: 'numeric', month: 'short', year: 'numeric' }) => (iso ? new Date(iso).toLocaleDateString('es-PE', opts) : '—');
const contact = (c) => [c.telefono, c.email].filter(Boolean).join(' · ') || 'Sin datos de contacto';

async function load({ append = false } = {}) {
  const my = ++seq;
  const qs = new URLSearchParams({ page: String(page), per_page: String(PER_PAGE), orden: $('[data-orden]').value });
  const term = $('[data-q]').value.trim();
  if (term) qs.set('q', term);
  try {
    const { data, meta } = await papi(`/panel/clientes?${qs}`, { withMeta: true });
    if (my !== seq) return;
    const html = data.map((c) => `<li class="row row--single"><button type="button" class="row__btn" data-id="${c.id}">
        <strong>${escapeHtml(c.nombre)}</strong>${c.tiene_cuenta ? ' <span class="badge">Con cuenta</span>' : ' <span class="badge">Mostrador</span>'}${c.bloqueado ? ' <span class="badge badge--off">No reserva en línea</span>' : ''}
        <small>${escapeHtml(contact(c))}</small>
        <small>${c.total_reservas} reserva${c.total_reservas === 1 ? '' : 's'}${c.total_inasistencias ? ` · ${c.total_inasistencias} inasistencia${c.total_inasistencias === 1 ? '' : 's'}` : ''} · Última visita: ${escapeHtml(fmtIso(c.ultima_visita))}${c.proxima_cita ? ` · Próxima: ${escapeHtml(new Date(c.proxima_cita).toLocaleString('es-PE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }))}` : ''}</small>
      </button></li>`).join('');
    list.innerHTML = append ? list.innerHTML + html : html;
    if (!append && !data.length) list.innerHTML = `<li class="empty"><p>${term ? 'Sin coincidencias para esa búsqueda.' : 'Aún no hay clientes. Aparecerán cuando reserven o los registres.'}</p></li>`;
    $('[data-count]').textContent = `${meta.total} cliente${meta.total === 1 ? '' : 's'}${term ? ' encontrados' : ''}`;
    $('[data-more]').hidden = meta.page * meta.per_page >= meta.total;
    noticeEl.hidden = true;
  } catch (e) {
    notice(noticeEl, e.message, 'error');
  } finally {
    $('[data-loading]').hidden = true;
    $('[data-page]').removeAttribute('aria-busy');
  }
}

list.addEventListener('click', (e) => {
  const b = e.target.closest('[data-id]');
  if (b) openClient(Number(b.dataset.id));
});

// ======================================================================= ficha

async function openClient(id) {
  $('[data-det-body]').innerHTML = '<div class="skeleton"></div>';
  $('[data-det-title]').textContent = 'Cliente';
  openSheet(detail);
  try {
    const [c, prox, pas] = await Promise.all([
      papi(`/panel/clientes/${id}`),
      papi(`/panel/clientes/${id}/reservas?tipo=proximas&per_page=10`),
      papi(`/panel/clientes/${id}/reservas?tipo=pasadas&per_page=10`, { withMeta: true }),
    ]);
    renderClient(c, prox, pas);
  } catch (e) {
    $('[data-det-body]').innerHTML = `<p class="alert" data-type="error">${escapeHtml(e.message)}</p>`;
  }
}

const bookingRow = (r) => `<li><a href="agenda.html${q}${q ? '&' : '?'}fecha=${r.fecha}&cita=${r.id}">
  <span>${escapeHtml(fmtDate(r.fecha))} ${escapeHtml(r.hora_inicio)} · ${escapeHtml(r.servicios.map((s) => s.nombre).join(' + '))}<br><small class="muted">${escapeHtml(r.barbero.nombre)} · ${money(r.precio_total)}</small></span>${estadoBadge(r)}</a></li>`;

function renderClient(c, prox, pas) {
  $('[data-det-title]').textContent = c.nombre;
  const s = c.resumen;
  const tel = c.telefono ? c.telefono.replace(/[^\d+]/g, '') : '';
  const editContact = !c.tiene_cuenta && can(ctx, 'clientes.notas');
  $('[data-det-body]').innerHTML = `
    <p class="muted" style="margin-top:0">${c.tiene_cuenta ? 'Tiene cuenta en BARBERAPP: sus datos de contacto solo los cambia él.' : 'Cliente de mostrador (registrado por la barbería).'} Cliente desde ${escapeHtml(fmtIso(c.cliente_desde))}.</p>
    <div class="action-grid">
      ${can(ctx, 'reservas.crear') ? `<a class="btn btn--sm" href="agenda.html${q}${q ? '&' : '?'}nuevo=1&cliente=${c.id}">Nueva cita</a>` : ''}
      ${tel ? `<a class="btn btn--ghost btn--sm" href="tel:${escapeHtml(tel)}">Llamar</a><a class="btn btn--ghost btn--sm" href="https://wa.me/${escapeHtml(tel.replace('+', ''))}" target="_blank" rel="noopener">WhatsApp</a>` : ''}
    </div>
    <dl class="kv">
      <dt>Teléfono</dt><dd>${escapeHtml(c.telefono || '—')}</dd>
      <dt>Correo</dt><dd>${escapeHtml(c.email || '—')}</dd>
      <dt>Reservas</dt><dd>${c.total_reservas} en total · ${s.por_estado.completada} atendida${s.por_estado.completada === 1 ? '' : 's'} · ${s.por_estado.cancelada} cancelada${s.por_estado.cancelada === 1 ? '' : 's'} · ${s.por_estado.no_asistio} inasistencia${s.por_estado.no_asistio === 1 ? '' : 's'}</dd>
      <dt>Primera / última visita</dt><dd>${escapeHtml(fmtIso(c.primera_visita))} / ${escapeHtml(fmtIso(c.ultima_visita))}</dd>
      ${s.servicios_frecuentes.length ? `<dt>Servicios frecuentes</dt><dd>${s.servicios_frecuentes.map((x) => `${escapeHtml(x.nombre)} (${x.veces})`).join(', ')}</dd>` : ''}
      ${s.barbero_frecuente ? `<dt>Barbero habitual</dt><dd>${escapeHtml(s.barbero_frecuente.nombre)} (${s.barbero_frecuente.veces} cita${s.barbero_frecuente.veces === 1 ? '' : 's'})</dd>` : ''}
      ${s.total_cobrado !== null && s.total_cobrado !== undefined ? `<dt>Total cobrado</dt><dd>${money(s.total_cobrado)}</dd>` : ''}
    </dl>
    <h3>Próximas citas</h3>
    ${prox.length ? `<ul class="booking-list mini-list">${prox.map(bookingRow).join('')}</ul>` : '<p class="muted">No tiene citas próximas.</p>'}
    <h3>Historial</h3>
    ${pas.data.length ? `<ul class="booking-list mini-list">${pas.data.map(bookingRow).join('')}</ul>${pas.meta.total > pas.data.length ? `<p class="muted">Mostrando las ${pas.data.length} más recientes de ${pas.meta.total}.</p>` : ''}` : '<p class="muted">Sin citas pasadas.</p>'}
    ${can(ctx, 'clientes.notas') ? `<form class="form" data-edit-form novalidate style="margin-top:16px">
      <h3>Observaciones</h3>
      <p class="alert" data-form-alert role="alert" hidden></p>
      ${editContact ? `<div class="row2"><div class="field"><label for="e-tel">Teléfono</label><input id="e-tel" name="telefono" inputmode="tel" value="${escapeHtml(c.telefono || '')}"><p class="field__error" data-error-for="telefono"></p></div>
        <div class="field"><label for="e-email">Correo</label><input id="e-email" name="email" type="email" value="${escapeHtml(c.email || '')}"><p class="field__error" data-error-for="email"></p></div></div>` : ''}
      <div class="field"><label for="e-notas">Notas internas (no las ve el cliente)</label><textarea id="e-notas" name="notas_internas" maxlength="2000" rows="3" placeholder="Preferencias, alergias, tipo de corte…">${escapeHtml(c.notas_internas || '')}</textarea><p class="field__error" data-error-for="notas_internas"></p></div>
      <label class="switch"><span><strong>No permitir reservas en línea</strong><br><small class="muted">Podrás seguir agendándolo desde el panel.</small></span><input type="checkbox" role="switch" name="bloqueado"${c.bloqueado ? ' checked' : ''}></label>
      <div><button class="btn btn--ghost btn--sm" type="submit" data-loading="Guardando…">Guardar cambios</button></div>
    </form>` : (c.notas_internas ? `<h3>Observaciones</h3><p>${escapeHtml(c.notas_internas)}</p>` : '')}`;

  const f = $('[data-edit-form]', detail);
  if (f) {
    f.onsubmit = (e) => {
      e.preventDefault();
      clearErrors(f);
      const body = { notas_internas: f.notas_internas.value, bloqueado: f.bloqueado.checked };
      if (editContact) { body.telefono = f.telefono.value; body.email = f.email.value; }
      withLoading(f.querySelector('[type="submit"]'), async () => {
        try {
          await papi(`/panel/clientes/${c.id}`, { method: 'PUT', body });
          toast('Cliente actualizado');
          page = 1;
          load();
          openClient(c.id);
        } catch (err) { showErrors(f, err); }
      });
    };
  }
}

// ======================================================================= alta

$('[data-new]').addEventListener('click', () => {
  clearErrors(newForm);
  newForm.reset();
  openSheet(newSheet);
});

newForm.addEventListener('submit', (e) => {
  e.preventDefault();
  clearErrors(newForm);
  const body = { nombres: newForm.nombres.value, apellidos: newForm.apellidos.value, telefono: newForm.telefono.value, email: newForm.email.value };
  withLoading(newForm.querySelector('[type="submit"]'), async () => {
    try {
      const c = await papi('/panel/clientes', { method: 'POST', body });
      newSheet.close();
      toast(c.ya_existia ? 'Ya estaba registrado con ese contacto' : 'Cliente registrado');
      page = 1;
      load();
      if (can(ctx, 'clientes.ver')) openClient(c.id);
    } catch (err) { showErrors(newForm, err); }
  });
});

// ======================================================================= controles

let timer;
$('[data-q]').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { page = 1; load(); }, 300); });
$('[data-orden]').addEventListener('change', () => { page = 1; load(); });
$('[data-more]').addEventListener('click', () => { page += 1; load({ append: true }); });

(async () => {
  ctx = await bootPanel('clientes');
  if (!ctx) return;
  $('[data-new]').hidden = !can(ctx, 'reservas.crear');
  if (params.get('q')) $('[data-q]').value = params.get('q');
  await load();
  if (params.get('id')) openClient(Number(params.get('id')));
})();
