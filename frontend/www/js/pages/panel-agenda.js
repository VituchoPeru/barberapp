import { $, escapeHtml, notice, clearErrors, showErrors, withLoading } from '../ui.js';
import { icon } from '../icons.js';
import {
  bootPanel, papi, q, can, toast, money, mins, fmtDate, openSheet, confirmAction, estadoBadge, ESTADOS, idemKey,
} from '../panel.js';

/**
 * Agenda del panel (Fase 6): vista diaria por barbero y vista semanal, alta manual,
 * confirmación, atención, cancelación, reprogramación, notas y cobros.
 * Se actualiza sola cada minuto (sin recargar la página) y después de cada acción.
 * El backend valida cada operación: los botones visibles salen de `acciones` de la API.
 */
const params = new URLSearchParams(location.search);
const noticeEl = $('[data-notice]');
const board = $('[data-board]');
const detail = $('[data-detail]');
const newSheet = $('[data-new-sheet]');
const newForm = $('[data-new-form]');
const sub = $('[data-sub-sheet]');
const subForm = $('[data-sub-form]');

const PPM = 1.2; // píxeles por minuto en la vista diaria
const REFRESH_MS = 60000;
const METODOS = { efectivo: 'Efectivo', tarjeta: 'Tarjeta', yape: 'Yape', plin: 'Plin', transferencia: 'Transferencia', otro: 'Otro' };

let ctx;
let data = null;
let catalog = null;
let openId = null;
const st = {
  fecha: params.get('fecha') || '',
  vista: params.get('vista') === 'semana' ? 'semana' : 'dia',
  sucursal: params.get('sucursal_id') || '',
  barbero: params.get('barbero_id') || '',
};

const toMin = (hhmm) => { const [h, m] = hhmm.split(':').map(Number); return h * 60 + m; };
const hhmm = (m) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
const addDays = (ymd, n) => { const [y, m, d] = ymd.split('-').map(Number); const t = new Date(Date.UTC(y, m - 1, d + n)); return t.toISOString().slice(0, 10); };
const initials = (n) => n.split(/\s+/).slice(0, 2).map((w) => w[0] || '').join('').toUpperCase();

function syncUrl() {
  const p = new URLSearchParams(q.slice(1));
  if (st.fecha) p.set('fecha', st.fecha);
  if (st.vista === 'semana') p.set('vista', 'semana');
  if (st.sucursal) p.set('sucursal_id', st.sucursal);
  if (st.barbero) p.set('barbero_id', st.barbero);
  history.replaceState(null, '', `?${p}`);
}

// ======================================================================= carga y tablero

async function load({ silent = false } = {}) {
  const qs = new URLSearchParams({ vista: st.vista });
  if (st.fecha) qs.set('fecha', st.fecha);
  if (st.sucursal) qs.set('sucursal_id', st.sucursal);
  if (st.barbero) qs.set('barbero_id', st.barbero);
  try {
    data = await papi(`/panel/agenda?${qs}`);
    st.fecha = st.fecha || data.hoy;
    st.sucursal = String(data.sucursal.id);
    fillFilters();
    render();
    syncUrl();
    $('[data-updated]').textContent = `Actualizado ${new Date().toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })}`;
    noticeEl.hidden = true;
  } catch (e) {
    if (!silent) notice(noticeEl, e.status === 403 ? (e.message || 'Tu rol no permite ver la agenda.') : e.message, 'error');
  } finally {
    $('[data-loading]').hidden = true;
    $('[data-page]').removeAttribute('aria-busy');
  }
}

