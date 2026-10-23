<?php

declare(strict_types=1);

namespace App\Services\Docs;

use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Middleware\PlatformMiddleware;
use App\Middleware\TenantContextMiddleware;
use App\Middleware\VerifiedEmailMiddleware;
use InvalidArgumentException;

/**
 * Genera la especificación OpenAPI 3.1 de la API a partir de:
 *   - la tabla de rutas real (routes/api.php): rutas, métodos, parámetros, sesión, barbería,
 *     permisos y correo verificado salen del middleware de cada ruta, sin copiarlos a mano;
 *   - las fichas de docs/api/fuente/*.php: resumen, descripción, consulta, cuerpo y errores,
 *     escritos con la misma notación de reglas del Validator.
 *
 * problems() lista lo que no cuadra (ruta sin ficha, ficha sin ruta, código de error que no
 * existe…). La prueba OpenApiTest exige que esté vacío y que docs/api/openapi.json esté al día.
 */
final class OpenApiBuilder
{
    private const PREFIX = '/api/v1';

    /** Claves permitidas en cada ficha de operación. */
    private const CLAVES = ['resumen', 'descripcion', 'consulta', 'paginado', 'cuerpo', 'cuerpo_imagen', 'respuesta', 'produce', 'errores', 'idempotente', 'sin_contenido', 'estado'];

    /** @var list<string> */
    private array $problems = [];

    /** @var array<string, array<string, mixed>> */
    private array $ops = [];

    /** @var array<string, mixed> */
    private array $general;

    /** @var array<string, array{estados: list<int>, mensaje: string, archivos: list<string>}> */
    private array $errors;

    public function __construct(private Router $router, private string $sourceDir, private string $appDir)
    {
        $this->general = require $sourceDir . '/_general.php';
        foreach (glob($sourceDir . '/*.php') ?: [] as $file) {
            if (basename($file) === '_general.php') {
                continue;
            }
            $ops = require $file;
            if (!is_array($ops)) {
                throw new InvalidArgumentException(basename($file) . ' debe devolver un arreglo.');
            }
            foreach ($ops as $name => $op) {
                if (isset($this->ops[$name])) {
                    $this->problems[] = "Ficha duplicada: {$name} (" . basename($file) . ').';
                }
                $this->ops[$name] = (array) $op;
            }
        }
        $this->errors = ErrorCatalog::scan($appDir);
    }

    /** Texto de todo el código de app/ (para comprobar que los campos documentados existen). */
    private ?string $source = null;

