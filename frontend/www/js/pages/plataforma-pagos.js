import { $, escapeHtml } from '../ui.js';
import { icon } from '../icons.js';
import {
  bootPlatform, papi, fail, done, askText, toast, badge, demoBadge, PAGO_ESTADOS, METODOS, money, fmtDateTime, fmtDay,
  formQuery, debounce, errorText, pager, plural, kpi, formSheet, field,
} from '../plataforma.js';

/** Pagos del SaaS (Fase 9): listado, totales por estado y confirmación, rechazo o reembolso. */
let page = 1;
const form = $('[data-filters]');
const params = new URLSearchParams(location.search);
form.metodo.insertAdjacentHTML('beforeend', Object.entries(METODOS).map(([k, l]) => `<option value="${k}">${l}</option>`).join(''));
['q', 'estado', 'metodo', 'desde', 'hasta'].forEach((k) => { if (params.get(k) !== null) form[k].value = params.get(k); });

function row(p) {
  const acts = p.estado === 'pendiente'
    ? `<button class="btn btn--sm" type="button" data-act="confirmar" data-id="${p.id}">Confirmar</button><button class="btn btn--ghost btn--sm" type="button" data-act="rechazar" data-id="${p.id}">Rechazar</button>`
    : p.estado === 'confirmado' ? `<button class="btn btn--ghost btn--sm" type="button" data-act="reembolsar" data-id="${p.id}">Reembolsar</button>` : '';
  return `<li class="row" data-inactive="${['rechazado', 'reembolsado'].includes(p.estado)}">
    <span class="thumb">${icon('cash')}</span>
    <div class="row__main"><p class="row__title">${money(p.monto, p.moneda)} · ${escapeHtml(p.barberia.nombre)}</p>
      <p class="row__meta"><span>${escapeHtml(METODOS[p.metodo] || p.metodo)}${p.referencia ? ` · ${escapeHtml(p.referencia)}` : ''}</span>
        <span>${escapeHtml(p.plan || '')}${p.periodo_inicio ? ` · ${fmtDay(p.periodo_inicio)} – ${fmtDay(p.periodo_fin)}` : ''}</span>
        <span>${p.pagado_en ? `Pagado ${fmtDateTime(p.pagado_en)}` : `Registrado ${fmtDateTime(p.registrado_en)}`}${p.registrado_por ? ` por ${escapeHtml(p.registrado_por)}` : ''}</span>
        ${p.notas ? `<span>${escapeHtml(p.notas)}</span>` : ''}</p></div>
    <div class="row__actions">${demoBadge(p.barberia)}${badge(PAGO_ESTADOS, p.estado)}${acts}</div></li>`;
}

async function load() {
  try {
    const { data, meta } = await papi(`/pagos?${formQuery(form, { page })}`, { withMeta: true });
    $('[data-totals]').innerHTML = meta.totales.length
      ? meta.totales.map((t) => kpi(PAGO_ESTADOS[t.estado][0], money(t.monto, t.moneda), plural(t.pagos, 'pago', 'pagos'), '', t.estado === 'confirmado')).join('')
      : '';
    $('[data-count]').textContent = meta.total ? `${plural(meta.total, 'pago', 'pagos')} con estos filtros` : '';
    $('[data-list]').innerHTML = data.length ? data.map(row).join('')
      : `<li class="empty">${icon('cash')}<h3>Sin pagos</h3><p>No hay pagos con esos filtros. Registra un cobro desde la ficha de la suscripción.</p></li>`;
    pager(meta, (n) => { page = n; load(); });
  } catch (e) { fail(e.message); return; }
  done();
}

