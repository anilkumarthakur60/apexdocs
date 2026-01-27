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
    use CollectionExport;
    use WritesFiles;

    private int $sequence = 0;

    /** @return array<string, mixed> */
    public function toArray(Document $doc): array
    {
        $this->sequence = 0;
        $spec = $doc->toArray();
        $example = new SchemaExample($spec);
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

        $authentication = $this->authentication($spec);

        foreach ($this->groupByTag($spec) as $tag => $items) {
            $folderId = 'fld_'.$this->id();
            $resources[] = [
                '_id' => $folderId, '_type' => 'request_group',
                'parentId' => $workspaceId, 'name' => $tag,
            ];
            foreach ($items as [$path, $method, $op]) {
                $resources[] = $this->buildRequest($path, $method, $op, $folderId, $example, $authentication);
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
        $this->write($path, $this->toString($doc));
    }

    /**
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $authentication
     * @return array<string, mixed>
     */
    private function buildRequest(
        string $path,
        string $method,
        array $op,
        string $parentId,
        SchemaExample $example,
        array $authentication,
    ): array {
        $headers = [['name' => 'Accept', 'value' => 'application/json']];
        foreach ($this->parametersIn($op, 'header') as $h) {
            $headers[] = ['name' => (string) $h['name'], 'value' => $this->paramValue($h)];
        }

        $query = [];
        foreach ($this->parametersIn($op, 'query') as $p) {
            $query[] = [
                'name' => (string) $p['name'],
                'value' => $this->paramValue($p),
                'disabled' => ! ($p['required'] ?? false),
            ];
        }

        $pathParams = [];
        foreach ($this->parametersIn($op, 'path') as $p) {
            $pathParams[] = ['name' => (string) $p['name'], 'value' => $this->paramValue($p)];
        }

        $req = [
            '_id' => 'req_'.$this->id(),
            '_type' => 'request',
            'parentId' => $parentId,
            'name' => $this->itemName($path, $op),
            'method' => $method,
            'url' => '{{ _.base_url }}'.$path,
            'headers' => $headers,
            'parameters' => $query,
            'pathParameters' => $pathParams,
            'metaSortKey' => $this->sequence++,
        ];

        if (isset($op['requestBody']) && is_array($op['requestBody'])) {
            $req['body'] = $this->body($op['requestBody'], $example);
        }
        if (($op['description'] ?? '') !== '') {
            $req['description'] = $op['description'];
        }
        if ($authentication !== [] && ($op['security'] ?? null) !== []) {
            $req['authentication'] = $authentication;
        }

        return $req;
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
                'mimeType' => 'application/json',
                'text' => $this->jsonBody($content['application/json'], $example),
            ];
        }

        foreach (['multipart/form-data' => 'multipart/form-data', 'application/x-www-form-urlencoded' => 'application/x-www-form-urlencoded'] as $type => $mime) {
            if (! isset($content[$type])) {
                continue;
            }
            $params = [];
            foreach ($this->propertiesOf($content[$type], $example) as $name => $prop) {
                $binary = ($prop['format'] ?? '') === 'binary';
                $params[] = [
                    'name' => $name,
                    'value' => $binary ? '' : $this->scalar($example->build($prop)),
                    'type' => $binary ? 'file' : 'text',
                ];
            }

            return ['mimeType' => $mime, 'params' => $params];
        }

        return ['mimeType' => 'application/json', 'text' => '{}'];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function authentication(array $spec): array
    {
        foreach ($this->securitySchemes($spec) as $scheme) {
            $type = strtolower((string) ($scheme['type'] ?? ''));
            $httpScheme = strtolower((string) ($scheme['scheme'] ?? ''));

            if ($type === 'oauth2' || ($type === 'http' && $httpScheme === 'bearer')) {
                return ['type' => 'bearer', 'token' => '{{ _.token }}', 'prefix' => 'Bearer'];
            }
            if ($type === 'http' && $httpScheme === 'basic') {
                return ['type' => 'basic', 'username' => '{{ _.username }}', 'password' => '{{ _.password }}'];
            }
            if ($type === 'apikey') {
                return [
                    'type' => 'apikey',
                    'key' => (string) ($scheme['name'] ?? 'X-API-Key'),
                    'value' => '{{ _.api_key }}',
                    'addTo' => strtolower((string) ($scheme['in'] ?? 'header')) === 'query' ? 'queryParams' : 'header',
                ];
            }
        }

        return [];
    }

    private function id(): string
    {
        return substr(md5(uniqid('', true)), 0, 12);
    }
}
