<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

use ReflectionClass;

/**
 * Resolves the short class names written inside a PHP file — `new UserResource(…)`,
 * `PostResource::collection(…)`, `@property Carbon $created_at` — to fully
 * qualified names.
 *
 * Reflection exposes a file's classes but never its import table, and the body
 * of an API resource is written almost entirely in unqualified names, so the
 * `use` statements are recovered by tokenising the file once.
 *
 * Pure PHP — no framework dependency.
 */
final class NameResolver
{
    /** Spellings that can never be a class name, so are never rewritten. */
    private const NON_CLASS_TYPES = [
        'null', 'bool', 'boolean', 'true', 'false', 'int', 'integer', 'float',
        'double', 'number', 'string', 'class-string', 'non-empty-string',
        'numeric-string', 'array-key', 'positive-int', 'negative-int',
        'non-negative-int', 'array', 'iterable', 'list', 'object', 'mixed',
        'void', 'never', 'callable', 'resource', 'scalar', 'numeric',
    ];

    /** @var array<string, array<string, string>> keyed "<file>:<mtime>" so an edited file is re-read */
    private static array $cache = [];

    /**
     * @param  array<string, string>  $imports  lowercased alias => FQCN
     */
    private function __construct(
        private readonly string $namespace,
        private readonly array $imports,
        private readonly string $self,
    ) {}

    public static function forClass(ReflectionClass $class): self
    {
        $file = $class->getFileName();

        if ($file === false || ! is_file($file)) {
            // eval()'d, internal, or a class whose file has since moved: its
            // own namespace is still a useful resolution root.
            return new self($class->getNamespaceName(), [], $class->getName());
        }

        $key = $file.':'.(string) @filemtime($file);
        $imports = self::$cache[$key] ??= self::parse($file);

        return new self($class->getNamespaceName(), $imports, $class->getName());
    }

    /** Resolve one name as written in the file. Returned unchanged when nothing matches. */
    public function resolve(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            return '';
        }

        if ($name[0] === '\\') {
            return ltrim($name, '\\');
        }

        if (in_array(strtolower($name), ['self', 'static', '$this'], true)) {
            return $this->self;
        }

        $segments = explode('\\', $name);
        $first = strtolower((string) array_shift($segments));

        if (isset($this->imports[$first])) {
            return $segments === []
                ? $this->imports[$first]
                : $this->imports[$first].'\\'.implode('\\', $segments);
        }

        // Unimported: PHP resolves against the current namespace. We also
        // accept the global name, because `DateTimeImmutable` written without
        // a leading slash is what an author means even though PHP would not
        // agree inside a namespaced file.
        $candidate = $this->namespace === '' ? $name : $this->namespace.'\\'.$name;

        if (self::exists($candidate)) {
            return $candidate;
        }

        return self::exists($name) ? $name : $candidate;
    }

    /**
     * Resolve every class-ish identifier inside a type string of the shape
     * {@see TypeInferrer::normalise()} produces — `User`, `?User`, `User|null`,
     * `PostResource[]`, `Tag{}` — leaving primitives untouched.
     */
    public function resolveTypeString(string $type): string
    {
        $type = trim($type);

        if ($type === '') {
            return '';
        }

        foreach (['|', '&'] as $separator) {
            if (str_contains($type, $separator)) {
                return implode($separator, array_map(
                    fn (string $part): string => $this->resolveTypeString($part),
                    array_map('trim', explode($separator, $type)),
                ));
            }
        }

        foreach (['[]', '{}'] as $suffix) {
            if (str_ends_with($type, $suffix)) {
                return $this->resolveTypeString(substr($type, 0, -2)).$suffix;
            }
        }

        if (str_starts_with($type, '?')) {
            return $this->resolveTypeString(substr($type, 1)).'|null';
        }

        if (in_array(strtolower($type), self::NON_CLASS_TYPES, true)) {
            return strtolower($type);
        }

        return $this->resolve($type);
    }

    private static function exists(string $class): bool
    {
        return class_exists($class) || interface_exists($class) || enum_exists($class);
    }

    /**
     * The file's import table, lowercased alias => FQCN.
     *
     * `use` in a closure signature and `use` of a trait inside a class body are
     * both T_USE. The closure form is skipped by looking ahead; a trait import
     * is harmless — the alias it records maps to the right class anyway.
     *
     * @return array<string, string>
     */
    private static function parse(string $file): array
    {
        $source = @file_get_contents($file);

        if ($source === false) {
            return [];
        }

        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $imports = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            $next = $tokens[$i + 1] ?? null;

            // `function () use ($x)`, `use function foo;`, `use const BAR;`
            if ($next === '(' || (is_array($next) && in_array($next[0], [T_FUNCTION, T_CONST], true))) {
                continue;
            }

            $i = self::readImport($tokens, $i + 1, $imports);
        }

        return $imports;
    }

    /**
     * Consume one `use` statement — plain, aliased, or grouped — and return the
     * index of its last token.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @param  array<string, string>  $imports
     */
    private static function readImport(array $tokens, int $i, array &$imports): int
    {
        $count = count($tokens);
        $prefix = '';
        $buffer = '';
        $alias = '';
        $expectAlias = false;

        for (; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === ';') {
                break;
            }

            if (is_string($token)) {
                // Group import: `use App\Http\Resources\{UserResource, PostResource};`
                if ($token === '{') {
                    $prefix = rtrim($buffer, '\\');
                    $buffer = '';
                } elseif ($token === '}') {
                    self::record($imports, $prefix, $buffer, $alias);

                    return $i;
                } elseif ($token === ',') {
                    self::record($imports, $prefix, $buffer, $alias);
                    $buffer = '';
                    $alias = '';
                    $expectAlias = false;
                }

                continue;
            }

            if ($token[0] === T_AS) {
                $expectAlias = true;

                continue;
            }

            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                if ($expectAlias) {
                    $alias = $token[1];

                    continue;
                }
                $buffer .= $token[1];
            }
        }

        self::record($imports, $prefix, $buffer, $alias);

        return $i;
    }

    /** @param array<string, string> $imports */
    private static function record(array &$imports, string $prefix, string $name, string $alias): void
    {
        $name = trim($name, '\\');

        if ($name === '') {
            return;
        }

        $segments = explode('\\', $name);
        $key = $alias !== '' ? $alias : (string) end($segments);

        $imports[strtolower($key)] = $prefix === '' ? $name : $prefix.'\\'.$name;
    }
}
