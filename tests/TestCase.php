<?php

declare(strict_types=1);

namespace ApexDocs\Tests;

use ApexDocs\Bridge\Laravel\ServiceProvider;
use Orchestra\Testbench\TestCase as Base;

abstract class TestCase extends Base
{
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('apexdocs.cache.enabled', false);
        $app['config']->set('apexdocs.environments', ['testing']);
    }
}
