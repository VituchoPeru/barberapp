import { $, escapeHtml } from '../ui.js';
import { icon } from '../icons.js';
import {
  bootPlatform, papi, can, fail, done, excludeDemo, mountDemoToggle, demoBanner, kpi, money, moneyList, fmtDateTime, plural,
} from '../plataforma.js';

/** Dashboard SaaS (Fase 9). Todas las cifras vienen de la API; aquí no se estima nada. */
let ctx;

const pct = (v) => (v === null || v === undefined ? '—' : `${v.toLocaleString('es-PE')} %`);
const delta = (now, before) => {
  if (!before) return now ? 'sin datos del periodo anterior' : '';
  const d = Math.round(((now - before) * 100) / before);
  return `${d >= 0 ? '+' : ''}${d} % vs. 30 días anteriores`;
};

async function load() {
  try {
    const d = await papi(`/dashboard${excludeDemo.get() ? '?excluir_demo=1' : ''}`);
    demoBanner(d);
    $('[data-generated]').textContent = `Calculado ${fmtDateTime(d.generado_en)} · zona ${d.zona_horaria}`;
    const b = d.barberias;
    const s = d.suscripciones;

    const calls = [];
    if (b.pendientes && can(ctx, 'plataforma.tenants.gestionar')) calls.push(`<a class="callout" href="barberias.html?estado=pendiente">${icon('store')}${plural(b.pendientes, 'barbería espera', 'barberías esperan')} aprobación</a>`);
    if (s.vencen_7_dias && can(ctx, 'plataforma.suscripciones.gestionar')) calls.push(`<a class="callout" href="suscripciones.html?vence_en_dias=7">${icon('calendar')}${plural(s.vencen_7_dias, 'suscripción vence', 'suscripciones vencen')} en los próximos 7 días</a>`);
    const porConfirmar = (d.ingresos_mes || []).reduce((n, r) => n + (r.pendientes || 0), 0);
    if (porConfirmar && can(ctx, 'plataforma.pagos.gestionar')) calls.push(`<a class="callout" href="pagos.html?estado=pendiente">${icon('cash')}${plural(porConfirmar, 'pago por confirmar', 'pagos por confirmar')}</a>`);
    $('[data-callouts]').innerHTML = calls.join('');

    const cobros = (d.ingresos_mes || []).reduce((n, r) => n + r.cobros, 0);
    const churn = d.churn_30_dias;
    $('[data-kpis-money]').innerHTML = [
      kpi('MRR', moneyList(d.mrr), 'ingreso mensual recurrente', '', true),
      kpi('Ingresos del mes', moneyList(d.ingresos_mes), plural(cobros, 'pago confirmado', 'pagos confirmados'), can(ctx, 'plataforma.pagos.gestionar') ? 'pagos.html' : '', true),
      kpi('Churn 30 días', pct(churn.porcentaje), churn.base ? `${churn.perdidas} de ${churn.base} suscripciones de pago` : 'sin suscripciones de pago hace 30 días'),
      kpi('Nuevas altas', b.altas_30_dias, `últimos 30 días · ${delta(b.altas_30_dias, b.altas_30_dias_anteriores)}`),
    ].join('');

    const tl = can(ctx, 'plataforma.tenants.gestionar') ? (e) => `barberias.html${e ? `?estado=${e}` : ''}` : () => '';
    $('[data-kpis-tenants]').innerHTML = [
      kpi('Registradas', b.registradas, '', tl('')),
      kpi('Activas', b.activas, '', tl('activo')),
      kpi('Pendientes', b.pendientes, 'por aprobar', tl('pendiente')),
      kpi('Suspendidas', b.suspendidas, '', tl('suspendido')),
      kpi('De baja', b.canceladas, '', tl('cancelado')),
    ].join('');

    const sl = can(ctx, 'plataforma.suscripciones.gestionar') ? (q) => `suscripciones.html${q ? `?${q}` : ''}` : () => '';
    $('[data-kpis-subs]').innerHTML = [
      kpi('Activas', s.activas, 'pagadas y vigentes', sl('estado=activa')),
      kpi('En prueba', s.en_prueba, '', sl('estado=prueba')),
      kpi('Vencidas', s.vencidas, 'sin renovar', sl('estado=vencida')),
      kpi('Suspendidas', s.suspendidas, '', sl('estado=suspendida')),
      kpi('Canceladas', s.canceladas, '', sl('estado=cancelada')),
      kpi('Vencen en 7 días', s.vencen_7_dias, '', sl('vence_en_dias=7')),
      kpi('Sin suscripción', s.sin_suscripcion, 'barberías que nunca tuvieron una'),
    ].join('');

    const r = d.reservas_30_dias;
    $('[data-kpis-usage]').innerHTML = [
      kpi('Reservas creadas', r.total, delta(r.total, r.periodo_anterior)),
      kpi('Citas atendidas', r.atendidas),
      kpi('Cancelaciones', r.canceladas),
      kpi('Inasistencias', r.no_asistio),
      kpi('Barberías con reservas', r.barberias_con_reservas, `de ${b.activas} activas`),
    ].join('');

    const lim = (v) => (v === null ? 'sin límite' : `máx. ${v} c/u`);
    $('[data-plans]').innerHTML = d.consumo_por_plan.length ? `<table class="data-table">
      <thead><tr><th>Plan</th><th>Barberías</th><th>En prueba</th><th>MRR</th><th>Sucursales</th><th>Barberos</th><th>Reservas 30 d</th><th>Exceden límites</th></tr></thead>
      <tbody>${d.consumo_por_plan.map((p) => `<tr>
        <td>${escapeHtml(p.plan.nombre)}${p.plan.activo ? '' : ' <span class="badge badge--off">Inactivo</span>'}</td>
        <td>${p.barberias}</td><td>${p.en_prueba}</td><td>${money(p.mrr, p.plan.moneda)}</td>
        <td>${p.sucursales} <span class="muted">/ ${lim(p.plan.max_sucursales)}</span></td>
        <td>${p.barberos} <span class="muted">/ ${lim(p.plan.max_barberos)}</span></td>
        <td>${p.reservas_30_dias}</td>
        <td>${p.exceden_sucursales + p.exceden_barberos ? `<span class="badge badge--warn">${p.exceden_sucursales} suc. · ${p.exceden_barberos} barb.</span>` : '0'}</td></tr>`).join('')}</tbody></table>`
      : '<p class="muted">Aún no hay suscripciones vigentes.</p>';
  } catch (e) {
    fail(e.message);
    return;
  }
  done();
  loadSystem();
}

