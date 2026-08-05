<?php

declare(strict_types=1);

namespace ApexDocs\Mcp;

use ApexDocs\Diff\SpecDiff;
use ApexDocs\Exception\ExporterException;
use ApexDocs\Export\BrunoExporter;
use ApexDocs\Export\InsomniaExporter;
use ApexDocs\Export\JsonExporter;
use ApexDocs\Export\PostmanExporter;
use ApexDocs\Export\YamlExporter;
use ApexDocs\Spec\Document;
use ApexDocs\Validation\SpecValidator;

/**
 * Model Context Protocol server for ApexDocs  stdio transport, newline-
 * delimited JSON-RPC 2.0, no dependencies beyond the core.
 *
 * Lets an AI coding agent see the API documentation exactly as the generator
 * produces it from the code on disk: which routes are documented (and why one
 * is not), each operation's parameters / body / responses / security, the
 * component schemas, validation findings, breaking-change diffs, and exports.
 *
 * The server never inspects framework internals itself; everything comes from
 * a {@see SnapshotProviderInterface}, so the same server serves Laravel,
 * Symfony and standalone projects.
 */
final class McpServer
{
    public const PROTOCOL_VERSION = '2025-06-18';

    public const NAME = 'apexdocs';

    public const VERSION = '0.2.0';

    private const EXPORT_FORMATS = ['openapi-json', 'openapi-yaml', 'postman', 'insomnia', 'bruno'];

    /**
     * Reference topics shipped with the skill, exposed as resources
     * (apexdocs://skill/{topic}) and via read_reference / search_reference.
     *
     * @var array<string, array{0: string, 1: string}>  topic => [relative file, description]
     */
    public const REFERENCES = [
        'skill' => ['skills/apexdocs/SKILL.md', 'Overview, golden rules, workflow, debugging checklist'],
        'attributes' => ['skills/apexdocs/references/attributes.md', 'Every PHP attribute: signature, target, precedence, what it emits'],
        'schemas-and-types' => ['skills/apexdocs/references/schemas-and-types.md', 'How DTOs, API resources, return types, generics, enums and PHPDoc become JSON Schema'],
        'inference' => ['skills/apexdocs/references/inference.md', 'What the generator infers without attributes: operationId, tags, summary, parameters, responses, security, request bodies'],
        'config' => ['skills/apexdocs/references/config.md', 'Every config key (Laravel array, Symfony tree, Config object) and its effect'],
        'commands' => ['skills/apexdocs/references/commands.md', 'apexdocs:generate|validate|export|diff|watch|mock|mcp|install-ai|snapshot'],
        'laravel' => ['skills/apexdocs/references/laravel.md', 'Laravel bridge: routes, FormRequest rule mapping, security detection, docs routes, caching, environments, facade'],
        'symfony' => ['skills/apexdocs/references/symfony.md', 'Symfony bundle: config tree, services, MapRequestPayload, IsGranted, route requirements'],
        'standalone' => ['skills/apexdocs/references/standalone.md', 'Standalone / PSR-15 / custom framework bridge: ApexDocs, Config, Route, ArrayRouteCollection, Handler, contracts'],
        'customisation' => ['skills/apexdocs/references/customisation.md', 'Transformers, filterRoutes, webhooks, Document/Operation/Components API, extensions'],
        'exports-and-http' => ['skills/apexdocs/references/exports-and-http.md', 'Exporters, SpecPayload, docs UI, theme, cache'],
        'validation-and-diff' => ['skills/apexdocs/references/validation-and-diff.md', 'Exact rules of SpecValidator and SpecDiff, and how to fix each finding'],
        'testing' => ['skills/apexdocs/references/testing.md', 'Pest recipes for asserting the generated spec'],
        'agents' => ['AGENTS.md', 'Short instructions block for AGENTS.md / CLAUDE.md'],
    ];

    private ?Snapshot $memo = null;

    private float $memoExpires = 0.0;

    /**
     * @param  string  $resourcesPath  directory holding the shipped `resources/ai` files
     * @param  string|null  $attributesPath  directory of the attribute classes (for attribute_reference)
     * @param  float  $memoSeconds  how long consecutive tool calls share one snapshot
     */
    public function __construct(
        private SnapshotProviderInterface $provider,
        private string $resourcesPath,
        private ?string $attributesPath = null,
        private float $memoSeconds = 2.0,
    ) {
        $this->attributesPath ??= dirname(__DIR__).'/Attribute';
    }