function fillFilters() {
  $('[data-fecha]').value = st.fecha;
  const suc = $('[data-sucursal]');
  suc.innerHTML = data.sucursales.filter((s) => s.activo || String(s.id) === st.sucursal)
    .map((s) => `<option value="${s.id}"${String(s.id) === st.sucursal ? ' selected' : ''}>${escapeHtml(s.nombre)}</option>`).join('');
  suc.closest('.field').hidden = data.sucursales.length < 2;
  const bf = $('[data-barbero-field]');
  bf.hidden = data.alcance === 'propia';
  if (data.alcance !== 'propia') {
    const sel = $('[data-barbero]');
    const current = st.barbero;
    // Las columnas de la sucursal (sin filtro) definen las opciones.
    if (!current || sel.options.length <= 1) {
      sel.innerHTML = '<option value="">Todos</option>' + data.barberos.map((b) => `<option value="${b.id}">${escapeHtml(b.nombre)}</option>`).join('');
    }
    sel.value = current;
  }
  document.querySelectorAll('[data-vista]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.vista === st.vista)));
}

function render() {
  const title = st.vista === 'dia'
    ? fmtDate(data.desde, { weekday: 'long', day: 'numeric', month: 'long' })
    : `${fmtDate(data.desde, { day: 'numeric', month: 'short' })} – ${fmtDate(data.hasta, { day: 'numeric', month: 'short', year: 'numeric' })}`;
  $('[data-date-title]').textContent = title;
  const r = data.resumen;
  $('[data-summary]').innerHTML = [
    `<span><strong>${r.total}</strong> cita${r.total === 1 ? '' : 's'}</span>`,
    r.pendiente ? `<span>${r.pendiente} pendiente${r.pendiente === 1 ? '' : 's'}</span>` : '',
    r.en_atencion ? `<span>${r.en_atencion} en atención</span>` : '',
    r.completada ? `<span>${r.completada} atendida${r.completada === 1 ? '' : 's'}</span>` : '',
    r.cancelada ? `<span>${r.cancelada} cancelada${r.cancelada === 1 ? '' : 's'}</span>` : '',
    r.no_asistio ? `<span>${r.no_asistio} no asistió</span>` : '',
  ].join('');
  if (!data.barberos.length) {
    board.innerHTML = `<div class="empty">${icon('user')}<h2>No hay barberos en esta sucursal</h2><p>Registra barberos y su horario para empezar a agendar.</p></div>`;
    return;
  }
  if (st.vista === 'dia') renderDay(); else renderWeek();
}

const evButton = (r) => `<button type="button" class="ag-ev" data-id="${r.id}" data-estado="${r.estado}" data-live="${r.en_atencion}"
  aria-label="${escapeHtml(`${r.hora_inicio} ${r.cliente.nombre}, ${r.servicios.map((s) => s.nombre).join(' y ')}, ${r.en_atencion ? 'en atención' : ESTADOS[r.estado][0]}`)}">
  <strong>${escapeHtml(r.cliente.nombre)}</strong>
  <span>${escapeHtml(r.hora_inicio)}–${escapeHtml(r.hora_fin)} · ${escapeHtml(r.servicios.map((s) => s.nombre).join(' + '))}</span>
  <span class="ag-ev__tag">${r.en_atencion ? 'En atención' : ESTADOS[r.estado][0]}${r.canal === 'panel' ? ' · Panel' : ''}${r.asistencia_confirmada ? ' · Confirmó asistencia' : ''}</span>
</button>`;

function renderDay() {
  const fecha = data.desde;
  const days = data.reservas.filter((r) => r.fecha === fecha);
  const jornadas = data.jornadas.filter((j) => j.fecha === fecha);
  let start = 8 * 60;
  let end = 20 * 60;
  jornadas.forEach((j) => { start = Math.min(start, toMin(j.inicio)); end = Math.max(end, toMin(j.fin)); });
  days.forEach((r) => { start = Math.min(start, toMin(r.hora_inicio)); end = Math.max(end, toMin(r.hora_fin)); });
  start = Math.floor(start / 60) * 60;
  end = Math.min(24 * 60, Math.ceil(end / 60) * 60);
  const height = (end - start) * PPM;
  const y = (m) => (m - start) * PPM;

  const times = [];
  for (let m = start; m <= end; m += 60) times.push(`<span class="ag-time" style="top:${y(m)}px">${hhmm(m)}</span>`);
  const nowMin = data.hoy === fecha ? (() => { const n = new Date(); return n.getHours() * 60 + n.getMinutes(); })() : null;

  const cols = data.barberos.map((b) => {
    const work = jornadas.filter((j) => j.barbero_id === b.id).map((j) => `<div class="ag-work" data-barbero="${b.id}" data-start="${toMin(j.inicio)}"
      style="top:${y(toMin(j.inicio))}px;height:${(toMin(j.fin) - toMin(j.inicio)) * PPM}px" title="Jornada ${j.inicio}–${j.fin}"></div>`).join('');
    const blocks = data.bloqueos.filter((bl) => bl.barbero_id === null || bl.barbero_id === b.id).map((bl) => {
      const s = bl.inicio.slice(0, 10) < fecha ? start : Math.max(start, toMin(bl.inicio.slice(11)));
      const e = bl.fin.slice(0, 10) > fecha ? end : Math.min(end, toMin(bl.fin.slice(11)));
      if (e <= s) return '';
      return `<div class="ag-block" data-estado="${bl.estado}" style="top:${y(s)}px;height:${(e - s) * PPM}px">${escapeHtml(bl.estado === 'solicitado' ? 'Ausencia solicitada' : (bl.motivo || bl.tipo))}</div>`;
    }).join('');
    const evs = days.filter((r) => r.barbero.id === b.id).map((r) => {
      const s = toMin(r.hora_inicio);
      const e = r.hora_fin === '24:00' ? 24 * 60 : toMin(r.hora_fin);
      return evButton(r).replace('class="ag-ev"', `class="ag-ev" style="top:${y(s) + 1}px;height:${Math.max(24, (e - s) * PPM - 2)}px"`);
    }).join('');
    const now = nowMin !== null && nowMin >= start && nowMin <= end ? `<div class="ag-now" style="top:${y(nowMin)}px" aria-hidden="true"></div>` : '';
    return `<div class="ag-col" style="height:${height}px">${work}${blocks}${evs}${now}</div>`;
  });

  board.innerHTML = `<div class="ag-scroll" data-scroll><div class="ag-grid" style="--cols:${data.barberos.length};--hour:${60 * PPM}px">
    <div class="ag-head ag-head--corner"></div>
    ${data.barberos.map((b) => `<div class="ag-head"><span class="avatar-sm">${b.foto_url ? `<img src="${escapeHtml(b.foto_url)}" alt="">` : escapeHtml(initials(b.nombre))}</span>${escapeHtml(b.nombre)}</div>`).join('')}
    <div class="ag-times" style="height:${height}px;position:sticky">${times.join('')}</div>
    ${cols.join('')}
  </div></div>`;
  // Lleva la vista a la hora actual (o al inicio de la jornada).
  const sc = $('[data-scroll]');
  if (sc && !sc.dataset.positioned) {
    sc.scrollTop = Math.max(0, y(nowMin !== null ? nowMin - 60 : start));
    sc.dataset.positioned = '1';
  }
}

function renderWeek() {
  const out = [];
  for (let i = 0; i < 7; i++) {
    const f = addDays(data.desde, i);
    const items = data.reservas.filter((r) => r.fecha === f);
    const canNew = can(ctx, 'reservas.crear') && data.alcance === 'barberia' && f >= data.hoy;
    out.push(`<section class="ag-day" data-today="${f === data.hoy}" aria-label="${escapeHtml(fmtDate(f, { weekday: 'long', day: 'numeric', month: 'long' }))}">
      <div class="ag-day__head"><button class="link-btn" type="button" data-goto="${f}">${escapeHtml(fmtDate(f))}</button>${canNew ? `<button class="link-btn" type="button" data-new-day="${f}" aria-label="Nueva cita el ${escapeHtml(fmtDate(f))}">+ Cita</button>` : ''}</div>
      ${items.length ? items.map(evButton).join('') : '<p class="muted" style="font-size:.8rem;margin:4px">Sin citas</p>'}
    </section>`);
  }
  board.innerHTML = `<div class="ag-week">${out.join('')}</div>`;
}

board.addEventListener('click', (e) => {
  const ev = e.target.closest('.ag-ev');
  if (ev) { openDetail(Number(ev.dataset.id)); return; }
  const go = e.target.closest('[data-goto]');
  if (go) { st.fecha = go.dataset.goto; st.vista = 'dia'; load(); return; }
  const nd = e.target.closest('[data-new-day]');
  if (nd) { openNew({ fecha: nd.dataset.newDay }); return; }
  const w = e.target.closest('.ag-work');
  if (w && can(ctx, 'reservas.crear') && data.alcance === 'barberia') {
    const rect = w.getBoundingClientRect();
    const m = Number(w.dataset.start) + Math.floor((e.clientY - rect.top) / PPM / 15) * 15;
    openNew({ fecha: data.desde, barbero: Number(w.dataset.barbero), hora: hhmm(m) });
  }
});

// ======================================================================= detalle

async function openDetail(id) {
  openId = id;
  $('[data-det-body]').innerHTML = '<div class="skeleton"></div>';
  openSheet(detail).then(() => { openId = null; });
  try {
    renderDetail(await papi(`/panel/reservas/${id}`));
  } catch (e) {
    $('[data-det-body]').innerHTML = `<p class="alert" data-type="error">${escapeHtml(e.message)}</p>`;
  }
}

function renderDetail(r) {
  const a = r.acciones;
  $('[data-det-title]').textContent = `Cita ${r.codigo}`;
  const tel = r.cliente.telefono ? r.cliente.telefono.replace(/[^\d+]/g, '') : '';
  const btn = (key, label, cls = 'btn--ghost') => (a[key] ? `<button class="btn ${cls} btn--sm" type="button" data-act="${key}">${label}</button>` : '');
  const cobros = Array.isArray(r.cobros) ? r.cobros : null;
  $('[data-det-body]').innerHTML = `
    <div class="detail-head">${estadoBadge(r)}
      <p class="detail-when">${escapeHtml(fmtDate(r.fecha, { weekday: 'long', day: 'numeric', month: 'long' }))} · ${escapeHtml(r.hora_inicio)}–${escapeHtml(r.hora_fin)}</p>
      <p class="muted" style="margin:0">${escapeHtml(r.barbero.nombre)}${r.barbero_asignado_auto ? ' (asignado automáticamente)' : ''} · ${escapeHtml(r.sucursal.nombre)} · ${mins(r.duracion_total_minutos)}</p>
    </div>
    <div class="action-grid">
      ${btn('confirmar', 'Confirmar', '')}${btn('iniciar', 'Iniciar atención', '')}${btn('completar', r.estado === 'no_asistio' ? 'Sí fue atendido' : 'Marcar atendida', '')}
      ${btn('cobrar', 'Registrar cobro')}${btn('reprogramar', 'Reprogramar')}${btn('no_asistio', r.estado === 'completada' ? 'Corregir: no asistió' : 'No asistió')}${btn('cancelar', 'Cancelar cita', 'btn--danger')}
    </div>
    <dl class="kv">
      <dt>Cliente</dt><dd>${escapeHtml(r.cliente.nombre)} ${r.cliente.tiene_cuenta ? '<span class="badge">Con cuenta</span>' : '<span class="badge">Mostrador</span>'}${can(ctx, 'clientes.ver') ? ` · <a href="clientes.html${q}${q ? '&' : '?'}id=${r.cliente.id}">Ver ficha</a>` : ''}</dd>
      ${tel ? `<dt>Teléfono</dt><dd><a href="tel:${escapeHtml(tel)}">${escapeHtml(r.cliente.telefono)}</a> · <a href="https://wa.me/${escapeHtml(tel.replace('+', ''))}" target="_blank" rel="noopener">WhatsApp</a></dd>` : ''}
      ${r.cliente.email ? `<dt>Correo</dt><dd>${escapeHtml(r.cliente.email)}</dd>` : ''}
      <dt>Servicios</dt><dd>${r.servicios.map((s) => `${escapeHtml(s.hora_inicio)} ${escapeHtml(s.nombre)} (${mins(s.duracion_minutos)}, ${money(s.precio)})`).join('<br>')}</dd>
      <dt>Origen</dt><dd>${escapeHtml({ marketplace: 'Marketplace', enlace: 'Enlace directo', qr: 'Código QR', app: 'App', panel: 'Registrada en el panel' }[r.canal] || r.canal)}${r.reprogramaciones ? ` · reprogramada ${r.reprogramaciones} vez${r.reprogramaciones === 1 ? '' : 'es'}` : ''}</dd>
      ${r.asistencia_confirmada ? '<dt>Asistencia</dt><dd>El cliente confirmó que asistirá</dd>' : ''}
      ${r.notas_cliente ? `<dt>Nota del cliente</dt><dd>${escapeHtml(r.notas_cliente)}</dd>` : ''}
      ${r.cancelacion ? `<dt>Cancelación</dt><dd>Por ${escapeHtml({ cliente: 'el cliente', personal: 'la barbería', sistema: 'el sistema' }[r.cancelacion.por] || '—')}${r.cancelacion.motivo ? `: ${escapeHtml(r.cancelacion.motivo)}` : ''}</dd>` : ''}
    </dl>
    <div class="money-row"><div><small>Importe de la cita</small><strong>${money(r.precio_total)}</strong></div>
      ${cobros !== null ? `<div><small>Cobrado</small><strong>${money(r.pagado)}</strong></div><div><small>Saldo</small><strong>${money(r.saldo)}</strong></div>` : ''}</div>
    ${cobros && cobros.length ? `<ul class="pay-list">${cobros.map((p) => `<li data-void="${p.anulado}"><span>${money(p.monto)} · ${escapeHtml(METODOS[p.metodo] || p.metodo)}${p.referencia ? ` · ${escapeHtml(p.referencia)}` : ''}<br><small class="muted">${escapeHtml(new Date(p.pagado_en).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'short' }))}${p.registrado_por ? ` · ${escapeHtml(p.registrado_por)}` : ''}${p.anulado ? ` · Anulado: ${escapeHtml(p.motivo_anulacion || '')}` : ''}</small></span>
      ${!p.anulado && can(ctx, 'reservas.cobrar') ? `<button class="link-btn" type="button" data-void="${p.id}">Anular</button>` : ''}</li>`).join('')}</ul>` : ''}
    ${a.notas ? `<form class="form" data-notes-form><div class="field"><label for="d-notas">Notas internas (solo personal)</label><textarea id="d-notas" name="notas_internas" maxlength="1000" rows="2">${escapeHtml(r.notas_internas || '')}</textarea></div>
      <div><button class="btn btn--ghost btn--sm" type="submit" data-loading="Guardando…">Guardar notas</button></div></form>` : (r.notas_internas ? `<p><strong>Notas internas:</strong> ${escapeHtml(r.notas_internas)}</p>` : '')}
    <details><summary class="link-btn">Historial de cambios</summary><ol class="history">${(r.historial || []).map((h) => `<li>${escapeHtml(h.texto)}<small>${escapeHtml(h.actor)} · ${escapeHtml(new Date(h.fecha).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'short' }))}</small></li>`).join('')}</ol></details>`;

  const body = $('[data-det-body]');
  body.querySelectorAll('[data-act]').forEach((b) => { b.onclick = () => action(r, b.dataset.act, b); });
  body.querySelectorAll('[data-void]').forEach((b) => { if (b.tagName === 'BUTTON') b.onclick = () => voidPayment(r, Number(b.dataset.void)); });
  const nf = body.querySelector('[data-notes-form]');
  if (nf) {
    nf.onsubmit = (e) => {
      e.preventDefault();
      withLoading(nf.querySelector('button'), async () => {
        try {
          renderDetail(await papi(`/panel/reservas/${r.id}/notas`, { method: 'PUT', body: { notas_internas: nf.notas_internas.value } }));
          toast('Notas guardadas');
        } catch (err) { toast(err.message, 'error'); }
      });
    };
  }
}

