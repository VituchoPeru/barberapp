import { $, escapeHtml } from '../ui.js';
import { icon } from '../icons.js';
import {
  bootPlatform, papi, can, fail, done, openSheet, confirmAction, askText, toast, badge, demoBadge, SUB_ESTADOS, PAGO_ESTADOS, METODOS,
  money, fmtDateTime, fmtDay, formQuery, debounce, errorText, pager, plural, formSheet, field, options,
} from '../plataforma.js';

/** Suscripciones del SaaS (Fase 9): alta, cambio de plan, renovación, suspensión, cancelación y pagos. */
let ctx;
let page = 1;
let plans = [];
const form = $('[data-filters]');
const sheet = $('[data-detail]');
const params = new URLSearchParams(location.search);
['q', 'estado', 'plan', 'vence_en_dias', 'historial'].forEach((k) => { if (params.get(k) !== null && form[k]) form[k].value = params.get(k); });

const EVENTOS = {
  alta: 'Alta', cambio_plan: 'Cambio de plan', renovacion: 'Renovación', pago: 'Pago', suspension: 'Suspensión',
  reactivacion: 'Reactivación', cancelacion: 'Cancelación', vencimiento: 'Vencimiento',
};
const vigente = (s) => ['prueba', 'activa'].includes(s.estado_efectivo);

function row(s) {
  const fin = s.estado_efectivo === 'cancelada' ? `Cancelada ${fmtDay(s.cancelada_en || s.terminada_en)}`
    : vigente(s) ? `Vence ${fmtDay(s.periodo_fin_en)} (${plural(s.dias_restantes, 'día', 'días')})` : `Terminó ${fmtDay(s.terminada_en || s.periodo_fin_en)}`;
  return `<li class="row row--link" tabindex="0" role="button" data-id="${s.id}" aria-label="Ver suscripción de ${escapeHtml(s.barberia.nombre)}">
    <span class="thumb">${icon('calendar')}</span>
    <div class="row__main"><p class="row__title">${escapeHtml(s.barberia.nombre)}</p>
      <p class="row__meta"><strong>${escapeHtml(s.plan.nombre)}</strong><span>${money(s.precio_pactado, s.moneda)} / ${s.ciclo === 'anual' ? 'año' : 'mes'}</span><span>${fin}</span></p></div>
    <div class="row__actions">${demoBadge(s.barberia)}${badge(SUB_ESTADOS, s.estado_efectivo)}</div></li>`;
}

async function load() {
  try {
    const { data, meta } = await papi(`/suscripciones?${formQuery(form, { page })}`, { withMeta: true });
    $('[data-count]').textContent = meta.total ? plural(meta.total, 'suscripción', 'suscripciones') : '';
    $('[data-list]').innerHTML = data.length ? data.map(row).join('')
      : `<li class="empty">${icon('calendar')}<h3>Sin resultados</h3><p>No hay suscripciones con esos filtros.</p></li>`;
    pager(meta, (p) => { page = p; load(); });
  } catch (e) { fail(e.message); return; }
  done();
}

