import { api } from '../api.js';
import { $, notice } from '../ui.js';

const status = $('[data-status]');
const token = new URLSearchParams(location.search).get('token') || '';
history.replaceState(null, '', location.pathname);

(async () => {
  if (!token) {
    notice(status, 'El enlace está incompleto. Puedes pedir uno nuevo desde tu cuenta.', 'error');
  } else {
    try {
      const data = await api('/auth/verify-email', { method: 'POST', body: { token } });
      notice(status, data.mensaje, 'ok');
    } catch (e) {
      notice(status, e.message, 'error');
    }
  }
  $('[data-continue]').hidden = false;
})();
