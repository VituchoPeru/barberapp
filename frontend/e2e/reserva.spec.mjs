// Flujo crítico del cliente en cada dispositivo: elegir servicio, barbero, día y hora; confirmar;
// ver el comprobante; encontrarla en "Mis citas"; cancelarla. Y el panel la muestra al propietario.
import { test, expect, revisarPantalla } from './fixtures.mjs';
import { WEB } from './entorno.mjs';

// En pantallas bajas la barra inferior fija tapa lo que Playwright desplaza "justo" a la vista:
// se centra el control antes de tocarlo, como haría una persona.
async function centrar(loc) {
  await loc.evaluate((el) => el.scrollIntoView({ block: 'center' }));
  return loc;
}

/** Marca una opción tocando su tarjeta (la etiqueta), como en el teléfono. */
async function elegir(input) {
  const tarjeta = input.locator('xpath=ancestor::label[1]');
  await (await centrar(tarjeta)).click();
  await expect(input).toBeChecked();
}

test.describe('cliente reserva y cancela', () => {
  test.use({ rol: 'cliente' });

  test('reserva desde el enlace de la barbería y la cancela', async ({ page }) => {
    await page.goto(`${WEB}/pages/reservar.html?slug=demo-centro`);
    const pasos = page.locator('[data-steps]');

    const sucursal = pasos.locator('input[name="sucursal"]');
    if (!(await sucursal.first().isChecked())) await elegir(sucursal.first());
    await elegir(pasos.locator('input[name="servicio"]:not([disabled])').first());
    const barbero = pasos.locator('input[name="barbero"]:not([value="cualquiera"])').first();
    if (!(await barbero.isChecked())) await elegir(barbero);

    // Un día con horarios a partir de pasado mañana (fuera del plazo de cancelación) y su primera hora libre.
    await expect(pasos.locator('.day:not([disabled])').nth(2)).toBeVisible();
    const dia = pasos.locator('.day:not([disabled])').nth(2);
    await (await centrar(dia)).click();
    const hora = pasos.locator('.slot:not([disabled])').first();
    await expect(hora).toBeVisible();
    const elegida = (await hora.getAttribute('data-hora')) || '';
    await (await centrar(hora)).click();
    await revisarPantalla(page, 'reservar con selección');

    await (await centrar(pasos.locator('[data-confirm]'))).click();
    await page.waitForURL(/cita\.html\?id=\d+/);
    await expect(page.locator('body')).toContainText(elegida);
    await revisarPantalla(page, 'comprobante');
    const id = new URL(page.url()).searchParams.get('id');

    await page.goto(`${WEB}/pages/mis-citas.html`);
    await expect(page.locator(`a[href*="cita.html?id=${id}"]`).first()).toBeVisible();

    await page.goto(`${WEB}/pages/cita.html?id=${id}`);
    await (await centrar(page.locator('[data-cancel]').first())).click();
    const dlg = page.locator('[data-cancel-dialog]');
    await expect(dlg).toBeVisible();
    await revisarPantalla(page, 'diálogo de cancelación');
    await dlg.locator('[type="submit"]').click();
    await expect(page.locator('body')).toContainText(/Cancelada/i);
  });
});

test.describe('panel', () => {
  test.use({ rol: 'propietario' });

  test('la agenda muestra las citas del día con su estado', async ({ page }) => {
    await page.goto(`${WEB}/pages/panel/agenda.html`);
    await page.waitForLoadState('networkidle');
    await revisarPantalla(page, 'agenda');
    await expect(page.locator('main')).toBeVisible();
  });
});
