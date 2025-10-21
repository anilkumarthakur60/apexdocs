<?php

/**
 * ApexDocs mock-server router. Spawned by `apexdocs:mock` via
 * `php -S host:port resources/mock/server.php`.
 *
 * The spec is NOT embedded in this file. Instead the parent process writes
 * it to a temp JSON file and exposes the path through the env var
 * `APEXDOCS_MOCK_SPEC`. That keeps user-controlled content (descriptions,
 * route names, example values) far away from PHP source evaluation.
 */

declare(strict_types=1);

$specPath = getenv('APEXDOCS_MOCK_SPEC') ?: '';
if ($specPath === '' || ! is_file($specPath) || ! is_readable($specPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'APEXDOCS_MOCK_SPEC env var not set or unreadable']);

    return true;
}

$json = file_get_contents($specPath);
if ($json === false) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Could not read spec file']);

    return true;
}

try {
    $spec = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Spec is not valid JSON', 'detail' => $e->getMessage()]);

    return true;
}

if (! is_array($spec)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Spec root must be an object']);

    return true;
}

$method = strtolower((string) ($_SERVER['REQUEST_METHOD'] ?? 'get'));
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('X-Powered-By: ApexDocs Mock');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(204);

    return true;
}

foreach ($spec['paths'] ?? [] as $specPathTpl => $methods) {
    if (! is_string($specPathTpl) || ! is_array($methods)) {
        continue;
    }
    $pattern = '#^/?'.preg_replace('/\{[A-Za-z_][A-Za-z0-9_]*\}/', '[^/]+', str_replace('/', '\/', $specPathTpl)).'/?$#';
    if (preg_match($pattern, $path) && isset($methods[$method]) && is_array($methods[$method])) {
        $op = $methods[$method];
        $responses = $op['responses'] ?? ['200' => []];
        $code = (int) array_key_first($responses);
        http_response_code($code);
        $resp = $responses[$code] ?? [];
        $schema = $resp['content']['application/json']['schema'] ?? ['type' => 'object'];
        echo json_encode(apexdocs_mock_example($schema, $spec), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return true;
    }
}

http_response_code(404);
echo json_encode(['message' => 'Not found in mock spec', 'path' => $path]);

return true;

function apexdocs_mock_example(array $s, array $spec, int $depth = 0): mixed
{
    if ($depth > 32) {
        return null;
    }
    if (isset($s['$ref']) && is_string($s['$ref'])) {
        $parts = explode('/', ltrim($s['$ref'], '#/'));
        $t = $spec;
        foreach ($parts as $p) {
            if (! is_array($t) || ! array_key_exists($p, $t)) {
                return null;
            }
            $t = $t[$p];
        }

        return is_array($t) ? apexdocs_mock_example($t, $spec, $depth + 1) : null;
    }
    if (array_key_exists('example', $s)) {
        return $s['example'];
    }
    $t = $s['type'] ?? 'object';
    if (is_array($t)) {
        $f = array_values(array_filter($t, fn ($x) => $x !== 'null'));
        $t = $f[0] ?? 'object';
    }

    return match ($t) {
        'object' => (object) array_map(
            fn ($v) => is_array($v) ? apexdocs_mock_example($v, $spec, $depth + 1) : null,
            $s['properties'] ?? ['id' => ['type' => 'integer']]
        ),
        'array' => [apexdocs_mock_example(is_array($s['items'] ?? null) ? $s['items'] : ['type' => 'string'], $spec, $depth + 1)],
        'integer' => 1,
        'number' => 1.0,
        'boolean' => true,
        'null' => null,
        default => $s['enum'][0] ?? 'string',
    };
}
