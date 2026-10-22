import { $, escapeHtml, notice, clearErrors, showErrors, withLoading } from '../ui.js';
import { icon } from '../icons.js';
import { bootPanel, papi, q, can, toast, confirmAction } from '../panel.js';

/**
 * Configuración del panel (Fase 6): nombre comercial y colores, enlace de reservas,
 * reglas de reserva / política de cancelación (general o por sucursal) y accesos a
 * sucursales, horarios, equipo y perfil público (logo, QR).
 */
const noticeEl = $('[data-notice]');
const bForm = $('[data-business-form]');
const pForm = $('[data-policy-form]');
const DEF_PRI = '#C9A45C';
const DEF_SEC = '#111111';
const NUM_FIELDS = ['intervalo_minutos', 'margen_entre_citas_minutos', 'anticipacion_minima_minutos', 'horizonte_maximo_dias',
  'cancelacion_limite_minutos', 'reprogramacion_limite_minutos', 'max_reprogramaciones', 'max_reservas_activas_cliente'];

let ctx;
let policies = null;

// ======================================================================= negocio

function preview() {
  const p = $('[data-preview]');
  p.style.setProperty('--prev-pri', bForm.color_primario.value);
  p.style.setProperty('--prev-sec', bForm.color_secundario.value);
  $('[data-preview-name]').textContent = bForm.nombre_comercial.value || 'Tu barbería';
  document.querySelectorAll('[data-hex]').forEach((c) => { c.textContent = bForm[c.dataset.hex].value.toUpperCase(); });
}

function fillBusiness(n) {
  bForm.nombre_comercial.value = n.nombre_comercial;
  bForm.color_primario.value = (n.colores.primario || DEF_PRI).toLowerCase();
  bForm.color_secundario.value = (n.colores.secundario || DEF_SEC).toLowerCase();
  $('[data-enlace]').textContent = n.enlace;
  preview();
}

async function loadBusiness() {
  const n = await papi('/panel/negocio');
  fillBusiness(n);
  $('[data-business]').hidden = false;
}

bForm.addEventListener('input', preview);
bForm.addEventListener('submit', (e) => {
  e.preventDefault();
  clearErrors(bForm);
  const body = { nombre_comercial: bForm.nombre_comercial.value.trim(), color_primario: bForm.color_primario.value, color_secundario: bForm.color_secundario.value };
  withLoading(bForm.querySelector('[type="submit"]'), async () => {
    try {
      const n = await papi('/panel/negocio', { method: 'PUT', body });
      fillBusiness(n);
      document.querySelectorAll('[data-tenant-name]').forEach((el) => { el.textContent = `· ${n.nombre_comercial}`; });
      toast('Datos del negocio guardados');
    } catch (err) { showErrors(bForm, err); }
  });
});

$('[data-copy]').addEventListener('click', async () => {
  const text = $('[data-enlace]').textContent;
  try {
    await navigator.clipboard.writeText(text);
    toast('Enlace copiado');
  } catch {
    // Sin permiso de portapapeles: se selecciona el texto para copiarlo a mano.
    const range = document.createRange();
    range.selectNodeContents($('[data-enlace]'));
    const sel = getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    toast('Selecciona y copia el enlace');
  }
});

// ======================================================================= reglas de reserva

function currentScope() {
  return $('[data-policy-scope]').value;
}

function fillPolicy() {
  const id = currentScope();
  const own = id ? policies.sucursales.find((s) => String(s.sucursal_id) === id) : null;
  const p = id ? (own || policies.general) : policies.general;
  NUM_FIELDS.forEach((f) => { pForm[f].value = p[f]; });
  pForm.confirmacion_automatica.checked = !!p.confirmacion_automatica;
  pForm.permite_cualquier_barbero.checked = !!p.permite_cualquier_barbero;
  pForm.texto_politica_cancelacion.value = p.texto_politica_cancelacion || '';
  $('[data-policy-reset]').hidden = !own;
  const hint = pForm.querySelector('[data-scope-hint]');
  if (hint) hint.remove();
  if (id && !own) {
    pForm.insertAdjacentHTML('afterbegin', '<p class="hint" data-scope-hint>Esta sucursal usa las reglas generales. Si guardas aquí, tendrá reglas propias.</p>');
  }
}

