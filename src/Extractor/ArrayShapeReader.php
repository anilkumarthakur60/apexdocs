<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Recovers the JSON shape of a class whose payload is assembled by a method
 * instead of declared as public properties - the API resource case:
 *
 *     final class UserResource extends JsonResource
 *     {
 *         public function toArray($request): array
 *         {
 *             return [
 *                 'id'    => $this->id,
 *                 'name'  => $this->name,
 *                 'posts' => PostResource::collection($this->whenLoaded('posts')),
 *             ];
 *         }
 *     }
 *
 * Reflecting that class finds nothing to document: every key lives inside
 * `toArray()`. So the array literal is read statically - the class is never
 * instantiated and the method never called, which means no model, request,
 * container or database is involved.
 *
 * {@see forClass()} reads the payload method, preferring a
 * `@return array{id: int, name: string}` annotation over the body it describes:
 * the author said it explicitly, and it is the escape hatch for a body too
 * dynamic to read. {@see fromAnnotations()} is the separate, weaker source for
 * a class with no payload method at all - an Eloquent model, whose columns
 * exist only as `@property` tags.
 *
 * A key whose value expression yields no evidence is documented as a key with
 * no type rather than a guessed one, except for the handful of naming
 * conventions in {@see fromKeyName()}.
 *
 * Pure PHP - no framework dependency; Laravel's conditional helpers are
 * recognised by name only.
 *
 * @phpstan-type ShapeNode array{
 *     type?: string,
 *     shape?: array<string, mixed>,
 *     list?: array<string, mixed>,
 *     format?: string,
 *     nullable?: bool,
 *     optional?: bool,
 * }
 */
final class ArrayShapeReader
{
    /** Methods whose returned array *is* the JSON payload, most authoritative first. */
    private const SHAPE_METHODS = ['jsonSerialize', 'toArray'];

    /** How deep nested array literals and property chains are followed. */
    private const MAX_DEPTH = 5;

    /**
     * Laravel's conditional merges: the helper returns a `MissingValue` that
     * removes the key from the payload, so the key is present *sometimes*.
     * The value is the argument index carrying the documented value.
     */
    private const CONDITIONAL = [
        'when' => 1,
        'unless' => 1,
        'whenloaded' => 1,
        'whennull' => 0,
        'whennotnull' => 0,
        'whenappended' => 1,
        'whencounted' => 1,
        'whenaggregated' => 3,
        'whenexistsloaded' => 1,
        'whenhas' => 1,
        'whenpivotloaded' => 1,
        'whenpivotloadedas' => 2,
        'mergewhen' => 1,
        'mergeunless' => 1,
    ];

    /** Static factories on a resource class: `PostResource::collection($posts)`. */
    private const COLLECTION_FACTORIES = ['collection', 'collect'];

    private const INSTANCE_FACTORIES = ['make', 'create', 'from', 'fromModel'];

    /** Method calls whose result type is unambiguous whatever the receiver is. */
    private const METHOD_TYPES = [
        'toiso8601string' => ['type' => 'string', 'format' => 'date-time'],
        'torfc3339string' => ['type' => 'string', 'format' => 'date-time'],
        'toatomstring' => ['type' => 'string', 'format' => 'date-time'],
        'todatetimestring' => ['type' => 'string', 'format' => 'date-time'],
        'todatestring' => ['type' => 'string', 'format' => 'date'],
        'totimestring' => ['type' => 'string', 'format' => 'time'],
        'format' => ['type' => 'string'],
        'tostring' => ['type' => 'string'],
        '__tostring' => ['type' => 'string'],
        'tojson' => ['type' => 'string'],
        'count' => ['type' => 'integer'],
        'timestamp' => ['type' => 'integer'],
        'gettimestamp' => ['type' => 'integer'],
        'sum' => ['type' => 'number'],
        'avg' => ['type' => 'number'],
        'average' => ['type' => 'number'],
        'isempty' => ['type' => 'boolean'],
        'isnotempty' => ['type' => 'boolean'],
        'exists' => ['type' => 'boolean'],
        'contains' => ['type' => 'boolean'],
        'toarray' => ['type' => 'array'],
        'pluck' => ['type' => 'array'],
        'map' => ['type' => 'array'],
        'transform' => ['type' => 'array'],
        'filter' => ['type' => 'array'],
        'flatten' => ['type' => 'array'],
        'unique' => ['type' => 'array'],
        'take' => ['type' => 'array'],
        'reverse' => ['type' => 'array'],
        'values' => ['type' => 'array'],
        'keys' => ['type' => 'array'],
        'all' => ['type' => 'array'],
    ];

