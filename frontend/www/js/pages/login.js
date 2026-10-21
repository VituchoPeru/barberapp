import { login, currentUser, nextUrl } from '../auth.js';
import { $, clearErrors, showErrors, withLoading, bindPasswordToggles, notice } from '../ui.js';

const form = $('#login-form');
bindPasswordToggles(form);

// Si ya hay sesión, no tiene sentido volver a pedir credenciales.
currentUser().then((me) => { if (me) location.replace(nextUrl()); }).catch(() => {});

if (new URLSearchParams(location.search).get('restablecida') === '1') {
  notice($('[data-form-alert]', form), 'Tu contraseña se actualizó. Inicia sesión con la nueva.', 'ok');
}

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  clearErrors(form);
  const email = form.email.value.trim();
  const password = form.password.value;
  if (!email || !password) {
    showErrors(form, { status: 422, fields: { ...(email ? {} : { email: ['Ingresa tu correo.'] }), ...(password ? {} : { password: ['Ingresa tu contraseña.'] }) } });
    return;
  }
  try {
    await withLoading(form.querySelector('[type=submit]'), () => login(email, password));
    location.assign(nextUrl());
  } catch (e) {
    form.password.value = '';
    showErrors(form, e);
  }
});
