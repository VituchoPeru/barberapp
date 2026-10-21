import { api } from '../api.js';
import { cardHtml, skeletonCards, emptyHtml, me, mountTabbar, bindFavoriteButtons, loginUrl, page } from '../marketplace.js';

mountTabbar('favoritos');
bindFavoriteButtons();
const list = document.querySelector('[data-list]');

const empty = () => emptyHtml('heart', 'Aún no tienes favoritas',
  'Toca el corazón en una barbería para guardarla aquí y volver en un toque.',
  `<a class="btn" href="${page('explorar.html')}">Explorar barberías</a>`);

async function load() {
  list.innerHTML = skeletonCards(3);
  try {
    const user = await me();
    if (!user) {
      list.innerHTML = emptyHtml('heart', 'Guarda tus barberías favoritas',
        'Inicia sesión o crea tu cuenta gratis para guardar barberías y reservar más rápido.',
        `<a class="btn" href="${loginUrl('favoritos.html')}">Iniciar sesión</a><a class="btn btn--ghost" href="${page('auth/registro.html')}">Crear cuenta</a>`);
      return;
    }
    const items = await api('/me/favoritos');
    list.innerHTML = items.length ? items.map((b) => cardHtml(b)).join('') : empty();
  } catch (e) {
    list.innerHTML = emptyHtml('heart', 'No pudimos cargar tus favoritas', e.message, '<button class="btn btn--ghost" type="button" data-retry>Reintentar</button>');
  }
}

// Al quitar una favorita, la tarjeta sale de la lista.
document.addEventListener('favorite-change', (e) => {
  if (e.detail.favorito) return;
  const card = list.querySelector(`.card[data-slug="${CSS.escape(e.detail.slug)}"]`);
  if (card) card.remove();
  if (!list.querySelector('.card')) list.innerHTML = empty();
});
document.addEventListener('click', (e) => { if (e.target.closest('[data-retry]')) load(); });
load();
