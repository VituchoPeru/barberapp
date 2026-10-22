import { installState, promptInstall } from '../shell.js';
import { icon } from '../icons.js';

/**
 * Pantalla de instalación de la PWA (Fase 12): botón "Instalar" cuando el navegador lo permite
 * y los pasos para Safari (iPhone), Chrome (Android) y escritorio. Se ordena según el equipo.
 */
document.querySelectorAll('[data-icon]').forEach((el) => { el.innerHTML = icon(el.dataset.icon); });
const title = document.querySelector('[data-state-title]');
const text = document.querySelector('[data-state-text]');
const actions = document.querySelector('[data-state-actions]');

const isAndroid = /android/i.test(navigator.userAgent);
const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const first = isIOS ? 'ios' : isAndroid ? 'android' : 'pc';
const main = document.querySelector('main');
const steps = main.querySelector(`[data-steps="${first}"]`);
main.insertBefore(steps, main.querySelector('[data-steps]'));

function render() {
  const state = installState();
  if (state === 'instalada') {
    title.textContent = 'BARBERAPP ya está instalada';
    text.textContent = 'Estás usando BARBERAPP como app. Encuéntrala en tu pantalla de inicio.';
    actions.innerHTML = '<a class="btn btn--sm" href="inicio.html">Ir al inicio</a>';
    document.querySelectorAll('[data-steps]').forEach((s) => { s.hidden = true; });
    return;
  }
  actions.innerHTML = state === 'disponible'
    ? '<button class="btn" type="button" data-install-now>Instalar ahora</button>'
    : `<p class="install-card__text">${state === 'ios' ? 'En iPhone se instala desde Safari: sigue los pasos de abajo.' : 'Sigue los pasos de tu navegador que ves abajo.'}</p>`;
}

actions.addEventListener('click', async (e) => {
  if (!e.target.closest('[data-install-now]')) return;
  await promptInstall();
  render();
});
document.addEventListener('pwa:estado', render);
render();
