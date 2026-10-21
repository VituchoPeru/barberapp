# Registro de decisiones técnicas

| # | Fecha | Decisión | Motivo |
|---|---|---|---|
| 001 | 2026-10-21 | PHP puro con router, pipeline de middleware y PDO propios; sin frameworks. | Restricción del proyecto. Código pequeño y auditable, compatible con PHP 8.0. |
| 002 | 2026-10-21 | El backend no necesita Composer para ejecutarse (autoloader PSR-4 propio). Composer solo para PHPUnit 9.6. | Instalación simple en XAMPP y hosting compartido. PHPUnit 9.6 es la última línea que soporta PHP 8.0. |
| 003 | 2026-10-21 | IDs `BIGINT UNSIGNED AUTO_INCREMENT`; código público aleatorio de 10 caracteres para reservas. | Índices compactos y FK compuestas eficientes; el código del comprobante no revela volumen. |
| 004 | 2026-10-21 | Aislamiento multiempresa con `UNIQUE (tenant_id, id)` + FK compuestas en tablas hijas. | La base de datos impide mezclar datos de barberías aunque falle una capa superior. |
| 005 | 2026-10-21 | Rol de plataforma en `usuarios.rol_plataforma_id`; roles de barbería en `membresias`. | El superadministrador no obtiene acceso implícito a datos privados de clientes de las barberías. |
| 006 | 2026-10-21 | `clientes` global + `cliente_tenants` por barbería; `reservas` referencia `cliente_tenants`. | Una persona usa una sola cuenta en varias barberías; cada barbería solo ve a los clientes vinculados. Permite clientes de mostrador sin cuenta. |
| 007 | 2026-10-21 | Fechas en `DATETIME` UTC (la conexión fija `time_zone = '+00:00'`); horarios semanales en `TIME` local + `zona_horaria` de sucursal. | Evita el límite de 2038 de `TIMESTAMP` y errores con horario de verano; Perú usa UTC-5 fijo pero el modelo soporta otras zonas. |
| 008 | 2026-10-21 | Descansos diarios = varios tramos en `horarios_barberos`; vacaciones/ausencias/feriados = `bloqueos_agenda`. | Modelo simple para calcular disponibilidad en la Fase 5. |
| 009 | 2026-10-21 | Prevención de doble reserva con `SELECT … FOR UPDATE` sobre la fila de `barberos` (candado estable de agenda). | MySQL no tiene restricciones de exclusión por rangos; bloquear una fila que siempre existe serializa las escrituras de la agenda de un barbero sin bloquear a otros. |
| 010 | 2026-10-21 | Columnas generadas `VIRTUAL` con índice único para unicidad condicional (suscripción vigente, políticas, preferencias). | MySQL permite múltiples `NULL` en índices únicos; la columna generada expresa la regla en la BD. |
| 011 | 2026-10-21 | `auditoria` sin FKs, solo inserciones. | El historial debe sobrevivir a la eliminación de usuarios o barberías. |
| 012 | 2026-10-21 | Migraciones en SQL plano con checksum; `schema.sql` generado y verificado por pruebas. | SQL legible por cualquier DBA; una prueba falla si `schema.sql` se desincroniza. |
| 013 | 2026-10-21 | Datos demo con nombres "Demo" y correos `@barberapp.test`. | Nunca se confunden con datos reales (requisito de no presentar datos ficticios como reales). |