function detailHtml(s) {
  const acts = [];
  const pagos = can(ctx, 'plataforma.pagos.gestionar');
  const est = s.estado_efectivo;
  const ended = new Date(s.periodo_fin_en) <= new Date();
  if (vigente(s)) acts.push(['cambiar-plan', 'Cambiar plan'], ['renovar', 'Renovar'], ['suspender', 'Suspender', true]);
  if (est === 'vencida') acts.push(['renovar', 'Renovar desde hoy']);
  if (est === 'suspendida') acts.push(ended ? ['renovar', 'Renovar desde hoy'] : ['reactivar', 'Reactivar']);
  if (est !== 'cancelada') {
    if (pagos) acts.push(['pago', 'Registrar pago']);
    acts.push(['cancelar', 'Cancelar', true]);
  }
  return `
    <div class="toolbar">${badge(SUB_ESTADOS, est)}${demoBadge(s.barberia)}${est !== s.estado ? '<span class="badge badge--warn">Periodo terminado, pendiente de marcar</span>' : ''}</div>
    ${acts.length ? `<div class="action-grid">${acts.map(([a, l, d]) => `<button class="btn btn--sm${d ? ' btn--danger' : a === 'pago' || a === 'renovar' ? '' : ' btn--ghost'}" type="button" data-act="${a}">${l}</button>`).join('')}</div>` : ''}
    <dl class="kv">
      <dt>Barbería</dt><dd>${escapeHtml(s.barberia.nombre)} <span class="muted">/b/${escapeHtml(s.barberia.slug)}</span></dd>
      <dt>Plan</dt><dd>${escapeHtml(s.plan.nombre)} · ${escapeHtml(s.ciclo)}</dd>
      <dt>Precio pactado</dt><dd>${money(s.precio_pactado, s.moneda)} · aporta ${money(s.mrr, s.moneda)} al MRR</dd>
      <dt>Inicio</dt><dd>${fmtDateTime(s.inicio_en)}</dd>
      ${s.prueba_fin_en ? `<dt>Fin de la prueba</dt><dd>${fmtDateTime(s.prueba_fin_en)}</dd>` : ''}
      <dt>Fin del periodo</dt><dd>${fmtDateTime(s.periodo_fin_en)}${s.dias_restantes !== null ? ` · ${plural(s.dias_restantes, 'día', 'días')}` : ''}</dd>
      ${s.terminada_en ? `<dt>Terminó</dt><dd>${fmtDateTime(s.terminada_en)}</dd>` : ''}
      ${s.notas ? `<dt>Notas</dt><dd>${escapeHtml(s.notas)}</dd>` : ''}
    </dl>
    <h3 class="section-sub">Pagos</h3>
    ${s.pagos.length ? `<ul class="pay-list">${s.pagos.map((p) => `<li data-void="${['rechazado', 'reembolsado'].includes(p.estado)}"><span>${money(p.monto, p.moneda)} · ${escapeHtml(METODOS[p.metodo] || p.metodo)}${p.referencia ? ` · ${escapeHtml(p.referencia)}` : ''}<br><small class="muted">${p.periodo_inicio ? `${fmtDay(p.periodo_inicio)} – ${fmtDay(p.periodo_fin)}` : 'Sin periodo asignado'}</small></span>${badge(PAGO_ESTADOS, p.estado)}</li>`).join('')}</ul>` : '<p class="muted">Sin pagos registrados.</p>'}
    <h3 class="section-sub">Historial</h3>
    <ul class="history">${s.eventos.map((e) => `<li><strong>${escapeHtml(EVENTOS[e.tipo] || e.tipo)}</strong>${e.plan_anterior && e.plan_nuevo && e.plan_anterior !== e.plan_nuevo ? `: ${escapeHtml(e.plan_anterior)} → ${escapeHtml(e.plan_nuevo)}` : ''}${e.precio_anterior && e.precio_nuevo && e.precio_anterior !== e.precio_nuevo ? ` (${escapeHtml(e.precio_anterior)} → ${escapeHtml(e.precio_nuevo)})` : ''}${e.periodo_fin_nuevo ? ` · hasta ${fmtDay(e.periodo_fin_nuevo)}` : ''}${e.notas ? `<br>${escapeHtml(e.notas)}` : ''}<small>${fmtDateTime(e.fecha)}${e.por ? ` · ${escapeHtml(e.por)}` : ''}</small></li>`).join('')}</ul>`;
}

async function open(id) {
  let s;
  try { s = await papi(`/suscripciones/${id}`); } catch (e) { toast(e.message, 'error'); return; }
  $('[data-det-title]').textContent = s.barberia.nombre;
  const body = $('[data-det-body]');
  body.innerHTML = detailHtml(s);
  body.querySelectorAll('[data-act]').forEach((b) => { b.onclick = () => act(s, b.dataset.act); });
  openSheet(sheet);
}

const done2 = (s, msg) => { toast(msg); load(); open(s.id); };
const planOptions = (selected) => options(plans.filter((p) => p.activo || String(p.id) === String(selected)).map((p) => [p.id, `${p.nombre} · ${money(p.precio_mensual, p.moneda)}/mes`]), selected);

