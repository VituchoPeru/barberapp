import { api } from './api.js';
import { CREST } from './icons.js';

/**
 * Splash screen: muestra la marca mientras verifica la API (GET /health) y luego
 * dirige al onboarding (primera visita) o al inicio del marketplace.
 */
const ONBOARDING_KEY = 'barberapp.onboarding';
const MIN_SPLASH_MS = 900;

document.querySelector('[data-crest]').outerHTML = CREST;
const status = document.querySelector('[data-api-status]');
const spinner = document.querySelector('[data-spinner]');
const retry = document.querySelector('[data-retry]');

function seenOnboarding() {
  try { return localStorage.getItem(ONBOARDING_KEY) === 'visto'; } catch { return true; }
}

async function start() {
  const started = Date.now();
  retry.hidden = true;
  spinner.hidden = false;
  status.dataset.state = '';
  status.textContent = 'Cargando tu experiencia…';
  try {
    const data = await api('/health');
    if (data.status !== 'ok') throw new Error('El servicio no está disponible en este momento. Intenta en unos minutos.');
    const wait = MIN_SPLASH_MS - (Date.now() - started);
    if (wait > 0) await new Promise((r) => setTimeout(r, wait));
    location.replace(seenOnboarding() ? 'pages/inicio.html' : 'pages/onboarding.html');
  } catch (e) {
    spinner.hidden = true;
    status.dataset.state = 'error';
    status.textContent = e.message;
    retry.hidden = false;
    document.querySelector('main').removeAttribute('aria-busy');
  }
}

document.querySelector('[data-retry-btn]').addEventListener('click', start);
start();
