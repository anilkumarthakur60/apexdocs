<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel\Console\Commands;

use ApexDocs\ApexDocs;
use ApexDocs\Exception\ExporterException;
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
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['json', 'yaml', 'yml'], true)) {
            $this->error("Unknown format: {$format}. Use json or yaml.");

            return self::INVALID;
        }
        $isYaml = $format !== 'json';

        $started = microtime(true);
        $doc = $apexDocs->generate();
        $ms = (int) round((microtime(true) - $started) * 1000);

        $output = (string) $this->option('output');
        $paths = count($doc->toArray()['paths'] ?? []);

        if ($output !== '') {
            try {
                $isYaml ? $yaml->toFile($doc, $output) : $json->toFile($doc, $output);
            } catch (ExporterException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info("Written to: <comment>{$output}</comment>");
            $this->info("<fg=green>✓</> {$paths} paths in {$ms}ms");

            return self::SUCCESS;
        }

        // stdout carries the spec and nothing else, so `> openapi.json` and
        // pipes stay valid. Progress goes to stderr.
        $this->output->writeln(
            $isYaml ? $yaml->toString($doc) : $json->toString($doc),
        );
        $this->output->getErrorStyle()->writeln("<fg=green>✓</> {$paths} paths in {$ms}ms");

        return self::SUCCESS;
    }
}
