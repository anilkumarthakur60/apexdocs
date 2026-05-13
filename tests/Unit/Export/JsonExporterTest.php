<?php

declare(strict_types=1);

use ApexDocs\Export\JsonExporter;
use ApexDocs\Spec\Document;

function jsonExporterDoc(): Document
{
    return (new Document)
        ->info('JSON', '1.0.0')
        ->addServer('http://localhost')
        ->addTag('A');
}

it('emits pretty-printed JSON with the openapi field at root', function () {
    $out = (new JsonExporter)->toString(jsonExporterDoc());
    $decoded = json_decode($out, true);

    expect($decoded)->toBeArray()
        ->and($decoded['openapi'])->toBe('3.1.0')
        ->and($out)->toContain("\n"); // pretty
});

it('emits compact JSON when pretty=false', function () {
    $out = (new JsonExporter)->toString(jsonExporterDoc(), pretty: false);

    expect($out)->not->toContain("\n");
});

it('uses JSON_THROW_ON_ERROR to surface encoding failures', function () {
    $out = (new JsonExporter)->toString(jsonExporterDoc());
    // Valid JSON round-trips
    expect(fn () => json_decode($out, true, 512, JSON_THROW_ON_ERROR))->not->toThrow(\JsonException::class);
});

it('writes to a file, creating the directory', function () {
    $dir = sys_get_temp_dir().'/apexdocs_json_'.uniqid();
    $path = $dir.'/nested/spec.json';

    try {
        (new JsonExporter)->toFile(jsonExporterDoc(), $path);
        expect(file_exists($path))->toBeTrue()
            ->and(json_decode((string) file_get_contents($path), true))->toBeArray();
    } finally {
        @unlink($path);
        @rmdir(dirname($path));
        @rmdir($dir);
    }
});
