import { register } from '../auth.js';
import { $, clearErrors, showErrors, withLoading, bindPasswordToggles } from '../ui.js';

const form = $('#registro-form');
const shopFields = $('[data-propietario]', form);
const tabs = document.querySelectorAll('[role=tab]');
let tipo = new URLSearchParams(location.search).get('tipo') === 'propietario' ? 'propietario' : 'cliente';
bindPasswordToggles(form);

function selectTipo(value) {
  tipo = value;
  tabs.forEach((t) => t.setAttribute('aria-selected', String(t.dataset.tipo === value)));
  form.setAttribute('aria-labelledby', `tab-${value}`);
  shopFields.hidden = value !== 'propietario';
  shopFields.disabled = value !== 'propietario';
}
tabs.forEach((t) => t.addEventListener('click', () => selectTipo(t.dataset.tipo)));
selectTipo(tipo);

const slugify = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/&/g, ' y ').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
const preview = $('[data-slug-preview]', form);
const updatePreview = () => { preview.textContent = form.slug.value.trim() || slugify(form.nombre_comercial.value) || 'tu-barberia'; };
form.nombre_comercial.addEventListener('input', updatePreview);
form.slug.addEventListener('input', updatePreview);

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  clearErrors(form);
  const payload = {
    tipo,
    nombres: form.nombres.value.trim(),
    apellidos: form.apellidos.value.trim() || null,
    email: form.email.value.trim(),
    telefono: form.telefono.value.replace(/[\s()-]/g, '') || null,
    password: form.password.value,
    acepta_terminos: form.acepta_terminos.checked,
  };
  if (tipo === 'propietario') {
    payload.barberia = {
      nombre_comercial: form.nombre_comercial.value.trim(),
      slug: form.slug.value.trim() || null,
      ruc: form.ruc.value.trim() || null,
    };
  }
  if (!payload.acepta_terminos) {
    showErrors(form, { status: 422, fields: { acepta_terminos: ['Debes aceptar los términos y la política de privacidad.'] } });
    return;
  }
  try {
    await withLoading(form.querySelector('[type=submit]'), () => register(payload));
    location.assign('cuenta.html?bienvenida=1');
  } catch (e) {
    showErrors(form, e);
  }
});
