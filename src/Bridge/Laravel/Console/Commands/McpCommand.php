<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel\Console\Commands;

use ApexDocs\ApexDocs;
use ApexDocs\Mcp\InProcessSnapshotProvider;
use ApexDocs\Mcp\McpServer;
use ApexDocs\Mcp\SubprocessSnapshotProvider;
use Illuminate\Console\Command;

class McpCommand extends Command
{
    protected $signature = 'apexdocs:mcp
        {--in-process : Build snapshots in this process (faster, but code changes are not seen until restart)}
        {--timeout=120 : Seconds a snapshot subprocess may take}';

    protected $description = 'Serve the ApexDocs Model Context Protocol server over stdio (Claude Code, Cursor, Copilot, Codex and other MCP clients)';

    public function handle(ApexDocs $apexDocs): int
    {
        $provider = $this->option('in-process')
            ? new InProcessSnapshotProvider($apexDocs)
            : new SubprocessSnapshotProvider(
                [PHP_BINARY, base_path('artisan'), 'apexdocs:snapshot', '--no-ansi'],
                base_path(),
                max(5, (int) $this->option('timeout')),
            );

        $server = new McpServer($provider, dirname(__DIR__, 5).'/resources/ai');

        $input = fopen('php://stdin', 'rb');
        $output = fopen('php://stdout', 'wb');

        if ($input === false || $output === false) {
            $this->error('Unable to open stdio streams.');

            return self::FAILURE;
        }

        $server->serve($input, $output);

        return self::SUCCESS;
    }
}
