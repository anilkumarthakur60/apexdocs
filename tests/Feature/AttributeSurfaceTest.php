<?php

declare(strict_types=1);

use ApexDocs\ApexDocs;
use ApexDocs\Config;
use ApexDocs\Route\ArrayRouteCollection;
use ApexDocs\Route\Route;
use ApexDocs\Tests\Fixtures\Controllers\ReportController;

/**
 * Regression cover for the attribute handling that shipped broken:
 *
 *  - an explicit 201 no longer grows a phantom 200 beside it
 *  - #[Produces] keeps the required `description` and does not invent a 200
 *  - #[ResponseHeader] lands in responses.<code>.headers, not an extension
 *  - #[Example] is actually read
 *  - repeatable #[Tag] contributes every tag
 *  - method-level parameter attributes beat class-level ones
 *  - a route registered for several verbs yields one operation per verb
 */
function reportSpec(): array
{
    $routes = new ArrayRouteCollection([
        new Route(['POST'], '/api/reports', [ReportController::class, 'store']),
        new Route(['GET'], '/api/reports/{id}/pdf', [ReportController::class, 'download']),
        new Route(['GET'], '/api/reports/{id}/{slug}', [ReportController::class, 'show']),
        new Route(['GET', 'POST'], '/api/reports/any', [ReportController::class, 'anyVerb'], metadata: ['name' => 'reports.any']),
    ]);

    return ApexDocs::make(new Config(title: 'Reports', version: '1.0.0'))
        ->routes($routes)
        ->generate()
        ->toArray();
}

it('does not add a 200 next to an explicitly documented 201', function () {
    $responses = reportSpec()['paths']['/api/reports']['post']['responses'];

    expect($responses)->toHaveKey('201')
        ->and($responses)->not->toHaveKey('200');
});

it('keeps a description on the response #[Produces] rewrites', function () {
    $response = reportSpec()['paths']['/api/reports/{id}/pdf']['get']['responses']['200'];

    expect($response['description'])->toBe('Rendered PDF')
        ->and($response['content'])->toHaveKey('application/pdf')
        ->and($response['content'])->not->toHaveKey('application/json');
});

it('documents #[ResponseHeader] on the response object itself', function () {
    $response = reportSpec()['paths']['/api/reports/{id}/pdf']['get']['responses']['200'];

    expect($response['headers'])->toHaveKey('X-Request-Id')
        ->and($response['headers']['X-Request-Id']['schema']['type'])->toBe('string');
});

it('wires #[Example] into request and response examples', function () {
    $op = reportSpec()['paths']['/api/reports']['post'];

    expect($op['requestBody']['content']['application/json']['examples'])->toHaveKey('minimal')
        ->and($op['requestBody']['content']['application/json']['examples']['minimal']['value'])->toBe(['name' => 'Q1'])
        ->and($op['responses']['201']['content']['application/json']['examples'])->toHaveKey('created');
});

it('encodes a nullable #[BodyParam] as an OpenAPI 3.1 type array', function () {
    $properties = reportSpec()['paths']['/api/reports']['post']['requestBody']['content']['application/json']['schema']['properties'];

    expect($properties['note'])->not->toHaveKey('nullable')
        ->and($properties['note']['type'])->toBe(['string', 'null']);
});

it('collects every repeatable #[Tag]', function () {
    $spec = reportSpec();

    expect($spec['paths']['/api/reports/{id}/pdf']['get']['tags'])->toBe(['Reports', 'Legacy'])
        ->and(array_column($spec['tags'], 'name'))->toContain('Reports', 'Legacy');
});

it('lets a method-level parameter attribute win over the class-level one', function () {
    $params = reportSpec()['paths']['/api/reports/{id}/{slug}']['get']['parameters'];
    $trace = current(array_filter($params, fn ($p) => $p['name'] === 'trace'));

    expect($trace['description'])->toBe('method level');
});

it('types path parameters from the handler signature and the docblock', function () {
    $params = reportSpec()['paths']['/api/reports/{id}/{slug}']['get']['parameters'];
    $byName = array_column($params, null, 'name');

    expect($byName['id']['schema']['type'])->toBe('integer')
        ->and($byName['id']['description'])->toBe('The report identifier')
        ->and($byName['slug']['schema']['type'])->toBe('string');
});

it('documents one operation per verb for a multi-method route', function () {
    $item = reportSpec()['paths']['/api/reports/any'];

    expect(array_keys($item))->toBe(['get', 'post'])
        ->and($item['get']['operationId'])->not->toBe($item['post']['operationId']);
});

it('keeps validation errors off read-only verbs', function () {
    $item = reportSpec()['paths']['/api/reports/any'];

    expect($item['get']['responses'])->not->toHaveKey('422')
        ->and($item['post']['responses'])->toHaveKey('422');
});

it('never emits the OpenAPI 3.0 nullable keyword anywhere', function () {
    $json = json_encode(reportSpec());

    expect($json)->not->toContain('"nullable"');
});

it('documents closure routes from the route alone instead of dropping them', function () {
    $spec = ApexDocs::make(new Config(title: 'C', version: '1'))
        ->routes(new ArrayRouteCollection([new Route(['GET'], '/api/ping', '')]))
        ->generate()
        ->toArray();

    expect($spec['paths'])->toHaveKey('/api/ping')
        ->and($spec['paths']['/api/ping']['get']['responses'])->toHaveKey('200');
});
