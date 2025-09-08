<?php

declare(strict_types=1);

use ApexDocs\Spec\Document;
use ApexDocs\Spec\Operation;

test('document starts with openapi 3.1.0', function () {
    $doc = new Document;
    expect($doc->toArray()['openapi'])->toBe('3.1.0');
});

test('can add an operation and retrieve it', function () {
    $doc = new Document;
    $op = (new Operation)->summary('List users')->addResponse('200', ['description' => 'OK']);
    $doc->addOperation('/users', 'get', $op);

    expect($doc->toArray()['paths']['/users']['get']['summary'])->toBe('List users');
});

test('can add a schema to components', function () {
    $doc = new Document;
    $doc->components()->addSchema('User', ['type' => 'object']);

    expect($doc->toArray()['components']['schemas']['User']['type'])->toBe('object');
});

test('can add a webhook', function () {
    $doc = new Document;
    $doc->addWebhook('payment.done', ['post' => ['summary' => 'Payment done']]);

    expect($doc->toArray()['webhooks']['payment.done'])->toHaveKey('post');
});

test('empty sections are omitted from toArray', function () {
    $doc = new Document;
    $array = $doc->toArray();

    expect($array)->not->toHaveKey('paths');
    expect($array)->not->toHaveKey('webhooks');
    expect($array)->not->toHaveKey('security');
});

test('toJson produces valid json', function () {
    $doc = (new Document)->info('Test API', '1.0.0');
    $json = $doc->toJson();

    expect(json_decode($json, true)['info']['title'])->toBe('Test API');
});
