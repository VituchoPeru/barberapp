import { api } from '../api.js';
import { $, notice } from '../ui.js';

const status = $('[data-status]');
const params = new URLSearchParams(location.search);
const token = params.get('token') || '';
// cambio=1: enlace para confirmar un correo nuevo (Mi cuenta → Cambiar correo).
const cambio = params.get('cambio') === '1';
history.replaceState(null, '', location.pathname);

(async () => {
  if (!token) {
    notice(status, 'El enlace está incompleto. Puedes pedir uno nuevo desde tu cuenta.', 'error');
  } else {
    try {
      if (cambio) {
        await api('/auth/confirm-email', { method: 'POST', body: { token } });
        notice(status, 'Listo: tu cuenta ahora usa este correo. Desde ahora inicia sesión con él.', 'ok');
      } else {
        const data = await api('/auth/verify-email', { method: 'POST', body: { token } });
        notice(status, data.mensaje, 'ok');
      }
    } catch (e) {
      notice(status, e.message, 'error');
    }
  }
  $('[data-continue]').hidden = false;
})();
