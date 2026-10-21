import { api } from '../api.js';
import { escapeHtml } from '../ui.js';
import { icon } from '../icons.js';
import { money, minutes, safeUrl, avatarHtml, emptyHtml, mountTabbar, originFor, page } from '../marketplace.js';

/**
 * Acceso al flujo de reservas (Fase 3): sucursal → servicios → barbero → resumen.
 * Solo ofrece combinaciones reales: los servicios elegidos deben poder ser atendidos
 * consecutivamente por un mismo barbero de la sucursal (regla del MVP).
 * La elección de fecha y hora la hará el motor de disponibilidad (Fase 5); la selección
 * queda guardada en la pestaña para continuar desde ahí.
 */
document.querySelectorAll('[data-icon]').forEach((el) => { el.innerHTML = icon(el.dataset.icon); });
mountTabbar('explorar');

const $ = (s) => document.querySelector(s);
const params = new URLSearchParams(location.search);
const slug = (params.get('slug') || '').toLowerCase();
const root = $('[data-steps]');
const KEY = 'barberapp.reserva';

let b = null;
const sel = { sucursal: null, servicios: [], barbero: null };
let preferred = null; // barbero elegido desde "Reservar con …"; filtra los servicios que ofrece

const online = () => b.barberos.filter((x) => x.reservas_online && x.servicios.length);
const barbersAt = (sucId) => online().filter((x) => x.sucursal_id === sucId);
const does = (barber, ids) => ids.every((id) => barber.servicios.some((s) => s.id === id));
const eligible = () => barbersAt(sel.sucursal).filter((x) => does(x, sel.servicios));

function totals(barber) {
  const items = sel.servicios.map((id) => barber.servicios.find((s) => s.id === id));
  return { min: items.reduce((a, s) => a + s.duracion_minutos, 0), price: items.reduce((a, s) => a + Number(s.precio), 0) };
}

function save() {
  try {
    sessionStorage.setItem(KEY, JSON.stringify({
      slug, sucursal_id: sel.sucursal, servicio_ids: sel.servicios, barbero_id: sel.barbero === 'cualquiera' ? null : sel.barbero,
      cualquier_barbero: sel.barbero === 'cualquiera', origen: originFor(slug), guardado_en: new Date().toISOString(),
    }));
  } catch { /* sin almacenamiento */ }
}

