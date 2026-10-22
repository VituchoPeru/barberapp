// Pruebas E2E multiplataforma de BARBERAPP (Fase 14). Ver docs/PRUEBAS.md.
//
//   npm run e2e         Chromium: escritorio, Android, pantalla pequeña (320 px) y tablets
//   npm run e2e:todos   además Safari (WebKit) en iPhone, iPad y Mac, y Firefox
//                       (una vez: npx playwright install chromium webkit firefox)
//
// Levanta el servidor embebido de PHP con e2e/router.php sobre una base propia (barberapp_e2e).
import { defineConfig, devices } from '@playwright/test';
import { BASE_URL, PORT, serverEnv } from './e2e/entorno.mjs';

const chromium = [
  { name: 'chrome-escritorio', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } },
  { name: 'android-pixel-7', use: { ...devices['Pixel 7'] } },
  // iPhone SE de 1.ª generación (320×568) con Chromium: la pantalla más pequeña soportada.
  { name: 'pantalla-pequena-320', use: { ...devices['iPhone SE'], browserName: 'chromium', defaultBrowserType: 'chromium' } },
  { name: 'tablet-galaxy-tab', use: { ...devices['Galaxy Tab S4'] } },
  { name: 'tablet-horizontal', use: { ...devices['Galaxy Tab S4 landscape'] } },
];

const otros = [
  // Safari: el mismo motor (WebKit) que usa Safari en iOS y macOS.
  { name: 'safari-iphone-13', use: { ...devices['iPhone 13'] } },
  { name: 'safari-ipad', use: { ...devices['iPad (gen 7)'] } },
  { name: 'safari-mac', use: { ...devices['Desktop Safari'], viewport: { width: 1440, height: 900 } } },
  { name: 'firefox-escritorio', use: { ...devices['Desktop Firefox'], viewport: { width: 1440, height: 900 } } },
];

export default defineConfig({
  testDir: './e2e',
  testMatch: /.*\.spec\.mjs/,
  globalSetup: './e2e/global-setup.mjs',
  fullyParallel: false,
  workers: 1, // una sola base compartida: las pruebas que reservan no deben pisarse
  retries: process.env.CI ? 1 : 0,
  timeout: 45_000,
  expect: { timeout: 10_000 },
  reporter: process.env.CI ? [['list'], ['html', { open: 'never', outputFolder: 'e2e-informe' }]] : [['list']],
  outputDir: 'e2e-resultados',
  use: {
    baseURL: BASE_URL,
    locale: 'es-PE',
    timezoneId: 'America/Lima',
    serviceWorkers: 'block', // la PWA se prueba aparte (npm run verificar); aquí, la red real
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [...chromium, ...otros],
  webServer: {
    command: `php -S 127.0.0.1:${PORT} -t .. e2e/router.php`,
    url: `${BASE_URL}/backend/public/api/v1/health/live`,
    reuseExistingServer: !process.env.CI,
    env: { ...process.env, ...serverEnv },
    stdout: 'ignore',
    stderr: 'ignore', // php -S escribe cada petición en stderr; los fallos se ven como respuestas 5xx
    timeout: 30_000,
  },
});
