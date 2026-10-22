import { $, escapeHtml, withLoading } from './ui.js';
import { icon } from './icons.js';
import {
  papi, q, context, can, openSheet, confirmAction, toast, estadoBadge, mins, money, fmtDate,
} from './panel.js';

/**
 * Panel del barbero (Fase 7): utilidades compartidas por Hoy, Citas, Horario y Ausencias.
 *
 * Interfaz móvil simplificada sobre /api/v1/barbero/*. El backend limita todo al barbero
 * vinculado a la cuenta y vuelve a validar cada acción: ocultar un botón aquí es comodidad.
 */
export { papi, q, toast, estadoBadge, mins, money, fmtDate, confirmAction, openSheet };

const TABS = [
  ['index', 'Hoy', 'home'],
  ['citas', 'Citas', 'calendar'],
  ['horario', 'Horario', 'clock'],
  ['ausencias', 'Ausencias', 'bell'],
];

export const TIPOS = { vacaciones: 'Vacaciones', ausencia: 'Ausencia', descanso: 'Descanso', bloqueo: 'Bloqueo', feriado: 'Feriado' };

/** "HH:MM" → minutos desde la medianoche. */
export const toMin = (hhmm) => { const [h, m] = hhmm.split(':').map(Number); return h * 60 + m; };
export const toHHMM = (m) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
export const telLink = (t) => (t ? t.replace(/[^\d+]/g, '') : '');

/**
 * Prepara una página: iconos, barra inferior, nombre de la barbería y comprobación de que la
 * cuenta tiene ficha de barbero. Devuelve el contexto o null (y muestra por qué).
 */
export async function bootBarber(active) {
  document.querySelectorAll('[data-icon]').forEach((el) => { el.innerHTML = icon(el.dataset.icon); });
  const nav = document.createElement('nav');
  nav.className = 'tabbar bb-tabbar';
  nav.setAttribute('aria-label', 'Panel del barbero');
  nav.innerHTML = TABS.map(([key, label, ic]) =>
    `<a href="${key}.html${q}"${key === active ? ' aria-current="page"' : ''}>${icon(ic)}<span>${label}</span></a>`).join('');
  document.body.appendChild(nav);
  try {
    const ctx = await context();
    document.querySelectorAll('[data-tenant-name]').forEach((el) => { el.textContent = ctx.tenant.nombre_comercial; });
    if (!ctx.barbero) {
      blocked('Tu cuenta no tiene ficha de barbero', 'Pide al propietario que vincule tu cuenta a tu ficha de barbero en Barberos.', ctx);
      return null;
    }
    // Quien además gestiona la barbería conserva el acceso al panel completo.
    const full = $('[data-full-panel]');
    if (full && can(ctx, ['reservas.ver_todas', 'reportes.operativos', 'barberos.gestionar', 'bloqueos.gestionar'])) {
      full.href = `../panel/inicio.html${q}`;
      full.hidden = false;
    }
    return ctx;
  } catch (e) {
    if (e.status === 401) {
      location.replace(`../auth/login.html?next=${encodeURIComponent(`../barbero/${active}.html${location.search}`)}`);
      return null;
    }
    blocked(e.status === 404 ? 'No perteneces a esta barbería' : 'No se pudo cargar tu panel', e.status === 404 ? 'Elige una barbería desde Mi cuenta.' : e.message);
    return null;
  }
}

function blocked(title, text) {
  const main = $('[data-page]');
  main.removeAttribute('aria-busy');
  main.innerHTML = `<div class="empty bb-empty">${icon('scissors')}<h2>${escapeHtml(title)}</h2><p>${escapeHtml(text)}</p>
    <a class="btn btn--ghost btn--sm" href="../auth/cuenta.html">Ir a Mi cuenta</a></div>`;
}

/** Fecha y hora relativas para la tarjeta "Siguiente". */
export function relative(isoUtc) {
  const diff = Math.round((new Date(isoUtc).getTime() - Date.now()) / 60000);
  if (diff <= 0) return diff > -60 ? `empezó hace ${-diff} min` : 'ya empezó';
  if (diff < 60) return `en ${diff} min`;
  const h = Math.floor(diff / 60);
  return h < 24 ? `en ${h} h${diff % 60 ? ` ${diff % 60} min` : ''}` : `en ${Math.round(h / 24)} día${Math.round(h / 24) === 1 ? '' : 's'}`;
}

export function dayLabel(ymd, hoy) {
  const base = fmtDate(ymd, { weekday: 'long', day: 'numeric', month: 'long' });
  if (!hoy) return base;
  const d = (new Date(`${ymd}T12:00:00`) - new Date(`${hoy}T12:00:00`)) / 86400000;
  return d === 0 ? `Hoy, ${base}` : d === 1 ? `Mañana, ${base}` : d === -1 ? `Ayer, ${base}` : base;
}

