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

// ?__status=404 asks the mock for a specific documented response.
$wanted = isset($_GET['__status']) ? (string) $_GET['__status'] : null;

foreach ($spec['paths'] ?? [] as $specPathTpl => $methods) {
    if (! is_string($specPathTpl) || ! is_array($methods)) {
        continue;
    }
    if (! isset($methods[$method]) || ! is_array($methods[$method])) {
        continue;
    }
    // Quote the literal parts of the template so a path containing regex
    // metacharacters cannot break — or widen — the pattern.
    $quoted = preg_quote($specPathTpl, '#');
    $pattern = '#^/?'.preg_replace('/\\\\\{[A-Za-z_][A-Za-z0-9_]*\\\\}/', '[^/]+', $quoted).'/?$#';
    if (preg_match($pattern, $path) !== 1) {
        continue;
    }

    $op = $methods[$method];
    $responses = is_array($op['responses'] ?? null) && $op['responses'] !== [] ? $op['responses'] : ['200' => []];
    $status = apexdocs_mock_status($responses, $wanted);
    $resp = $responses[$status] ?? $responses[(int) $status] ?? [];
    $resp = is_array($resp) ? $resp : [];

    // A $ref'd response (e.g. #/components/responses/ValidationError)
    if (isset($resp['$ref']) && is_string($resp['$ref'])) {
        $resolved = apexdocs_mock_resolve($resp['$ref'], $spec);
        $resp = is_array($resolved) ? $resolved : [];
    }

    http_response_code(is_numeric($status) ? (int) $status : 200);

    foreach (($resp['headers'] ?? []) as $name => $header) {
        if (is_string($name) && is_array($header)) {
            $value = $header['example'] ?? apexdocs_mock_example(is_array($header['schema'] ?? null) ? $header['schema'] : [], $spec);
            if (is_scalar($value)) {
                header($name.': '.$value);
            }
        }
    }

    $content = is_array($resp['content'] ?? null) ? $resp['content'] : [];
    $media = $content['application/json'] ?? (is_array(reset($content)) ? reset($content) : []);

    if ($media === [] || $media === false) {
        // Documented with no body (204 No Content and friends).
        return true;
    }

    if (isset($media['example'])) {
        echo json_encode($media['example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return true;
    }

    $schema = is_array($media['schema'] ?? null) ? $media['schema'] : ['type' => 'object'];
    echo json_encode(apexdocs_mock_example($schema, $spec), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return true;
}

http_response_code(404);
echo json_encode(['message' => 'Not found in mock spec', 'path' => $path]);

return true;

/**
 * Pick which documented response to serve: the caller's choice if it exists,
 * otherwise the lowest success status. Never the first key — an operation that
 * documents 404 before 200 must still answer 200 by default.
 */
function apexdocs_mock_status(array $responses, ?string $wanted): string
{
    $keys = array_map('strval', array_keys($responses));

    if ($wanted !== null && in_array($wanted, $keys, true)) {
        return $wanted;
    }

    $success = array_values(array_filter($keys, static fn (string $k): bool => (int) $k >= 200 && (int) $k < 300));
    if ($success !== []) {
        sort($success, SORT_NATURAL);

        return $success[0];
    }

    $numeric = array_values(array_filter($keys, 'is_numeric'));
    sort($numeric, SORT_NATURAL);

    return $numeric[0] ?? $keys[0];
}

function apexdocs_mock_resolve(string $ref, array $spec): ?array
{
    if (! str_starts_with($ref, '#/')) {
        return null;
    }
    $node = $spec;
    foreach (explode('/', substr($ref, 2)) as $segment) {
        $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
        if (! is_array($node) || ! array_key_exists($segment, $node)) {
            return null;
        }
        $node = $node[$segment];
    }

    return is_array($node) ? $node : null;
}

function apexdocs_mock_example(array $s, array $spec, int $depth = 0): mixed
{
    if ($depth > 32) {
        return null;
    }
    if (isset($s['$ref']) && is_string($s['$ref'])) {
        $t = apexdocs_mock_resolve($s['$ref'], $spec);

        return is_array($t) ? apexdocs_mock_example($t, $spec, $depth + 1) : null;
    }
    if (array_key_exists('example', $s)) {
        return $s['example'];
    }
    if (array_key_exists('default', $s)) {
        return $s['default'];
    }
    if (isset($s['allOf']) && is_array($s['allOf'])) {
        $merged = [];
        foreach ($s['allOf'] as $branch) {
            $part = is_array($branch) ? apexdocs_mock_example($branch, $spec, $depth + 1) : null;
            if (is_object($part)) {
                $merged = array_merge($merged, (array) $part);
            }
        }

        return (object) $merged;
    }
    foreach (['oneOf', 'anyOf'] as $combinator) {
        if (! isset($s[$combinator]) || ! is_array($s[$combinator])) {
            continue;
        }
        foreach ($s[$combinator] as $branch) {
            if (is_array($branch) && ($branch['type'] ?? null) !== 'null') {
                return apexdocs_mock_example($branch, $spec, $depth + 1);
            }
        }

        return null;
    }
    $t = $s['type'] ?? (isset($s['properties']) ? 'object' : 'string');
    if (is_array($t)) {
        $f = array_values(array_filter($t, fn ($x) => $x !== 'null'));
        $t = $f[0] ?? 'null';
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
