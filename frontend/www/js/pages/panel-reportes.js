import { $, escapeHtml, notice } from '../ui.js';
import { bootPanel, papi, money, mins, fmtDate, today, hbars } from '../panel.js';

/**
 * Reportes del panel (Fase 6). Todo sale de /panel/reportes: reservas reales, jornadas
 * efectivas y cobros registrados. Los ingresos solo aparecen con reportes.financieros.
 */
const noticeEl = $('[data-notice]');
const METODOS = { efectivo: 'Efectivo', tarjeta: 'Tarjeta', yape: 'Yape', plin: 'Plin', transferencia: 'Transferencia', otro: 'Otro' };
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'set', 'oct', 'nov', 'dic'];

let report = null;
let barberList = null; // barberos de la sucursal sin filtrar, para el selector
let seq = 0;

const pct = (v) => (v === null || v === undefined ? '—' : `${v} %`);
const mesLabel = (ym) => { const [y, m] = ym.split('-'); return `${MESES[Number(m) - 1]} ${y.slice(2)}`; };
/** Primera, central y última etiqueta: quedan alineadas con sus barras en el eje. */
const ends = (arr) => (arr.length < 3 ? arr : [arr[0], arr[Math.floor((arr.length - 1) / 2)], arr[arr.length - 1]]);
const activas = (d) => d.pendiente + d.confirmada + d.completada + d.no_asistio;
const kpi = (label, value, hint = '', accent = false) => `<div class="kpi${accent ? ' kpi--accent' : ''}"><span class="kpi__label">${escapeHtml(label)}</span>
  <span class="kpi__value">${escapeHtml(String(value))}</span>${hint ? `<span class="kpi__hint">${escapeHtml(hint)}</span>` : ''}</div>`;

function range() {
  const r = $('[data-rango]').value;
  if (r === 'custom') return [$('[data-desde]').value, $('[data-hasta]').value];
  if (r === 'mes') return [`${today().slice(0, 8)}01`, today()];
  return [today(-(Number(r) - 1)), today()];
}

/** Columnas verticales de una sola serie con tooltip accesible (foco con teclado). */
function vbars(items, { axis = [] } = {}) {
  const max = Math.max(1, ...items.map((i) => i.value));
  return `<div class="vbars" role="list">${items.map((i) => `<div class="vbar" role="listitem" tabindex="0" aria-label="${escapeHtml(i.aria)}">
      <span class="vbar__tip">${i.tip}</span><span class="vbar__fill" style="height:${(i.value * 100) / max}%"></span></div>`).join('')}</div>
    <div class="vbars__axis" aria-hidden="true">${axis.map((a) => `<span>${escapeHtml(a)}</span>`).join('')}</div>`;
}

async function load() {
  const [desde, hasta] = range();
  if (!desde || !hasta) return;
  const qs = new URLSearchParams({ desde, hasta });
  if ($('[data-sucursal]').value) qs.set('sucursal_id', $('[data-sucursal]').value);
  if ($('[data-barbero]').value) qs.set('barbero_id', $('[data-barbero]').value);
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
  if (!r.filtros.barbero_id) {
    barberList = r.ocupacion.barberos.map((b) => ({ id: b.barbero_id, nombre: b.nombre }));
    const sel = $('[data-barbero]');
    sel.innerHTML = '<option value="">Todos</option>' + barberList.map((b) => `<option value="${b.id}">${escapeHtml(b.nombre)}</option>`).join('');
  }
}