async function act(s, a) {
  const post = (path, body) => papi(`/suscripciones/${s.id}/${path}`, { method: 'POST', body });
  try {
    if (a === 'suspender' || a === 'cancelar') {
      const motivo = await askText(a === 'suspender'
        ? 'Suspender la suscripción: deja de estar vigente y de sumar al MRR. Podrás reactivarla mientras quede periodo.'
        : 'Cancelar la suscripción: termina de forma definitiva. Para volver, se crea una nueva.', { label: 'Motivo (mín. 5 caracteres)', ok: a === 'suspender' ? 'Suspender' : 'Cancelar suscripción', danger: true, required: true });
      if (motivo === null) return;
      done2(await post(a, { motivo }), a === 'suspender' ? 'Suscripción suspendida.' : 'Suscripción cancelada.');
    } else if (a === 'reactivar') {
      if (!await confirmAction('¿Reactivar la suscripción con el periodo que le quedaba?', { ok: 'Reactivar' })) return;
      const r = await post('reactivar', {});
      sheet.close();
      toast('Suscripción reactivada.');
      load();
      open(r.id);
    } else if (a === 'renovar') {
      formSheet({
        title: 'Renovar suscripción',
        ok: 'Renovar',
        html: `<p class="hint">${vigente(s) ? `Se extiende desde el fin del periodo actual (${fmtDay(s.periodo_fin_en)}).` : 'El periodo ya terminó: se crea una suscripción nueva desde hoy con el mismo plan y precio.'} Para registrar el cobro a la vez, usa «Registrar pago».</p>
          ${field('periodos', `Periodos (${s.ciclo === 'anual' ? 'años' : 'meses'})`, '<input type="number" min="1" max="24" value="1" required>')}
          ${field('notas', 'Notas', '<input maxlength="500">')}`,
        onSubmit: async (f) => {
          const r = await post('renovar', { periodos: Number(f.periodos.value), notas: f.notas.value.trim() || null });
          sheet.close(); toast('Suscripción renovada.'); load(); open(r.id);
        },
      });
    } else if (a === 'cambiar-plan') {
      formSheet({
        title: 'Cambiar plan',
        ok: 'Cambiar plan',
        html: `${field('plan_id', 'Plan', `<select required>${planOptions(s.plan.id)}</select>`)}
          ${field('ciclo', 'Ciclo', `<select>${options([['mensual', 'Mensual'], ['anual', 'Anual']], s.ciclo)}</select>`)}
          ${field('precio_pactado', 'Precio pactado (opcional)', '<input type="number" min="0" step="0.01" inputmode="decimal">', 'Vacío = precio de lista del plan para el ciclo elegido.')}
          ${field('notas', 'Notas', '<input maxlength="500">')}
          <p class="hint">El periodo actual se mantiene. El nuevo precio pactado cuenta desde ahora en el MRR y se cobra en las próximas renovaciones.</p>`,
        onSubmit: async (f) => {
          const body = { plan_id: Number(f.plan_id.value), ciclo: f.ciclo.value, notas: f.notas.value.trim() || null };
          if (f.precio_pactado.value !== '') body.precio_pactado = f.precio_pactado.value;
          done2(await post('cambiar-plan', body), 'Plan actualizado.');
        },
      });
    } else if (a === 'pago') {
      paymentForm(s);
    }
  } catch (e) { toast(errorText(e), 'error'); }
}

function paymentForm(s) {
  formSheet({
    title: 'Registrar pago',
    ok: 'Registrar pago',
    html: `<p class="hint">Pagos manuales (transferencia, Yape, Plin, efectivo). Un pago confirmado con periodos renueva la suscripción.</p>
      <div class="row2">${field('monto', `Monto (${s.moneda})`, `<input type="number" min="0.01" step="0.01" inputmode="decimal" value="${escapeHtml(s.precio_pactado)}" required>`)}
      ${field('metodo', 'Método', `<select>${options(Object.entries(METODOS).filter(([k]) => k !== 'pasarela'), 'transferencia')}</select>`)}</div>
      <div class="row2">${field('referencia', 'N.º de operación', '<input maxlength="120" autocomplete="off">', 'Evita registrar dos veces el mismo pago.')}
      ${field('estado', 'Estado', `<select>${options([['confirmado', 'Confirmado (ya recibido)'], ['pendiente', 'Por confirmar']], 'confirmado')}</select>`)}</div>
      <div class="row2">${field('periodos', 'Periodos que paga', '<input type="number" min="0" max="24" value="1">', '0 = no extiende el periodo.')}
      ${field('pagado_en', 'Fecha de pago', '<input type="date">', 'Vacío = hoy.')}</div>
      ${field('notas', 'Notas', '<input maxlength="500">')}`,
    onSubmit: async (f) => {
      const body = { monto: f.monto.value, metodo: f.metodo.value, estado: f.estado.value, periodos: Number(f.periodos.value || 0), referencia: f.referencia.value.trim() || null, notas: f.notas.value.trim() || null };
      if (f.pagado_en.value) body.pagado_en = f.pagado_en.value;
      await papi(`/suscripciones/${s.id}/pagos`, { method: 'POST', body });
      sheet.close();
      toast(body.estado === 'confirmado' ? 'Pago registrado.' : 'Pago registrado como por confirmar.');
      load();
      open(s.id);
    },
  });
}

