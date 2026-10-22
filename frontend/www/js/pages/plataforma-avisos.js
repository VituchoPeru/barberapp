import { $, escapeHtml, clearErrors, showErrors } from '../ui.js';
import { icon } from '../icons.js';
import { bootPlatform, papi, fail, done, toast, confirmAction, fmtDateTime, plural, formSheet, field } from '../plataforma.js';

/** Plantillas globales de avisos (Fase 9): edición con variables validadas y vista previa. */
let items = [];
const CANAL = { push: 'Push y bandeja de la app', email: 'Correo' };
const GRUPOS = { citas: 'Avisos de citas (a clientes)', cuenta: 'Avisos de cuenta (a propietarios)' };

function render() {
  $('[data-list]').innerHTML = Object.entries(GRUPOS).map(([g, title]) => {
    const list = items.filter((t) => t.grupo === g);
    if (!list.length) return '';
    return `<h3 class="group-title">${title}</h3><ul class="rows">${list.map((t) => `
      <li class="row row--link" tabindex="0" role="button" data-key="${t.codigo}|${t.canal}" aria-label="Editar ${escapeHtml(t.nombre)}, ${CANAL[t.canal]}">
        <span class="thumb">${icon(t.canal === 'email' ? 'chat' : 'bell')}</span>
        <div class="row__main"><p class="row__title">${escapeHtml(t.nombre)}</p>
          <p class="row__meta"><span>${CANAL[t.canal]}</span><span>${escapeHtml(t.asunto)}</span>
          ${t.barberias_con_plantilla_propia ? `<span>${plural(t.barberias_con_plantilla_propia, 'barbería usa la suya', 'barberías usan la suya')}</span>` : ''}</p></div>
        <div class="row__actions">${t.personalizada ? '<span class="badge badge--gold">Personalizada</span>' : '<span class="badge">Texto del sistema</span>'}</div></li>`).join('')}</ul>`;
  }).join('');
}

async function load() {
  try {
    items = await papi('/plantillas');
    render();
  } catch (e) { fail(e.message); return; }
  done();
}

function edit(t) {
  const path = `/plantillas/${t.codigo}/${t.canal}`;
  const f = formSheet({
    title: `${t.nombre} · ${CANAL[t.canal]}`,
    ok: 'Guardar plantilla',
    html: `${field('asunto', t.canal === 'email' ? 'Asunto del correo' : 'Título', `<input maxlength="${t.limites.asunto}" required value="${escapeHtml(t.asunto)}">`)}
      ${field('cuerpo', 'Texto', `<textarea class="mono" rows="${t.canal === 'email' ? 10 : 4}" maxlength="${t.limites.cuerpo}" required>${escapeHtml(t.cuerpo)}</textarea>`, `Máximo ${t.limites.cuerpo} caracteres. Sin < ni >.`)}
      <div><p class="hint" style="margin-bottom:6px">Insertar variable:</p><div class="var-chips">${t.variables.map((v) => `<button type="button" data-var="${v}">{{${v}}}</button>`).join('')}</div></div>
      <div class="link-row"><button class="btn btn--ghost btn--sm" type="button" data-preview>Vista previa</button>
        ${t.personalizada ? '<button class="btn btn--ghost btn--sm" type="button" data-restore>Restaurar texto del sistema</button>' : ''}</div>
      <div class="preview-box" data-preview-box hidden></div>
      <p class="hint">Versión ${t.version}${t.actualizada_en ? ` · actualizada ${fmtDateTime(t.actualizada_en)}` : ''}.${t.barberias_con_plantilla_propia ? ` ${plural(t.barberias_con_plantilla_propia, 'barbería tiene', 'barberías tienen')} su propia versión y no verá este cambio.` : ''}</p>`,
    onSubmit: async (fm) => {
      await papi(path, { method: 'PUT', body: { asunto: fm.asunto.value, cuerpo: fm.cuerpo.value } });
      toast('Plantilla guardada. Se usará en los próximos avisos.');
      load();
    },
  });
  let last = f.cuerpo;
  [f.asunto, f.cuerpo].forEach((el) => el.addEventListener('focus', () => { last = el; }));
  f.querySelectorAll('[data-var]').forEach((b) => {
    b.onclick = () => {
      const text = `{{${b.dataset.var}}}`;
      const s = last.selectionStart ?? last.value.length;
      last.value = last.value.slice(0, s) + text + last.value.slice(last.selectionEnd ?? s);
      last.focus();
      last.setSelectionRange(s + text.length, s + text.length);
    };
  });
  f.querySelector('[data-preview]').onclick = async () => {
    clearErrors(f);
    const box = f.querySelector('[data-preview-box]');
    try {
      const p = await papi(`${path}/vista-previa`, { method: 'POST', body: { asunto: f.asunto.value, cuerpo: f.cuerpo.value } });
      box.innerHTML = `<strong>${escapeHtml(p.asunto)}</strong>${escapeHtml(p.cuerpo)}<p class="hint" style="margin-top:8px">Vista previa con datos de ejemplo.</p>`;
      box.hidden = false;
    } catch (e) { box.hidden = true; showErrors(f, e); }
  };
  const restore = f.querySelector('[data-restore]');
  if (restore) {
    restore.onclick = async () => {
      if (!await confirmAction('¿Volver al texto original del sistema para esta plantilla?', { ok: 'Restaurar' })) return;
      try {
        await papi(path, { method: 'DELETE' });
        document.getElementById('pl-form').close();
        toast('Plantilla restaurada.');
        load();
      } catch (e) { toast(e.message, 'error'); }
    };
  }
}

(async () => {
  if (!await bootPlatform('avisos')) return;
  const pick = (e) => {
    const li = e.target.closest('[data-key]');
    if (!li || (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ')) return;
    e.preventDefault();
    edit(items.find((t) => `${t.codigo}|${t.canal}` === li.dataset.key));
  };
  $('[data-list]').addEventListener('click', pick);
  $('[data-list]').addEventListener('keydown', pick);
  load();
})();
