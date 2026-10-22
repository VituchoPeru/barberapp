import { $, escapeHtml } from '../ui.js';
import { icon } from '../icons.js';
import {
  bootPlatform, papi, can, fail, done, openSheet, confirmAction, askText, toast, badge, demoBadge, TENANT_ESTADOS, SUB_ESTADOS, PAGO_ESTADOS,
  money, fmtDateTime, fmtDay, formQuery, debounce, errorText, pager, plural,
} from '../plataforma.js';

/** Gestión de barberías (Fase 9): listado, ficha con uso agregado y cambios de estado. */
let ctx;
let page = 1;
const form = $('[data-filters]');
const sheet = $('[data-detail]');
const ROLES = { propietario: 'Propietarios', administrador: 'Administradores', recepcionista: 'Recepción', barbero: 'Barberos' };
const ACCIONES = {
  'plataforma.barberia.aprobada': 'Aprobada', 'plataforma.barberia.rechazada': 'Rechazada', 'plataforma.barberia.suspendida': 'Suspendida',
  'plataforma.barberia.reactivada': 'Reactivada', 'plataforma.barberia.actualizada': 'Datos internos actualizados',
};

const params = new URLSearchParams(location.search);
['q', 'estado', 'plan', 'demo'].forEach((k) => { if (params.get(k) !== null && form[k]) form[k].value = params.get(k); });

function row(t) {
  const s = t.suscripcion;
  return `<li class="row row--link" tabindex="0" role="button" data-id="${t.id}" aria-label="Ver ${escapeHtml(t.nombre)}">
    <span class="thumb">${icon('store')}</span>
    <div class="row__main"><p class="row__title">${escapeHtml(t.nombre)}</p>
      <p class="row__meta"><span>/b/${escapeHtml(t.slug)}</span>
        <span>${s ? `${escapeHtml(s.plan.nombre)} · ${escapeHtml((SUB_ESTADOS[s.estado] || [s.estado])[0])}` : 'Sin suscripción'}</span>
        <span>${plural(t.uso.barberos, 'barbero', 'barberos')} · ${plural(t.uso.reservas_30_dias, 'reserva', 'reservas')} en 30 días</span>
        <span>Alta ${fmtDay(t.creado_en)}</span></p></div>
    <div class="row__actions">${demoBadge(t)}${badge(TENANT_ESTADOS, t.estado)}</div></li>`;
}

async function load() {
  const list = $('[data-list]');
  try {
    const { data, meta } = await papi(`/barberias?${formQuery(form, { page })}`, { withMeta: true });
    const r = meta.resumen;
    const cur = form.estado.value;
    $('[data-resumen]').innerHTML = [['', 'Todas', Object.values(r).reduce((a, b) => a + b, 0)], ...Object.entries(r).map(([k, n]) => [k, TENANT_ESTADOS[k][0], n])]
      .map(([k, label, n]) => `<li><button type="button" data-estado="${k}" aria-pressed="${cur === k}">${escapeHtml(label)} <strong>${n}</strong></button></li>`).join('');
    $('[data-resumen]').querySelectorAll('[data-estado]').forEach((b) => { b.onclick = () => { form.estado.value = b.dataset.estado; page = 1; load(); }; });
    $('[data-count]').textContent = meta.total ? plural(meta.total, 'barbería', 'barberías') : '';
    list.innerHTML = data.length ? data.map(row).join('')
      : `<li class="empty">${icon('store')}<h3>Sin resultados</h3><p>No hay barberías con esos filtros.</p></li>`;
    pager(meta, (p) => { page = p; load(); });
  } catch (e) {
    fail(e.message);
    return;
  }
  done();
}

async function loadPlans() {
  try {
    const plans = await papi('/planes');
    form.plan.insertAdjacentHTML('beforeend', plans.map((p) => `<option value="${escapeHtml(p.codigo)}">${escapeHtml(p.nombre)}</option>`).join(''));
    if (params.get('plan')) form.plan.value = params.get('plan');
  } catch { /* el filtro por plan es opcional */ }
}

