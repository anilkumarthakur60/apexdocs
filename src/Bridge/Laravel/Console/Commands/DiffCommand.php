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
        $basePath = $this->argument('base');

        if (! file_exists($basePath)) {
            $this->error("Baseline file not found: {$basePath}");

            return self::FAILURE;
        }

        $base = json_decode(file_get_contents($basePath), true);
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

                if (! ($baseOp['deprecated'] ?? false) && ($currentOp['deprecated'] ?? false)) {
                    $changed[] = strtoupper($m)." {$path}: deprecated";
                }
            }
        }

        return [$breaking, $added, $changed];
    }

    private function requiredParams(array $op): array
    {
        return array_map(
            fn ($p) => $p['name'].':'.$p['in'],
            array_filter($op['parameters'] ?? [], fn ($p) => $p['required'] ?? false),
        );
    }
}
