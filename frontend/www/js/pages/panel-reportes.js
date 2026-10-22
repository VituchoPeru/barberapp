import { $, escapeHtml, notice } from '../ui.js';
import { config } from '../config.js';
import { bootPanel, papi, H, q, money, mins, fmtDate, today, hbars, DIAS, DIAS_CORTOS, isNativeApp } from '../panel.js';

/**
 * Reportes y analítica del panel (Fases 6 y 11). Todo sale de /panel/reportes: reservas reales,
 * jornadas efectivas y cobros registrados. Los ingresos solo aparecen con reportes.financieros.
 * Las exportaciones (CSV y Excel) las genera el backend y quedan en la auditoría.
 */
const noticeEl = $('[data-notice]');
const METODOS = { efectivo: 'Efectivo', tarjeta: 'Tarjeta', yape: 'Yape', plin: 'Plin', transferencia: 'Transferencia', otro: 'Otro' };
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'set', 'oct', 'nov', 'dic'];

let report = null;
let seq = 0;

const pct = (v) => (v === null || v === undefined ? '—' : `${v} %`);
const plural = (n, one, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;
const mesLabel = (ym) => { const [y, m] = ym.split('-'); return `${MESES[Number(m) - 1]} ${y.slice(2)}`; };
const hh = (h) => `${String(h).padStart(2, '0')}:00`;
/** Primera, central y última etiqueta: quedan alineadas con sus barras en el eje. */
const ends = (arr) => (arr.length < 3 ? arr : [arr[0], arr[Math.floor((arr.length - 1) / 2)], arr[arr.length - 1]]);
const activas = (d) => d.pendiente + d.confirmada + d.completada + d.no_asistio;
const kpi = (label, value, hint = '', accent = false) => `<div class="kpi${accent ? ' kpi--accent' : ''}"><span class="kpi__label">${escapeHtml(label)}</span>
  <span class="kpi__value">${escapeHtml(String(value))}</span>${hint ? `<span class="kpi__hint">${escapeHtml(hint)}</span>` : ''}</div>`;
const table = (heads, rows) => `<div class="table-wrap"><table class="data-table"><thead><tr>${heads.map((h) => `<th scope="col">${escapeHtml(h)}</th>`).join('')}</tr></thead>
  <tbody>${rows.map((r) => `<tr>${r.map((c) => `<td>${escapeHtml(String(c ?? '—'))}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
const moreTable = (heads, rows) => `<details class="more-table"><summary>Ver tabla</summary>${table(heads, rows)}</details>`;

function range() {
  const r = $('[data-rango]').value;
  if (r === 'custom') return [$('[data-desde]').value, $('[data-hasta]').value];
  if (r === 'mes') return [`${today().slice(0, 8)}01`, today()];
  if (r === 'mes-anterior') {
    const d = new Date(`${today().slice(0, 8)}01T12:00:00`);
    d.setDate(0); // último día del mes anterior
    const fin = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    return [`${fin.slice(0, 8)}01`, fin];
  }
  return [today(-(Number(r) - 1)), today()];
}

function filtersQuery() {
  const [desde, hasta] = range();
  const qs = new URLSearchParams({ desde, hasta });
  if ($('[data-sucursal]').value) qs.set('sucursal_id', $('[data-sucursal]').value);
  if ($('[data-barbero]').value) qs.set('barbero_id', $('[data-barbero]').value);
  return qs;
}

/** Columnas verticales de una sola serie con tooltip accesible (foco con teclado). */
function vbars(items, { axis = [] } = {}) {
  const max = Math.max(1, ...items.map((i) => i.value));
  return `<div class="vbars" role="list">${items.map((i) => `<div class="vbar" role="listitem" tabindex="0" aria-label="${escapeHtml(i.aria)}">
      <span class="vbar__tip">${i.tip}</span><span class="vbar__fill" style="height:${(i.value * 100) / max}%"></span></div>`).join('')}</div>
    <div class="vbars__axis" aria-hidden="true">${axis.map((a) => `<span>${escapeHtml(a)}</span>`).join('')}</div>`;
}

async function load() {
  const qs = filtersQuery();
  if (!qs.get('desde') || !qs.get('hasta')) return;
  const my = ++seq;
  $('[data-page]').setAttribute('aria-busy', 'true');
  try {
    const r = await papi(`/panel/reportes?${qs}`);
    if (my !== seq) return;
    report = r;
    render(r);
    noticeEl.hidden = true;
    $('[data-report]').hidden = false;
  } catch (e) {
    notice(noticeEl, e.message, 'error');
  } finally {
    $('[data-loading]').hidden = true;
    $('[data-page]').removeAttribute('aria-busy');
  }
}

function fillFilters(r) {
  const suc = $('[data-sucursal]');
  if (suc.options.length <= 1) {
    suc.insertAdjacentHTML('beforeend', r.sucursales.map((s) => `<option value="${s.id}">${escapeHtml(s.nombre)}</option>`).join(''));
    suc.closest('.field').hidden = r.sucursales.length < 2;
  }
  const sel = $('[data-barbero]');
  if (sel.options.length <= 1) {
    sel.insertAdjacentHTML('beforeend', r.barberos.map((b) => `<option value="${b.id}">${escapeHtml(b.nombre)}${b.activo ? '' : ' (inactivo)'}</option>`).join(''));
    sel.closest('.field').hidden = r.barberos.length < 2;
  }
  const opt90 = $('[data-rango] option[value="90"]');
  if (opt90) opt90.disabled = !r.plan.reportes_avanzados;
  const nombre = (list, id) => (list.find((x) => x.id === id) || {}).nombre;
  $('[data-print-head]').textContent = `Periodo ${r.rango.desde} a ${r.rango.hasta} · Sucursal: ${nombre(r.sucursales, r.filtros.sucursal_id) || 'todas'} · Barbero: ${nombre(r.barberos, r.filtros.barbero_id) || 'todos'} · Zona horaria ${r.rango.zona_horaria}`;
}

/** Sección no incluida en el plan (Fase 10): explica y enlaza a la suscripción. */
const locked = (what) => `<p class="muted">${escapeHtml(what)} y los periodos de más de 31 días están en los planes con reportes avanzados.
  <a href="suscripcion.html${q}">Ver planes</a></p>`;

function render(r) {
  fillFilters(r);
  const t = r.totales;
  const c = r.confirmacion;
  const cl = r.clientes;
  const k = [
    kpi('Reservas', t.reservas, `${fmtDate(r.rango.desde, { day: 'numeric', month: 'short' })} – ${fmtDate(r.rango.hasta, { day: 'numeric', month: 'short' })}`, true),
    kpi('Atendidas', t.completada, `${t.pendiente + t.confirmada} aún por atender`),
    kpi('Confirmadas', c.confirmadas, `${pct(c.tasa_confirmacion)} de las no canceladas`),
    kpi('Cancelaciones', t.cancelada, `${pct(t.tasa_cancelacion)} de las reservas`),
    kpi('Inasistencias', t.no_asistio, `${pct(t.tasa_inasistencia)} de las citas vencidas`),
  ];
  if (r.ocupacion) k.push(kpi('Ocupación', pct(r.ocupacion.porcentaje), r.ocupacion.minutos_disponibles ? `${mins(r.ocupacion.minutos_reservados)} de ${mins(r.ocupacion.minutos_disponibles)}` : 'Sin jornada en el periodo'));
  k.push(kpi('Clientes recurrentes', cl.recurrentes, cl.atendidos ? `${pct(cl.tasa_recurrencia)} de ${plural(cl.atendidos, 'atendido')}` : 'Sin clientes atendidos'));
  if (r.ingresos) k.push(kpi('Ingresos registrados', money(r.ingresos.registrados), `${plural(r.ingresos.cobros, 'cobro')} en el periodo`, true));
  $('[data-kpis]').innerHTML = k.join('');

  renderByDay(r);
  renderServices(r);
  renderConfirm(r);
  renderDemand(r);
  renderProductivity(r);
  renderClients(r);
  renderCancel(r);
  renderIncome(r);
  renderMonthly(r);
}

function renderByDay(r) {
  const dias = r.por_dia;
  $('[data-by-day]').innerHTML = (dias.some((d) => activas(d) || d.cancelada) ? vbars(dias.map((d) => ({
    value: activas(d),
    aria: `${fmtDate(d.fecha)}: ${activas(d)} reservas, ${d.completada} atendidas, ${d.cancelada} canceladas`,
    tip: `<strong>${escapeHtml(fmtDate(d.fecha))}</strong><br>${plural(activas(d), 'reserva')}<br>${plural(d.completada, 'atendida')} · ${d.no_asistio} no asistió<br>${d.pendiente + d.confirmada} por atender · ${plural(d.cancelada, 'cancelada')}`,
  })), { axis: ends(dias).map((d) => fmtDate(d.fecha, { day: 'numeric', month: 'short' })) }) : '<p class="muted">Sin reservas en el periodo.</p>')
    + moreTable(['Día', 'Reservas', 'Atendidas', 'No asistió', 'Canceladas'], dias.map((d) => [fmtDate(d.fecha), activas(d), d.completada, d.no_asistio, d.cancelada]));
}

function renderServices(r) {
  const svc = r.servicios.slice(0, 8);
  $('[data-services]').innerHTML = svc.length
    ? hbars(svc.map((s) => ({ label: s.nombre, value: s.cantidad })), Math.max(...svc.map((s) => s.cantidad)), (v) => `${v}`)
    : '<li class="muted">Sin servicios reservados en el periodo.</li>';
}

function renderConfirm(r) {
  const c = r.confirmacion;
  $('[data-confirm]').innerHTML = c.reservas_activas ? `<dl class="kv">
      <dt>Reservas no canceladas</dt><dd>${c.reservas_activas}</dd>
      <dt>Confirmadas por la barbería</dt><dd>${c.confirmadas} (${pct(c.tasa_confirmacion)})</dd>
      <dt>Asistencia confirmada por el cliente</dt><dd>${c.asistencia_confirmada_cliente} (${pct(c.tasa_asistencia_confirmada)})</dd>
      <dt>Pendientes sin confirmar</dt><dd>${c.pendientes_sin_confirmar}</dd>
    </dl><p class="hint">Una cita cuenta como confirmada si la barbería la confirmó o la atendió. La asistencia la confirma el cliente desde el recordatorio.</p>`
    : '<p class="muted">Sin reservas en el periodo.</p>';
}

function renderDemand(r) {
  const d = r.demanda;
  if (!d) { $('[data-demand]').innerHTML = locked('La demanda por día y hora'); return; }
  if (!d.total) { $('[data-demand]').innerHTML = '<p class="muted">Sin reservas en el periodo.</p>'; return; }
  const grid = {};
  d.celdas.forEach((x) => { grid[`${x.dia}-${x.hora}`] = x.reservas; });
  const level = (n) => (n ? Math.max(0.12, n / d.max_celda) : 0);
  const head = `<tr><th scope="col"><span class="sr-only">Día</span></th>${d.horas.map((h) => `<th scope="col">${String(h).padStart(2, '0')}</th>`).join('')}<th scope="col">Total</th></tr>`;
  const body = d.por_dia_semana.map((row) => `<tr><th scope="row" title="${DIAS[row.dia]}">${DIAS_CORTOS[row.dia]}</th>${d.horas.map((h) => {
    const n = grid[`${row.dia}-${h}`] || 0;
    const a = level(n);
    return `<td class="heat__c${a > 0.6 ? ' heat__c--hi' : ''}" style="--a:${a.toFixed(2)}" title="${DIAS[row.dia]} ${hh(h)}: ${plural(n, 'reserva')}">${n || ''}</td>`;
  }).join('')}<td class="heat__t">${row.reservas}</td></tr>`).join('');
  const picos = d.picos.map((p) => `<li><strong>${DIAS[p.dia]} ${hh(p.hora)}</strong> · ${plural(p.reservas, 'reserva')}${p.promedio !== null ? ` (${p.promedio} por ${DIAS[p.dia].toLowerCase()})` : ''}</li>`).join('');
  $('[data-demand]').innerHTML = `<div class="table-wrap"><table class="heat"><caption class="sr-only">Reservas por día de la semana y hora de inicio</caption><thead>${head}</thead><tbody>${body}</tbody></table></div>
    <div class="grid-2" style="margin-top:12px"><div><h4 class="mini-h">Franjas con más demanda</h4><ol class="plain-list">${picos}</ol></div>
    <div><h4 class="mini-h">Promedio por día de la semana</h4><ul class="hbars">${hbars(d.por_dia_semana.map((x) => ({ label: DIAS[x.dia], value: x.promedio ?? 0 })), Math.max(0.01, ...d.por_dia_semana.map((x) => x.promedio ?? 0)), (v) => `${v}`)}</ul>
    <p class="hint">Reservas ÷ veces que ese día aparece en el periodo.</p></div></div>`;
}

function renderProductivity(r) {
  const p = r.productividad;
  if (!p) { $('[data-productivity]').innerHTML = locked('La productividad y ocupación por barbero'); return; }
  if (!p.length) { $('[data-productivity]').innerHTML = '<p class="muted">Ningún barbero tuvo jornada ni citas en el periodo.</p>'; return; }
  const fin = p[0].ingresos_registrados !== undefined;
  const occ = p.filter((b) => b.ocupacion !== null);
  $('[data-productivity]').innerHTML = (occ.length ? `<h4 class="mini-h">Ocupación</h4><ul class="hbars">${hbars(occ.map((b) => ({ label: b.nombre, value: b.ocupacion })), 100, (v) => `${v} %`)}</ul>` : '')
    + `<div style="margin-top:12px">${table(
      ['Barbero', 'Atendidas', 'Clientes', 'Tiempo atendido', 'Atendidas por hora', 'No asistió', 'Duración real', 'Previsto atendidas', ...(fin ? ['Cobrado de atendidas', 'Ticket promedio'] : [])],
      p.map((b) => [
        b.nombre + (b.activo ? '' : ' (inactivo)'), b.atendidas, b.clientes_atendidos, mins(b.minutos_atendidos),
        b.atendidas_por_hora ?? '—', `${b.no_asistio}${b.tasa_inasistencia !== null ? ` (${b.tasa_inasistencia} %)` : ''}`,
        b.duracion_real_promedio !== null ? mins(b.duracion_real_promedio) : '—', money(b.importe_previsto_atendidas),
        ...(fin ? [money(b.cobrado_atendidas), b.ticket_promedio !== null ? money(b.ticket_promedio) : '—'] : []),
      ]),
    )}</div><p class="hint">Duración real: promedio entre el inicio y el fin de atención registrados. El ticket promedio usa solo citas atendidas con cobro.</p>`;
}

function renderClients(r) {
  const c = r.clientes;
  if (!c.atendidos) { $('[data-clients]').innerHTML = '<p class="muted">Sin clientes atendidos en el periodo.</p>'; return; }
  const d = c.distribucion_visitas;
  $('[data-clients]').innerHTML = `<dl class="kv">
      <dt>Clientes atendidos</dt><dd>${c.atendidos}</dd>
      <dt>Recurrentes</dt><dd>${c.recurrentes} (${pct(c.tasa_recurrencia)})</dd>
      <dt>Nuevos</dt><dd>${c.nuevos}</dd>
      <dt>Visitas por cliente</dt><dd>${c.visitas_promedio}</dd>
    </dl>
    <ul class="hbars" style="margin-top:12px">${hbars([{ label: '1 visita', value: d['1'] }, { label: '2 visitas', value: d['2'] }, { label: '3 o más', value: d['3+'] }], Math.max(1, d['1'], d['2'], d['3+']), (v) => `${v}`)}</ul>
    ${c.top && c.top.length ? `<h4 class="mini-h" style="margin-top:12px">Vuelven más seguido</h4><ol class="plain-list">${c.top.map((x) => `<li>${escapeHtml(x.nombre)} · ${plural(x.visitas, 'visita')}</li>`).join('')}</ol>` : ''}
    <p class="hint">Recurrente: atendido en el periodo y con otra visita atendida antes o dentro del periodo${r.filtros.barbero_id || r.filtros.sucursal_id ? ' (con el filtro elegido)' : ''}.</p>`;
}

function renderCancel(r) {
  const t = r.totales;
  const c = r.cancelaciones;
  $('[data-cancel]').innerHTML = `<dl class="kv">
    <dt>Canceladas</dt><dd>${c.total} (${pct(c.tasa)} de ${t.reservas})</dd>
    <dt>Por el cliente</dt><dd>${c.por.cliente}</dd>
    <dt>Por la barbería</dt><dd>${c.por.personal}</dd>
    <dt>Por el sistema</dt><dd>${c.por.sistema}</dd>
    <dt>Inasistencias</dt><dd>${r.inasistencias.total} (${pct(r.inasistencias.tasa)} de las citas que ya debían atenderse)</dd>
  </dl>`;
}

function renderIncome(r) {
  $('[data-income-box]').hidden = !r.ingresos;
  if (!r.ingresos) return;
  const i = r.ingresos;
  const a = i.atendidas;
  const pm = i.por_metodo;
  const pb = i.por_barbero;
  const dias = i.por_dia;
  $('[data-income]').innerHTML = `<p style="margin:0 0 8px"><strong style="font-size:1.4rem">${money(i.registrados)}</strong> <span class="muted">en ${plural(i.cobros, 'cobro')} del periodo</span></p>
    ${dias.some((d) => Number(d.monto) > 0) ? vbars(dias.map((d) => ({
    value: Number(d.monto),
    aria: `${fmtDate(d.fecha)}: ${money(d.monto)} en ${plural(d.cobros, 'cobro')}`,
    tip: `<strong>${escapeHtml(fmtDate(d.fecha))}</strong><br>${money(d.monto)} · ${plural(d.cobros, 'cobro')}`,
  })), { axis: ends(dias).map((d) => fmtDate(d.fecha, { day: 'numeric', month: 'short' })) }) : '<p class="muted">Aún no se registraron cobros en el periodo.</p>'}
    <div class="grid-2" style="margin-top:12px">
      <div><h4 class="mini-h">Citas atendidas del periodo</h4><dl class="kv">
        <dt>Importe previsto</dt><dd>${money(a.importe_previsto)}</dd>
        <dt>Cobrado por esas citas</dt><dd>${money(a.cobrado)}</dd>
        <dt>Saldo por cobrar</dt><dd>${money(a.saldo_por_cobrar)}</dd>
        <dt>Atendidas sin cobro</dt><dd>${a.sin_cobro} de ${a.citas}</dd>
      </dl><p class="hint">El previsto es el precio de las citas; solo lo cobrado es ingreso.</p></div>
      <div>${pm.length ? `<h4 class="mini-h">Por método de pago</h4><ul class="hbars">${hbars(pm.map((p) => ({ label: METODOS[p.metodo] || p.metodo, value: Number(p.monto) })), Math.max(...pm.map((p) => Number(p.monto))), money)}</ul>` : ''}
        ${pb.length ? `<h4 class="mini-h" style="margin-top:12px">Por barbero</h4><ul class="hbars">${hbars(pb.map((p) => ({ label: p.nombre, value: Number(p.monto) })), Math.max(...pb.map((p) => Number(p.monto))), money)}</ul>` : ''}</div>
    </div>
    <p class="hint">Solo cuenta lo cobrado y registrado en la agenda, por fecha de cobro (cobros anulados excluidos).</p>`;
}

function renderMonthly(r) {
  const m = r.evolucion_mensual;
  if (!m) { $('[data-monthly]').innerHTML = locked('La evolución mensual'); return; }
  const conIngresos = m.length && m[0].ingresos !== undefined;
  $('[data-monthly]').innerHTML = vbars(m.map((x) => ({
    value: x.reservas,
    aria: `${mesLabel(x.mes)}: ${x.reservas} reservas`,
    tip: `<strong>${escapeHtml(mesLabel(x.mes))}</strong><br>${plural(x.reservas, 'reserva')} · ${plural(x.completada, 'atendida')}<br>${plural(x.cancelada, 'cancelada')} · ${x.no_asistio} no asistió${conIngresos ? `<br>Ingresos: ${money(x.ingresos)}` : ''}`,
  })), { axis: ends(m).map((x) => mesLabel(x.mes)) })
    + moreTable(['Mes', 'Reservas', 'Atendidas', 'Canceladas', 'No asistió', ...(conIngresos ? ['Ingresos'] : [])],
      m.map((x) => [mesLabel(x.mes), x.reservas, x.completada, x.cancelada, x.no_asistio, ...(conIngresos ? [money(x.ingresos)] : [])]));
}

// ======================================================================= exportar

/** Descarga desde el backend con la sesión actual (la exportación queda en la auditoría). */
async function download(btn, tipo, formato) {
  const qs = filtersQuery();
  qs.set('tipo', tipo);
  qs.set('formato', formato);
  btn.disabled = true;
  btn.setAttribute('aria-busy', 'true');
  try {
    const res = await fetch(`${config.API_BASE_URL}/panel/reportes/exportar?${qs}`, { credentials: 'include', headers: { ...H, Accept: formato === 'csv' ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' } });
    if (!res.ok) {
      const p = await res.json().catch(() => null);
      throw new Error((p && p.error && p.error.message) || 'No se pudo generar el archivo.');
    }
    const name = (res.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/);
    const url = URL.createObjectURL(await res.blob());
    const a = Object.assign(document.createElement('a'), { href: url, download: name ? name[1] : `reporte.${formato}` });
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    $('[data-export]').open = false;
  } catch (e) {
    notice(noticeEl, e.message, 'error');
  } finally {
    btn.disabled = false;
    btn.removeAttribute('aria-busy');
  }
}

/** Imprimir: se abren las tablas plegadas para que salgan en el PDF. */
function printReport() {
  $('[data-export]').open = false;
  const closed = [...document.querySelectorAll('[data-report] details:not([open])')];
  closed.forEach((d) => { d.open = true; });
  window.addEventListener('afterprint', () => closed.forEach((d) => { d.open = false; }), { once: true });
  window.print();
}

// ======================================================================= controles

$('[data-rango]').addEventListener('change', (e) => {
  const custom = e.target.value === 'custom';
  document.querySelectorAll('[data-custom]').forEach((f) => { f.hidden = !custom; });
  if (custom) {
    const [d, h] = report ? [report.rango.desde, report.rango.hasta] : [today(-29), today()];
    $('[data-desde]').value = d;
    $('[data-hasta]').value = h;
  }
  load();
});
$('[data-desde]').addEventListener('change', load);
$('[data-hasta]').addEventListener('change', load);
$('[data-sucursal]').addEventListener('change', load);
$('[data-barbero]').addEventListener('change', load);
$('[data-filters]').addEventListener('submit', (e) => e.preventDefault());
document.querySelectorAll('[data-dl]').forEach((b) => b.addEventListener('click', () => {
  const [tipo, formato] = b.dataset.dl.split(':');
  download(b, tipo, formato);
}));
const printBtn = $('[data-print]');
// En la app nativa (Capacitor) no hay diálogo de impresión: la opción se oculta.
if (isNativeApp() || typeof window.print !== 'function') printBtn.hidden = true;
printBtn.addEventListener('click', printReport);
document.addEventListener('click', (e) => {
  const menu = $('[data-export]');
  if (menu.open && !menu.contains(e.target)) menu.open = false;
});

(async () => {
  const ctx = await bootPanel('reportes');
  if (!ctx) return;
  load();
})();
