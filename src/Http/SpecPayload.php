<?php

declare(strict_types=1);

namespace ApexDocs\Http;

use ApexDocs\ApexDocs;
use ApexDocs\Export\BrunoExporter;
use ApexDocs\Export\InsomniaExporter;
use ApexDocs\Export\JsonExporter;
use ApexDocs\Export\PostmanExporter;
use ApexDocs\Export\YamlExporter;
use ApexDocs\Spec\Document;

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

    public static function json(ApexDocs|Document $spec): self
    {
        return new self(
            body: (new JsonExporter)->toString(self::document($spec)),
            contentType: 'application/json',
            headers: ['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'no-store'],
            downloadName: null,
        );
    }

    public static function yaml(ApexDocs|Document $spec): self
    {
        return new self(
            body: (new YamlExporter)->toString(self::document($spec)),
            contentType: 'application/yaml',
            headers: ['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'no-store'],
            downloadName: null,
        );
    }

    public static function postman(ApexDocs|Document $spec): self
    {
        return new self(
            body: (new PostmanExporter)->toString(self::document($spec)),
            contentType: 'application/json',
            headers: [],
            downloadName: 'postman-collection.json',
        );
    }

    public static function insomnia(ApexDocs|Document $spec): self
    {
        return new self(
            body: (new InsomniaExporter)->toString(self::document($spec)),
            contentType: 'application/json',
            headers: [],
            downloadName: 'insomnia-collection.json',
        );
    }

    public static function bruno(ApexDocs|Document $spec): self
    {
        return new self(
            body: (new BrunoExporter)->toString(self::document($spec)),
            contentType: 'application/json',
            headers: [],
            downloadName: 'bruno-collection.json',
        );
    }

    /**
     * Accepting a built {@see Document} as well as the generator lets callers
     * serve a cached spec — and reuse one build across several formats — while
     * every adapter keeps calling the same named constructors.
     */
    private static function document(ApexDocs|Document $spec): Document
    {
        return $spec instanceof Document ? $spec : $spec->generate();
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
