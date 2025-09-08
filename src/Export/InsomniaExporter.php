<?php

declare(strict_types=1);

namespace ApexDocs\Export;

use ApexDocs\Spec\Document;

/**
 * Converts an OpenAPI Document to an Insomnia Export v4.
 * No framework dependency.
 */
final class InsomniaExporter
{
    /** @return array<string, mixed> */
    public function toArray(Document $doc): array
    {
        $spec = $doc->toArray();
        $title = $spec['info']['title'] ?? 'API';
        $workspaceId = 'wrk_'.$this->id();

        $resources = [
            [
                '_id' => $workspaceId,
                '_type' => 'workspace',
                'name' => $title,
                'description' => $spec['info']['description'] ?? '',
                'scope' => 'collection',
            ],
            [
                '_id' => 'env_'.$this->id(),
                '_type' => 'environment',
                'parentId' => $workspaceId,
                'name' => 'Base',
                'data' => ['base_url' => $spec['servers'][0]['url'] ?? 'http://localhost'],
            ],
        ];

        foreach ($this->groupByTag($spec) as $tag => $items) {
            $folderId = 'fld_'.$this->id();
            $resources[] = [
                '_id' => $folderId, '_type' => 'request_group',
                'parentId' => $workspaceId, 'name' => $tag,
            ];
            foreach ($items as [$path, $method, $op]) {
                $resources[] = $this->buildRequest($path, $method, $op, $folderId);
            }
        }

        return [
            '_type' => 'export',
            '__export_format' => 4,
            '__export_date' => date('c'),
            '__export_source' => 'apexdocs',
            'resources' => $resources,
        ];
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

    private function buildRequest(string $path, string $method, array $op, string $parentId): array
    {
        $params = $op['parameters'] ?? [];
        $query = array_map(
            fn ($p) => ['name' => $p['name'], 'value' => (string) ($p['example'] ?? ''), 'disabled' => ! ($p['required'] ?? false)],
            array_filter($params, fn ($p) => ($p['in'] ?? '') === 'query'),
        );
        $headers = [['name' => 'Accept', 'value' => 'application/json']];
        foreach (array_filter($params, fn ($p) => ($p['in'] ?? '') === 'header') as $h) {
            $headers[] = ['name' => $h['name'], 'value' => (string) ($h['example'] ?? '')];
        }

        $req = [
            '_id' => 'req_'.$this->id(),
            '_type' => 'request',
            'parentId' => $parentId,
            'name' => ($op['deprecated'] ?? false)
                                ? ($op['summary'] ?? $path).' [DEPRECATED]'
                                : ($op['summary'] ?? $path),
            'method' => $method,
            'url' => '{{ _.base_url }}'.$path,
            'headers' => $headers,
            'parameters' => array_values($query),
        ];

        if (isset($op['requestBody'])) {
            $req['body'] = $this->body($op['requestBody']);
        }
        if ($op['description'] ?? '') {
            $req['description'] = $op['description'];
        }

        return $req;
    }

    private function body(array $requestBody): array
    {
        $content = $requestBody['content'] ?? [];
        if (isset($content['application/json'])) {
            return [
                'mimeType' => 'application/json',
                'text' => json_encode($this->example($content['application/json']['schema'] ?? []), JSON_PRETTY_PRINT),
            ];
        }
        if (isset($content['multipart/form-data'])) {
            $params = [];
            foreach (($content['multipart/form-data']['schema']['properties'] ?? []) as $k => $v) {
                $params[] = ['name' => $k, 'value' => '', 'type' => ($v['format'] ?? '') === 'binary' ? 'file' : 'text'];
            }

            return ['mimeType' => 'multipart/form-data', 'params' => $params];
        }

        return ['mimeType' => 'application/json', 'text' => '{}'];
    }

    private function example(array $schema): mixed
    {
        if (isset($schema['example'])) {
            return $schema['example'];
        }

        return match ($schema['type'] ?? 'object') {
            'object' => (object) array_map(fn ($v) => $this->example($v), $schema['properties'] ?? []),
            'array' => [$this->example($schema['items'] ?? [])],
            'integer' => 1, 'number' => 1.0, 'boolean' => true, 'null' => null,
            default => $schema['enum'][0] ?? 'string',
        };
    }

    private function id(): string
    {
        return substr(md5(uniqid('', true)), 0, 12);
    }
}
