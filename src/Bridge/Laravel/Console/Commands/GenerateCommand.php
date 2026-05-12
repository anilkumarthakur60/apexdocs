<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel\Console\Commands;

use ApexDocs\ApexDocs;
use ApexDocs\Export\JsonExporter;
use ApexDocs\Export\YamlExporter;
use Illuminate\Console\Command;

class GenerateCommand extends Command
{
    protected $signature = 'apexdocs:generate
        {--format=json   : Output format: json or yaml}
        {--output=       : Write to file instead of stdout}';

    protected $description = 'Generate the OpenAPI specification';

    public function handle(ApexDocs $apexDocs, JsonExporter $json, YamlExporter $yaml): int
    {
        $t = microtime(true);
        $doc = $apexDocs->generate();
        $ms = round((microtime(true) - $t) * 1000);

        $content = $this->option('format') === 'yaml'
            ? $yaml->toString($doc)
            : $json->toString($doc);

        $output = $this->option('output');
        if ($output) {
            $this->option('format') === 'yaml'
                ? $yaml->toFile($doc, $output)
                : $json->toFile($doc, $output);
            $this->info("Written to: <comment>{$output}</comment>");
        } else {
            $this->line($content);
        }

        $paths = count($doc->toArray()['paths'] ?? []);
        $this->newLine();
        $this->info("<fg=green>✓</> {$paths} paths in {$ms}ms");

        return self::SUCCESS;
    }
}
