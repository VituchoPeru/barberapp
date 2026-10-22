import { api } from '../api.js';

/**
 * Páginas legales (Fase 13): completa los datos del responsable, plazos y retención desde
 * GET /legal. Los textos se insertan con textContent; si un dato no se publicó, se indica
 * en lugar de inventarlo.
 */
const fill = (key, value) => {
  document.querySelectorAll(`[data-legal="${key}"]`).forEach((el) => {
    const empty = value === undefined || value === null || value === '';
    el.textContent = empty ? (key === 'registro_banco_datos' ? 'en trámite' : 'pendiente de publicar') : String(value);
    el.classList.toggle('pendiente', empty);
  });
};

(async () => {
  try {
    const data = await api('/legal');
    const r = data.responsable || {};
    Object.entries(r).forEach(([k, v]) => fill(k, v));
    fill('terminos_version', data.terminos_version);
    fill('privacidad_version', data.privacidad_version);
    const plazos = data.plazos_dias_habiles || {};
    fill('plazo_acceso', plazos.acceso);
    fill('plazo_rectificacion', plazos.rectificacion);
    Object.entries(data.retencion || {}).forEach(([k, v]) => fill(k, v));
    fill('notificaciones_retencion_dias', data.notificaciones_retencion_dias);
    fill('copias_conservadas', data.copias_conservadas);
    const warn = document.querySelector('[data-legal-incompleto]');
    if (warn) warn.hidden = !!data.configuracion_completa;
    // El correo de privacidad como enlace.
    document.querySelectorAll('[data-legal="email_privacidad"]').forEach((el) => {
      if (!r.email_privacidad) return;
      const a = document.createElement('a');
      a.href = `mailto:${r.email_privacidad}`;
      a.textContent = r.email_privacidad;
      el.replaceChildren(a);
    });
  } catch {
    // Sin conexión: el texto queda legible; los datos variables muestran "…".
    const warn = document.querySelector('[data-legal-incompleto]');
    if (warn) {
      warn.textContent = 'No pudimos cargar los datos de la empresa. Revisa tu conexión y vuelve a abrir esta página.';
      warn.hidden = false;
    }
  }
})();
