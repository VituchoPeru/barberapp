import { api } from '../api.js';
import { escapeHtml } from '../ui.js';
import { icon } from '../icons.js';
import { money, minutes, safeUrl, avatarHtml, emptyHtml, mountTabbar, originFor, page, me, loginUrl, toast } from '../marketplace.js';
import { daysHtml, slotsHtml, longDate, addDays, idempotencyKey, forgetIdempotencyKey, durationText, priceText } from '../booking.js';

/**
 * Flujo de reserva (Fases 3 y 5): sucursal → servicios → barbero → fecha → hora → resumen → confirmar.
 * Solo ofrece combinaciones reales (los servicios elegidos los atiende consecutivamente un mismo
 * barbero) y horarios calculados por el motor de disponibilidad del backend, que vuelve a validar
 * todo al confirmar. Sin sesión, la selección se guarda y se retoma tras iniciar sesión.
 */
document.querySelectorAll('[data-icon]').forEach((el) => { el.innerHTML = icon(el.dataset.icon); });
mountTabbar('explorar');

const $ = (s) => document.querySelector(s);
const params = new URLSearchParams(location.search);
const slug = (params.get('slug') || '').toLowerCase();
const root = $('[data-steps]');
const KEY = 'barberapp.reserva';
const DAYS_PAGE = 14;

let b = null;
const sel = { sucursal: null, servicios: [], barbero: null, fecha: null, hora: null, notas: '' };
let preferred = null; // barbero elegido desde "Reservar con …"; filtra los servicios que ofrece
let cal = { key: null, loading: false, error: null, data: null, dias: [] }; // fechas
let day = { key: null, loading: false, error: null, data: null };           // horarios del día
let notice = null; // aviso tras un intento fallido (horario tomado, correo sin verificar…)
let sending = false;

const atBranch = (x, sucId) => (x.sucursal_ids || [x.sucursal_id]).includes(sucId);
const online = () => b.barberos.filter((x) => x.reservas_online && x.servicios.length);
const barbersAt = (sucId) => online().filter((x) => atBranch(x, sucId));
const does = (barber, ids) => ids.every((id) => barber.servicios.some((s) => s.id === id));
const eligible = () => barbersAt(sel.sucursal).filter((x) => does(x, sel.servicios));
const comboKey = () => `${sel.sucursal}|${sel.servicios.join(',')}|${sel.barbero}`;

function totals(barber) {
  const items = sel.servicios.map((id) => barber.servicios.find((s) => s.id === id));
  return { min: items.reduce((a, s) => a + s.duracion_minutos, 0), price: items.reduce((a, s) => a + Number(s.precio), 0) };
}

function query(extra) {
  const q = new URLSearchParams({ slug, sucursal_id: sel.sucursal, servicio_ids: sel.servicios.join(','), ...extra });
  if (sel.barbero !== 'cualquiera') q.set('barbero_id', sel.barbero);
  return `/disponibilidad?${q}`;
}

function save() {
  try {
    sessionStorage.setItem(KEY, JSON.stringify({
      slug, sucursal_id: sel.sucursal, servicio_ids: sel.servicios, barbero_id: sel.barbero === 'cualquiera' ? null : sel.barbero,
      cualquier_barbero: sel.barbero === 'cualquiera', fecha: sel.fecha, hora: sel.hora, notas: sel.notas,
      origen: originFor(slug), guardado_en: new Date().toISOString(),
    }));
  } catch { /* sin almacenamiento */ }
}

function restore() {
  try {
    const s = JSON.parse(sessionStorage.getItem(KEY) || 'null');
    if (!s || s.slug !== slug || Date.now() - Date.parse(s.guardado_en) > 6 * 3600 * 1000) return false;
    sel.sucursal = s.sucursal_id;
    sel.servicios = s.servicio_ids || [];
    sel.barbero = s.cualquier_barbero ? 'cualquiera' : s.barbero_id;
    sel.fecha = s.fecha || null;
    sel.hora = s.hora || null;
    sel.notas = s.notas || '';
    return true;
  } catch { return false; }
}

// ------------------------------------------------------------------ datos de disponibilidad

