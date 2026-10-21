import { api } from './api.js';

// Fase 1: verifica la conexión con la API (GET /health). Las pantallas llegan en fases posteriores.
const status = document.querySelector('[data-api-status]');

async function checkApi() {
  try {
    const data = await api('/health');
    status.dataset.state = data.status === 'ok' ? 'ok' : 'error';
    status.textContent = data.status === 'ok' ? 'API conectada' : 'API con problemas: revisa la base de datos';
  } catch (e) {
    status.dataset.state = 'error';
    status.textContent = e.message;
  }
}

checkApi();
