import { api } from '../api.js';
import { escapeHtml } from '../ui.js';
import { icon } from '../icons.js';
import {
  cardHtml, skeletonCards, emptyHtml, mountTabbar, bindFavoriteButtons, toast, money,
  savedLocation, requestLocation, forgetLocation,
} from '../marketplace.js';

/**
 * Explorar y resultados de búsqueda. El estado de búsqueda vive en la URL (se puede
 * compartir); la ubicación NO: se pide con permiso y se guarda solo en la pestaña.
 */
document.querySelectorAll('[data-icon]').forEach((el) => { el.innerHTML = icon(el.dataset.icon); });
mountTabbar('explorar');
bindFavoriteButtons();

const $ = (s) => document.querySelector(s);
const results = $('[data-results]');
const countEl = $('[data-count]');
const more = $('[data-more]');
const dialog = $('[data-filters]');
const form = $('[data-filter-form]');
const nearBtn = $('[data-near]');
const KEYS = ['q', 'distrito', 'tipo', 'precio_min', 'precio_max', 'radio_km', 'orden'];

let state = {};
let filtros = null;
let page = 1;
let loading = false;

function readUrl() {
  const p = new URLSearchParams(location.search);
  state = {};
  KEYS.forEach((k) => { const v = (p.get(k) || '').trim(); if (v) state[k] = v; });
  state.cerca = p.get('cerca') === '1';
}

function writeUrl() {
  const p = new URLSearchParams();
  KEYS.forEach((k) => { if (state[k]) p.set(k, state[k]); });
  if (state.cerca) p.set('cerca', '1');
  history.replaceState(null, '', `${location.pathname}${p.toString() ? '?' + p : ''}`);
}

function query(pageNum) {
  const p = new URLSearchParams();
  KEYS.forEach((k) => { if (state[k]) p.set(k, state[k]); });
  const loc = state.cerca ? savedLocation() : null;
  if (loc) { p.set('lat', loc.lat); p.set('lng', loc.lng); } else { p.delete('radio_km'); if (p.get('orden') === 'distancia') p.delete('orden'); }
  p.set('page', pageNum);
  p.set('per_page', 12);
  return p.toString();
}

const labelTipo = (code) => (filtros?.tipos_servicio.find((t) => t.codigo === code) || {}).nombre || code;

function renderActive() {
  const chips = [];
  if (state.q) chips.push(['q', `“${state.q}”`]);
  if (state.distrito) chips.push(['distrito', state.distrito]);
  if (state.tipo) chips.push(['tipo', labelTipo(state.tipo)]);
  if (state.precio_min || state.precio_max) chips.push(['precio', `${state.precio_min ? money(state.precio_min) : 'S/ 0'} – ${state.precio_max ? money(state.precio_max) : 'más'}`]);
  if (state.cerca && state.radio_km) chips.push(['radio_km', `≤ ${state.radio_km} km`]);
  $('[data-active-filters]').innerHTML = chips.map(([k, label]) =>
    `<button class="pill pill--active" type="button" data-remove="${k}" aria-label="Quitar filtro ${escapeHtml(label)}">${escapeHtml(label)} ${icon('close')}</button>`).join('');
  const n = ['distrito', 'tipo', 'radio_km'].filter((k) => state[k]).length + (state.precio_min || state.precio_max ? 1 : 0) + (state.orden ? 1 : 0);
  $('[data-open-filters]').dataset.count = String(n);
  $('[data-search] input').value = state.q || '';
  nearBtn.setAttribute('aria-pressed', String(!!state.cerca && !!savedLocation()));
}

async function load(reset = true) {
  if (loading) return;
  loading = true;
  if (reset) {
    page = 1;
    results.innerHTML = skeletonCards(6);
    countEl.textContent = 'Buscando…';
  }
  results.setAttribute('aria-busy', 'true');
  try {
    const res = await fetchPage(page);
    const items = res.items;
    const meta = res.meta;
    if (reset) results.innerHTML = '';
    if (reset && items.length === 0) {
      countEl.innerHTML = '<strong>0</strong> barberías';
      const filtered = Object.keys(state).some((k) => KEYS.includes(k) && state[k]);
      results.innerHTML = emptyHtml('search', 'No encontramos barberías',
        filtered ? 'Prueba con otra búsqueda o quita algunos filtros.' : 'Todavía no hay barberías publicadas en el marketplace.',
        filtered ? '<button class="btn btn--ghost" type="button" data-clear-all>Limpiar filtros</button>' : '');
    } else {
      results.insertAdjacentHTML('beforeend', items.map((b) => cardHtml(b)).join(''));
      countEl.innerHTML = `<strong>${meta.total}</strong> ${meta.total === 1 ? 'barbería' : 'barberías'}${meta.con_ubicacion ? ' · ordenadas por cercanía' : ''}`;
    }
    more.hidden = meta.page >= meta.pages;
  } catch (e) {
    if (reset) results.innerHTML = emptyHtml('compass', 'No pudimos buscar', e.message, '<button class="btn btn--ghost" type="button" data-retry>Reintentar</button>');
    else toast(e.message, 'error');
    countEl.textContent = '';
  } finally {
    results.removeAttribute('aria-busy');
    loading = false;
  }
}

