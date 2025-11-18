<?php

declare(strict_types=1);

use ApexDocs\ApexDocs;
use ApexDocs\Bridge\Symfony\DependencyInjection\ApexDocsExtension;
use ApexDocs\Bridge\Symfony\RouteCollection as SymfonyRouteBridge;
use ApexDocs\Bridge\Symfony\SecurityDetector as SymfonySecurityDetector;
use ApexDocs\Bridge\Symfony\ValidationExtractor as SymfonyValidationExtractor;
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
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection as SymfonyRouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * The Symfony bridge stays untested at the container level until someone
 * actually plugs the bundle into a Symfony app — by which point a compile
 * failure breaks their boot. These tests run the extension against a real
 * ContainerBuilder and assert that every public service resolves to an
 * instance of the expected concrete class.
 *
 * If any of these fail, no Symfony user can boot the bundle. The test
 * thus acts as a contract check on the entire DI wiring graph.
 */
function buildContainer(array $userConfig = []): ContainerBuilder
{
    $container = new ContainerBuilder;

    // Stub the RouterInterface dependency that ApexDocs' Symfony route bridge
    // expects. We can't pull in symfony/framework-bundle just for tests, so
    // a minimal factory pointing at an in-process router instance is enough.
    $routerDef = new Definition(RouterInterface::class);
    $routerDef->setFactory(['TestRouterFactory', 'create'])->setPublic(true);
    $container->setDefinition(RouterInterface::class, $routerDef);

    $ext = new ApexDocsExtension;
    $ext->load([$userConfig], $container);

    $container->compile();

    return $container;
}

final class TestRouterFactory
{
    public static function create(): RouterInterface
    {
        return new class implements RouterInterface
        {
            private SymfonyRouteCollection $rc;

            public function __construct()
            {
                $this->rc = new SymfonyRouteCollection;
            }

            public function setContext(RequestContext $context): void {}

            public function getContext(): RequestContext
            {
                return new RequestContext;
            }

            public function getRouteCollection(): SymfonyRouteCollection
            {
                return $this->rc;
            }

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/';
            }

            public function match(string $pathinfo): array
            {
                return [];
            }
        };
    }
}

it('compiles the container with default config', function () {
    $container = buildContainer();

    expect($container->has(ApexDocs::class))->toBeTrue();
});

it('binds Config to the value object', function () {
    $container = buildContainer();
    expect($container->get(Config::class))->toBeInstanceOf(Config::class);
});

it('binds RouteCollectionInterface to the Symfony bridge', function () {
    $container = buildContainer();
    expect($container->get(RouteCollectionInterface::class))->toBeInstanceOf(SymfonyRouteBridge::class);
});

it('binds ValidationExtractorInterface to the Symfony bridge', function () {
    $container = buildContainer();
    expect($container->get(ValidationExtractorInterface::class))->toBeInstanceOf(SymfonyValidationExtractor::class);
});

it('binds SecurityDetectorInterface to the Symfony bridge', function () {
    $container = buildContainer();
    expect($container->get(SecurityDetectorInterface::class))->toBeInstanceOf(SymfonySecurityDetector::class);
});

it('binds the UI renderer and every exporter', function () {
    $container = buildContainer();

    expect($container->get(UiRenderer::class))->toBeInstanceOf(UiRenderer::class)
        ->and($container->get(JsonExporter::class))->toBeInstanceOf(JsonExporter::class)
        ->and($container->get(YamlExporter::class))->toBeInstanceOf(YamlExporter::class)
        ->and($container->get(PostmanExporter::class))->toBeInstanceOf(PostmanExporter::class)
        ->and($container->get(InsomniaExporter::class))->toBeInstanceOf(InsomniaExporter::class)
        ->and($container->get(BrunoExporter::class))->toBeInstanceOf(BrunoExporter::class);
});

it('builds the ApexDocs facade through the factory', function () {
    $container = buildContainer();
    expect($container->get(ApexDocs::class))->toBeInstanceOf(ApexDocs::class);
});

it('honours user-supplied apex_docs config', function () {
    $container = buildContainer([
        'info' => ['title' => 'Custom Symfony API', 'version' => '7.7.7'],
        'api_path_prefix' => 'v1',
    ]);

    /** @var Config $cfg */
    $cfg = $container->get(Config::class);
    expect($cfg->title)->toBe('Custom Symfony API')
        ->and($cfg->version)->toBe('7.7.7');
});

it('rejects invalid config types via the bundle schema', function () {
    // max_depth must be an integer; a string should explode at validate time.
    expect(fn () => buildContainer([
        'responses' => ['max_depth' => 'not-an-int'],
    ]))->toThrow(InvalidConfigurationException::class);
});
