<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel;

use ApexDocs\Contract\ValidationExtractorInterface;
use ApexDocs\Route\Route;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Laravel bridge: extracts a request-body schema from FormRequest::rules().
 *
 * Documentation-time, not request-time. Anything we instantiate is purely so
 * we can call rules()  we do NOT want side effects from real request
 * handling. So:
 *
 *  - We bypass the constructor (`newInstanceWithoutConstructor`)  avoids
 *    HTTP context, route resolution, and DB access that the FormRequest base
 *    class normally wires up.
 *  - We wire a container only if the method exists; we never call validate()
 *    or authorize().
 *  - We suppress notices/warnings around the rules() call: app code often
 *    references `$this->user()`, `$this->route('id')`, etc.  which raise
 *    deprecations against a constructorless instance. They're noise here,
 *    not actionable.
 *  - Anything that throws  including \Error  is treated as "rules
 *    unavailable" and the route is documented without a body schema.
 *  - Schemas are cached per-class for the lifetime of the process so big
 *    controllers don't re-instantiate the same FormRequest dozens of times.
 */
final class ValidationExtractor implements ValidationExtractorInterface
{
    /** @var array<class-string, array<string, mixed>|null> */
    private array $cache = [];

    public function __construct(private RuleParser $parser) {}

    public function extract(ReflectionMethod $handler, Route $route): ?array
    {
        $formRequestClass = $this->findFormRequest($handler);
        if ($formRequestClass === null) {
            return null;
        }

        if (array_key_exists($formRequestClass, $this->cache)) {
            return $this->cache[$formRequestClass];
        }

        return $this->cache[$formRequestClass] = $this->buildBody($formRequestClass);
    }

    /**
     * @param  class-string  $formRequestClass
     * @return array<string, mixed>|null
     */
    private function buildBody(string $formRequestClass): ?array
    {
        $rules = $this->safeReadRules($formRequestClass);
        if ($rules === null || $rules === []) {
            return null;
        }

        try {
            $schema = $this->parser->toSchema($rules);
        } catch (\Throwable) {
            // A rule shape we cannot read is not worth failing the build over.
            return null;
        }

        // toSchema() already marks nested objects required; union in the
        // top-level names so both views agree.
        $required = array_values(array_unique(array_merge(
            is_array($schema['required'] ?? null) ? $schema['required'] : [],
            $this->parser->required($rules),
        )));
        if ($required !== []) {
            $schema['required'] = $required;
        }

        $body = [
            'required' => $required !== [],
            'content' => ['application/json' => ['schema' => $schema]],
        ];

        if ($this->hasFileRule($rules)) {
            $body['content']['multipart/form-data'] = ['schema' => $schema];
        }

        return $body;
    }

    /**
     * Invoke FormRequest::rules() with maximum tolerance. Returns the rules
     * array on success, null on any failure (we do not let documentation
     * generation be killed by a misbehaving FormRequest).
     *
     * @param  class-string  $class
     * @return array<string, mixed>|null
     */
    private function safeReadRules(string $class): ?array
    {
        try {
            $ref = new \ReflectionClass($class);
        } catch (\ReflectionException) {
            return null;
        }

        if (! $ref->hasMethod('rules')) {
            return null;
        }

        $rulesMethod = $ref->getMethod('rules');
        if ($rulesMethod->getNumberOfRequiredParameters() > 0) {
            // `rules(Foo $x)`  we can't fabricate $x safely. Skip.
            return null;
        }

        $previousLevel = error_reporting(0);
        try {
            $instance = $ref->newInstanceWithoutConstructor();
            if (method_exists($instance, 'setContainer') && function_exists('app')) {
                try {
                    $instance->setContainer(app());
                } catch (\Throwable) {
                    // ignore  container wiring is best-effort
                }
            }
            $rules = $rulesMethod->invoke($instance);
        } catch (\Throwable) {
            return null;
        } finally {
            error_reporting($previousLevel);
        }

        return is_array($rules) ? $rules : null;
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

    /**
     * @param  array<string, mixed>  $rules
     */
    private function hasFileRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            // Only string rules can be inspected  casting a closure or a
            // ValidationRule object to string throws.
            foreach (is_array($rule) ? $rule : [$rule] as $part) {
                if (! is_string($part) && ! $part instanceof \Stringable) {
                    continue;
                }
                if (preg_match('/\b(file|image|mimes|mimetypes)\b/i', (string) $part) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
