<?php

declare(strict_types=1);

use ApexDocs\ApexDocs;
use ApexDocs\Exception\ApexDocsException;
use ApexDocs\Exception\ExporterException;
use ApexDocs\Exception\InvalidConfigException;
use ApexDocs\Exception\MissingRouteCollectionException;
use ApexDocs\Exception\SchemaBuildException;

it('throws a typed MissingRouteCollectionException when generate() is called without routes', function () {
    $apex = new ApexDocs();
    expect(fn () => $apex->generate())
        ->toThrow(MissingRouteCollectionException::class);
});

it('the MissingRouteCollectionException implements the ApexDocsException marker', function () {
    $e = MissingRouteCollectionException::create();
    expect($e)->toBeInstanceOf(ApexDocsException::class)
        ->and($e)->toBeInstanceOf(RuntimeException::class)
        ->and($e->getMessage())->toContain('routes');
});

it('all concrete exceptions can be caught via the marker interface', function () {
    $exceptions = [
        MissingRouteCollectionException::create(),
        InvalidConfigException::forField('ui.theme', 'must be dark, light or auto.'),
        SchemaBuildException::forClass('App\Models\Ghost'),
        ExporterException::writeFailed('/tmp/x.json'),
    ];

    foreach ($exceptions as $e) {
        expect($e)->toBeInstanceOf(ApexDocsException::class);
    }
});
