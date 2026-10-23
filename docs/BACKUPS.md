# Procedimiento de copias de seguridad

Qué se respalda, cuándo, dónde, cómo se comprueba que sirve y cómo se recupera BARBERAPP, desde un error puntual hasta la pérdida del servidor entero. Este documento reúne el procedimiento. Los detalles técnicos del cifrado están en [SEGURIDAD.md](SEGURIDAD.md#7-copias-de-seguridad) y la configuración del servidor en [DESPLIEGUE.md](DESPLIEGUE.md#10-copias-de-seguridad).

## Contenido

1. [Resumen](#1-resumen)
2. [Qué se respalda y qué no](#2-qué-se-respalda-y-qué-no)
3. [Puesta en marcha](#3-puesta-en-marcha)
4. [Calendario](#4-calendario)
5. [Comandos](#5-comandos)
6. [Comprobar que las copias sirven](#6-comprobar-que-las-copias-sirven)
7. [Recuperar](#7-recuperar)
8. [La clave `BACKUP_KEY`](#8-la-clave-backup_key)
9. [Retención y datos personales](#9-retención-y-datos-personales)
10. [Responsables y registro](#10-responsables-y-registro)
11. [Qué se probó](#11-qué-se-probó)

## 1. Resumen

| Capa | Dónde | Frecuencia | Comando |
|---|---|---|---|
| Copia cifrada de la base | En el servidor (`BACKUP_PATH`) | Diaria, 03:15 en Lima | `backup:crear` |
| Copia fuera del servidor | Bucket compatible con S3, otra cuenta o proveedor | Diaria, 03:45 en Lima | `backup:enviar` |
| Fotos de las barberías | El mismo bucket, sincronización incremental | Diaria, con la anterior | `backup:enviar` |
| Prueba de restauración | Base temporal que luego se borra | Semanal, domingo 04:30 en Lima | `backup:verificar` |
| Copia previa a cada actualización | En el servidor | En cada despliegue con migraciones | `desplegar.sh` (automático) |

`monitor:revisar` avisa por correo (`MONITOR_EMAIL`) si una de estas tareas deja de correr o falla.

Objetivos con esta configuración:

- **Pérdida máxima de datos (RPO):** 24 horas, que es el tiempo entre copias diarias. Para reducirlo, programa `backup:crear` y `backup:enviar` más seguido; cada copia tarda segundos con volúmenes pequeños.
- **Tiempo de recuperación (RTO):** el de instalar un servidor nuevo y restaurar, aproximadamente 1 hora con práctica (sección 7.3). Se mide en el simulacro.

## 2. Qué se respalda y qué no

| Dato | Se respalda | Cómo |
|---|---|---|
| Base de datos completa (las 44 tablas y `migraciones`) | Sí | `backup:crear`: instantánea consistente, gzip y AES-256-GCM |
| Fotos (logos, portadas, galería, barberos) en `MEDIA_PATH` (`shared/media` en el VPS) | Sí, fuera del servidor | `backup:enviar` con `BACKUP_S3_MEDIA=true` |
| `backend/.env` | **No** | Contiene secretos. Guarda una copia en el gestor de contraseñas del equipo (sección 8). |
| Clave de Firebase (`FCM_CREDENTIALS`) | **No** | Se vuelve a descargar de la consola de Firebase. |
| Código | No hace falta | Está en el repositorio. Cada versión desplegada se guarda en `releases/`. |
| Registros (`/var/log/barberapp`) | No | Se rotan y se borran a los `LOG_RETENTION_DAYS` días. |
| Correos y push de prueba en `storage/` | No | Solo existen en local. |

## 3. Puesta en marcha

Una sola vez, en cada servidor:

```bash
cd /var/www/barberapp/current/backend
sudo -u barberapp php bin/console backup:clave       # genera BACKUP_KEY en .env (no la reemplaza si existe)
```

1. **Guarda `BACKUP_KEY`** en el gestor de contraseñas del equipo, junto con la fecha. Sin ella las copias no se pueden restaurar (sección 8).
2. Configura el almacenamiento externo en `shared/.env`: variables `BACKUP_S3_ENDPOINT`, `BACKUP_S3_REGION`, `BACKUP_S3_BUCKET`, `BACKUP_S3_KEY`, `BACKUP_S3_SECRET` y `BACKUP_S3_PREFIX` (`.env.production.example` trae `barberapp/produccion`; vacío = `barberapp/<APP_ENV>`). Proveedores y permisos recomendados: [DESPLIEGUE.md](DESPLIEGUE.md#configurar-el-almacenamiento-externo).
3. Bucket **privado**, con **versionado**, con una regla de ciclo de vida de 30 días y una clave de acceso **sin permiso de borrar**. Si alguien toma el servidor, no puede eliminar las copias externas.
4. Comprueba la configuración:

   ```bash
   sudo -u barberapp php bin/console backup:crear
   sudo -u barberapp php bin/console backup:enviar
   sudo -u barberapp php bin/console backup:remotas
   sudo -u barberapp php bin/console backup:verificar
   sudo -u barberapp php bin/console seguridad:revisar   # avisa si falta BACKUP_KEY o el cron de copias
   ```

El cron lo instala `instalar-vps.sh` en `/etc/cron.d/barberapp`, generado por `despliegue:config`. En local con XAMPP se usa el Programador de tareas de Windows con los mismos comandos (ver [MANUAL_INSTALACION.md](MANUAL_INSTALACION.md#8-tareas-programadas-en-local)).

## 4. Calendario

Horas del servidor en UTC, como las deja `infrastructure/cron/barberapp.cron.tpl`:

```cron
15 8 * * *   backup:crear        # 03:15 en Lima
45 8 * * *   backup:enviar       # 03:45 en Lima
30 9 * * 0   backup:verificar    # domingo 04:30 en Lima
0 9 * * *    privacidad:retencion
```

Cada tarea deja un "latido" en `storage/cache/latidos/`. `monitor:revisar --alertar` (cada 5 minutos) envía un correo si la copia más reciente tiene más de 26 horas, o si `backup:enviar` o `backup:verificar` no terminan bien dentro de su plazo (un fallo no deja latido).

## 5. Comandos

Todos se ejecutan desde `backend/` (en producción, con `sudo -u barberapp`).

| Comando | Qué hace |
|---|---|
| `backup:clave [--force]` | Genera `BACKUP_KEY`. Con `--force` la reemplaza: las copias anteriores quedan con la clave vieja. |
| `backup:crear` | Copia cifrada con su `.sha256`. Conserva las `BACKUP_KEEP` (14) más recientes. |
| `backup:listar` | Copias guardadas en el servidor. |
| `backup:verificar [archivo]` | Restaura la última copia (o la indicada) en `BACKUP_VERIFY_DATABASE`, compara las filas de cada tabla con el manifiesto y borra la base temporal. Código de salida 1 si no coincide. |
| `backup:restaurar <archivo> --base=<nombre>` | Restaura en otra base. |
| `backup:restaurar <archivo> --base=<principal> --sobrescribir [--limpiar]` | Restaura sobre la base principal. `--limpiar` borra antes todas sus tablas, también las que no están en la copia. |
| `backup:restaurar <archivo> --solo-descifrar=<destino.sql.gz>` | Solo descifra la copia, para importarla a mano con `gunzip -c … \| mysql`. |
| `backup:enviar` | Sube las copias cifradas que falten y sincroniza las fotos. |
| `backup:remotas` | Copias guardadas fuera del servidor. |
| `backup:traer [archivo]` | Baja una copia externa (la última si no se indica) y verifica su SHA-256. |

`<archivo>` puede ser la ruta completa o **solo el nombre** que muestran `backup:listar` y `backup:traer` (por ejemplo `barberapp-20261023-023234.sql.gz.enc`). El nombre se busca en `BACKUP_PATH`.

## 6. Comprobar que las copias sirven

Una copia que nunca se restauró no es una copia. Hay tres niveles de comprobación:

1. **Cada copia:** al crearla se guarda su SHA-256 y un manifiesto con las filas de cada tabla. Al subirla, el proveedor rechaza el archivo si no coincide con el SHA-256. Cada bloque de 1 MiB está autenticado con AES-GCM, así que un archivo alterado o truncado no se restaura.
2. **Cada semana:** `backup:verificar` restaura la última copia en una base temporal y compara las filas por tabla.
3. **Cada trimestre, y antes de abrir al público:** un **simulacro de desastre** completo en un servidor de prueba (sección 7.3) usando una copia **traída del bucket**, no la del servidor. Anota cuánto tardó (es tu RTO) y cualquier paso que faltara en este documento.

## 7. Recuperar

Antes de restaurar sobre producción, **siempre** restaura primero en una base aparte y revisa los datos. La restauración sobre la base principal reemplaza todo lo que haya.

### 7.1 Recuperar datos puntuales (un error humano)

Por ejemplo, alguien borró una sucursal o anuló cobros por error. No restaures toda la base, porque perderías lo que pasó después de la copia.

```bash
sudo -u barberapp php bin/console backup:listar
sudo -u barberapp php bin/console backup:restaurar barberapp-AAAAMMDD-HHMMSS.sql.gz.enc --base=barberapp_recuperada
```

Consulta `barberapp_recuperada`, copia a mano solo las filas necesarias a la base principal (con cuidado de las claves foráneas y del `tenant_id`), registra lo hecho y borra la base auxiliar:

```sql
DROP DATABASE barberapp_recuperada;
```

La propia restauración queda en la auditoría (`sistema.backup_restaurado`).

### 7.2 Restaurar toda la base en el mismo servidor

Cuando la base quedó inutilizable, por ejemplo por una migración fallida que el rollback automático no cubrió:

```bash
cd /var/www/barberapp/current/backend
sudo -u barberapp php bin/console mantenimiento:activar --motivo="Restaurando datos"
sudo systemctl stop barberapp-worker
sudo -u barberapp php bin/console backup:verificar barberapp-AAAAMMDD-HHMMSS.sql.gz.enc
sudo -u barberapp php bin/console backup:restaurar barberapp-AAAAMMDD-HHMMSS.sql.gz.enc --base=barberapp --sobrescribir --limpiar
sudo -u barberapp php bin/console migrate
sudo systemctl start barberapp-worker
sudo -u barberapp php bin/console mantenimiento:desactivar
sudo -u barberapp php bin/console monitor:revisar
```

Si el problema llegó con una versión nueva del código, vuelve también a la versión anterior con `rollback.sh` (ver [DESPLIEGUE.md](DESPLIEGUE.md#14-rollback)).

### 7.3 Desastre: el servidor se perdió

Necesitas `BACKUP_KEY`, las variables `BACKUP_S3_*` y el resto del `.env` desde el gestor de contraseñas.

1. Crea un VPS nuevo e instálalo con `instalar-vps.sh`, con los mismos dominios (ver [DESPLIEGUE.md](DESPLIEGUE.md#4-instalación-en-un-vps)).
2. En `/var/www/barberapp/shared/.env` copia la **`BACKUP_KEY` guardada**, las variables `BACKUP_S3_*` y los demás secretos (`APP_KEY`, correo, Mercado Pago, Firebase). Si no recuperas la misma `APP_KEY`, las sesiones abiertas dejan de valer: las personas solo tienen que volver a iniciar sesión.
3. Trae la última copia externa y pruébala:

   ```bash
   cd /var/www/barberapp/current/backend
   sudo -u barberapp php bin/console backup:remotas
   sudo -u barberapp php bin/console backup:traer
   sudo -u barberapp php bin/console backup:verificar barberapp-AAAAMMDD-HHMMSS.sql.gz.enc
   ```

4. Restaura con la API en mantenimiento:

   ```bash
   sudo -u barberapp php bin/console mantenimiento:activar --motivo="Recuperación"
   sudo -u barberapp php bin/console backup:restaurar barberapp-AAAAMMDD-HHMMSS.sql.gz.enc --base=barberapp --sobrescribir --limpiar
   sudo -u barberapp php bin/console migrate
   sudo -u barberapp php bin/console mantenimiento:desactivar
   ```

5. Recupera las fotos con la herramienta del proveedor. La ruta es `<BACKUP_S3_PREFIX>/media/`, por ejemplo:

   ```bash
   aws s3 sync s3://<bucket>/barberapp/produccion/media/ /var/www/barberapp/shared/media/ --endpoint-url <BACKUP_S3_ENDPOINT>
   sudo chown -R barberapp:barberapp /var/www/barberapp/shared/media
   ```

6. Cambia el DNS a la IP nueva y espera a que se emitan los certificados.
7. Revisa con `monitor:revisar`, `curl https://api.tu-dominio.pe/api/v1/health` y un recorrido con un usuario real: buscar, reservar y ver la agenda.
8. Avisa a las barberías: las citas o cambios hechos después de la copia restaurada se perdieron y deben revisarlos.

### 7.4 Si la copia está dañada

`backup:verificar` y `backup:restaurar` se niegan a usar un archivo cuyo SHA-256 o cuyos bloques cifrados no coinciden. Usa la copia anterior (`backup:listar` en el servidor, `backup:remotas` fuera). Con el bucket versionado, puedes recuperar también versiones anteriores de un objeto desde la consola del proveedor.

## 8. La clave `BACKUP_KEY`

- Es la única forma de leer las copias. **Si se pierde, las copias no sirven**, y no hay forma de recuperarlas.
- Guárdala en el gestor de contraseñas del equipo (al menos dos personas con acceso) y, si es posible, también impresa en un lugar seguro.
- **No** la subas al bucket ni al repositorio. Por eso el proveedor externo nunca ve datos personales.
- **Cambiar la clave:** `backup:clave --force` genera una nueva. Las copias anteriores siguen necesitando la vieja: guarda las dos, anotando desde qué fecha rige cada una, hasta que las copias viejas venzan (30 días en el bucket, 14 copias en el servidor).

## 9. Retención y datos personales

| Dónde | Cuánto | Cómo |
|---|---|---|
| Servidor | `BACKUP_KEEP` = 14 copias | Rotación al crear |
| Fuera del servidor | 30 días | Regla de ciclo de vida del bucket (recomendado, con `BACKUP_REMOTE_KEEP=0`) o `BACKUP_REMOTE_KEEP=N` si el servidor puede borrar |
| Fotos borradas | Se borran del bucket en la siguiente sincronización, o por la regla de ciclo de vida si la clave no puede borrar | `backup:enviar` |

Cuando una persona elimina su cuenta, sus datos desaparecen de la base al instante y de las copias **cuando estas vencen**, en un máximo de 30 días. Así lo dice la página pública *Qué se borra y qué se conserva*. Si se restaura una copia anterior a una eliminación, hay que **volver a aplicar las eliminaciones posteriores**: las constancias quedan en *Plataforma → Privacidad* (solicitudes `cancelacion` atendidas). Revísalas después de cualquier restauración completa.

## 10. Responsables y registro

| Tarea | Responsable | Frecuencia |
|---|---|---|
| Revisar los correos de `monitor:revisar` | Quien esté de guardia | Al llegar |
| Comprobar que `backup:remotas` muestra la copia del día | Responsable técnico | Semanal |
| Simulacro de desastre (7.3) | Responsable técnico, con otra persona | Trimestral y antes de abrir al público |
| Verificar que `BACKUP_KEY` está en el gestor y coincide | Responsable técnico | En cada simulacro |

Anota cada simulacro y cada restauración real: fecha, copia usada, tiempo total, problemas y cambios a este documento. Las restauraciones quedan además en la auditoría (`sistema.backup_restaurado`, `sistema.backup_verificado`).

## 11. Qué se probó

**Pruebas automáticas:**

- `tests/Integration/Security/BackupTest.php`: copia cifrada que restaura las mismas filas y datos binarios, columnas generadas, rechazo de copias alteradas o con otra clave, rotación, clave obligatoria en producción y nombres de copia sin carpeta.
- `tests/Integration/Ops/OffsiteBackupTest.php`: subida, listado, descarga con SHA-256 y sincronización de fotos contra un simulador de S3.

**Prueba manual en este entorno** (23 de octubre de 2026, MySQL 8.0.46 y PHP 8.3.6, base de demostración):

| Paso | Resultado |
|---|---|
| `backup:crear` | 45 tablas, 371 filas, archivo cifrado con su SHA-256 |
| `backup:verificar` | Restauración de prueba en `barberapp_restore_check`: 45 tablas, 371 filas, 1.24 s. *Copia verificada* |
| `backup:restaurar … --base=barberapp_recuperada` | Restaurada: 45 tablas, 371 filas |
| `backup:verificar <solo el nombre>` | Fallaba con *No existe la copia* aunque `backup:traer` sugiere ese comando. **Se corrigió en la Fase 16**: los nombres se buscan en `BACKUP_PATH` (decisión 127). |

**No probado aquí:** un proveedor S3 real (solo el simulador) ni un simulacro de desastre en un VPS real. Ambos quedan en la lista de salida a producción de [DESPLIEGUE.md](DESPLIEGUE.md#16-lista-de-salida-a-producción).
