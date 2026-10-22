import { $, escapeHtml } from '../ui.js';
import { bootPlatform, papi, can, fail, done, toast, money, plural, formSheet, field, options } from '../plataforma.js';

/** Planes del SaaS (Fase 9). Ver: planes o suscripciones; editar: plataforma.planes.gestionar. */
let ctx;
let plans = [];
const CANALES = [['push', 'Push en la app'], ['interna', 'Bandeja de la app'], ['email', 'Correo'], ['whatsapp', 'WhatsApp'], ['sms', 'SMS']];
const lim = (v, one, many) => (v === null ? `${many} ilimitados` : plural(v, one, many));

function card(p) {
  const c = p.caracteristicas;
  const edit = can(ctx, 'plataforma.planes.gestionar');
  return `<article class="plan-card" data-inactive="${!p.activo}">
    <div class="toolbar"><h3>${escapeHtml(p.nombre)}</h3>${p.activo ? '' : '<span class="badge badge--off">Inactivo</span>'}${p.publico ? '' : '<span class="badge">Oculto</span>'}</div>
    <p class="price">${money(p.precio_mensual, p.moneda)} <small>/ mes</small></p>
    <p class="muted" style="margin:0">${p.precio_anual ? `${money(p.precio_anual, p.moneda)} / año` : 'Sin precio anual'} · ${p.dias_prueba ? `${p.dias_prueba} días de prueba` : 'Sin prueba'}</p>
    ${p.descripcion ? `<p style="margin:0">${escapeHtml(p.descripcion)}</p>` : ''}
    <ul><li>${lim(p.max_sucursales, 'sucursal', 'sucursales')}</li><li>${lim(p.max_barberos, 'barbero', 'barberos')}</li>
      <li>Avisos: ${c.canales.map((k) => escapeHtml((CANALES.find(([v]) => v === k) || [k, k])[1])).join(', ')}</li>
      <li>${c.reportes_avanzados ? 'Con' : 'Sin'} reportes avanzados · ${c.personalizacion ? 'con' : 'sin'} personalización</li></ul>
    <p class="stat-inline"><span><strong>${p.suscripciones.activas}</strong> activas</span><span><strong>${p.suscripciones.en_prueba}</strong> en prueba</span><span><strong>${p.suscripciones.historicas}</strong> en total</span></p>
    <p class="hint">Código: ${escapeHtml(p.codigo)}</p>
    ${edit ? `<button class="btn btn--ghost btn--sm" type="button" data-edit="${p.id}">Editar</button>` : ''}</article>`;
}

async function load() {
  try {
    plans = await papi('/planes');
    $('[data-list]').innerHTML = plans.map(card).join('');
  } catch (e) { fail(e.message); return; }
  done();
}

