import { api } from '../api.js';
import { $, notice, withLoading } from '../ui.js';
import { icon } from '../icons.js';
import { emptyHtml, mountTabbar, toast, page } from '../marketplace.js';
import { itemHtml } from '../avisos.js';

/**
 * Bandeja de avisos (Fase 8): recordatorios, cambios en las citas y avisos de la agenda.
 * Sirve al cliente y al personal; los enlaces al panel conservan la barbería (?tenant=).
 */
const params = new URLSearchParams(location.search);
const tenant = params.get('tenant') || '';
const noticeEl = $('[data-notice]');
const list = $('[data-list]');
const more = $('[data-more]');
let filtro = '';
let pagina = 1;

document.querySelectorAll('[data-icon]').forEach((el) => { el.innerHTML = icon(el.dataset.icon); });
const back = $('[data-back]');
if (tenant) back.href = `panel/inicio.html?tenant=${encodeURIComponent(tenant)}`;
else mountTabbar('');
back.addEventListener('click', (e) => { if (history.length > 1 && document.referrer.startsWith(location.origin)) { e.preventDefault(); history.back(); } });

const skeleton = () => Array.from({ length: 4 }, () => '<li class="sk sk--aviso" aria-hidden="true"></li>').join('');

function announce(meta) {
  document.dispatchEvent(new CustomEvent('avisos:leidos', { detail: { no_leidas: meta.no_leidas } }));
  $('[data-read-all]').hidden = meta.no_leidas === 0;
}

async function load(reset = true) {
  if (reset) { pagina = 1; list.innerHTML = skeleton(); }
  try {
    const qs = new URLSearchParams({ page: String(pagina), per_page: '20' });
    if (filtro) qs.set('solo_no_leidas', '1');
    const { data, meta } = await api(`/me/notificaciones?${qs}`, { withMeta: true });
    if (reset) list.innerHTML = '';
    if (!data.length && pagina === 1) {
      list.innerHTML = `<li>${emptyHtml('bell', filtro ? 'Estás al día' : 'Aún no tienes avisos', filtro ? 'No tienes avisos sin leer.' : 'Aquí verás tus recordatorios y los cambios en tus citas.')}</li>`;
    } else {
      list.insertAdjacentHTML('beforeend', data.map(itemHtml).join(''));
    }
    more.hidden = pagina * meta.per_page >= meta.total;
    announce(meta);
    noticeEl.hidden = true;
  } catch (e) {
    if (e.status === 401) {
      location.replace(page(`auth/login.html?next=${encodeURIComponent('../notificaciones.html' + location.search)}`));
      return;
    }
    list.innerHTML = '';
    notice(noticeEl, e.message, 'error');
  } finally {
    $('[data-page]').removeAttribute('aria-busy');
  }
}

async function markRead(li) {
  if (!li.classList.contains('aviso--nuevo')) return;
  const summary = await api(`/me/notificaciones/${li.dataset.id}/leer`, { method: 'POST', body: {} });
  li.classList.remove('aviso--nuevo');
  li.querySelector('[data-leer]')?.remove();
  li.querySelector('.sr-only')?.remove();
  announce(summary);
}

list.addEventListener('click', async (e) => {
  const li = e.target.closest('.aviso');
  if (!li) return;
  const confirmar = e.target.closest('[data-confirmar]');
  const leer = e.target.closest('[data-leer]');
  const open = e.target.closest('[data-open]');
  try {
    if (confirmar) {
      await withLoading(confirmar, () => api(`/mis-reservas/${confirmar.dataset.confirmar}/confirmar-asistencia`, { method: 'POST', body: {} }));
      confirmar.replaceWith(Object.assign(document.createElement('span'), { className: 'badge', textContent: 'Asistencia confirmada' }));
      toast('¡Listo! La barbería sabe que asistirás.');
      await markRead(li);
    } else if (leer) {
      await markRead(li);
    } else if (open) {
      e.preventDefault();
      await markRead(li).catch(() => {});
      location.assign(open.href);
    }
  } catch (err) {
    toast(err.message, 'error');
  }
});

$('[data-read-all]').addEventListener('click', async (e) => {
  try {
    const s = await withLoading(e.currentTarget, () => api('/me/notificaciones/leer-todas', { method: 'POST', body: {} }));
    announce(s);
    load();
  } catch (err) {
    toast(err.message, 'error');
  }
});

document.querySelectorAll('[role="tab"]').forEach((tab) => tab.addEventListener('click', () => {
  document.querySelectorAll('[role="tab"]').forEach((t) => { t.setAttribute('aria-selected', String(t === tab)); t.tabIndex = t === tab ? 0 : -1; });
  $('[role="tabpanel"]').setAttribute('aria-labelledby', tab.id);
  filtro = tab.dataset.filtro;
  load();
}));
more.querySelector('button').addEventListener('click', () => { pagina += 1; load(false); });

load();
