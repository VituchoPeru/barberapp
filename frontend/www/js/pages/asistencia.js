import { api } from '../api.js';
import { $, escapeHtml, notice, withLoading } from '../ui.js';
import { icon } from '../icons.js';

/**
 * Confirmar asistencia desde el enlace del recordatorio (Fase 8), sin iniciar sesión.
 * El token firmado va en el fragmento (#t=…): el navegador no lo envía al servidor web ni
 * queda en sus registros. Se quita de la barra de direcciones apenas se lee.
 * No confirmar nunca cancela la cita.
 */
const token = new URLSearchParams(location.hash.slice(1)).get('t') || '';
if (token) history.replaceState(null, '', location.pathname);
const content = $('[data-content]');
const noticeEl = $('[data-notice]');

function render(c, justConfirmed = false) {
  const estado = c.asistencia_confirmada
    ? `<p class="alert" data-type="ok" role="status">${icon('check')} ${justConfirmed ? '¡Gracias! La barbería ya sabe que asistirás.' : 'Ya confirmaste tu asistencia a esta cita.'}</p>`
    : (c.puede_confirmar ? '' : '<p class="alert" data-type="info">Esta cita ya no está activa o ya pasó.</p>');
  content.innerHTML = `
    <div class="asistencia__cita">
      <p class="asistencia__cuando">${escapeHtml(c.cuando.charAt(0).toUpperCase() + c.cuando.slice(1))}</p>
      <p class="item__title">${escapeHtml(c.barberia)}</p>
      <ul class="asistencia__datos">
        <li>${icon('scissors')}<span>${escapeHtml(c.servicios)} con ${escapeHtml(c.barbero)}</span></li>
        <li>${icon('pin')}<span>${escapeHtml(c.sucursal)}${c.direccion ? ` · ${escapeHtml(c.direccion)}` : ''}</span></li>
        <li>${icon('calendar')}<span>${escapeHtml(c.fecha)}, ${escapeHtml(c.hora)} · Código ${escapeHtml(c.codigo)}</span></li>
      </ul>
    </div>
    ${estado}
    ${c.puede_confirmar ? `<button class="btn btn--block" type="button" data-confirm data-loading="Confirmando…">${icon('check')}Sí, asistiré</button>
      <p class="hint">¿No puedes ir? Cancela o reprograma desde <a href="cita.html?id=${c.reserva_id}">tu cita en la app</a> para liberar el horario.</p>` : ''}
    <a class="btn btn--ghost btn--block" href="cita.html?id=${c.reserva_id}">Ver mi cita</a>`;
  const btn = content.querySelector('[data-confirm]');
  if (btn) {
    btn.addEventListener('click', async () => {
      try {
        render(await withLoading(btn, () => api('/asistencia/confirmar', { method: 'POST', body: { token } })), true);
      } catch (e) {
        notice(noticeEl, e.message, 'error');
      }
    });
  }
}

async function start() {
  if (!token) {
    content.innerHTML = '';
    notice(noticeEl, 'El enlace está incompleto. Abre tu cita desde la app para confirmar tu asistencia.', 'error');
    return;
  }
  try {
    render(await api('/asistencia/consultar', { method: 'POST', body: { token } }));
  } catch (e) {
    content.innerHTML = '<a class="btn btn--ghost btn--block" href="mis-citas.html">Ver mis citas</a>';
    notice(noticeEl, e.message, 'error');
  } finally {
    $('[data-page]').removeAttribute('aria-busy');
  }
}

start();
