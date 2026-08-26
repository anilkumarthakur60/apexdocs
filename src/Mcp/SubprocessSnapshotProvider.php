<?php

declare(strict_types=1);

namespace ApexDocs\Mcp;

/**
 * Runs a command that prints a JSON snapshot ({@see Snapshot::toArray()}) to
 * stdout and parses it. Every call is a fresh PHP process, so the snapshot
 * always reflects the code on disk — the property a long-lived MCP server
 * cannot get any other way.
 */
final class SubprocessSnapshotProvider implements SnapshotProviderInterface
{
    /**
     * @param  list<string>  $command  argv, e.g. [PHP_BINARY, 'artisan', 'apexdocs:snapshot']
     * @param  string|null  $cwd  working directory for the command
     * @param  int  $timeoutSeconds  hard cap on generation time
     */
    public function __construct(
        private array $command,
        private ?string $cwd = null,
        private int $timeoutSeconds = 120,
    ) {}

    public function snapshot(): Snapshot
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($this->command, $descriptors, $pipes, $this->cwd, null);

        if (! is_resource($process)) {
            throw new \RuntimeException('Could not start snapshot process: '.implode(' ', $this->command));
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (! $status['running']) {
                // Drain whatever arrived between the last read and exit.
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                throw new \RuntimeException("Snapshot process exceeded {$this->timeoutSeconds}s: ".implode(' ', $this->command));
            }

            usleep(20_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            throw new \RuntimeException(sprintf(
                "Snapshot process failed (exit %d): %s\n%s",
                $exit,
                implode(' ', $this->command),
                trim($stderr) !== '' ? trim($stderr) : trim($stdout),
            ));
        }

        try {
            $data = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                'Snapshot process did not print valid JSON: '.$e->getMessage()
                .(trim($stderr) !== '' ? "\nstderr: ".trim($stderr) : ''),
            );
        }

        if (! is_array($data) || ! isset($data['spec'])) {
            throw new \RuntimeException('Snapshot JSON is missing the "spec" key.');
        }

        return Snapshot::fromArray($data);
    }
}
