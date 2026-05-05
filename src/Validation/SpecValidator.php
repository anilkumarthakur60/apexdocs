<?php

declare(strict_types=1);

namespace ApexDocs\Validation;

/**
 * Structural validation of a generated OpenAPI 3.1 document.
 *
 * Framework-agnostic — takes the document as an array and returns errors and
 * warnings as plain strings. Used by `apexdocs:validate` and the MCP server so
 * both report exactly the same findings.
 *
 * Errors: missing info.title / info.version, no paths, a response without a
 * description, an invalid response key, duplicate operationIds, a path
 * template variable with no matching parameter, a non-required path
 * parameter, an unresolved local $ref, a security requirement naming an
 * undefined scheme, a server without a url.
 *
 * Warnings: missing operationId, missing summary.
 */
final class SpecValidator
{
    private const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param  array<string, mixed>  $spec
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validate(array $spec): array
    {
        $this->errors = [];
        $this->warnings = [];

        $this->checkRoot($spec);
        $this->checkOperations($spec);
        $this->checkReferences($spec);

        return ['errors' => $this->errors, 'warnings' => $this->warnings];
    }

    /** @param array<string, mixed> $spec */
    private function checkRoot(array $spec): void
    {
        foreach (['openapi', 'info'] as $field) {
            if (empty($spec[$field])) {
                $this->errors[] = "Missing required field: {$field}";
            }
        }

        foreach (['title', 'version'] as $field) {
            if (empty($spec['info'][$field])) {
                $this->errors[] = "Missing required field: info.{$field}";
            }
        }

        // OpenAPI 3.1 tolerates a document with no paths, but for a generator
        // it always means misconfiguration — fail rather than ship an empty API.
        if (empty($spec['paths'])) {
            $this->errors[] = 'Missing required field: paths (no routes matched — check api_path_prefix and exclude_paths)';
        }

        foreach ($spec['servers'] ?? [] as $i => $server) {
            if (! is_array($server) || ($server['url'] ?? '') === '') {
                $this->errors[] = "servers[{$i}]: missing url";
            }
        }
    }

    /** @param array<string, mixed> $spec */
    private function checkOperations(array $spec): void
    {
        $operationIds = [];

        foreach ($spec['paths'] ?? [] as $path => $methods) {
            if (! is_array($methods)) {
                continue;
            }

            $declared = $this->pathTemplateParams((string) $path);

            foreach ($methods as $method => $op) {
                if (! is_array($op) || ! in_array(strtolower((string) $method), self::HTTP_METHODS, true)) {
                    continue;
                }

                $loc = strtoupper((string) $method)." {$path}";

                if (empty($op['responses'])) {
                    $this->errors[] = "{$loc}: no responses";
                }

                foreach ($op['responses'] ?? [] as $status => $response) {
                    if (! is_array($response)) {
                        continue;
                    }
                    if (! isset($response['$ref']) && ($response['description'] ?? '') === '') {
                        $this->errors[] = "{$loc}: response {$status} has no description (required by the spec)";
                    }
                    if ((string) $status !== 'default' && preg_match('/^[1-5](\d\d|XX)$/', (string) $status) !== 1) {
                        $this->errors[] = "{$loc}: '{$status}' is not a valid response key";
                    }
                }

                $id = $op['operationId'] ?? '';
                if ($id === '') {
                    $this->warnings[] = "{$loc}: missing operationId";
                } elseif (isset($operationIds[$id])) {
                    $this->errors[] = "{$loc}: duplicate operationId '{$id}' (also on {$operationIds[$id]})";
                } else {
                    $operationIds[$id] = $loc;
                }

                if (($op['summary'] ?? '') === '') {
                    $this->warnings[] = "{$loc}: missing summary";
                }

                $this->checkParameters($op, $loc, $declared);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $op
     * @param  list<string>  $declared
     */
    private function checkParameters(array $op, string $loc, array $declared): void
    {
        $documented = [];

        foreach ($op['parameters'] ?? [] as $param) {
            if (! is_array($param) || isset($param['$ref'])) {
                continue;
            }
            foreach (['name', 'in'] as $field) {
                if (($param[$field] ?? '') === '') {
                    $this->errors[] = "{$loc}: parameter missing required field '{$field}'";
                }
            }
            if (($param['in'] ?? '') === 'path') {
                $documented[] = (string) ($param['name'] ?? '');
                if (($param['required'] ?? false) !== true) {
                    $this->errors[] = "{$loc}: path parameter '{$param['name']}' must be required";
                }
            }
        }

        foreach (array_diff($declared, $documented) as $missing) {
            $this->errors[] = "{$loc}: path template declares {{$missing}} but no matching parameter";
        }
    }

    /**
     * Every local $ref must resolve — a dangling pointer is the most common way
     * a generated document fails downstream tooling.
     *
     * @param  array<string, mixed>  $spec
     */
    private function checkReferences(array $spec): void
    {
        foreach ($this->collectRefs($spec) as $ref => $count) {
            if (! str_starts_with($ref, '#/')) {
                continue;
            }
            $node = $spec;
            foreach (explode('/', substr($ref, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                if (! is_array($node) || ! array_key_exists($segment, $node)) {
                    $this->errors[] = "Unresolved \$ref: {$ref} (referenced {$count}×)";

                    continue 2;
                }
                $node = $node[$segment];
            }
        }

        $defined = array_keys($spec['components']['securitySchemes'] ?? []);
        foreach ($this->collectSecurityNames($spec) as $name) {
            if (! in_array($name, $defined, true)) {
                $this->errors[] = "Security requirement '{$name}' has no matching securityScheme";
            }
        }
    }

    /**
     * @param  mixed  $node
     * @param  array<string, int>  $found
     * @return array<string, int>
     */
    private function collectRefs($node, array &$found = []): array
    {
        if (! is_array($node)) {
            return $found;
        }
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $found[$value] = ($found[$value] ?? 0) + 1;

                continue;
            }
            $this->collectRefs($value, $found);
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    private function collectSecurityNames(array $spec): array
    {
        $names = [];

        $collect = static function ($requirements) use (&$names): void {
            if (! is_array($requirements)) {
                return;
            }
            foreach ($requirements as $requirement) {
                if (! is_array($requirement)) {
                    continue;
                }
                foreach (array_keys($requirement) as $name) {
                    if (is_string($name)) {
                        $names[$name] = true;
                    }
                }
            }
        };

        $collect($spec['security'] ?? []);
        foreach ($spec['paths'] ?? [] as $methods) {
            if (! is_array($methods)) {
                continue;
            }
            foreach ($methods as $op) {
                if (is_array($op)) {
                    $collect($op['security'] ?? []);
                }
            }
        }

        return array_keys($names);
    }

    /** @return list<string> */
    private function pathTemplateParams(string $path): array
    {
        preg_match_all('/\{([^}\/]+)}/', $path, $m);

        return $m[1];
    }
}
