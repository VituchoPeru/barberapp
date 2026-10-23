// Prepara una base exclusiva para las pruebas E2E (Fase 14) y una sesión por rol.
//   1. Crea y migra BARBERAPP_E2E_DB (por defecto barberapp_e2e) con los datos demo.
//   2. Agrega una carga XSS a los textos visibles (sembrar-xss.php).
//   3. Inicia sesión con cada cuenta demo y guarda las cookies en e2e/.auth/<rol>.json.
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { request } from '@playwright/test';
import { BASE_URL, API_URL, CUENTAS, PASSWORD, serverEnv } from './entorno.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const repo = join(here, '..', '..');
const consola = join(repo, 'backend', 'bin', 'console');

function php(args) {
  execFileSync(process.env.PHP_BINARY || 'php', args, { cwd: join(repo, 'backend'), env: { ...process.env, ...serverEnv }, stdio: 'pipe' });
}

export default async function globalSetup() {
  php([consola, 'db:create']);
  php([consola, 'migrate:fresh']);
  php([consola, 'db:seed', '--demo']);
  php([join(here, 'sembrar-xss.php')]);

  mkdirSync(join(here, '.auth'), { recursive: true });
  for (const [rol, email] of Object.entries(CUENTAS)) {
    const ctx = await request.newContext({ baseURL: BASE_URL });
    const res = await ctx.post(`${API_URL}/auth/login`, { data: { email, password: PASSWORD, canal: 'web' } });
    if (!res.ok()) throw new Error(`No se pudo iniciar sesión como ${rol}: ${res.status()} ${await res.text()}`);
    const { data } = await res.json();
    const state = await ctx.storageState();
    // El frontend guarda el token CSRF en sessionStorage; si falta, lo pide a /auth/csrf solo.
    writeFileSync(join(here, '.auth', `${rol}.json`), JSON.stringify({ ...state, csrf: data.csrf_token }));
    await ctx.dispose();
  }
}
