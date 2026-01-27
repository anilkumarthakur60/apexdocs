<?php

declare(strict_types=1);

namespace ApexDocs\Export;

use ApexDocs\Spec\Document;

/**
 * Exports an OpenAPI Document to a Bruno Collection (v1).
 * Bruno is an open-source, Git-friendly API client (https://www.usebruno.com/).
 *
 * The exported JSON can be imported via Bruno → Import Collection → Bruno Collection.
 */
final class BrunoExporter
{
    use CollectionExport;
    use WritesFiles;

    private int $sequence = 0;

    /** @return array<string, mixed> */
    public function toArray(Document $doc): array
    {
        $this->sequence = 0;
        $spec = $doc->toArray();
        $example = new SchemaExample($spec);

        $collection = [
            'version' => '1',
            'name' => $spec['info']['title'] ?? 'API',
            'meta' => [
                'version' => $spec['info']['version'] ?? '1.0.0',
                'description' => $spec['info']['description'] ?? '',
                'openapi' => $spec['openapi'] ?? '3.1.0',
            ],
            'environments' => $this->buildEnvironments($spec),
            'items' => [],
        ];

        foreach ($this->groupByTag($spec) as $tag => $items) {
            $folder = ['type' => 'folder', 'name' => $tag, 'seq' => count($collection['items']) + 1, 'items' => []];
            foreach ($items as [$path, $method, $op]) {
                $folder['items'][] = $this->buildItem($path, $method, $op, $example, $spec);
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
        $this->write($path, $this->toString($doc));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function buildItem(string $path, string $method, array $op, SchemaExample $example, array $spec): array
    {
        $brunoPath = preg_replace('/\{(\w+)}/', ':$1', $path) ?? $path;

        return [
            'type' => 'http',
            'name' => $this->itemName($path, $op),
            'seq' => ++$this->sequence,
            'meta' => array_filter([
                'description' => $op['description'] ?? '',
                'operationId' => $op['operationId'] ?? '',
                'deprecated' => $op['deprecated'] ?? false,
            ]),
            'request' => [
                'method' => $method,
                'url' => '{{baseUrl}}'.$brunoPath,
                'headers' => $this->buildHeaders($op),
                'params' => $this->buildParams($op),
                'body' => $this->buildBody($op, $example),
                'auth' => $this->buildAuth($op, $spec),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $op
     * @return list<array<string, mixed>>
     */
    private function buildHeaders(array $op): array
    {
        $headers = [['name' => 'Accept', 'value' => 'application/json', 'enabled' => true]];
        foreach ($this->parametersIn($op, 'header') as $p) {
            $headers[] = [
                'name' => (string) $p['name'],
                'value' => $this->paramValue($p),
                'enabled' => (bool) ($p['required'] ?? false),
            ];
        }

        return $headers;
    }

    /**
     * Bruno keeps query and path parameters in one list, distinguished by type.
     *
     * @param  array<string, mixed>  $op
     * @return list<array<string, mixed>>
     */
    private function buildParams(array $op): array
    {
        $params = [];

        foreach ($this->parametersIn($op, 'query') as $p) {
            $params[] = [
                'name' => (string) $p['name'],
                'value' => $this->paramValue($p),
                'type' => 'query',
                'enabled' => (bool) ($p['required'] ?? false),
            ];
        }

        foreach ($this->parametersIn($op, 'path') as $p) {
            $params[] = [
                'name' => (string) $p['name'],
                'value' => $this->paramValue($p),
                'type' => 'path',
                'enabled' => true,
            ];
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function buildBody(array $op, SchemaExample $example): array
    {
        $content = $op['requestBody']['content'] ?? [];
        if (! is_array($content)) {
            return ['mode' => 'none'];
        }

        if (isset($content['application/json'])) {
            return ['mode' => 'json', 'json' => $this->jsonBody($content['application/json'], $example)];
        }

        if (isset($content['multipart/form-data'])) {
            $fields = [];
            foreach ($this->propertiesOf($content['multipart/form-data'], $example) as $name => $prop) {
                $binary = ($prop['format'] ?? '') === 'binary';
                $fields[] = [
                    'name' => $name,
                    'value' => $binary ? '' : $this->scalar($example->build($prop)),
                    'enabled' => true,
                    'type' => $binary ? 'file' : 'text',
                ];
            }

            return ['mode' => 'multipartForm', 'multipartForm' => $fields];
        }

        if (isset($content['application/x-www-form-urlencoded'])) {
            $fields = [];
            foreach ($this->propertiesOf($content['application/x-www-form-urlencoded'], $example) as $name => $prop) {
                $fields[] = ['name' => $name, 'value' => $this->scalar($example->build($prop)), 'enabled' => true];
            }

            return ['mode' => 'formUrlEncoded', 'formUrlEncoded' => $fields];
        }

        return ['mode' => 'none'];
    }

    /**
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function buildAuth(array $op, array $spec): array
    {
        $security = $op['security'] ?? null;
        if ($security === null) {
            return ['mode' => 'inherit'];
        }
        if ($security === []) {
            return ['mode' => 'none'];
        }

        $schemes = $this->securitySchemes($spec);

        foreach ($security as $requirement) {
            if (! is_array($requirement)) {
                continue;
            }
            $name = (string) array_key_first($requirement);
            $definition = $schemes[$name] ?? [];
            $type = strtolower((string) ($definition['type'] ?? ''));
            $httpScheme = strtolower((string) ($definition['scheme'] ?? ''));
            $lowerName = strtolower($name);

            if ($type === 'oauth2') {
                return ['mode' => 'oauth2', 'oauth2' => ['grantType' => 'authorization_code', 'accessTokenUrl' => '', 'clientId' => '', 'clientSecret' => '']];
            }
            if ($type === 'http' && $httpScheme === 'basic') {
                return ['mode' => 'basic', 'basic' => ['username' => '{{username}}', 'password' => '{{password}}']];
            }
            if ($type === 'apikey') {
                return ['mode' => 'apikey', 'apikey' => [
                    'key' => (string) ($definition['name'] ?? 'X-API-Key'),
                    'value' => '{{apiKey}}',
                    'placement' => strtolower((string) ($definition['in'] ?? 'header')) === 'query' ? 'queryparams' : 'header',
                ]];
            }
            // Fall back to the scheme name when the definition is missing.
            if (($type === 'http' && $httpScheme === 'bearer')
                || str_contains($lowerName, 'bearer')
                || str_contains($lowerName, 'sanctum')
                || str_contains($lowerName, 'jwt')
                || str_contains($lowerName, 'token')
            ) {
                return ['mode' => 'bearer', 'bearer' => ['token' => '{{token}}']];
            }
        }

        return ['mode' => 'inherit'];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<array<string, mixed>>
     */
    private function buildEnvironments(array $spec): array
    {
        $envs = [];
        foreach (($spec['servers'] ?? []) as $server) {
            $url = is_array($server) ? ($server['url'] ?? '') : '';
            if (! is_string($url) || $url === '') {
                continue;
            }
            $name = is_string($server['description'] ?? null) && $server['description'] !== ''
                ? $server['description']
                : $url;

            $envs[] = [
                'name' => $name,
                'variables' => [
                    ['name' => 'baseUrl', 'value' => $url, 'enabled' => true, 'secret' => false],
                ],
            ];
        }

        if ($envs === []) {
            $envs[] = [
                'name' => 'Local',
                'variables' => [
                    ['name' => 'baseUrl', 'value' => 'http://localhost', 'enabled' => true, 'secret' => false],
                ],
            ];
        }

        return $envs;
    }
}
