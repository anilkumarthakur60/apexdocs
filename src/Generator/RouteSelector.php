<?php

declare(strict_types=1);

namespace ApexDocs\Generator;

use ApexDocs\Attribute\ApiGroup;
use ApexDocs\Config;
use ApexDocs\Extractor\AttributeReader;
use ApexDocs\Route\Route;
use Closure;
use ReflectionClass;
use ReflectionException;

/**
 * Decides which routes a document covers, and can say why one was left out.
 *
 * Order of the gates (each one may exclude): configured path prefixes →
 * exclude_paths (glob or anchored regex) → spec_group (#[ApiGroup]) → the
 * user's filterRoutes() closure. {@see SpecBuilder} uses {@see select()};
 * tooling (the MCP server) uses {@see exclusionReason()} to explain a
 * missing endpoint instead of leaving the author guessing.
 */
final class RouteSelector
{
    public const REASON_PREFIX = 'api_path_prefix';

    public const REASON_EXCLUDED = 'exclude_paths';

    public const REASON_GROUP = 'spec_group';

    public const REASON_FILTER = 'filterRoutes';

    public const REASON_NO_PREFIX_USABLE = 'api_path_prefix (no usable prefix configured)';

    /** @var list<string> */
    private array $prefixes = [];

    private bool $matchAll;

    private bool $unusable = false;

    /** @var array<string, bool> */
    private array $compiles = [];

    public function __construct(
        private Config $config,
        private ?Closure $routeFilter = null,
    ) {
        // An empty list — or an empty prefix inside it — means "document every
        // route".
        $this->matchAll = $config->pathPrefixes === [];
        foreach ($config->pathPrefixes as $prefix) {
            if (! is_string($prefix)) {
                // A stray null (an unset env var in the list) must be dropped,
                // never promoted to the match-everything sentinel — that would
                // silently publish every route in the application.
                continue;
            }
            $prefix = trim($prefix, '/');
            if ($prefix === '') {
                $this->matchAll = true;

                continue;
            }
            $this->prefixes[] = $prefix;
        }

        // Every configured prefix was unusable: document nothing rather than
        // guess, and let apexdocs:validate report the empty spec.
        $this->unusable = ! $this->matchAll && $this->prefixes === [];
    }

    /**
     * @param  list<Route>  $routes
     * @return list<Route>
     */
    public function select(array $routes): array
    {
        return array_values(array_filter($routes, fn (Route $r): bool => $this->exclusionReason($r) === null));
    }

    /**
     * Why the route is not documented, or null when it is selected.
     */
    public function exclusionReason(Route $route): ?string
    {
        if ($this->unusable) {
            return self::REASON_NO_PREFIX_USABLE;
        }

        if (! $this->matchAll && ! $this->matchesPrefix($route)) {
            return self::REASON_PREFIX;
        }

        if ($this->config->excludePaths !== [] && $this->isExcluded($route)) {
            return self::REASON_EXCLUDED;
        }

        if ($this->config->specGroup !== '' && ! $this->matchesGroup($route, $this->config->specGroup)) {
            return self::REASON_GROUP;
        }

        if ($this->routeFilter !== null && ! (bool) ($this->routeFilter)($route)) {
            return self::REASON_FILTER;
        }

        return null;
    }

    private function matchesPrefix(Route $route): bool
    {
        $uri = ltrim($route->path, '/');
        foreach ($this->prefixes as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function isExcluded(Route $route): bool
    {
        // Matched with and without the leading slash so both documented styles work.
        $candidates = [$route->path, ltrim($route->path, '/')];

        foreach ($this->config->excludePaths as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }
            foreach ($candidates as $candidate) {
                if (fnmatch($pattern, $candidate)) {
                    return true;
                }
                if ($this->matchesRegex($pattern, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Exclude patterns may be globs or regexes. The regex is ANCHORED: an
     * unanchored `api` would match every path under /api and silently empty the
     * whole document, and `api/internal` would also drop `/api/internally`.
     *
     * A pattern that is not a valid regex is simply not treated as one — testing
     * it first keeps PCRE from emitting a warning that a strict error handler
     * would turn into an exception mid-build.
     */
    private function matchesRegex(string $pattern, string $subject): bool
    {
        if (! array_key_exists($pattern, $this->compiles)) {
            set_error_handler(static fn (): bool => true);
            $this->compiles[$pattern] = preg_match('#^'.$pattern.'$#', '') !== false;
            restore_error_handler();
        }

        return $this->compiles[$pattern] && preg_match('#^'.$pattern.'$#', $subject) === 1;
    }

    private function matchesGroup(Route $route, string $group): bool
    {
        [$class, $method] = $route->resolveHandler();

        try {
            $refClass = new ReflectionClass($class);
            $refMethod = $refClass->getMethod($method);
        } catch (ReflectionException) {
            return false;
        }

        $allGroups = array_merge(
            AttributeReader::all($refClass, ApiGroup::class),
            AttributeReader::all($refMethod, ApiGroup::class),
        );

        // No #[ApiGroup] on the route → always include
        if ($allGroups === []) {
            return true;
        }

        foreach ($allGroups as $g) {
            if ($g->name === $group) {
                return true;
            }
        }

        return false;
    }
}