/** Tarjeta compacta de una cita (lista del día, próximas e historial). */
export function citaCard(c, { showDate = false } = {}) {
  const svcs = c.servicios.map((s) => s.nombre).join(' + ');
  return `<button type="button" class="bb-cita" data-cita="${c.id}" data-estado="${c.en_atencion ? 'live' : c.estado}">
    <span class="bb-cita__time"><strong>${escapeHtml(c.hora_inicio)}</strong><small>${escapeHtml(c.hora_fin)}</small></span>
    <span class="bb-cita__body">
      <span class="bb-cita__name">${escapeHtml(c.cliente.nombre)}</span>
      <span class="bb-cita__svc">${escapeHtml(svcs)} · ${mins(c.duracion_total_minutos)}</span>
      <span class="bb-cita__meta">${estadoBadge(c)}${showDate ? `<span>${escapeHtml(fmtDate(c.fecha))}</span>` : ''}
        ${c.asistencia_confirmada && ['pendiente', 'confirmada'].includes(c.estado) ? '<span class="badge badge--ok">Confirmó que viene</span>' : ''}
        <span class="muted">${escapeHtml(c.sucursal.nombre)}</span></span>
    </span>
    ${icon('chevron')}
  </button>`;
}

// ================================================================== detalle de una cita

let detailDlg;
let onChanged = () => {};

const ACTION_TEXT = {
  confirmar: ['confirmar', 'Cita confirmada'],
  iniciar: ['iniciar', 'Atención iniciada'],
  completar: ['completar', 'Cliente atendido'],
  no_asistio: ['no-asistio', 'Inasistencia registrada'],
};

/** Botones de atención según las acciones permitidas por la API. */
export function actionButtons(c, { big = false } = {}) {
  const a = c.acciones;
  const cls = big ? 'btn' : 'btn btn--sm';
  const out = [];
  if (a.iniciar) out.push(`<button class="${cls}" type="button" data-act="iniciar">${icon('scissors')} Iniciar atención</button>`);
  if (a.completar) out.push(`<button class="${cls}${a.iniciar ? ' btn--ghost' : ''}" type="button" data-act="completar">${icon('check')} ${c.estado === 'no_asistio' ? 'Sí fue atendido' : 'Marcar atendido'}</button>`);
  if (a.confirmar) out.push(`<button class="${cls} btn--ghost" type="button" data-act="confirmar">Confirmar cita</button>`);
  if (a.no_asistio) out.push(`<button class="${cls} btn--danger" type="button" data-act="no_asistio">${c.estado === 'completada' ? 'Corregir: no asistió' : 'No asistió'}</button>`);
  return out.join('');
}

/** Ejecuta una acción de atención (con confirmación para la inasistencia). Devuelve la cita actualizada o null. */
export async function runAction(c, key, button) {
  if (key === 'no_asistio' && !(await confirmAction(`¿Registrar que ${c.cliente.nombre} no asistió? Se liberará el horario.`, { ok: 'Registrar inasistencia', danger: true }))) return null;
  if (key === 'completar' && c.estado === 'no_asistio' && !(await confirmAction(`¿Corregir y marcar que ${c.cliente.nombre} sí fue atendido?`, { ok: 'Sí fue atendido' }))) return null;
  return withLoading(button, async () => {
    try {
      const r = await papi(`/barbero/citas/${c.id}/${ACTION_TEXT[key][0]}`, { method: 'POST', body: {} });
      toast(ACTION_TEXT[key][1]);
      if (navigator.vibrate) navigator.vibrate(15);
      return r;
    } catch (e) {
      toast(e.message, 'error');
      return null;
    }
  });
}