async function newSubscription(tenantId = '') {
  let tenants = [];
  try {
    tenants = (await papi('/barberias?per_page=100')).filter((t) => t.estado !== 'cancelado' && (!t.suscripcion || !['prueba', 'activa'].includes(t.suscripcion.estado)));
  } catch (e) { toast(e.message, 'error'); return; }
  if (!tenants.length) { toast('Todas las barberías ya tienen una suscripción vigente.'); return; }
  const f = formSheet({
    title: 'Nueva suscripción',
    ok: 'Crear suscripción',
    html: `${field('tenant_id', 'Barbería', `<select required>${options(tenants.map((t) => [t.id, `${t.nombre}${t.es_demo ? ' (demo)' : ''}`]), tenantId)}</select>`, 'Solo barberías sin suscripción vigente.')}
      ${field('plan_id', 'Plan', `<select required>${planOptions('')}</select>`)}
      <div class="row2">${field('estado', 'Tipo', `<select>${options([['prueba', 'Prueba gratuita'], ['activa', 'Pagada']], 'prueba')}</select>`)}
      ${field('ciclo', 'Ciclo', `<select>${options([['mensual', 'Mensual'], ['anual', 'Anual']], 'mensual')}</select>`)}</div>
      <div class="row2" data-prueba>${field('dias_prueba', 'Días de prueba', '<input type="number" min="1" max="90" inputmode="numeric">', 'Vacío = los del plan.')}</div>
      <div class="row2" data-pagada hidden>${field('periodos', 'Periodos', '<input type="number" min="1" max="24" value="1">')}
      ${field('precio_pactado', 'Precio pactado', '<input type="number" min="0" step="0.01" inputmode="decimal">', 'Vacío = precio de lista.')}</div>
      ${field('notas', 'Notas', '<input maxlength="500">')}`,
    onSubmit: async (fm) => {
      const paid = fm.estado.value === 'activa';
      const body = { tenant_id: Number(fm.tenant_id.value), plan_id: Number(fm.plan_id.value), estado: fm.estado.value, ciclo: fm.ciclo.value, notas: fm.notas.value.trim() || null };
      if (!paid && fm.dias_prueba.value) body.dias_prueba = Number(fm.dias_prueba.value);
      if (paid) { body.periodos = Number(fm.periodos.value || 1); if (fm.precio_pactado.value !== '') body.precio_pactado = fm.precio_pactado.value; }
      const r = await papi('/suscripciones', { method: 'POST', body });
      toast('Suscripción creada.');
      load();
      open(r.id);
    },
  });
  const sync = () => { const paid = f.estado.value === 'activa'; f.querySelector('[data-pagada]').hidden = !paid; f.querySelector('[data-prueba]').hidden = paid; };
  f.estado.addEventListener('change', sync);
}

(async () => {
  ctx = await bootPlatform('suscripciones');
  if (!ctx) return;
  try { plans = await papi('/planes'); } catch { plans = []; }
  form.plan.insertAdjacentHTML('beforeend', options(plans.map((p) => [p.codigo, p.nombre]), params.get('plan') || ''));
  const reload = () => { page = 1; load(); };
  form.addEventListener('submit', (e) => { e.preventDefault(); reload(); });
  form.q.addEventListener('input', debounce(reload));
  ['estado', 'plan', 'vence_en_dias', 'historial'].forEach((k) => form[k].addEventListener('change', reload));
  $('[data-list]').addEventListener('click', (e) => { const li = e.target.closest('[data-id]'); if (li) open(li.dataset.id); });
  $('[data-list]').addEventListener('keydown', (e) => { const li = e.target.closest('[data-id]'); if (li && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(li.dataset.id); } });
  if (can(ctx, 'plataforma.tenants.gestionar')) {
    $('[data-new]').hidden = false;
    $('[data-new]').addEventListener('click', () => newSubscription());
  }
  load();
  if (params.get('abrir')) open(params.get('abrir'));
  if (params.get('nueva') && can(ctx, 'plataforma.tenants.gestionar')) newSubscription(params.get('nueva'));
})();
