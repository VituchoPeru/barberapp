import { api } from '../api.js';
import { currentUser, logout } from '../auth.js';
import { $, withLoading, notice, escapeHtml } from '../ui.js';
import { mountTabbar } from '../marketplace.js';
import { isNative } from '../native.js';
import { isInstalled } from '../shell.js';

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
      ${me.plataforma ? `<span class="chip">${me.plataforma.rol === 'operador' ? 'Equipo BARBERAPP' : 'Superadministrador'}</span>` : ''}
      ${u.email_verificado ? '<span class="chip">Correo verificado</span>' : '<span class="chip chip--warn">Correo sin verificar</span>'}
    </div>`;
  $('[data-verify]').hidden = u.email_verificado;
  // Panel del SaaS (Fase 9): solo cuentas del equipo de BARBERAPP. El backend vuelve a verificarlo.
  if (me.plataforma) {
    $('[data-profile]').insertAdjacentHTML('beforeend', '<div class="item__actions" style="margin-top:12px"><a class="btn btn--sm" href="../plataforma/index.html">Abrir panel de la plataforma</a></div>');
  }
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
    // Accesos al panel según rol (el backend vuelve a verificar cada permiso).
    const qs = `?tenant=${encodeURIComponent(t.slug)}`;
    const links = [];
    if (m.roles.length) {
      // Inicio redirige al primer módulo permitido; el menú del panel muestra el resto según permisos.
      links.push(`<a class="btn btn--sm" href="../panel/inicio.html${qs}">Abrir panel</a>`);
      // El barbero tiene su agenda móvil (Fase 7); el resto, la agenda de la barbería.
      links.push(m.roles.includes('barbero')
        ? `<a class="btn btn--ghost btn--sm" href="../barbero/index.html${qs}">Mi agenda</a>`
        : `<a class="btn btn--ghost btn--sm" href="../panel/agenda.html${qs}">Agenda</a>`);
    }
    const perfil = links.join('');
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

const CANALES = [['push', 'Push'], ['email', 'Correo']];

/** Preferencias de avisos (Fase 8): push y correo por categoría. */
async function renderPrefs() {
  const prefs = await api('/me/preferencias-notificacion');
  $('[data-prefs]').innerHTML = `<table class="prefs">
    <thead><tr><th scope="col"><span class="sr-only">Aviso</span></th>${CANALES.map(([, l]) => `<th scope="col">${l}</th>`).join('')}</tr></thead>
    <tbody>${prefs.categorias.map((c) => `<tr><th scope="row">${escapeHtml(c.nombre)}</th>${CANALES.map(([k, l]) => (k in c
      ? `<td><label class="switch"><span class="sr-only">${escapeHtml(c.nombre)} por ${l}</span><input type="checkbox" role="switch" data-pref="${c.categoria}" data-canal="${k}"${c[k] ? ' checked' : ''}></label></td>`
      : '<td><span class="muted" aria-label="No disponible">—</span></td>')).join('')}</tr>`).join('')}</tbody></table>`;
}

/** Avisos push en este teléfono (solo en la app nativa, Fase 12). */
async function renderPushHere() {
  const box = $('[data-push-here]');
  if (!isNative()) { box.hidden = true; return; }
  const { pushPermission } = await import('../push.js');
  const state = await pushPermission();
  box.hidden = state === 'unsupported';
  box.innerHTML = state === 'granted'
    ? '<p class="hint">Este teléfono tiene los avisos activados.</p>'
    : state === 'denied'
      ? '<p class="alert" data-type="info">Las notificaciones de BARBERAPP están desactivadas en este teléfono. Puedes activarlas en los ajustes del sistema, en Notificaciones.</p>'
      : '<button class="btn btn--sm" type="button" data-push-enable data-loading="Activando…">Activar avisos en este teléfono</button>';
}

const PLATAFORMAS = { android: 'Android', ios: 'iPhone / iPad', web: 'Navegador' };
async function renderDevices() {
  const devices = await api('/me/dispositivos');
  const el = $('[data-devices]');
  if (!devices.length) {
    el.innerHTML = isNative()
      ? '<li class="muted">Ningún teléfono recibe notificaciones todavía.</li>'
      : '<li class="muted">Ningún teléfono recibe notificaciones todavía. Se activan al <a href="../instalar.html">instalar la app de BARBERAPP</a> y permitir los avisos.</li>';
    return;
  }
  el.innerHTML = devices.map((d) => `<li class="item">
      <div><p class="item__title">${escapeHtml(d.modelo || PLATAFORMAS[d.plataforma] || d.plataforma)}</p>
        <p class="item__meta">${escapeHtml(PLATAFORMAS[d.plataforma] || d.plataforma)}${d.ultimo_uso_en ? ' · Último aviso: ' + fmt(d.ultimo_uso_en) : ''}${d.activo ? '' : ' · ' + escapeHtml(d.motivo_desactivado || 'Desactivado')}</p></div>
      <div class="item__actions">${d.activo ? `<button class="btn btn--ghost btn--sm" type="button" data-device-off="${d.id}">Dejar de avisar aquí</button>` : '<span class="chip chip--warn">Inactivo</span>'}</div>
    </li>`).join('');
}

document.addEventListener('change', async (event) => {
  const input = event.target.closest('[data-pref]');
  if (!input) return;
  input.disabled = true;
  try {
    await api('/me/preferencias-notificacion', { method: 'PUT', body: { [input.dataset.pref]: { [input.dataset.canal]: input.checked } } });
    notice(noticeEl, 'Preferencias de avisos guardadas.', 'ok');
  } catch (e) {
    input.checked = !input.checked;
    notice(noticeEl, e.message, 'error');
  } finally {
    input.disabled = false;
  }
});

// Enlace a la pantalla de instalación cuando se usa desde el navegador (Fase 12).
$('[data-install-link]').hidden = isInstalled();

async function load() {
  const me = await currentUser();
  if (!me) {
    location.replace('login.html?next=cuenta.html');
    return;
  }
  await renderProfile(me);
  await Promise.all([renderMemberships(me), renderPrefs(), renderDevices(), renderSessions(), renderPushHere().catch(() => {})]);
  if (location.hash === '#avisos') $('#avisos').scrollIntoView();
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
  else if (b.hasAttribute('data-push-enable')) {
    run(b, async () => {
      const { enablePush } = await import('../push.js');
      const state = await enablePush();
      if (state !== 'granted') throw new Error('No se activaron los avisos: el permiso de notificaciones no fue concedido.');
    }, 'Listo: este teléfono recibirá tus recordatorios.');
  } else if (b.dataset.deviceOff) run(b, () => api(`/me/dispositivos/${b.dataset.deviceOff}`, { method: 'DELETE' }), 'Ese teléfono ya no recibirá notificaciones.');
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
