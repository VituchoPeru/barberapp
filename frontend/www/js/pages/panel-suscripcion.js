import { $, escapeHtml, notice, withLoading } from '../ui.js';
import { icon } from '../icons.js';
import { bootPanel, papi, tenant, q, money, toast, openSheet, confirmAction, hbars, isNativeApp } from '../panel.js';

/**
 * Suscripción de la barbería (Fase 10): plan vigente, uso frente a los límites, planes con su
 * acción según el estado (renovar, contratar, mejorar con prorrateo o programar el cambio),
 * pagos en línea iniciados e historial. Los montos los calcula siempre el backend.
 *
 * Al volver de la pasarela (?orden=…&resultado=…) se consulta la orden hasta que el pago se
 * confirme por webhook o por la consulta a la pasarela; nunca se da por pagado desde la URL.
 */
const noticeEl = $('[data-notice]');
const returnEl = $('[data-return]');
const params = new URLSearchParams(location.search);
const nativo = isNativeApp();
let data = null;
let ciclo = 'mensual';

const ESTADOS = {
  prueba: ['Prueba gratuita', 'gold'],
  activa: ['Activa', 'ok'],
  vencida: ['Vencida', 'warn'],
  suspendida: ['Suspendida', 'off'],
  cancelada: ['Cancelada', 'off'],
  sin_suscripcion: ['Sin suscripción', 'off'],
};
const ORDEN_ESTADOS = { pendiente: ['Pendiente', 'warn'], pagada: ['Pagada', 'ok'], fallida: ['Rechazada', 'off'], expirada: ['Expirada', 'off'], anulada: ['Anulada', 'off'] };
const PAGO_ESTADOS = { pendiente: ['En revisión', 'warn'], confirmado: ['Confirmado', 'ok'], rechazado: ['Rechazado', 'off'], reembolsado: ['Reembolsado', 'off'] };
const METODOS = { manual: 'Manual', transferencia: 'Transferencia', yape: 'Yape', plin: 'Plin', efectivo: 'Efectivo', pasarela: 'Pago en línea' };
const TIPOS = { renovacion: 'Renovación', contratacion: 'Contratación', mejora: 'Mejora de plan' };
const CICLOS = { mensual: 'mes', anual: 'año' };
const CANALES = { interna: 'Bandeja de la app', push: 'Notificaciones push', email: 'Correo electrónico' };

