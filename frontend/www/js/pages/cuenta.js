import { api } from '../api.js';
import { currentUser, logout } from '../auth.js';
import { $, withLoading, notice, escapeHtml } from '../ui.js';
import { mountTabbar } from '../marketplace.js';

mountTabbar('cuenta');

const noticeEl = $('[data-notice]');
const ROLES = { propietario: 'Propietario', administrador: 'Administrador', barbero: 'Barbero' };
const ESTADOS = { pendiente: 'Pendiente de aprobación', suspendido: 'Suspendida', cancelado: 'Cancelada' };
const fmt = (iso) => new Date(iso.replace(' ', 'T') + (iso.endsWith('Z') ? '' : 'Z')).toLocaleString('es-PE', { dateStyle: 'medium', timeStyle: 'short' });

function deviceLabel(s) {
  if (s.nombre_dispositivo) return s.nombre_dispositivo;
  const ua = s.user_agent || '';
  const browser = /Edg\//.test(ua) ? 'Edge' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : 'Navegador';
  const os = /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Windows/.test(ua) ? 'Windows' : /Mac OS/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : '';
  return s.canal === 'movil' ? `App móvil${os ? ' · ' + os : ''}` : `${browser}${os ? ' · ' + os : ''}`;
}

async function renderProfile(me) {
  const u = me.usuario;
  $('[data-profile]').innerHTML = `
    <p class="item__title">${escapeHtml(u.nombres)} ${escapeHtml(u.apellidos || '')}</p>
    <p class="item__meta">${escapeHtml(u.email)}${u.telefono ? ' · ' + escapeHtml(u.telefono) : ''}</p>
    <div class="chips">
      ${me.es_cliente ? '<span class="chip">Cliente</span>' : ''}
      ${me.plataforma ? '<span class="chip">Superadministrador</span>' : ''}
      ${u.email_verificado ? '<span class="chip">Correo verificado</span>' : '<span class="chip chip--warn">Correo sin verificar</span>'}
    </div>`;
  $('[data-verify]').hidden = u.email_verificado;
}

async function renderMemberships(me) {
  const list = $('[data-memberships]');
  const items = await api('/me/memberships');
  $('[data-memberships-empty]').hidden = items.length > 0;
  const current = me.contexto ? me.contexto.tenant.id : null;
  list.innerHTML = items.map((m) => {
    const t = m.tenant;
    const roles = m.roles.map((r) => `<span class="chip">${ROLES[r] || escapeHtml(r)}</span>`).join('');
    const estado = ESTADOS[t.estado] ? `<span class="chip chip--warn">${ESTADOS[t.estado]}</span>` : '';
    const invites = m.invitaciones.map((i) => `
      <div class="item__actions">
        <span class="muted">Te invitaron como ${ROLES[i.rol] || escapeHtml(i.rol)}</span>
        <button class="btn btn--sm" type="button" data-accept="${i.membresia_id}">Aceptar</button>
        <button class="btn btn--ghost btn--sm" type="button" data-reject="${i.membresia_id}">Rechazar</button>
      </div>`).join('');
    const select = m.roles.length && t.id !== current
      ? `<button class="btn btn--ghost btn--sm" type="button" data-context="${escapeHtml(t.slug)}">Trabajar aquí</button>`
      : (t.id === current ? '<span class="chip">Barbería activa</span>' : '');
    // El propietario gestiona el perfil público (permiso negocio.perfil.gestionar).
    const perfil = m.roles.includes('propietario')
      ? `<a class="btn btn--ghost btn--sm" href="../panel/perfil-publico.html?tenant=${encodeURIComponent(t.slug)}">Perfil público y QR</a>`
      : '';
    return `<li class="item" data-current="${t.id === current}">
      <div><p class="item__title">${escapeHtml(t.nombre_comercial)}</p>
        <p class="item__meta">/b/${escapeHtml(t.slug)}</p><div class="chips">${roles}${estado}</div></div>
      <div class="item__actions">${select}${perfil}</div>${invites}</li>`;
  }).join('');
}

async function renderSessions() {
  const sessions = await api('/me/sessions');
  $('[data-sessions]').innerHTML = sessions.map((s) => `
    <li class="item" data-current="${s.actual}">
      <div><p class="item__title">${escapeHtml(deviceLabel(s))}</p>
        <p class="item__meta">${s.actual ? 'Esta sesión · ' : ''}Último uso: ${fmt(s.ultimo_uso_en)}${s.ip ? ' · IP ' + escapeHtml(s.ip) : ''}</p></div>
      <div class="item__actions">${s.actual ? '' : `<button class="btn btn--ghost btn--sm" type="button" data-revoke="${s.id}">Cerrar</button>`}</div>
    </li>`).join('');
}

async function load() {
  const me = await currentUser();
  if (!me) {
    location.replace('login.html?next=cuenta.html');
    return;
  }
  await renderProfile(me);
  await Promise.all([renderMemberships(me), renderSessions()]);
}

async function run(button, fn, ok) {
  try {
    await withLoading(button, fn);
    if (ok) notice(noticeEl, ok, 'ok');
    await load();
  } catch (e) {
    notice(noticeEl, e.message, 'error');
  }
}

document.addEventListener('click', (event) => {
  const b = event.target.closest('button');
  if (!b) return;
  if (b.dataset.context) run(b, () => api('/me/context', { method: 'POST', body: { tenant: b.dataset.context } }), 'Barbería seleccionada.');
  else if (b.dataset.accept) run(b, () => api(`/me/memberships/${b.dataset.accept}/aceptar`, { method: 'POST', body: {} }), 'Invitación aceptada. ¡Bienvenido al equipo!');
  else if (b.dataset.reject) run(b, () => api(`/me/memberships/${b.dataset.reject}/rechazar`, { method: 'POST', body: {} }), 'Invitación rechazada.');
  else if (b.dataset.revoke) run(b, () => api(`/me/sessions/${b.dataset.revoke}`, { method: 'DELETE' }), 'Sesión cerrada.');
  else if (b.hasAttribute('data-resend')) run(b, () => api('/auth/verify-email/resend', { method: 'POST', body: {} }).then((d) => notice(noticeEl, d.mensaje, 'ok')));
  else if (b.hasAttribute('data-logout') || b.hasAttribute('data-logout-all')) {
    const all = b.hasAttribute('data-logout-all');
    if (all && !confirm('¿Cerrar sesión en todos tus dispositivos, incluido este?')) return;
    withLoading(b, () => logout(all)).catch(() => {}).finally(() => location.assign('login.html'));
  }
});

if (new URLSearchParams(location.search).get('bienvenida') === '1') {
  notice(noticeEl, 'Tu cuenta está lista. Te enviamos un correo para confirmar tu dirección.', 'ok');
  history.replaceState(null, '', location.pathname);
}
load().catch((e) => notice(noticeEl, e.message, 'error'));