    /** Plain function calls with an unambiguous return type. */
    private const FUNCTION_TYPES = [
        'count' => ['type' => 'integer'],
        'intval' => ['type' => 'integer'],
        'strlen' => ['type' => 'integer'],
        'floatval' => ['type' => 'number'],
        'round' => ['type' => 'number'],
        'boolval' => ['type' => 'boolean'],
        'strval' => ['type' => 'string'],
        'sprintf' => ['type' => 'string'],
        'implode' => ['type' => 'string'],
        'join' => ['type' => 'string'],
        'number_format' => ['type' => 'string'],
        'json_encode' => ['type' => 'string'],
        'ucfirst' => ['type' => 'string'],
        'strtolower' => ['type' => 'string'],
        'strtoupper' => ['type' => 'string'],
        'trim' => ['type' => 'string'],
        'date' => ['type' => 'string'],
        '__' => ['type' => 'string'],
        'trans' => ['type' => 'string'],
        'trans_choice' => ['type' => 'string'],
        'explode' => ['type' => 'array'],
        'array_map' => ['type' => 'array'],
        'array_values' => ['type' => 'array'],
        'array_keys' => ['type' => 'array'],
        'array_filter' => ['type' => 'array'],
        'array_merge' => ['type' => 'array'],
    ];

    private ?NameResolver $resolver = null;

    private function __construct(private readonly ReflectionClass $class) {}

    /**
     * The shape the class's payload method builds, or `[]` when there is no
     * readable one.
     *
     * @return array<string, array<string, mixed>> key => ShapeNode
     */
    public static function forClass(ReflectionClass $class): array
    {
        return (new self($class))->fromShapeMethods(0);
    }

    /**
     * The shape implied by `@property` annotations - the fallback for a class
     * with neither a payload method nor a public property, which is how an
     * Eloquent model documents its columns.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fromAnnotations(ReflectionClass $class): array
    {
        return (new self($class))->fromPropertyTags();
    }

    /** Deferred: a class with no payload method never has its file tokenised. */
    private function resolver(): NameResolver
    {
        return $this->resolver ??= NameResolver::forClass($this->class);
    }

    /** @return array<string, array<string, mixed>> */
    private function fromShapeMethods(int $depth): array
    {
        foreach (self::SHAPE_METHODS as $name) {
            $method = $this->shapeMethod($name);
            if ($method === null) {
                continue;
            }

            $shape = $this->fromMethod($method, $depth);
            if ($shape !== []) {
                return $shape;
            }
        }

        return [];
    }

    /**
     * The class's own payload method, if it has one worth reading.
     *
     * Framework base classes are skipped: `JsonResource::toArray()` returns
     * `$this->resource->toArray()`, which describes nothing, and every resource
     * in the application inherits it.
     */
    private function shapeMethod(string $name): ?ReflectionMethod
    {
        if (! $this->class->hasMethod($name)) {
            return null;
        }

        $method = $this->class->getMethod($name);

        return self::isReadable($method) ? $method : null;
    }

    private static function isReadable(ReflectionMethod $method): bool
    {
        if ($method->isAbstract() || $method->getDeclaringClass()->isInternal()) {
            return false;
        }

        $file = $method->getFileName();

        return $file !== false
            && is_file($file)
            && ! str_contains(str_replace('\\', '/', $file), '/vendor/');
    }

    /** @return array<string, array<string, mixed>> */
    private function fromMethod(ReflectionMethod $method, int $depth): array
    {
        $declaring = $method->getDeclaringClass();

        // An inherited payload method was written against its own file's
        // imports, so it is read in the context of the class that declares it.
        $reader = $declaring->getName() === $this->class->getName()
            ? $this
            : new self($declaring);

        $shape = $reader->fromReturnAnnotation($method);

        return $shape !== [] ? $shape : $reader->fromBody($method, $depth);
    }

    // ── Source 1: the @return array{…} annotation ─────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function fromReturnAnnotation(ReflectionMethod $method): array
    {
        $doc = DocBlockReader::parse($method->getDocComment());

        if ($doc === null) {
            return [];
        }

        foreach ($doc->getReturnTagValues() as $tag) {
            if ($tag->type instanceof ArrayShapeNode) {
                return $this->fromArrayShapeNode($tag->type, 0);
            }
        }

        return [];
    }

    /** @return array<string, array<string, mixed>> */
    private function fromArrayShapeNode(ArrayShapeNode $node, int $depth): array
    {
        $shape = [];

        foreach ($node->items as $item) {
            if ($item->keyName === null) {
                // `array{int, string}` is a tuple, not an object - there are no
                // key names to document.
                return [];
            }

            $key = self::annotationKey($item->keyName);
            if ($key === null) {
                continue;
            }

            $value = $item->valueType;
            $child = $value instanceof ArrayShapeNode && $depth < self::MAX_DEPTH
                ? ['shape' => $this->fromArrayShapeNode($value, $depth + 1)]
                : ['type' => $this->resolver()->resolveTypeString(TypeInferrer::normalise((string) $value))];

            if ($item->optional) {
                $child['optional'] = true;
            }

            $shape[$key] = $child;
        }

        return $shape;
    }

    /**
     * The key of one `array{…}` item: `array{id: int}` and `array{'id': int}`
     * are the same key spelled two ways.
     *
     * ConstExprStringNode stringifies with its quotes in phpdoc-parser v1 and
     * without in v2, so the value is trimmed rather than read per-version.
     */
    private static function annotationKey(mixed $keyName): ?string
    {
        $name = trim((string) $keyName, "'\"");

        return $name === '' ? null : $name;
    }

