import { escapeHtml } from './ui.js';
import { money, minutes } from './marketplace.js';
import { icon } from './icons.js';

/**
 * Piezas compartidas del motor de reservas (Fase 5): selector de fecha, horarios disponibles,
 * claves de idempotencia, estados y archivo de calendario. Las usan la reserva nueva,
 * la reprogramación y "Mis citas".
 */

const DIAS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
const DIAS_LARGOS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const MESES_LARGOS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** 'AAAA-MM-DD' → Date a mediodía (evita saltos por zona horaria del dispositivo). */
const asDate = (f) => new Date(`${f}T12:00:00`);
export const addDays = (f, n) => {
  const d = asDate(f);
  d.setDate(d.getDate() + n);
  const pad = (x) => String(x).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

export function longDate(f) {
  const d = asDate(f);
  return `${DIAS_LARGOS[d.getDay()]} ${d.getDate()} de ${MESES_LARGOS[d.getMonth()]}`;
}

/** Tira horizontal de días con cantidad de horarios libres. */
export function daysHtml(dias, selected) {
  if (!dias.length) return '<p class="hint">No hay fechas disponibles en este periodo.</p>';
  return `<div class="days" role="radiogroup" aria-label="Fecha">${dias.map((d) => {
    const date = asDate(d.fecha);
    const off = !d.disponibles;
    return `<button type="button" class="day" role="radio" data-fecha="${d.fecha}" aria-checked="${d.fecha === selected}" ${off ? 'aria-disabled="true"' : ''}
      aria-label="${escapeHtml(longDate(d.fecha))}${off ? ', sin horarios' : `, ${d.disponibles} horarios`}">
      <span class="day__dow">${DIAS[date.getDay()]}</span><span class="day__num">${date.getDate()}</span><span class="day__mon">${MESES[date.getMonth()]}</span>
      <span class="day__dot" data-off="${off}"></span></button>`;
  }).join('')}</div>`;
}

/** Horarios agrupados en mañana, tarde y noche. */
export function slotsHtml(horarios, selected) {
  if (!horarios.length) {
    return `<div class="empty empty--inline">${icon('calendar')}<p>No quedan horarios libres este día. Prueba con otra fecha.</p></div>`;
  }
  const groups = [['Mañana', (h) => h < '12:00'], ['Tarde', (h) => h >= '12:00' && h < '18:00'], ['Noche', (h) => h >= '18:00']];
  return groups.map(([label, test]) => {
    const items = horarios.filter((s) => test(s.hora));
    if (!items.length) return '';
    return `<div class="slot-group"><h3>${label}</h3><div class="slots" role="radiogroup" aria-label="${label}">
      ${items.map((s) => `<button type="button" class="slot" role="radio" data-hora="${s.hora}" aria-checked="${s.hora === selected}">${s.hora}</button>`).join('')}
    </div></div>`;
  }).join('');
}

/**
 * Clave de idempotencia por intento: se reutiliza si la misma solicitud se reintenta
 * (p. ej. se cortó la red) y se descarta cuando la operación termina.
 */
export function idempotencyKey(scope, payload) {
  const store = `barberapp.idem.${scope}`;
  const sig = JSON.stringify(payload);
  try {
    const saved = JSON.parse(sessionStorage.getItem(store) || 'null');
    if (saved && saved.sig === sig) return saved.key;
  } catch { /* sin almacenamiento */ }
  const key = (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`).replace(/[^A-Za-z0-9_-]/g, '');
  try { sessionStorage.setItem(store, JSON.stringify({ sig, key })); } catch { /* sin almacenamiento */ }
  return key;
}
export function forgetIdempotencyKey(scope) {
  try { sessionStorage.removeItem(`barberapp.idem.${scope}`); } catch { /* sin almacenamiento */ }
}

export const ESTADOS = {
  pendiente: ['Pendiente', 'Esperando confirmación de la barbería'],
  confirmada: ['Confirmada', 'Te esperamos'],
  completada: ['Atendida', 'Gracias por tu visita'],
  cancelada: ['Cancelada', 'Esta cita se canceló'],
  no_asistio: ['No asististe', 'La barbería registró inasistencia'],
};

export const statusChip = (estado) => `<span class="badge" data-estado="${escapeHtml(estado)}">${escapeHtml((ESTADOS[estado] || [estado])[0])}</span>`;

/** Tarjeta resumida de una cita (lista de "Mis citas"). */
export function bookingCardHtml(r, href) {
  return `<a class="booking" href="${href}">
    <div class="booking__date" aria-hidden="true"><span>${DIAS[asDate(r.fecha).getDay()]}</span><b>${asDate(r.fecha).getDate()}</b><span>${MESES[asDate(r.fecha).getMonth()]}</span></div>
    <div class="booking__body">
      <p class="booking__top">${statusChip(r.estado)}<span>${escapeHtml(r.hora_inicio)} – ${escapeHtml(r.hora_fin)}</span></p>
      <h3>${escapeHtml(r.barberia.nombre)}</h3>
      <p class="booking__meta">${escapeHtml(r.servicios.map((s) => s.nombre).join(' + '))}</p>
      <p class="booking__meta">${icon('user')}${escapeHtml(r.barbero.nombre)} · ${icon('pin')}${escapeHtml(r.sucursal.nombre)}</p>
    </div>
    <span class="booking__price">${money(r.precio_total, r.moneda)}</span>
  </a>`;
}

export const durationText = (d) => (d.min === d.max ? minutes(d.min) : `${minutes(d.min)} – ${minutes(d.max)}`);
export const priceText = (p) => (p.min === p.max ? money(p.min) : `${money(p.min)} – ${money(p.max)}`);

/** Archivo .ics para agregar la cita al calendario del teléfono (sin enviar datos a terceros). */
export function icsFile(r) {
  const stamp = (iso) => iso.replace(/[-:]/g, '').replace(/\.\d+/, '');
  const esc = (s) => String(s || '').replace(/[\\,;]/g, (c) => `\\${c}`).replace(/\n/g, '\\n');
  const lines = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//BARBERAPP//Reservas//ES', 'CALSCALE:GREGORIAN', 'BEGIN:VEVENT',
    `UID:${r.codigo}@barberapp`, `DTSTAMP:${stamp(new Date().toISOString())}`,
    `DTSTART:${stamp(r.inicio_utc)}`, `DTEND:${stamp(r.fin_utc)}`,
    `SUMMARY:${esc(`${r.servicios.map((s) => s.nombre).join(' + ')} · ${r.barberia.nombre}`)}`,
    `LOCATION:${esc(`${r.sucursal.nombre}, ${r.sucursal.direccion}, ${r.sucursal.distrito}`)}`,
    `DESCRIPTION:${esc(`Código ${r.codigo}. Barbero: ${r.barbero.nombre}.`)}`,
    'BEGIN:VALARM', 'TRIGGER:-PT2H', 'ACTION:DISPLAY', 'DESCRIPTION:Recordatorio de tu cita', 'END:VALARM',
    'END:VEVENT', 'END:VCALENDAR',
  ];
  return new Blob([lines.join('\r\n')], { type: 'text/calendar;charset=utf-8' });
}

/** Enlace a Google Maps con las coordenadas o la dirección de la sucursal. */
export function mapsUrl(s) {
  const q = s.coordenadas ? `${s.coordenadas.lat},${s.coordenadas.lng}` : `${s.direccion}, ${s.distrito}`;
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(q)}`;
}
