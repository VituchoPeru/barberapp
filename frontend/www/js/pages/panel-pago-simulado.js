import { $, escapeHtml, notice, withLoading } from '../ui.js';
import { icon } from '../icons.js';
import { papi, tenant, q, money } from '../panel.js';

/**
 * Simulador de la pasarela (Fase 10, solo con PAYMENT_DRIVER=sandbox): aprueba o rechaza una
 * orden enviando al backend el mismo webhook firmado que mandaría la pasarela. El backend
 * responde 404 si el simulador no está activo (por ejemplo, en producción).
 */
const codigo = new URLSearchParams(location.search).get('orden') || '';
const noticeEl = $('[data-notice]');
document.querySelectorAll('[data-icon]').forEach((el) => { el.innerHTML = icon(el.dataset.icon); });
$('[data-back]').href = `suscripcion.html${q}`;

const ESTADOS = { pendiente: 'Pendiente', pagada: 'Pagada', fallida: 'Rechazada', expirada: 'Expirada', anulada: 'Anulada' };

function show(o) {
  $('[data-detail]').innerHTML = `<dt>Detalle</dt><dd>${escapeHtml(o.detalle)}</dd>
    <dt>Monto</dt><dd><strong>${money(o.monto)}</strong> ${escapeHtml(o.moneda)}</dd>
    <dt>Código</dt><dd><code>${escapeHtml(o.codigo)}</code></dd>
    <dt>Estado</dt><dd>${escapeHtml(ESTADOS[o.estado] || o.estado)}</dd>`;
  $('[data-actions]').hidden = o.estado !== 'pendiente';
  $('[data-order]').hidden = false;
}

async function init() {
  if (!tenant || !/^[a-f0-9]{24}$/.test(codigo)) {
    notice(noticeEl, 'Falta la orden de pago. Vuelve a la suscripción e inicia el pago otra vez.', 'error');
    return;
  }
  try {
    const r = await papi(`/panel/suscripcion/ordenes/${codigo}`);
    show(r.orden);
    if (r.orden.estado !== 'pendiente') notice(noticeEl, 'Esta orden ya no admite pagos.', 'info');
  } catch (e) {
    if (e.status === 401) {
      location.replace(`../auth/login.html?next=${encodeURIComponent(`../panel/pago-simulado.html${location.search}`)}`);
      return;
    }
    notice(noticeEl, e.message, 'error');
  }
}

document.querySelectorAll('[data-sim]').forEach((btn) => btn.addEventListener('click', async () => {
  const aprobar = btn.dataset.sim === '1';
  try {
    await withLoading(btn, () => papi(`/panel/suscripcion/ordenes/${codigo}/simular`, { method: 'POST', body: { aprobar } }));
    location.assign(`suscripcion.html${q}&orden=${codigo}&resultado=${aprobar ? 'aprobado' : 'rechazado'}`);
  } catch (e) {
    notice(noticeEl, e.message, 'error');
  }
}));

init().finally(() => $('[data-page]').removeAttribute('aria-busy'));
