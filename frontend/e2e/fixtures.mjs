// Fixtures compartidas: sesión por rol y "vigilante" de cada página.
// El vigilante falla la prueba si la página tuvo errores de JavaScript, respuestas 5xx de la API,
// recursos propios rotos, contenido más ancho que la pantalla o una carga XSS convertida en HTML.
import { test as base, expect } from '@playwright/test';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { WEB } from './entorno.mjs';

const here = dirname(fileURLToPath(import.meta.url));

export function authFile(rol) {
  return join(here, '.auth', `${rol}.json`);
}

export const test = base.extend({
  rol: [null, { option: true }],

  storageState: async ({ rol }, use) => {
    await use(rol ? authFile(rol) : undefined);
  },

  page: async ({ page, rol }, use) => {
    if (rol && existsSync(authFile(rol))) {
      // El token CSRF vive en sessionStorage (no en la cookie): se deja listo antes de cargar.
      const { csrf } = JSON.parse(readFileSync(authFile(rol), 'utf8'));
      await page.addInitScript((t) => { try { sessionStorage.setItem('barberapp.csrf', t); } catch { /* nada */ } }, csrf);
    }
    const problemas = [];
    page.on('pageerror', (e) => problemas.push(`Error de JavaScript: ${e.message}`));
    page.on('console', (m) => {
      // Los 4xx esperados (p. ej. /me sin sesión) también se anuncian en consola: se juzgan por la respuesta.
      if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) problemas.push(`Consola: ${m.text()}`);
    });
    page.on('response', (r) => {
      const url = r.url();
      if (r.status() >= 500) problemas.push(`HTTP ${r.status()} en ${url}`);
      if (r.status() === 404 && url.includes(`${WEB}/`) && !url.includes('/api/')) problemas.push(`Recurso faltante: ${url}`);
    });
    page.on('requestfailed', (r) => {
      const err = r.failure()?.errorText || '';
      if (!/ERR_ABORTED|NS_BINDING_ABORTED|cancelled|Load request cancelled/i.test(err)) problemas.push(`Petición fallida ${r.url()}: ${err}`);
    });
    page.problemas = problemas;
    await use(page);
    expect(problemas, 'Problemas en la página').toEqual([]);
  },
});

export { expect };

/** Revisiones comunes a toda pantalla ya cargada. */
export async function revisarPantalla(page, nombre) {
  // Sin esqueletos de carga pendientes (la pantalla terminó de pedir sus datos).
  await expect(page.locator('.skeleton:visible')).toHaveCount(0, { timeout: 15_000 });
  const xss = await page.locator('[data-xss]').count();
  expect(xss, `${nombre}: un texto con etiquetas se pintó como HTML (XSS)`).toBe(0);
  // En móviles, si algo desborda, el navegador agranda el viewport (innerWidth crece con él):
  // se compara contra el ancho real de la pantalla emulada.
  const vista = page.viewportSize()?.width ?? 0;
  const ancho = await page.evaluate((vista) => {
    const doc = document.scrollingElement || document.documentElement;
    // Elementos que de verdad ensanchan la página: se salen por la derecha, no están dentro de un
    // contenedor con scroll o recorte propio y su padre no se sale (se informa el más externo).
    const sale = (el) => el.getBoundingClientRect().right > vista + 1;
    const recortado = (el) => {
      for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
        if (getComputedStyle(p).overflowX !== 'visible') return true;
      }
      return false;
    };
    const nombreDe = (el) => {
      const clase = typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).join('.') : '';
      return `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${clase}`;
    };
    const anchos = [...document.querySelectorAll('body *')]
      .filter((el) => sale(el) && getComputedStyle(el).position !== 'fixed' && el.closest('[hidden],dialog:not([open])') === null)
      .filter((el) => !recortado(el) && !(el.parentElement && el.parentElement !== document.body && sale(el.parentElement)))
      .slice(0, 4)
      .map((el) => {
        const r = el.getBoundingClientRect();
        return `${nombreDe(el)} [${Math.round(r.left)}–${Math.round(r.right)}px]`;
      });
    const scroll = Math.max(doc.scrollWidth, window.innerWidth);
    if (scroll > vista + 1 && anchos.length === 0) {
      // Ninguna caja se sale: se busca texto o contenido interno (controles de formulario) que
      // desborde su caja, fuera de contenedores con scroll.
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
      for (let t = walker.nextNode(); t && anchos.length < 4; t = walker.nextNode()) {
        const el = t.parentElement;
        if (!el || !t.textContent.trim() || recortado(el) || el.closest('[hidden],dialog:not([open])')) continue;
        const r = document.createRange();
        r.selectNodeContents(t);
        const der = Math.max(0, ...[...r.getClientRects()].map((x) => x.right));
        if (der > vista + 1) anchos.push(`texto «${t.textContent.trim().slice(0, 30)}» en ${nombreDe(el)} [→${Math.round(der)}px]`);
      }
      for (const el of document.querySelectorAll('body *')) {
        if (anchos.length >= 6) break;
        if (recortado(el) || getComputedStyle(el).overflowX !== 'visible' || el.closest('[hidden],dialog:not([open])') || el.scrollWidth <= el.clientWidth + 1 || el.clientWidth === 0) continue;
        const r = el.getBoundingClientRect();
        if (r.left + el.scrollWidth > vista + 1) anchos.push(`contenido de ${nombreDe(el)} [${Math.round(r.left)}+${el.scrollWidth}px, caja ${el.clientWidth}px]`);
      }
      if (anchos.length === 0) anchos.push(`innerWidth ${window.innerWidth}, scrollWidth ${doc.scrollWidth}, body ${document.body.scrollWidth}`);
    }
    return { scroll, vista, anchos };
  }, vista);
  expect(ancho.scroll, `${nombre}: desborde horizontal (${ancho.anchos.join(', ')})`).toBeLessThanOrEqual(ancho.vista + 1);
  await expect(page.locator('h1').first(), `${nombre}: sin título principal`).toBeAttached();
}