function render(r) {
  fillFilters(r);
  const t = r.totales;
  const k = [
    kpi('Reservas', t.reservas, `${fmtDate(r.rango.desde, { day: 'numeric', month: 'short' })} – ${fmtDate(r.rango.hasta, { day: 'numeric', month: 'short' })}`, true),
    kpi('Atendidas', t.completada, `${t.pendiente + t.confirmada} aún por atender`),
    kpi('Cancelaciones', t.cancelada, `${pct(t.tasa_cancelacion)} de las reservas`),
    kpi('Inasistencias', t.no_asistio, `${pct(t.tasa_inasistencia)} de las citas vencidas`),
    kpi('Ocupación', pct(r.ocupacion.porcentaje), r.ocupacion.minutos_disponibles ? `${mins(r.ocupacion.minutos_reservados)} de ${mins(r.ocupacion.minutos_disponibles)}` : 'Sin jornada en el periodo'),
  ];
  if (t.clientes_nuevos !== null) k.push(kpi('Clientes nuevos', t.clientes_nuevos, 'primera vez en tu barbería'));
  if (r.ingresos) k.push(kpi('Ingresos registrados', money(r.ingresos.registrados), `${r.ingresos.cobros} cobro${r.ingresos.cobros === 1 ? '' : 's'} · previsto de atendidas ${money(t.importe_previsto_atendidas)}`, true));
  $('[data-kpis]').innerHTML = k.join('');

  // Reservas por día (sin canceladas).
  const dias = r.por_dia;
  $('[data-by-day]').innerHTML = (dias.some((d) => activas(d) || d.cancelada) ? vbars(dias.map((d) => ({
    value: activas(d),
    aria: `${fmtDate(d.fecha)}: ${activas(d)} reservas, ${d.completada} atendidas, ${d.cancelada} canceladas`,
    tip: `<strong>${escapeHtml(fmtDate(d.fecha))}</strong><br>${activas(d)} reserva${activas(d) === 1 ? '' : 's'}<br>${d.completada} atendida${d.completada === 1 ? '' : 's'} · ${d.no_asistio} no asistió<br>${d.pendiente + d.confirmada} por atender · ${d.cancelada} cancelada${d.cancelada === 1 ? '' : 's'}`,
  })), { axis: ends(dias).map((d) => fmtDate(d.fecha, { day: 'numeric', month: 'short' })) }) : '<p class="muted">Sin reservas en el periodo.</p>')
    + `<details style="margin-top:8px"><summary class="link-btn">Ver tabla</summary><div class="table-wrap"><table class="data-table"><thead><tr><th>Día</th><th>Reservas</th><th>Atendidas</th><th>No asistió</th><th>Canceladas</th></tr></thead>
      <tbody>${dias.map((d) => `<tr><td>${escapeHtml(fmtDate(d.fecha))}</td><td>${activas(d)}</td><td>${d.completada}</td><td>${d.no_asistio}</td><td>${d.cancelada}</td></tr>`).join('')}</tbody></table></div></details>`;

  // Servicios más solicitados.
  const svc = r.servicios.slice(0, 8);
  $('[data-services]').innerHTML = svc.length
    ? hbars(svc.map((s) => ({ label: s.nombre, value: s.cantidad })), Math.max(...svc.map((s) => s.cantidad)), (v) => `${v}`)
    : '<li class="muted">Sin servicios reservados en el periodo.</li>';

  // Ocupación por barbero.
  const occ = r.ocupacion.barberos;
  $('[data-occupancy]').innerHTML = occ.length ? `<ul class="hbars">${hbars(occ.map((b) => ({ label: b.nombre, value: b.porcentaje ?? 0 })), 100, (v) => `${v} %`)}</ul>
    <div class="table-wrap" style="margin-top:12px"><table class="data-table"><thead><tr><th>Barbero</th><th>Reservado</th><th>Jornada</th><th>Atendidas</th><th>No asistió</th></tr></thead>
    <tbody>${occ.map((b) => `<tr><td>${escapeHtml(b.nombre)}</td><td>${mins(b.minutos_reservados)}</td><td>${b.minutos_disponibles ? mins(b.minutos_disponibles) : '—'}</td><td>${b.citas.completada}</td><td>${b.citas.no_asistio}${b.tasa_inasistencia !== null ? ` (${b.tasa_inasistencia} %)` : ''}</td></tr>`).join('')}</tbody></table></div>`
    : '<p class="muted">Ningún barbero tuvo jornada en el periodo.</p>';

  // Cancelaciones e inasistencias.
  const c = r.cancelaciones;
  $('[data-cancel]').innerHTML = `<dl class="kv">
    <dt>Canceladas</dt><dd>${c.total} (${pct(c.tasa)} de ${t.reservas})</dd>
    <dt>Por el cliente</dt><dd>${c.por.cliente}</dd>
    <dt>Por la barbería</dt><dd>${c.por.personal}</dd>
    <dt>Por el sistema</dt><dd>${c.por.sistema}</dd>
    <dt>Inasistencias</dt><dd>${r.inasistencias.total} (${pct(r.inasistencias.tasa)} de las citas que ya debían atenderse)</dd>
  </dl>`;

  // Ingresos (solo con permiso financiero).
  $('[data-income-box]').hidden = !r.ingresos;
  if (r.ingresos) {
    const pm = r.ingresos.por_metodo;
    $('[data-income]').innerHTML = `<p style="margin:0 0 8px"><strong style="font-size:1.4rem">${money(r.ingresos.registrados)}</strong> <span class="muted">en ${r.ingresos.cobros} cobro${r.ingresos.cobros === 1 ? '' : 's'}</span></p>
      ${pm.length ? `<ul class="hbars">${hbars(pm.map((p) => ({ label: METODOS[p.metodo] || p.metodo, value: Number(p.monto) })), Math.max(...pm.map((p) => Number(p.monto))), money)}</ul>` : '<p class="muted">Aún no se registraron cobros en el periodo.</p>'}
      <p class="hint">Solo cuenta lo cobrado y registrado en la agenda (cobros anulados excluidos).</p>`;
  }

  // Evolución mensual.
  const m = r.evolucion_mensual;
  const conIngresos = m.length && m[0].ingresos !== undefined;
  $('[data-monthly]').innerHTML = vbars(m.map((x) => ({
    value: x.reservas,
    aria: `${mesLabel(x.mes)}: ${x.reservas} reservas`,
    tip: `<strong>${escapeHtml(mesLabel(x.mes))}</strong><br>${x.reservas} reserva${x.reservas === 1 ? '' : 's'} · ${x.completada} atendida${x.completada === 1 ? '' : 's'}<br>${x.cancelada} cancelada${x.cancelada === 1 ? '' : 's'} · ${x.no_asistio} no asistió${conIngresos ? `<br>Ingresos: ${money(x.ingresos)}` : ''}`,
  })), { axis: ends(m).map((x) => mesLabel(x.mes)) })
    + `<details style="margin-top:8px"><summary class="link-btn">Ver tabla</summary><div class="table-wrap"><table class="data-table"><thead><tr><th>Mes</th><th>Reservas</th><th>Atendidas</th><th>Canceladas</th><th>No asistió</th>${conIngresos ? '<th>Ingresos</th>' : ''}</tr></thead>
      <tbody>${m.map((x) => `<tr><td>${escapeHtml(mesLabel(x.mes))}</td><td>${x.reservas}</td><td>${x.completada}</td><td>${x.cancelada}</td><td>${x.no_asistio}</td>${conIngresos ? `<td>${money(x.ingresos)}</td>` : ''}</tr>`).join('')}</tbody></table></div></details>`;
}

