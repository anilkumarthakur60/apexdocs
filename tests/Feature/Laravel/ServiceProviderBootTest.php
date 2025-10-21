<?php

declare(strict_types=1);

use ApexDocs\ApexDocs;
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

it('boots Orchestra Testbench with the ApexDocs service provider', function () {
    expect(app()->bound(ApexDocs::class))->toBeTrue();
});

it('binds every public contract to a concrete implementation', function (string $abstract) {
    $resolved = app($abstract);
    expect($resolved)->not->toBeNull();
})->with([
    Config::class,
    ApexDocs::class,
    RouteCollectionInterface::class,
    ValidationExtractorInterface::class,
    SecurityDetectorInterface::class,
    UiRenderer::class,
    JsonExporter::class,
    YamlExporter::class,
    PostmanExporter::class,
    InsomniaExporter::class,
    BrunoExporter::class,
]);

it('registers every doc route under the configured prefix', function () {
    $routeNames = ['apexdocs.ui', 'apexdocs.json', 'apexdocs.yaml', 'apexdocs.postman', 'apexdocs.insomnia', 'apexdocs.bruno'];

    foreach ($routeNames as $name) {
        expect(app('router')->getRoutes()->getByName($name))->not->toBeNull();
    }
});

it('seeds the default server from app.url when servers is empty', function () {
    config()->set('apexdocs.servers', []);
    config()->set('app.url', 'https://prod.example.test');

    // Re-resolve so the singleton picks up the new config
    app()->forgetInstance(Config::class);
    /** @var Config $cfg */
    $cfg = app(Config::class);

    expect($cfg->servers[0]['url'])->toBe('https://prod.example.test');
});

it('serves a 200 with valid JSON from the /spec.json route', function () {
    $response = $this->get('/documentation/api/spec.json');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json');

    $body = json_decode($response->getContent(), true);
    expect($body)->toBeArray()
        ->and($body['openapi'] ?? null)->toBe('3.1.0');
});

it('serves YAML from the /spec.yaml route', function () {
    $response = $this->get('/documentation/api/spec.yaml');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/yaml');
});

it('serves the UI HTML from the docs root', function () {
    $response = $this->get('/documentation/api');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/html');
    expect($response->getContent())->toContain('<html');
});

it('attaches the Postman download Content-Disposition', function () {
    $response = $this->get('/documentation/api/postman');

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))
        ->toContain('postman-collection.json');
});
