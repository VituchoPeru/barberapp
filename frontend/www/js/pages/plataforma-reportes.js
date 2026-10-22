import { $, escapeHtml } from '../ui.js';
import { downloadFromApi } from '../descargas.js';
import { bootPlatform, papi, fail, done, toast, excludeDemo, mountDemoToggle, demoBanner, kpi, money, hbars } from '../plataforma.js';

/** Reportes globales (Fase 9): series mensuales calculadas en el backend y exportación CSV. */
const mesesSel = $('[data-meses]');
const MES = (ym) => { const [y, m] = ym.split('-').map(Number); return new Date(y, m - 1, 1).toLocaleDateString('es-PE', { month: 'short', year: '2-digit' }); };

/** Columnas verticales de una serie, con el valor en el tooltip y en aria-label. */
function vbars(meses, key, fmt = (v) => String(v)) {
  const vals = meses.map((m) => Number(m[key]));
  const max = Math.max(1, ...vals);
  if (vals.every((v) => v === 0)) return '<p class="muted">Sin datos en el periodo.</p>';
  return `<div class="vbars" role="list">${meses.map((m, i) => `<div class="vbar" role="listitem" tabindex="0" aria-label="${escapeHtml(`${MES(m.mes)}: ${fmt(vals[i])}`)}">
      <span class="vbar__tip">${escapeHtml(MES(m.mes))}<br><strong>${escapeHtml(fmt(vals[i]))}</strong></span><span class="vbar__fill" style="height:${(vals[i] * 100) / max}%"></span></div>`).join('')}</div>
    <div class="vbars__axis" aria-hidden="true"><span>${escapeHtml(MES(meses[0].mes))}</span><span>${escapeHtml(MES(meses[meses.length - 1].mes))}</span></div>`;
}

const query = () => `meses=${mesesSel.value}${excludeDemo.get() ? '&excluir_demo=1' : ''}`;

async function load() {
  try {
    const r = await papi(`/reportes?${query()}`);
    demoBanner(r);
    const m = r.meses;
    const sum = (k) => m.reduce((n, x) => n + Number(x[k]), 0);
    const mon = (v) => money(v, r.moneda);
    $('[data-kpis]').innerHTML = [
      kpi('Ingresos del periodo', mon(sum('ingresos')), 'pagos confirmados', '', true),
      kpi('Altas de barberías', sum('altas_barberias')),
      kpi('Suscripciones nuevas', sum('suscripciones_nuevas')),
      kpi('Bajas de suscripciones', sum('bajas_suscripciones'), 'canceladas, vencidas o suspendidas'),
      kpi('Reservas creadas', sum('reservas_creadas'), `${sum('citas_atendidas')} atendidas`),
    ].join('');
    $('[data-ch-ingresos]').innerHTML = vbars(m, 'ingresos', mon);
    $('[data-ch-vigentes]').innerHTML = vbars(m, 'barberias_con_suscripcion_vigente');
    $('[data-ch-altas]').innerHTML = vbars(m, 'altas_barberias');
    $('[data-ch-reservas]').innerHTML = vbars(m, 'reservas_creadas');
    $('[data-table]').innerHTML = `<table class="data-table"><thead><tr><th>Mes</th><th>Altas</th><th>Subs. nuevas</th><th>Bajas</th><th>Vigentes</th><th>Ingresos</th><th>Reservas</th><th>Atendidas</th><th>Canceladas</th><th>No asistió</th></tr></thead>
      <tbody>${m.slice().reverse().map((x) => `<tr><td>${escapeHtml(MES(x.mes))}</td><td>${x.altas_barberias}</td><td>${x.suscripciones_nuevas}</td><td>${x.bajas_suscripciones}</td><td>${x.barberias_con_suscripcion_vigente}</td>
        <td>${mon(x.ingresos)}</td><td>${x.reservas_creadas}</td><td>${x.citas_atendidas}</td><td>${x.citas_canceladas}</td><td>${x.citas_no_asistio}</td></tr>`).join('')}</tbody></table>`;
    const top = r.top_barberias;
    $('[data-top]').innerHTML = top.length
      ? hbars(top.map((t) => ({ label: `${t.nombre}${t.es_demo ? ' (demo)' : ''}`, value: t.reservas })), Math.max(1, ...top.map((t) => t.reservas)), (v) => `${v} reservas`)
      : '<li class="muted">Sin reservas en el periodo.</li>';
  } catch (e) { fail(e.message); return; }
  done();
}

/** Descarga el CSV con la sesión actual (la exportación queda en la auditoría). En la app, "Compartir". */
async function csv(btn) {
  btn.disabled = true;
  try {
    await downloadFromApi(`/plataforma/reportes?${query()}&formato=csv`, { accept: 'text/csv', fallbackName: 'barberapp-reporte-global.csv', title: 'Reporte global BARBERAPP' });
  } catch (e) { toast(e.message, 'error'); } finally { btn.disabled = false; }
}

(async () => {
  if (!await bootPlatform('reportes')) return;
  mountDemoToggle(load);
  mesesSel.addEventListener('change', load);
  $('[data-csv]').addEventListener('click', (e) => csv(e.currentTarget));
  load();
})();
