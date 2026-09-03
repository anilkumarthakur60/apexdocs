<?php

declare(strict_types=1);

use ApexDocs\ApexDocs;
use ApexDocs\Config;
use ApexDocs\Mcp\InProcessSnapshotProvider;
use ApexDocs\Mcp\McpServer;
use ApexDocs\Mcp\Snapshot;
use ApexDocs\Mcp\SnapshotProviderInterface;
use ApexDocs\Route\ArrayRouteCollection;
use ApexDocs\Tests\Fixtures\Controllers\UserController;

/*
 * Framework-agnostic coverage of the MCP server: protocol handling, every
 * tool, resources and prompts - driven by the fixture controllers through an
 * in-process snapshot, so no Laravel container is involved.
 */

function mcpServer(?Config $config = null): McpServer
{
    $routes = (new ArrayRouteCollection)
        ->add('GET', '/api/users', UserController::class.'@index', middleware: ['auth:sanctum'], metadata: ['name' => 'users.index'])
        ->add('GET', '/api/users/{id}', UserController::class.'@show', metadata: ['name' => 'users.show'])
        ->add('GET', '/internal/health', UserController::class.'@index', metadata: ['name' => 'health'])
        ->add('GET', '/api/legacy', UserController::class.'@index', metadata: ['name' => 'legacy']);

    $apex = ApexDocs::make($config ?? new Config(title: 'Fixture API', version: '0.1.0', excludePaths: ['api/legacy']))
        ->routes($routes)
        ->security(new \ApexDocs\Bridge\Laravel\SecurityDetector)   // no Laravel container needed; detects auth:* middleware
        ->filterRoutes(fn ($route) => true);

    return new McpServer(new InProcessSnapshotProvider($apex), dirname(__DIR__, 3).'/resources/ai', memoSeconds: 0);
}

/** @return array<string, mixed> */
function mcpCall(McpServer $server, string $method, array $params = [], int|string|null $id = 1): array
{
    $response = $server->handle(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
    expect($response)->toBeArray();

    return $response;
}

function mcpTool(McpServer $server, string $name, array $arguments = []): mixed
{
    $response = mcpCall($server, 'tools/call', ['name' => $name, 'arguments' => $arguments]);
    if (! array_key_exists('result', $response)) {
        test()->fail('Tool call failed: '.json_encode($response['error'] ?? null));
    }
    $result = $response['result'];
    if (($result['isError'] ?? false) === true) {
        test()->fail('Tool returned isError: '.($result['content'][0]['text'] ?? ''));
    }

    $text = $result['content'][0]['text'];
    $decoded = json_decode($text, true);

    return $decoded ?? $text;
}

it('answers initialize with protocol version and capabilities', function () {
    $r = mcpCall(mcpServer(), 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'pest', 'version' => '1']]);

    expect($r['result']['protocolVersion'])->toBe(McpServer::PROTOCOL_VERSION)
        ->and($r['result']['serverInfo']['name'])->toBe('apexdocs')
        ->and($r['result']['capabilities'])->toHaveKeys(['tools', 'resources', 'prompts']);
});

