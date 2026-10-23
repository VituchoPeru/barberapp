# Documentación de BARBERAPP

Índice de toda la documentación. Si es tu primera vez, empieza por la sección que corresponde a tu caso.

| Soy… | Empieza por |
|---|---|
| Cliente, propietario, barbero o parte del equipo de la plataforma | [Manuales de uso](#manuales-de-uso) |
| Quien instala BARBERAPP en su computadora | [Manual de instalación](MANUAL_INSTALACION.md) |
| Quien publica BARBERAPP en un servidor | [Manual de despliegue](DESPLIEGUE.md) y [Copias de seguridad](BACKUPS.md) |
| Quien programa o integra con la API | [Arquitectura](ARQUITECTURA.md), [API](API.md) y [Decisiones técnicas](DECISIONES.md) |
| Quien compila las apps | [Guía Android](guias/ANDROID.md) y [Guía iOS](guias/IOS.md) |

## Entregables de la documentación final (Fase 16)

| # | Entregable | Documento | Cómo se mantiene |
|---|---|---|---|
| 1 | Manual de instalación | [MANUAL_INSTALACION.md](MANUAL_INSTALACION.md) | A mano |
| 2 | Manual del cliente | [manuales/CLIENTE.md](manuales/CLIENTE.md) | A mano; capturas con `capturas-manuales.mjs` |
| 3 | Manual del propietario | [manuales/PROPIETARIO.md](manuales/PROPIETARIO.md) | A mano; capturas con `capturas-manuales.mjs` |
| 4 | Manual del barbero | [manuales/BARBERO.md](manuales/BARBERO.md) | A mano; capturas con `capturas-manuales.mjs` |
| 5 | Manual del superadministrador | [manuales/SUPERADMIN.md](manuales/SUPERADMIN.md) | A mano; capturas con `capturas-manuales.mjs` |
| 6 | Documentación OpenAPI | [api/openapi.json](api/openapi.json), visor [api/index.html](api/index.html) y guía [API.md](API.md) | **Generada** desde las rutas, el `Validator` y las fichas de `fuente/api/` |
| 7 | Diccionario de datos | [DICCIONARIO_DATOS.md](DICCIONARIO_DATOS.md) | **Generado** desde la base migrada |
| 8 | Diagrama entidad-relación | [MODELO_ER.md](MODELO_ER.md) (Mermaid) y [diagramas/](diagramas/) (SVG) | Diagramas **generados**; reglas a mano |
| 9 | Diagrama de arquitectura | [ARQUITECTURA.md](ARQUITECTURA.md) | A mano (Mermaid) |
| 10 | Guía de compilación Android | [guias/ANDROID.md](guias/ANDROID.md) | A mano |
| 11 | Guía de compilación iOS | [guias/IOS.md](guias/IOS.md) | A mano |
| 12 | Manual de despliegue | [DESPLIEGUE.md](DESPLIEGUE.md) | A mano |
| 13 | Procedimiento de backups | [BACKUPS.md](BACKUPS.md) | A mano |
| 14 | Matriz de pruebas | [MATRIZ_PRUEBAS.md](MATRIZ_PRUEBAS.md) | **Generada** desde las pruebas y `fuente/matriz-pruebas.php` |
| 15 | Registro de decisiones técnicas | [DECISIONES.md](DECISIONES.md) | A mano, una fila por decisión |

## Manuales de uso

- [Cliente](manuales/CLIENTE.md): buscar una barbería, reservar, cambiar o cancelar una cita, avisos, cuenta y datos personales.
- [Propietario y administrador](manuales/PROPIETARIO.md): registro de la barbería, sucursales, servicios, barberos, horarios, agenda, cobros, clientes, reportes, avisos, plan y datos legales.
- [Barbero](manuales/BARBERO.md): agenda del día, atender, historial, horario y ausencias.
- [Superadministrador y operador](manuales/SUPERADMIN.md): barberías, planes, suscripciones, pagos, usuarios, privacidad, avisos, auditoría y configuración de la plataforma.

## Documentación técnica por tema

| Tema | Documento |
|---|---|
| Autenticación, roles, permisos y multiempresa | [AUTENTICACION.md](AUTENTICACION.md) |
| Matriz de acceso rol × ruta (generada por las pruebas) | [MATRIZ_ACCESO.md](MATRIZ_ACCESO.md) |
| Marketplace, perfil público y QR | [MARKETPLACE.md](MARKETPLACE.md) |
| Servicios, barberos y horarios | [SERVICIOS_HORARIOS.md](SERVICIOS_HORARIOS.md) |
| Motor de reservas | [RESERVAS.md](RESERVAS.md) |
| Panel administrativo | [PANEL.md](PANEL.md) |
| Panel del barbero | [PANEL_BARBERO.md](PANEL_BARBERO.md) |
| Avisos y recordatorios | [RECORDATORIOS.md](RECORDATORIOS.md) |
| Panel del superadministrador | [SUPERADMIN.md](SUPERADMIN.md) |
| Planes y monetización | [MONETIZACION.md](MONETIZACION.md) |
| Reportes y analítica | [REPORTES.md](REPORTES.md) |
| PWA, Android e iOS | [APPS_MOVILES.md](APPS_MOVILES.md) |
| Seguridad y protección de datos (Ley 29733) | [SEGURIDAD.md](SEGURIDAD.md) |
| Pruebas y control de calidad | [PRUEBAS.md](PRUEBAS.md) |
| Script SQL completo | [backend/database/schema.sql](../backend/database/schema.sql) |

## Documentación generada

Una parte de la documentación se genera desde el código y la base ([decisión 124](DECISIONES.md)):

```bash
cd backend
php bin/console docs:generar     # regenera OpenAPI, diccionario, diagramas ER, endpoints y errores de API.md, matriz de pruebas
php bin/console docs:verificar   # código 1 si algo quedó desactualizado (lo corre la CI)
```

Necesita la base local migrada (`php bin/console migrate`). Los bloques generados están entre `<!-- generado:… -->` y `<!-- /generado:… -->`: no los edites a mano, porque se reemplazan. Lo que se escribe a mano está en `docs/fuente/`:

- `fuente/api/*.php`: resumen, descripción y ejemplos de cada ruta de la API.
- `fuente/matriz-pruebas.php`: qué requisito cubre cada prueba.

`DocumentationTest` (PHPUnit) comprueba además que los enlaces entre documentos funcionen, que cada comando de la consola esté documentado y que los 15 entregables existan y estén en este índice.

Para repetir las capturas de los manuales (necesita MySQL, PHP y Chromium; usa una base aparte, `barberapp_manual`, con datos de demostración):

```bash
cd frontend
npm ci
node scripts/capturas-manuales.mjs
```

## Comandos de la consola

Todos se ejecutan desde `backend/` con `php bin/console <comando>`. Sin comando, la consola muestra la ayuda con todas las opciones.

| Comando | Qué hace |
|---|---|
| `db:create` | Crea la base `DB_DATABASE` si no existe |
| `migrate`, `migrate:status` | Aplica las migraciones pendientes y muestra su estado |
| `migrate:fresh [--seed\|--demo]` | Borra todas las tablas y migra de nuevo (solo local y pruebas) |
| `db:seed [--demo]` | Catálogo de roles, permisos, planes y plantillas (más datos de demostración en local) |
| `schema:build` | Regenera `database/schema.sql` desde las migraciones |
| `routes` | Lista las rutas de la API |
| `docs:generar`, `docs:verificar` | Regenera o comprueba la documentación generada. `docs:dictionary` es el nombre anterior de `docs:generar` |
| `key:generate [--force]` | Genera `APP_KEY` en `.env` |
| `auth:purge`, `reservas:purge` | Borran sesiones y tokens vencidos, y claves de idempotencia de más de 24 h (cron diario) |
| `notificaciones:procesar [--continuo]` | Worker de avisos: cada minuto por cron o permanente con systemd |
| `notificaciones:estado`, `notificaciones:purge` | Estado de la cola y limpieza de avisos antiguos |
| `push:probar <correo>` | Push de prueba a los teléfonos de esa cuenta |
| `suscripciones:vencer` | Cambios de plan, vencimientos, restricción y avisos (cron cada hora) |
| `seguridad:revisar` | Revisa la configuración de seguridad antes de publicar |
| `privacidad:retencion` | Aplica la retención de datos (cron diario) |
| `backup:clave`, `backup:crear`, `backup:listar`, `backup:verificar`, `backup:restaurar` | Copias cifradas: ver [BACKUPS.md](BACKUPS.md) |
| `backup:enviar`, `backup:remotas`, `backup:traer` | Copias fuera del servidor (S3 compatible): ver [BACKUPS.md](BACKUPS.md) |
| `monitor:revisar [--alertar]` | Estado de producción con alertas por correo (cada 5 minutos) |
| `mantenimiento:activar`, `mantenimiento:desactivar`, `mantenimiento:estado` | Modo mantenimiento: la API responde 503 durante una actualización |
| `logs:purge` | Borra registros antiguos (cron diario) |
| `plataforma:superadmin <correo>` | Crea la primera cuenta de superadministrador en producción |
| `despliegue:verificar` | Revisión previa a publicar una versión |
| `despliegue:config --salida=<carpeta>` | Genera la configuración de Nginx o Apache, PHP-FPM, cron, systemd y logrotate desde `.env` |
| `despliegue:frontend --version=<id>` | Escribe la URL de la API y la versión del service worker en el frontend publicado (lo usa `desplegar.sh`) |
