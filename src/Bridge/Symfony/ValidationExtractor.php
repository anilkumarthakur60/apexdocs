<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Symfony;

use ApexDocs\Contract\ValidationExtractorInterface;
use ApexDocs\Extractor\SchemaBuilder;
use ApexDocs\Route\Route;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Symfony bridge: extracts the request body schema from controller parameters
 * carrying #[MapRequestPayload] (Symfony 6.2+).
 *
 * #[MapQueryString] describes query parameters rather than a body, which this
 * contract cannot express  document those with #[QueryParam] for now.
 */
final class ValidationExtractor implements ValidationExtractorInterface
{
    private const MAP_REQUEST_PAYLOAD = 'Symfony\\Component\\HttpKernel\\Attribute\\MapRequestPayload';

    public function __construct(private SchemaBuilder $schemaBuilder) {}

    public function extract(ReflectionMethod $method, Route $route): ?array
    {
        foreach ($method->getParameters() as $param) {
            foreach ($param->getAttributes() as $attr) {
                if ($attr->getName() === self::MAP_REQUEST_PAYLOAD) {
                    return $this->fromTypedParam($param, $attr);
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function fromTypedParam(\ReflectionParameter $param, \ReflectionAttribute $attr): ?array
    {
        $type = $param->getType();
        if (! ($type instanceof ReflectionNamedType) || $type->isBuiltin()) {
            return null;
        }

        $class = $type->getName();
        if (! class_exists($class)) {
            return null;
        }

        $schema = $this->schemaBuilder->fromClass($class);
        $content = [];

        // #[MapRequestPayload(acceptFormat: 'json')] pins the media type.
        $format = $this->acceptFormat($attr);
        foreach ($format !== null ? [$format] : ['json', 'form'] as $accepted) {
            $mediaType = match ($accepted) {
                'json' => 'application/json',
                'form' => 'application/x-www-form-urlencoded',
                'xml' => 'application/xml',
                default => 'application/json',
            };
            $content[$mediaType] = ['schema' => $schema];
        }

        return [
            'required' => ! $type->allowsNull() && ! $param->isDefaultValueAvailable(),
            'content' => $content,
        ];
    }

    private function acceptFormat(\ReflectionAttribute $attr): ?string
    {
        $args = $attr->getArguments();
        $format = $args['acceptFormat'] ?? null;

        return is_string($format) && $format !== '' ? strtolower($format) : null;
    }
}
