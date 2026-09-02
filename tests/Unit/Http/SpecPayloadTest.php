<?php

declare(strict_types=1);

use ApexDocs\ApexDocs;
use ApexDocs\Config;
use ApexDocs\Http\SpecPayload;
use ApexDocs\Route\ArrayRouteCollection;

function emptyApexDocs(): ApexDocs
{
    return ApexDocs::make(new Config(title: 'Test', version: '1.0.0'))
        ->routes(new ArrayRouteCollection);
}

it('builds a JSON payload with CORS + no-store and no download name', function () {
    $payload = SpecPayload::json(emptyApexDocs());

    expect($payload->contentType)->toBe('application/json')
        ->and($payload->headers)->toMatchArray([
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ])
        ->and($payload->downloadName)->toBeNull()
        ->and($payload->body)->toContain('"openapi"');
});

it('marks Postman/Insomnia/Bruno as downloads', function () {
    foreach (['postman' => 'postman-collection.json', 'insomnia' => 'insomnia-collection.json', 'bruno' => 'bruno-collection.json'] as $kind => $expected) {
        $p = SpecPayload::{$kind}(emptyApexDocs());
        expect($p->downloadName)->toBe($expected)
            ->and($p->contentType)->toBe('application/json');
    }
});

it('does not attach a download name to YAML', function () {
    $payload = SpecPayload::yaml(emptyApexDocs());

    expect($payload->contentType)->toBe('application/yaml')
        ->and($payload->downloadName)->toBeNull();
});

it('never lets the documentation page be cached', function () {
    // The page inlines the entire stylesheet and script, so a cached copy pins
    // the reader to an old UI with no symptom and nothing to invalidate — while
    // the spec it fetches is already no-store.
    $payload = SpecPayload::html('<!doctype html><title>x</title>');

    expect($payload->headers)->toMatchArray(['Cache-Control' => 'no-store'])
        ->and($payload->contentType)->toBe('text/html; charset=UTF-8')
        ->and($payload->downloadName)->toBeNull();
});
