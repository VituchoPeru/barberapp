import { api } from '../api.js';
import { $, clearErrors, showErrors, withLoading, notice } from '../ui.js';

const form = $('#olvide-form');
form.addEventListener('submit', async (event) => {
  event.preventDefault();
  clearErrors(form);
  const email = form.email.value.trim();
  if (!email) {
    showErrors(form, { status: 422, fields: { email: ['Ingresa tu correo.'] } });
    return;
  }
  try {
    const data = await withLoading(form.querySelector('[type=submit]'), () => api('/auth/forgot-password', { method: 'POST', body: { email } }));
    notice($('[data-form-alert]', form), data.mensaje, 'ok');
    form.reset();
  } catch (e) {
    showErrors(form, e);
  }
});
