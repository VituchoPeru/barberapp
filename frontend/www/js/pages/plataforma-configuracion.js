import { $, escapeHtml, clearErrors, showErrors, withLoading } from '../ui.js';
import { bootPlatform, papi, fail, done, toast, plural } from '../plataforma.js';

/** Configuración global (Fase 9): parámetros de la plataforma y reglas de avisos por defecto. */
const form = $('[data-form]');
let view;

const LABELS = {
  'plataforma.nombre': 'Nombre de la plataforma', 'plataforma.soporte_email': 'Correo de soporte', 'plataforma.zona_horaria': 'Zona horaria',
  'plataforma.moneda': 'Moneda por defecto', 'plataforma.aprobacion_manual_tenants': 'Aprobar manualmente las nuevas barberías',
  'plataforma.prueba_automatica': 'Prueba gratuita automática', 'plataforma.plan_prueba': 'Plan de la prueba gratuita',
  'plataforma.dias_gracia': 'Días de gracia tras vencer', 'plataforma.restringir_sin_suscripcion': 'Restringir barberías sin suscripción',
  'plataforma.avisos_vencimiento': 'Avisos de vencimiento (días antes)',
  'plataforma.legal_razon_social': 'Razón social', 'plataforma.legal_ruc': 'RUC', 'plataforma.legal_domicilio': 'Domicilio legal',
  'plataforma.privacidad_email': 'Correo para temas de datos personales', 'plataforma.legal_registro_bdp': 'Inscripción del banco de datos (código)',
  recordatorios_horas: 'Recordatorios (horas antes de la cita)', pedir_confirmacion: 'Pedir confirmación de asistencia',
  aviso_confirmacion: 'Aviso de reserva registrada o confirmada', aviso_cancelacion: 'Aviso de cancelación por la barbería',
  aviso_modificacion: 'Aviso de cambio de fecha, hora o barbero', canal_push: 'Canal push', canal_email: 'Canal correo',
};

const BILLING = ['plataforma.prueba_automatica', 'plataforma.plan_prueba', 'plataforma.dias_gracia', 'plataforma.restringir_sin_suscripcion', 'plataforma.avisos_vencimiento'];

const LEGAL = ['plataforma.legal_razon_social', 'plataforma.legal_ruc', 'plataforma.legal_domicilio', 'plataforma.privacidad_email', 'plataforma.legal_registro_bdp'];

const err = (name) => `<p class="field__error" data-error-for="${escapeHtml(name)}"></p>`;
const sw = (name, label, desc, checked) => `<div><label class="switch"><span>${escapeHtml(label)}<br><small class="muted">${escapeHtml(desc)}</small></span><input type="checkbox" role="switch" name="${escapeHtml(name)}"${checked ? ' checked' : ''}></label>${err(name)}</div>`;

function zones(current) {
  const list = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [current];
  if (!list.includes(current)) list.unshift(current);
  return list.map((z) => `<option${z === current ? ' selected' : ''}>${escapeHtml(z)}</option>`).join('');
}

function paramHtml(p) {
  const id = `c-${p.clave.replace(/\W/g, '-')}`;
  const label = LABELS[p.clave] || p.clave;
  if (p.tipo === 'bool') return sw(p.clave, label, p.descripcion, p.valor);
  let control;
  if (p.tipo === 'moneda') control = `<select id="${id}" name="${p.clave}">${view.monedas.map((m) => `<option${m === p.valor ? ' selected' : ''}>${m}</option>`).join('')}</select>`;
  else if (p.tipo === 'plan') control = `<select id="${id}" name="${p.clave}">${view.planes.map((pl) => `<option value="${escapeHtml(pl.codigo)}"${pl.codigo === p.valor ? ' selected' : ''}>${escapeHtml(pl.nombre)} (${plural(pl.dias_prueba, 'día', 'días')} de prueba)</option>`).join('')}</select>`;
  else if (p.tipo === 'entero') control = `<input id="${id}" name="${p.clave}" type="number" inputmode="numeric" min="0" max="30" step="1" value="${escapeHtml(String(p.valor))}">`;
  else if (p.tipo === 'dias') control = `<input id="${id}" name="${p.clave}" inputmode="numeric" value="${escapeHtml(p.valor.join(', '))}" placeholder="7, 3, 1">`;
  else if (p.tipo === 'ruc') control = `<input id="${id}" name="${p.clave}" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" value="${escapeHtml(p.valor)}">`;
  else if (p.tipo === 'texto_largo' || p.tipo === 'texto_opcional') control = `<input id="${id}" name="${p.clave}" maxlength="${p.tipo === 'texto_largo' ? 200 : 60}" value="${escapeHtml(p.valor)}">`;
  else if (p.tipo === 'zona') control = `<select id="${id}" name="${p.clave}">${zones(p.valor)}</select>`;
  else control = `<input id="${id}" name="${p.clave}" ${p.tipo === 'email' ? 'type="email" placeholder="soporte@tu-dominio.com"' : 'maxlength="80"'} value="${escapeHtml(p.valor)}">`;
  return `<div class="field"><label for="${id}">${escapeHtml(label)}</label>${control}<p class="hint">${escapeHtml(p.descripcion)}</p>${err(p.clave)}</div>`;
}

