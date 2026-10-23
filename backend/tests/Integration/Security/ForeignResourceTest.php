<?php

declare(strict_types=1);

namespace Tests\Integration\Security;

use Tests\Integration\Auth\ApiTestCase;

/**
 * Fase 14 — seguridad: acceso a recursos ajenos por id (IDOR) en TODAS las rutas con {id}.
 *
 * Para cada ruta se usa el id REAL de un recurso de otra persona u otra barbería:
 *   - panel: propietario de demo-norte contra datos de demo-centro;
 *   - panel del barbero: barbero de demo-norte contra citas y ausencias de demo-centro;
 *   - cliente: cliente2 contra las citas, sesiones, avisos y dispositivos de cliente1.
 * Ninguna puede responder 2xx ni cambiar la fila atacada.
 *
 * @group seguridad
 */
final class ForeignResourceTest extends ApiTestCase
{
    /** Primer segmento con id → tabla y condición que identifica al dueño legítimo. */
    private const RECURSOS = [
        'panel' => [
            'personal' => ['membresias', 'tenant_id = :centro'],
            'fotos' => ['tenant_fotos', 'tenant_id = :centro'],
            'sucursales' => ['sucursales', 'tenant_id = :centro'],
            'servicios' => ['servicios', 'tenant_id = :centro'],
            'categorias' => ['categorias_servicio', 'tenant_id = :centro'],
            'barberos' => ['barberos', 'tenant_id = :centro'],
            'politicas-reserva' => ['sucursales', 'tenant_id = :centro'],
            'bloqueos' => ['bloqueos_agenda', 'tenant_id = :centro'],
            'reservas' => ['reservas', 'tenant_id = :centro'],
            // Cliente que solo existe en demo-centro.
            'clientes' => ['clientes', 'id IN (SELECT cliente_id FROM cliente_tenants WHERE tenant_id = :centro) AND id NOT IN (SELECT cliente_id FROM cliente_tenants WHERE tenant_id = :norte)'],
        ],
        'barbero' => [
            'citas' => ['reservas', 'tenant_id = :centro'],
            'ausencias' => ['bloqueos_agenda', 'tenant_id = :centro'],
        ],
        'mis-reservas' => [
            '' => ['reservas', 'cliente_id = (SELECT id FROM clientes WHERE usuario_id = :victima)'],
        ],
        'me' => [
            'memberships' => ['membresias', 'usuario_id = :victima_staff'],
            'sessions' => ['sesiones', 'usuario_id = :victima'],
            'dispositivos' => ['dispositivos_push', 'usuario_id = :victima'],
            'notificaciones' => ['notificaciones', 'usuario_id = :victima'],
        ],
    ];

    /** Grupo → cuenta atacante y barbería desde la que actúa. */
    private const ATACANTES = [
        'panel' => ['propietario.norte@barberapp.test', 'demo-norte'],
        'barbero' => ['barbero1.norte@barberapp.test', 'demo-norte'],
        'mis-reservas' => ['cliente2@barberapp.test', null],
        'me' => ['cliente2@barberapp.test', null],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Datos que la demo no trae: una foto de demo-centro, una sesión, un dispositivo y un aviso de cliente1.
        $centro = $this->tenantId('demo-centro');
        $this->exec("INSERT INTO tenant_fotos (tenant_id, tipo, ruta, ancho, alto, bytes) VALUES (?, 'galeria', ?, 10, 10, 100)", [$centro, "tenants/{$centro}/" . str_repeat('ab', 16) . '.webp']);
        $this->loginMobile('cliente1@barberapp.test'); // deja una sesión de cliente1
        $victima = $this->userId('cliente1@barberapp.test');
        if ((int) $this->scalar('SELECT COUNT(*) FROM dispositivos_push WHERE usuario_id = ?', [$victima]) === 0) {
            $this->exec("INSERT INTO dispositivos_push (usuario_id, plataforma, token) VALUES (?, 'android', ?)", [$victima, 'tok-' . bin2hex(random_bytes(8))]);
        }
        $this->cookies = [];
    }

