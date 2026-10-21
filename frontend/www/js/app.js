import { api } from './api.js';
import { currentUser } from './auth.js';

// Verifica la conexión con la API (GET /health) y muestra el acceso a la cuenta.
// El marketplace y el resto de pantallas llegan en la Fase 3.
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

currentUser().then((me) => {
  if (!me) return;
  document.querySelector('[data-home-actions]').innerHTML =
    '<a class="btn" href="pages/auth/cuenta.html">Mi cuenta</a>';
}).catch(() => {});