async function loadSystem() {
  try {
    const s = await papi('/sistema');
    $('[data-system-box]').hidden = false;
    $('[data-alerts]').innerHTML = s.alertas.length
      ? s.alertas.map((a) => `<li data-nivel="${escapeHtml(a.nivel)}">${escapeHtml(a.mensaje)}</li>`).join('')
      : '<li data-nivel="info">Sin alertas: la cola de avisos y las integraciones funcionan con normalidad.</li>';
    const env = s.envios_24h.reduce((n, x) => n + (x.resultado === 'enviada' ? x.total : 0), 0);
    const fall = s.envios_24h.reduce((n, x) => n + (x.resultado !== 'enviada' ? x.total : 0), 0);
    $('[data-system]').innerHTML = [
      kpi('Avisos en cola', s.cola.programada, s.cola.atrasadas ? `${s.cola.atrasadas} atrasados` : 'al día'),
      kpi('Enviados 24 h', env, fall ? `${fall} fallidos` : 'sin fallos'),
      kpi('Accesos denegados 24 h', s.auditoria_24h.denegado),
      kpi('Errores en el registro', s.log.total, 'últimas entradas del log'),
    ].join('');
    $('[data-system-meta]').textContent = `Versión ${s.app.version} · ${s.app.entorno} · PHP ${s.app.php} · MySQL ${s.app.mysql} · push: ${s.integraciones.push.driver} · correo: ${s.integraciones.correo.driver} · último envío: ${fmtDateTime(s.cola.ultimo_envio)}`;
  } catch {
    // El estado del sistema es complementario: si falla, el dashboard sigue siendo útil.
  }
}

(async () => {
  ctx = await bootPlatform('index');
  if (!ctx) return;
  mountDemoToggle(load);
  $('[data-refresh]').addEventListener('click', load);
  load();
})();