function render() {
  const cobro = (p) => BILLING.includes(p.clave);
  const legal = (p) => LEGAL.includes(p.clave);
  $('[data-params]').innerHTML = view.parametros.filter((p) => !cobro(p) && !legal(p)).map(paramHtml).join('');
  $('[data-legal]').innerHTML = view.parametros.filter(legal).map(paramHtml).join('');
  $('[data-billing]').innerHTML = view.parametros.filter(cobro).map(paramHtml).join('');
  const g = view.pasarela;
  $('[data-gateway]').textContent = g.habilitada
    ? `Pago en línea: ${g.nombre === 'mercadopago' ? 'Mercado Pago' : 'simulador local (sin dinero real)'}. Se configura en el servidor (PAYMENT_DRIVER en .env).`
    : 'Pago en línea desactivado (PAYMENT_DRIVER=none): las barberías pagan por transferencia y registras el pago en Pagos.';
  const l = view.avisos_limites;
  $('[data-av-hint]').textContent = `Se aplican a toda barbería que no definió sus propias reglas${view.barberias_con_reglas_propias ? ` (${plural(view.barberias_con_reglas_propias, 'barbería tiene', 'barberías tienen')} reglas propias)` : ''}. Hasta ${l.max_recordatorios} recordatorios, entre ${l.horas_min} y ${l.horas_max} horas antes.`;
  $('[data-avisos]').innerHTML = view.avisos.map((a) => {
    const name = `avisos.${a.clave}`;
    if (Array.isArray(a.valor)) {
      return `<div class="field"><label for="c-horas">${escapeHtml(LABELS[a.clave] || a.clave)}</label><input id="c-horas" name="${name}" inputmode="numeric" value="${escapeHtml(a.valor.join(', '))}" placeholder="24, 2">
        <p class="hint">Separa con comas. Vacío = sin recordatorios.</p>${err(name)}</div>`;
    }
    return sw(name, LABELS[a.clave] || a.clave, a.descripcion, a.valor);
  }).join('');
  form.hidden = false;
}

async function load() {
  try {
    view = await papi('/configuracion');
    render();
  } catch (e) { fail(e.message); return; }
  done();
}

form.addEventListener('submit', (e) => {
  e.preventDefault();
  clearErrors(form);
  const parametros = {};
  view.parametros.forEach((p) => {
    const el = form.elements[p.clave];
    if (p.tipo === 'bool') parametros[p.clave] = el.checked;
    else if (p.tipo === 'entero') parametros[p.clave] = el.value.trim() === '' ? '' : Number(el.value);
    else if (p.tipo === 'dias') parametros[p.clave] = el.value.split(',').map((x) => x.trim()).filter(Boolean).map(Number);
    else parametros[p.clave] = el.value.trim();
  });
  const avisos = {};
  view.avisos.forEach((a) => {
    const el = form.elements[`avisos.${a.clave}`];
    avisos[a.clave] = Array.isArray(a.valor)
      ? el.value.split(',').map((s) => s.trim()).filter(Boolean).map(Number)
      : el.checked;
  });
  withLoading(form.querySelector('[type="submit"]'), async () => {
    try {
      const { data, meta } = await papi('/configuracion', { method: 'PUT', body: { parametros, avisos }, withMeta: true });
      view = data;
      render();
      toast(meta.cambios.length ? `Guardado: ${plural(meta.cambios.length, 'cambio', 'cambios')}.` : 'No había cambios que guardar.');
    } catch (er) { showErrors(form, er); }
  });
});

(async () => {
  if (!await bootPlatform('configuracion')) return;
  load();
})();
