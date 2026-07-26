<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel\Console\Commands;

use ApexDocs\ApexDocs;
use Illuminate\Console\Command;

class DiffCommand extends Command
{
    protected $signature = 'apexdocs:diff
        {base            : Path to the baseline OpenAPI JSON file}
        {--format=text   : Output format: text or json}';

    protected $description = 'Diff current spec against a saved baseline to detect breaking changes';

    public function handle(ApexDocs $apexDocs): int
    {
        $basePath = (string) $this->argument('base');

        if (! is_file($basePath) || ! is_readable($basePath)) {
            $this->error("Baseline file not found or unreadable: {$basePath}");

            return self::FAILURE;
        }

        $raw = file_get_contents($basePath);
        $base = $raw === false ? null : json_decode($raw, true);

        if (! is_array($base) || ! $this->hasUsablePaths($base)) {
            $this->error("Baseline is not a valid OpenAPI JSON document: {$basePath}");

            return self::FAILURE;
        }

        $current = $apexDocs->generate()->toArray();

        [$breaking, $added, $changed] = $this->diff($base, $current);

        if ($this->option('format') === 'json') {
            $this->line(json_encode(compact('breaking', 'added', 'changed'), JSON_PRETTY_PRINT));

            return empty($breaking) ? self::SUCCESS : self::FAILURE;
        }

        foreach ($breaking as $msg) {
            $this->line("  <fg=red>✗ BREAKING:</> {$msg}");
        }
        foreach ($added as $msg) {
            $this->line("  <fg=green>+ ADDED:</>   {$msg}");
        }
        foreach ($changed as $msg) {
            $this->line("  <fg=yellow>~ CHANGED:</> {$msg}");
        }

        $this->newLine();
        $this->line(count($breaking).' breaking · '.count($added).' added · '.count($changed).' changed');

        return empty($breaking) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * `paths` and every path item must be maps before the diff walks them; a
     * scalar anywhere in there would reach array_keys() and throw.
     *
     * @param  array<string, mixed>  $base
     */
    private function hasUsablePaths(array $base): bool
    {
        if (! array_key_exists('paths', $base)) {
            return true;
        }
        if (! is_array($base['paths'])) {
            return false;
        }
        foreach ($base['paths'] as $item) {
            if (! is_array($item)) {
                return false;
            }
        }

        return true;
    }

    private function diff(array $base, array $current): array
    {
        $breaking = [];
        $added = [];
        $changed = [];

        $basePaths = array_keys($base['paths'] ?? []);
        $currentPaths = array_keys($current['paths'] ?? []);

        foreach (array_diff($basePaths, $currentPaths) as $path) {
            foreach (array_keys($base['paths'][$path] ?? []) as $method) {
                $breaking[] = strtoupper($method)." {$path} removed";
            }
        }

        foreach (array_diff($currentPaths, $basePaths) as $path) {
            foreach (array_keys($current['paths'][$path] ?? []) as $method) {
                $added[] = strtoupper($method)." {$path}";
            }
        }

        foreach (array_intersect($basePaths, $currentPaths) as $path) {
            $bm = array_keys($base['paths'][$path] ?? []);
            $cm = array_keys($current['paths'][$path] ?? []);

            foreach (array_diff($bm, $cm) as $m) {
                $breaking[] = strtoupper($m)." {$path} method removed";
            }
            foreach (array_diff($cm, $bm) as $m) {
                $added[] = strtoupper($m)." {$path}";
            }

            foreach (array_intersect($bm, $cm) as $m) {
                $baseOp = $base['paths'][$path][$m] ?? [];
                $currentOp = $current['paths'][$path][$m] ?? [];
                if (! is_array($baseOp) || ! is_array($currentOp)) {
                    continue;
                }

                // Required params added → breaking
                $bReq = $this->requiredParams($baseOp);
                $cReq = $this->requiredParams($currentOp);
                foreach (array_diff($cReq, $bReq) as $p) {
                    $breaking[] = strtoupper($m)." {$path}: new required param '{$p}'";
                }
                foreach (array_diff($bReq, $cReq) as $p) {
                    $changed[] = strtoupper($m)." {$path}: param '{$p}' no longer required";
                }

                // Newly required request-body fields break existing clients too
                $bBody = $this->requiredBodyFields($baseOp);
                $cBody = $this->requiredBodyFields($currentOp);
                foreach (array_diff($cBody, $bBody) as $field) {
                    $breaking[] = strtoupper($m)." {$path}: new required body field '{$field}'";
                }

                // A response status a client relied on has disappeared
                $bStatuses = array_keys($baseOp['responses'] ?? []);
                $cStatuses = array_keys($currentOp['responses'] ?? []);
                foreach (array_diff($bStatuses, $cStatuses) as $status) {
                    if ((int) $status >= 200 && (int) $status < 400) {
                        $breaking[] = strtoupper($m)." {$path}: response {$status} removed";
                    }
                }

                if (! ($baseOp['deprecated'] ?? false) && ($currentOp['deprecated'] ?? false)) {
                    $changed[] = strtoupper($m)." {$path}: deprecated";
                }
            }
        }

        return [$breaking, $added, $changed];
    }

    /** @return list<string> */
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
     * Body fields that a client must now send but did not before.
     *
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