    // ── Transport ─────────────────────────────────────────────────────────────

    /**
     * Serve until the input stream closes.
     *
     * @param  resource  $input
     * @param  resource  $output
     */
    public function serve($input, $output): void
    {
        while (($line = fgets($input)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $response = $this->handleRaw($line);
            if ($response !== null) {
                fwrite($output, $response."\n");
                fflush($output);
            }
        }
    }

    /**
     * Handle one raw JSON-RPC message. Null for notifications.
     */
    public function handleRaw(string $json): ?string
    {
        try {
            $message = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->encode(self::error(null, -32700, 'Parse error: '.$e->getMessage()));
        }

        if (! is_array($message)) {
            return $this->encode(self::error(null, -32600, 'Invalid Request'));
        }

        $response = $this->handle($message);

        return $response === null ? null : $this->encode($response);
    }

    /**
     * @param  array<array-key, mixed>  $message
     * @return array<string, mixed>|null
     */
    public function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $isNotification = ! array_key_exists('id', $message);

        if (! is_string($method)) {
            return $isNotification ? null : self::error($id, -32600, 'Invalid Request: missing method');
        }

        if (str_starts_with($method, 'notifications/')) {
            return null;
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize(),
                'ping' => [],
                'tools/list' => ['tools' => $this->toolDefinitions()],
                'tools/call' => $this->callTool($params),
                'resources/list' => ['resources' => $this->resourceDefinitions()],
                'resources/read' => $this->readResource($params),
                'prompts/list' => ['prompts' => $this->promptDefinitions()],
                'prompts/get' => $this->getPrompt($params),
                default => throw new McpException("Method not found: {$method}", -32601),
            };
        } catch (McpException $e) {
            return $isNotification ? null : self::error($id, $e->getCode(), $e->getMessage());
        } catch (\Throwable $e) {
            return $isNotification ? null : self::error($id, -32603, $e->getMessage());
        }

