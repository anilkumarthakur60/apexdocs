<?php

declare(strict_types=1);

use ApexDocs\Export\PostmanExporter;
use ApexDocs\Spec\Document;
use ApexDocs\Spec\Operation;

function makeDoc(): Document
{
    $doc = (new Document)
        ->info('Test API', '1.0.0')
        ->addServer('http://localhost');

    $op = (new Operation)
        ->id('users_index')
        ->summary('List users')
        ->tags(['Users'])
        ->addResponse('200', ['description' => 'OK']);

    $doc->addOperation('/api/users', 'get', $op);

    return $doc;
}

test('has correct postman schema URL', function () {
    $e = new PostmanExporter;
    $c = $e->toArray(makeDoc());
    expect($c['info']['schema'])
        ->toBe('https://schema.getpostman.com/json/collection/v2.1.0/collection.json');
});

test('groups items under tag folder', function () {
    $e = new PostmanExporter;
    $c = $e->toArray(makeDoc());
    $folders = array_column($c['item'], 'name');
    expect($folders)->toContain('Users');
});

test('outputs valid JSON', function () {
    $e = new PostmanExporter;
    $json = $e->toString(makeDoc());
    expect(json_decode($json, true))->toBeArray();
});

test('base URL variable is set', function () {
    $e = new PostmanExporter;
    $c = $e->toArray(makeDoc());
    $keys = array_column($c['variable'], 'key');
    expect($keys)->toContain('baseUrl');
});
