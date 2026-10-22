#!/usr/bin/env node
/**
 * Genera los iconos y pantallas de inicio de la PWA, Android e iOS a partir de un solo
 * archivo: resources/emblema.svg (el emblema dorado de BARBERAPP) sobre el negro #111111.
 *
 *   npm run recursos
 *
 * Herramienta de desarrollo (Node + sharp); no forma parte de la app publicada. Si cambias el
 * emblema, ejecútalo de nuevo y confirma los PNG generados en el repositorio.
 */
import { readFile, writeFile, mkdir, access } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const BG = '#111111';

const source = await readFile(join(ROOT, 'resources/emblema.svg'), 'utf8');
const defs = source.match(/<defs>[\s\S]*?<\/defs>/)[0];
const shapes = source
  .replace(/<\?xml[^>]*>/, '')
  .replace(/<svg[^>]*>/, '')
  .replace(/<\/svg>\s*$/, '')
  .replace(/<!--[\s\S]*?-->/g, '')
  .replace(defs, '')
  .trim();

// El emblema ocupa x 22–98 e y 12–112 del lienzo de 120: centro (60, 62) y 100 de alto.
const CX = 60;
const CY = 62;
const H = 100;

/**
 * SVG de un icono cuadrado de `size` px.
 * scale: alto del emblema como fracción del lado. bg: color o null (transparente).
 * shape: square | rounded | circle. mono: emblema blanco (iconos monocromos y de notificación).
 */
