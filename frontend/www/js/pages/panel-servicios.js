import { $, clearErrors, showErrors, withLoading, escapeHtml, notice } from '../ui.js';
import { icon } from '../icons.js';
import { bootPanel, papi, openSheet, confirmAction, toast, money, mins } from '../panel.js';

/** Panel: servicios, precios, duraciones y categorías (Fase 4). */
const listEl = $('[data-list]');
const sheet = $('[data-sheet]');
const form = $('[data-form]');
const catSheet = $('[data-cat-sheet]');
const noticeEl = $('[data-notice]');
let state = { items: [], categorias: [], tipos: [] };
let editing = null;

function render() {
  if (!state.items.length) {
    listEl.innerHTML = `<div class="empty">${icon('scissors')}<h2>Aún no tienes servicios</h2><p>Crea tu primer servicio con su duración y precio para que los clientes puedan reservar.</p></div>`;
    return;
  }
  const groups = new Map();
  state.items.forEach((s) => {
    const key = s.categoria || 'Sin categoría';
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(s);
  });
  listEl.innerHTML = [...groups].map(([cat, items]) => `
    <h3 class="group-title">${escapeHtml(cat)}</h3>
    <ul class="rows">${items.map((s) => `
      <li class="row row--link" data-id="${s.id}" data-inactive="${!s.activo}" tabindex="0" role="button" aria-label="Editar ${escapeHtml(s.nombre)}">
        <span class="thumb">${icon('scissors')}</span>
        <div class="row__main"><p class="row__title">${escapeHtml(s.nombre)}</p>
          <p class="row__meta"><strong>${money(s.precio)}</strong><span>${mins(s.duracion_minutos)}</span>
          <span>${s.barberos ? `${s.barberos} barbero${s.barberos > 1 ? 's' : ''}` : '<span class="badge badge--warn">Sin barberos</span>'}</span>
          ${s.activo ? '' : '<span class="badge badge--off">Inactivo</span>'}</p></div>
        <div class="row__actions"><label class="switch" aria-label="${s.activo ? 'Desactivar' : 'Activar'} ${escapeHtml(s.nombre)}"><input type="checkbox" role="switch" data-toggle="${s.id}" ${s.activo ? 'checked' : ''}></label></div>
      </li>`).join('')}</ul>`).join('');
}

function fillSelects() {
  form.categoria_id.innerHTML = '<option value="">Sin categoría</option>' + state.categorias.filter((c) => c.activo)
    .map((c) => `<option value="${c.id}">${escapeHtml(c.nombre)}</option>`).join('');
  form.tipo.innerHTML = '<option value="">Sin clasificar</option>' + state.tipos.map((t) => `<option value="${escapeHtml(t.codigo)}">${escapeHtml(t.nombre)}</option>`).join('');
}

async function load() {
  try {
    const { data, meta } = await papi('/panel/servicios', { withMeta: true });
    state = { items: data, categorias: meta.categorias, tipos: meta.tipos };
    fillSelects();
    render();
    renderCategories();
  } catch (e) {
    notice(noticeEl, e.status === 403 ? 'Tu rol no permite gestionar servicios.' : e.message, 'error');
  } finally {
    $('[data-loading]').hidden = true;
    $('[data-page]').removeAttribute('aria-busy');
  }
}

function edit(s) {
  editing = s;
  clearErrors(form);
  form.reset();
  $('[data-sheet-title]').textContent = s ? 'Editar servicio' : 'Nuevo servicio';
  $('[data-delete]').hidden = !s;
  if (s) {
    form.nombre.value = s.nombre;
    form.duracion_minutos.value = s.duracion_minutos;
    form.precio.value = Number(s.precio).toFixed(2);
    form.categoria_id.value = s.categoria_id || '';
    form.tipo.value = s.tipo || '';
    form.descripcion.value = s.descripcion || '';
    form.activo.checked = s.activo;
  }
  openSheet(sheet);
  form.nombre.focus();
}

form.addEventListener('submit', (e) => {
  e.preventDefault();
  clearErrors(form);
  const body = {
    nombre: form.nombre.value.trim(),
    duracion_minutos: form.duracion_minutos.value === '' ? null : Number(form.duracion_minutos.value),
    precio: form.precio.value === '' ? null : form.precio.value,
    categoria_id: form.categoria_id.value ? Number(form.categoria_id.value) : null,
    tipo: form.tipo.value || null,
    descripcion: form.descripcion.value.trim() || null,
    activo: form.activo.checked,
  };
  withLoading(form.querySelector('[type="submit"]'), async () => {
    try {
      const path = editing ? `/panel/servicios/${editing.id}` : '/panel/servicios';
      const { meta } = await papi(path, { method: editing ? 'PUT' : 'POST', body, withMeta: true });
      sheet.close();
      toast(editing ? 'Servicio actualizado' : 'Servicio creado');
      if (meta && meta.reservas_futuras) notice(noticeEl, `Hay ${meta.reservas_futuras} reserva(s) futuras con este servicio: conservan el precio y la duración con que se reservaron.`, 'info');
      await load();
    } catch (err) { showErrors(form, err); }
  });
});

