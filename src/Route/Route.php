<?php

declare(strict_types=1);

namespace ApexDocs\Route;

/**
 * Framework-agnostic route value object.
 * Framework bridges convert their native route objects into this.
 */
final class Route
{
    /**
     * @param  list<string>  $methods  HTTP methods (uppercase): ['GET'], ['POST','HEAD']
     * @param  string  $path  URI pattern: /api/users/{id}
     * @param  string  $handler  FQCN@method or FQCN for __invoke
     * @param  list<string>  $middleware  Raw middleware names/signatures
     * @param  array<string, mixed>  $metadata  Framework-specific extras (e.g. route name)
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $path,
        public readonly string $handler,
        public readonly array $middleware = [],
        public readonly array $metadata = [],
    ) {}

    public function method(): string
    {
        foreach ($this->methods as $m) {
            if (! in_array($m, ['HEAD', 'OPTIONS'], true)) {
                return strtolower($m);
            }
        }

        return strtolower($this->methods[0]);
    }

    /** Normalise {param?} optional segments to {param} */
    public function normalizedPath(): string
    {
        return preg_replace('/\{(\w+)\?}/', '{$1}', $this->path);
    }

    /** Extract path parameter names from the URI pattern. */
    public function pathParamNames(): array
    {
        preg_match_all('/\{(\w+)\??}/', $this->path, $m);

        return $m[1];
    }

    public function hasMiddleware(string $name): bool
    {
        foreach ($this->middleware as $mw) {
            if (str_starts_with($mw, $name)) {
                return true;
            }
        }

        return false;
    }

    /** Split "Class@method" into [class, method]. Returns [class, '__invoke'] for single-class handlers. */
    public function resolveHandler(): array
    {
        if (str_contains($this->handler, '@')) {
            return explode('@', $this->handler, 2);
        }

        return [$this->handler, '__invoke'];
    }

    public function name(): string
    {
        return $this->metadata['name'] ?? '';
    }
}