    // ── Source 2: the return [...] literal ───────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function fromBody(ReflectionMethod $method, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        $tokens = TokenScanner::ofMethod($method);
        $branches = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_RETURN) {
                continue;
            }

            $cursor = $i + 1;
            $branch = $this->fromReturnExpression($tokens, $cursor, $depth);

            // Past the expression, so a closure returning an array inside it is
            // not mistaken for a second branch.
            $i = max($i, $cursor);

            if ($branch !== []) {
                $branches[] = $branch;
            }
        }

        return self::mergeBranches($branches);
    }

    /**
     * The shape of one `return` expression: an array literal, or one wrapped in
     * the two array functions that keep its keys.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     * @return array<string, array<string, mixed>>
     */
    private function fromReturnExpression(array $tokens, int &$cursor, int $depth): array
    {
        $elements = TokenScanner::arrayElements($tokens, $cursor);

        if ($elements !== null) {
            return $this->fromElements($elements, $depth);
        }

        if (($tokens[$cursor][0] ?? 0) !== T_STRING || ($tokens[$cursor + 1][1] ?? '') !== '(') {
            return [];
        }

        $function = strtolower($tokens[$cursor][1]);

        if (! in_array($function, ['array_merge', 'array_replace', 'array_filter'], true)) {
            return [];
        }

        // array_filter() drops every falsy value, so nothing it wraps is
        // guaranteed to reach the payload.
        $optional = $function === 'array_filter';
        $shape = [];

        foreach (TokenScanner::arguments(array_slice($tokens, $cursor)) as $argument) {
            $inner = 0;
            $elements = TokenScanner::arrayElements($argument, $inner);

            if ($elements === null) {
                continue;
            }

            foreach ($this->fromElements($elements, $depth) as $key => $node) {
                if ($optional) {
                    $node['optional'] = true;
                }
                $shape[$key] ??= $node;
            }
        }

        return $shape;
    }

    /**
     * A method with several `return [...]` statements documents the union of
     * their keys; a key missing from any branch is optional.
     *
     * @param  list<array<string, array<string, mixed>>>  $branches
     * @return array<string, array<string, mixed>>
     */
    private static function mergeBranches(array $branches): array
    {
        if (count($branches) <= 1) {
            return $branches[0] ?? [];
        }

        $merged = [];
        foreach ($branches as $branch) {
            foreach ($branch as $key => $node) {
                $merged[$key] ??= $node;
            }
        }

        foreach ($merged as $key => $node) {
            foreach ($branches as $branch) {
                if (! isset($branch[$key])) {
                    $node['optional'] = true;
                    $merged[$key] = $node;

                    break;
                }
            }
        }

        return $merged;
    }

    /**
     * @param  list<list<array{0: int, 1: string}>>  $elements
     * @return array<string, array<string, mixed>>
     */
    private function fromElements(array $elements, int $depth): array
    {
        $shape = [];

        foreach ($elements as $element) {
            $split = TokenScanner::indexOfTopLevel($element, T_DOUBLE_ARROW);

            if ($split === null) {
                // No `=>`: a spread or one of Laravel's merge helpers, both of
                // which contribute their own keys to this level.
                foreach ($this->fromMergeElement($element, $depth) as $key => $node) {
                    $shape[$key] ??= $node;
                }

                continue;
            }

            $key = TokenScanner::literalKey(array_slice($element, 0, $split));
            if ($key === null) {
                continue;
            }

            $shape[$key] = $this->nodeFor(array_slice($element, $split + 1), $key, $depth);
        }

        return $shape;
    }

    /**
     * Keys contributed without a `=>`:
     *   `...parent::toArray($request)`, `...$this->baseFields()`, `...[…]`
     *   `$this->merge([...])`, `$this->mergeWhen($cond, [...])`
     *
     * @param  list<array{0: int, 1: string}>  $element
     * @return array<string, array<string, mixed>>
     */
    private function fromMergeElement(array $element, int $depth): array
    {
        $optional = false;

        if ($element[0][0] === T_ELLIPSIS) {
            $element = array_slice($element, 1);
        } elseif (($call = $this->conditionalCall($element)) !== null) {
            // mergeWhen/merge: the array argument's keys land at this level.
            [$element, $optional] = $call;
        } else {
            return [];
        }

        if ($element === []) {
            return [];
        }

        $shape = [];
        $cursor = 0;

        if (($elements = TokenScanner::arrayElements($element, $cursor)) !== null) {
            $shape = $this->fromElements($elements, $depth + 1);
        } elseif (($method = $this->calledShapeMethod($element)) !== null) {
            $shape = $this->fromMethod($method, $depth + 1);
        }

        if ($optional) {
            foreach ($shape as $key => $node) {
                $node['optional'] = true;
                $shape[$key] = $node;
            }
        }

        return $shape;
    }

    /**
     * `$this->merge([...])` and friends: returns the value argument's tokens
     * plus whether the merge is conditional. Null when this is not a merge.
     *
     * @param  list<array{0: int, 1: string}>  $element
     * @return array{0: list<array{0: int, 1: string}>, 1: bool}|null
     */
    private function conditionalCall(array $element): ?array
    {
        $name = TokenScanner::methodCallOnThis($element);

        if ($name === null) {
            return null;
        }

        $lower = strtolower($name);

        if ($lower === 'merge') {
            return [TokenScanner::argument($element, 0), false];
        }

        if ($lower === 'mergewhen' || $lower === 'mergeunless') {
            return [TokenScanner::argument($element, 1), true];
        }

        return null;
    }

    /**
     * The shape method behind `parent::toArray(…)`, `self::fields()` or
     * `$this->fields(…)`.
     *
     * @param  list<array{0: int, 1: string}>  $element
     */
    private function calledShapeMethod(array $element): ?ReflectionMethod
    {
        $target = $this->class;
        $name = TokenScanner::methodCallOnThis($element);

        if ($name === null && ($element[1][1] ?? '') === '::' && ($element[2][0] ?? 0) === T_STRING) {
            $receiver = strtolower($element[0][1]);

            if ($receiver === 'parent') {
                $parent = $this->class->getParentClass();
                $target = $parent === false ? null : $parent;
                $name = $element[2][1];
            } elseif ($receiver === 'self' || $receiver === 'static') {
                $name = $element[2][1];
            }
        }

        if ($name === null || $target === null || ! $target->hasMethod($name)) {
            return null;
        }

        $method = $target->getMethod($name);

        return self::isReadable($method) ? $method : null;
    }

    // ── Value expressions ────────────────────────────────────────────────────

    /**
     * The schema node for one value expression, from whatever evidence the
     * tokens carry.
     *
     * @param  list<array{0: int, 1: string}>  $value
     * @return array<string, mixed>
     */
    private function nodeFor(array $value, string $key, int $depth): array
    {
        $node = $this->inferNode($value, $depth);

        if (! isset($node['type']) && ! isset($node['shape']) && ! isset($node['list'])) {
            // The expression said nothing about the type: fall back to the
            // naming conventions, keeping what was established (nullability,
            // conditionality).
            $node += self::fromKeyName($key);
        }

        return $node;
    }

    /**
     * @param  list<array{0: int, 1: string}>  $value
     * @return array<string, mixed>
     */
    private function inferNode(array $value, int $depth): array
    {
        $value = TokenScanner::unwrap($value);

        if ($value === [] || $depth > self::MAX_DEPTH) {
            return [];
        }

        // Each attempt returns null when it does not recognise the expression
        // and an array - possibly empty - when it does.
        $node = $this->fromConditional($value, $depth)
            ?? $this->fromCoalesce($value, $depth)
            ?? $this->fromTernary($value, $depth)
            ?? self::fromCast($value)
            ?? self::fromLiteral($value)
            ?? $this->fromArrayLiteral($value, $depth)
            ?? $this->fromInstantiation($value)
            ?? $this->fromStaticFactory($value)
            ?? $this->fromConstant($value)
            ?? self::fromFunctionCall($value)
            ?? $this->fromChain($value)
            ?? [];

        if (! isset($node['type']) && ! isset($node['shape']) && ! isset($node['list'])) {
            $node += self::fromTrailingCall($value);
        }

        return $node;
    }

    /**
     * `$this->when($cond, $value)`, `$this->whenLoaded('posts', …)`: the key is
     * conditional, and the documented type is the value argument's.
     */
    private function fromConditional(array $value, int $depth): ?array
    {
        $name = TokenScanner::methodCallOnThis($value);
        $index = $name === null ? null : (self::CONDITIONAL[strtolower($name)] ?? null);

        if ($index === null) {
            return null;
        }

        $argument = TokenScanner::argument($value, $index);
        $node = $argument === [] ? [] : $this->inferNode($argument, $depth + 1);
        $node['optional'] = true;

        return $node;
    }

    /** `$this->avatar ?? null` - the left branch types the key. */
    private function fromCoalesce(array $value, int $depth): ?array
    {
        $split = TokenScanner::indexOfTopLevel($value, T_COALESCE);

        if ($split === null) {
            return null;
        }

        $left = array_slice($value, 0, $split);
        $right = array_slice($value, $split + 1);

        $node = $this->inferNode($left, $depth + 1);
        if ($node === []) {
            $node = $this->inferNode($right, $depth + 1);
        } elseif (TokenScanner::isNullLiteral($right)) {
            $node['nullable'] = true;
        }

        return $node;
    }

    /** `$cond ? $a : $b` and `$a ?: $b`. */
    private function fromTernary(array $value, int $depth): ?array
    {
        $question = TokenScanner::indexOfTopLevel($value, TokenScanner::CHAR, '?');

        if ($question === null) {
            return null;
        }

        $colon = TokenScanner::indexOfTopLevel(array_slice($value, $question + 1), TokenScanner::CHAR, ':');
        if ($colon === null) {
            return null;
        }

        $then = array_slice($value, $question + 1, $colon);
        $else = array_slice($value, $question + 1 + $colon + 1);

        // `?:` keeps its value on the left of the question mark.
        if ($then === []) {
            $then = array_slice($value, 0, $question);
        }

        $node = $this->inferNode($then, $depth + 1);
        if ($node === []) {
            $node = $this->inferNode($else, $depth + 1);
        } elseif (TokenScanner::isNullLiteral($else)) {
            $node['nullable'] = true;
        }

        return $node;
    }

    /** `(bool) $this->active` - the cast is the whole answer. */
    private static function fromCast(array $value): ?array
    {
        return match ($value[0][0]) {
            T_BOOL_CAST => ['type' => 'bool'],
            T_INT_CAST => ['type' => 'int'],
            T_DOUBLE_CAST => ['type' => 'float'],
            T_STRING_CAST => ['type' => 'string'],
            T_ARRAY_CAST => ['type' => 'array'],
            T_OBJECT_CAST => ['type' => 'object'],
            default => null,
        };
    }

    /** Scalar literals, interpolated strings, and string concatenation. */
    private static function fromLiteral(array $value): ?array
    {
        if (TokenScanner::indexOfTopLevel($value, TokenScanner::CHAR, '.') !== null) {
            return ['type' => 'string'];
        }

        $first = $value[0];

        if ($first[0] === T_START_HEREDOC || $first[1] === '"') {
            return ['type' => 'string'];
        }

        if (count($value) !== 1) {
            // A negative number literal is still a literal.
            if (count($value) === 2 && $first[1] === '-') {
                return self::fromLiteral([$value[1]]);
            }

            return null;
        }

        return match (true) {
            $first[0] === T_LNUMBER => ['type' => 'int'],
            $first[0] === T_DNUMBER => ['type' => 'float'],
            $first[0] === T_CONSTANT_ENCAPSED_STRING => ['type' => 'string'],
            $first[0] === T_STRING && in_array(strtolower($first[1]), ['true', 'false'], true) => ['type' => 'bool'],
            $first[0] === T_STRING && strtolower($first[1]) === 'null' => ['nullable' => true],
            default => null,
        };
    }

    /** A nested literal is either an object of its own or a JSON array. */
    private function fromArrayLiteral(array $value, int $depth): ?array
    {
        $cursor = 0;
        $elements = TokenScanner::arrayElements($value, $cursor);

        if ($elements === null) {
            return null;
        }

        if ($elements === []) {
            return ['type' => 'array'];
        }

        // String keys make it an object; anything else is a JSON array whose
        // items are described by the first element.
        $shape = $this->fromElements($elements, $depth + 1);

        return $shape !== []
            ? ['shape' => $shape]
            : ['list' => $this->inferNode($elements[0], $depth + 1)];
    }

    /** `new ProfileResource($this->profile)`. */
    private function fromInstantiation(array $value): ?array
    {
        if ($value[0][0] !== T_NEW || ! isset($value[1])) {
            return null;
        }

        $class = TokenScanner::name($value, 1);

        return $class === null ? [] : ['type' => $this->resolver()->resolve($class)];
    }

    /** `PostResource::collection($posts)` and `PostResource::make($post)`. */
    private function fromStaticFactory(array $value): ?array
    {
        if (! in_array($value[0][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        if (($value[1][1] ?? '') !== '::' || ($value[2][0] ?? 0) !== T_STRING || ($value[3][1] ?? '') !== '(') {
            return null;
        }

        $method = strtolower($value[2][1]);
        $class = $this->resolver()->resolve($value[0][1]);

        // A conditional relation inside the factory keeps the key conditional:
        // `PostResource::collection($this->whenLoaded('posts'))`.
        $optional = self::mentionsConditional($value) ? ['optional' => true] : [];

        if (in_array($method, self::COLLECTION_FACTORIES, true)) {
            return ['list' => ['type' => $class]] + $optional;
        }

        if (in_array($method, self::INSTANCE_FACTORIES, true)) {
            return ['type' => $class] + $optional;
        }

        return null;
    }

    /** `self::VERSION`, `OrderStatus::Pending`, `User::class`. */
    private function fromConstant(array $value): ?array
    {
        if (count($value) !== 3
            || ! in_array($value[0][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC], true)
            || $value[1][1] !== '::'
        ) {
            return null;
        }

        if ($value[2][0] === T_CLASS || strtolower($value[2][1]) === 'class') {
            return ['type' => 'string'];
        }

        if ($value[2][0] !== T_STRING) {
            return null;
        }

        $class = $this->resolver()->resolve($value[0][1]);

        if (! class_exists($class) && ! interface_exists($class) && ! enum_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->hasConstant($value[2][1])) {
            return null;
        }

        // An enum case is a constant whose value is the case object, so this
        // covers `Status::Active` as well as `self::MAX`.
        return self::fromValue($reflection->getConstant($value[2][1]));
    }

    /** @return array<string, mixed> */
    private static function fromValue(mixed $value): array
    {
        return match (true) {
            is_bool($value) => ['type' => 'bool'],
            is_int($value) => ['type' => 'int'],
            is_float($value) => ['type' => 'float'],
            is_string($value) => ['type' => 'string'],
            is_array($value) => ['type' => 'array'],
            $value === null => ['nullable' => true],
            is_object($value) => ['type' => $value::class],
            default => [],
        };
    }

    /** `count($this->posts)`, `sprintf(…)` - functions with one obvious type. */
    private static function fromFunctionCall(array $value): ?array
    {
        if ($value[0][0] !== T_STRING || ($value[1][1] ?? '') !== '(') {
            return null;
        }

        return self::FUNCTION_TYPES[strtolower($value[0][1])] ?? null;
    }

    /**
     * Last chance on an expression nothing else could read: the method it ends
     * with. `collect($rows)->pluck('name')` is an array however it was built.
     *
     * @param  list<array{0: int, 1: string}>  $value
     * @return array<string, mixed>
     */
    private static function fromTrailingCall(array $value): array
    {
        for ($i = count($value) - 1; $i >= 2; $i--) {
            if ($value[$i][1] !== '('
                || $value[$i - 1][0] !== T_STRING
                || ! in_array($value[$i - 2][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
            ) {
                continue;
            }

            return self::METHOD_TYPES[strtolower($value[$i - 1][1])] ?? [];
        }

        return [];
    }

    /**
     * `$this->name`, `$this->resource->email`, `$this->created_at?->toIso8601String()`:
     * follow the chain through reflection, `@property` tags and `@mixin`.
     */
    private function fromChain(array $value): ?array
    {
        if ($value[0][0] !== T_VARIABLE || $value[0][1] !== '$this') {
            return null;
        }

        $class = $this->class->getName();
        $node = [];
        $nullsafe = false;
        $i = 1;
        $count = count($value);

        while ($i + 1 < $count
            && in_array($value[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && $value[$i + 1][0] === T_STRING
        ) {
            $nullsafe = $nullsafe || $value[$i][0] === T_NULLSAFE_OBJECT_OPERATOR;
            $member = $value[$i + 1][1];

            // A call ends the walk: its result is the value of the key.
            if (($value[$i + 2][1] ?? '') === '(') {
                $node = $this->methodResult($class, $member) ?? [];

                break;
            }

            $resolved = $this->memberType($class, $member, 0);
            if ($resolved === null) {
                break;
            }

            $i += 2;

            // Only the last link types the key: in `$this->author->name` the
            // answer is the type of `name`, not of `author`. A list type walks
            // on as its item class, so `$this->collection->count()` still
            // resolves.
            $next = self::classOf(rtrim((string) ($resolved['type'] ?? ''), '[]{}'));

            if ($next === null || $i + 1 >= $count) {
                $node = $resolved;

                break;
            }

            $class = $next;
        }

        if ($nullsafe) {
            $node['nullable'] = true;
        }

        return $node;
    }

    /**
     * The declared return type of `$this->something()`, else the naming
     * conventions in {@see METHOD_TYPES}.
     *
     * @return array<string, mixed>|null
     */
    private function methodResult(string $class, string $method): ?array
    {
        $declared = null;

        if (class_exists($class) || interface_exists($class)) {
            $reflection = new ReflectionClass($class);
            if ($reflection->hasMethod($method)) {
                $target = $reflection->getMethod($method);
                $type = TypeInferrer::returnType($target);
                if ($type !== null && ! in_array(strtolower($type), ['mixed', 'static', 'self', '$this'], true)) {
                    $declared = ['type' => NameResolver::forClass($target->getDeclaringClass())->resolveTypeString($type)];
                }
            }
        }

        // A declared *class* return type is the most specific answer there is.
        // A declared scalar is not: `toIso8601String(): string` is true but
        // `date-time` is truer, and Collection's `pluck(): static` says nothing.
        if ($declared !== null && self::classOf((string) $declared['type']) !== null) {
            return $declared;
        }

        return self::METHOD_TYPES[strtolower($method)] ?? $declared;
    }

    /**
     * The type of `$class::$member`, from a real property, a `@property`
     * annotation, or the model a resource is a `@mixin` of.
     *
     * @return array<string, mixed>|null
     */
    private function memberType(string $class, string $member, int $depth): ?array
    {
        if ($depth > 3 || (! class_exists($class) && ! interface_exists($class))) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        // `$this->collection` inside a resource collection is the list of
        // resources it collects - the one member whose declared type
        // (a bare Collection) is less true than the convention.
        if ($member === 'collection' && ($collects = self::collects($reflection)) !== null) {
            return ['type' => $collects.'[]'];
        }

        if ($reflection->hasProperty($member)) {
            $property = $reflection->getProperty($member);
            $type = $property->getType();
            $annotated = DocBlockReader::varType($property->getDocComment());

            if ($annotated !== null && (! $type instanceof ReflectionNamedType
                || in_array(strtolower($type->getName()), ['array', 'iterable', 'mixed', 'object'], true))
            ) {
                return ['type' => NameResolver::forClass($property->getDeclaringClass())
                    ->resolveTypeString(TypeInferrer::normalise($annotated))];
            }

            if ($type instanceof ReflectionNamedType && strtolower($type->getName()) !== 'mixed') {
                // A reflection type is already fully qualified, and `int` is not
                // a class to be resolved against the file's imports.
                $node = ['type' => $type->getName()];
                if ($type->allowsNull()) {
                    $node['nullable'] = true;
                }

                return $node;
            }
        }

        // `@property` / `@property-read`, walking up the hierarchy: each
        // docblock is resolved against the imports of the file it lives in.
        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            $types = DocBlockReader::propertyTypes($current->getDocComment());
            if (isset($types[$member])) {
                return ['type' => NameResolver::forClass($current)
                    ->resolveTypeString(TypeInferrer::normalise($types[$member]))];
            }
        }

        // An Eloquent-style model declares its column types in `$casts` (or the
        // `casts()` method) and its key type in `$keyType`. Both are static
        // declarations, so no model is constructed and no database is touched -
        // and they are the only *declared* truth about a column, which is why
        // they beat the naming conventions.
        $declared = $this->declaredAttributeType($reflection, $member);
        if ($declared !== null) {
            return $declared;
        }

        // A resource reads its model: `$this->resource` *is* the mixin target,
        // and `$this->anything_else` is one of its attributes.
        foreach ($this->mixinsOf($reflection) as $mixin) {
            if ($member === 'resource') {
                return ['type' => $mixin];
            }

            $node = $this->memberType($mixin, $member, $depth + 1);
            if ($node !== null) {
                return $node;
            }
        }

        return null;
    }

    /**
     * The type a model *declares* for one attribute: its cast, or - for the
     * primary key - `$keyType`, which is how a UUID model says its `id` is a
     * string where the naming convention would guess an integer.
     *
     * Duck-typed on the declarations themselves rather than on Eloquent, so it
     * costs nothing outside Laravel and works for any class that follows the
     * same convention.
     *
     * @return array<string, mixed>|null
     */
    private function declaredAttributeType(ReflectionClass $class, string $member): ?array
    {
        $defaults = $class->getDefaultProperties();

        $key = is_string($defaults['primaryKey'] ?? null) ? $defaults['primaryKey'] : null;
        if ($key !== null && $member === $key) {
            return ['type' => ($defaults['keyType'] ?? 'int') === 'int' ? 'int' : 'string'];
        }

        $cast = $this->castMap($class)[$member] ?? null;

        return $cast === null ? null : self::fromCastType($cast);
    }

    /**
     * A model's cast map, from the `$casts` property default and from the
     * literal a `casts()` method returns (Laravel 11's form). Both are plain
     * arrays of strings; neither needs the model to exist as an object.
     *
     * @return array<string, string>
     */
    private function castMap(ReflectionClass $class): array
    {
        $casts = [];

        $declared = $class->getDefaultProperties()['casts'] ?? null;

        foreach (is_array($declared) ? $declared : [] as $attribute => $cast) {
            if (is_string($attribute) && is_string($cast)) {
                $casts[$attribute] = $cast;
            }
        }

        $method = $class->hasMethod('casts') ? $class->getMethod('casts') : null;
        if ($method === null || ! self::isReadable($method)) {
            return $casts;
        }

        $tokens = TokenScanner::ofMethod($method);
        $count = count($tokens);
        $resolver = NameResolver::forClass($method->getDeclaringClass());

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_RETURN) {
                continue;
            }
            $cursor = $i + 1;
            foreach (TokenScanner::arrayElements($tokens, $cursor) ?? [] as $element) {
                $split = TokenScanner::indexOfTopLevel($element, T_DOUBLE_ARROW);
                if ($split === null) {
                    continue;
                }
                $attribute = TokenScanner::literalKey(array_slice($element, 0, $split));
                $value = self::literalString(array_slice($element, $split + 1), $resolver);
                if ($attribute !== null && $value !== null) {
                    $casts[$attribute] ??= $value;
                }
            }
            break;
        }

        return $casts;
    }

    /**
     * A cast written as `'boolean'` or as `Status::class`.
     *
     * @param  list<array{0: int, 1: string}>  $value
     */
    private static function literalString(array $value, NameResolver $resolver): ?string
    {
        if (count($value) === 1 && $value[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            return substr($value[0][1], 1, -1);
        }

        // `'status' => OrderStatus::class`
        if (count($value) === 3 && $value[1][1] === '::' && strtolower($value[2][1]) === 'class') {
            return $resolver->resolve($value[0][1]);
        }

        return null;
    }

    /**
     * Laravel's cast vocabulary → JSON. `decimal:` is a string on purpose:
     * Eloquent formats it with number_format and hands back a string.
     *
     * @return array<string, mixed>
     */
    private static function fromCastType(string $cast): array
    {
        // `decimal:2`, `encrypted:array`, `datetime:Y-m-d` - the modifier is
        // formatting, the head is the type.
        [$head, $modifier] = array_pad(explode(':', $cast, 2), 2, null);
        $head = strtolower(trim((string) $head));

        if ($head === 'encrypted') {
            return $modifier === null ? ['type' => 'string'] : self::fromCastType($modifier);
        }

        $node = match (strtolower($head)) {
            'int', 'integer', 'timestamp' => ['type' => 'int'],
            'real', 'float', 'double' => ['type' => 'float'],
            'decimal', 'string', 'hashed' => ['type' => 'string'],
            'bool', 'boolean' => ['type' => 'bool'],
            'array', 'json', 'collection' => ['type' => 'array'],
            'object' => ['type' => 'object'],
            'date', 'immutable_date' => ['type' => 'string', 'format' => 'date'],
            'datetime', 'immutable_datetime', 'custom_datetime', 'immutable_custom_datetime'
                => ['type' => 'string', 'format' => 'date-time'],
            default => [],
        };

        if ($node !== []) {
            return $node;
        }

        // A class-string cast. An enum documents itself; Eloquent's own casters
        // are named after the shape they produce; anything else is a custom
        // CastsAttributes and says nothing about the JSON it makes.
        if (enum_exists($head)) {
            return ['type' => $head];
        }

        return match (strtolower(ClassName::short($head))) {
            'ascollection', 'asarrayobject', 'asenumcollection',
            'asencryptedcollection', 'asencryptedarrayobject' => ['type' => 'array'],
            'asstringable' => ['type' => 'string'],
            default => [],
        };
    }

    /**
     * What a resource collection collects: its `$collects` property, else the
     * class it is named after - `UserCollection` collects `User` or
     * `UserResource`, the same two candidates Laravel itself tries.
     */
    private static function collects(ReflectionClass $class): ?string
    {
        if (! $class->hasProperty('collects') || ! $class->hasProperty('collection')) {
            return null;
        }

        $property = $class->getProperty('collects');
        $declared = $property->hasDefaultValue() ? $property->getDefaultValue() : null;

        if (is_string($declared) && (class_exists($declared) || interface_exists($declared))) {
            return $declared;
        }

        $name = $class->getName();
        if (! str_ends_with($name, 'Collection')) {
            return null;
        }

        $base = substr($name, 0, -strlen('Collection'));
        foreach ([$base, $base.'Resource'] as $candidate) {
            if ($candidate !== '' && class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function mixinsOf(ReflectionClass $class): array
    {
        $mixins = [];
        $resolver = NameResolver::forClass($class);

        foreach (DocBlockReader::mixins($class->getDocComment()) as $mixin) {
            $resolved = $resolver->resolve($mixin);
            if (class_exists($resolved) || interface_exists($resolved)) {
                $mixins[] = $resolved;
            }
        }

        return $mixins;
    }

    // ── Source 3: @property annotations on the class ─────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function fromPropertyTags(): array
    {
        $shape = [];

        foreach (DocBlockReader::propertyTypes($this->class->getDocComment()) as $name => $type) {
            $shape[$name] = ['type' => $this->resolver()->resolveTypeString(TypeInferrer::normalise($type))];
        }

        return $shape;
    }

    // ── Naming conventions ───────────────────────────────────────────────────

    /**
     * Last resort, and the only place a type is guessed rather than read: the
     * naming conventions an API payload almost always follows. Overridable by
     * annotating the key - `@return array{id: string, …}` - or the model
     * attribute it reads.
     *
     * @return array<string, mixed>
     */
    private static function fromKeyName(string $key): array
    {
        $name = strtolower($key);

        return match (true) {
            $name === 'id', str_ends_with($name, '_id') => ['type' => 'int'],
            str_ends_with($name, '_at') => ['type' => 'string', 'format' => 'date-time'],
            $name === 'count', str_ends_with($name, '_count') => ['type' => 'int'],
            str_starts_with($name, 'is_'), str_starts_with($name, 'has_'), str_starts_with($name, 'can_') => ['type' => 'bool'],
            $name === 'email' => ['type' => 'string', 'format' => 'email'],
            $name === 'url', str_ends_with($name, '_url') => ['type' => 'string', 'format' => 'uri'],
            $name === 'uuid' => ['type' => 'string', 'format' => 'uuid'],
            default => [],
        };
    }

    /** Does a conditional helper appear anywhere in this expression? */
    private static function mentionsConditional(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token[0] === T_STRING && isset(self::CONDITIONAL[strtolower($token[1])])) {
                return true;
            }
        }

        return false;
    }

    /** A type string that names one loadable class, else null. */
    private static function classOf(string $type): ?string
    {
        $type = trim(explode('|', $type)[0]);

        return $type !== '' && (class_exists($type) || interface_exists($type)) ? $type : null;
    }
}
