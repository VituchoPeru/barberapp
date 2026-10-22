#!/usr/bin/env node
/**
 * Comprobaciones estáticas de la PWA y del empaquetado (Fase 12):  npm run verificar
 *
 * - Todo archivo del núcleo del service worker existe (si falta uno, la instalación falla).
 * - Los iconos del manifiesto existen y miden lo que declaran.
 * - Cada pantalla HTML enlaza el manifiesto, carga js/shell.js y no usa fuentes externas.
 * - Los módulos JS importan archivos que existen.
 * - Las versiones de Capacitor y sus plugins están fijadas (sin ^ ni ~).
 * Sale con código 1 si algo falla.
 */
import { readFile, readdir, access } from 'node:fs/promises';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const WWW = join(ROOT, 'www');
const errors = [];
const exists = (p) => access(p).then(() => true, () => false);

async function walk(dir, ext) {
  const out = [];
  for (const e of await readdir(dir, { withFileTypes: true })) {
    const p = join(dir, e.name);
    if (e.isDirectory()) out.push(...await walk(p, ext));
    else if (p.endsWith(ext)) out.push(p);
  }
  return out;
}

// 1. Núcleo del service worker
const sw = await readFile(join(WWW, 'sw.js'), 'utf8');
const core = JSON.parse(sw.match(/const CORE_FILES = (\[[\s\S]*?\]);/)[1].replace(/'/g, '"').replace(/,\s*\]/, ']'));
for (const f of core) {
  if (f === './') continue;
  if (!await exists(join(WWW, f))) errors.push(`sw.js: falta ${f}`);
}

// 2. Manifiesto
const manifest = JSON.parse(await readFile(join(WWW, 'manifest.webmanifest'), 'utf8'));
for (const k of ['name', 'short_name', 'start_url', 'scope', 'display', 'icons', 'theme_color', 'background_color']) {
  if (!manifest[k]) errors.push(`manifest: falta "${k}"`);
}
const sharp = await import('sharp').then((m) => m.default).catch(() => null);
for (const icon of manifest.icons) {
  const p = join(WWW, icon.src);
  if (!await exists(p)) { errors.push(`manifest: falta el icono ${icon.src}`); continue; }
  if (sharp) {
    const { width, height } = await sharp(p).metadata();
    if (`${width}x${height}` !== icon.sizes) errors.push(`manifest: ${icon.src} mide ${width}x${height}, declara ${icon.sizes}`);
  }
}
if (!manifest.icons.some((i) => i.purpose === 'maskable')) errors.push('manifest: falta un icono "maskable"');
if (!manifest.icons.some((i) => i.sizes === '512x512')) errors.push('manifest: falta el icono de 512x512');

// 3. Pantallas HTML
for (const f of await walk(WWW, '.html')) {
  const rel = relative(WWW, f);
  if (rel === 'offline.html') continue; // autocontenida a propósito
  const html = await readFile(f, 'utf8');
  if (!/rel="manifest"/.test(html)) errors.push(`${rel}: no enlaza el manifiesto`);
  if (!/js\/shell\.js"/.test(html)) errors.push(`${rel}: no carga js/shell.js`);
  if (/fonts\.(googleapis|gstatic)\.com/.test(html)) errors.push(`${rel}: usa Google Fonts (la fuente va en assets/fonts)`);
  for (const [, src] of html.matchAll(/(?:src|href)="([^"#?:]+\.(?:js|css|webmanifest|png|svg))"/g)) {
    if (!await exists(join(dirname(f), src))) errors.push(`${rel}: no existe ${src}`);
  }
}

// 4. Importaciones de los módulos
for (const f of await walk(join(WWW, 'js'), '.js')) {
  const js = await readFile(f, 'utf8');
  for (const [, spec] of js.matchAll(/(?:import\s[^'"]*?from\s*|import\(\s*)'(\.{1,2}\/[^']+)'/g)) {
    if (!await exists(join(dirname(f), spec))) errors.push(`${relative(WWW, f)}: importa ${spec}, que no existe`);
  }
}

// 5. Versiones fijadas
const pkg = JSON.parse(await readFile(join(ROOT, 'package.json'), 'utf8'));
for (const [name, v] of Object.entries({ ...pkg.dependencies, ...pkg.devDependencies })) {
  if (/^[\^~]|^latest$|\*/.test(v)) errors.push(`package.json: ${name}@${v} debe fijar una versión exacta`);
}

if (errors.length) {
  console.error(`✖ ${errors.length} problema(s):\n  ` + errors.join('\n  '));
  process.exit(1);
}
console.log(`✔ PWA verificada: ${core.length} archivos en el núcleo del service worker, ${manifest.icons.length} iconos, pantallas y módulos sin referencias rotas.`);
