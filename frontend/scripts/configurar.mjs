#!/usr/bin/env node
/**
 * Configura la app nativa antes de `npx cap sync` (Fase 12):
 *
 *   BARBERAPP_API_URL=https://api.barberapp.pe/api/v1 \
 *   BARBERAPP_WEB_URL=https://app.barberapp.pe \
 *   npm run configurar [-- --produccion]
 *
 * - Escribe www/js/runtime-config.js con la URL de la API (la WebView de Capacitor sirve los
 *   archivos desde https://localhost o capacitor://localhost, así que no puede calcularla).
 * - Ajusta el dominio de los enlaces directos /b/{slug} en Android (gradle.properties) e iOS
 *   (App.entitlements) a partir de BARBERAPP_WEB_URL.
 * - Con --produccion exige HTTPS y rechaza localhost.
 *
 * Sin variables conserva la configuración actual. No guarda secretos: todo esto viaja dentro
 * de la app y cualquiera puede leerlo.
 */
import { readFile, writeFile, access } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const prod = process.argv.includes('--produccion');
const fail = (msg) => { console.error(`✖ ${msg}`); process.exit(1); };
const exists = (p) => access(join(ROOT, p)).then(() => true, () => false);

const file = join(ROOT, 'www/js/runtime-config.js');
// Valores actuales (el archivo es un módulo del navegador; se lee como texto).
const previous = await readFile(file, 'utf8').catch(() => '');
const read = (key) => { const m = previous.match(new RegExp(`${key}:\\s*("(?:[^"\\\\]|\\\\.)*")`)); return m ? JSON.parse(m[1]) : ''; };
const current = { apiUrl: read('apiUrl'), webUrl: read('webUrl') };

function clean(value, name) {
  if (value === undefined) return undefined;
  const v = String(value).trim().replace(/\/+$/, '');
  if (v === '') return '';
  let u;
  try { u = new URL(v); } catch { fail(`${name} no es una URL válida: ${v}`); }
  if (!['https:', 'http:'].includes(u.protocol)) fail(`${name} debe empezar con https://`);
  if (u.username || u.password) fail(`${name} no debe llevar usuario ni contraseña.`);
  return v;
}

const apiUrl = clean(process.env.BARBERAPP_API_URL, 'BARBERAPP_API_URL') ?? current.apiUrl ?? '';
const webUrl = clean(process.env.BARBERAPP_WEB_URL, 'BARBERAPP_WEB_URL') ?? current.webUrl ?? '';

if (prod) {
  for (const [name, v] of [['BARBERAPP_API_URL', apiUrl], ['BARBERAPP_WEB_URL', webUrl]]) {
    if (!v) fail(`${name} es obligatoria para compilar la versión de las tiendas.`);
    const u = new URL(v);
    if (u.protocol !== 'https:') fail(`${name} debe usar HTTPS en producción (${v}).`);
    if (['localhost', '127.0.0.1', '10.0.2.2'].includes(u.hostname)) fail(`${name} no puede apuntar a ${u.hostname} en producción.`);
  }
}
if (!apiUrl) {
  console.warn('⚠ BARBERAPP_API_URL está vacía: la app mostrará "sin configurar" al abrir. Ver docs/APPS_MOVILES.md.');
} else if (new URL(apiUrl).protocol === 'http:') {
  console.warn('⚠ La API usa http: solo sirve para pruebas con `adb reverse` hacia localhost (compilación debug).');
}

await writeFile(file, `// Configuración de la app nativa. Este archivo lo reescribe \`npm run configurar\` (frontend/scripts/configurar.mjs)
// antes de \`npx cap sync\`; en web no se usa. No pongas secretos aquí: viaja dentro de la app.
export default {
  apiUrl: ${JSON.stringify(apiUrl)},
  webUrl: ${JSON.stringify(webUrl)},
};
`);

// Dominio de los enlaces directos en Android e iOS.
if (webUrl) {
  const host = new URL(webUrl).hostname;
  if (await exists('android/gradle.properties')) {
    const p = join(ROOT, 'android/gradle.properties');
    let g = await readFile(p, 'utf8');
    g = /^appLinkHost=.*$/m.test(g) ? g.replace(/^appLinkHost=.*$/m, `appLinkHost=${host}`) : `${g.trimEnd()}\nappLinkHost=${host}\n`;
    await writeFile(p, g);
  }
  if (await exists('ios/App/App/App.entitlements')) {
    const p = join(ROOT, 'ios/App/App/App.entitlements');
    const e = await readFile(p, 'utf8');
    await writeFile(p, e.replace(/<string>applinks:[^<]+<\/string>/, `<string>applinks:${host}</string>`));
  }
}

console.log(`✔ App configurada${prod ? ' para producción' : ''}\n  API: ${apiUrl || '(sin configurar)'}\n  Web: ${webUrl || '(sin configurar)'}`);
