<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel\Console\Commands;

use ApexDocs\ApexDocs;
use ApexDocs\Export\JsonExporter;
use Illuminate\Console\Command;

class WatchCommand extends Command
{
    protected $signature = 'apexdocs:watch
        {--output=       : File to write spec on each change}
        {--interval=2    : Poll interval in seconds}';

    protected $description = 'Watch for PHP file changes and regenerate the spec automatically';

    private bool $running = true;

    public function handle(ApexDocs $apexDocs, JsonExporter $exporter): int
    {
        $interval = max(1, (int) $this->option('interval'));
        $output = $this->option('output');

        $this->info("Watching for changes (Ctrl+C to stop) | interval: {$interval}s");

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () {
                $this->running = false;
            });
            pcntl_signal(SIGTERM, function () {
                $this->running = false;
            });
        }

        $dirs = array_filter([app_path(), base_path('routes')], 'is_dir');
        $lastHash = $this->hash($dirs);

        $this->regenerate($apexDocs, $exporter, $output);

        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            sleep($interval);

            $hash = $this->hash($dirs);
            if ($hash !== $lastHash) {
                $this->line('['.date('H:i:s').'] Change detected  regenerating...');
                $this->regenerate($apexDocs, $exporter, $output);
                $lastHash = $hash;
            }
        }

        $this->info('Stopped.');

        return self::SUCCESS;
    }

    private function regenerate(ApexDocs $apexDocs, JsonExporter $exporter, ?string $output): void
    {
        try {
            $t = microtime(true);
            $doc = $apexDocs->generate();
            $ms = round((microtime(true) - $t) * 1000);
            $n = count($doc->toArray()['paths'] ?? []);
            $this->line('['.date('H:i:s')."] <fg=green>✓</> {$n} paths ({$ms}ms)");
            if ($output) {
                $exporter->toFile($doc, $output);
            }
        } catch (\Throwable $e) {
            $this->error('['.date('H:i:s').'] Error: '.$e->getMessage());
        }
    }

    private function hash(array $dirs): string
    {
        $stamps = [];
        foreach ($dirs as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $stamps[] = $f->getMTime().$f->getPathname();
                }
            }
        }
        sort($stamps);

        return md5(implode('', $stamps));
    }
}
