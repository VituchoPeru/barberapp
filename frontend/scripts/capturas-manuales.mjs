// Capturas de pantalla para los manuales de usuario (Fase 16): docs/manuales/img/*.jpg
//
//   node scripts/capturas-manuales.mjs          (desde frontend/, con MySQL y PHP disponibles)
//
// Crea una base propia (BARBERAPP_MANUAL_DB, por defecto barberapp_manual) con los datos de
// demostración del seeder, sin las cargas XSS de los E2E, levanta el servidor embebido de PHP
// con e2e/router.php e inicia sesión con cada cuenta demo. Las capturas muestran datos de
// demostración (barberías "Demo …", correos @barberapp.test), nunca datos reales.
import { execFileSync, spawn } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, request } from '@playwright/test';
import { CUENTAS, PASSWORD, WEB } from '../e2e/entorno.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const repo = join(here, '..', '..');
const salida = join(repo, 'docs', 'manuales', 'img');
const PORT = Number(process.env.MANUAL_PORT || 8095);
const BASE = `http://127.0.0.1:${PORT}`;
const API = `${BASE}/backend/public/api/v1`;
const env = {
  ...process.env,
  APP_ENV: 'local',
  APP_DEBUG: 'false',
  DB_DATABASE: process.env.BARBERAPP_MANUAL_DB || 'barberapp_manual',
  APP_URL: `${BASE}/backend/public`,
  FRONTEND_URL: `${BASE}${WEB}`,
  PUBLIC_BOOKING_URL: `${BASE}${WEB}`,
  MAIL_DRIVER: 'log',
  PUSH_DRIVER: 'log',
  FORCE_HTTPS: 'false',
  SESSION_SECURE_COOKIE: 'false',
  RATE_LIMIT_WRITES: '0',
  CORS_ALLOWED_ORIGINS: BASE,
};

