<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel\Console\Commands;

use ApexDocs\ApexDocs;
use ApexDocs\Validation\SpecValidator;
use Illuminate\Console\Command;

class ValidateCommand extends Command
{
    protected $signature = 'apexdocs:validate
        {--strict : Treat warnings as errors (useful in CI)}';

    protected $description = 'Validate the generated OpenAPI spec for errors and warnings';

    public function handle(ApexDocs $apexDocs, SpecValidator $validator): int
    {
        ['errors' => $errors, 'warnings' => $warnings] = $validator->validate($apexDocs->generate()->toArray());

        foreach ($errors as $e) {
            $this->error($e);
        }
        foreach ($warnings as $w) {
            $this->warn($w);
        }

        $this->newLine();
        $strict = (bool) $this->option('strict');

        if ($errors === [] && ! ($strict && $warnings !== [])) {
            $this->info('<fg=green>✓</> Valid. '.count($warnings).' warning(s).');

            return self::SUCCESS;
        }

        $this->error(count($errors).' error(s), '.count($warnings).' warning(s).');

        return self::FAILURE;
    }
}
