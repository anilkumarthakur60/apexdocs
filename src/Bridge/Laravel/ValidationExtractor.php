<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel;

use ApexDocs\Contract\ValidationExtractorInterface;
use ApexDocs\Route\Route;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Laravel bridge: extracts request body schema from FormRequest::rules().
 */
final class ValidationExtractor implements ValidationExtractorInterface
{
    public function __construct(private RuleParser $parser) {}

    public function extract(ReflectionMethod $handler, Route $route): ?array
    {
        $formRequestClass = $this->findFormRequest($handler);
        if ($formRequestClass === null) {
            return null;
        }

        try {
            $ref = new \ReflectionClass($formRequestClass);
            $instance = $ref->newInstanceWithoutConstructor();
            if (method_exists($instance, 'setContainer')) {
                $instance->setContainer(app());
            }
            $rules = method_exists($instance, 'rules') ? $instance->rules() : [];
        } catch (\Throwable) {
            return null;
        }

        $schema = $this->parser->toSchema($rules);
        $required = $this->parser->required($rules);

        if ($required) {
            $schema['required'] = $required;
        }

        $body = [
            'required' => true,
            'content' => ['application/json' => ['schema' => $schema]],
        ];

        if ($this->hasFileRule($rules)) {
            $body['content']['multipart/form-data'] = ['schema' => $schema];
        }

        return $body;
    }

    private function findFormRequest(ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if (! ($type instanceof ReflectionNamedType) || ! class_exists($type->getName())) {
                continue;
            }
            try {
                $ref = new \ReflectionClass($type->getName());
                if ($ref->isSubclassOf(FormRequest::class)) {
                    return $type->getName();
                }
            } catch (\ReflectionException) {
                continue;
            }
        }

        return null;
    }

    private function hasFileRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            $str = is_array($rule) ? implode('|', $rule) : (string) $rule;
            if (preg_match('/\b(file|image|mimes|mimetypes)\b/i', $str)) {
                return true;
            }
        }

        return false;
    }
}
