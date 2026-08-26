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
     * The verbs a Path Item Object can hold. Anything else (PROPFIND, PURGE, …)
     * has no place to go in an OpenAPI document.
     */
    private const DOCUMENTABLE_METHODS = ['GET', 'PUT', 'POST', 'DELETE', 'PATCH', 'TRACE'];

    /** @var list<string> HTTP methods, uppercase */
    public readonly array $methods;

    /** URI pattern, always rooted: /api/users/{id} */
    public readonly string $path;

    /** FQCN@method, or FQCN for __invoke handlers */
    public readonly string $handler;

    /**
     * @param  list<string>  $methods  HTTP methods: ['GET'], ['post','head'] — normalised to uppercase
     * @param  string  $path  URI pattern: /api/users/{id}
     * @param  string|array{0: string, 1?: string}  $handler  "FQCN@method", "FQCN", or [FQCN::class, 'method']
     * @param  list<string>  $middleware  Raw middleware names/signatures
     * @param  array<string, mixed>  $metadata  Framework-specific extras (route name, param constraints, …)
     */
    public function __construct(
        array $methods,
        string $path,
        string|array $handler,
        public readonly array $middleware = [],
        public readonly array $metadata = [],
    ) {
        $this->methods = array_values(array_map(
            static fn (string $m): string => strtoupper($m),
            array_filter($methods, 'is_string'),
        ));

        // A Path Item key must be rooted. Bridges already pass "/…", but a
        // hand-built route may not — and an unrooted or numeric path also turns
        // the paths map into a JSON array once PHP coerces the array key.
        $this->path = '/'.ltrim($path, '/');

        $this->handler = is_array($handler)
            ? implode('@', array_slice(array_map('strval', array_values($handler)), 0, 2))
            : $handler;
    }

    /**
     * The single method this route is documented under. Prefers a real verb
     * over HEAD/OPTIONS, which routers add implicitly.
     */
    public function method(): string
    {
        return strtolower($this->documentedMethods()[0] ?? $this->methods[0] ?? 'get');
    }

    /**
     * Every method that deserves its own operation in the spec. A router may
     * register one route for several verbs (Laravel's `match`/`any`, a Symfony
     * route with no method requirement) — each becomes its own operation.
     *
     * HEAD and OPTIONS are dropped because routers add them implicitly, and
     * anything a Path Item Object cannot hold (PROPFIND, PURGE, …) is dropped
     * because there is nowhere valid to put it.
     *
     * @return list<string> uppercase
     */
    public function documentedMethods(): array
    {
        return array_values(array_filter(
            $this->methods,
            static fn (string $m): bool => in_array($m, self::DOCUMENTABLE_METHODS, true),
        ));
    }

    /** Normalise {param?} optional segments to {param} */
    public function normalizedPath(): string
    {
        return preg_replace('/\{([^{}\/?]+)\?}/u', '{$1}', $this->path) ?? $this->path;
    }

    /**
     * Extract path parameter names from the URI pattern.
     *
     * Deliberately wider than \w: a template variable the spec sees but no
     * parameter describes makes the document invalid, so anything between the
     * braces counts — unicode names, dots, dashes.
     *
     * @return list<string>
     */
    public function pathParamNames(): array
    {
        preg_match_all('/\{([^{}\/]+?)\??}/u', $this->path, $m);

        return array_values(array_unique($m[1]));
    }

    public function hasMiddleware(string $name): bool
    {
        foreach ($this->middleware as $mw) {
            if (! is_string($mw)) {
                continue;
            }
            // Exact, or a parameterised form of the same middleware ("auth:api",
            // "throttle:60,1") — never a different middleware that merely shares
            // a prefix ("authorize" must not match "auth").
            if ($mw === $name || str_starts_with($mw, $name.':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split "Class@method" into [class, method].
     * Returns [class, '__invoke'] for single-class handlers.
     *
     * @return array{0: string, 1: string}
     */
    public function resolveHandler(): array
    {
        if (str_contains($this->handler, '@')) {
            /** @var array{0: string, 1: string} $parts */
            $parts = explode('@', $this->handler, 2);

            return $parts;
        }

        return [$this->handler, '__invoke'];
    }

    public function name(): string
    {
        $name = $this->metadata['name'] ?? '';

        return is_string($name) ? $name : '';
    }

    /**
     * Framework-supplied constraints for path parameters, keyed by parameter
     * name (Laravel's `wheres`, Symfony's requirements).
     *
     * @return array<string, string>
     */
    public function paramConstraints(): array
    {
        $wheres = $this->metadata['wheres'] ?? [];
        if (! is_array($wheres)) {
            return [];
        }

        $out = [];
        foreach ($wheres as $param => $pattern) {
            if (is_string($param) && is_string($pattern)) {
                $out[$param] = $pattern;
            }
        }

        return $out;
    }
}