async function action(r, key, button) {
  const simple = { confirmar: 'confirmar', iniciar: 'iniciar', completar: 'completar', no_asistio: 'no-asistio' };
  if (simple[key]) {
    if (key === 'no_asistio' && !(await confirmAction(`¿Registrar que ${r.cliente.nombre} no asistió? Se liberará el horario.`, { ok: 'Registrar inasistencia', danger: true }))) return;
    await withLoading(button, async () => {
      try {
        renderDetail(await papi(`/panel/reservas/${r.id}/${simple[key]}`, { method: 'POST', body: {} }));
        toast({ confirmar: 'Cita confirmada', iniciar: 'Atención iniciada', completar: 'Cita atendida', no_asistio: 'Inasistencia registrada' }[key]);
        load({ silent: true });
      } catch (e) { toast(e.message, 'error'); }
    });
    return;
  }
  if (key === 'cancelar') cancelSheet(r);
  if (key === 'reprogramar') rescheduleSheet(r);
  if (key === 'cobrar') paySheet(r);
}

/** Hoja secundaria con un formulario propio; onSubmit devuelve la cita actualizada. */
function subSheet(title, html, submitLabel, onSubmit, { danger = false } = {}) {
  $('[data-sub-title]').textContent = title;
  $('[data-sub-body]').innerHTML = `<p class="alert" data-form-alert role="alert" hidden></p>${html}`;
  const submit = $('[data-sub-submit]');
  submit.textContent = submitLabel;
  submit.className = `btn${danger ? ' btn--danger' : ''}`;
  subForm.onsubmit = (e) => {
    e.preventDefault();
    clearErrors(subForm);
    withLoading(submit, async () => {
      try {
        const updated = await onSubmit(subForm);
        if (updated) {
          sub.close();
          renderDetail(updated);
          load({ silent: true });
        }
      } catch (err) { showErrors(subForm, err); }
    });
  };
  openSheet(sub);
}