async function loadDays(more = false) {
  const key = comboKey();
  if (!more && cal.key === key) return;
  if (!more) cal = { key, loading: true, error: null, data: null, dias: [] };
  else cal.loading = true;
  render();
  const desde = more ? addDays(cal.dias[cal.dias.length - 1].fecha, 1) : null;
  try {
    const res = await api(query(desde ? { desde, hasta: addDays(desde, DAYS_PAGE - 1) } : {}));
    if (cal.key !== key) return; // la selección cambió mientras cargaba
    cal.data = res;
    cal.dias = [...cal.dias, ...res.dias.filter((d) => d.reservable)];
    if (!sel.fecha || !cal.dias.some((d) => d.fecha === sel.fecha)) {
      const first = cal.dias.find((d) => d.disponibles);
      if (!more) sel.fecha = first ? first.fecha : null;
    }
  } catch (e) {
    if (cal.key === key) cal.error = e.message;
  }
  cal.loading = false;
  render();
  if (sel.fecha) loadSlots();
}

async function loadSlots(force = false) {
  const key = `${comboKey()}|${sel.fecha}`;
  if (!force && day.key === key) return;
  day = { key, loading: true, error: null, data: null };
  render();
  try {
    const res = await api(query({ fecha: sel.fecha }));
    if (day.key !== key) return;
    day.data = res;
    if (sel.hora && !res.horarios.some((h) => h.hora === sel.hora)) sel.hora = null;
  } catch (e) {
    if (day.key === key) day.error = e.message;
  }
  day.loading = false;
  render();
}

// ------------------------------------------------------------------ pantalla

function render() {
  const sucs = b.sucursales.filter((s) => barbersAt(s.id).length);
  if (!sucs.length) {
    root.innerHTML = emptyHtml('calendar', 'Reservas en línea no disponibles', 'Esta barbería aún no tiene barberos habilitados para reservas en línea. Puedes contactarla directamente.', `<div class="contact-list">${contactButtons()}</div>`);
    return;
  }
  if (!sel.sucursal || !sucs.some((s) => s.id === sel.sucursal)) sel.sucursal = sucs.length === 1 ? sucs[0].id : null;

  const here = sel.sucursal ? barbersAt(sel.sucursal) : [];
  const pref = preferred ? here.find((x) => x.id === preferred) : null;
  const offered = b.servicios.filter((s) => (pref ? does(pref, [s.id]) : here.some((x) => does(x, [s.id]))));
  sel.servicios = sel.servicios.filter((id) => offered.some((s) => s.id === id));
  // Si la combinación dejó de ser compatible, se conserva solo el primer servicio.
  if (sel.servicios.length && !here.some((x) => does(x, sel.servicios))) sel.servicios = sel.servicios.slice(0, 1);
  const elig = sel.servicios.length ? eligible() : [];
  if (sel.barbero !== 'cualquiera' && sel.barbero && !elig.some((x) => x.id === sel.barbero)) sel.barbero = null;
  if (sel.barbero === 'cualquiera' && (!b.politica?.permite_cualquier_barbero || elig.length < 2)) sel.barbero = elig.length === 1 ? elig[0].id : null;
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
          const compatible = checked || here.some((x) => does(x, [...sel.servicios, s.id]));
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

  let rest = '';
  if (sel.servicios.length && sel.barbero) {
    if (cal.key !== comboKey()) queueMicrotask(() => loadDays());
    rest = stepDate() + stepTime() + stepSummary(elig);
  }
  root.innerHTML = step1 + step2 + step3 + rest;
  const notes = root.querySelector('[name="notas"]');
  if (notes) notes.value = sel.notas;
}

function stepDate() {
  let body;
  if (cal.error) body = `<p class="alert" role="alert">${escapeHtml(cal.error)}</p><button class="btn btn--ghost btn--sm" type="button" data-retry-days>Reintentar</button>`;
  else if (!cal.data) body = '<div class="sk" style="height:84px" aria-hidden="true"></div>';
  else {
    const last = cal.dias.length ? cal.dias[cal.dias.length - 1].fecha : null;
    const more = last && last < cal.data.reservable_hasta;
    const any = cal.dias.some((d) => d.disponibles);
    body = `${daysHtml(cal.dias, sel.fecha)}
      ${!any && !more ? '<p class="hint">No hay horarios libres en los próximos días. Prueba con otro barbero o contacta a la barbería.</p>' : ''}
      ${more ? `<button class="btn btn--ghost btn--sm" type="button" data-more-days ${cal.loading ? 'disabled' : ''}>${cal.loading ? 'Cargando…' : 'Ver más fechas'}</button>` : ''}
      <p class="hint">Reservas hasta el ${escapeHtml(longDate(cal.data.reservable_hasta))}. Horario de ${escapeHtml(cal.data.zona_horaria.replace('_', ' '))}.</p>`;
  }
  return `<section class="step" aria-labelledby="s4"><h2 id="s4"><b>4</b>Fecha</h2>${body}</section>`;
}

