import { $, escapeHtml, notice } from '../ui.js';
import { icon } from '../icons.js';
import { bootPanel, papi, q, can, homeModule, money, fmtDate, estadoBadge, hbars } from '../panel.js';

/** Panel: resumen del día (Fase 6). Todo sale de la API; nada se estima en el navegador. */
const noticeEl = $('[data-notice]');
let ctx;

const pct = (v) => (v === null || v === undefined ? '—' : `${v.toLocaleString('es-PE')} %`);
const kpi = (label, value, hint = '', href = '', accent = false) => {
  const tag = href ? 'a' : 'div';
  return `<${tag} class="kpi${accent ? ' kpi--accent' : ''}"${href ? ` href="${href}"` : ''}><span class="kpi__label">${escapeHtml(label)}</span>
    <span class="kpi__value">${escapeHtml(String(value))}</span>${hint ? `<span class="kpi__hint">${escapeHtml(hint)}</span>` : ''}</${tag}>`;
};

function link(page, extra = {}) {
  const p = new URLSearchParams(q.slice(1));
  Object.entries(extra).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') p.set(k, v); });
  return `${page}.html?${p}`;
}

async function load() {
  const sucursal = $('[data-sucursal]').value;
  try {
    const d = await papi(`/panel/dashboard${sucursal ? `?sucursal_id=${sucursal}` : ''}`);
    const sel = $('[data-sucursal]');
    if (sel.options.length === 1) {
      sel.insertAdjacentHTML('beforeend', d.sucursales.filter((s) => s.activo).map((s) => `<option value="${s.id}">${escapeHtml(s.nombre)}</option>`).join(''));
      if (d.sucursales.filter((s) => s.activo).length < 2) sel.closest('.field').hidden = true;
    }
    $('[data-date-label]').textContent = fmtDate(d.fecha, { weekday: 'long', day: 'numeric', month: 'long' });
    $('[data-link-agenda]').href = link('agenda', { sucursal_id: sucursal });

    const c = d.citas;
    const out = [
      kpi('Citas del día', c.total, `${c.en_atencion} en atención ahora`, link('agenda', { sucursal_id: sucursal }), true),
      kpi('Pendientes', c.pendiente, 'por confirmar hoy'),
      kpi('Confirmadas', c.confirmada),
      kpi('Clientes atendidos', c.clientes_atendidos, `${c.completada} cita${c.completada === 1 ? '' : 's'} atendida${c.completada === 1 ? '' : 's'}`),
      kpi('Cancelaciones', c.cancelada),
      kpi('Inasistencias', c.no_asistio),
      kpi('Ocupación', pct(d.ocupacion.porcentaje), d.ocupacion.minutos_disponibles ? `${d.ocupacion.minutos_reservados} de ${d.ocupacion.minutos_disponibles} min` : 'Sin jornada hoy'),
    ];
    if (d.ingresos) {
      out.push(kpi('Ingresos registrados hoy', money(d.ingresos.dia), `${d.ingresos.cobros_dia} cobro${d.ingresos.cobros_dia === 1 ? '' : 's'} · mes: ${money(d.ingresos.mes)}`, can(ctx, 'reportes.operativos') ? link('reportes') : '', true));
    }
    $('[data-kpis]').innerHTML = out.join('');

    const calls = [];
    if (d.pendientes_confirmar) calls.push(`<a class="callout" href="${link('agenda')}">${icon('bell')}${d.pendientes_confirmar} cita${d.pendientes_confirmar === 1 ? '' : 's'} pendiente${d.pendientes_confirmar === 1 ? '' : 's'} de confirmar</a>`);
    if (d.solicitudes_bloqueo && can(ctx, 'bloqueos.gestionar')) calls.push(`<a class="callout" href="${link('ausencias')}">${icon('calendar')}${d.solicitudes_bloqueo} solicitud${d.solicitudes_bloqueo === 1 ? '' : 'es'} de ausencia por aprobar</a>`);
    $('[data-callouts]').innerHTML = calls.join('');

    $('[data-next]').innerHTML = d.proximas.length ? d.proximas.map((r) => `
      <li><a class="row row--link" href="${link('agenda', { fecha: r.fecha, cita: r.id, sucursal_id: sucursal })}" style="text-decoration:none;color:inherit">
        <span class="thumb">${escapeHtml(r.hora_inicio)}</span>
        <div class="row__main"><p class="row__title">${escapeHtml(r.cliente.nombre)}</p>
          <p class="row__meta"><span>${escapeHtml(r.servicios.map((s) => s.nombre).join(' + '))}</span><span>${escapeHtml(r.barbero.nombre)}</span></p></div>
        <div class="row__actions">${estadoBadge(r)}</div></a></li>`).join('')
      : `<li class="empty empty--inline"><p>${d.fecha === d.hoy ? 'No quedan citas para hoy.' : 'Elige el día de hoy para ver las próximas citas.'}</p></li>`;

    $('[data-occupancy]').innerHTML = d.ocupacion.barberos.length
      ? hbars(d.ocupacion.barberos.map((b) => ({ label: b.nombre, value: b.porcentaje ?? 0 })), 100, (v) => `${v} %`)
      : '<li class="muted">Ningún barbero tiene jornada hoy.</li>';

    const max = Math.max(1, ...d.semana.map((s) => s.citas));
    $('[data-week]').innerHTML = `<ul class="hbars">${hbars(d.semana.map((s) => ({ label: fmtDate(s.fecha), value: s.citas })), max)}</ul>`;
  } catch (e) {
    notice(noticeEl, e.message, 'error');
  } finally {
    $('[data-loading]').hidden = true;
    $('[data-page]').removeAttribute('aria-busy');
  }
}

(async () => {
  ctx = await bootPanel('inicio');
  if (!ctx) return;
  if (!can(ctx, ['reservas.ver_todas', 'reportes.operativos'])) {
    // El barbero tiene su propio panel móvil (Fase 7).
    if (ctx.barbero) { location.replace(`../barbero/index.html${q}`); return; }
    const home = homeModule(ctx);
    if (home && home !== 'inicio') { location.replace(`${home}.html${q}`); return; }
    notice(noticeEl, 'Tu rol no tiene acceso al resumen de la barbería.', 'error');
    $('[data-loading]').hidden = true;
    return;
  }
  $('[data-sucursal]').addEventListener('change', load);
  load();
})();
