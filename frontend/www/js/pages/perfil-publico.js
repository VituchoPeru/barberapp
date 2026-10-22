import { api } from '../api.js';
import { config } from '../config.js';
import { $, clearErrors, showErrors, withLoading, notice, escapeHtml } from '../ui.js';
import { icon } from '../icons.js';
import { safeUrl, toast } from '../marketplace.js';
import { bootPanel } from '../panel.js';

/**
 * Panel de la barbería: perfil público en el marketplace, fotos, enlace directo y QR.
 * La barbería se indica con ?tenant=slug (o la seleccionada en la sesión) y se envía
 * en la cabecera X-Tenant; el backend verifica la membresía y el permiso.
 */
document.querySelectorAll('[data-icon]').forEach((el) => { el.innerHTML = icon(el.dataset.icon); });

const tenant = (new URLSearchParams(location.search).get('tenant') || '').toLowerCase();
const H = tenant ? { 'X-Tenant': tenant } : {};
const noticeEl = $('[data-notice]');
const form = $('[data-form]');
const REQ = {
  sucursal: 'Al menos una sucursal activa',
  horario: 'Horario de atención de la sucursal',
  servicio: 'Al menos un servicio activo',
  barbero: 'Al menos un barbero activo con servicios asignados',
};
const REQ_LINK = { sucursal: 'sucursales', horario: 'sucursales', servicio: 'servicios', barbero: 'barberos' };
let profile = null;

function render(p) {
  profile = p;
  $('[data-tenant-name]').textContent = `· ${p.nombre_comercial}`;
  document.title = `Perfil público · ${p.nombre_comercial}`;

  const vis = $('[data-visibility-text]');
  if (p.estado !== 'activo') vis.textContent = 'Tu barbería está pendiente de aprobación: aparecerá en el marketplace y su enlace funcionará cuando BARBERAPP la active.';
  else if (p.visible_en_marketplace) vis.textContent = 'Tu barbería aparece en las búsquedas del marketplace.';
  else if (p.publicado_marketplace) vis.textContent = 'Está marcada para publicarse, pero aún no aparece: completa los requisitos.';
  else vis.textContent = 'No aparece en las búsquedas. Los clientes pueden llegar por tu enlace directo o QR.';
  $('[data-reqs]').innerHTML = Object.entries(REQ).map(([k, label]) =>
    `<li data-ok="${p.requisitos[k]}">${escapeHtml(label)}${p.requisitos[k] ? '' : ` <a href="${REQ_LINK[k]}.html${location.search}">Configurar</a>`}</li>`).join('');
  $('[data-publish]').checked = p.publicado_marketplace;

  $('[data-link]').textContent = p.enlace;
  const qrBase = `${config.API_BASE_URL}/marketplace/barberias/${encodeURIComponent(p.slug)}/qr`;
  $('[data-qr]').innerHTML = p.enlace_activo
    ? `<img src="${escapeHtml(qrBase)}?formato=svg" alt="Código QR de ${escapeHtml(p.nombre_comercial)}" width="260" height="260">
       <div class="contact-list">
         <a class="btn btn--sm" href="${escapeHtml(qrBase)}?formato=png&tam=20&descargar=1" data-api-download="${escapeHtml(qrBase.slice(config.API_BASE_URL.length))}?formato=png&tam=20&descargar=1" data-filename="qr-barberia.png">${icon('download')}Descargar PNG</a>
         <a class="btn btn--sm btn--ghost" href="${escapeHtml(qrBase)}?formato=svg&descargar=1" data-api-download="${escapeHtml(qrBase.slice(config.API_BASE_URL.length))}?formato=svg&descargar=1" data-filename="qr-barberia.svg">${icon('download')}SVG para imprenta</a>
         <a class="btn btn--sm btn--ghost" href="${escapeHtml(p.enlace)}" target="_blank" rel="noopener">${icon('external')}Ver mi perfil</a>
       </div>`
    : '<p class="muted">El QR estará disponible cuando tu barbería esté activa.</p>';

  $('[data-max-mb]').textContent = (p.max_upload_bytes / 1048576).toFixed(0);
  const logo = p.fotos.find((f) => f.tipo === 'logo');
  const portada = p.fotos.find((f) => f.tipo === 'portada');
  $('[data-logo]').innerHTML = logo && safeUrl(logo.url) ? `<img src="${escapeHtml(safeUrl(logo.url))}" alt="Logo actual">` : 'Sin logo';
  $('[data-portada]').innerHTML = portada && safeUrl(portada.url) ? `<img src="${escapeHtml(safeUrl(portada.url))}" alt="Portada actual">` : 'Sin portada';
  const gal = p.fotos.filter((f) => f.tipo === 'galeria');
  $('[data-gallery-count]').textContent = `${gal.length} de ${p.max_galeria}`;
  $('[data-gallery]').innerHTML = gal.map((f) => `<div class="media-item"><img src="${escapeHtml(safeUrl(f.url) || '')}" alt="${escapeHtml(f.alt || 'Foto de la galería')}" loading="lazy">
    <button class="icon-btn" type="button" data-delete="${f.id}" aria-label="Eliminar foto">${icon('trash')}</button></div>`).join('');
  $('[data-gallery-upload]').hidden = gal.length >= p.max_galeria;

  ['descripcion', 'telefono', 'whatsapp', 'instagram', 'tiktok', 'facebook', 'sitio_web'].forEach((k) => { form[k].value = p[k] || ''; });
}

