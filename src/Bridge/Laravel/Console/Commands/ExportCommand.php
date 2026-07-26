<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel\Console\Commands;

use ApexDocs\ApexDocs;
use ApexDocs\Exception\ExporterException;
use ApexDocs\Export\BrunoExporter;
use ApexDocs\Export\InsomniaExporter;
use ApexDocs\Export\JsonExporter;
use ApexDocs\Export\PostmanExporter;
use ApexDocs\Export\YamlExporter;
use Illuminate\Console\Command;

class ExportCommand extends Command
{
    private const FORMATS = ['openapi-json', 'openapi-yaml', 'postman', 'insomnia', 'bruno'];

    protected $signature = 'apexdocs:export
        {format          : openapi-json | openapi-yaml | postman | insomnia | bruno}
        {--output=       : Output file path}';

    protected $description = 'Export the API spec in various formats';

    public function handle(
        ApexDocs $apexDocs,
        JsonExporter $json,
        YamlExporter $yaml,
        PostmanExporter $postman,
        InsomniaExporter $insomnia,
        BrunoExporter $bruno,
    ): int {
        $format = (string) $this->argument('format');

        if (! in_array($format, self::FORMATS, true)) {
            $this->error("Unknown format: {$format}");
            $this->line('Supported: '.implode(' | ', self::FORMATS));

            return self::INVALID;
        }

        $doc = $apexDocs->generate();
        $base = rtrim((string) config('apexdocs.export.default_path', storage_path('apexdocs')), '/');
        $out = (string) $this->option('output');

        $target = $out !== '' ? $out : $base.'/'.match ($format) {
            'openapi-json' => 'openapi.json',
            'openapi-yaml' => 'openapi.yaml',
            default => $format.'.json',
        };

        try {
            match ($format) {
                'openapi-json' => $json->toFile($doc, $target),
                'openapi-yaml' => $yaml->toFile($doc, $target),
                'postman' => $postman->toFile($doc, $target),
                'insomnia' => $insomnia->toFile($doc, $target),
                'bruno' => $bruno->toFile($doc, $target),
            };
        } catch (ExporterException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("<fg=green>✓</> Exported {$format} to <comment>{$target}</comment>");

        return self::SUCCESS;
    }
}