async function act(id, a) {
  try {
    if (a === 'confirmar') {
      formSheet({
        title: 'Confirmar pago',
        ok: 'Confirmar',
        html: `<p class="hint">Confírmalo cuando veas el dinero en la cuenta. Con periodos, la suscripción se renueva.</p>
          ${field('periodos', 'Periodos que paga', '<input type="number" min="0" max="24" value="1">', '0 = no extiende el periodo.')}
          ${field('pagado_en', 'Fecha de pago', '<input type="date">', 'Vacío = hoy.')}`,
        onSubmit: async (f) => {
          const body = { periodos: Number(f.periodos.value || 0) };
          if (f.pagado_en.value) body.pagado_en = f.pagado_en.value;
          await papi(`/pagos/${id}/confirmar`, { method: 'POST', body });
          toast('Pago confirmado.');
          load();
        },
      });
      return;
    }
    const motivo = await askText(a === 'rechazar' ? 'Rechazar el pago (no se recibió o es incorrecto).' : 'Reembolsar el pago. El periodo ya pagado no se acorta: si corresponde, suspende o cancela la suscripción.', {
      label: 'Motivo (mín. 5 caracteres)', ok: a === 'rechazar' ? 'Rechazar' : 'Reembolsar', danger: true, required: true,
    });
    if (motivo === null) return;
    await papi(`/pagos/${id}/${a}`, { method: 'POST', body: { motivo } });
    toast(a === 'rechazar' ? 'Pago rechazado.' : 'Pago marcado como reembolsado.');
    load();
  } catch (e) { toast(errorText(e), 'error'); }
}

// ======================================================================= pagos en línea (Fase 10)

const ORDEN_ESTADOS = { pendiente: ['Pendiente', 'warn'], pagada: ['Pagada', 'ok'], fallida: ['Rechazada', 'off'], expirada: ['Expirada', 'off'], anulada: ['Anulada', 'off'] };
const oForm = $('[data-online-filters]');
let oPage = 1;

function orderRow(o) {
  return `<li class="row" data-inactive="${['fallida', 'expirada', 'anulada'].includes(o.estado)}">
    <span class="thumb">${icon('globe')}</span>
    <div class="row__main"><p class="row__title">${money(o.monto, o.moneda)} · ${escapeHtml(o.barberia.nombre)}</p>
      <p class="row__meta"><span>${escapeHtml(o.detalle)}</span>
        <span>Creada ${fmtDateTime(o.creada_en)}${o.solicitado_por ? ` por ${escapeHtml(o.solicitado_por)}` : ''}${o.pagada_en ? ` · pagada ${fmtDateTime(o.pagada_en)}` : ''}</span>
        <span>${escapeHtml(o.pasarela === 'mercadopago' ? 'Mercado Pago' : o.pasarela)}${o.pago_externo_id ? ` · operación ${escapeHtml(o.pago_externo_id)}` : ''}</span>
        ${o.error ? `<span>${escapeHtml(o.error)}</span>` : ''}</p></div>
    <div class="row__actions">${demoBadge(o.barberia)}${o.por_revisar ? '<span class="badge badge--warn">En revisión</span>' : ''}${badge(ORDEN_ESTADOS, o.estado)}</div></li>`;
}

async function loadOrders(append = false) {
  try {
    const { data, meta } = await papi(`/ordenes?${formQuery(oForm, { page: oPage })}`, { withMeta: true });
    const g = meta.pasarela;
    if (!g.habilitada) $('[data-online-hint]').textContent = 'El pago en línea está desactivado (PAYMENT_DRIVER=none). Aquí se verán las órdenes cuando se active.';
    const html = data.map(orderRow).join('');
    const list = $('[data-online-list]');
    if (append) list.insertAdjacentHTML('beforeend', html);
    else list.innerHTML = html || `<li class="empty">${icon('globe')}<h3>Sin órdenes</h3><p>Ninguna barbería inició un pago en línea con esos filtros.</p></li>`;
    $('[data-online-more]').hidden = meta.page * meta.per_page >= meta.total;
  } catch (e) { toast(errorText(e), 'error'); }
}

(async () => {
  if (!await bootPlatform('pagos')) return;
  const reloadOrders = () => { oPage = 1; loadOrders(); };
  oForm.addEventListener('change', reloadOrders);
  oForm.addEventListener('submit', (e) => { e.preventDefault(); reloadOrders(); });
  $('[data-online-more] button').addEventListener('click', () => { oPage += 1; loadOrders(true); });
  loadOrders();
  const reload = () => { page = 1; load(); };
  form.addEventListener('submit', (e) => { e.preventDefault(); reload(); });
  form.q.addEventListener('input', debounce(reload));
  ['estado', 'metodo', 'desde', 'hasta'].forEach((k) => form[k].addEventListener('change', reload));
  $('[data-list]').addEventListener('click', (e) => { const b = e.target.closest('[data-act]'); if (b) act(b.dataset.id, b.dataset.act); });
  load();
})();
