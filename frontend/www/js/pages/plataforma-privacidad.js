import { $, escapeHtml } from '../ui.js';
import { icon } from '../icons.js';
import { bootPlatform, papi, fail, done, fmtDateTime, fmtDay, formQuery, kpi, badge, formSheet, field, options, toast } from '../plataforma.js';

/** Bandeja de solicitudes de derechos ARCO (Fase 13). */
const form = $('[data-filters]');
let items = [];
const ESTADOS = { recibida: ['Recibida', 'warn'], en_proceso: ['En proceso', 'gold'], atendida: ['Atendida', 'ok'], rechazada: ['Rechazada', 'off'] };

const row = (r) => {
  const abierta = r.estado === 'recibida' || r.estado === 'en_proceso';
  return `<li class="row${abierta ? ' row--link' : ''}"${abierta ? ` tabindex="0" role="button" data-id="${r.id}" aria-label="Responder ${escapeHtml(r.codigo)}"` : ''}>
    <span class="thumb">${icon(abierta ? 'clock' : 'check')}</span>
    <div class="row__main"><p class="row__title">${escapeHtml(r.tipo_nombre)} · ${escapeHtml(r.codigo)}</p>
      <p class="row__meta"><span>${r.persona ? escapeHtml(`${r.persona.nombre} · ${r.persona.email}`) : 'Cuenta eliminada'}</span>
        <span>${r.origen === 'autoservicio' ? 'Desde la app' : 'Formulario'} · ${fmtDateTime(r.creada_en)}</span>
        ${abierta ? `<span>Vence ${fmtDay(r.plazo_vence_en)}</span>` : ''}</p>
      ${r.detalle ? `<p class="row__meta">${escapeHtml(r.detalle)}</p>` : ''}
      ${r.respuesta ? `<p class="row__meta"><strong>Respuesta:</strong> ${escapeHtml(r.respuesta)}</p>` : ''}</div>
    <div class="row__actions">${r.vencida ? '<span class="badge badge--off">Plazo vencido</span>' : ''}${badge(ESTADOS, r.estado)}</div></li>`;
};

async function load() {
  try {
    const data = await papi(`/solicitudes-datos?${formQuery(form)}`);
    items = data.items;
    const s = data.resumen;
    $('[data-kpis]').innerHTML = kpi('Abiertas', s.abiertas || 0, '', '', true) + kpi('Plazo vencido', s.vencidas || 0) + kpi('Atendidas', s.atendidas || 0) + kpi('Rechazadas', s.rechazadas || 0);
    const legal = data.legal;
    $('[data-legal-warning]').innerHTML = legal && !legal.configuracion_completa
      ? `<p class="alert" data-type="error">La política de privacidad no muestra los datos del responsable (${legal.datos_faltantes.map(escapeHtml).join(', ')}). Complétalos en <a href="configuracion.html">Configuración → Datos legales</a>.</p>`
      : '';
    $('[data-list]').innerHTML = items.length ? items.map(row).join('')
      : `<li class="empty">${icon('check')}<h3>Sin solicitudes</h3><p>No hay solicitudes con ese filtro.</p></li>`;
  } catch (e) { fail(e.message); return; }
  done();
}

function respond(id) {
  const r = items.find((x) => String(x.id) === String(id));
  if (!r) return;
  formSheet({
    title: `Responder ${r.codigo}`,
    ok: 'Guardar respuesta',
    html: `<p class="hint">${escapeHtml(r.tipo_nombre)} · vence el ${fmtDay(r.plazo_vence_en)}. La respuesta llega a la persona por correo y en Mi cuenta.</p>
      ${r.detalle ? `<p>${escapeHtml(r.detalle)}</p>` : ''}
      ${field('estado', 'Estado', `<select>${options([['en_proceso', 'En proceso (sin avisar todavía)'], ['atendida', 'Atendida'], ['rechazada', 'Rechazada (explica el motivo)']], r.estado === 'recibida' ? 'atendida' : r.estado)}</select>`)}
      ${field('respuesta', 'Respuesta para la persona', `<textarea rows="5" maxlength="2000">${escapeHtml(r.respuesta || '')}</textarea>`, 'Obligatoria al atender o rechazar.')}`,
    onSubmit: async (f) => {
      await papi(`/solicitudes-datos/${r.id}/responder`, { method: 'POST', body: { estado: f.estado.value, respuesta: f.respuesta.value.trim() } });
      toast('Solicitud actualizada.');
      await load();
    },
  });
}

(async () => {
  if (!await bootPlatform('privacidad')) return;
  form.addEventListener('change', load);
  form.addEventListener('submit', (e) => { e.preventDefault(); load(); });
  $('[data-list]').addEventListener('click', (e) => { const li = e.target.closest('[data-id]'); if (li) respond(li.dataset.id); });
  $('[data-list]').addEventListener('keydown', (e) => { const li = e.target.closest('[data-id]'); if (li && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); respond(li.dataset.id); } });
  await load();
})();
