import { api } from '../api.js';
import { escapeHtml } from '../ui.js';
import { icon } from '../icons.js';
import { cardHtml, skeletonCards, emptyHtml, me, mountTabbar, bindFavoriteButtons, toast } from '../marketplace.js';

document.querySelectorAll('[data-icon]').forEach((el) => { el.innerHTML = icon(el.dataset.icon); });
mountTabbar('inicio');
bindFavoriteButtons();

const $ = (s) => document.querySelector(s);
const latest = $('[data-latest]');

function hello(user) {
  const h = new Date().getHours();
  const saludo = h < 12 ? 'Buenos días' : h < 19 ? 'Buenas tardes' : 'Buenas noches';
  $('[data-greeting]').textContent = user ? `${saludo}, ${user.usuario.nombres.split(' ')[0]}` : `${saludo} 👋`;
}

async function loadFilters() {
  const f = await api('/marketplace/filtros');
  $('[data-types]').innerHTML = f.tipos_servicio.map((t) =>
    `<a class="pill" href="explorar.html?tipo=${encodeURIComponent(t.codigo)}">${escapeHtml(t.nombre)}</a>`).join('');
  $('[data-districts]').innerHTML = f.distritos.length
    ? f.distritos.map((d) => `<a class="pill" href="explorar.html?distrito=${encodeURIComponent(d.distrito)}">${icon('pin')}${escapeHtml(d.distrito)} <span class="muted">${d.barberias}</span></a>`).join('')
    : '<p class="lead">Aún no hay barberías publicadas.</p>';
}

async function loadLatest() {
  latest.innerHTML = skeletonCards(3);
  try {
    const items = await api('/marketplace/barberias?orden=recientes&per_page=6');
    latest.innerHTML = items.length
      ? items.map((b) => cardHtml(b)).join('')
      : emptyHtml('store', 'Pronto habrá barberías aquí', 'Las barberías aparecen cuando completan su perfil y lo publican.');
  } catch (e) {
    latest.innerHTML = emptyHtml('compass', 'No pudimos cargar las barberías', e.message, '<button class="btn btn--ghost" type="button" data-reload>Reintentar</button>');
  }
}

async function loadFavorites(user) {
  if (!user) return;
  const favs = await api('/me/favoritos');
  $('[data-favs-section]').hidden = favs.length === 0;
  $('[data-favs]').innerHTML = favs.slice(0, 8).map((b) => cardHtml(b, { mini: true })).join('');
}

document.addEventListener('click', (e) => {
  if (e.target.closest('[data-reload]')) loadLatest();
});
document.addEventListener('favorite-change', () => {
  me().then(loadFavorites).catch(() => {});
});

loadLatest();
loadFilters().catch((e) => toast(e.message, 'error'));
me().then((user) => {
  hello(user);
  return loadFavorites(user);
}).catch(() => hello(null));