function detailHtml(t) {
  const u = t.uso;
  const actions = [];
  if (t.estado === 'pendiente') {
    actions.push('<button class="btn btn--sm" type="button" data-act="aprobar">Aprobar</button>', '<button class="btn btn--danger btn--sm" type="button" data-act="rechazar">Rechazar</button>');
  }
  if (t.estado === 'activo') actions.push('<button class="btn btn--danger btn--sm" type="button" data-act="suspender">Suspender</button>');
  if (t.estado === 'suspendido') actions.push('<button class="btn btn--sm" type="button" data-act="reactivar">Reactivar</button>');
  const subs = can(ctx, 'plataforma.suscripciones.gestionar');
  return `
    <div class="detail-head"><div class="toolbar">${badge(TENANT_ESTADOS, t.estado)}${demoBadge(t)}${t.publicado_marketplace ? '<span class="badge">En marketplace</span>' : ''}</div>
      ${t.motivo ? `<p class="note-box"><strong>Motivo:</strong> ${escapeHtml(t.motivo)}</p>` : ''}</div>
    ${actions.length ? `<div class="action-grid">${actions.join('')}</div>` : ''}
    <dl class="kv">
      <dt>Dirección web</dt><dd>/b/${escapeHtml(t.slug)}</dd>
      <dt>Propietario</dt><dd>${t.propietario ? `${escapeHtml(t.propietario.nombre)}<br><span class="muted">${escapeHtml(t.propietario.email)}${t.propietario.email_verificado ? '' : ' · correo sin verificar'}${t.propietario.estado !== 'activo' ? ` · cuenta ${escapeHtml(t.propietario.estado)}` : ''}</span>` : '—'}</dd>
      <dt>Contacto</dt><dd>${escapeHtml(t.email || '—')}${t.telefono ? ` · ${escapeHtml(t.telefono)}` : ''}</dd>
      <dt>Razón social</dt><dd>${escapeHtml(t.razon_social || '—')}${t.ruc ? ` · RUC ${escapeHtml(t.ruc)}` : ''}</dd>
      <dt>Zona horaria</dt><dd>${escapeHtml(t.zona_horaria)}</dd>
      <dt>Registro</dt><dd>${fmtDateTime(t.creado_en)}</dd>
      <dt>Aprobada</dt><dd>${fmtDateTime(t.aprobado_en)}</dd>
      ${t.suspendido_en ? `<dt>Suspendida</dt><dd>${fmtDateTime(t.suspendido_en)}</dd>` : ''}
    </dl>
    <h3 class="section-sub">Uso (cifras agregadas)</h3>
    <div class="stat-inline"><span><strong>${u.sucursales}</strong> sucursales</span><span><strong>${u.barberos}</strong> barberos</span><span><strong>${u.servicios}</strong> servicios</span>
      <span><strong>${u.reservas_30_dias}</strong> reservas en 30 días</span><span><strong>${u.reservas_total}</strong> reservas en total</span><span><strong>${u.clientes}</strong> clientes</span></div>
    <div class="stat-inline">${Object.entries(t.equipo).map(([r, n]) => `<span><strong>${n}</strong> ${escapeHtml((ROLES[r] || r).toLowerCase())}</span>`).join('') || '<span>Sin equipo activo</span>'}</div>
    <p class="privacy">${icon('check')}<span>Por privacidad no se muestran los datos de los clientes ni sus citas.</span></p>
    <h3 class="section-sub">Suscripciones</h3>
    ${t.suscripciones.length ? `<ul class="pay-list">${t.suscripciones.map((s) => `<li><span>${escapeHtml(s.plan.nombre)} · ${escapeHtml(s.ciclo)} · ${money(s.precio_pactado, s.moneda)}<br><small class="muted">${fmtDay(s.inicio_en)} – ${fmtDay(s.periodo_fin_en)}</small></span>${badge(SUB_ESTADOS, s.estado_efectivo)}</li>`).join('')}</ul>` : '<p class="muted">Sin suscripciones.</p>'}
    ${subs ? `<div class="link-row"><a class="btn btn--ghost btn--sm" href="suscripciones.html?${t.suscripciones.length ? `abrir=${t.suscripciones[0].id}` : `nueva=${t.id}`}">${t.suscripciones.length ? 'Gestionar suscripción' : 'Crear suscripción'}</a></div>` : ''}
    ${t.pagos.length ? `<h3 class="section-sub">Últimos pagos</h3><ul class="pay-list">${t.pagos.map((p) => `<li data-void="${['rechazado', 'reembolsado'].includes(p.estado)}"><span>${money(p.monto, p.moneda)} · ${escapeHtml(p.metodo)}<br><small class="muted">${fmtDay(p.pagado_en || p.registrado_en)}</small></span>${badge(PAGO_ESTADOS, p.estado)}</li>`).join('')}</ul>` : ''}
    <h3 class="section-sub">Datos internos</h3>
    <form class="form" data-internal>
      <label class="switch"><span>Barbería de demostración<br><small class="muted">Se puede excluir de los indicadores.</small></span><input type="checkbox" role="switch" name="es_demo"${t.es_demo ? ' checked' : ''}></label>
      <div class="field"><label for="nota">Nota interna (solo equipo BARBERAPP)</label><textarea id="nota" name="nota_interna" rows="2" maxlength="500">${escapeHtml(t.nota_interna || '')}</textarea></div>
      <button class="btn btn--ghost btn--sm" type="submit">Guardar datos internos</button>
    </form>
    ${t.historial.length ? `<h3 class="section-sub">Historial</h3><ul class="history">${t.historial.map((h) => `<li>${escapeHtml(ACCIONES[h.accion] || h.accion)}${h.datos && h.datos.motivo ? `: ${escapeHtml(h.datos.motivo)}` : ''}<small>${fmtDateTime(h.fecha)}${h.por ? ` · ${escapeHtml(h.por)}` : ''}</small></li>`).join('')}</ul>` : ''}`;
}