    public function testNoRouteReachesAnotherPersonsOrBarbershopsResourceById(): void
    {
        $params = [
            'centro' => $this->tenantId('demo-centro'),
            'norte' => $this->tenantId('demo-norte'),
            'victima' => $this->userId('cliente1@barberapp.test'),
            'victima_staff' => $this->userId('barbero1.centro@barberapp.test'),
        ];
        $tokens = [];
        $checked = [];
        $n = 0;
        foreach (RouteProbe::routes() as $r) {
            if (!preg_match('#^/(panel|barbero|mis-reservas|me)(?:/([a-z-]+))?/999999#', $r['path'], $m)) {
                continue;
            }
            [$grupo, $segmento] = [$m[1], $m[2] ?? ''];
            $this->assertArrayHasKey($segmento, self::RECURSOS[$grupo], "Ruta nueva sin recurso asignado en esta prueba: {$r['name']} ({$r['path']})");
            [$tabla, $cond] = self::RECURSOS[$grupo][$segmento];
            $sqlParams = array_intersect_key($params, array_flip($this->placeholders($cond)));
            $id = $this->scalarNamed("SELECT id FROM {$tabla} WHERE {$cond} ORDER BY id LIMIT 1", $sqlParams);
            $this->assertNotFalse($id, "Sin datos de la víctima para {$r['name']} ({$tabla})");

            [$email, $tenant] = self::ATACANTES[$grupo];
            $tokens[$email] ??= $this->loginMobile($email)['access_token'];
            $headers = ['Authorization' => 'Bearer ' . $tokens[$email], 'Idempotency-Key' => 'prueba-idor-' . $n] + ($tenant !== null ? ['X-Tenant' => $tenant] : []);
            $path = preg_replace('#/999999#', '/' . $id, $r['path'], 1);
            if (str_contains($path, '/pagos/999999')) {
                $pago = $this->scalar('SELECT id FROM reserva_pagos WHERE reserva_id = ? LIMIT 1', [$id]) ?: 999999;
                $path = str_replace('/pagos/999999', '/pagos/' . $pago, $path);
            }

            $antes = $this->row($tabla, (int) $id);
            $this->ip = '198.51.100.' . (++$n % 250 + 1);
            $this->db->exec('SAVEPOINT idor');
            $res = $r['name'] === 'panel.barbers.photo'
                ? $this->send('POST', $path, $this->png(), $headers + ['Content-Type' => 'image/png'])
                : $this->call($r['method'], $path, RouteProbe::isWrite($r['method']) ? $this->plausibleBody() : null, $headers);
            $despues = $this->row($tabla, (int) $id);
            $this->db->exec('ROLLBACK TO SAVEPOINT idor');

            $this->assertContains($res->status(), [403, 404, 409, 422], "{$r['name']} {$r['method']} {$path} como {$email} → {$res->status()} {$res->body()}");
            $this->assertSame($antes, $despues, "{$r['name']}: la fila {$tabla}#{$id} cambió");
            $checked[] = $r['name'];
        }
        $this->assertGreaterThanOrEqual(50, count($checked), 'Rutas con {id} revisadas: ' . implode(', ', $checked));
    }

    /** Cuerpo con valores válidos para que, si faltara el control de acceso, la operación sí se ejecutara. */
    private function plausibleBody(): array
    {
        return [
            'motivo' => 'Prueba de acceso ajeno', 'notas_internas' => 'x', 'nombre' => 'Cambio ajeno', 'nombres' => 'Cambio ajeno',
            'duracion_minutos' => 30, 'precio' => 10, 'activo' => false, 'fecha' => gmdate('Y-m-d', time() + 5 * 86400), 'hora' => '10:00',
            'monto' => 10, 'metodo' => 'efectivo', 'comentario' => 'x', 'dias' => [], 'servicio_ids' => [], 'bloqueado' => true,
        ];
    }

    /** PNG real de 64×64 (o, sin GD, uno mínimo válido). */
    private function png(): string
    {
        if (function_exists('imagecreatetruecolor')) {
            $img = imagecreatetruecolor(64, 64);
            ob_start();
            imagepng($img);
            return (string) ob_get_clean();
        }
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }

    /** @return list<string> */
    private function placeholders(string $sql): array
    {
        preg_match_all('/:([a-z_]+)/', $sql, $m);
        return array_unique($m[1]);
    }

    /** @param array<string, mixed> $params @return mixed */
    private function scalarNamed(string $sql, array $params)
    {
        // PDO no admite repetir un marcador con nombre: se numeran.
        $i = 0;
        $values = [];
        $sql = (string) preg_replace_callback('/:([a-z_]+)/', static function (array $m) use (&$i, &$values, $params): string {
            $values[':p' . $i] = $params[$m[1]];
            return ':p' . $i++;
        }, $sql);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        return $stmt->fetchColumn();
    }

    /** @return array<string, mixed>|false */
    private function row(string $tabla, int $id)
    {
        $stmt = $this->db->prepare("SELECT * FROM {$tabla} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
