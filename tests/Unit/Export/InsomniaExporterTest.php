<?php

declare(strict_types=1);

use ApexDocs\Export\InsomniaExporter;
use ApexDocs\Spec\Document;
use ApexDocs\Spec\Operation;

function insomniaExporterDoc(): Document
{
    $doc = (new Document)
        ->info('Insomnia API', '1.0.0', 'demo')
        ->addServer('https://api.example.com');

    $op = (new Operation)
        ->summary('Create book')
        ->tags(['Books'])
        ->addResponse('201', ['description' => 'Created']);

    $doc->addOperation('/api/books', 'post', $op);

    return $doc;
}

it('exports an Insomnia v4 workspace + environment + folder + request', function () {
    $out = (new InsomniaExporter)->toArray(insomniaExporterDoc());

    expect($out['_type'])->toBe('export')
        ->and($out['__export_format'])->toBe(4)
        ->and($out['resources'])->toBeArray();

    $types = array_column($out['resources'], '_type');
    expect($types)->toContain('workspace')
        ->and($types)->toContain('environment')
        ->and($types)->toContain('request_group')
        ->and($types)->toContain('request');
});

it('binds requests under their tag folder', function () {
    $out = (new InsomniaExporter)->toArray(insomniaExporterDoc());

    $folder = collect($out['resources'])->firstWhere('_type', 'request_group');
    $request = collect($out['resources'])->firstWhere('_type', 'request');

    expect($folder['name'])->toBe('Books')
        ->and($request['parentId'])->toBe($folder['_id'])
        ->and($request['method'])->toBe('POST');
});

it('round-trips through toString() as valid JSON', function () {
    $json = (new InsomniaExporter)->toString(insomniaExporterDoc());

    expect(fn () => json_decode($json, true, 512, JSON_THROW_ON_ERROR))->not->toThrow(\JsonException::class);
});
