<?php

declare(strict_types=1);

/**
 * Agrega una carga XSS al final de los textos que se ven en pantalla (datos demo de demo-centro,
 * sus clientes, citas y avisos, y los nombres de las personas). Las pruebas E2E fallan si alguna
 * pantalla la convierte en HTML: todas buscan el elemento [data-xss] después de cargar.
 *
 * Solo corre sobre una base cuyo nombre termina en _e2e (la que crea global-setup.mjs).
 */

use App\Core\Database;

require dirname(__DIR__, 2) . '/backend/bootstrap/app.php';

$db = Database::connection();
$name = (string) $db->query('SELECT DATABASE()')->fetchColumn();
if (!str_ends_with($name, '_e2e')) {
    fwrite(STDERR, "Se esperaba una base *_e2e y la conexión apunta a «{$name}».\n");
    exit(1);
}

const CARGA = ' "\'><i data-xss>x</i>';
$centro = (int) $db->query("SELECT id FROM tenants WHERE slug = 'demo-centro'")->fetchColumn();

// tabla => [columnas, condición]
$objetivos = [
    'tenants' => [['nombre_comercial', 'descripcion'], "id = {$centro}"],
    'sucursales' => [['nombre', 'direccion', 'referencia'], "tenant_id = {$centro}"],
    'servicios' => [['nombre', 'descripcion'], "tenant_id = {$centro}"],
    'categorias_servicio' => [['nombre'], "tenant_id = {$centro}"],
    'barberos' => [['nombre_publico', 'especialidad', 'biografia'], "tenant_id = {$centro}"],
    'bloqueos_agenda' => [['motivo'], "tenant_id = {$centro}"],
    'reservas' => [['notas_cliente', 'notas_internas'], "tenant_id = {$centro}"],
    'cliente_tenants' => [['notas_internas'], "tenant_id = {$centro}"],
    'clientes' => [['nombres', 'apellidos'], '1 = 1'],
    'usuarios' => [['nombres', 'apellidos'], '1 = 1'],
    'notificaciones' => [['titulo', 'cuerpo'], "canal = 'interna'"],
    'planes' => [['descripcion'], '1 = 1'],
];

$total = 0;
foreach ($objetivos as $tabla => [$columnas, $where]) {
    foreach ($columnas as $col) {
        $max = (int) $db->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$tabla}' AND column_name = '{$col}'")->fetchColumn();
        $keep = max(0, min($max, 65535) - mb_strlen(CARGA));
        $stmt = $db->prepare("UPDATE `{$tabla}` SET `{$col}` = CONCAT(LEFT(COALESCE(`{$col}`, 'Texto'), {$keep}), ?) WHERE {$where}");
        $stmt->execute([CARGA]);
        $total += $stmt->rowCount();
    }
}
echo "Carga XSS agregada a {$total} textos.\n";