function stepTime() {
  if (!sel.fecha) return '';
  let body;
  if (day.error) body = `<p class="alert" role="alert">${escapeHtml(day.error)}</p><button class="btn btn--ghost btn--sm" type="button" data-retry-slots>Reintentar</button>`;
  else if (!day.data) body = '<div class="sk" style="height:120px" aria-hidden="true"></div>';
  else body = slotsHtml(day.data.horarios, sel.hora);
  return `<section class="step" aria-labelledby="s5"><h2 id="s5"><b>5</b>Hora <small class="step__sub">${escapeHtml(longDate(sel.fecha))}</small></h2>${body}</section>`;
}

function stepSummary(elig) {
  if (!sel.fecha || !sel.hora) {
    return notice ? `<p class="alert" role="alert">${notice}</p>` : '';
  }
  const pool = sel.barbero === 'cualquiera' ? elig : elig.filter((x) => x.id === sel.barbero);
  const ts = pool.map(totals);
  const dur = { min: Math.min(...ts.map((t) => t.min)), max: Math.max(...ts.map((t) => t.min)) };
  const price = { min: Math.min(...ts.map((t) => t.price)), max: Math.max(...ts.map((t) => t.price)) };
  const suc = b.sucursales.find((s) => s.id === sel.sucursal);
  const names = sel.servicios.map((id) => b.servicios.find((s) => s.id === id).nombre);
  const barberName = sel.barbero === 'cualquiera' ? 'Cualquiera disponible' : pool[0].nombre;
  save();
  return `
    <section class="step summary" aria-labelledby="s6"><h2 id="s6"><b>6</b>Revisa y confirma</h2>
      <dl>
        <dt>Barbería</dt><dd>${escapeHtml(b.nombre)}</dd>
        <dt>Sucursal</dt><dd>${escapeHtml(suc.nombre)}</dd>
        <dt>Servicios</dt><dd>${names.map(escapeHtml).join('<br>')}</dd>
        <dt>Barbero</dt><dd>${escapeHtml(barberName)}</dd>
        <dt>Fecha</dt><dd>${escapeHtml(longDate(sel.fecha))}</dd>
        <dt>Hora</dt><dd>${escapeHtml(sel.hora)}</dd>
        <dt>Duración</dt><dd>${durationText(dur)}</dd>
        <dt class="total">Total</dt><dd class="total">${priceText(price)}</dd>
      </dl>
      <div class="field"><label for="notas">Nota para la barbería <small>(opcional)</small></label>
        <textarea id="notas" name="notas" maxlength="500" rows="2" placeholder="Ej.: degradado bajo, llego en bicicleta…"></textarea></div>
      <p class="hint">El pago se realiza en la barbería. ${b.politica?.texto ? escapeHtml(b.politica.texto) : ''}</p>
      ${notice ? `<p class="alert" role="alert">${notice}</p>` : ''}
      <button class="btn btn--block" type="button" data-confirm ${sending ? 'disabled aria-busy="true"' : ''}>${sending ? 'Reservando…' : 'Confirmar reserva'}</button>
    </section>`;
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

// ------------------------------------------------------------------ confirmar

async function confirmBooking() {
  if (sending) return;
  notice = null;
  // Sin conexión no se confirma nada: la reserva solo existe cuando el servidor la valida (Fase 12).
  if (navigator.onLine === false) {
    notice = 'Estás sin conexión. Conéctate a internet para confirmar tu reserva; tu selección se mantiene.';
    render();
    return;
  }
  const user = await me().catch(() => null);
  if (!user) {
    save();
    location.assign(loginUrl());
    return;
  }
  if (!user.usuario?.email_verificado) {
    notice = `Confirma tu correo electrónico para reservar. <a href="${page('auth/cuenta.html')}">Reenviar el correo de verificación</a>.`;
    render();
    return;
  }
  const payload = {
    slug, sucursal_id: sel.sucursal, servicio_ids: sel.servicios, barbero_id: sel.barbero === 'cualquiera' ? null : sel.barbero,
    fecha: sel.fecha, hora: sel.hora, notas: sel.notas.trim() || null, canal: originFor(slug),
  };
  const key = idempotencyKey('reserva', payload);
  sending = true;
  render();
  try {
    const r = await api('/reservas', { method: 'POST', body: payload, headers: { 'Idempotency-Key': key }, timeout: 25000 });
    forgetIdempotencyKey('reserva');
    try { sessionStorage.removeItem(KEY); } catch { /* sin almacenamiento */ }
    location.assign(page(`cita.html?id=${r.id}&nueva=1`));
    return;
  } catch (e) {
    sending = false;
    if (e.code === 'network_error') {
      // Se conserva la clave: si la reserva sí llegó al servidor, el reintento devuelve la misma.
      notice = 'No pudimos confirmar por un problema de conexión. Toca "Confirmar reserva" otra vez: no se duplicará.';
    } else {
      forgetIdempotencyKey('reserva');
      if (e.code === 'slot_unavailable') {
        const alts = (e.details && e.details.alternativas) || [];
        notice = `${escapeHtml(e.message)}${alts.length ? ` Horarios cercanos: <strong>${alts.map(escapeHtml).join(', ')}</strong>.` : ''}`;
        sel.hora = null;
        cal.key = null;
        loadDays();
        loadSlots(true);
      } else if (e.code === 'email_not_verified') {
        notice = `${escapeHtml(e.message)} <a href="${page('auth/cuenta.html')}">Ir a mi cuenta</a>.`;
      } else if (e.status === 401) {
        save();
        location.assign(loginUrl());
        return;
      } else {
        notice = escapeHtml(e.message);
      }
    }
    render();
    toast('No se pudo completar la reserva', 'error');
  }
}

// ------------------------------------------------------------------ eventos

root.addEventListener('click', (e) => {
  if (e.target.closest('[data-all-services]')) { preferred = null; render(); return; }
  const d = e.target.closest('.day');
  if (d) {
    if (d.getAttribute('aria-disabled') === 'true') return;
    sel.fecha = d.dataset.fecha; sel.hora = null; notice = null;
    render(); loadSlots();
    root.querySelector(`.day[data-fecha="${sel.fecha}"]`)?.focus();
    return;
  }
  const s = e.target.closest('.slot');
  if (s) {
    sel.hora = s.dataset.hora; notice = null;
    render();
    root.querySelector('[data-confirm]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    root.querySelector(`.slot[data-hora="${sel.hora}"]`)?.focus({ preventScroll: true });
    return;
  }
  if (e.target.closest('[data-more-days]')) { loadDays(true); return; }
  if (e.target.closest('[data-retry-days]')) { cal.key = null; loadDays(); return; }
  if (e.target.closest('[data-retry-slots]')) { loadSlots(true); return; }
  if (e.target.closest('[data-confirm]')) confirmBooking();
});

root.addEventListener('input', (e) => {
  if (e.target.name === 'notas') { sel.notas = e.target.value; save(); }
});

root.addEventListener('change', (e) => {
  const t = e.target;
  if (!['sucursal', 'servicio', 'barbero'].includes(t.name)) return;
  if (t.name === 'sucursal') { sel.sucursal = Number(t.value); sel.barbero = null; }
  if (t.name === 'servicio') {
    const id = Number(t.value);
    sel.servicios = t.checked ? [...sel.servicios, id] : sel.servicios.filter((x) => x !== id);
    sel.barbero = null;
  }
  if (t.name === 'barbero') { sel.barbero = t.value === 'cualquiera' ? 'cualquiera' : Number(t.value); preferred = null; }
  sel.fecha = null; sel.hora = null; notice = null;
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
  const pre = Number(params.get('servicio'));
  const preBarber = Number(params.get('barbero'));
  if (!pre && !preBarber && restore()) {
    render();
    return;
  }
  // Preselección desde el perfil (servicio o barbero).
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