function cancelSheet(r) {
  subSheet('Cancelar cita', `<p>${escapeHtml(r.cliente.nombre)} · ${escapeHtml(fmtDate(r.fecha))} ${escapeHtml(r.hora_inicio)}. El cliente recibirá el aviso y el horario quedará libre.</p>${Number(r.pagado) > 0 ? `<p class="alert" data-type="warn">Esta cita tiene ${money(r.pagado)} cobrados. Si devuelves el dinero, anula el cobro para que no figure como ingreso.</p>` : ''}
    <div class="field"><label for="s-motivo">Motivo (queda en el historial)</label><input id="s-motivo" name="motivo" maxlength="255" required placeholder="Ej.: El cliente llamó para cancelar"><p class="field__error" data-error-for="motivo"></p></div>`,
  'Cancelar cita', async (f) => {
    const out = await papi(`/panel/reservas/${r.id}/cancelar`, { method: 'POST', body: { motivo: f.motivo.value } });
    toast('Cita cancelada');
    return out;
  }, { danger: true });
}

function paySheet(r) {
  subSheet('Registrar cobro', `<p class="muted">Importe de la cita ${money(r.precio_total)} · cobrado ${money(r.pagado)}.</p>
    <div class="row2"><div class="field"><label for="s-monto">Monto (S/)</label><input id="s-monto" name="monto" type="number" inputmode="decimal" min="0.01" max="99999.99" step="0.01" value="${Number(r.saldo) > 0 ? Number(r.saldo).toFixed(2) : ''}" required><p class="field__error" data-error-for="monto"></p></div>
    <div class="field"><label for="s-metodo">Método</label><select id="s-metodo" name="metodo">${Object.entries(METODOS).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select><p class="field__error" data-error-for="metodo"></p></div></div>
    <div class="field"><label for="s-ref">N.º de operación (opcional)</label><input id="s-ref" name="referencia" maxlength="100"><p class="field__error" data-error-for="referencia"></p></div>`,
  'Registrar cobro', async (f) => {
    const body = { monto: f.monto.value, metodo: f.metodo.value, referencia: f.referencia.value };
    try {
      const out = await papi(`/panel/reservas/${r.id}/pagos`, { method: 'POST', body });
      toast('Cobro registrado');
      return out;
    } catch (e) {
      if (e.code === 'payment_exceeds_total' && await confirmAction(`${e.message}`, { ok: 'Sí, registrar' })) {
        const out = await papi(`/panel/reservas/${r.id}/pagos`, { method: 'POST', body: { ...body, confirmar_excedente: true } });
        toast('Cobro registrado');
        return out;
      }
      throw e;
    }
  });
}

