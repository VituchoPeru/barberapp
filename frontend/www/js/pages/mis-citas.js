import { api } from '../api.js';
import { emptyHtml, me, mountTabbar, loginUrl, page } from '../marketplace.js';
import { bookingCardHtml } from '../booking.js';

/** "Mis citas": próximas (la más cercana primero) e historial, en todas las barberías. */
mountTabbar('citas');

const list = document.querySelector('[data-list]');
const more = document.querySelector('[data-more]');
const tabs = [...document.querySelectorAll('[role="tab"]')];
const panel = document.querySelector('[role="tabpanel"]');
let tipo = new URLSearchParams(location.search).get('tipo') === 'pasadas' ? 'pasadas' : 'proximas';
let pageNo = 1;

const sk = () => Array.from({ length: 3 }, () => '<div class="sk" style="height:112px" aria-hidden="true"></div>').join('');

function emptyFor() {
  return tipo === 'proximas'
    ? emptyHtml('calendar', 'No tienes citas próximas', 'Reserva en tu barbería favorita y aquí verás la fecha, la hora y el código de tu cita.',
      `<a class="btn" href="${page('explorar.html')}">Reservar una cita</a>`)
    : emptyHtml('clock', 'Aún no tienes historial', 'Aquí aparecerán tus citas atendidas, canceladas o pasadas.');
}

async function load(append = false) {
  if (!append) { pageNo = 1; list.innerHTML = sk(); more.hidden = true; }
  try {
    const user = await me();
    if (!user) {
      list.innerHTML = emptyHtml('calendar', 'Tus citas en un solo lugar', 'Inicia sesión para ver, reprogramar o cancelar tus reservas.',
        `<a class="btn" href="${loginUrl('mis-citas.html')}">Iniciar sesión</a><a class="btn btn--ghost" href="${page('auth/registro.html')}">Crear cuenta</a>`);
      return;
    }
    const { data, meta } = await api(`/mis-reservas?tipo=${tipo}&page=${pageNo}&per_page=20`, { withMeta: true });
    const html = data.map((r) => bookingCardHtml(r, page(`cita.html?id=${r.id}`))).join('');
    if (append) list.insertAdjacentHTML('beforeend', html);
    else list.innerHTML = html || emptyFor();
    more.hidden = meta.page * meta.per_page >= meta.total;
  } catch (e) {
    list.innerHTML = emptyHtml('calendar', 'No pudimos cargar tus citas', e.message, '<button class="btn btn--ghost" type="button" data-retry>Reintentar</button>');
  }
}

function select(next) {
  tipo = next;
  tabs.forEach((t) => {
    const on = t.dataset.tipo === tipo;
    t.setAttribute('aria-selected', String(on));
    t.tabIndex = on ? 0 : -1;
  });
  panel.setAttribute('aria-labelledby', `tab-${tipo}`);
  history.replaceState(null, '', tipo === 'pasadas' ? '?tipo=pasadas' : location.pathname);
  load();
}

tabs.forEach((t) => {
  t.addEventListener('click', () => select(t.dataset.tipo));
  t.addEventListener('keydown', (e) => {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
    const other = tabs.find((x) => x !== t);
    other.focus();
    select(other.dataset.tipo);
  });
});
more.querySelector('button').addEventListener('click', () => { pageNo += 1; load(true); });
document.addEventListener('click', (e) => { if (e.target.closest('[data-retry]')) load(); });
select(tipo);
