import { $, escapeHtml, notice } from '../ui.js';
import { icon } from '../icons.js';
import { bootPlatform, papi, fail, done, openSheet, confirmAction, askText, toast, fmtDateTime, errorText, formSheet, field, options } from '../plataforma.js';

/** Usuarios de plataforma (Fase 9): equipo, búsqueda auditada, bloqueo y roles. */
let ctx;
const sheet = $('[data-detail]');
const ROLES = { superadmin: 'Superadministrador', operador: 'Operador' };
const ROLES_BARBERIA = { propietario: 'Propietario', administrador: 'Administrador', recepcionista: 'Recepción', barbero: 'Barbero' };
const ACCIONES = { 'plataforma.usuario.bloqueado': 'Bloqueada', 'plataforma.usuario.desbloqueado': 'Desbloqueada', 'plataforma.usuario.rol': 'Rol de plataforma cambiado' };

function row(u) {
  return `<li class="row row--link" tabindex="0" role="button" data-id="${u.id}" aria-label="Ver cuenta ${escapeHtml(u.email)}">
    <span class="thumb">${icon('user')}</span>
    <div class="row__main"><p class="row__title">${escapeHtml(u.nombre)}</p>
      <p class="row__meta"><span>${escapeHtml(u.email)}</span><span>Último acceso: ${fmtDateTime(u.ultimo_login_en)}</span>
        ${u.barberias && u.barberias.length ? `<span>${u.barberias.length} barbería${u.barberias.length === 1 ? '' : 's'}</span>` : ''}</p></div>
    <div class="row__actions">${u.rol_plataforma ? `<span class="badge badge--gold">${ROLES[u.rol_plataforma]}</span>` : ''}${u.estado === 'bloqueado' ? '<span class="badge badge--off">Bloqueada</span>' : ''}${u.email_verificado ? '' : '<span class="badge badge--warn">Sin verificar</span>'}</div></li>`;
}

async function loadTeam() {
  try {
    const team = await papi('/usuarios');
    $('[data-team]').innerHTML = team.map(row).join('');
  } catch (e) { fail(e.message); return; }
  done();
}

async function search(e) {
  if (e) e.preventDefault();
  const q = $('[data-search]').q.value.trim();
  const out = $('[data-results]');
  if (q.length < 3) { out.innerHTML = '<li class="muted">Escribe al menos 3 caracteres.</li>'; return; }
  try {
    const items = await papi(`/usuarios/buscar?q=${encodeURIComponent(q)}`);
    out.innerHTML = items.length ? items.map(row).join('') + (items.length === 20 ? '<li class="muted">Se muestran las primeras 20: afina la búsqueda.</li>' : '')
      : '<li class="muted">No hay cuentas que coincidan.</li>';
  } catch (err) { out.innerHTML = `<li class="muted">${escapeHtml(err.message)}</li>`; }
}