function voidPayment(r, pagoId) {
  subSheet('Anular cobro', `<p>El cobro no se borra: queda anulado con su motivo en el historial.</p>
    <div class="field"><label for="s-motivo2">Motivo</label><input id="s-motivo2" name="motivo" maxlength="255" required placeholder="Ej.: Se registró dos veces"><p class="field__error" data-error-for="motivo"></p></div>`,
  'Anular cobro', async (f) => {
    const out = await papi(`/panel/reservas/${r.id}/pagos/${pagoId}/anular`, { method: 'POST', body: { motivo: f.motivo.value } });
    toast('Cobro anulado');
    return out;
  }, { danger: true });
}

async function rescheduleSheet(r) {
  const cat = await getCatalog().catch(() => null);
  const ids = r.servicios.map((s) => s.id);
  const barbers = (cat ? cat.barberos : []).filter((b) => ids.every((id) => b.servicio_ids.includes(id)) && b.sucursal_ids.includes(r.sucursal.id));
  let hora = '';
  subSheet('Reprogramar cita', `<p class="muted">Actual: ${escapeHtml(fmtDate(r.fecha))} ${escapeHtml(r.hora_inicio)} con ${escapeHtml(r.barbero.nombre)}. La cita actual se mantiene hasta que el cambio se confirme.</p>
    <div class="row2"><div class="field"><label for="s-fecha">Fecha</label><input id="s-fecha" name="fecha" type="date" value="${r.fecha}" min="${data.hoy}" required><p class="field__error" data-error-for="fecha"></p></div>
    <div class="field"><label for="s-barbero">Barbero</label><select id="s-barbero" name="barbero_id">${(barbers.length ? barbers : [{ id: r.barbero.id, nombre: r.barbero.nombre }]).map((b) => `<option value="${b.id}"${b.id === r.barbero.id ? ' selected' : ''}>${escapeHtml(b.nombre)}</option>`).join('')}</select><p class="field__error" data-error-for="barbero_id"></p></div></div>
    <div class="field"><span style="font-size:.875rem;font-weight:600">Hora</span><div class="slot-grid" data-r-slots></div><p class="hint" data-r-hint></p><p class="field__error" data-error-for="hora"></p></div>`,
  'Reprogramar', async (f) => {
    if (!hora) throw Object.assign(new Error('Elige un horario.'), { fields: { hora: ['Elige un horario.'] }, status: 422 });
    const out = await papi(`/panel/reservas/${r.id}/reprogramar`, { method: 'POST', body: { fecha: f.fecha.value, hora, barbero_id: Number(f.barbero_id.value) }, headers: { 'Idempotency-Key': idemKey() } });
    toast('Cita reprogramada');
    return out;
  });
  const loadSlots = async () => {
    hora = '';
    const grid = sub.querySelector('[data-r-slots]');
    const hint = sub.querySelector('[data-r-hint]');
    grid.innerHTML = '';
    hint.textContent = 'Buscando horarios…';
    try {
      const d = await papi(`/panel/reservas/${r.id}/disponibilidad?fecha=${subForm.fecha.value}&barbero_id=${subForm.barbero_id.value}`);
      grid.innerHTML = d.horarios.map((h) => `<button type="button" aria-pressed="false" data-h="${h.hora}">${h.hora}</button>`).join('');
      hint.textContent = d.horarios.length ? '' : 'No hay horarios libres ese día. Prueba otra fecha o barbero.';
    } catch (e) { hint.textContent = e.message; }
  };
  sub.querySelector('[data-r-slots]').onclick = (e) => {
    const b = e.target.closest('[data-h]');
    if (!b) return;
    sub.querySelectorAll('[data-h]').forEach((x) => x.setAttribute('aria-pressed', 'false'));
    b.setAttribute('aria-pressed', 'true');
    hora = b.dataset.h;
  };
  subForm.fecha.onchange = loadSlots;
  subForm.barbero_id.onchange = loadSlots;
  loadSlots();
}