function render() {
  const sucs = b.sucursales.filter((s) => barbersAt(s.id).length);
  if (!sucs.length) {
    root.innerHTML = emptyHtml('calendar', 'Reservas en línea no disponibles', 'Esta barbería aún no tiene barberos habilitados para reservas en línea. Puedes contactarla directamente.', contactButtons());
    return;
  }
  if (!sel.sucursal || !sucs.some((s) => s.id === sel.sucursal)) sel.sucursal = sucs.length === 1 ? sucs[0].id : null;

  const atBranch = sel.sucursal ? barbersAt(sel.sucursal) : [];
  const pref = preferred ? atBranch.find((x) => x.id === preferred) : null;
  const offered = b.servicios.filter((s) => (pref ? does(pref, [s.id]) : atBranch.some((x) => does(x, [s.id]))));
  sel.servicios = sel.servicios.filter((id) => offered.some((s) => s.id === id));
  // Si la combinación dejó de ser compatible, se conserva solo el primer servicio.
  if (sel.servicios.length && !atBranch.some((x) => does(x, sel.servicios))) sel.servicios = sel.servicios.slice(0, 1);
  const elig = sel.servicios.length ? eligible() : [];
  if (sel.barbero !== 'cualquiera' && sel.barbero && !elig.some((x) => x.id === sel.barbero)) sel.barbero = null;
  if (sel.barbero === 'cualquiera' && (!b.politica?.permite_cualquier_barbero || !elig.length)) sel.barbero = null;
  if (pref && elig.some((x) => x.id === pref.id)) sel.barbero = pref.id;
  if (!sel.barbero && elig.length === 1) sel.barbero = elig[0].id;

  const step1 = `
    <section class="step" aria-labelledby="s1"><h2 id="s1"><b>1</b>Sucursal</h2>
      <fieldset class="options"><legend class="sr-only">Elige la sucursal</legend>
        ${sucs.map((s) => `<label class="option"><input type="radio" name="sucursal" value="${s.id}" ${s.id === sel.sucursal ? 'checked' : ''}>
          <span><strong>${escapeHtml(s.nombre)}</strong><small>${escapeHtml(s.direccion)}, ${escapeHtml(s.distrito)}</small></span><span></span></label>`).join('')}
      </fieldset></section>`;

  const step2 = sel.sucursal ? `
    <section class="step" aria-labelledby="s2"><h2 id="s2"><b>2</b>Servicios</h2>
      <p class="hint">Puedes elegir varios: los atenderá el mismo barbero, uno tras otro.</p>
      ${pref ? `<p class="hint">Mostrando los servicios de ${escapeHtml(pref.nombre)}. <button class="btn btn--sm btn--ghost" type="button" data-all-services>Ver todos</button></p>` : ''}
      <fieldset class="options"><legend class="sr-only">Elige uno o más servicios</legend>
        ${offered.map((s) => {
          const checked = sel.servicios.includes(s.id);
          const compatible = checked || atBranch.some((x) => does(x, [...sel.servicios, s.id]));
          return `<label class="option"><input type="checkbox" name="servicio" value="${s.id}" ${checked ? 'checked' : ''} ${compatible ? '' : 'disabled'}>
            <span><strong>${escapeHtml(s.nombre)}</strong><small>${minutes(s.duracion_minutos)}${compatible ? '' : ' · ningún barbero lo combina con tu selección'}</small></span>
            <span class="svc__price">${money(s.precio, s.moneda)}</span></label>`;
        }).join('')}
      </fieldset></section>` : '';

  const step3 = sel.servicios.length ? `
    <section class="step" aria-labelledby="s3"><h2 id="s3"><b>3</b>Barbero</h2>
      <fieldset class="options"><legend class="sr-only">Elige tu barbero</legend>
        ${b.politica?.permite_cualquier_barbero && elig.length > 1 ? `<label class="option"><input type="radio" name="barbero" value="cualquiera" ${sel.barbero === 'cualquiera' ? 'checked' : ''}>
          <span><strong>Cualquiera disponible</strong><small>Te asignamos el barbero libre en el horario que elijas</small></span><span></span></label>` : ''}
        ${elig.map((x) => { const t = totals(x); return `<label class="option"><input type="radio" name="barbero" value="${x.id}" ${sel.barbero === x.id ? 'checked' : ''}>
          <span style="display:flex;gap:12px;align-items:center">${avatarHtml(x.nombre, x.foto_url)}<span><strong>${escapeHtml(x.nombre)}</strong><small>${minutes(t.min)}</small></span></span>
          <span class="svc__price">${money(t.price)}</span></label>`; }).join('')}
      </fieldset></section>` : '';

  let step4 = '';
  if (sel.servicios.length && sel.barbero) {
    const pool = sel.barbero === 'cualquiera' ? elig : elig.filter((x) => x.id === sel.barbero);
    const ts = pool.map(totals);
    const pMin = Math.min(...ts.map((t) => t.price));
    const pMax = Math.max(...ts.map((t) => t.price));
    const dMin = Math.min(...ts.map((t) => t.min));
    const dMax = Math.max(...ts.map((t) => t.min));
    const suc = b.sucursales.find((s) => s.id === sel.sucursal);
    const names = sel.servicios.map((id) => b.servicios.find((s) => s.id === id).nombre);
    const barberName = sel.barbero === 'cualquiera' ? 'Cualquiera disponible' : pool[0].nombre;
    save();
    step4 = `
    <section class="step summary" aria-labelledby="s4"><h2 id="s4"><b>4</b>Resumen</h2>
      <dl>
        <dt>Barbería</dt><dd>${escapeHtml(b.nombre)}</dd>
        <dt>Sucursal</dt><dd>${escapeHtml(suc.nombre)}</dd>
        <dt>Servicios</dt><dd>${names.map(escapeHtml).join('<br>')}</dd>
        <dt>Barbero</dt><dd>${escapeHtml(barberName)}</dd>
        <dt>Duración</dt><dd>${dMin === dMax ? minutes(dMin) : `${minutes(dMin)} – ${minutes(dMax)}`}</dd>
        <dt class="total">Total</dt><dd class="total">${pMin === pMax ? money(pMin) : `${money(pMin)} – ${money(pMax)}`}</dd>
      </dl>
      <p class="hint">El pago se realiza en la barbería. ${b.politica?.texto ? escapeHtml(b.politica.texto) : ''}</p>
    </section>
    <section class="step" aria-labelledby="s5"><h2 id="s5"><b>5</b>Fecha y hora</h2>
      <p class="alert" data-type="info" role="status">La elección de fecha y hora en línea llega con el motor de reservas de BARBERAPP. Tu selección quedó guardada; mientras tanto puedes coordinar tu cita directamente con la barbería.</p>
      <div class="contact-list" style="margin-top:12px">${contactButtons(names, barberName, suc)}</div>
    </section>`;
  }

  root.innerHTML = step1 + step2 + step3 + step4;
}