async function loadPolicies() {
  policies = await papi('/panel/politicas-reserva');
  const sel = $('[data-policy-scope]');
  let branches = [];
  try {
    branches = (await papi('/panel/sucursales')).map((s) => ({ id: s.id, nombre: s.nombre }));
  } catch {
    // Sin permiso para listar sucursales: se usan las que ya tienen reglas propias.
    branches = policies.sucursales.map((s) => ({ id: s.sucursal_id, nombre: s.sucursal }));
  }
  const prev = sel.value;
  sel.innerHTML = '<option value="">Todas las sucursales (general)</option>' + branches.map((b) => {
    const own = policies.sucursales.some((s) => s.sucursal_id === b.id);
    return `<option value="${b.id}">${escapeHtml(b.nombre)}${own ? ' · reglas propias' : ''}</option>`;
  }).join('');
  sel.value = branches.some((b) => String(b.id) === prev) ? prev : '';
  sel.closest('.field').hidden = branches.length < 2 && !policies.sucursales.length;
  fillPolicy();
  $('[data-policy]').hidden = false;
}

$('[data-policy-scope]').addEventListener('change', () => { clearErrors(pForm); fillPolicy(); });

pForm.addEventListener('submit', (e) => {
  e.preventDefault();
  clearErrors(pForm);
  const body = { sucursal_id: currentScope() ? Number(currentScope()) : null };
  NUM_FIELDS.forEach((f) => { body[f] = pForm[f].value === '' ? null : Number(pForm[f].value); });
  body.confirmacion_automatica = pForm.confirmacion_automatica.checked;
  body.permite_cualquier_barbero = pForm.permite_cualquier_barbero.checked;
  body.texto_politica_cancelacion = pForm.texto_politica_cancelacion.value;
  withLoading(pForm.querySelector('[type="submit"]'), async () => {
    try {
      await papi('/panel/politicas-reserva', { method: 'PUT', body });
      await loadPolicies();
      toast('Reglas guardadas');
    } catch (err) { showErrors(pForm, err); }
  });
});

$('[data-policy-reset]').addEventListener('click', async () => {
  const id = currentScope();
  if (!id || !(await confirmAction('Esta sucursal volverá a usar las reglas generales. ¿Continuar?', { ok: 'Usar reglas generales' }))) return;
  try {
    await papi(`/panel/politicas-reserva/${id}`, { method: 'DELETE' });
    await loadPolicies();
    toast('La sucursal usa las reglas generales');
  } catch (err) { toast(err.message, 'error'); }
});

// ======================================================================= equipo (Fase 7)

const tForm = $('[data-team-form]');
async function loadTeam() {
  const t = await papi('/panel/equipo/ajustes');
  Object.entries(t).forEach(([k, v]) => { if (tForm[k]) tForm[k].checked = v; });
  $('[data-team]').hidden = false;
}
tForm.addEventListener('change', async (e) => {
  const input = e.target;
  input.disabled = true;
  try {
    const t = await papi('/panel/equipo/ajustes', { method: 'PUT', body: { [input.name]: input.checked } });
    input.checked = t[input.name];
    toast('Ajuste del equipo guardado');
  } catch (err) {
    input.checked = !input.checked;
    toast(err.message, 'error');
  } finally { input.disabled = false; }
});

// ======================================================================= accesos

function shortcuts() {
  const items = [
    ['sucursales', 'Sucursales y horario de atención', 'store', 'sucursales.gestionar'],
    ['barberos', 'Barberos y sus horarios', 'user', ['barberos.gestionar', 'horarios.gestionar']],
    ['servicios', 'Servicios y precios', 'scissors', 'servicios.gestionar'],
    ['ausencias', 'Ausencias y feriados', 'calendar', 'bloqueos.gestionar'],
    ['perfil-publico', 'Logo, portada, enlace y QR', 'qr', 'negocio.perfil.gestionar'],
  ].filter(([, , , perm]) => can(ctx, perm));
  $('[data-shortcuts]').innerHTML = items.length
    ? items.map(([page, label, ic]) => `<a class="btn btn--ghost btn--sm" href="${page}.html${q}">${icon(ic)} ${escapeHtml(label)}</a>`).join('')
    : '<p class="muted">Tu rol no gestiona sucursales, horarios ni equipo.</p>';
}

(async () => {
  ctx = await bootPanel('configuracion');
  if (!ctx) return;
  document.querySelectorAll('[data-link]').forEach((a) => { a.href = `${a.dataset.link}.html${q}`; });
  shortcuts();
  const jobs = [];
  if (can(ctx, 'negocio.perfil.gestionar')) jobs.push(loadBusiness());
  if (can(ctx, 'politicas.gestionar')) jobs.push(loadPolicies());
  if (can(ctx, 'personal.gestionar')) jobs.push(loadTeam());
  const results = await Promise.allSettled(jobs);
  const failed = results.find((r) => r.status === 'rejected');
  if (failed) notice(noticeEl, failed.reason.message, 'error');
  if (!jobs.length) notice(noticeEl, 'Tu rol no puede cambiar la configuración del negocio.', 'error');
  $('[data-loading]').hidden = true;
  $('[data-page]').removeAttribute('aria-busy');
})();
