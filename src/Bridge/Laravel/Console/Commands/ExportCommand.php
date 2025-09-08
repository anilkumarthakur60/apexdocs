<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel\Console\Commands;

use ApexDocs\ApexDocs;
use ApexDocs\Export\InsomniaExporter;
use ApexDocs\Export\JsonExporter;
use ApexDocs\Export\PostmanExporter;
use ApexDocs\Export\YamlExporter;
use Illuminate\Console\Command;

class ExportCommand extends Command
{
    protected $signature = 'apexdocs:export
        {format          : openapi-json | openapi-yaml | postman | insomnia}
        {--output=       : Output file path}';

    protected $description = 'Export the API spec in various formats';

    public function handle(
        ApexDocs $apexDocs,
        JsonExporter $json,
        YamlExporter $yaml,
        PostmanExporter $postman,
        InsomniaExporter $insomnia,
    ): int {
        $doc = $apexDocs->generate();
        $format = $this->argument('format');
        $base = config('apexdocs.export.default_path', storage_path('apexdocs'));
        $out = $this->option('output');

        match ($format) {
            'openapi-json' => $json->toFile($doc, $out ?: "{$base}/openapi.json"),
            'openapi-yaml' => $yaml->toFile($doc, $out ?: "{$base}/openapi.yaml"),
            'postman' => $postman->toFile($doc, $out ?: "{$base}/postman.json"),
            'insomnia' => $insomnia->toFile($doc, $out ?: "{$base}/insomnia.json"),
            default => $this->error("Unknown format: {$format}"),
        };

        if (in_array($format, ['openapi-json', 'openapi-yaml', 'postman', 'insomnia'], true)) {
            $this->info("<fg=green>✓</> Exported {$format}.");
        }

        return self::SUCCESS;
    }
}
