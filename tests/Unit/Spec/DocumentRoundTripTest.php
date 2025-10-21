<?php

declare(strict_types=1);

use ApexDocs\Spec\Components;
use ApexDocs\Spec\Document;

it('round-trips every top-level section through fromArray/toArray', function () {
    $doc = new Document;
    $doc->info('Catalog', '2.0.0', 'demo');
    $doc->addServer('https://api.example.com', 'prod');
    $doc->addGlobalSecurity('bearer', ['read']);
    $doc->addTag('Books', 'Inventory routes');
    $doc->extend('build', 'sha-123');
    $doc->addWebhook('order.placed', ['post' => ['summary' => 'New order']]);
    $doc->components()->addSchema('Book', ['type' => 'object']);

    $array = $doc->toArray();
    $rebuilt = Document::fromArray($array);

    expect($rebuilt->toArray())->toBe($array);
});

it('preserves x- extensions on a cache round-trip', function () {
    $array = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'X', 'version' => '1'],
        'x-internal' => ['team' => 'platform'],
    ];

    $rebuilt = Document::fromArray($array);

    expect($rebuilt->toArray())->toHaveKey('x-internal')
        ->and($rebuilt->toArray()['x-internal'])->toBe(['team' => 'platform']);
});

it('reconstructs Components verbatim', function () {
    $source = [
        'schemas' => ['Foo' => ['type' => 'object']],
        'responses' => ['NotFound' => ['description' => 'gone']],
        'parameters' => ['Page' => ['name' => 'page']],
        'examples' => ['One' => ['value' => 1]],
        'requestBodies' => ['Body' => ['required' => true]],
        'headers' => ['X-RateLimit' => ['schema' => ['type' => 'integer']]],
        'securitySchemes' => ['bearer' => ['type' => 'http']],
    ];

    expect(Components::fromArray($source)->toArray())->toBe($source);
});
