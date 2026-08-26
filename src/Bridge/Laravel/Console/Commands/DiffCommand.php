<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel\Console\Commands;

use ApexDocs\ApexDocs;
use ApexDocs\Diff\SpecDiff;
use Illuminate\Console\Command;

class DiffCommand extends Command
{
    protected $signature = 'apexdocs:diff
        {base            : Path to the baseline OpenAPI JSON file}
        {--format=text   : Output format: text or json}';

    protected $description = 'Diff current spec against a saved baseline to detect breaking changes';

    public function handle(ApexDocs $apexDocs, SpecDiff $diff): int
    {
        $basePath = (string) $this->argument('base');

        if (! is_file($basePath) || ! is_readable($basePath)) {
            $this->error("Baseline file not found or unreadable: {$basePath}");

            return self::FAILURE;
        }

        $raw = file_get_contents($basePath);
        $base = $raw === false ? null : json_decode($raw, true);

        if (! is_array($base) || ! SpecDiff::hasUsablePaths($base)) {
            $this->error("Baseline is not a valid OpenAPI JSON document: {$basePath}");

            return self::FAILURE;
        }

        $result = $diff->compare($base, $apexDocs->generate()->toArray());
        ['breaking' => $breaking, 'added' => $added, 'changed' => $changed] = $result;

        if ($this->option('format') === 'json') {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return $breaking === [] ? self::SUCCESS : self::FAILURE;
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

        return $breaking === [] ? self::SUCCESS : self::FAILURE;
    }
}
