/** Ayudas de interfaz para formularios: errores por campo, estado de carga y avisos. */

export function $(sel, root = document) {
  return root.querySelector(sel);
}

export function clearErrors(form) {
  form.querySelectorAll('[data-error-for]').forEach((el) => { el.textContent = ''; });
  form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
  const alert = form.querySelector('[data-form-alert]');
  if (alert) { alert.hidden = true; alert.textContent = ''; }
}

/** Muestra errores de un ApiError: por campo (422) o general. */
export function showErrors(form, error) {
  const fields = (error && error.fields) || {};
  let shown = false;
  Object.entries(fields).forEach(([name, messages]) => {
    const slot = form.querySelector(`[data-error-for="${CSS.escape(name)}"]`);
    const input = form.querySelector(`[name="${CSS.escape(name)}"]`);
    if (slot) { slot.textContent = messages.join(' '); shown = true; }
    if (input) input.setAttribute('aria-invalid', 'true');
  });
  const alert = form.querySelector('[data-form-alert]');
  if (alert && (!shown || error.status !== 422)) {
    alert.textContent = error.message;
    alert.hidden = false;
  }
  const first = form.querySelector('[aria-invalid="true"]');
  if (first) first.focus();
}

export async function withLoading(button, fn) {
  const label = button.textContent;
  button.disabled = true;
  button.setAttribute('aria-busy', 'true');
  button.textContent = button.dataset.loading || 'Procesando…';
  try {
    return await fn();
  } finally {
    button.disabled = false;
    button.removeAttribute('aria-busy');
    button.textContent = label;
  }
}

export function notice(el, message, type = 'ok') {
  el.textContent = message;
  el.dataset.type = type;
  el.hidden = false;
}

/** Botón mostrar/ocultar contraseña. */
export function bindPasswordToggles(root = document) {
  root.querySelectorAll('[data-toggle-password]').forEach((btn) => {
    const input = root.querySelector(`#${btn.getAttribute('aria-controls')}`);
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', String(show));
      btn.textContent = show ? 'Ocultar' : 'Mostrar';
    });
  });
}

export function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