function iconSvg({ size, scale, bg = BG, shape = 'square', mono = false, width = size, height = size }) {
  const s = (scale * Math.min(width, height)) / H;
  const body = mono ? shapes.replace(/url\(#g\)/g, '#FFFFFF') : shapes;
  const r = shape === 'rounded' ? Math.round(Math.min(width, height) * 0.22) : 0;
  const back = !bg ? '' : shape === 'circle'
    ? `<circle cx="${width / 2}" cy="${height / 2}" r="${Math.min(width, height) / 2}" fill="${bg}"/>`
    : `<rect width="${width}" height="${height}" rx="${r}" fill="${bg}"/>`;
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">${mono ? '' : defs}${back}`
    + `<g transform="translate(${width / 2} ${height / 2}) scale(${s}) translate(${-CX} ${-CY})">${body}</g></svg>`;
}

async function png(path, opts, { flatten = false } = {}) {
  const out = join(ROOT, path);
  await mkdir(dirname(out), { recursive: true });
  // El SVG ya mide lo mismo que el PNG final: se rasteriza a tamaño real (vectorial, sin reescalar).
  let img = sharp(Buffer.from(iconSvg(opts)), { limitInputPixels: false });
  if (flatten) img = img.flatten({ background: BG }); // iOS no acepta transparencia en el icono
  await img.png({ compressionLevel: 9 }).toFile(out);
  return path;
}

const exists = (p) => access(join(ROOT, p)).then(() => true, () => false);
const made = [];

// ------------------------------------------------------------------ PWA (www/assets/icons)
const web = 'www/assets/icons';
made.push(await png(`${web}/icon-192.png`, { size: 192, scale: 0.62, shape: 'rounded' }));
made.push(await png(`${web}/icon-512.png`, { size: 512, scale: 0.62, shape: 'rounded' }));
// "maskable": el sistema recorta la forma; el emblema queda dentro del círculo seguro del 80 %.
made.push(await png(`${web}/icon-maskable-192.png`, { size: 192, scale: 0.5 }));
made.push(await png(`${web}/icon-maskable-512.png`, { size: 512, scale: 0.5 }));
made.push(await png(`${web}/apple-touch-icon.png`, { size: 180, scale: 0.6 }, { flatten: true }));
made.push(await png(`${web}/favicon-32.png`, { size: 32, scale: 0.78, shape: 'rounded' }));
await writeFile(join(ROOT, `${web}/favicon.svg`), iconSvg({ size: 64, scale: 0.74, shape: 'rounded' }) + '\n');
made.push(`${web}/favicon.svg`);

// ------------------------------------------------------------------ Android
if (await exists('android/app/src/main/res')) {
  const res = 'android/app/src/main/res';
  const dens = { mdpi: 1, hdpi: 1.5, xhdpi: 2, xxhdpi: 3, xxxhdpi: 4 };
  for (const [d, k] of Object.entries(dens)) {
    made.push(await png(`${res}/mipmap-${d}/ic_launcher.png`, { size: 48 * k, scale: 0.6, shape: 'rounded' }));
    made.push(await png(`${res}/mipmap-${d}/ic_launcher_round.png`, { size: 48 * k, scale: 0.56, shape: 'circle' }));
    // Icono adaptable (Android 8+): 108 dp con zona segura de 66 dp; capa monocroma para los
    // iconos temáticos de Android 13+.
    made.push(await png(`${res}/mipmap-${d}/ic_launcher_foreground.png`, { size: 108 * k, scale: 0.44, bg: null }));
    made.push(await png(`${res}/mipmap-${d}/ic_launcher_monochrome.png`, { size: 108 * k, scale: 0.44, bg: null, mono: true }));
    // Icono pequeño de las notificaciones: blanco sobre transparente (Android lo tiñe).
    made.push(await png(`${res}/drawable-${d}/ic_stat_barberapp.png`, { size: 24 * k, scale: 0.92, bg: null, mono: true }));
    // Pantalla de inicio de Android 12+: icono de 288 dp dentro de un círculo de 192 dp.
    made.push(await png(`${res}/drawable-${d}/splash_icon.png`, { size: 288 * k, scale: 0.42, bg: null }));
  }
  // Pantalla de inicio de Android 11 o anterior (imagen completa).
  const legacy = {
    'drawable/splash.png': [480, 320],
    'drawable-port-mdpi/splash.png': [320, 480], 'drawable-port-hdpi/splash.png': [480, 800],
    'drawable-port-xhdpi/splash.png': [720, 1280], 'drawable-port-xxhdpi/splash.png': [960, 1600],
    'drawable-port-xxxhdpi/splash.png': [1280, 1920],
    'drawable-land-mdpi/splash.png': [480, 320], 'drawable-land-hdpi/splash.png': [800, 480],
    'drawable-land-xhdpi/splash.png': [1280, 720], 'drawable-land-xxhdpi/splash.png': [1600, 960],
    'drawable-land-xxxhdpi/splash.png': [1920, 1280],
  };
  for (const [p, [w, h]] of Object.entries(legacy)) {
    made.push(await png(`${res}/${p}`, { width: w, height: h, size: Math.max(w, h), scale: 0.26 }, { flatten: true }));
  }
  await writeFile(join(ROOT, `${res}/values/ic_launcher_background.xml`),
    `<?xml version="1.0" encoding="utf-8"?>\n<resources>\n    <color name="ic_launcher_background">${BG}</color>\n</resources>\n`);
  const adaptive = `<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@color/ic_launcher_background"/>
    <foreground android:drawable="@mipmap/ic_launcher_foreground"/>
    <monochrome android:drawable="@mipmap/ic_launcher_monochrome"/>
</adaptive-icon>
`;
  await writeFile(join(ROOT, `${res}/mipmap-anydpi-v26/ic_launcher.xml`), adaptive);
  await writeFile(join(ROOT, `${res}/mipmap-anydpi-v26/ic_launcher_round.xml`), adaptive);
}

// ------------------------------------------------------------------ iOS
if (await exists('ios/App/App/Assets.xcassets')) {
  const x = 'ios/App/App/Assets.xcassets';
  made.push(await png(`${x}/AppIcon.appiconset/AppIcon-512@2x.png`, { size: 1024, scale: 0.6 }, { flatten: true }));
  for (const n of ['splash-2732x2732.png', 'splash-2732x2732-1.png', 'splash-2732x2732-2.png']) {
    made.push(await png(`${x}/Splash.imageset/${n}`, { size: 2732, scale: 0.2 }, { flatten: true }));
  }
}

console.log(`Recursos generados (${made.length}):\n  ` + made.join('\n  '));
