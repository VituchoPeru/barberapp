import { api, clearSession } from '../api.js';
import { $, clearErrors, showErrors, withLoading, bindPasswordToggles } from '../ui.js';

const form = $('#restablecer-form');
const params = new URLSearchParams(location.search);
const token = params.get('token') || '';
bindPasswordToggles(form);

if (params.get('invitacion') === '1') {
  $('[data-title]').textContent = 'Crea tu contraseña para unirte al equipo';
}
// El token no debe quedar en el historial ni enviarse como Referer.
history.replaceState(null, '', location.pathname + (params.get('invitacion') ? '?invitacion=1' : ''));

if (!token) {
  showErrors(form, { message: 'El enlace está incompleto. Solicita uno nuevo desde "¿Olvidaste tu contraseña?".' });
  form.querySelector('[type=submit]').disabled = true;
}

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  clearErrors(form);
  if (form.password.value !== form.password2.value) {
    showErrors(form, { status: 422, fields: { password2: ['Las contraseñas no coinciden.'] } });
    return;
  }
  try {
    await withLoading(form.querySelector('[type=submit]'), () => api('/auth/reset-password', { method: 'POST', body: { token, password: form.password.value } }));
    await clearSession();
    location.assign('login.html?restablecida=1');
  } catch (e) {
    showErrors(form, e);
  }
});
