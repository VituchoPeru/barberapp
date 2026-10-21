import { $, clearErrors, showErrors, withLoading, escapeHtml, notice } from '../ui.js';
import { icon } from '../icons.js';
import { safeUrl, initials } from '../marketplace.js';
import { bootPanel, papi, openSheet, can, q } from '../panel.js';

/** Panel: listado del equipo de barberos (Fase 4). La ficha completa está en barbero.html. */
const listEl = $('[data-list]');
const sheet = $('[data-sheet]');
const form = $('[data-form]');
const noticeEl = $('[data-notice]');

const detailUrl = (id) => `barbero.html${q}${q ? '&' : '?'}id=${id}`;

function row(b) {
  const foto = safeUrl(b.foto_url);
  const estado = !b.activo ? '<span class="badge badge--off">Inactivo</span>'
    : !b.acepta_reservas_online ? '<span class="badge badge--warn">Sin reservas online</span>' : '';
  const alertas = [];
  if (b.activo && !b.servicios) alertas.push('<span class="badge badge--warn">Sin servicios</span>');
  if (b.activo && !b.horas_semana) alertas.push('<span class="badge badge--warn">Sin horario</span>');
  return `<li class="row row--link" data-inactive="${!b.activo}">
    <span class="thumb">${foto ? `<img src="${escapeHtml(foto)}" alt="">` : escapeHtml(initials(b.nombre_publico))}</span>
    <div class="row__main"><p class="row__title"><a href="${detailUrl(b.id)}" style="color:inherit;text-decoration:none">${escapeHtml(b.nombre_publico)}</a></p>
      <p class="row__meta"><span>${escapeHtml(b.sucursal)}</span>${b.especialidad ? `<span>${escapeHtml(b.especialidad)}</span>` : ''}
        <span>${b.servicios || 0} servicio(s)</span><span>${b.horas_semana || 0} h/semana</span>${estado}${alertas.join('')}</p></div>
    <div class="row__actions">${icon('back', 'icon--flip')}</div></li>`;
}

async function load() {
  const { data, meta } = await papi('/panel/barberos', { withMeta: true });
  listEl.innerHTML = data.length ? data.map(row).join('')
    : `<li class="empty">${icon('user')}<h2>Aún no registras barberos</h2><p>Agrega a tu equipo, asígnales servicios y define su horario para recibir reservas.</p></li>`;
  form.usuario_id.innerHTML = '<option value="">Sin cuenta (solo ficha)</option>' + meta.cuentas_vinculables
    .map((u) => `<option value="${u.usuario_id}">${escapeHtml(u.nombre)} · ${escapeHtml(u.email)}${u.estado === 'invitado' ? ' (invitación pendiente)' : ''}</option>`).join('');
}

listEl.addEventListener('click', (e) => {
  const r = e.target.closest('.row--link');
  if (r && !e.target.closest('a')) r.querySelector('a').click();
});

form.addEventListener('submit', (e) => {
  e.preventDefault();
  clearErrors(form);
  const body = {
    nombre_publico: form.nombre_publico.value.trim(),
    especialidad: form.especialidad.value.trim() || null,
    sucursal_id: form.sucursal_id.value ? Number(form.sucursal_id.value) : null,
    usuario_id: form.usuario_id.value ? Number(form.usuario_id.value) : null,
  };
  withLoading(form.querySelector('[type="submit"]'), async () => {
    try {
      const b = await papi('/panel/barberos', { method: 'POST', body });
      location.href = detailUrl(b.id);
    } catch (err) { showErrors(form, err); }
  });
});

bootPanel('barberos').then(async (ctx) => {
  if (!ctx) { $('[data-loading]').hidden = true; return; }
  try {
    const sucursales = await papi('/panel/sucursales');
    const activas = sucursales.filter((s) => s.activo);
    form.sucursal_id.innerHTML = activas.map((s) => `<option value="${s.id}">${escapeHtml(s.nombre)}</option>`).join('');
    const btn = $('[data-new]');
    btn.hidden = !can(ctx, 'barberos.gestionar');
    btn.addEventListener('click', () => {
      if (!activas.length) { notice(noticeEl, 'Primero registra una sucursal activa.', 'info'); return; }
      form.reset();
      clearErrors(form);
      openSheet(sheet);
    });
    await load();
  } catch (e) {
    notice(noticeEl, e.status === 403 ? 'Tu rol no permite gestionar barberos.' : e.message, 'error');
  } finally {
    $('[data-loading]').hidden = true;
    $('[data-page]').removeAttribute('aria-busy');
  }
});