$('[data-delete]').addEventListener('click', async () => {
  if (!editing || !(await confirmAction(`¿Eliminar "${editing.nombre}"? Dejará de ofrecerse; las reservas ya hechas se conservan.`, { ok: 'Eliminar', danger: true }))) return;
  try {
    const { meta } = await papi(`/panel/servicios/${editing.id}`, { method: 'DELETE', withMeta: true });
    sheet.close();
    toast('Servicio eliminado');
    if (meta && meta.reservas_futuras) notice(noticeEl, `Atención: ${meta.reservas_futuras} reserva(s) futuras incluyen este servicio y se mantienen.`, 'info');
    await load();
  } catch (err) { toast(err.message, 'error'); }
});

listEl.addEventListener('change', async (e) => {
  const sw = e.target.closest('[data-toggle]');
  if (!sw) return;
  sw.disabled = true;
  try {
    await papi(`/panel/servicios/${sw.dataset.toggle}`, { method: 'PUT', body: { activo: sw.checked } });
    toast(sw.checked ? 'Servicio activado' : 'Servicio desactivado');
    await load();
  } catch (err) { sw.checked = !sw.checked; toast(err.message, 'error'); } finally { sw.disabled = false; }
});
listEl.addEventListener('click', (e) => {
  if (e.target.closest('.switch')) return;
  const row = e.target.closest('[data-id]');
  if (row) edit(state.items.find((s) => s.id === Number(row.dataset.id)));
});
listEl.addEventListener('keydown', (e) => {
  if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('[data-id]')) { e.preventDefault(); e.target.click(); }
});
$('[data-new]').addEventListener('click', () => edit(null));

// ---------- Categorías
function renderCategories() {
  $('[data-cat-list]').innerHTML = state.categorias.map((c) => `
    <li class="row"><span class="thumb">${icon('filter')}</span>
      <form class="row__main field--inline" data-cat-edit="${c.id}">
        <input class="input" name="nombre" value="${escapeHtml(c.nombre)}" maxlength="80" aria-label="Nombre de la categoría" required>
        <small class="muted">${c.servicios} serv.</small>
      </form>
      <div class="row__actions">
        <button class="btn btn--ghost btn--sm" type="button" data-cat-save="${c.id}">Guardar</button>
        <button class="icon-btn" type="button" data-cat-delete="${c.id}" aria-label="Eliminar ${escapeHtml(c.nombre)}">${icon('trash')}</button></div></li>`).join('')
    || '<li class="muted">Sin categorías.</li>';
}
$('[data-categories]').addEventListener('click', () => openSheet(catSheet));
$('[data-cat-form]').addEventListener('submit', (e) => {
  e.preventDefault();
  const f = e.target;
  clearErrors(f);
  withLoading(f.querySelector('[type="submit"]'), async () => {
    try {
      await papi('/panel/categorias', { method: 'POST', body: { nombre: f.nombre.value.trim() } });
      f.reset();
      toast('Categoría agregada');
      await load();
    } catch (err) { showErrors(f, err); }
  });
});
async function renameCategory(id) {
  const input = $(`[data-cat-edit="${id}"] [name="nombre"]`);
  try {
    await papi(`/panel/categorias/${id}`, { method: 'PUT', body: { nombre: input.value.trim() } });
    toast('Categoría guardada');
    await load();
  } catch (err) { toast((err.fields.nombre && err.fields.nombre[0]) || err.message, 'error'); }
}
$('[data-cat-list]').addEventListener('submit', (e) => { e.preventDefault(); renameCategory(e.target.dataset.catEdit); });
$('[data-cat-list]').addEventListener('click', async (e) => {
  const save = e.target.closest('[data-cat-save]');
  if (save) { e.preventDefault(); renameCategory(save.dataset.catSave); return; }
  const del = e.target.closest('[data-cat-delete]');
  if (!del) return;
  try {
    await papi(`/panel/categorias/${del.dataset.catDelete}`, { method: 'DELETE' });
    toast('Categoría eliminada');
    await load();
  } catch (err) { toast(err.message, 'error'); }
});

bootPanel('servicios').then((ctx) => { if (ctx) load(); else { $('[data-loading]').hidden = true; } });
