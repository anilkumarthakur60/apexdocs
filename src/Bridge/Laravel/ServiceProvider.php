<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel;

use ApexDocs\ApexDocs;
use ApexDocs\Bridge\Laravel\Console\Commands\DiffCommand;
use ApexDocs\Bridge\Laravel\Console\Commands\ExportCommand;
use ApexDocs\Bridge\Laravel\Console\Commands\GenerateCommand;
use ApexDocs\Bridge\Laravel\Console\Commands\MockCommand;
use ApexDocs\Bridge\Laravel\Console\Commands\ValidateCommand;
use ApexDocs\Bridge\Laravel\Console\Commands\WatchCommand;
use ApexDocs\Config;
use ApexDocs\Contract\RouteCollectionInterface;
use ApexDocs\Contract\SecurityDetectorInterface;
use ApexDocs\Contract\ValidationExtractorInterface;
use ApexDocs\Export\BrunoExporter;
use ApexDocs\Export\InsomniaExporter;
use ApexDocs\Export\JsonExporter;
use ApexDocs\Export\PostmanExporter;
use ApexDocs\Export\YamlExporter;
use ApexDocs\Http\UiRenderer;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;

class ServiceProvider extends LaravelServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/apexdocs.php', 'apexdocs');

        // Bindings
        $this->app->singleton(Config::class, function ($app) {
            $cfg = $app['config']->get('apexdocs', []);
            // Inject app.url as the default server when the user hasn't set one.
            // We read from trusted Laravel config — never from $_SERVER.
            if (empty($cfg['servers'])) {
                $appUrl = $app['config']->get('app.url');
                if (is_string($appUrl) && $appUrl !== '') {
                    $cfg['servers'] = [['url' => rtrim($appUrl, '/'), 'description' => $app['config']->get('app.env', 'production')]];
                }
            }

            return Config::fromArray($cfg);
        });

        $this->app->singleton(RouteCollectionInterface::class, fn ($app) => new RouteCollection($app->make(Router::class)));

        $this->app->singleton(ValidationExtractorInterface::class, fn ($app) => new ValidationExtractor(new RuleParser));

        $this->app->singleton(SecurityDetectorInterface::class, fn () => new SecurityDetector);

        $this->app->singleton(ApexDocs::class, fn ($app) => ApexDocs::make($app->make(Config::class))
            ->routes($app->make(RouteCollectionInterface::class))
            ->validation($app->make(ValidationExtractorInterface::class))
            ->security($app->make(SecurityDetectorInterface::class))
        );

        // Alias
        $this->app->alias(ApexDocs::class, 'apexdocs');

        // Exporters
        $this->app->bind(JsonExporter::class);
        $this->app->bind(YamlExporter::class);
        $this->app->bind(PostmanExporter::class);
        $this->app->bind(InsomniaExporter::class);
        $this->app->bind(BrunoExporter::class);
        $this->app->bind(UiRenderer::class);
    }

    public function boot(): void
    {
        $this->publishConfig();
        $this->registerRoutes();
        $this->registerCommands();
    }

    private function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/config/apexdocs.php' => config_path('apexdocs.php'),
        ], 'apexdocs-config');
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $middleware = config('apexdocs.middleware', ['web']);
        $basePath = config('apexdocs.ui.path', 'documentation/api');

        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->group(['middleware' => $middleware], function () use ($router, $basePath) {
            $router->get($basePath, [DocsController::class, 'ui'])->name('apexdocs.ui');
            $router->get($basePath.'/spec.json', [DocsController::class, 'json'])->name('apexdocs.json');
            $router->get($basePath.'/spec.yaml', [DocsController::class, 'yaml'])->name('apexdocs.yaml');
            $router->get($basePath.'/postman', [DocsController::class, 'postman'])->name('apexdocs.postman');
            $router->get($basePath.'/insomnia', [DocsController::class, 'insomnia'])->name('apexdocs.insomnia');
            $router->get($basePath.'/bruno', [DocsController::class, 'bruno'])->name('apexdocs.bruno');
        });
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateCommand::class,
                ValidateCommand::class,
                ExportCommand::class,
                DiffCommand::class,
                WatchCommand::class,
                MockCommand::class,
            ]);
        }
    }
}
