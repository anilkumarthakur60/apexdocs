<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel\Console\Commands;

use ApexDocs\ApexDocs;
use ApexDocs\Mcp\Snapshot;
use Illuminate\Console\Command;

/**
 * Prints the MCP snapshot (spec + routes + config) as JSON. Used by
 * `apexdocs:mcp`, which runs it in a fresh process so the snapshot always
 * reflects the code on disk. Hidden from `artisan list`.
 */
class SnapshotCommand extends Command
{
    protected $signature = 'apexdocs:snapshot';

    protected $description = 'Print the ApexDocs MCP snapshot (spec, routes, config) as JSON';

    protected $hidden = true;

    public function handle(ApexDocs $apexDocs): int
    {
        // stdout carries the JSON and nothing else — the parent parses it.
        $this->output->writeln((string) json_encode(
            Snapshot::fromApexDocs($apexDocs)->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ));

        return self::SUCCESS;
    }
}
