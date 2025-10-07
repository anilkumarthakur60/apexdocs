<?php

declare(strict_types=1);

namespace ApexDocs\Export;

use ApexDocs\Spec\Document;

/**
 * Converts an OpenAPI Document to a Postman Collection v2.1.
 * No framework dependency.
 */
final class PostmanExporter
{
    /** @return array<string, mixed> */
    public function toArray(Document $doc): array
    {
        $spec = $doc->toArray();

        $collection = [
            'info' => [
                '_postman_id' => $this->uuid(),
                'name' => $spec['info']['title'] ?? 'API',
                'description' => $spec['info']['description'] ?? '',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'variable' => $this->variables($spec),
            'auth' => $this->globalAuth($spec),
            'item' => [],
        ];

        foreach ($this->groupByTag($spec) as $tag => $items) {
            $folder = ['name' => $tag, 'item' => []];
            foreach ($items as [$path, $method, $op]) {
                $folder['item'][] = $this->buildItem($path, $method, $op);
            }
            $collection['item'][] = $folder;
        }

        return $collection;
    }

    public function toString(Document $doc): string
    {
        return json_encode(
            $this->toArray($doc),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    public function toFile(Document $doc, string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $this->toString($doc));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function groupByTag(array $spec): array
    {
        $groups = [];
        foreach ($spec['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $op) {
                if (! is_array($op)) {
                    continue;
                }
                $tag = $op['tags'][0] ?? 'General';
                $groups[$tag][] = [$path, strtoupper($method), $op];
            }
        }

        return $groups;
    }

    private function buildItem(string $path, string $method, array $op): array
    {
        $item = [
            'name' => $op['summary'] ?? $op['operationId'] ?? $path,
            'request' => [
                'method' => $method,
                'header' => $this->headers($op),
                'url' => $this->url($path, $op),
            ],
        ];

        if ($op['deprecated'] ?? false) {
            $item['name'] .= ' [DEPRECATED]';
        }
        if (isset($op['requestBody'])) {
            $item['request']['body'] = $this->body($op['requestBody']);
        }
        if ($op['description'] ?? '') {
            $item['request']['description'] = $op['description'];
        }

        return $item;
    }

    private function headers(array $op): array
    {
        $h = [['key' => 'Accept', 'value' => 'application/json']];
        foreach ($op['parameters'] ?? [] as $p) {
            if (($p['in'] ?? '') === 'header') {
                $h[] = [
                    'key' => $p['name'],
                    'value' => (string) ($p['example'] ?? ''),
                    'disabled' => ! ($p['required'] ?? false),
                ];
            }
        }

        return $h;
    }

    private function url(string $path, array $op): array
    {
        $postmanPath = preg_replace('/\{(\w+)}/', ':$1', $path);
        $url = [
            'raw' => '{{baseUrl}}'.$postmanPath,
            'host' => ['{{baseUrl}}'],
            'path' => array_values(array_filter(explode('/', ltrim($postmanPath, '/')))),
        ];

        $query = [];
        $vars = [];
        foreach ($op['parameters'] ?? [] as $p) {
            if (($p['in'] ?? '') === 'query') {
                $query[] = [
                    'key' => $p['name'],
                    'value' => (string) ($p['example'] ?? ''),
                    'disabled' => ! ($p['required'] ?? false),
                ];
            }
            if (($p['in'] ?? '') === 'path') {
                $vars[] = ['key' => $p['name'], 'value' => (string) ($p['example'] ?? '')];
            }
        }
        if ($query) {
            $url['query'] = $query;
        }
        if ($vars) {
            $url['variable'] = $vars;
        }

        return $url;
    }

    private function body(array $requestBody): array
    {
        $content = $requestBody['content'] ?? [];

        if (isset($content['application/json'])) {
            $example = json_encode(
                $this->exampleFromSchema($content['application/json']['schema'] ?? []),
                JSON_PRETTY_PRINT,
            );

            return ['mode' => 'raw', 'raw' => $example, 'options' => ['raw' => ['language' => 'json']]];
        }

        if (isset($content['multipart/form-data'])) {
            $fd = [];
            foreach (($content['multipart/form-data']['schema']['properties'] ?? []) as $k => $prop) {
                $fd[] = [
                    'key' => $k,
                    'value' => (string) ($prop['example'] ?? ''),
                    'type' => ($prop['format'] ?? '') === 'binary' ? 'file' : 'text',
                ];
            }

            return ['mode' => 'formdata', 'formdata' => $fd];
        }

        return ['mode' => 'raw', 'raw' => '{}'];
    }

    private function exampleFromSchema(array $schema): mixed
    {
        if (isset($schema['example'])) {
            return $schema['example'];
        }

        $type = $schema['type'] ?? 'object';
        // OpenAPI 3.1 nullable form: type is an array containing 'null'.
        if (is_array($type)) {
            $primary = array_values(array_filter($type, static fn ($t) => $t !== 'null'));
            $type = $primary[0] ?? 'object';
        }

        return match ($type) {
            'object' => (object) array_map(fn ($v) => $this->exampleFromSchema($v), $schema['properties'] ?? []),
            'array' => [$this->exampleFromSchema($schema['items'] ?? [])],
            'integer' => 1,
            'number' => 1.0,
            'boolean' => true,
            'null' => null,
            default => $schema['enum'][0] ?? 'string',
        };
    }

    private function variables(array $spec): array
    {
        return [['key' => 'baseUrl', 'value' => $spec['servers'][0]['url'] ?? 'http://localhost', 'type' => 'default']];
    }

    private function globalAuth(array $spec): ?array
    {
        $schemes = $spec['components']['securitySchemes'] ?? [];
        if (isset($schemes['sanctum']) || isset($schemes['jwt'])) {
            return ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']]];
        }

        return null;
    }

    private function uuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF),
            mt_rand(0, 0x0FFF) | 0x4000, mt_rand(0, 0x3FFF) | 0x8000,
            mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF));
    }
}
