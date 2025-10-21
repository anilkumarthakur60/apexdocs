<?php

declare(strict_types=1);

use ApexDocs\Export\BrunoExporter;
use ApexDocs\Spec\Document;
use ApexDocs\Spec\Operation;

function brunoExporterDoc(): Document
{
    $doc = (new Document)
        ->info('Bruno API', '3.0.0', 'demo')
        ->addServer('https://api.example.com', 'prod');

    $op = (new Operation)
        ->summary('Patch book')
        ->tags(['Books'])
        ->addResponse('204', ['description' => 'No content']);

    $doc->addOperation('/api/books/{id}', 'patch', $op);

    return $doc;
}

it('emits a v1 collection with name, meta, and environments', function () {
    $out = (new BrunoExporter)->toArray(brunoExporterDoc());

    expect($out['version'])->toBe('1')
        ->and($out['name'])->toBe('Bruno API')
        ->and($out['meta']['openapi'])->toBe('3.1.0')
        ->and($out['environments'])->toBeArray()->not->toBeEmpty();
});

it('groups operations under their tag folders', function () {
    $out = (new BrunoExporter)->toArray(brunoExporterDoc());

    $folder = collect($out['items'])->firstWhere('name', 'Books');

    expect($folder)->not->toBeNull()
        ->and($folder['type'])->toBe('folder')
        ->and($folder['items'])->toBeArray()->not->toBeEmpty();
});

it('round-trips through toString() as valid JSON', function () {
    $json = (new BrunoExporter)->toString(brunoExporterDoc());

    expect(fn () => json_decode($json, true, 512, JSON_THROW_ON_ERROR))->not->toThrow(\JsonException::class);
});
