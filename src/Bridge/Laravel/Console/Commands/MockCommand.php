<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel\Console\Commands;

use ApexDocs\ApexDocs;
use Illuminate\Console\Command;

class MockCommand extends Command
{
    protected $signature = 'apexdocs:mock
        {--host=127.0.0.1 : Bind host}
        {--port=8081      : Bind port}';

    protected $description = 'Start a mock HTTP server that returns example responses from your spec';

    /**
     * Path to the framework-agnostic mock router. The router reads the spec
     * from a sidecar JSON file referenced via env var  the spec is NEVER
     * embedded in PHP source. See resources/mock/server.php.
     */
    private const ROUTER = __DIR__.'/../../../../../resources/mock/server.php';

    public function handle(ApexDocs $apexDocs): int
    {
        $host = (string) $this->option('host');
        $port = (string) $this->option('port');
        $spec = $apexDocs->generate()->toArray();
        $n = count($spec['paths'] ?? []);

        $this->info("Mock server → <comment>http://{$host}:{$port}</comment> ({$n} endpoints)");
        $this->info("Press Ctrl+C to stop.\n");

        foreach ($spec['paths'] ?? [] as $path => $methods) {
            if (! is_array($methods)) {
                continue;
            }
            foreach ($methods as $method => $op) {
                if (is_array($op)) {
                    $this->line(sprintf('  <fg=cyan>%-8s</> %s', strtoupper((string) $method), (string) $path));
                }
            }
        }
        $this->newLine();

        $specFile = tempnam(sys_get_temp_dir(), 'apexdocs_spec_');
        if ($specFile === false) {
            $this->error('Could not create temp spec file.');

            return self::FAILURE;
        }
        @chmod($specFile, 0600);
        file_put_contents($specFile, (string) json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $router = realpath(self::ROUTER);
        if ($router === false) {
            $this->error('Mock router script missing: '.self::ROUTER);
            @unlink($specFile);

            return self::FAILURE;
        }

        // Pass the spec path via the environment, NOT the command line  keeps
        // it out of process listings and avoids shell quoting entirely. Using
        // proc_open with an explicit env array also works on Windows, where
        // "VAR=value cmd" is not valid shell syntax.
        $process = @proc_open(
            [PHP_BINARY, '-S', $host.':'.$port, $router],
            [0 => STDIN, 1 => STDOUT, 2 => STDERR],
            $pipes,
            null,
            ['APEXDOCS_MOCK_SPEC' => $specFile] + getenv(),
        );

        if (! is_resource($process)) {
            $this->error('Could not start the PHP development server.');
            @unlink($specFile);

            return self::FAILURE;
        }

        $exitCode = proc_close($process);
        @unlink($specFile);

        // Ctrl+C terminates the child; that is a normal way to stop the server.
        return $exitCode === 0 || $exitCode === 130 || $exitCode === 255 ? self::SUCCESS : self::FAILURE;
    }
}