// ======================================================================= CSV

function csv() {
  if (!report) return;
  const r = report;
  const rows = [];
  const cell = (v) => {
    const s = String(v ?? '');
    return /[",;\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  rows.push(['Reporte BARBERAPP', `${r.rango.desde} a ${r.rango.hasta}`, r.rango.zona_horaria]);
  rows.push([]);
  rows.push(['Fecha', 'Reservas (sin canceladas)', 'Pendientes', 'Confirmadas', 'Atendidas', 'No asistió', 'Canceladas']);
  r.por_dia.forEach((d) => rows.push([d.fecha, activas(d), d.pendiente, d.confirmada, d.completada, d.no_asistio, d.cancelada]));
  rows.push([]);
  rows.push(['Servicio', 'Veces solicitado', 'Atendidos', 'Importe previsto atendidos']);
  r.servicios.forEach((s) => rows.push([s.nombre, s.cantidad, s.atendidos, s.importe_previsto]));
  rows.push([]);
  rows.push(['Barbero', 'Minutos reservados', 'Minutos de jornada', 'Ocupación %', 'Atendidas', 'No asistió', 'Canceladas']);
  r.ocupacion.barberos.forEach((b) => rows.push([b.nombre, b.minutos_reservados, b.minutos_disponibles, b.porcentaje ?? '', b.citas.completada, b.citas.no_asistio, b.citas.cancelada]));
  rows.push([]);
  const conIngresos = r.evolucion_mensual.length && r.evolucion_mensual[0].ingresos !== undefined;
  rows.push(['Mes', 'Reservas', 'Atendidas', 'Canceladas', 'No asistió', ...(conIngresos ? ['Ingresos registrados'] : [])]);
  r.evolucion_mensual.forEach((x) => rows.push([x.mes, x.reservas, x.completada, x.cancelada, x.no_asistio, ...(conIngresos ? [x.ingresos] : [])]));
  if (r.ingresos) {
    rows.push([]);
    rows.push(['Método de pago', 'Monto registrado']);
    r.ingresos.por_metodo.forEach((p) => rows.push([METODOS[p.metodo] || p.metodo, p.monto]));
  }
  const blob = new Blob([`﻿${rows.map((row) => row.map(cell).join(',')).join('\r\n')}`], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `reporte-${r.rango.desde}-${r.rango.hasta}.csv`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
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
$('[data-sucursal]').addEventListener('change', () => { $('[data-barbero]').value = ''; load(); });
$('[data-barbero]').addEventListener('change', load);
$('[data-filters]').addEventListener('submit', (e) => e.preventDefault());
$('[data-csv]').addEventListener('click', csv);

(async () => {
  const ctx = await bootPanel('reportes');
  if (!ctx) return;
  load();
})();