// ======================================================================= alta manual

function getCatalog() {
  catalog = catalog || papi('/panel/agenda/catalogo');
  return catalog;
}

let chosenClient = null;
let newHora = '';

function setClient(c) {
  chosenClient = c;
  const box = $('[data-client-chosen]');
  box.hidden = !c;
  $('[data-client-pick]').hidden = !!c;
  box.innerHTML = c ? `<div class="chosen"><span><strong>${escapeHtml(c.nombre)}</strong><br><small class="muted">${escapeHtml([c.telefono, c.email].filter(Boolean).join(' · ') || 'Sin contacto')}</small></span>
    <button class="link-btn" type="button" data-unpick>Cambiar</button></div>` : '';
  if (c) box.querySelector('[data-unpick]').onclick = () => setClient(null);
}

async function openNew({ fecha = data.desde, barbero = null, hora = '', cliente = null } = {}) {
  clearErrors(newForm);
  newForm.reset();
  setClient(null);
  $('[data-client-results]').innerHTML = '';
  newHora = hora;
  $('[data-n-fecha]').value = fecha < data.hoy ? data.hoy : fecha;
  $('[data-n-fecha]').min = data.hoy;
  openSheet(newSheet);
  try {
    const cat = await getCatalog();
    $('[data-svcs]').innerHTML = cat.servicios.map((s) => `<label><input type="checkbox" value="${s.id}" data-svc><span>${escapeHtml(s.nombre)}<br><small class="muted">${mins(s.duracion_minutos)}</small></span><span>${money(s.precio)}</span></label>`).join('')
      || '<p class="muted">No hay servicios activos.</p>';
    newSheet.dataset.prefBarber = barbero || '';
    fillNewBarbers();
    if (cliente) {
      const c = await papi(`/panel/clientes/${cliente}`).catch(() => null);
      if (c) setClient(c);
    }
  } catch (e) { showErrors(newForm, e); }
}