async function open(id) {
  let t;
  try {
    t = await papi(`/barberias/${id}`);
  } catch (e) { toast(e.message, 'error'); return; }
  $('[data-det-title]').textContent = t.nombre;
  const body = $('[data-det-body]');
  body.innerHTML = detailHtml(t);
  body.querySelectorAll('[data-act]').forEach((b) => { b.onclick = () => act(t, b.dataset.act); });
  body.querySelector('[data-internal]').onsubmit = async (e) => {
    e.preventDefault();
    const f = e.target;
    try {
      await papi(`/barberias/${t.id}`, { method: 'PUT', body: { es_demo: f.es_demo.checked, nota_interna: f.nota_interna.value.trim() || null } });
      toast('Datos internos guardados.');
      load();
    } catch (err) { toast(errorText(err), 'error'); }
  };
  openSheet(sheet);
}

async function act(t, action) {
  let body;
  if (action === 'aprobar') {
    if (!await confirmAction(`¿Aprobar «${t.nombre}»? Podrá publicarse en el marketplace y recibir reservas. Se avisará al propietario.`, { ok: 'Aprobar' })) return;
  } else if (action === 'reactivar') {
    if (!await confirmAction(`¿Reactivar «${t.nombre}»? Volverá a recibir reservas. Se avisará al propietario.`, { ok: 'Reactivar' })) return;
  } else {
    const msg = action === 'suspender'
      ? `Suspender «${t.nombre}»: sale del marketplace y no recibe reservas nuevas. Las citas ya agendadas se mantienen. El motivo se envía al propietario.`
      : `Rechazar «${t.nombre}»: la barbería queda de baja. El motivo se envía al propietario.`;
    const motivo = await askText(msg, { label: 'Motivo (mín. 5 caracteres)', ok: action === 'suspender' ? 'Suspender' : 'Rechazar', danger: true, required: true });
    if (motivo === null) return;
    body = { motivo };
  }
  try {
    const r = await papi(`/barberias/${t.id}/${action}`, { method: 'POST', body });
    const extra = r.reservas_futuras ? ` Tiene ${plural(r.reservas_futuras, 'cita futura', 'citas futuras')}.` : '';
    toast(`Listo: ${TENANT_ESTADOS[r.barberia.estado][0].toLowerCase()}. ${plural(r.avisos_enviados, 'aviso enviado', 'avisos enviados')}.${extra}`);
    sheet.close();
    load();
  } catch (e) { toast(errorText(e), 'error'); }
}

(async () => {
  ctx = await bootPlatform('barberias');
  if (!ctx) return;
  const reload = () => { page = 1; load(); };
  form.addEventListener('submit', (e) => { e.preventDefault(); reload(); });
  form.q.addEventListener('input', debounce(reload));
  ['estado', 'plan', 'demo'].forEach((k) => form[k].addEventListener('change', reload));
  $('[data-list]').addEventListener('click', (e) => { const li = e.target.closest('[data-id]'); if (li) open(li.dataset.id); });
  $('[data-list]').addEventListener('keydown', (e) => { const li = e.target.closest('[data-id]'); if (li && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(li.dataset.id); } });
  await loadPlans();
  load();
  if (params.get('abrir')) open(params.get('abrir'));
})();
