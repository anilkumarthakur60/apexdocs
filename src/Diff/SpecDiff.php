<?php

declare(strict_types=1);

namespace ApexDocs\Diff;

/**
 * Compares two OpenAPI documents and classifies the differences.
 *
 * Framework-agnostic — used by `apexdocs:diff` and the MCP server.
 *
 * Breaking: a path or method removed, a new required parameter, a new required
 * request-body field, a 2xx/3xx response removed.
 * Added: new paths / methods.
 * Changed: a parameter no longer required, an operation newly deprecated.
 */
final class SpecDiff
{
    /**
     * `paths` and every path item must be maps before the diff walks them; a
     * scalar anywhere in there would reach array_keys() and throw.
     *
     * @param  array<string, mixed>  $spec
     */
    public static function hasUsablePaths(array $spec): bool
    {
        if (! array_key_exists('paths', $spec)) {
            return true;
        }
        if (! is_array($spec['paths'])) {
            return false;
        }
        foreach ($spec['paths'] as $item) {
            if (! is_array($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $current
     * @return array{breaking: list<string>, added: list<string>, changed: list<string>}
     */
    public function compare(array $base, array $current): array
    {
        $breaking = [];
        $added = [];
        $changed = [];

        $basePaths = array_keys($base['paths'] ?? []);
        $currentPaths = array_keys($current['paths'] ?? []);

        foreach (array_diff($basePaths, $currentPaths) as $path) {
            foreach (array_keys($base['paths'][$path] ?? []) as $method) {
                $breaking[] = strtoupper((string) $method)." {$path} removed";
            }
        }

        foreach (array_diff($currentPaths, $basePaths) as $path) {
            foreach (array_keys($current['paths'][$path] ?? []) as $method) {
                $added[] = strtoupper((string) $method)." {$path}";
            }
        }

        foreach (array_intersect($basePaths, $currentPaths) as $path) {
            $bm = array_keys($base['paths'][$path] ?? []);
            $cm = array_keys($current['paths'][$path] ?? []);

            foreach (array_diff($bm, $cm) as $m) {
                $breaking[] = strtoupper((string) $m)." {$path} method removed";
            }
            foreach (array_diff($cm, $bm) as $m) {
                $added[] = strtoupper((string) $m)." {$path}";
            }

            foreach (array_intersect($bm, $cm) as $m) {
                $baseOp = $base['paths'][$path][$m] ?? [];
                $currentOp = $current['paths'][$path][$m] ?? [];
                if (! is_array($baseOp) || ! is_array($currentOp)) {
                    continue;
                }
                $verb = strtoupper((string) $m);

                $bReq = $this->requiredParams($baseOp);
                $cReq = $this->requiredParams($currentOp);
                foreach (array_diff($cReq, $bReq) as $p) {
                    $breaking[] = "{$verb} {$path}: new required param '{$p}'";
                }
                foreach (array_diff($bReq, $cReq) as $p) {
                    $changed[] = "{$verb} {$path}: param '{$p}' no longer required";
                }

                $bBody = $this->requiredBodyFields($baseOp);
                $cBody = $this->requiredBodyFields($currentOp);
                foreach (array_diff($cBody, $bBody) as $field) {
                    $breaking[] = "{$verb} {$path}: new required body field '{$field}'";
                }

                $bStatuses = array_keys($baseOp['responses'] ?? []);
                $cStatuses = array_keys($currentOp['responses'] ?? []);
                foreach (array_diff($bStatuses, $cStatuses) as $status) {
                    if ((int) $status >= 200 && (int) $status < 400) {
                        $breaking[] = "{$verb} {$path}: response {$status} removed";
                    }
                }

                if (! ($baseOp['deprecated'] ?? false) && ($currentOp['deprecated'] ?? false)) {
                    $changed[] = "{$verb} {$path}: deprecated";
                }
            }
        }

        return ['breaking' => $breaking, 'added' => $added, 'changed' => $changed];
    }

    /**
     * @param  array<string, mixed>  $op
     * @return list<string>
     */
    private function requiredParams(array $op): array
    {
        $out = [];
        foreach ($op['parameters'] ?? [] as $param) {
            if (is_array($param) && isset($param['name'], $param['in']) && ($param['required'] ?? false)) {
                $out[] = $param['name'].':'.$param['in'];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $op
     * @return list<string>
     */
    private function requiredBodyFields(array $op): array
    {
        $schema = $op['requestBody']['content']['application/json']['schema'] ?? null;
        if (! is_array($schema) || ! is_array($schema['required'] ?? null)) {
            return [];
        }

        return array_values(array_filter($schema['required'], 'is_string'));
    }
}
