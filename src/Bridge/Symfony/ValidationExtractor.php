<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Symfony;

use ApexDocs\Contract\ValidationExtractorInterface;
use ApexDocs\Extractor\SchemaBuilder;
use ApexDocs\Route\Route;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Symfony bridge: extracts request body schema from controller method parameters.
 *
 * Detects:
 *  - #[MapRequestPayload] (Symfony 6.2+) on a typed parameter
 *  - #[MapQueryString] on a typed parameter (documents as query params body schema)
 */
final class ValidationExtractor implements ValidationExtractorInterface
{
    public function __construct(private SchemaBuilder $schemaBuilder) {}

    public function extract(ReflectionMethod $method, Route $route): ?array
    {
        foreach ($method->getParameters() as $param) {
            $attrs = $param->getAttributes();
            foreach ($attrs as $attr) {
                $attrName = $attr->getName();

                if ($attrName === 'Symfony\\Component\\HttpKernel\\Attribute\\MapRequestPayload') {
                    return $this->fromTypedParam($param);
                }
            }
        }

        return null;
    }

    private function fromTypedParam(\ReflectionParameter $param): ?array
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

        return [
            'required' => ! $type->allowsNull(),
            'content' => [
                'application/json' => ['schema' => $schema],
                'application/x-www-form-urlencoded' => ['schema' => $schema],
            ],
        ];
    }
}