async function open(id) {
  let u;
  try { u = await papi(`/usuarios/${id}`); } catch (e) { toast(e.message, 'error'); return; }
  const self = u.id === ctx.usuario.id;
  $('[data-det-title]').textContent = u.nombre;
  const body = $('[data-det-body]');
  body.innerHTML = `
    <p class="alert" data-det-alert role="alert" hidden></p>
    <div class="toolbar">${u.rol_plataforma ? `<span class="badge badge--gold">${ROLES[u.rol_plataforma]}</span>` : ''}${u.estado === 'bloqueado' ? '<span class="badge badge--off">Bloqueada</span>' : '<span class="badge badge--ok">Activa</span>'}</div>
    ${u.estado === 'bloqueado' ? `<p class="note-box"><strong>Bloqueada ${fmtDateTime(u.bloqueado_en)}.</strong> ${escapeHtml(u.motivo_bloqueo || '')}</p>` : ''}
    <dl class="kv">
      <dt>Correo</dt><dd>${escapeHtml(u.email)}${u.email_verificado ? '' : ' · sin verificar'}</dd>
      <dt>Creada</dt><dd>${fmtDateTime(u.creado_en)}</dd>
      <dt>Último acceso</dt><dd>${fmtDateTime(u.ultimo_login_en)}</dd>
      <dt>Sesiones activas</dt><dd>${u.sesiones_activas}</dd>
      <dt>Barberías</dt><dd>${u.barberias.length ? u.barberias.map((m) => `${escapeHtml(m.nombre)} · ${escapeHtml(ROLES_BARBERIA[m.rol] || m.rol)}${m.estado !== 'activo' ? ` (${escapeHtml(m.estado)})` : ''}`).join('<br>') : 'Ninguna (cuenta de cliente)'}</dd>
    </dl>
    <div class="action-grid">
      ${self ? '<p class="hint">No puedes bloquear tu propia cuenta ni cambiar tu rol.</p>' : `
      ${u.estado === 'bloqueado' ? '<button class="btn btn--sm" type="button" data-act="desbloquear">Desbloquear</button>' : '<button class="btn btn--danger btn--sm" type="button" data-act="bloquear">Bloquear cuenta</button>'}
      <button class="btn btn--ghost btn--sm" type="button" data-act="rol">Rol de plataforma</button>`}
    </div>
    <p class="privacy">${icon('check')}<span>Esta consulta queda registrada en la auditoría. Bloquear cierra todas las sesiones de la cuenta.</span></p>
    ${u.historial.length ? `<h3 class="section-sub">Historial</h3><ul class="history">${u.historial.map((h) => `<li>${escapeHtml(ACCIONES[h.accion] || h.accion)}${h.datos && h.datos.motivo ? `: ${escapeHtml(h.datos.motivo)}` : ''}${h.datos && 'rol' in h.datos ? `: ${escapeHtml(ROLES[h.datos.rol] || 'sin rol')}` : ''}<small>${fmtDateTime(h.fecha)}${h.por ? ` · ${escapeHtml(h.por)}` : ''}</small></li>`).join('')}</ul>` : ''}`;
  body.querySelectorAll('[data-act]').forEach((b) => { b.onclick = () => act(u, b.dataset.act); });
  openSheet(sheet);
}

async function act(u, a) {
  const after = (msg) => { toast(msg); loadTeam(); if ($('[data-search]').q.value.trim().length >= 3) search(); open(u.id); };
  try {
    if (a === 'bloquear') {
      const motivo = await askText(`Bloquear a ${u.email}: no podrá iniciar sesión y se cerrarán sus sesiones abiertas.`, { label: 'Motivo (mín. 5 caracteres)', ok: 'Bloquear', danger: true, required: true });
      if (motivo === null) return;
      const r = await papi(`/usuarios/${u.id}/bloquear`, { method: 'POST', body: { motivo } });
      after(`Cuenta bloqueada. Sesiones cerradas: ${r.sesiones_cerradas ?? 0}.`);
    } else if (a === 'desbloquear') {
      if (!await confirmAction(`¿Desbloquear a ${u.email}? Podrá volver a iniciar sesión.`, { ok: 'Desbloquear' })) return;
      await papi(`/usuarios/${u.id}/desbloquear`, { method: 'POST' });
      after('Cuenta desbloqueada.');
    } else if (a === 'rol') {
      formSheet({
        title: 'Rol de plataforma',
        ok: 'Guardar rol',
        html: `<p class="hint">Solo para personas del equipo de BARBERAPP con el correo verificado. Cambiar el rol cierra sus sesiones.</p>
          ${field('rol', 'Rol', `<select>${options([['', 'Sin rol (cuenta normal)'], ['operador', 'Operador'], ['superadmin', 'Superadministrador']], u.rol_plataforma || '')}</select>`)}`,
        onSubmit: async (f) => {
          await papi(`/usuarios/${u.id}/rol`, { method: 'PUT', body: { rol: f.rol.value || null } });
          after('Rol actualizado.');
        },
      });
    }
  } catch (e) {
    const el = $('[data-det-alert]');
    if (el) notice(el, errorText(e), 'error'); else toast(errorText(e), 'error');
  }
}

(async () => {
  ctx = await bootPlatform('usuarios');
  if (!ctx) return;
  $('[data-search]').addEventListener('submit', search);
  document.addEventListener('click', (e) => { const li = e.target.closest('[data-team] [data-id], [data-results] [data-id]'); if (li) open(li.dataset.id); });
  document.addEventListener('keydown', (e) => { const li = e.target.closest('[data-team] [data-id], [data-results] [data-id]'); if (li && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(li.dataset.id); } });
  loadTeam();
})();