    /**
     * Cada campo documentado en consulta o cuerpo debe aparecer como 'campo' en el código:
     * detecta erratas y campos que se renombraron o eliminaron.
     *
     * @param array<string, mixed> $fields
     */
    private function checkFieldsExist(string $name, array $fields): void
    {
        if ($this->source === null) {
            $this->source = '';
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->appDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $this->source .= file_get_contents($f->getPathname());
                }
            }
        }
        foreach ($fields as $field => $spec) {
            if (!str_contains($this->source, "'{$field}'") && !str_contains($this->source, "\"{$field}\"")) {
                $this->problems[] = "{$name}: el campo \"{$field}\" no aparece en el código (¿errata o se renombró?).";
            }
            if (is_array($spec)) {
                foreach (['campos', 'items'] as $k) {
                    if (isset($spec[$k]) && is_array($spec[$k]) && !isset($spec[$k]['regla'])) {
                        $this->checkFieldsExist($name, $spec[$k]);
                    }
                }
            }
        }
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return array<string, array{estados: list<int>, mensaje: string, archivos: list<string>}> */
    public function errorCatalog(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $paths = [];
        $seen = [];
        $tagsUsed = [];
        foreach ($this->router->definitions() as $route) {
            $name = (string) $route['name'];
            if ($name === '') {
                $this->problems[] = "Ruta sin nombre: {$route['method']} {$route['path']}.";
                continue;
            }
            $seen[$name] = true;
            $op = $this->ops[$name] ?? null;
            if ($op === null) {
                $this->problems[] = "Ruta sin documentar: {$name} ({$route['method']} {$route['path']}).";
                $op = ['resumen' => '(sin documentar)'];
            }
            [$path, $pathParams] = $this->path($route['path']);
            $operation = $this->operation($name, $route, $op, $pathParams);
            $tagsUsed[$operation['tags'][0]] = true;
            $paths[$path][strtolower($route['method'])] = $operation;
        }
        foreach (array_keys($this->ops) as $name) {
            if (!isset($seen[$name])) {
                $this->problems[] = "Ficha sin ruta (¿se renombró o eliminó?): {$name}.";
            }
        }

        $tags = [];
        foreach ($this->general['etiquetas'] as $t) {
            if (isset($tagsUsed[$t['nombre']])) {
                $tags[] = ['name' => $t['nombre'], 'description' => $t['descripcion']];
            }
        }

        return [
            'openapi' => '3.1.0',
            'info' => $this->general['info'],
            'servers' => $this->general['servidores'],
            'tags' => $tags,
            'paths' => $paths,
            'components' => $this->components(),
        ];
    }

    public static function json(array $spec): string
    {
        return json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    // ------------------------------------------------------------------ operación

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $op
     * @param list<array<string, mixed>> $pathParams
     * @return array<string, mixed>
     */
    private function operation(string $name, array $route, array $op, array $pathParams): array
    {
        foreach (array_diff(array_keys($op), self::CLAVES) as $k) {
            $this->problems[] = "{$name}: clave desconocida \"{$k}\".";
        }
        if (trim((string) ($op['resumen'] ?? '')) === '') {
            $this->problems[] = "{$name}: falta el resumen.";
        }
        $method = (string) $route['method'];
        $write = !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
        $mw = $this->middleware($route['middleware']);

        $notes = [];
        $params = $pathParams;
        $out = [
            'operationId' => $name,
            'tags' => [$this->tag($name)],
            'summary' => (string) ($op['resumen'] ?? ''),
        ];

        // Sesión
        if ($name === 'health.operation') {
            $out['security'] = [['tokenMonitoreo' => []]];
        } elseif ($mw['auth'] === null) {
            $out['security'] = [];
            $notes[] = 'Pública: no requiere sesión.';
        } elseif ($mw['auth'] === 'opcional') {
            $out['security'] = [new \stdClass(), ['cookieSesion' => []], ['tokenAcceso' => []]];
            $notes[] = 'Sesión opcional: con sesión la respuesta se personaliza.';
        } else {
            $out['security'] = [['cookieSesion' => []], ['tokenAcceso' => []]];
            if ($write) {
                $params[] = ['$ref' => '#/components/parameters/CsrfToken'];
            }
        }
        if ($mw['tenant']) {
            $params[] = ['$ref' => '#/components/parameters/Tenant'];
            $notes[] = 'Barbería: la de la cabecera X-Tenant o la seleccionada en la sesión (POST /me/context); exige membresía activa.';
        }
        if ($mw['plataforma']) {
            $notes[] = 'Solo para el equipo de BARBERAPP (rol de plataforma).';
            $out['x-plataforma'] = true;
        }
        if ($mw['permisos'] !== []) {
            $txt = [];
            foreach ($mw['permisos'] as $g) {
                $txt[] = ($g['modo'] === 'alguno' && count($g['permisos']) > 1 ? 'uno de ' : '') . implode(count($g['permisos']) > 1 && $g['modo'] === 'todos' ? ' y ' : ', ', array_map(static fn (string $p): string => "`{$p}`", $g['permisos']));
            }
            $notes[] = 'Permiso: ' . implode(' + ', $txt) . '.';
            $out['x-permisos'] = $mw['permisos'];
        }
        if ($mw['correo_verificado']) {
            $notes[] = 'Requiere el correo verificado.';
            $out['x-requiere-correo-verificado'] = true;
        }
        if (!empty($op['idempotente'])) {
            $params[] = ['$ref' => '#/components/parameters/IdempotencyKey'];
            $notes[] = 'Idempotente: reintentar con la misma Idempotency-Key devuelve la misma respuesta sin repetir la operación.';
        }

        $desc = trim((string) ($op['descripcion'] ?? ''));
        $out['description'] = trim($desc . ($desc !== '' ? "\n\n" : '') . implode("\n\n", $notes));

        // Consulta
        $query = (array) ($op['consulta'] ?? []);
        $this->checkFieldsExist($name, $query + (array) ($op['cuerpo'] ?? []));
        if (isset($op['paginado'])) {
            [$def, $max] = (array) $op['paginado'];
            $query += [
                'page' => ['regla' => 'nullable|integer|min:1', 'descripcion' => 'Página (desde 1).'],
                'per_page' => ['regla' => 'nullable|integer|min:1|max:' . (int) $max, 'descripcion' => "Elementos por página (por defecto {$def})."],
            ];
        }
        foreach ($query as $q => $spec) {
            try {
                [$schema, $req] = RuleSchema::field($spec, "{$name}?{$q}");
            } catch (InvalidArgumentException $e) {
                $this->problems[] = $e->getMessage();
                continue;
            }
            $p = ['name' => $q, 'in' => 'query', 'required' => $req, 'schema' => $schema];
            if (isset($schema['description'])) {
                $p['description'] = $schema['description'];
                unset($p['schema']['description']);
            }
            if (($schema['type'] ?? null) === 'array' || (is_array($schema['type'] ?? null) && in_array('array', $schema['type'], true))) {
                $p['style'] = 'form';
                $p['explode'] = false;
            }
            $params[] = $p;
        }
        if ($params !== []) {
            $out['parameters'] = $params;
        }

        // Cuerpo
        if (isset($op['cuerpo'])) {
            if (!$write) {
                $this->problems[] = "{$name}: una consulta GET no lleva cuerpo.";
            }
            try {
                $schema = RuleSchema::object((array) $op['cuerpo'], $name);
                $out['requestBody'] = ['required' => isset($schema['required']), 'content' => ['application/json' => ['schema' => $schema]]];
            } catch (InvalidArgumentException $e) {
                $this->problems[] = $e->getMessage();
            }
        } elseif (!empty($op['cuerpo_imagen'])) {
            $bin = ['schema' => ['type' => 'string', 'contentMediaType' => 'application/octet-stream']];
            $out['requestBody'] = ['required' => true, 'description' => 'La imagen tal cual (cuerpo binario, sin multipart). Máximo MEDIA_MAX_UPLOAD_BYTES; se convierte a WebP.',
                'content' => ['image/jpeg' => $bin, 'image/png' => $bin, 'image/webp' => $bin]];
        }

        // Respuestas
        $out['responses'] = $this->responses($name, $op, $mw, $write, $pathParams !== [], $query !== [] || isset($op['cuerpo']));
        return $out;
    }

    /**
     * @param array<string, mixed> $op
     * @param array<string, mixed> $mw
     * @return array<string, mixed>
     */
    private function responses(string $name, array $op, array $mw, bool $write, bool $hasPathParams, bool $hasInput): array
    {
        $res = [];
        $status = (string) ($op['estado'] ?? (!empty($op['sin_contenido']) ? 204 : 200));
        $respDesc = is_string($op['respuesta'] ?? null) ? $op['respuesta'] : (string) (($op['respuesta']['descripcion'] ?? null) ?? 'Operación correcta.');
        if ($status === '204') {
            $res['204'] = ['description' => $respDesc !== 'Operación correcta.' ? $respDesc : 'Sin contenido.'];
        } elseif (isset($op['produce'])) {
            $content = [];
            foreach ((array) $op['produce'] as $type => $desc) {
                $content[$type] = ['schema' => ['type' => 'string', 'contentMediaType' => $type, 'description' => $desc]];
            }
            $res[$status] = ['description' => $respDesc, 'content' => $content];
        } else {
            $data = ['description' => 'Contenido de la respuesta (ver la descripción).'];
            if (is_array($op['respuesta'] ?? null) && isset($op['respuesta']['datos'])) {
                try {
                    $data = RuleSchema::object((array) $op['respuesta']['datos'], $name . '.respuesta');
                } catch (InvalidArgumentException $e) {
                    $this->problems[] = $e->getMessage();
                }
            }
            $res[$status] = ['description' => $respDesc, 'content' => ['application/json' => ['schema' => [
                'allOf' => [['$ref' => '#/components/schemas/Exito'], ['type' => 'object', 'properties' => ['data' => $data]]],
            ]]]];
        }

        $codes = [];
        foreach ((array) ($op['errores'] ?? []) as $code) {
            if (!isset($this->errors[$code])) {
                $this->problems[] = "{$name}: el código de error \"{$code}\" no aparece en el código fuente.";
                continue;
            }
            foreach ($this->errors[$code]['estados'] as $st) {
                $codes[(string) $st][] = $code;
            }
        }
        if ($hasInput) {
            $codes['422'][] = 'validation_failed';
        }
        if ($mw['auth'] === 'obligatoria') {
            $codes['401'][] = 'unauthenticated';
        }
        if ($mw['permisos'] !== [] || $mw['plataforma'] || $mw['tenant']) {
            $codes['403'][] = 'forbidden';
        }
        if ($mw['correo_verificado']) {
            $codes['403'][] = 'email_not_verified';
        }
        if ($mw['auth'] === 'obligatoria' && $write) {
            $codes['403'][] = 'csrf_mismatch';
        }
        if ($hasPathParams || $mw['tenant']) {
            $codes['404'][] = 'not_found';
        }
        if (!empty($op['idempotente'])) {
            $codes['428'][] = 'idempotency_key_required';
        }
        if ($write && $name !== 'webhooks.payments') {
            $codes['429'][] = 'too_many_requests';
        }
        foreach ($codes as $list) {
            foreach ($list as $c) {
                if (!isset($this->errors[$c])) {
                    $this->problems[] = "{$name}: el código automático \"{$c}\" ya no existe en el código fuente.";
                }
            }
        }
        ksort($codes);
        foreach ($codes as $st => $list) {
            $list = array_values(array_unique($list));
            $res[$st] = [
                'description' => implode(', ', array_map(static fn (string $c): string => "`{$c}`", $list)),
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
            ];
        }
        return $res;
    }

    /**
     * @param list<mixed> $middleware
     * @return array{auth: ?string, tenant: bool, plataforma: bool, correo_verificado: bool, permisos: list<array{modo: string, permisos: list<string>}>}
     */
    private function middleware(array $middleware): array
    {
        $out = ['auth' => null, 'tenant' => false, 'plataforma' => false, 'correo_verificado' => false, 'permisos' => []];
        foreach ($middleware as $m) {
            if ($m instanceof AuthMiddleware) {
                $out['auth'] = $m->isOptional() ? 'opcional' : 'obligatoria';
            } elseif ($m instanceof TenantContextMiddleware) {
                $out['tenant'] = true;
            } elseif ($m instanceof PlatformMiddleware) {
                $out['plataforma'] = true;
            } elseif ($m instanceof VerifiedEmailMiddleware) {
                $out['correo_verificado'] = true;
            } elseif ($m instanceof PermissionMiddleware) {
                $out['permisos'][] = ['modo' => $m->anyOf() ? 'alguno' : 'todos', 'permisos' => $m->permissions()];
            }
        }
        return $out;
    }

    private function tag(string $name): string
    {
        $best = null;
        $len = -1;
        foreach ($this->general['etiquetas'] as $t) {
            foreach ((array) $t['rutas'] as $prefix) {
                if (($name === $prefix || str_starts_with($name, $prefix . '.')) && strlen($prefix) > $len) {
                    $best = $t['nombre'];
                    $len = strlen($prefix);
                }
            }
        }
        if ($best === null) {
            $this->problems[] = "{$name}: ninguna etiqueta cubre esta ruta (docs/api/fuente/_general.php).";
            return 'Otros';
        }
        return $best;
    }

    /**
     * /api/v1/reservas/{id:\d+} → [/reservas/{id}, parámetros]
     *
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function path(string $full): array
    {
        $path = substr($full, strlen(self::PREFIX));
        $out = '';
        $params = [];
        $len = strlen($path);
        for ($i = 0; $i < $len; $i++) {
            if ($path[$i] !== '{') {
                $out .= $path[$i];
                continue;
            }
            $depth = 0;
            $j = $i;
            for (; $j < $len; $j++) {
                $depth += $path[$j] === '{' ? 1 : ($path[$j] === '}' ? -1 : 0);
                if ($depth === 0) {
                    break;
                }
            }
            [$pname, $regex] = array_pad(explode(':', substr($path, $i + 1, $j - $i - 1), 2), 2, '');
            $out .= '{' . $pname . '}';
            $schema = ['type' => 'string'];
            if ($regex === '\d+') {
                $schema = ['type' => 'integer', 'minimum' => 1];
            } elseif ($regex !== '' && preg_match('/^[a-z|]+$/', $regex)) {
                $schema['enum'] = explode('|', $regex);
            } elseif ($regex !== '') {
                $schema['pattern'] = '^' . $regex . '$';
            }
            $params[] = ['name' => $pname, 'in' => 'path', 'required' => true, 'schema' => $schema];
            $i = $j;
        }
        return [$out === '' ? '/' : $out, $params];
    }

    // ------------------------------------------------------------------ componentes

    /** @return array<string, mixed> */
    private function components(): array
    {
        return [
            'securitySchemes' => [
                'cookieSesion' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'barberapp_session',
                    'description' => 'Sesión web: cookie HttpOnly que crean /auth/login y /auth/register con "canal": "web". Las escrituras exigen además X-CSRF-Token.'],
                'tokenAcceso' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT',
                    'description' => 'App móvil: token de acceso HS256 de 15 minutos (login con "canal": "movil"). Se renueva con POST /auth/refresh (refresh token rotativo).'],
                'tokenMonitoreo' => ['type' => 'http', 'scheme' => 'bearer',
                    'description' => 'MONITOR_TOKEN del .env, solo para GET /health/operacion.'],
            ],
            'parameters' => [
                'CsrfToken' => ['name' => 'X-CSRF-Token', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string'],
                    'description' => 'Obligatorio con sesión de cookie en métodos que modifican datos (lo entregan login, register y GET /auth/csrf). No se usa con Bearer.'],
                'Tenant' => ['name' => 'X-Tenant', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$'],
                    'description' => 'Slug de la barbería en la que se trabaja. Si se omite, se usa la seleccionada en la sesión.'],
                'IdempotencyKey' => ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true,
                    'schema' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 100, 'pattern' => '^[A-Za-z0-9.:\-]+$'],
                    'description' => 'Identificador único por intento (p. ej. un UUID). Reutilízalo al reintentar por problemas de red; caduca a las 24 h.'],
            ],
            'schemas' => [
                'Exito' => ['type' => 'object', 'required' => ['ok', 'data', 'meta'], 'properties' => [
                    'ok' => ['const' => true],
                    'data' => ['description' => 'Contenido de la respuesta.'],
                    'meta' => ['$ref' => '#/components/schemas/Meta'],
                ]],
                'Error' => ['type' => 'object', 'required' => ['ok', 'error', 'meta'], 'properties' => [
                    'ok' => ['const' => false],
                    'error' => ['type' => 'object', 'required' => ['code', 'message'], 'properties' => [
                        'code' => ['type' => 'string', 'description' => 'Código estable para el programa (ver docs/API.md).', 'examples' => ['validation_failed']],
                        'message' => ['type' => 'string', 'description' => 'Mensaje para mostrar a la persona, en español.'],
                        'details' => ['type' => 'object', 'description' => 'Datos extra; con validation_failed, details.fields = {campo: [mensajes]}.'],
                    ]],
                    'meta' => ['$ref' => '#/components/schemas/Meta'],
                ]],
                'Meta' => ['type' => 'object', 'properties' => [
                    'request_id' => ['type' => 'string', 'description' => 'Identificador de la petición (también en la cabecera X-Request-Id y en los registros).'],
                    'page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'total' => ['type' => 'integer'],
                ], 'additionalProperties' => true],
            ],
        ];
    }
}