it('ignores notifications and rejects malformed input', function () {
    $server = mcpServer();

    expect($server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']))->toBeNull();
    expect(json_decode((string) $server->handleRaw('{nope'), true)['error']['code'])->toBe(-32700);
    expect(mcpCall($server, 'no/such')['error']['code'])->toBe(-32601);
    expect(mcpCall($server, 'tools/call', ['name' => 'nope'])['error']['code'])->toBe(-32602);
});

it('lists every tool, resource and prompt', function () {
    $server = mcpServer();

    expect(array_column(mcpCall($server, 'tools/list')['result']['tools'], 'name'))->toBe([
        'spec_summary', 'list_operations', 'describe_operation', 'list_routes', 'list_schemas', 'get_schema',
        'validate_spec', 'diff_spec', 'export_spec', 'get_config', 'attribute_reference', 'read_reference', 'search_reference',
    ]);

    $uris = array_column(mcpCall($server, 'resources/list')['result']['resources'], 'uri');
    expect($uris)->toContain('apexdocs://skill', 'apexdocs://skill/attributes', 'apexdocs://spec.json', 'apexdocs://config')
        ->and($uris)->toHaveCount(count(McpServer::REFERENCES) + 2);

    expect(array_column(mcpCall($server, 'prompts/list')['result']['prompts'], 'name'))->toBe(['document-endpoint', 'fix-validation', 'missing-endpoint']);
});

it('summarises the spec and explains excluded routes', function () {
    $summary = mcpTool(mcpServer(), 'spec_summary');

    expect($summary['info']['title'])->toBe('Fixture API')
        ->and($summary['counts']['routes_seen'])->toBe(4)
        ->and($summary['counts']['routes_documented'])->toBe(2)
        ->and($summary['counts']['paths'])->toBe(2)
        ->and($summary['operations_per_tag'])->toBe(['Users' => 2])
        ->and($summary['excluded_routes_by_reason'])->toBe(['api_path_prefix' => 1, 'exclude_paths' => 1]);
});

it('lists routes with inclusion reasons', function () {
    $server = mcpServer();
    $excluded = mcpTool($server, 'list_routes', ['only_excluded' => true]);

    expect(array_column($excluded, 'path'))->toBe(['/internal/health', '/api/legacy'])
        ->and(array_column($excluded, 'reason'))->toBe(['api_path_prefix', 'exclude_paths']);

    $users = mcpTool($server, 'list_routes', ['path_contains' => 'users/{id}']);
    expect($users)->toHaveCount(1)
        ->and($users[0]['included'])->toBeTrue()
        ->and($users[0]['operations'])->toBe(['get' => 'users_show'])
        ->and($users[0]['handler'])->toBe(UserController::class.'@show');
});

it('lists and describes operations', function () {
    $server = mcpServer();

    $all = mcpTool($server, 'list_operations');
    expect(array_column($all, 'operationId'))->toBe(['users_index', 'users_show']);

    $secured = mcpTool($server, 'list_operations', ['secured' => true]);
    expect(array_column($secured, 'path'))->toBe(['/api/users'])
        ->and($secured[0]['security'])->toBe([['bearerAuth' => []]])
        ->and($secured[0]['responses'])->toContain('401');

    expect(array_column(mcpTool($server, 'list_operations', ['secured' => false]), 'path'))->toBe(['/api/users/{id}']);

    $byTag = mcpTool($server, 'list_operations', ['tag' => 'Nope']);
    expect($byTag)->toBe([]);

    $op = mcpTool($server, 'describe_operation', ['path' => '/api/users/{id}', 'method' => 'GET']);
    expect($op['operation']['summary'])->toBe('Show a user')
        ->and($op['operation']['responses'])->toHaveKeys(['200', '404'])
        ->and($op['source_route']['name'])->toBe('users.show');

    $missing = mcpCall($server, 'tools/call', ['name' => 'describe_operation', 'arguments' => ['path' => '/api/nothing', 'method' => 'get']]);
    expect($missing['error']['code'])->toBe(-32602)
        ->and($missing['error']['message'])->toContain('list_routes');
});

it('lists and returns component schemas', function () {
    $server = mcpServer();

    $schemas = mcpTool($server, 'list_schemas');
    expect(array_column($schemas, 'name'))->toContain('UserDto', 'ValidationError', 'PaginationMeta');

    $user = mcpTool($server, 'get_schema', ['name' => 'UserDto']);
    expect($user['ref'])->toBe('#/components/schemas/UserDto')
        ->and($user['schema']['properties'])->toHaveKeys(['id', 'name', 'email', 'isAdmin'])
        ->and($user['schema']['required'])->toBe(['id', 'name']);

    expect(mcpCall($server, 'tools/call', ['name' => 'get_schema', 'arguments' => ['name' => 'Nope']])['error']['code'])->toBe(-32602);
});

it('validates and diffs the spec', function () {
    $server = mcpServer();

    $validation = mcpTool($server, 'validate_spec', ['strict' => true]);
    expect($validation['valid'])->toBeTrue()->and($validation['errors'])->toBe([]);

    $current = json_decode(mcpCall($server, 'resources/read', ['uri' => 'apexdocs://spec.json'])['result']['contents'][0]['text'], true);
    $baseline = $current;
    $baseline['paths']['/api/gone'] = ['delete' => ['responses' => ['204' => ['description' => 'x']]]];
    unset($baseline['paths']['/api/users']);

    $diff = mcpTool($server, 'diff_spec', ['baseline_json' => json_encode($baseline)]);
    expect($diff['has_breaking_changes'])->toBeTrue()
        ->and($diff['breaking'])->toBe(['DELETE /api/gone removed'])
        ->and($diff['added'])->toBe(['GET /api/users']);

    expect(mcpCall($server, 'tools/call', ['name' => 'diff_spec', 'arguments' => []])['error']['code'])->toBe(-32602);
    expect(mcpCall($server, 'tools/call', ['name' => 'diff_spec', 'arguments' => ['baseline_path' => '/nope.json']])['error']['code'])->toBe(-32602);
});

it('exports the spec to a file', function () {
    $dir = sys_get_temp_dir().'/apexdocs_mcp_'.uniqid();
    $path = $dir.'/nested/spec.yaml';

    $message = mcpTool(mcpServer(), 'export_spec', ['format' => 'openapi-yaml', 'path' => $path]);

    expect($message)->toContain('Exported openapi-yaml')
        ->and(file_get_contents($path))->toContain('openapi: 3.1.0');

    unlink($path);
    rmdir(dirname($path));
    rmdir($dir);

    expect(mcpCall(mcpServer(), 'tools/call', ['name' => 'export_spec', 'arguments' => ['format' => 'pdf', 'path' => '/x']])['error']['code'])->toBe(-32602);
});

it('returns the effective config', function () {
    $config = mcpTool(mcpServer(), 'get_config');

    expect($config['title'])->toBe('Fixture API')
        ->and($config['pathPrefixes'])->toBe(['api'])
        ->and($config['excludePaths'])->toBe(['api/legacy'])
        ->and($config)->toHaveKeys(['maxSchemaDepth', 'autoDetectSecurity', 'theme']);
});

it('reflects every attribute class', function () {
    $attributes = mcpTool(mcpServer(), 'attribute_reference');
    $names = array_column($attributes, 'attribute');
    $files = array_map(fn (string $f) => '#['.basename($f, '.php').']', glob(dirname(__DIR__, 3).'/src/Attribute/*.php'));

    expect($names)->toEqualCanonicalizing($files);

    $apiResponse = array_values(array_filter($attributes, fn (array $a) => $a['attribute'] === '#[ApiResponse]'))[0];
    expect($apiResponse['repeatable'])->toBeTrue()
        ->and($apiResponse['targets'])->toBe(['method'])
        ->and(array_column($apiResponse['parameters'], 'name'))->toBe(['status', 'description', 'resource', 'collection', 'schema', 'headers', 'examples']);
});

it('ships a reference file for every topic and can search them', function () {
    $server = mcpServer();

    foreach (array_keys(McpServer::REFERENCES) as $topic) {
        $text = mcpTool($server, 'read_reference', ['topic' => $topic]);
        expect($text)->toBeString()->not->toBeEmpty();
        expect(mcpCall($server, 'resources/read', ['uri' => McpServer::referenceUri($topic)])['result']['contents'][0]['text'])->toBe($text);
    }

    $hits = mcpTool($server, 'search_reference', ['query' => 'MapRequestPayload', 'context' => 1]);
    expect($hits['hits'])->toBeGreaterThan(0)
        ->and(array_column($hits['results'], 'topic'))->toContain('symfony');

    expect(mcpCall($server, 'tools/call', ['name' => 'read_reference', 'arguments' => ['topic' => 'nope']])['error']['code'])->toBe(-32602);
    expect(mcpCall($server, 'tools/call', ['name' => 'search_reference', 'arguments' => ['query' => 'x']])['error']['code'])->toBe(-32602);
});

it('documents every attribute and config key in the reference', function () {
    $server = mcpServer();
    $all = implode("\n", array_map(fn (string $t) => mcpTool($server, 'read_reference', ['topic' => $t]), array_keys(McpServer::REFERENCES)));

    $symbols = array_map(fn (string $f) => '#['.basename($f, '.php'), glob(dirname(__DIR__, 3).'/src/Attribute/*.php'));

    $config = require dirname(__DIR__, 3).'/src/Bridge/Laravel/config/apexdocs.php';
    foreach ($config as $section => $values) {
        if (is_array($values) && ! array_is_list($values)) {
            foreach (array_keys($values) as $key) {
                $symbols[] = "{$section}.{$key}";
            }
        } else {
            $symbols[] = $section;
        }
    }

    foreach ((new ReflectionClass(Config::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
        $symbols[] = $prop->getName();
    }

    foreach (['apexdocs:generate', 'apexdocs:validate', 'apexdocs:export', 'apexdocs:diff', 'apexdocs:watch', 'apexdocs:mock', 'apexdocs:mcp', 'apexdocs:install-ai',
        'filterRoutes', 'transformDocument', 'transformOperation', 'SpecCache', 'SpecPayload', 'Handler', 'ArrayRouteCollection',
        'RouteCollectionInterface', 'ValidationExtractorInterface', 'SecurityDetectorInterface', 'DocumentTransformerInterface', 'OperationTransformerInterface',
        'JsonExporter', 'YamlExporter', 'PostmanExporter', 'InsomniaExporter', 'BrunoExporter', 'SpecValidator', 'SpecDiff', 'MapRequestPayload', 'IsGranted'] as $s) {
        $symbols[] = $s;
    }

    $missing = array_values(array_filter(array_unique($symbols), fn (string $s) => ! str_contains($all, $s)));

    expect($missing)->toBe([]);
});

it('renders prompts with arguments', function () {
    $server = mcpServer();

    $prompt = mcpCall($server, 'prompts/get', ['name' => 'document-endpoint', 'arguments' => ['path' => '/api/users/{id}', 'method' => 'get']])['result'];
    expect($prompt['messages'][0]['content']['text'])->toContain('GET /api/users/{id}');

    expect(mcpCall($server, 'prompts/get', ['name' => 'missing-endpoint', 'arguments' => ['path' => '/api/x']])['result']['messages'][0]['content']['text'])->toContain('list_routes');
    expect(mcpCall($server, 'prompts/get', ['name' => 'nope'])['error']['code'])->toBe(-32602);
});

it('memoises snapshots for consecutive calls and honours fresh', function () {
    $provider = new class implements SnapshotProviderInterface
    {
        public int $calls = 0;

        public function snapshot(): Snapshot
        {
            $this->calls++;

            return new Snapshot(['openapi' => '3.1.0', 'info' => ['title' => 'T', 'version' => '1'], 'paths' => []], [], [], 'now', 1);
        }
    };
    $server = new McpServer($provider, dirname(__DIR__, 3).'/resources/ai', memoSeconds: 60);

    mcpTool($server, 'spec_summary');
    mcpTool($server, 'get_config');
    expect($provider->calls)->toBe(1);

    mcpTool($server, 'spec_summary', ['fresh' => true]);
    expect($provider->calls)->toBe(2);
});

it('reports provider failures as JSON-RPC errors, not crashes', function () {
    $provider = new class implements SnapshotProviderInterface
    {
        public function snapshot(): Snapshot
        {
            throw new RuntimeException('artisan exploded');
        }
    };
    $server = new McpServer($provider, dirname(__DIR__, 3).'/resources/ai');

    $r = mcpCall($server, 'tools/call', ['name' => 'spec_summary', 'arguments' => []]);
    expect($r['result']['isError'])->toBeTrue()
        ->and($r['result']['content'][0]['text'])->toContain('artisan exploded');
});

it('serves newline-delimited JSON-RPC over streams', function () {
    $in = fopen('php://memory', 'w+b');
    $out = fopen('php://memory', 'w+b');
    fwrite($in, json_encode(['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'ping'])."\n");
    fwrite($in, json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])."\n\n");
    fwrite($in, json_encode(['jsonrpc' => '2.0', 'id' => 'b', 'method' => 'tools/list'])."\n");
    rewind($in);

    mcpServer()->serve($in, $out);
    rewind($out);
    $lines = array_values(array_filter(explode("\n", stream_get_contents($out))));

    expect($lines)->toHaveCount(2)
        ->and(json_decode($lines[0], true))->toBe(['jsonrpc' => '2.0', 'id' => 'a', 'result' => []])
        ->and(json_decode($lines[1], true)['id'])->toBe('b');
});