function selectedServices() {
  return [...newForm.querySelectorAll('[data-svc]:checked')].map((i) => Number(i.value));
}

async function fillNewBarbers() {
  const cat = await getCatalog();
  const ids = selectedServices();
  const suc = Number(st.sucursal);
  const sel = $('[data-n-barbero]');
  const prev = sel.value || newSheet.dataset.prefBarber || '';
  const list = cat.barberos.filter((b) => b.sucursal_ids.includes(suc) && ids.every((id) => b.servicio_ids.includes(id)));
  sel.innerHTML = '<option value="">Cualquiera disponible</option>' + list.map((b) => `<option value="${b.id}">${escapeHtml(b.nombre)}</option>`).join('');
  if (list.some((b) => String(b.id) === String(prev))) sel.value = String(prev);
  loadNewSlots();
}

let slotsSeq = 0;
async function loadNewSlots() {
  const grid = $('[data-slots]');
  const hint = $('[data-slots-hint]');
  const ids = selectedServices();
  grid.innerHTML = '';
  if (!ids.length || !$('[data-n-fecha]').value) { hint.textContent = 'Elige servicios, barbero y fecha para ver los horarios libres.'; return; }
  hint.textContent = 'Buscando horarios…';
  const seq = ++slotsSeq;
  const qs = new URLSearchParams({ sucursal_id: st.sucursal, servicio_ids: ids.join(','), fecha: $('[data-n-fecha]').value });
  if ($('[data-n-barbero]').value) qs.set('barbero_id', $('[data-n-barbero]').value);
  try {
    const d = await papi(`/panel/disponibilidad?${qs}`);
    if (seq !== slotsSeq) return;
    grid.innerHTML = d.horarios.map((h) => `<button type="button" data-h="${h.hora}" aria-pressed="${h.hora === newHora}">${h.hora}</button>`).join('');
    if (!d.horarios.some((h) => h.hora === newHora)) newHora = '';
    hint.textContent = d.horarios.length ? `Duración ${mins(d.duracion_minutos.max)} · ${money(d.precio.min)}${d.precio.max !== d.precio.min ? ` – ${money(d.precio.max)}` : ''}` : 'No hay horarios libres ese día con esa combinación.';
  } catch (e) {
    if (seq === slotsSeq) hint.textContent = e.message;
  }
}

$('[data-slots]').addEventListener('click', (e) => {
  const b = e.target.closest('[data-h]');
  if (!b) return;
  newForm.querySelectorAll('[data-slots] [data-h]').forEach((x) => x.setAttribute('aria-pressed', 'false'));
  b.setAttribute('aria-pressed', 'true');
  newHora = b.dataset.h;
});
$('[data-svcs]').addEventListener('change', fillNewBarbers);
$('[data-n-barbero]').addEventListener('change', loadNewSlots);
$('[data-n-fecha]').addEventListener('change', loadNewSlots);

let searchTimer;
$('[data-client-search]').addEventListener('input', (e) => {
  clearTimeout(searchTimer);
  const term = e.target.value.trim();
  const list = $('[data-client-results]');
  if (term.length < 2) { list.innerHTML = ''; return; }
  searchTimer = setTimeout(async () => {
    try {
      const items = await papi(`/panel/clientes?q=${encodeURIComponent(term)}&per_page=8`);
      list.innerHTML = items.length ? items.map((c, i) => `<li><button type="button" data-pick="${i}"><span>${escapeHtml(c.nombre)}${c.bloqueado ? ' <span class="badge badge--off">No reserva en línea</span>' : ''}</span>
        <small>${escapeHtml([c.telefono, c.email].filter(Boolean).join(' · ') || 'Sin contacto')} · ${c.total_reservas} reserva${c.total_reservas === 1 ? '' : 's'}</small></button></li>`).join('')
        : '<li class="muted" style="font-size:.85rem">Sin coincidencias. Regístralo abajo.</li>';
      list.querySelectorAll('[data-pick]').forEach((b) => { b.onclick = () => setClient(items[Number(b.dataset.pick)]); });
    } catch (err) {
      list.innerHTML = `<li class="muted" style="font-size:.85rem">${escapeHtml(err.status === 403 ? 'Tu rol no permite buscar clientes; regístralo abajo.' : err.message)}</li>`;
    }
  }, 250);
});