const badge = (map, k) => `<span class="badge badge--${(map[k] || [k, 'off'])[1]}">${escapeHtml((map[k] || [k])[0])}</span>`;
const fecha = (iso) => (iso ? new Date(iso).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric' }) : '—');
const fechaCorta = (iso) => (iso ? new Date(iso).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const ymd = (s) => (s ? fechaCorta(`${s}T12:00:00Z`) : '—');
const lim = (v, uno, varios, ilimitado) => (v === null ? ilimitado : `${v} ${v === 1 ? uno : varios}`);

// ======================================================================= plan actual

function renderCurrent() {
  const r = data.resumen;
  const s = data.suscripcion;
  const fn = r.funciones || {};
  let estadoTxt = '';
  if (r.estado === 'sin_suscripcion') estadoTxt = 'Aún no tienes una suscripción. Elige un plan para contratarlo.';
  else if (r.vigente) estadoTxt = `${r.estado === 'prueba' ? 'La prueba termina' : 'Vigente hasta'} el ${fecha(r.periodo_fin_en)}${r.dias_restantes !== null ? ` (${r.dias_restantes <= 1 ? 'menos de un día' : `${r.dias_restantes} días`})` : ''}.`;
  else estadoTxt = `Terminó el ${fecha(r.periodo_fin_en)}.`;
  if (r.restringida) estadoTxt += ' Tu barbería no aparece en el marketplace ni recibe reservas nuevas hasta que pagues.';
  else if (r.restriccion_en) estadoTxt += ` Si no renuevas, desde el ${fecha(r.restriccion_en)} dejará de recibir reservas nuevas (las citas registradas se mantienen).`;

  const usos = Object.entries(r.limites || {}).map(([k, l]) => ({
    label: `${k === 'sucursales' ? 'Sucursales' : 'Barberos'} activos`,
    value: l.uso,
    max: l.limite,
    excedido: l.excedido,
  }));
  const cambio = s && s.cambio_programado;
  $('[data-current]').innerHTML = `
    <div class="sub-head">
      <div><p class="muted" style="margin:0">Tu plan</p><h3 id="h-plan">${escapeHtml(r.plan.nombre)} ${badge(ESTADOS, r.restringida ? 'vencida' : r.estado)}</h3></div>
      ${s && s.estado !== 'prueba' && r.vigente ? `<p style="margin:0"><strong>${money(s.precio_pactado)}</strong> <span class="muted">por ${CICLOS[s.ciclo]}</span></p>` : ''}
    </div>
    <p>${escapeHtml(estadoTxt)}</p>
    ${r.restringida ? `<p class="alert" data-type="error">Restringida desde el ${escapeHtml(fecha(r.restringida_desde))}. Al confirmarse tu pago se levanta al instante.</p>` : ''}
    ${cambio ? `<p class="alert" data-type="info">Cambio programado para el ${escapeHtml(fecha(cambio.desde))}: pasas al plan ${escapeHtml(cambio.plan.nombre)} por ${money(cambio.precio)} al ${CICLOS[cambio.ciclo]}. Ese día renuevas a ese precio.
      ${data.puede_gestionar ? ' <button class="link-btn" type="button" data-cancel-change>Anular cambio</button>' : ''}</p>` : ''}
    <div class="grid-2" style="margin-top:12px">
      <div><h4 style="margin:0 0 8px">Uso del plan</h4>
        ${usos.length ? `<ul class="hbars">${usos.map((u) => hbars([{ label: u.label, value: u.value }], u.max === null ? Math.max(u.value, 1) : u.max, (v) => (u.max === null ? `${v}` : `${v} de ${u.max}`))).join('')}</ul>
          ${usos.some((u) => u.excedido) ? '<p class="alert" data-type="error">Usas más de lo que incluye tu plan. Nada se desactiva solo, pero no puedes añadir más hasta que desactives algunos o cambies de plan.</p>' : ''}`
    : '<p class="muted">Sin datos de uso.</p>'}
      </div>
      <div><h4 style="margin:0 0 8px">Incluye</h4>${feats({ reportes_avanzados: fn.reportes_avanzados, personalizacion: fn.personalizacion, canales: fn.canales || [] })}</div>
    </div>`;
}

function feats(c, p = null) {
  const item = (ok, text) => `<li class="${ok ? '' : 'no'}">${icon(ok ? 'check' : 'close')}<span>${escapeHtml(text)}</span></li>`;
  const rows = [];
  if (p) {
    rows.push(item(true, lim(p.max_sucursales, 'sucursal', 'sucursales', 'Sucursales ilimitadas')));
    rows.push(item(true, lim(p.max_barberos, 'barbero', 'barberos', 'Barberos ilimitados')));
  }
  rows.push(item(!!c.reportes_avanzados, 'Reportes avanzados: ocupación, productividad, demanda por hora, evolución mensual y periodos largos'));
  rows.push(item(!!c.personalizacion, 'Colores propios en tu perfil'));
  ['interna', 'push', 'email'].forEach((k) => rows.push(item((c.canales || []).includes(k), CANALES[k])));
  return `<ul class="plan__feats">${rows.join('')}</ul>`;
}

// ======================================================================= planes

function actionFor(p) {
  const o = p.opciones[ciclo] || {};
  const pagos = data.pasarela.habilitada && !nativo;
  const prueba = data.resumen.estado === 'prueba';
  const out = [];
  if (!data.puede_gestionar) return '';
  if (prueba && !p.actual && !p.excede) {
    out.push(`<button class="btn btn--ghost btn--sm" type="button" data-trial="${p.id}">Probar este plan gratis</button>`);
  }
  switch (o.accion) {
    case 'renovacion':
      if (pagos) out.push(`<button class="btn" type="button" data-pay="${p.id}">Renovar · ${money(o.monto)}</button>`);
      break;
    case 'contratacion':
      if (pagos) out.push(`<button class="btn" type="button" data-pay="${p.id}">${prueba && p.actual ? 'Contratar este plan' : 'Contratar'} · ${money(o.monto)}</button>`);
      break;
    case 'mejora':
      if (pagos) out.push(`<button class="btn" type="button" data-pay="${p.id}">Mejorar · ${money(o.monto)}</button>`);
      out.push(`<p class="plan__note">Pagas solo la diferencia hasta el ${escapeHtml(fecha(o.hasta))}; luego renuevas al precio del plan.</p>`);
      break;
    case 'mejora_sin_cobro':
      out.push(`<button class="btn" type="button" data-pay="${p.id}">Mejorar sin costo</button>`);
      out.push('<p class="plan__note">Queda poco de tu periodo: la diferencia no llega al mínimo y no se cobra.</p>');
      break;
    case 'programar':
      // Con más sucursales o barberos activos de los que admite el plan, el backend lo rechaza: se explica sin botón.
      if (!p.excede) {
        out.push(`<button class="btn btn--ghost" type="button" data-schedule="${p.id}">Programar cambio</button>`);
        out.push(`<p class="plan__note">${escapeHtml(o.motivo || '')}</p>`);
      }
      break;
    default:
      if (o.motivo) out.push(`<p class="plan__note">${escapeHtml(o.motivo)}</p>`);
  }
  if (['renovacion', 'contratacion', 'mejora'].includes(o.accion) && !pagos) {
    out.push(`<p class="plan__note">${nativo ? 'Paga desde la web de BARBERAPP.' : 'El pago en línea no está disponible por ahora. Escríbenos para pagar por transferencia.'}</p>`);
  }
  if (p.excede) out.push('<p class="plan__note">Tu uso actual supera los límites de este plan: desactiva sucursales o barberos para poder elegirlo.</p>');
  return out.join('');
}

function renderPlans() {
  document.querySelectorAll('[data-ciclo]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.ciclo === ciclo)));
  $('[data-planes-hint]').textContent = ciclo === 'anual' ? 'Precio por año. Pagar el año cuesta menos que doce meses.' : 'Precio por mes, en soles (PEN).';
  $('[data-native]').hidden = !nativo;
  $('[data-plans]').innerHTML = data.planes.map((p) => {
    const precio = ciclo === 'anual' ? p.precio_anual : p.precio_mensual;
    return `<article class="plan${p.actual ? ' plan--actual' : ''}" aria-labelledby="plan-${p.id}">
      <div class="plan__head"><h4 class="plan__name" id="plan-${p.id}">${escapeHtml(p.nombre)}</h4>${p.actual ? '<span class="badge badge--gold">Tu plan</span>' : ''}</div>
      <p class="plan__price">${money(precio)} <small>/ ${CICLOS[ciclo]}</small></p>
      ${p.descripcion ? `<p class="muted" style="margin:0">${escapeHtml(p.descripcion)}</p>` : ''}
      ${feats(p.caracteristicas || {}, p)}
      <div class="plan__foot">${actionFor(p)}</div>
    </article>`;
  }).join('');
}

// ======================================================================= órdenes y pagos

function renderOrders() {
  const os = data.ordenes || [];
  $('[data-orders-box]').hidden = !os.length;
  $('[data-orders]').innerHTML = `<div class="table-wrap"><table class="data-table"><thead><tr><th>Fecha</th><th>Detalle</th><th>Monto</th><th>Estado</th><th></th></tr></thead><tbody>
    ${os.map((o) => `<tr><td>${escapeHtml(fechaCorta(o.creada_en))}</td><td>${escapeHtml(o.detalle)}</td><td>${money(o.monto)}</td>
      <td>${badge(ORDEN_ESTADOS, o.estado)}${o.por_revisar ? ' <span class="badge badge--warn">En revisión</span>' : ''}</td>
      <td>${o.estado === 'pendiente' && o.url && !nativo && data.puede_gestionar ? `<a class="link-btn" href="${escapeHtml(o.url)}">Continuar pago</a>` : ''}</td></tr>`).join('')}
    </tbody></table></div>
    ${os.some((o) => o.por_revisar) ? '<p class="hint">Un pago en revisión se recibió pero no se pudo aplicar solo (por ejemplo, el monto no coincide). El equipo de BARBERAPP lo revisa y te escribe.</p>' : ''}`;
}

function renderPayments() {
  const ps = data.pagos || [];
  $('[data-payments]').innerHTML = ps.length ? `<div class="table-wrap"><table class="data-table"><thead><tr><th>Fecha</th><th>Plan</th><th>Periodo</th><th>Método</th><th>Monto</th><th>Estado</th></tr></thead><tbody>
    ${ps.map((p) => `<tr><td>${escapeHtml(fechaCorta(p.pagado_en || p.registrado_en))}</td><td>${escapeHtml(p.plan || '')}</td>
      <td>${p.periodo_inicio ? `${escapeHtml(ymd(p.periodo_inicio))} – ${escapeHtml(ymd(p.periodo_fin))}` : '—'}</td>
      <td>${escapeHtml(METODOS[p.metodo] || p.metodo)}</td><td>${money(p.monto)}</td><td>${badge(PAGO_ESTADOS, p.estado)}</td></tr>`).join('')}
    </tbody></table></div>` : '<p class="muted">Aún no hay pagos registrados.</p>';
}

function render() {
  renderCurrent();
  renderPlans();
  renderOrders();
  renderPayments();
  $('[data-support]').innerHTML = data.soporte_email
    ? `¿Dudas con tu plan o un pago? Escríbenos a <a href="mailto:${escapeHtml(data.soporte_email)}">${escapeHtml(data.soporte_email)}</a>.`
    : '';
  if (!data.puede_gestionar) $('[data-support]').insertAdjacentHTML('afterbegin', 'Solo el propietario de la barbería puede pagar o cambiar el plan. ');
}

async function load() {
  data = await papi('/panel/suscripcion');
  render();
  $('[data-content]').hidden = false;
}

// ======================================================================= acciones

const sheet = $('[data-pay-sheet]');
const payForm = $('[data-pay-form]');
let payPlan = null;

async function quote() {
  const err = $('[data-pay-error]');
  err.hidden = true;
  const periodos = Number(payForm.periodos.value || 1);
  try {
    const c = await papi('/panel/suscripcion/cotizar', { method: 'POST', body: { plan_id: payPlan.id, ciclo, periodos } });
    $('[data-quote]').innerHTML = `<dt>Concepto</dt><dd>${escapeHtml(TIPOS[c.tipo] || 'Cambio de plan')}: ${escapeHtml(c.detalle || payPlan.nombre)}</dd>
      <dt>Cubre</dt><dd>${escapeHtml(fecha(c.desde))} al ${escapeHtml(fecha(c.hasta))}</dd>
      <dt>Total</dt><dd><strong>${money(c.monto)}</strong></dd>`;
    $('[data-pay-go]').disabled = false;
  } catch (e) {
    $('[data-quote]').innerHTML = '';
    notice(err, e.message, 'error');
    $('[data-pay-go]').disabled = true;
  }
}

async function startPay(plan) {
  payPlan = plan;
  const o = plan.opciones[ciclo] || {};
  if (o.accion === 'mejora_sin_cobro') {
    if (!(await confirmAction(`¿Pasar al plan ${plan.nombre} ahora? No se cobra la diferencia.`, { ok: 'Cambiar plan' }))) return;
    try {
      const r = await papi('/panel/suscripcion/pagar', { method: 'POST', body: { plan_id: plan.id, ciclo, periodos: 1 } });
      toast(r.mensaje || 'Plan actualizado');
      await load();
    } catch (e) { notice(noticeEl, e.message, 'error'); }
    return;
  }
  const max = o.accion === 'mejora' ? 1 : (ciclo === 'anual' ? 2 : 12);
  payForm.periodos.innerHTML = Array.from({ length: max }, (_, i) => `<option value="${i + 1}">${i + 1} ${ciclo === 'anual' ? (i ? 'años' : 'año') : (i ? 'meses' : 'mes')}</option>`).join('');
  $('[data-periodos-field]').hidden = max === 1;
  $('[data-pay-title]').textContent = `${TIPOS[o.accion] || 'Pagar'}: ${plan.nombre}`;
  $('[data-quote]').innerHTML = '<dt>Calculando…</dt><dd></dd>';
  openSheet(sheet);
  await quote();
}

payForm.periodos.addEventListener('change', quote);
payForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = $('[data-pay-go]');
  try {
    const r = await withLoading(btn, () => papi('/panel/suscripcion/pagar', { method: 'POST', body: { plan_id: payPlan.id, ciclo, periodos: Number(payForm.periodos.value || 1) } }));
    if (r.url) {
      location.assign(r.url);
      return;
    }
    sheet.close();
    toast(r.mensaje || 'Listo');
    await load();
  } catch (err) {
    notice($('[data-pay-error]'), err.code === 'email_not_verified' ? 'Verifica tu correo antes de pagar: revisa tu bandeja o reenvía el enlace desde Mi cuenta.' : err.message, 'error');
  }
});

async function changePlan(plan, prueba) {
  const msg = prueba
    ? `¿Probar el plan ${plan.nombre}? Tu prueba gratuita sigue terminando el ${fecha(data.resumen.periodo_fin_en)}.`
    : `¿Programar el cambio al plan ${plan.nombre} (${ciclo}) para el fin de tu periodo pagado? Hasta entonces conservas tu plan actual.`;
  if (!(await confirmAction(msg, { ok: prueba ? 'Cambiar plan' : 'Programar' }))) return;
  try {
    data = await papi('/panel/suscripcion/cambiar-plan', { method: 'POST', body: { plan_id: plan.id, ciclo } });
    toast(data.mensaje || 'Plan actualizado');
    render();
  } catch (e) { notice(noticeEl, e.message, 'error'); }
}

document.addEventListener('click', async (e) => {
  const t = e.target.closest('[data-pay],[data-schedule],[data-trial],[data-cancel-change],[data-ciclo]');
  if (!t || !data) return;
  if (t.dataset.ciclo) {
    ciclo = t.dataset.ciclo;
    renderPlans();
    return;
  }
  if (t.hasAttribute('data-cancel-change')) {
    if (!(await confirmAction('¿Anular el cambio programado? Tu plan seguirá igual al renovar.', { ok: 'Anular cambio' }))) return;
    try {
      data = await papi('/panel/suscripcion/cambio-programado', { method: 'DELETE' });
      toast(data.mensaje || 'Cambio anulado');
      render();
    } catch (err) { notice(noticeEl, err.message, 'error'); }
    return;
  }
  const plan = data.planes.find((p) => String(p.id) === (t.dataset.pay || t.dataset.schedule || t.dataset.trial));
  if (!plan) return;
  if (t.dataset.pay) startPay(plan);
  else changePlan(plan, !!t.dataset.trial);
});

// ======================================================================= vuelta de la pasarela

const sleep = (ms) => new Promise((r) => { setTimeout(r, ms); });

async function followOrder(codigo) {
  const pid = params.get('payment_id') || params.get('collection_id');
  const resultado = params.get('resultado');
  notice(returnEl, 'Comprobando tu pago…', 'info');
  for (let i = 0; i < 8; i += 1) {
    try {
      const qs = pid ? `?payment_id=${encodeURIComponent(pid)}` : '';
      const r = await papi(`/panel/suscripcion/ordenes/${encodeURIComponent(codigo)}${qs}`);
      const o = r.orden;
      if (o.estado === 'pagada') {
        notice(returnEl, `Pago confirmado: ${o.detalle}. ¡Gracias!`, 'ok');
        await load();
        break;
      }
      if (o.por_revisar) {
        notice(returnEl, 'Recibimos tu pago, pero requiere una revisión del equipo de BARBERAPP. Te avisaremos al resolverlo.', 'info');
        break;
      }
      if (['fallida', 'expirada', 'anulada'].includes(o.estado)) {
        notice(returnEl, o.estado === 'fallida' ? 'El pago fue rechazado. Puedes intentarlo de nuevo con otro medio.' : 'Esta orden de pago ya no está vigente.', 'error');
        break;
      }
      if (i === 7) {
        notice(returnEl, resultado === 'pendiente'
          ? 'Tu pago está pendiente en la pasarela (por ejemplo, un pago en efectivo). Lo aplicaremos en cuanto se acredite.'
          : 'Aún no recibimos la confirmación del pago. Si pagaste, se aplicará en unos minutos; vuelve a abrir esta página.', 'info');
      }
    } catch (e) {
      notice(returnEl, e.message, 'error');
      break;
    }
    await sleep(3000);
  }
  // Se limpia la URL para que recargar no repita la consulta.
  history.replaceState(null, '', `suscripcion.html${q}`);
}

(async () => {
  const ctx = await bootPanel('suscripcion');
  if (!ctx) {
    $('[data-loading]').hidden = true;
    $('[data-page]').removeAttribute('aria-busy');
    return;
  }
  try {
    await load();
  } catch (e) {
    notice(noticeEl, e.message, 'error');
  } finally {
    $('[data-loading]').hidden = true;
    $('[data-page]').removeAttribute('aria-busy');
  }
  const codigo = params.get('orden');
  if (codigo && /^[a-f0-9]{24}$/.test(codigo) && tenant) followOrder(codigo);
})();
