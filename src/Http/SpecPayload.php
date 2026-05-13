<?php

declare(strict_types=1);

namespace ApexDocs\Http;

use ApexDocs\ApexDocs;
use ApexDocs\Export\BrunoExporter;
use ApexDocs\Export\InsomniaExporter;
use ApexDocs\Export\JsonExporter;
use ApexDocs\Export\PostmanExporter;
use ApexDocs\Export\YamlExporter;

/**
 * Single source of truth for the bytes + headers we serve out of any HTTP
 * adapter (PSR-15 {@see Handler}, Laravel {@see \ApexDocs\Bridge\Laravel\DocsController},
 * future Symfony controller, etc.). Adapters just translate this value
 * object into their framework's native response — they no longer duplicate
 * exporter wiring or content-type tables.
 *
 * Immutable, intentionally — readonly + named constructors so callers can
 * pattern-match by kind and never need to mutate.
 */
final readonly class SpecPayload
{
    /**
     * @param  array<string, string>  $headers  extra headers beyond Content-Type
     */
    private function __construct(
        public string $body,
        public string $contentType,
        public array $headers,
        public ?string $downloadName,
    ) {}

    public static function json(ApexDocs $apex): self
    {
        return new self(
            body: (new JsonExporter)->toString($apex->generate()),
            contentType: 'application/json',
            headers: ['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'no-store'],
            downloadName: null,
        );
    }

    public static function yaml(ApexDocs $apex): self
    {
        return new self(
            body: (new YamlExporter)->toString($apex->generate()),
            contentType: 'application/yaml',
            headers: ['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'no-store'],
            downloadName: null,
        );
    }

    public static function postman(ApexDocs $apex): self
    {
        return new self(
            body: (new PostmanExporter)->toString($apex->generate()),
            contentType: 'application/json',
            headers: [],
            downloadName: 'postman-collection.json',
        );
    }

    public static function insomnia(ApexDocs $apex): self
    {
        return new self(
            body: (new InsomniaExporter)->toString($apex->generate()),
            contentType: 'application/json',
            headers: [],
            downloadName: 'insomnia-collection.json',
        );
    }

    public static function bruno(ApexDocs $apex): self
    {
        return new self(
            body: (new BrunoExporter)->toString($apex->generate()),
            contentType: 'application/json',
            headers: [],
            downloadName: 'bruno-collection.json',
        );
    }

    public static function html(string $html): self
    {
        return new self(
            body: $html,
            contentType: 'text/html; charset=UTF-8',
            headers: [],
            downloadName: null,
        );
    }
}