function renderDetail(c) {
  const tel = telLink(c.cliente.telefono);
  const prev = c.visitas_previas || { atendidas: 0, ultimas: [] };
  $('[data-det-title]', detailDlg).textContent = c.cliente.nombre;
  const body = $('[data-det-body]', detailDlg);
  body.innerHTML = `
    <div class="detail-head">${estadoBadge(c)}
      <p class="detail-when">${escapeHtml(fmtDate(c.fecha, { weekday: 'long', day: 'numeric', month: 'long' }))} · ${escapeHtml(c.hora_inicio)}–${escapeHtml(c.hora_fin)}</p>
      <p class="muted" style="margin:0">${escapeHtml(c.sucursal.nombre)} · ${mins(c.duracion_total_minutos)} · Código ${escapeHtml(c.codigo)}</p>
    </div>
    ${tel ? `<div class="bb-contact"><a class="btn btn--ghost btn--sm" href="tel:${escapeHtml(tel)}">${icon('phone')} Llamar</a>
      <a class="btn btn--ghost btn--sm" href="https://wa.me/${escapeHtml(tel.replace('+', ''))}" target="_blank" rel="noopener">${icon('chat')} WhatsApp</a></div>` : ''}
    <div class="action-grid bb-actions">${actionButtons(c)}</div>
    <section aria-label="Servicios"><h3 class="group-title">Servicios</h3>
      <ol class="bb-svcs">${c.servicios.map((s) => `<li><span class="bb-svcs__time">${escapeHtml(s.hora_inicio)}</span>
        <span><strong>${escapeHtml(s.nombre)}</strong><small>${mins(s.duracion_minutos)} · ${money(s.precio)}</small></span></li>`).join('')}</ol></section>
    <dl class="kv">
      <dt>Cliente</dt><dd>${escapeHtml(c.cliente.nombre)} ${c.cliente.tiene_cuenta ? '<span class="badge">Con cuenta</span>' : '<span class="badge">Mostrador</span>'}</dd>
      ${c.asistencia_confirmada ? '<dt>Asistencia</dt><dd>Confirmó que asistirá</dd>' : ''}
      ${c.notas_cliente ? `<dt>Nota del cliente</dt><dd>${escapeHtml(c.notas_cliente)}</dd>` : ''}
      ${c.cancelacion ? `<dt>Cancelación</dt><dd>Por ${escapeHtml({ cliente: 'el cliente', personal: 'la barbería', sistema: 'el sistema' }[c.cancelacion.por] || '—')}${c.cancelacion.motivo ? `: ${escapeHtml(c.cancelacion.motivo)}` : ''}</dd>` : ''}
      <dt>Contigo</dt><dd>${prev.atendidas ? `${prev.atendidas} visita${prev.atendidas === 1 ? '' : 's'} atendida${prev.atendidas === 1 ? '' : 's'}` : 'Primera visita contigo'}</dd>
    </dl>
    ${prev.ultimas.length ? `<section aria-label="Visitas anteriores contigo"><h3 class="group-title">Últimas visitas contigo</h3>
      <ul class="bb-prev">${prev.ultimas.map((v) => `<li><span>${escapeHtml(fmtDate(v.fecha))}</span><span>${escapeHtml(v.servicios.join(' + ') || '—')}</span>
        <span class="badge badge--${v.estado === 'completada' ? 'ok' : 'off'}">${v.estado === 'completada' ? 'Atendido' : 'No asistió'}</span></li>`).join('')}</ul></section>` : ''}
    ${c.acciones.notas ? `<form class="form" data-notes-form><div class="field"><label for="d-notas">Notas internas (solo el equipo)</label>
      <textarea id="d-notas" name="notas_internas" maxlength="1000" rows="2" placeholder="Ej.: degradado bajo, máquina 1 a los lados">${escapeHtml(c.notas_internas || '')}</textarea></div>
      <div><button class="btn btn--ghost btn--sm" type="submit" data-loading="Guardando…">Guardar notas</button></div></form>`
      : (c.notas_internas ? `<p><strong>Notas internas:</strong> ${escapeHtml(c.notas_internas)}</p>` : '')}
    <details><summary class="link-btn">Historial de la cita</summary><ol class="history">${(c.historial || []).map((h) => `<li>${escapeHtml(h.texto)}<small>${escapeHtml(h.actor)} · ${escapeHtml(new Date(h.fecha).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'short' }))}</small></li>`).join('')}</ol></details>`;

  body.querySelectorAll('[data-act]').forEach((b) => {
    b.onclick = async () => {
      const r = await runAction(c, b.dataset.act, b);
      if (r) { renderDetail({ ...r, visitas_previas: r.visitas_previas || prev }); onChanged(); }
    };
  });
  const nf = body.querySelector('[data-notes-form]');
  if (nf) {
    nf.onsubmit = (e) => {
      e.preventDefault();
      withLoading(nf.querySelector('button'), async () => {
        try {
          renderDetail(await papi(`/barbero/citas/${c.id}/notas`, { method: 'PUT', body: { notas_internas: nf.notas_internas.value } }));
          toast('Notas guardadas');
        } catch (err) { toast(err.message, 'error'); }
      });
    };
  }
}

/** Abre la ficha de una cita en una hoja inferior. onChange se llama tras cada acción. */
export async function openCita(id, onChange = () => {}) {
  detailDlg = detailDlg || $('[data-detail]');
  onChanged = onChange;
  $('[data-det-title]', detailDlg).textContent = 'Cita';
  $('[data-det-body]', detailDlg).innerHTML = '<div class="skeleton"></div><div class="skeleton" style="height:140px"></div>';
  openSheet(detailDlg);
  try {
    renderDetail(await papi(`/barbero/citas/${id}`));
  } catch (e) {
    $('[data-det-body]', detailDlg).innerHTML = `<p class="alert" data-type="error">${escapeHtml(e.message)}</p>`;
  }
}

/** ¿Hay un diálogo abierto? (no se refresca la pantalla mientras el barbero lo usa). */
export const dialogOpen = () => !!document.querySelector('dialog[open]');