/** Próximo día de lunes a sábado en Lima: el día en que el seeder deja citas (DemoSeeder::proximoDiaLaborable). */
function proximoDiaLaborable() {
  const hoy = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima' }).format(new Date());
  const d = new Date(`${hoy}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + 1);
  if (d.getUTCDay() === 0) d.setUTCDate(d.getUTCDate() + 1);
  return d.toISOString().slice(0, 10);
}
const DIA = proximoDiaLaborable();

const MOVIL = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true };
const ESCRITORIO = { viewport: { width: 1366, height: 860 }, deviceScaleFactor: 1 };

/** [archivo, ruta, opciones]. Opciones: accion (función sobre la página), completa (página entera). */
const CAPTURAS = {
  anonimo: { disp: MOVIL, paginas: [
    ['cliente-onboarding', 'pages/onboarding.html'],
    ['cliente-inicio', 'pages/inicio.html'],
    ['cliente-explorar', 'pages/explorar.html?q=corte'],
    ['cliente-barberia', 'pages/barberia.html?slug=demo-centro'],
    ['cliente-registro', 'pages/auth/registro.html'],
    ['cliente-login', 'pages/auth/login.html'],
    ['cliente-instalar', 'pages/instalar.html'],
    ['propietario-registro', 'pages/auth/registro.html', { accion: elegirPropietario }],
  ] },
  cliente: { disp: MOVIL, paginas: [
    ['cliente-reservar', 'pages/reservar.html?slug=demo-centro', { accion: armarReserva, completa: true }],
    ['cliente-comprobante', null, { accion: confirmarReserva }],
    ['cliente-mis-citas', 'pages/mis-citas.html'],
    ['cliente-cancelar', null, { accion: abrirCancelacion }],
    ['cliente-favoritos', 'pages/favoritos.html'],
    ['cliente-notificaciones', 'pages/notificaciones.html'],
    ['cliente-textos-legales', 'pages/auth/cuenta.html', { accion: (page) => page.locator('[data-legal-dialog][open]').waitFor() }],
    ['cliente-cuenta', null, { accion: aceptarTextos, completa: true }],
  ] },
  propietario: { disp: ESCRITORIO, paginas: [
    ['propietario-inicio', 'pages/panel/inicio.html'],
    ['propietario-agenda', `pages/panel/agenda.html?fecha=${DIA}`],
    ['propietario-cita', `pages/panel/agenda.html?fecha=${DIA}&cita=1`, { accion: (page) => page.locator('dialog[open]').first().waitFor() }],
    ['propietario-agenda-semana', `pages/panel/agenda.html?vista=semana&fecha=${DIA}`],
    ['propietario-barberos', 'pages/panel/barberos.html'],
    ['propietario-barbero', 'pages/panel/barbero.html?id=1'],
    ['propietario-servicios', 'pages/panel/servicios.html'],
    ['propietario-sucursales', 'pages/panel/sucursales.html'],
    ['propietario-clientes', 'pages/panel/clientes.html'],
    ['propietario-ausencias', 'pages/panel/ausencias.html'],
    ['propietario-reportes', 'pages/panel/reportes.html'],
    ['propietario-avisos', 'pages/panel/avisos.html'],
    ['propietario-perfil-publico', 'pages/panel/perfil-publico.html'],
    ['propietario-suscripcion', 'pages/panel/suscripcion.html'],
    ['propietario-configuracion', 'pages/panel/configuracion.html'],
  ] },
  barbero: { disp: MOVIL, paginas: [
    ['barbero-hoy', `pages/barbero/index.html?fecha=${DIA}`],
    ['barbero-citas', 'pages/barbero/citas.html'],
    ['barbero-horario', 'pages/barbero/horario.html'],
    ['barbero-ausencias', 'pages/barbero/ausencias.html'],
  ] },
  superadmin: { disp: ESCRITORIO, paginas: [
    ['superadmin-resumen', 'pages/plataforma/index.html'],
    ['superadmin-barberias', 'pages/plataforma/barberias.html'],
    ['superadmin-planes', 'pages/plataforma/planes.html'],
    ['superadmin-suscripciones', 'pages/plataforma/suscripciones.html'],
    ['superadmin-pagos', 'pages/plataforma/pagos.html'],
    ['superadmin-usuarios', 'pages/plataforma/usuarios.html'],
    ['superadmin-avisos', 'pages/plataforma/avisos.html'],
    ['superadmin-auditoria', 'pages/plataforma/auditoria.html'],
    ['superadmin-reportes', 'pages/plataforma/reportes.html'],
    ['superadmin-configuracion', 'pages/plataforma/configuracion.html'],
    ['superadmin-privacidad', 'pages/plataforma/privacidad.html'],
  ] },
};

async function centrar(loc) {
  await loc.evaluate((el) => el.scrollIntoView({ block: 'center' }));
  return loc;
}

async function elegirPropietario(page) {
  await page.locator('[data-tipo="propietario"]').click();
}

async function armarReserva(page) {
  const pasos = page.locator('[data-steps]');
  const sucursal = pasos.locator('input[name="sucursal"]').first();
  if (!(await sucursal.isChecked())) await sucursal.locator('xpath=ancestor::label[1]').click();
  await pasos.locator('input[name="servicio"]:not([disabled])').first().locator('xpath=ancestor::label[1]').click();
  const barbero = pasos.locator('input[name="barbero"]:not([value="cualquiera"])').first();
  await (await centrar(barbero.locator('xpath=ancestor::label[1]'))).click();
  const dia = pasos.locator('.day:not([disabled])').nth(2);
  await (await centrar(dia)).click();
  const hora = pasos.locator('.slot:not([disabled])').first();
  await hora.waitFor();
  await (await centrar(hora)).click();
}

async function aceptarTextos(page) {
  const dlg = page.locator('[data-legal-dialog]');
  await dlg.locator('input[name="acepta"]').check();
  await dlg.locator('[type="submit"]').click();
  await dlg.waitFor({ state: 'hidden' });
}

async function confirmarReserva(page) {
  await (await centrar(page.locator('[data-steps] [data-confirm]'))).click();
  await page.waitForURL(/cita\.html\?id=\d+/);
  page.citaId = new URL(page.url()).searchParams.get('id');
}

async function abrirCancelacion(page, ultimaCita) {
  await page.goto(`${BASE}${WEB}/pages/cita.html?id=${ultimaCita}`);
  await page.waitForLoadState('networkidle');
  await (await centrar(page.locator('[data-cancel]').first())).click();
  await page.locator('[data-cancel-dialog]').waitFor();
}

function php(args) {
  execFileSync(process.env.PHP_BINARY || 'php', args, { cwd: join(repo, 'backend'), env, stdio: 'pipe' });
}

async function esperarServidor() {
  for (let i = 0; i < 60; i++) {
    try {
      const r = await fetch(`${API}/health/live`);
      if (r.ok) return;
    } catch { /* aún no responde */ }
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error('El servidor PHP no respondió.');
}

async function main() {
  mkdirSync(salida, { recursive: true });
  const consola = join(repo, 'backend', 'bin', 'console');
  php([consola, 'db:create']);
  php([consola, 'migrate:fresh']);
  php([consola, 'db:seed', '--demo']);

  const servidor = spawn(process.env.PHP_BINARY || 'php', ['-S', `127.0.0.1:${PORT}`, '-t', '..', 'e2e/router.php'], {
    cwd: join(repo, 'frontend'), env, stdio: 'ignore',
  });
  const navegador = await chromium.launch();
  let hechas = 0;
  try {
    await esperarServidor();
    let ultimaCita = null;
    for (const [rol, { disp, paginas }] of Object.entries(CAPTURAS)) {
      const ctx = await navegador.newContext({ ...disp, baseURL: BASE, locale: 'es-PE', timezoneId: 'America/Lima', serviceWorkers: 'block' });
      if (rol !== 'anonimo') {
        const api = await request.newContext({ baseURL: BASE });
        const res = await api.post(`${API}/auth/login`, { data: { email: CUENTAS[rol], password: PASSWORD, canal: 'web' } });
        if (!res.ok()) throw new Error(`No se pudo iniciar sesión como ${rol}: ${res.status()}`);
        const { data } = await res.json();
        await ctx.addCookies((await api.storageState()).cookies);
        await ctx.addInitScript((t) => { try { sessionStorage.setItem('barberapp.csrf', t); } catch { /* nada */ } }, data.csrf_token);
        await api.dispose();
      }
      await ctx.addInitScript(() => { try { localStorage.setItem('barberapp.onboarding', 'visto'); } catch { /* nada */ } });
      const page = await ctx.newPage();
      for (const [archivo, ruta, opciones = {}] of paginas) {
        if (ruta) {
          await page.goto(`${BASE}${WEB}/${ruta}`, { waitUntil: 'domcontentloaded' });
          await page.waitForLoadState('networkidle');
        }
        if (opciones.accion) {
          await opciones.accion(page, ultimaCita);
          await page.waitForLoadState('networkidle');
          if (page.citaId) ultimaCita = page.citaId;
        }
        await page.locator('.skeleton:visible').first().waitFor({ state: 'detached', timeout: 15_000 }).catch(() => {});
        await page.waitForTimeout(300);
        if (opciones.completa) {
          // En la captura de página entera, las barras fijas quedarían pintadas a mitad de la imagen.
          await page.evaluate(() => {
            for (const el of document.querySelectorAll('body *')) {
              const pos = getComputedStyle(el).position;
              if (pos === 'fixed' || pos === 'sticky') el.style.position = 'static';
            }
            window.scrollTo(0, 0);
          });
        }
        await page.screenshot({ path: join(salida, `${archivo}.jpg`), type: 'jpeg', quality: 72, fullPage: Boolean(opciones.completa) });
        hechas++;
        console.log(`✓ ${archivo}`);
      }
      await ctx.close();
    }
  } finally {
    await navegador.close();
    servidor.kill();
  }
  console.log(`${hechas} capturas en docs/manuales/img/`);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