newForm.addEventListener('submit', (e) => {
  e.preventDefault();
  clearErrors(newForm);
  const body = {
    sucursal_id: Number(st.sucursal),
    servicio_ids: selectedServices(),
    barbero_id: $('[data-n-barbero]').value ? Number($('[data-n-barbero]').value) : null,
    fecha: $('[data-n-fecha]').value,
    hora: newHora,
    notas: newForm.notas.value,
    notas_internas: newForm.notas_internas.value,
    estado: newForm.confirmada.checked ? 'confirmada' : 'pendiente',
  };
  if (chosenClient) body.cliente_id = chosenClient.id;
  else body.cliente = { nombres: newForm.nombres.value, apellidos: newForm.apellidos.value, telefono: newForm.telefono.value, email: newForm.email.value };
  if (!body.servicio_ids.length) { showErrors(newForm, { status: 422, fields: { servicio_ids: ['Elige al menos un servicio.'] }, message: '' }); return; }
  if (!body.hora) { showErrors(newForm, { status: 422, fields: { hora: ['Elige un horario libre.'] }, message: '' }); return; }
  const key = newForm.dataset.key || (newForm.dataset.key = idemKey());
  withLoading(newForm.querySelector('[type="submit"]'), async () => {
    try {
      const r = await papi('/panel/reservas', { method: 'POST', body, headers: { 'Idempotency-Key': key } });
      delete newForm.dataset.key;
      newSheet.close();
      toast(`Cita registrada · ${r.codigo}`);
      st.fecha = r.fecha;
      await load({ silent: true });
      openDetail(r.id);
    } catch (err) {
      if (err.status !== 0) delete newForm.dataset.key; // sin red: se reintenta con la misma clave
      const fields = {};
      Object.entries(err.fields || {}).forEach(([k, v]) => { fields[['nombres', 'apellidos', 'telefono', 'email'].includes(k) ? `cliente.${k}` : k] = v; });
      showErrors(newForm, Object.assign(err, { details: { ...(err.details || {}), fields } }));
      if (err.code === 'slot_unavailable') {
        const alts = (err.details && err.details.alternativas) || [];
        if (alts.length) { const al = newForm.querySelector('[data-form-alert]'); al.textContent = `${err.message} Libres cerca: ${alts.join(', ')}.`; al.hidden = false; }
        loadNewSlots();
      }
    }
  });
});

// ======================================================================= controles

document.querySelectorAll('[data-vista]').forEach((b) => b.addEventListener('click', () => { st.vista = b.dataset.vista; load(); }));
$('[data-prev]').addEventListener('click', () => { st.fecha = addDays(st.fecha, st.vista === 'semana' ? -7 : -1); load(); });
$('[data-next]').addEventListener('click', () => { st.fecha = addDays(st.fecha, st.vista === 'semana' ? 7 : 1); load(); });
$('[data-today]').addEventListener('click', () => { st.fecha = ''; load(); });
$('[data-fecha]').addEventListener('change', (e) => { if (e.target.value) { st.fecha = e.target.value; load(); } });
$('[data-sucursal]').addEventListener('change', (e) => { st.sucursal = e.target.value; st.barbero = ''; $('[data-barbero]').innerHTML = '<option value="">Todos</option>'; load(); });
$('[data-barbero]').addEventListener('change', (e) => { st.barbero = e.target.value; load(); });
$('[data-refresh]').addEventListener('click', () => load());
$('[data-new]').addEventListener('click', () => openNew());

// Actualización sin recarga: cada minuto mientras la pestaña está visible y no hay un formulario abierto.
setInterval(() => {
  if (document.visibilityState === 'visible' && !newSheet.open && !sub.open) {
    load({ silent: true });
    if (openId && detail.open) papi(`/panel/reservas/${openId}`).then(renderDetail).catch(() => {});
  }
}, REFRESH_MS);
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && data) load({ silent: true }); });

(async () => {
  ctx = await bootPanel('agenda');
  if (!ctx) return;
  $('[data-new]').hidden = !can(ctx, 'reservas.crear');
  if (!can(ctx, 'reservas.crear')) $('[data-legend-work]').textContent = 'Jornada';
  await load();
  if (params.get('cita')) openDetail(Number(params.get('cita')));
  if (params.get('nuevo') && can(ctx, 'reservas.crear')) openNew({ cliente: params.get('cliente') });
})();