        return $isNotification ? null : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['subscribe' => false, 'listChanged' => false],
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => ['name' => self::NAME, 'version' => self::VERSION],
            'instructions' => 'OpenAPI 3.1 documentation for this application, generated by anil/apexdocs from the '
                .'code on disk. Start with spec_summary; use list_routes to see why an endpoint is missing, '
                .'describe_operation before editing a controller, validate_spec after any change. '
                .'read_reference/search_reference explain every attribute, config key and inference rule.',
        ];
    }

    // ── Snapshot ──────────────────────────────────────────────────────────────

    private function snapshot(bool $fresh = false): Snapshot
    {
        $now = microtime(true);
        if (! $fresh && $this->memo !== null && $now < $this->memoExpires) {
            return $this->memo;
        }

        $this->memo = $this->provider->snapshot();
        $this->memoExpires = $now + $this->memoSeconds;

        return $this->memo;
    }

    // ── Tools ─────────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function toolDefinitions(): array
    {
        $ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $none = ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false];
        $fresh = ['fresh' => ['type' => 'boolean', 'default' => false, 'description' => 'Force a brand-new snapshot instead of reusing one taken in the last few seconds']];

        return [
            [
                'name' => 'spec_summary',
                'description' => 'Overview of the generated OpenAPI document: info, servers, counts of paths/operations/schemas/tags/webhooks/security schemes, operations per tag, and how many routes were excluded and why.',
                'inputSchema' => ['type' => 'object', 'properties' => $fresh, 'additionalProperties' => false],
                'annotations' => $ro + ['title' => 'Spec summary'],
            ],
            [
                'name' => 'list_operations',
                'description' => 'Every documented operation (method, path, operationId, summary, tags, security, whether it has a request body, response status codes). Filter by tag, HTTP method, path substring or security presence.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $fresh + [
                        'tag' => ['type' => 'string'],
                        'method' => ['type' => 'string', 'description' => 'get|post|put|patch|delete|trace'],
                        'path_contains' => ['type' => 'string'],
                        'secured' => ['type' => 'boolean', 'description' => 'true = only operations with a security requirement, false = only public ones'],
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => $ro + ['title' => 'List operations'],
            ],
            [
                'name' => 'describe_operation',
                'description' => 'The full OpenAPI Operation Object for one path + method, plus the source route (handler class@method, middleware, route name). Use before editing a controller to see exactly what is already inferred.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $fresh + [
                        'path' => ['type' => 'string', 'description' => 'OpenAPI path template, e.g. /api/users/{id}'],
                        'method' => ['type' => 'string', 'description' => 'HTTP method, e.g. get'],
                    ],
                    'required' => ['path', 'method'],
                    'additionalProperties' => false,
                ],
                'annotations' => $ro + ['title' => 'Describe operation'],
            ],
            [
                'name' => 'list_routes',
                'description' => 'Every route the framework reports, with included=true/false and the exact reason an excluded route is not documented (api_path_prefix, exclude_paths, spec_group, filterRoutes, hidden). The first place to look when an endpoint is missing from the docs.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $fresh + [
                        'only_excluded' => ['type' => 'boolean', 'default' => false],
                        'path_contains' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => $ro + ['title' => 'List routes'],
            ],
            [
                'name' => 'list_schemas',
                'description' => 'Names of every components/schemas entry with type, property count and a one-line shape.',
                'inputSchema' => ['type' => 'object', 'properties' => $fresh, 'additionalProperties' => false],
                'annotations' => $ro + ['title' => 'List schemas'],
            ],
            [
                'name' => 'get_schema',
                'description' => 'One components/schemas entry in full (JSON Schema 2020-12 as emitted).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $fresh + ['name' => ['type' => 'string', 'description' => 'Component name, e.g. UserDto']],
                    'required' => ['name'],
                    'additionalProperties' => false,
                ],
                'annotations' => $ro + ['title' => 'Get schema'],
            ],
            [
                'name' => 'validate_spec',
                'description' => 'Run the same structural validation as `apexdocs:validate`: errors (missing descriptions, duplicate operationIds, unmatched path params, unresolved $refs, undefined security schemes, no paths) and warnings (missing operationId/summary).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $fresh + ['strict' => ['type' => 'boolean', 'default' => false, 'description' => 'Treat warnings as failures']],
                    'additionalProperties' => false,
                ],
                'annotations' => $ro + ['title' => 'Validate spec'],
            ],
            [
                'name' => 'diff_spec',
                'description' => 'Compare the current spec with a baseline (same rules as `apexdocs:diff`): breaking (removed paths/methods/2xx-3xx responses, new required params or body fields), added, changed. Pass either a baseline file path or the baseline JSON itself.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $fresh + [
                        'baseline_path' => ['type' => 'string', 'description' => 'Path to a saved OpenAPI JSON file'],
                        'baseline_json' => ['type' => 'string', 'description' => 'Baseline OpenAPI document as a JSON string'],
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => $ro + ['title' => 'Diff spec'],
            ],
            [
                'name' => 'export_spec',
                'description' => 'Write the spec to a file in one of: openapi-json, openapi-yaml, postman, insomnia, bruno. Creates parent directories; overwrites the target file.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $fresh + [
                        'format' => ['type' => 'string', 'enum' => self::EXPORT_FORMATS],
                        'path' => ['type' => 'string', 'description' => 'Absolute or cwd-relative output file path'],
                    ],
                    'required' => ['format', 'path'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['title' => 'Export spec', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'get_config',
                'description' => 'The effective ApexDocs Config (title, version, pathPrefixes, excludePaths, specGroup, responses flags, maxSchemaDepth, security schemes, cache, UI settings, transformers, webhook scan paths).',
                'inputSchema' => ['type' => 'object', 'properties' => $fresh, 'additionalProperties' => false],
                'annotations' => $ro + ['title' => 'Get config'],
            ],
            [
                'name' => 'attribute_reference',
                'description' => 'Live reflection of every ApexDocs\\Attribute class installed: constructor parameters with types and defaults, allowed targets (class/method), repeatable or not. Always matches the installed package version.',
                'inputSchema' => $none,
                'annotations' => $ro + ['title' => 'Attribute reference'],
            ],
            [
                'name' => 'read_reference',
                'description' => 'Read one topic of the bundled apexdocs reference (derived from the package source). Topics: '.implode(', ', array_keys(self::REFERENCES)).'.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['topic' => ['type' => 'string', 'enum' => array_keys(self::REFERENCES)]],
                    'required' => ['topic'],
                    'additionalProperties' => false,
                ],
                'annotations' => $ro + ['title' => 'Read reference'],
            ],
            [
                'name' => 'search_reference',
                'description' => 'Case-insensitive full-text search across every reference topic; returns matching lines with topic, line number and context.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'minLength' => 2],
                        'context' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10, 'default' => 2],
                    ],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
                'annotations' => $ro + ['title' => 'Search reference'],
            ],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (! is_string($name)) {
            throw new McpException('tools/call requires a "name"', -32602);
        }

        $fresh = ($args['fresh'] ?? false) === true;

        try {
            $result = match ($name) {
                'spec_summary' => $this->specSummary($this->snapshot($fresh)),
                'list_operations' => $this->listOperations($this->snapshot($fresh), $args),
                'describe_operation' => $this->describeOperation($this->snapshot($fresh), self::str($args, 'path'), self::str($args, 'method')),
                'list_routes' => $this->listRoutes($this->snapshot($fresh), $args),
                'list_schemas' => $this->listSchemas($this->snapshot($fresh)),
                'get_schema' => $this->getSchema($this->snapshot($fresh), self::str($args, 'name')),
                'validate_spec' => $this->validateSpec($this->snapshot($fresh), ($args['strict'] ?? false) === true),
                'diff_spec' => $this->diffSpec($this->snapshot($fresh), $args),
                'export_spec' => $this->exportSpec($this->snapshot($fresh), self::str($args, 'format'), self::str($args, 'path')),
                'get_config' => $this->snapshot($fresh)->config,
                'attribute_reference' => $this->attributeReference(),
                'read_reference' => $this->readReference(self::str($args, 'topic')),
                'search_reference' => $this->searchReference(self::str($args, 'query'), is_int($args['context'] ?? null) ? $args['context'] : 2),
                default => throw new McpException("Unknown tool: {$name}", -32602),
            };
        } catch (McpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return self::toolText($e::class.': '.$e->getMessage(), isError: true);
        }

        return self::toolText(is_string($result) ? $result : $this->encode($result, pretty: true));
    }

    /** @return array<string, mixed> */
    private function specSummary(Snapshot $s): array
    {
        $spec = $s->spec;
        $operations = 0;
        $perTag = [];
        foreach ($spec['paths'] ?? [] as $methods) {
            if (! is_array($methods)) {
                continue;
            }
            foreach ($methods as $op) {
                if (! is_array($op)) {
                    continue;
                }
                $operations++;
                foreach ($op['tags'] ?? ['(untagged)'] as $tag) {
                    $perTag[(string) $tag] = ($perTag[(string) $tag] ?? 0) + 1;
                }
            }
        }

        $excluded = [];
        foreach ($s->routes as $route) {
            if (($route['included'] ?? false) === false) {
                $reason = (string) ($route['reason'] ?? 'unknown');
                $excluded[$reason] = ($excluded[$reason] ?? 0) + 1;
            }
        }

        return [
            'generated_at' => $s->generatedAt,
            'generation_ms' => $s->durationMs,
            'openapi' => $spec['openapi'] ?? null,
            'info' => $spec['info'] ?? [],
            'servers' => $spec['servers'] ?? [],
            'counts' => [
                'routes_seen' => count($s->routes),
                'routes_documented' => count(array_filter($s->routes, static fn (array $r): bool => ($r['included'] ?? false) === true)),
                'paths' => count($spec['paths'] ?? []),
                'operations' => $operations,
                'schemas' => count($spec['components']['schemas'] ?? []),
                'security_schemes' => count($spec['components']['securitySchemes'] ?? []),
                'tags' => count($spec['tags'] ?? []),
                'webhooks' => count($spec['webhooks'] ?? []),
            ],
            'operations_per_tag' => $perTag,
            'excluded_routes_by_reason' => $excluded,
            'security_schemes' => array_keys($spec['components']['securitySchemes'] ?? []),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $args
     * @return list<array<string, mixed>>
     */
    private function listOperations(Snapshot $s, array $args): array
    {
        $tag = self::optStr($args, 'tag');
        $method = self::optStr($args, 'method');
        $needle = self::optStr($args, 'path_contains');
        $secured = array_key_exists('secured', $args) && is_bool($args['secured']) ? $args['secured'] : null;

        $out = [];
        foreach ($s->spec['paths'] ?? [] as $path => $methods) {
            if (! is_array($methods)) {
                continue;
            }
            foreach ($methods as $verb => $op) {
                if (! is_array($op)) {
                    continue;
                }
                $verb = strtolower((string) $verb);
                if ($method !== null && strtolower($method) !== $verb) {
                    continue;
                }
                if ($needle !== null && ! str_contains((string) $path, $needle)) {
                    continue;
                }
                if ($tag !== null && ! in_array($tag, $op['tags'] ?? [], true)) {
                    continue;
                }
                $hasSecurity = isset($op['security']) && $op['security'] !== [];
                if ($secured !== null && $hasSecurity !== $secured) {
                    continue;
                }

                $out[] = [
                    'method' => $verb,
                    'path' => (string) $path,
                    'operationId' => $op['operationId'] ?? null,
                    'summary' => $op['summary'] ?? null,
                    'tags' => $op['tags'] ?? [],
                    'deprecated' => (bool) ($op['deprecated'] ?? false),
                    'security' => $op['security'] ?? null,
                    'has_request_body' => isset($op['requestBody']),
                    'parameters' => array_map(static fn (array $p): string => ($p['in'] ?? '?').':'.($p['name'] ?? '?'), array_filter($op['parameters'] ?? [], 'is_array')),
                    'responses' => array_map('strval', array_keys($op['responses'] ?? [])),
                ];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function describeOperation(Snapshot $s, string $path, string $method): array
    {
        $method = strtolower($method);
        $path = '/'.ltrim($path, '/');
        $op = $s->spec['paths'][$path][$method] ?? null;

        if (! is_array($op)) {
            $known = [];
            foreach ($s->spec['paths'] ?? [] as $p => $methods) {
                if (is_array($methods) && (str_contains((string) $p, trim($path, '/')) || levenshtein((string) $p, $path) <= 3)) {
                    $known[] = strtoupper(implode('|', array_keys($methods))).' '.$p;
                }
            }
            throw new McpException(
                "No documented operation for ".strtoupper($method)." {$path}."
                .($known !== [] ? ' Similar: '.implode(', ', array_slice($known, 0, 5)).'.' : '')
                .' Use list_routes to see whether the route exists and why it may be excluded.',
                -32602,
            );
        }

        $source = null;
        foreach ($s->routes as $route) {
            $routePath = preg_replace('/\{([^{}\/?]+)\?}/u', '{$1}', (string) ($route['path'] ?? ''));
            if ($routePath === $path && in_array(strtoupper($method), $route['methods'] ?? [], true)) {
                $source = $route;
                break;
            }
        }

        return ['method' => $method, 'path' => $path, 'operation' => $op, 'source_route' => $source];
    }

    /**
     * @param  array<array-key, mixed>  $args
     * @return list<array<string, mixed>>
     */
    private function listRoutes(Snapshot $s, array $args): array
    {
        $onlyExcluded = ($args['only_excluded'] ?? false) === true;
        $needle = self::optStr($args, 'path_contains');

        return array_values(array_filter($s->routes, static function (array $r) use ($onlyExcluded, $needle): bool {
            if ($onlyExcluded && ($r['included'] ?? false) === true) {
                return false;
            }

            return $needle === null || str_contains((string) ($r['path'] ?? ''), $needle);
        }));
    }

    /** @return list<array<string, mixed>> */
    private function listSchemas(Snapshot $s): array
    {
        $out = [];
        foreach ($s->spec['components']['schemas'] ?? [] as $name => $schema) {
            if (! is_array($schema)) {
                continue;
            }
            $props = $schema['properties'] ?? ($schema['allOf'][1]['properties'] ?? []);
            $out[] = [
                'name' => (string) $name,
                'type' => $schema['type'] ?? (isset($schema['allOf']) ? 'allOf' : null),
                'title' => $schema['title'] ?? null,
                'properties' => is_array($props) ? array_keys($props) : [],
                'required' => $schema['required'] ?? ($schema['allOf'][1]['required'] ?? []),
                'enum' => $schema['enum'] ?? null,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function getSchema(Snapshot $s, string $name): array
    {
        $schema = $s->spec['components']['schemas'][$name] ?? null;
        if (! is_array($schema)) {
            throw new McpException("No component schema named '{$name}'. Available: ".implode(', ', array_keys($s->spec['components']['schemas'] ?? [])), -32602);
        }

        return ['name' => $name, 'ref' => '#/components/schemas/'.$name, 'schema' => $schema];
    }

    /** @return array<string, mixed> */
    private function validateSpec(Snapshot $s, bool $strict): array
    {
        $result = (new SpecValidator)->validate($s->spec);
        $ok = $result['errors'] === [] && ! ($strict && $result['warnings'] !== []);

        return ['valid' => $ok, 'strict' => $strict] + $result;
    }

    /**
     * @param  array<array-key, mixed>  $args
     * @return array<string, mixed>
     */
    private function diffSpec(Snapshot $s, array $args): array
    {
        $json = self::optStr($args, 'baseline_json');
        $path = self::optStr($args, 'baseline_path');

        if ($json === null && $path === null) {
            throw new McpException('Provide baseline_path or baseline_json', -32602);
        }

        if ($json === null) {
            if (! is_file($path) || ! is_readable($path)) {
                throw new McpException("Baseline file not found or unreadable: {$path}", -32602);
            }
            $json = (string) file_get_contents($path);
        }

        $base = json_decode($json, true);
        if (! is_array($base) || ! SpecDiff::hasUsablePaths($base)) {
            throw new McpException('Baseline is not a valid OpenAPI JSON document', -32602);
        }

        $result = (new SpecDiff)->compare($base, $s->spec);

        return ['has_breaking_changes' => $result['breaking'] !== []] + $result;
    }

    private function exportSpec(Snapshot $s, string $format, string $path): string
    {
        if (! in_array($format, self::EXPORT_FORMATS, true)) {
            throw new McpException("Unknown format '{$format}'. Supported: ".implode(', ', self::EXPORT_FORMATS), -32602);
        }
        if (trim($path) === '') {
            throw new McpException('Argument "path" is required', -32602);
        }

        $doc = Document::fromArray($s->spec);

        try {
            match ($format) {
                'openapi-json' => (new JsonExporter)->toFile($doc, $path),
                'openapi-yaml' => (new YamlExporter)->toFile($doc, $path),
                'postman' => (new PostmanExporter)->toFile($doc, $path),
                'insomnia' => (new InsomniaExporter)->toFile($doc, $path),
                'bruno' => (new BrunoExporter)->toFile($doc, $path),
            };
        } catch (ExporterException $e) {
            throw new McpException($e->getMessage(), -32603);
        }

        return "Exported {$format} to {$path} (".(is_file($path) ? filesize($path) : 0).' bytes)';
    }

    /** @return list<array<string, mixed>> */
    private function attributeReference(): array
    {
        $out = [];
        $dir = (string) $this->attributesPath;

        foreach (is_dir($dir) ? scandir($dir) ?: [] : [] as $file) {
            if (! str_ends_with($file, '.php')) {
                continue;
            }
            $class = 'ApexDocs\\Attribute\\'.substr($file, 0, -4);
            if (! class_exists($class)) {
                continue;
            }

            $ref = new \ReflectionClass($class);
            $meta = $ref->getAttributes(\Attribute::class)[0] ?? null;
            $flags = $meta !== null ? (int) ($meta->getArguments()[0] ?? \Attribute::TARGET_ALL) : \Attribute::TARGET_ALL;

            $params = [];
            foreach ($ref->getConstructor()?->getParameters() ?? [] as $param) {
                $entry = [
                    'name' => $param->getName(),
                    'type' => (string) ($param->getType() ?? 'mixed'),
                    'required' => ! $param->isDefaultValueAvailable(),
                ];
                if ($param->isDefaultValueAvailable()) {
                    $entry['default'] = $param->getDefaultValue();
                }
                $params[] = $entry;
            }

            $targets = [];
            if ($flags & \Attribute::TARGET_CLASS) {
                $targets[] = 'class';
            }
            if ($flags & \Attribute::TARGET_METHOD) {
                $targets[] = 'method';
            }
            if ($flags & \Attribute::TARGET_PROPERTY) {
                $targets[] = 'property';
            }

            $out[] = [
                'attribute' => '#['.$ref->getShortName().']',
                'class' => $class,
                'targets' => $targets,
                'repeatable' => (bool) ($flags & \Attribute::IS_REPEATABLE),
                'parameters' => $params,
            ];
        }

        return $out;
    }

    // ── Resources ─────────────────────────────────────────────────────────────

    /** @return list<array<string, string>> */
    public function resourceDefinitions(): array
    {
        $resources = [];
        foreach (self::REFERENCES as $topic => [, $description]) {
            $resources[] = ['uri' => self::referenceUri($topic), 'name' => "apexdocs: {$topic}", 'description' => $description, 'mimeType' => 'text/markdown'];
        }
        $resources[] = ['uri' => 'apexdocs://spec.json', 'name' => 'Generated OpenAPI document', 'description' => 'The full OpenAPI 3.1 JSON as generated right now', 'mimeType' => 'application/json'];
        $resources[] = ['uri' => 'apexdocs://config', 'name' => 'Effective ApexDocs config', 'description' => 'Config object as JSON', 'mimeType' => 'application/json'];

        return $resources;
    }

    /**
     * @param  array<array-key, mixed>  $params
     * @return array<string, mixed>
     */
    private function readResource(array $params): array
    {
        $uri = $params['uri'] ?? null;
        if (! is_string($uri)) {
            throw new McpException('resources/read requires a "uri"', -32602);
        }

        if ($uri === 'apexdocs://spec.json') {
            return ['contents' => [['uri' => $uri, 'mimeType' => 'application/json', 'text' => $this->encode($this->snapshot()->spec, pretty: true)]]];
        }
        if ($uri === 'apexdocs://config') {
            return ['contents' => [['uri' => $uri, 'mimeType' => 'application/json', 'text' => $this->encode($this->snapshot()->config, pretty: true)]]];
        }

        foreach (array_keys(self::REFERENCES) as $topic) {
            if (self::referenceUri($topic) === $uri) {
                return ['contents' => [['uri' => $uri, 'mimeType' => 'text/markdown', 'text' => $this->readReference($topic)]]];
            }
        }

        throw new McpException("Unknown resource: {$uri}", -32002);
    }

    public static function referenceUri(string $topic): string
    {
        return match ($topic) {
            'skill' => 'apexdocs://skill',
            'agents' => 'apexdocs://agents',
            default => "apexdocs://skill/{$topic}",
        };
    }

    private function readReference(string $topic): string
    {
        if (! isset(self::REFERENCES[$topic])) {
            throw new McpException("Unknown reference topic: {$topic}. Available: ".implode(', ', array_keys(self::REFERENCES)), -32602);
        }

        $path = $this->resourcesPath.'/'.self::REFERENCES[$topic][0];
        $text = is_file($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new McpException("Reference file missing: {$path}", -32603);
        }

        return $text;
    }

    /** @return array<string, mixed> */
    private function searchReference(string $query, int $context): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            throw new McpException('Argument "query" must be at least 2 characters', -32602);
        }

        $context = max(0, min(10, $context));
        $needle = mb_strtolower($query);
        $hits = [];

        foreach (array_keys(self::REFERENCES) as $topic) {
            $lines = preg_split('/\r?\n/', $this->readReference($topic)) ?: [];
            $total = count($lines);
            foreach ($lines as $index => $line) {
                if (! str_contains(mb_strtolower($line), $needle)) {
                    continue;
                }
                $from = max(0, $index - $context);
                $to = min($total - 1, $index + $context);
                $hits[] = [
                    'topic' => $topic,
                    'line' => $index + 1,
                    'match' => trim($line),
                    'context' => implode("\n", array_slice($lines, $from, $to - $from + 1)),
                ];
                if (count($hits) >= 50) {
                    break 2;
                }
            }
        }

        return ['query' => $query, 'hits' => count($hits), 'truncated' => count($hits) >= 50, 'results' => $hits];
    }

    // ── Prompts ───────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function promptDefinitions(): array
    {
        return [
            [
                'name' => 'document-endpoint',
                'description' => 'Bring one endpoint\'s documentation to production quality using attributes, typed DTOs and FormRequests.',
                'arguments' => [
                    ['name' => 'path', 'description' => 'OpenAPI path, e.g. /api/users/{id}', 'required' => true],
                    ['name' => 'method', 'description' => 'HTTP method', 'required' => true],
                ],
            ],
            [
                'name' => 'fix-validation',
                'description' => 'Resolve every error and warning reported by validate_spec.',
                'arguments' => [],
            ],
            [
                'name' => 'missing-endpoint',
                'description' => 'Find out why a route is absent from the docs and fix the cause.',
                'arguments' => [['name' => 'path', 'description' => 'Route path as registered', 'required' => true]],
            ],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $params
     * @return array<string, mixed>
     */
    private function getPrompt(array $params): array
    {
        $name = $params['name'] ?? null;
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $text = match ($name) {
            'document-endpoint' => $this->documentEndpointPrompt(self::optStr($args, 'path') ?? '/api/example', self::optStr($args, 'method') ?? 'get'),
            'fix-validation' => $this->fixValidationPrompt(),
            'missing-endpoint' => $this->missingEndpointPrompt(self::optStr($args, 'path') ?? '/api/example'),
            default => throw new McpException('Unknown prompt: '.(is_string($name) ? $name : '(none)'), -32602),
        };

        return ['description' => $name, 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]]];
    }

    private function documentEndpointPrompt(string $path, string $method): string
    {
        $verb = strtoupper($method);

        return <<<MD
        Document {$verb} {$path} to production quality with anil/apexdocs.

        1. `describe_operation` (path "{$path}", method "{$method}")  note what is already inferred (summary from PHPDoc, response from return type, body from FormRequest, security from middleware).
        2. Read `read_reference` topics `attributes` and `schemas-and-types`.
        3. Prefer code over annotations: give the action a typed DTO/Resource return with `@return Dto[]` or `Collection<int, Dto>` where it returns a list; make sure a FormRequest is type-hinted for writes.
        4. Add only the attributes inference cannot produce: `#[Endpoint(summary:)]` if the PHPDoc first line is poor, `#[QueryParam]` for each query key the action reads, `#[ApiResponse]` for non-2xx statuses it really returns (404, 403, 409…), `#[Example]` with realistic but fake data, `#[Security]`/`#[NoSecurity]` only if middleware detection is wrong.
        5. Make DTO properties typed and documented (`@var Item[]`, backed enums, nullable types) so the schema is exact.
        6. `validate_spec` (strict: true) and `describe_operation` again; summarise the before/after and any remaining warnings.
        MD;
    }

    private function fixValidationPrompt(): string
    {
        return <<<'MD'
        Make `validate_spec` (strict: true) pass for this application.

        1. Run `validate_spec` with strict=true and group the findings by kind.
        2. Read `read_reference` topic `validation-and-diff`  it lists the cause and fix for every message.
        3. Fix causes in code/config, never in generated output: missing summary → PHPDoc first line or `#[Endpoint]`; duplicate operationId → give routes distinct names; unmatched path param → fix the route template or the `#[PathParam]` name; undefined security scheme → declare it under `security.schemes` or fix `#[Security(scheme:)]`; unresolved $ref → the referenced class no longer exists or is excluded by max_depth; no paths → `api_path_prefix` / `exclude_paths`.
        4. Re-run `validate_spec` until clean; report what changed.
        MD;
    }

    private function missingEndpointPrompt(string $path): string
    {
        return <<<MD
        The route {$path} does not appear in the generated documentation. Find out why and fix it.

        1. `list_routes` with path_contains "{$path}"  read `included` and `reason`.
        2. Reasons and fixes: `api_path_prefix` → the path is outside the configured prefixes (config `api_path_prefix`, or add the prefix); `exclude_paths` → a glob/regex in config matches it; `spec_group` → the controller/method carries `#[ApiGroup]` for a different group than `spec_group`; `filterRoutes` → a closure in the app excludes it (search for `filterRoutes(`); `hidden` → `#[Hidden]` on the class/method, or the route only answers HEAD/OPTIONS/non-standard verbs.
        3. If the route is not listed at all, the framework does not register it (route cache, environment, closure route with no handler)  check `php artisan route:list` / the router.
        4. Apply the smallest fix, then `describe_operation` to confirm and `validate_spec`.
        MD;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param array<array-key, mixed> $args */
    private static function str(array $args, string $key): string
    {
        $value = $args[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new McpException("Argument \"{$key}\" is required", -32602);
        }

        return $value;
    }

    /** @param array<array-key, mixed> $args */
    private static function optStr(array $args, string $key): ?string
    {
        $value = $args[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    private static function toolText(string $text, bool $isError = false): array
    {
        $result = ['content' => [['type' => 'text', 'text' => $text]]];
        if ($isError) {
            $result['isError'] = true;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function encode(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | ($pretty ? JSON_PRETTY_PRINT : 0);
        $json = json_encode($value, $flags);

        return $json === false
            ? '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Failed to encode response"}}'
            : $json;
    }
}