async function fetchPage(n) {
  const res = await api(`/marketplace/barberias?${query(n)}`, { withMeta: true });
  return { items: res.data, meta: res.meta };
}

async function useLocation() {
  nearBtn.disabled = true;
  try {
    await requestLocation();
    state.cerca = true;
    if (!state.orden) state.orden = 'distancia';
    writeUrl();
    renderActive();
    await load();
  } catch (e) {
    state.cerca = false;
    writeUrl();
    renderActive();
    toast(e.message, 'error');
  } finally {
    nearBtn.disabled = false;
  }
}

function fillForm() {
  form.distrito.value = state.distrito || '';
  form.tipo.value = state.tipo || '';
  form.precio_min.value = state.precio_min || '';
  form.precio_max.value = state.precio_max || '';
  form.radio_km.value = state.radio_km || '';
  form.orden.value = state.orden || '';
  $('[data-filter-alert]').hidden = true;
}

async function loadFilters() {
  filtros = await api('/marketplace/filtros');
  form.distrito.insertAdjacentHTML('beforeend', filtros.distritos.map((d) =>
    `<option value="${escapeHtml(d.distrito)}">${escapeHtml(d.distrito)} (${d.barberias})</option>`).join(''));
  form.tipo.insertAdjacentHTML('beforeend', filtros.tipos_servicio.map((t) =>
    `<option value="${escapeHtml(t.codigo)}">${escapeHtml(t.nombre)}</option>`).join(''));
  if (filtros.precios.minimo !== null) {
    $('[data-price-hint]').textContent = `Precios publicados: de ${money(filtros.precios.minimo)} a ${money(filtros.precios.maximo)}.`;
  }
  renderActive();
}

// ---------- eventos
$('[data-search]').addEventListener('submit', (e) => {
  e.preventDefault();
  state.q = e.target.q.value.trim();
  if (!state.q) delete state.q;
  writeUrl(); renderActive(); load();
});
$('[data-open-filters]').addEventListener('click', () => { fillForm(); dialog.showModal(); });
dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());
dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
$('[data-clear]').addEventListener('click', () => { form.reset(); });
form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const v = Object.fromEntries(new FormData(form));
  const alert = $('[data-filter-alert]');
  if (v.precio_min && v.precio_max && Number(v.precio_min) > Number(v.precio_max)) {
    alert.textContent = 'El precio mínimo no puede ser mayor que el máximo.';
    alert.hidden = false;
    return;
  }
  ['distrito', 'tipo', 'precio_min', 'precio_max', 'radio_km', 'orden'].forEach((k) => { if (v[k]) state[k] = v[k]; else delete state[k]; });
  dialog.close();
  if ((state.radio_km || state.orden === 'distancia') && !savedLocation()) {
    await useLocation();
    return;
  }
  if (state.radio_km || state.orden === 'distancia') state.cerca = true;
  writeUrl(); renderActive(); load();
});
nearBtn.addEventListener('click', () => {
  if (nearBtn.getAttribute('aria-pressed') === 'true') {
    forgetLocation();
    state.cerca = false;
    delete state.radio_km;
    if (state.orden === 'distancia') delete state.orden;
    writeUrl(); renderActive(); load();
  } else {
    useLocation();
  }
});
document.addEventListener('click', (e) => {
  const rm = e.target.closest('[data-remove]');
  if (rm) {
    const k = rm.dataset.remove;
    if (k === 'precio') { delete state.precio_min; delete state.precio_max; } else delete state[k];
    writeUrl(); renderActive(); load();
  }
  if (e.target.closest('[data-clear-all]')) { state = { cerca: state.cerca }; writeUrl(); renderActive(); load(); }
  if (e.target.closest('[data-retry]')) load();
});
more.querySelector('button').addEventListener('click', () => { page += 1; load(false); });

// ---------- inicio
readUrl();
renderActive();
loadFilters().catch(() => {});
if (state.cerca && !savedLocation()) useLocation();
else load();
