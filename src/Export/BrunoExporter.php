<?php

declare(strict_types=1);

namespace ApexDocs\Export;

use ApexDocs\Spec\Document;

/**
 * Exports an OpenAPI Document to a Bruno Collection (v1).
 * Bruno is an open-source, Git-friendly API client (https://www.usebruno.com/).
 *
 * The exported JSON can be imported via Bruno → Import Collection → OpenAPI/Bruno.
 */
final class BrunoExporter
{
    /** @return array<string, mixed> */
    public function toArray(Document $doc): array
    {
        $spec    = $doc->toArray();
        $baseUrl = $spec['servers'][0]['url'] ?? 'http://localhost';

        $collection = [
            'version' => '1',
            'name'    => $spec['info']['title'] ?? 'API',
            'meta'    => [
                'version'     => $spec['info']['version'] ?? '1.0.0',
                'description' => $spec['info']['description'] ?? '',
                'openapi'     => $spec['openapi'] ?? '3.1.0',
            ],
            'environments' => $this->buildEnvironments($spec),
            'items'        => [],
        ];

        foreach ($this->groupByTag($spec) as $tag => $items) {
            $folder = ['type' => 'folder', 'name' => $tag, 'items' => []];
            foreach ($items as [$path, $method, $op]) {
                $folder['items'][] = $this->buildItem($path, $method, $op, $baseUrl);
            }
            $collection['items'][] = $folder;
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

    private function buildItem(string $path, string $method, array $op, string $baseUrl): array
    {
        $brunoPath = preg_replace('/\{(\w+)\}/', ':$1', $path);
        $url       = '{{baseUrl}}'.$brunoPath;

        $item = [
            'type' => 'http',
            'name' => ($op['deprecated'] ?? false)
                ? (($op['summary'] ?? $path).' [DEPRECATED]')
                : ($op['summary'] ?? $path),
            'meta' => array_filter([
                'description' => $op['description'] ?? '',
                'operationId' => $op['operationId'] ?? '',
                'deprecated'  => $op['deprecated'] ?? false,
            ]),
            'request' => [
                'method'  => $method,
                'url'     => $url,
                'headers' => $this->buildHeaders($op),
                'params'  => $this->buildParams($op),
                'body'    => $this->buildBody($op),
                'auth'    => $this->buildAuth($op),
            ],
        ];

        return $item;
    }

    private function buildHeaders(array $op): array
    {
        $headers = [['name' => 'Accept', 'value' => 'application/json', 'enabled' => true]];
        foreach ($op['parameters'] ?? [] as $p) {
            if (($p['in'] ?? '') === 'header') {
                $headers[] = [
                    'name'    => $p['name'],
                    'value'   => (string) ($p['example'] ?? ''),
                    'enabled' => $p['required'] ?? false,
                ];
            }
        }

        return $headers;
    }

    private function buildParams(array $op): array
    {
        $params = [];
        foreach ($op['parameters'] ?? [] as $p) {
            if (($p['in'] ?? '') === 'query') {
                $params[] = [
                    'name'    => $p['name'],
                    'value'   => (string) ($p['example'] ?? ''),
                    'enabled' => $p['required'] ?? false,
                ];
            }
        }

        return $params;
    }

    private function buildBody(array $op): array
    {
        $content = $op['requestBody']['content'] ?? [];

        if (isset($content['application/json'])) {
            $example = json_encode(
                $this->exampleFromSchema($content['application/json']['schema'] ?? []),
                JSON_PRETTY_PRINT,
            );

            return ['mode' => 'json', 'json' => $example];
        }

        if (isset($content['multipart/form-data'])) {
            $fields = [];
            foreach (($content['multipart/form-data']['schema']['properties'] ?? []) as $k => $v) {
                $fields[] = [
                    'name'    => $k,
                    'value'   => '',
                    'enabled' => true,
                    'type'    => ($v['format'] ?? '') === 'binary' ? 'file' : 'text',
                ];
            }

            return ['mode' => 'multipartForm', 'multipartForm' => $fields];
        }

        return ['mode' => 'none'];
    }

    private function buildAuth(array $op): array
    {
        $security = $op['security'] ?? null;
        if ($security === null) {
            return ['mode' => 'inherit'];
        }
        if (empty($security)) {
            return ['mode' => 'none'];
        }

        foreach ($security as $scheme) {
            $name = array_key_first($scheme);
            if (str_contains(strtolower($name), 'bearer') || str_contains(strtolower($name), 'sanctum') || str_contains(strtolower($name), 'jwt')) {
                return ['mode' => 'bearer', 'bearer' => ['token' => '{{token}}']];
            }
        }

        return ['mode' => 'inherit'];
    }

    private function buildEnvironments(array $spec): array
    {
        $envs = [];
        foreach ($spec['servers'] ?? [] as $server) {
            $name    = $server['description'] ?? $server['url'];
            $envs[]  = [
                'name'      => $name,
                'variables' => [
                    ['name' => 'baseUrl', 'value' => $server['url'], 'enabled' => true, 'secret' => false],
                ],
            ];
        }

        if (empty($envs)) {
            $envs[] = [
                'name'      => 'Local',
                'variables' => [
                    ['name' => 'baseUrl', 'value' => 'http://localhost', 'enabled' => true, 'secret' => false],
                ],
            ];
        }

        return $envs;
    }

    private function exampleFromSchema(array $schema): mixed
    {
        if (isset($schema['example'])) {
            return $schema['example'];
        }

        return match ($schema['type'] ?? 'object') {
            'object'  => (object) array_map(fn ($v) => $this->exampleFromSchema($v), $schema['properties'] ?? []),
            'array'   => [$this->exampleFromSchema($schema['items'] ?? [])],
            'integer' => 1,
            'number'  => 1.0,
            'boolean' => true,
            'null'    => null,
            default   => $schema['enum'][0] ?? 'string',
        };
    }
}
