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
use ApexDocs\Cache\SpecCache;
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
use Illuminate\Contracts\Cache\Factory as CacheFactory;
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

            // Never document the documentation itself. These are the exact six
            // routes registered below — listing them literally, rather than as a
            // `path/*` pattern, means a docs path like "api" cannot swallow the
            // whole spec.
            $docsPath = trim((string) $app['config']->get('apexdocs.ui.path', 'documentation/api'), '/');
            if ($docsPath !== '') {
                $cfg['exclude_paths'] = array_values(array_unique(array_merge(
                    (array) ($cfg['exclude_paths'] ?? []),
                    array_map(
                        static fn (string $suffix): string => $docsPath.$suffix,
                        ['', '/spec.json', '/spec.yaml', '/postman', '/insomnia', '/bruno'],
                    ),
                )));
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

        $this->app->singleton(SpecCache::class, function ($app) {
            $store = $app['config']->get('apexdocs.cache.driver') ?: null;

            return new SpecCache(
                psr16: $app->make(CacheFactory::class)->store($store),
                ttl: (int) $app['config']->get('apexdocs.cache.ttl', 3600),
            );
        });

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
        if ($this->app->routesAreCached() || ! $this->docsAreVisible()) {
            return;
        }

        $middleware = config('apexdocs.middleware', ['web']);
        $basePath = trim((string) config('apexdocs.ui.path', 'documentation/api'), '/');

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

    /**
     * The docs site is registered only in the environments the app allows.
     * An empty list means "every environment"; the default keeps a public API
     * spec out of production unless the app opts in.
     */
    private function docsAreVisible(): bool
    {
        $environments = config('apexdocs.environments', []);

        if (! is_array($environments) || $environments === []) {
            return true;
        }

        return $this->app->environment($environments);
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
