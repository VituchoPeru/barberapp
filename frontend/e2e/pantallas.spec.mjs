// Todas las pantallas de BARBERAPP, con la cuenta que las usa, en cada dispositivo del proyecto.
// Cada una debe cargar sin errores, sin desborde horizontal y sin pintar como HTML los textos con
// etiquetas que global-setup sembró en los datos (XSS).
import { test, revisarPantalla } from './fixtures.mjs';
import { WEB } from './entorno.mjs';

const PANTALLAS = {
  anonimo: [
    'index.html', 'pages/onboarding.html', 'pages/inicio.html', 'pages/explorar.html', 'pages/explorar.html?q=corte',
    'pages/barberia.html?slug=demo-centro', 'pages/barberia.html?slug=no-existe', 'pages/reservar.html?slug=demo-centro',
    'pages/instalar.html', 'pages/legal/terminos.html', 'pages/legal/privacidad.html', 'pages/legal/eliminar-cuenta.html',
    'pages/auth/login.html', 'pages/auth/registro.html', 'pages/auth/olvide.html', 'pages/auth/restablecer.html?token=invalido',
    'pages/auth/verificar.html?token=invalido', 'pages/asistencia.html', 'offline.html',
  ],
  cliente: [
    'pages/inicio.html', 'pages/mis-citas.html', 'pages/favoritos.html', 'pages/notificaciones.html', 'pages/auth/cuenta.html',
    'pages/reservar.html?slug=demo-centro', 'pages/barberia.html?slug=demo-centro',
  ],
  propietario: [
    'pages/panel/inicio.html', 'pages/panel/agenda.html', 'pages/panel/agenda.html?vista=semana', 'pages/panel/barberos.html', 'pages/panel/barbero.html?id=1',
    'pages/panel/servicios.html', 'pages/panel/sucursales.html', 'pages/panel/clientes.html', 'pages/panel/ausencias.html',
    'pages/panel/reportes.html', 'pages/panel/avisos.html', 'pages/panel/perfil-publico.html', 'pages/panel/suscripcion.html',
    'pages/panel/configuracion.html',
  ],
  administrador: ['pages/panel/inicio.html', 'pages/panel/agenda.html', 'pages/panel/clientes.html'],
  barbero: ['pages/barbero/index.html', 'pages/barbero/citas.html', 'pages/barbero/horario.html', 'pages/barbero/ausencias.html'],
  superadmin: [
    'pages/plataforma/index.html', 'pages/plataforma/barberias.html', 'pages/plataforma/planes.html', 'pages/plataforma/suscripciones.html',
    'pages/plataforma/pagos.html', 'pages/plataforma/usuarios.html', 'pages/plataforma/avisos.html', 'pages/plataforma/auditoria.html',
    'pages/plataforma/reportes.html', 'pages/plataforma/configuracion.html', 'pages/plataforma/privacidad.html',
  ],
};

for (const [rol, rutas] of Object.entries(PANTALLAS)) {
  test.describe(`${rol}`, () => {
    test.use({ rol: rol === 'anonimo' ? null : rol });
    for (const ruta of rutas) {
      test(ruta, async ({ page }) => {
        const res = await page.goto(`${WEB}/${ruta}`, { waitUntil: 'domcontentloaded' });
        test.expect(res?.status()).toBeLessThan(400);
        await page.waitForLoadState('networkidle');
        if (rol !== 'anonimo') {
          test.expect(page.url(), 'La sesión del rol no debe mandar al login').not.toContain('/auth/login');
        }
        await revisarPantalla(page, `${rol} ${ruta}`);
      });
    }
  });
}
