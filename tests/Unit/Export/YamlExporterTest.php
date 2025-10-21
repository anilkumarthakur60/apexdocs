<?php

declare(strict_types=1);

use ApexDocs\Export\YamlExporter;
use ApexDocs\Spec\Document;
use ApexDocs\Spec\Operation;
use Symfony\Component\Yaml\Yaml;

function yamlExporterDoc(): Document
{
    $doc = (new Document)
        ->info('YAML API', '2.1.0', 'demo')
        ->addServer('https://api.example.com', 'prod');

    $op = (new Operation)
        ->summary('List')
        ->tags(['Books'])
        ->addResponse('200', ['description' => 'OK']);

    $doc->addOperation('/api/books', 'get', $op);
    $doc->components()->addSchema('Book', ['type' => 'object']);

    return $doc;
}

it('emits parseable YAML with the same structure as toArray()', function () {
    $yaml = (new YamlExporter)->toString(yamlExporterDoc());
    $parsed = Yaml::parse($yaml);

    expect($parsed)->toBe(yamlExporterDoc()->toArray());
});

it('uses 2-space indent and inline scalar threshold', function () {
    $yaml = (new YamlExporter)->toString(yamlExporterDoc());

    expect($yaml)->toMatch('/^openapi: 3\.1\.0$/m')
        ->and($yaml)->toContain('  title:'); // 2-space indent
});

it('writes to a file, creating the directory', function () {
    $dir = sys_get_temp_dir().'/apexdocs_yaml_'.uniqid();
    $path = $dir.'/deeper/spec.yaml';

    try {
        (new YamlExporter)->toFile(yamlExporterDoc(), $path);
        expect(file_exists($path))->toBeTrue()
            ->and(Yaml::parse((string) file_get_contents($path)))->toBeArray();
    } finally {
        @unlink($path);
        @rmdir(dirname($path));
        @rmdir($dir);
    }
});
