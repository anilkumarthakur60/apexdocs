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
    use CollectionExport;
    use WritesFiles;

    /** @return array<string, mixed> */
    public function toArray(Document $doc): array
    {
        $spec = $doc->toArray();
        $example = new SchemaExample($spec);

        $collection = [
            'info' => [
                '_postman_id' => $this->uuid(),
                'name' => $spec['info']['title'] ?? 'API',
                'description' => $spec['info']['description'] ?? '',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'variable' => $this->variables($spec),
            'item' => [],
        ];

        // Postman validates `auth` against a strict shape — omit it entirely
        // rather than emitting null when the API declares no bearer scheme.
        $auth = $this->globalAuth($spec);
        if ($auth !== null) {
            $collection['auth'] = $auth;
        }

        foreach ($this->groupByTag($spec) as $tag => $items) {
            $folder = ['name' => $tag, 'item' => []];
            foreach ($items as [$path, $method, $op]) {
                $folder['item'][] = $this->buildItem($path, $method, $op, $example);
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
        $this->write($path, $this->toString($doc));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function buildItem(string $path, string $method, array $op, SchemaExample $example): array
    {
        $item = [
            'name' => $this->itemName($path, $op),
            'request' => [
                'method' => $method,
                'header' => $this->headers($op),
                'url' => $this->url($path, $op),
            ],
        ];

        if (isset($op['requestBody']) && is_array($op['requestBody'])) {
            $item['request']['body'] = $this->body($op['requestBody'], $example);
        }
        if (($op['description'] ?? '') !== '') {
            $item['request']['description'] = $op['description'];
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $op
     * @return list<array<string, mixed>>
     */
    private function headers(array $op): array
    {
        $h = [['key' => 'Accept', 'value' => 'application/json']];
        foreach ($this->parametersIn($op, 'header') as $p) {
            $h[] = [
                'key' => (string) ($p['name'] ?? ''),
                'value' => $this->paramValue($p),
                'disabled' => ! ($p['required'] ?? false),
            ];
        }

        return $h;
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function url(string $path, array $op): array
    {
        $postmanPath = preg_replace('/\{(\w+)}/', ':$1', $path) ?? $path;
        $url = [
            'raw' => '{{baseUrl}}'.$postmanPath,
            'host' => ['{{baseUrl}}'],
            'path' => array_values(array_filter(explode('/', ltrim($postmanPath, '/')), static fn ($s) => $s !== '')),
        ];

        $query = [];
        foreach ($this->parametersIn($op, 'query') as $p) {
            $query[] = [
                'key' => (string) ($p['name'] ?? ''),
                'value' => $this->paramValue($p),
                'disabled' => ! ($p['required'] ?? false),
            ];
        }

        $vars = [];
        foreach ($this->parametersIn($op, 'path') as $p) {
            $vars[] = ['key' => (string) ($p['name'] ?? ''), 'value' => $this->paramValue($p)];
        }

        if ($query) {
            $url['query'] = $query;
        }
        if ($vars) {
            $url['variable'] = $vars;
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $requestBody
     * @return array<string, mixed>
     */
    private function body(array $requestBody, SchemaExample $example): array
    {
        $content = is_array($requestBody['content'] ?? null) ? $requestBody['content'] : [];

        if (isset($content['application/json'])) {
            return [
                'mode' => 'raw',
                'raw' => $this->jsonBody($content['application/json'], $example),
                'options' => ['raw' => ['language' => 'json']],
            ];
        }

        if (isset($content['multipart/form-data'])) {
            $fd = [];
            foreach ($this->propertiesOf($content['multipart/form-data'], $example) as $k => $prop) {
                $fd[] = [
                    'key' => $k,
                    'value' => ($prop['format'] ?? '') === 'binary' ? '' : $this->scalar($example->build($prop)),
                    'type' => ($prop['format'] ?? '') === 'binary' ? 'file' : 'text',
                ];
            }

            return ['mode' => 'formdata', 'formdata' => $fd];
        }

        if (isset($content['application/x-www-form-urlencoded'])) {
            $fields = [];
            foreach ($this->propertiesOf($content['application/x-www-form-urlencoded'], $example) as $k => $prop) {
                $fields[] = ['key' => $k, 'value' => $this->scalar($example->build($prop))];
            }

            return ['mode' => 'urlencoded', 'urlencoded' => $fields];
        }

        return ['mode' => 'raw', 'raw' => '{}'];
    }

    /** @param array<string, mixed> $spec */
    private function variables(array $spec): array
    {
        return [[
            'key' => 'baseUrl',
            'value' => $spec['servers'][0]['url'] ?? 'http://localhost',
            'type' => 'default',
        ]];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>|null
     */
    private function globalAuth(array $spec): ?array
    {
        foreach ($this->securitySchemes($spec) as $scheme) {
            $type = strtolower((string) ($scheme['type'] ?? ''));
            $httpScheme = strtolower((string) ($scheme['scheme'] ?? ''));

            if ($type === 'http' && $httpScheme === 'bearer') {
                return ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']]];
            }
            if ($type === 'http' && $httpScheme === 'basic') {
                return ['type' => 'basic', 'basic' => [
                    ['key' => 'username', 'value' => '{{username}}', 'type' => 'string'],
                    ['key' => 'password', 'value' => '{{password}}', 'type' => 'string'],
                ]];
            }
            if ($type === 'oauth2') {
                return ['type' => 'oauth2', 'oauth2' => [['key' => 'accessToken', 'value' => '{{token}}', 'type' => 'string']]];
            }
            if ($type === 'apikey') {
                return ['type' => 'apikey', 'apikey' => [
                    ['key' => 'key', 'value' => (string) ($scheme['name'] ?? 'X-API-Key'), 'type' => 'string'],
                    ['key' => 'value', 'value' => '{{apiKey}}', 'type' => 'string'],
                    ['key' => 'in', 'value' => (string) ($scheme['in'] ?? 'header'), 'type' => 'string'],
                ]];
            }
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
