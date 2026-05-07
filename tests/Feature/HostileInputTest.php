<?php

declare(strict_types=1);

use ApexDocs\ApexDocs;
use ApexDocs\Attribute\ApiResponse;
use ApexDocs\Attribute\QueryParam;
use ApexDocs\Config;
use ApexDocs\Export\BrunoExporter;
use ApexDocs\Export\InsomniaExporter;
use ApexDocs\Export\JsonExporter;
use ApexDocs\Export\PostmanExporter;
use ApexDocs\Export\YamlExporter;
use ApexDocs\Route\ArrayRouteCollection;
use ApexDocs\Route\Route;

/**
 * Inputs that used to produce an invalid document or a PHP warning. The
 * generator runs inside someone else's application: it must degrade to valid
 * output rather than emit something no validator accepts.
 */
class HostileController
{
    public function __invoke(): void {}

    #[ApiResponse(status: 999, description: 'Out of range')]
    public function outOfRange(): void {}

    #[QueryParam(name: '')]
    #[QueryParam(name: 'ok')]
    public function namelessParam(): void {}
}

function hostileSpec(array $routes, ?Config $config = null): array
{
    return ApexDocs::make($config ?? new Config(title: 'H', version: '1'))
        ->routes(new ArrayRouteCollection($routes))
        ->generate()
        ->toArray();
}

it('marks an optional route segment as a required path parameter', function () {
    // The emitted template always contains {id}, and the spec requires every
    // path parameter to be required.
    $params = hostileSpec([new Route(['GET'], '/api/items/{id?}', [HostileController::class, '__invoke'])])
        ['paths']['/api/items/{id}']['get']['parameters'];

    expect($params[0]['name'])->toBe('id')
        ->and($params[0]['required'])->toBeTrue()
        ->and($params[0]['description'])->toContain('optional segment');
});

it('files an out-of-range status under default rather than emitting an invalid key', function () {
    $responses = hostileSpec([new Route(['GET'], '/api/x', [HostileController::class, 'outOfRange'])])
        ['paths']['/api/x']['get']['responses'];

    expect($responses)->toHaveKey('default')
        ->and($responses)->not->toHaveKey('999')
        ->and($responses['default']['description'])->toBe('Out of range');
});

it('drops a parameter attribute with no name', function () {
    $params = hostileSpec([new Route(['GET'], '/api/x', [HostileController::class, 'namelessParam'])])
        ['paths']['/api/x']['get']['parameters'];

    expect($params)->toHaveCount(1)
        ->and($params[0]['name'])->toBe('ok');
});

it('survives an exclude pattern that is not a valid regex', function () {
    $warnings = [];
    set_error_handler(function (int $no, string $msg) use (&$warnings): bool {
        $warnings[] = $msg;

        return true;
    });

    try {
        $spec = hostileSpec(
            [new Route(['GET'], '/api/x', [HostileController::class, '__invoke'])],
            new Config(title: 'H', version: '1', excludePaths: ['[', '(unclosed']),
        );
    } finally {
        restore_error_handler();
    }

    expect($warnings)->toBe([])
        ->and($spec['paths'])->toHaveKey('/api/x');
});

it('handles a route with no usable methods without crashing', function () {
    $spec = hostileSpec([new Route([], '/api/x', [HostileController::class, '__invoke'])]);

    expect($spec['paths']['/api/x'])->toHaveKey('get');
});

it('keeps every exporter working on a hostile document', function () {
    $doc = ApexDocs::make(new Config(title: '0', version: '0'))
        ->routes(new ArrayRouteCollection([
            new Route(['GET'], '/api/{a}-{b}', [HostileController::class, '__invoke']),
            new Route(['POST'], '/api/a.b+c(d)', [HostileController::class, 'outOfRange']),
            new Route(['GET'], '/api/ünïcodé/{id}', 'NoSuchClass@nope'),
        ]))
        ->generate();

    foreach ([JsonExporter::class, YamlExporter::class, PostmanExporter::class, InsomniaExporter::class, BrunoExporter::class] as $exporter) {
        expect((new $exporter)->toString($doc))->toBeString()->not->toBeEmpty();
    }

    // "0" is falsy but still a legitimate title and version.
    expect($doc->toArray()['info'])->toBe(['title' => '0', 'version' => '0']);
});
