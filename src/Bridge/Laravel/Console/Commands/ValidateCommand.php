<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel\Console\Commands;

use ApexDocs\ApexDocs;
use Illuminate\Console\Command;

class ValidateCommand extends Command
{
    protected $signature = 'apexdocs:validate';

    protected $description = 'Validate the generated OpenAPI spec for errors and warnings';

    public function handle(ApexDocs $apexDocs): int
    {
        $spec = $apexDocs->generate()->toArray();
        $errors = [];
        $warns = [];

        foreach (['openapi', 'info', 'paths'] as $f) {
            if (empty($spec[$f])) {
                $errors[] = "Missing required field: {$f}";
            }
        }

        foreach ($spec['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $op) {
                if (! is_array($op)) {
                    continue;
                }
                $loc = strtoupper($method)." {$path}";
                if (empty($op['responses'])) {
                    $errors[] = "{$loc}: no responses";
                }
                if (empty($op['operationId'])) {
                    $warns[] = "{$loc}: missing operationId";
                }
                if (empty($op['summary'])) {
                    $warns[] = "{$loc}: missing summary";
                }
            }
        }

        foreach ($errors as $e) {
            $this->error($e);
        }
        foreach ($warns as $w) {
            $this->warn($w);
        }

        $this->newLine();
        if (empty($errors)) {
            $this->info('<fg=green>✓</> Valid. '.count($warns).' warning(s).');

            return self::SUCCESS;
        }
        $this->error(count($errors).' error(s) found.');

        return self::FAILURE;
    }
}