async function load() {
  try {
    render(await api('/panel/perfil-publico', { headers: H }));
    document.querySelectorAll('[data-section]').forEach((s) => { s.hidden = false; });
  } catch (e) {
    if (e.status === 401) { location.replace(`../auth/login.html?next=${encodeURIComponent('../panel/perfil-publico.html' + location.search)}`); return; }
    notice(noticeEl, e.status === 403 ? 'Solo el propietario de la barbería puede editar el perfil público.' : e.message, 'error');
  } finally {
    $('[data-loading]').hidden = true;
    $('[data-page]').removeAttribute('aria-busy');
  }
}

form.addEventListener('submit', (e) => {
  e.preventDefault();
  clearErrors(form);
  const body = Object.fromEntries(['descripcion', 'telefono', 'whatsapp', 'instagram', 'tiktok', 'facebook', 'sitio_web'].map((k) => [k, form[k].value.trim() || null]));
  withLoading(form.querySelector('[type="submit"]'), async () => {
    try {
      render(await api('/panel/perfil-publico', { method: 'PUT', body, headers: H }));
      toast('Cambios guardados');
    } catch (err) {
      showErrors(form, err);
    }
  });
});

$('[data-publish]').addEventListener('change', async (e) => {
  const sw = e.target;
  sw.disabled = true;
  try {
    render(await api('/panel/perfil-publico', { method: 'PUT', body: { publicado_marketplace: sw.checked }, headers: H }));
    toast(sw.checked ? 'Barbería publicada en el marketplace' : 'Barbería retirada del marketplace');
  } catch (err) {
    sw.checked = !sw.checked;
    const faltan = err.details && err.details.faltantes ? ' Falta: ' + err.details.faltantes.map((k) => REQ[k].toLowerCase()).join(', ') + '.' : '';
    notice(noticeEl, err.message + faltan, 'error');
  } finally {
    sw.disabled = false;
  }
});

document.addEventListener('change', async (e) => {
  const input = e.target.closest('[data-upload]');
  if (!input || !input.files.length) return;
  const file = input.files[0];
  const tipo = input.dataset.upload;
  input.value = '';
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { toast('Elige una imagen JPG, PNG o WebP.', 'error'); return; }
  if (profile && file.size > profile.max_upload_bytes) { toast(`La imagen supera ${(profile.max_upload_bytes / 1048576).toFixed(0)} MB.`, 'error'); return; }
  const label = input.parentElement;
  label.setAttribute('aria-busy', 'true');
  label.style.opacity = '.6';
  try {
    const alt = tipo === 'galeria' ? $('[data-alt]').value.trim() : '';
    const qs = new URLSearchParams({ tipo, ...(alt ? { alt } : {}) });
    await api(`/panel/fotos?${qs}`, { method: 'POST', raw: file, headers: H, timeout: 60000 });
    $('[data-alt]').value = '';
    toast('Imagen subida');
    await load();
  } catch (err) {
    const fields = err.fields || {};
    toast((fields.archivo && fields.archivo[0]) || err.message, 'error');
  } finally {
    label.removeAttribute('aria-busy');
    label.style.opacity = '';
  }
});

document.addEventListener('click', async (e) => {
  const del = e.target.closest('[data-delete]');
  if (del) {
    if (!confirm('¿Eliminar esta foto? Dejará de verse en tu perfil público.')) return;
    del.disabled = true;
    try {
      await api(`/panel/fotos/${del.dataset.delete}`, { method: 'DELETE', headers: H });
      toast('Foto eliminada');
      await load();
    } catch (err) { toast(err.message, 'error'); del.disabled = false; }
  }
  if (e.target.closest('[data-copy]')) {
    try { await navigator.clipboard.writeText(profile.enlace); toast('Enlace copiado'); } catch { toast('Copia el enlace manualmente.', 'error'); }
  }
});

bootPanel('perfil-publico');
load();