function contactButtons(names = [], barberName = '', suc = null) {
  const wa = safeUrl(b.contacto.whatsapp_url);
  const text = names.length
    ? `Hola ${b.nombre}, quisiera reservar: ${names.join(' + ')}${barberName && barberName !== 'Cualquiera disponible' ? ` con ${barberName}` : ''}${suc ? ` en ${suc.nombre}` : ''}. (Vía BARBERAPP)`
    : `Hola ${b.nombre}, quisiera reservar una cita. (Vía BARBERAPP)`;
  const phone = (suc && suc.telefono) || b.contacto.telefono;
  const out = [];
  if (wa) out.push(`<a class="btn" href="${escapeHtml(wa)}?text=${encodeURIComponent(text)}" target="_blank" rel="noopener noreferrer">${icon('chat')}Escribir por WhatsApp</a>`);
  if (phone) out.push(`<a class="btn btn--ghost" href="tel:${escapeHtml(phone)}">${icon('phone')}Llamar</a>`);
  out.push(`<a class="btn btn--ghost" href="${page(`barberia.html?slug=${encodeURIComponent(slug)}#ubicacion`)}">${icon('pin')}Ver ubicación</a>`);
  return out.join('');
}

root.addEventListener('click', (e) => {
  if (e.target.closest('[data-all-services]')) { preferred = null; render(); }
});

root.addEventListener('change', (e) => {
  const t = e.target;
  if (t.name === 'sucursal') { sel.sucursal = Number(t.value); sel.barbero = null; }
  if (t.name === 'servicio') {
    const id = Number(t.value);
    sel.servicios = t.checked ? [...sel.servicios, id] : sel.servicios.filter((x) => x !== id);
    sel.barbero = null;
  }
  if (t.name === 'barbero') { sel.barbero = t.value === 'cualquiera' ? 'cualquiera' : Number(t.value); preferred = null; }
  const focusName = t.name;
  const focusValue = t.value;
  render();
  const again = root.querySelector(`input[name="${focusName}"][value="${CSS.escape(focusValue)}"]`);
  if (again) again.focus();
});

async function load() {
  $('[data-back]').href = page(`barberia.html?slug=${encodeURIComponent(slug)}`);
  try {
    b = await api(`/marketplace/barberias/${encodeURIComponent(slug)}`);
  } catch (e) {
    root.innerHTML = emptyHtml('store', 'Barbería no disponible', e.message, `<a class="btn" href="${page('explorar.html')}">Explorar barberías</a>`);
    return;
  }
  document.title = `Reservar en ${b.nombre} · BARBERAPP`;
  $('[data-title]').textContent = b.nombre;
  // Preselección desde el perfil (servicio o barbero).
  const pre = Number(params.get('servicio'));
  const preBarber = Number(params.get('barbero'));
  const barber = online().find((x) => x.id === preBarber);
  if (barber) { sel.sucursal = barber.sucursal_id; preferred = barber.id; }
  if (pre) {
    const withSvc = online().filter((x) => does(x, [pre]) && (!barber || x.id === barber.id));
    if (withSvc.length) {
      if (!sel.sucursal) sel.sucursal = withSvc[0].sucursal_id;
      sel.servicios = [pre];
    }
  }
  render();
}

load();