function edit(p) {
  const c = p ? p.caracteristicas : { canales: ['push', 'interna'], reportes_avanzados: false, personalizacion: false };
  const num = (v) => (v === null || v === undefined ? '' : escapeHtml(String(v)));
  formSheet({
    title: p ? `Editar plan ${p.nombre}` : 'Nuevo plan',
    ok: p ? 'Guardar cambios' : 'Crear plan',
    html: `${p ? '' : field('codigo', 'Código', '<input maxlength="40" required autocomplete="off" placeholder="p. ej. empresarial">', 'Minúsculas, números y guiones. No se puede cambiar después.')}
      ${field('nombre', 'Nombre', `<input maxlength="80" required value="${p ? escapeHtml(p.nombre) : ''}">`)}
      ${field('descripcion', 'Descripción', `<textarea rows="2" maxlength="500">${p ? escapeHtml(p.descripcion || '') : ''}</textarea>`)}
      <div class="row3">${field('precio_mensual', 'Precio mensual', `<input type="number" min="0" step="0.01" inputmode="decimal" required value="${p ? num(p.precio_mensual) : ''}">`)}
        ${field('precio_anual', 'Precio anual', `<input type="number" min="0" step="0.01" inputmode="decimal" value="${p ? num(p.precio_anual) : ''}">`)}
        ${field('moneda', 'Moneda', `<select>${options([['PEN', 'Soles (PEN)'], ['USD', 'Dólares (USD)']], p ? p.moneda : 'PEN')}</select>`)}</div>
      <div class="row3">${field('dias_prueba', 'Días de prueba', `<input type="number" min="0" max="90" value="${p ? num(p.dias_prueba) : '14'}">`)}
        ${field('max_sucursales', 'Máx. sucursales', `<input type="number" min="1" value="${p ? num(p.max_sucursales) : ''}" placeholder="Sin límite">`)}
        ${field('max_barberos', 'Máx. barberos', `<input type="number" min="1" value="${p ? num(p.max_barberos) : ''}" placeholder="Sin límite">`)}</div>
      <fieldset class="field"><legend>Canales de avisos incluidos</legend><div class="svc-checks">${CANALES.map(([v, l]) => `<label><input type="checkbox" name="canal" value="${v}"${c.canales.includes(v) ? ' checked' : ''}${v === 'interna' ? ' disabled' : ''}><span>${l}${v === 'interna' ? ' (siempre incluida)' : ''}</span><span></span></label>`).join('')}</div>
        <p class="field__error" data-error-for="caracteristicas"></p></fieldset>
      <label class="switch"><span>Reportes avanzados</span><input type="checkbox" role="switch" name="reportes_avanzados"${c.reportes_avanzados ? ' checked' : ''}></label>
      <label class="switch"><span>Personalización de marca</span><input type="checkbox" role="switch" name="personalizacion"${c.personalizacion ? ' checked' : ''}></label>
      <label class="switch"><span>Visible para nuevas barberías</span><input type="checkbox" role="switch" name="publico"${!p || p.publico ? ' checked' : ''}></label>
      <label class="switch"><span>Activo<br><small class="muted">Un plan inactivo no admite suscripciones nuevas; las vigentes siguen.</small></span><input type="checkbox" role="switch" name="activo"${!p || p.activo ? ' checked' : ''}></label>
      ${field('orden', 'Orden', `<input type="number" min="0" max="999" value="${p ? num(p.orden) : ''}">`)}`,
    onSubmit: async (f) => {
      const n = (v) => (v === '' ? null : Number(v));
      const body = {
        nombre: f.nombre.value.trim(), descripcion: f.descripcion.value.trim() || null, precio_mensual: f.precio_mensual.value,
        precio_anual: f.precio_anual.value === '' ? null : f.precio_anual.value, moneda: f.moneda.value, dias_prueba: Number(f.dias_prueba.value || 0),
        max_sucursales: n(f.max_sucursales.value), max_barberos: n(f.max_barberos.value), publico: f.publico.checked, activo: f.activo.checked,
        caracteristicas: {
          canales: [...f.querySelectorAll('[name="canal"]:checked')].map((i) => i.value).concat('interna').filter((v, i, a) => a.indexOf(v) === i),
          reportes_avanzados: f.reportes_avanzados.checked, personalizacion: f.personalizacion.checked,
        },
      };
      if (f.orden.value !== '') body.orden = Number(f.orden.value);
      if (!p) body.codigo = f.codigo.value.trim().toLowerCase();
      const r = p ? await papi(`/planes/${p.id}`, { method: 'PUT', body }) : await papi('/planes', { method: 'POST', body });
      toast(r.aviso || (p ? 'Plan actualizado.' : 'Plan creado.'));
      load();
    },
  });
}

(async () => {
  ctx = await bootPlatform('planes');
  if (!ctx) return;
  if (can(ctx, 'plataforma.planes.gestionar')) {
    $('[data-new]').hidden = false;
    $('[data-new]').addEventListener('click', () => edit(null));
  }
  $('[data-list]').addEventListener('click', (e) => {
    const b = e.target.closest('[data-edit]');
    if (b) edit(plans.find((p) => String(p.id) === b.dataset.edit));
  });
  load();
})();
