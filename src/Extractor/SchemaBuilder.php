<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

use ApexDocs\Attribute\Schema as SchemaAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Converts PHP type strings into OpenAPI schema arrays.
 * Pure PHP  no framework dependency.
 *
 * When a ComponentRegistry is attached, every class schema is registered once
 * and subsequent references return $ref pointers instead of inlining the schema.
 */
final class SchemaBuilder
{
    private const REF_PREFIX = '#/components/schemas/';

    /** Psalm/PHPStan string and integer refinements - all one JSON type. */
    private const STRING_ALIASES = [
        'string', 'class-string', 'interface-string', 'enum-string', 'trait-string',
        'callable-string', 'literal-string', 'non-empty-string', 'non-falsy-string',
        'truthy-string', 'numeric-string', 'lowercase-string', 'non-empty-lowercase-string',
    ];

    private const INTEGER_ALIASES = [
        'positive-int', 'negative-int', 'non-positive-int', 'non-negative-int',
        'non-zero-int', 'int-mask', 'int-mask-of',
    ];

    /**
     * Framework bases that stand in for "some payload" without being one.
     * Matched EXACTLY, never by inheritance: `UserResource extends JsonResource`
     * must keep its schema - it is only the bare base, written as a return type,
     * that has nothing to say. Reflected, each publishes its own plumbing:
     * `{resource, with, additional}` for JsonResource, `{exists,
     * wasRecentlyCreated, timestamps}` for an Eloquent Model.
     */
    private const ABSTRACT_PAYLOADS = [
        'Illuminate\Http\Resources\Json\JsonResource',
        'Illuminate\Http\Resources\Json\ResourceCollection',
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Database\Eloquent\Relations\Relation',
        'Illuminate\Database\Eloquent\Builder',
        'Illuminate\Database\Query\Builder',
    ];

    /**
     * Classes that *carry* a payload rather than being one. Reflecting them
     * documents the framework's plumbing: `Illuminate\Http\JsonResponse` has
     * public `original` and `exception`, so a controller declared
     * `: JsonResponse` published `{original, exception}` as its response body -
     * a confident, wrong answer where the truth is that the body is unknown.
     *
     * Matched by inheritance, so one entry covers Illuminate's JsonResponse,
     * RedirectResponse, StreamedResponse and BinaryFileResponse alike. Laravel
     * API resources are deliberately absent: a JsonResource *subclass* is the
     * payload; only the bare base above is not.
     */
    private const RESPONSE_WRAPPERS = [
        'Symfony\Component\HttpFoundation\Response',
        'Psr\Http\Message\ResponseInterface',
        'Psr\Http\Message\StreamInterface',
        'Illuminate\Contracts\View\View',
        'Illuminate\Contracts\Routing\ResponseFactory',
    ];

    /** @var array<string, true> prevent infinite recursion during build */
    private array $building = [];

    private int $maxDepth;

    private ?ComponentRegistry $registry;

    public function __construct(int $maxDepth = 6, ?ComponentRegistry $registry = null)
    {
        $this->maxDepth = max(1, $maxDepth);
        $this->registry = $registry;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function fromTypeString(string $type, int $depth = 0): array
    {
        $type = trim(ltrim(trim($type), '\\'));
        $lower = strtolower($type);

        return match (true) {
            $type === ''                             => [],
            $lower === 'void', $lower === 'never'    => [],
            $lower === 'mixed'                       => [],
            $lower === 'null'                        => ['type' => 'null'],
            $lower === 'bool', $lower === 'boolean', $lower === 'true', $lower === 'false'
                                                     => ['type' => 'boolean'],
            $lower === 'int', $lower === 'integer'   => ['type' => 'integer'],
            $lower === 'array-key'                   => ['type' => ['string', 'integer']],
            $lower === 'float', $lower === 'double', $lower === 'number'
                                                     => ['type' => 'number', 'format' => 'float'],
            // PHPStan/Psalm narrow the scalars far past PHP's own vocabulary;
            // every one of these is still just a string or an integer in JSON.
            in_array($lower, self::STRING_ALIASES, true) => ['type' => 'string'],
            in_array($lower, self::INTEGER_ALIASES, true) => ['type' => 'integer'],
            $lower === 'array', $lower === 'iterable', $lower === 'list'
                                                     => ['type' => 'array', 'items' => new \stdClass],
            $lower === 'object', $lower === 'stdclass' => ['type' => 'object'],
            str_contains($type, '&')                 => $this->intersection($type, $depth),
            str_contains($type, '|')                 => $this->union($type, $depth),
            str_ends_with($type, '[]')               => $this->arrayOf(substr($type, 0, -2), $depth),
            str_ends_with($type, '{}')               => $this->mapOf(substr($type, 0, -2), $depth),
            class_exists($type) || interface_exists($type) || enum_exists($type)
                                                     => $this->fromClass($type, $depth),
            // A name that resolves to nothing - an unimported class, a typo, a
            // `callable`, a `resource` - constrains nothing. Guessing `string`
            // here is how a JSON object came to be documented as a string; for
            // a return type it also means no 200 content is invented.
            default                                  => [],
        };
    }

    /** @return array<string, mixed> */
    public function fromClass(string $class, int $depth = 0): array
    {
        $class = trim($class, '\\');

        // A date is a string in JSON, not an object graph: Laravel, Symfony and
        // every serialiser worth the name emit DateTimeInterface as an ISO-8601
        // string, and a $ref to a `Carbon` component documents nothing.
        if (is_a($class, \DateTimeInterface::class, true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        // A collection or paginator written without its generic is a list of
        // something unknown - the same reading `Collection<T>` already gets.
        if (TypeInferrer::isIterableWrapper($class)) {
            return ['type' => 'array', 'items' => new \stdClass];
        }

        // A response wrapper carries the payload; it is not the payload. Nor is
        // the abstract base a payload class happens to extend.
        if (self::isResponseWrapper($class) || in_array($class, self::ABSTRACT_PAYLOADS, true)) {
            return [];
        }

        // Already registered in registry → return $ref immediately
        if ($this->registry?->has($class)) {
            return ['$ref' => $this->registry->refFor($class)];
        }

        // Circular reference mid-build → return $ref (schema will be complete on unwind)
        if (isset($this->building[$class])) {
            return ['$ref' => self::REF_PREFIX.$this->schemaName($class)];
        }

        if ($depth >= $this->maxDepth) {
            return ['type' => 'object'];
        }

        // Claim the component name before descending so recursive references
        // point at the same name the finished schema is stored under.
        $name = $this->schemaName($class);
        $this->building[$class] = true;

        try {
            $ref = new ReflectionClass($class);
        } catch (\ReflectionException) {
            unset($this->building[$class]);
            $this->registry?->release($class);

            return ['type' => 'object'];
        }

        $schema = $ref->isEnum() ? $this->fromEnum($class) : $this->fromReflection($ref, $depth);

        // Apply #[Schema] metadata from the class itself
        $schema = $this->applySchemaAttribute($ref, $schema);

        unset($this->building[$class]);

        if ($this->registry !== null) {
            $this->registry->register($class, $schema);

            return ['$ref' => self::REF_PREFIX.$name];
        }

        return $schema;
    }

    /**
     * The components/schemas key this class is published under. Unique per
     * class when a registry is attached, otherwise the bare short name.
     */
    public function schemaName(string $class): string
    {
        $class = trim($class, '\\');

        return $this->registry?->reserve($class, ClassName::short($class)) ?? ClassName::short($class);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function fromEnum(string $class): array
    {
        try {
            $values = [];
            $type = 'string';

            foreach ($class::cases() as $case) {
                if (property_exists($case, 'value')) {
                    $values[] = $case->value;
                    $type = is_int($case->value) ? 'integer' : 'string';
                } else {
                    $values[] = $case->name;
                }
            }

            // An empty `enum` is not a meaningful constraint  omit it.
            return $values === [] ? ['type' => $type] : ['type' => $type, 'enum' => $values];
        } catch (\Throwable) {
            return ['type' => 'string'];
        }
    }

    private function fromReflection(ReflectionClass $ref, int $depth): array
    {
        // A class that declares how it becomes an array says more than its
        // property list does. For an API resource the keys live in `toArray()`
        // and the public surface is plumbing - Laravel's own `$collects` and
        // `$resource` are public and belong in no payload - and for a
        // JsonSerializable value object `jsonSerialize()` *is* the JSON.
        $shape = ArrayShapeReader::forClass($ref);
        if ($shape !== []) {
            // That method describes the whole payload, so a parent's properties
            // are not merged in on top of it.
            return $this->fromShape($shape, $depth);
        }

        $ownProperties = [];
        $required = [];

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            // Only own properties  parent properties handled via allOf
            if ($prop->getDeclaringClass()->getName() !== $ref->getName()) {
                continue;
            }

            // A static property is class state, not instance data: it cannot
            // appear in a payload. `JsonResource::$wrap` is the example that
            // matters - it was being published as a response key.
            if ($prop->isStatic()) {
                continue;
            }

            $propType = $prop->getType();
            $annotated = $this->annotatedType($prop);

            if (! ($propType instanceof ReflectionNamedType)) {
                // Untyped, union, or intersection property: the annotation is
                // the only type information available.
                $ownProperties[$prop->getName()] = $annotated !== null
                    ? $this->fromTypeString($annotated, $depth + 1)
                    : new \stdClass;

                continue;
            }

            // `@var Item[]` beats a bare `array` declaration.
            $useAnnotation = $annotated !== null
                && in_array(strtolower($propType->getName()), ['array', 'iterable', 'mixed', 'object'], true);

            $s = $this->fromTypeString(
                $useAnnotation ? $annotated : $propType->getName(),
                $depth + 1,
            );
            if ($propType->allowsNull() && ($s['type'] ?? null) !== 'null') {
                $s = self::asNullable($s);
            }
            // `mixed` constrains nothing, and an empty array encodes as `[]`
            // rather than the empty schema object `{}`.
            $ownProperties[$prop->getName()] = $s === [] ? new \stdClass : $s;

            // A property is required when it is non-nullable and has no default value,
            // or is readonly (must be supplied at construction time).
            if ($this->isRequired($prop, $propType)) {
                $required[] = $prop->getName();
            }
        }

        // Nothing to reflect and no payload method: an Eloquent model documents
        // its columns as annotations, because no PHP property declares them.
        if ($ownProperties === []) {
            $annotated = ArrayShapeReader::fromAnnotations($ref);
            if ($annotated !== []) {
                return $this->fromShape($annotated, $depth);
            }
        }

        $ownSchema = ['type' => 'object'];
        if ($ownProperties !== []) {
            // An empty PHP array encodes as `[]`, and `properties: []` is not a
            // valid JSON Schema. A class with nothing public and no readable
            // payload method is simply an untyped object.
            $ownSchema['properties'] = $ownProperties;
        }
        if ($required) {
            $ownSchema['required'] = $required;
        }

        // Schema inheritance: when the parent is a DTO of its own, model it as
        // allOf so the parent schema is reused via $ref.
        $parent = $ref->getParentClass();
        if ($parent !== false && $this->isDocumentableParent($parent)) {
            $parentSchema = $this->fromClass($parent->getName(), $depth + 1);

            return ['allOf' => [$parentSchema, $ownSchema]];
        }

        return $ownSchema;
    }

    /**
     * Turn a payload shape recovered by {@see ArrayShapeReader} into an object
     * schema. Keys are required unless the reader found them conditional.
     *
     * @param  array<string, array<string, mixed>>  $shape
     * @return array<string, mixed>
     */
    private function fromShape(array $shape, int $depth): array
    {
        $properties = [];
        $required = [];

        foreach ($shape as $key => $node) {
            if (is_int($key)) {
                // `properties` keyed 0,1,2… encodes as a JSON array, which is
                // not a Schema Object. A positional key describes a tuple, not
                // an object, so there is nothing to publish.
                continue;
            }

            $schema = $this->fromShapeNode($node, $depth + 1);
            $properties[$key] = $schema === [] ? new \stdClass : $schema;

            if (! ($node['optional'] ?? false)) {
                $required[] = $key;
            }
        }

        if ($properties === []) {
            return ['type' => 'object'];
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function fromShapeNode(array $node, int $depth): array
    {
        if (isset($node['shape'])) {
            /** @var array<string, array<string, mixed>> $nested */
            $nested = $node['shape'];

            return $depth >= $this->maxDepth ? ['type' => 'object'] : $this->fromShape($nested, $depth);
        }

        if (isset($node['list'])) {
            /** @var array<string, mixed> $item */
            $item = $node['list'];
            $items = $depth >= $this->maxDepth ? [] : $this->fromShapeNode($item, $depth + 1);

            return ['type' => 'array', 'items' => $items === [] ? new \stdClass : $items];
        }

        $schema = isset($node['type']) ? $this->fromTypeString((string) $node['type'], $depth) : [];

        if (isset($node['format']) && ($schema['type'] ?? null) === 'string') {
            $schema['format'] = (string) $node['format'];
        }

        if (($node['nullable'] ?? false) && $schema !== []) {
            $schema = self::asNullable($schema);
        }

        return $schema;
    }

    /**
     * Should a parent class become its own component schema?
     *
     * Only user-space parents with public properties qualify. Framework base
     * classes (Eloquent's Model, Symfony's controllers, …) expose unrelated
     * public state  $timestamps, $exists, $wasRecentlyCreated  that has no
     * business in an API payload schema.
     */
    private function isDocumentableParent(ReflectionClass $parent): bool
    {
        if ($parent->getProperties(ReflectionProperty::IS_PUBLIC) === []) {
            return false;
        }

        if ($this->isPhpBuiltin($parent->getName())) {
            return false;
        }

        $file = $parent->getFileName();

        return $file === false || ! str_contains(str_replace('\\', '/', $file), '/vendor/');
    }

    /**
     * The `@var` type on a property (or on its promoted constructor parameter),
     * normalised for {@see fromTypeString()}.
     */
    private function annotatedType(ReflectionProperty $prop): ?string
    {
        $doc = $prop->getDocComment();
        $type = $doc === false ? null : DocBlockReader::varType($doc);

        if ($type === null && $prop->isPromoted()) {
            $ctor = $prop->getDeclaringClass()->getConstructor();
            $type = $ctor === null
                ? null
                : DocBlockReader::paramTypes($ctor->getDocComment())[$prop->getName()] ?? null;
        }

        if ($type === null || $type === '') {
            return null;
        }

        $normalised = TypeInferrer::normalise($type);

        if ($normalised === '') {
            return null;
        }

        // `@var Item[]` names the Item this file imported, not a global one.
        return NameResolver::forClass($prop->getDeclaringClass())->resolveTypeString($normalised);
    }

    /**
     * Is the property required in the JSON representation?
     *
     * Required precisely when a JSON payload omitting it would be invalid:
     *
     *   - Has a default value → optional. Whether the default lives on the
     *     property itself or on the constructor-promoted parameter, missing
     *     keys are filled in.
     *   - Allows null and no default → still required to be PRESENT in the
     *     payload (clients send `null` explicitly), but only for readonly
     *     props. Mutable nullable props with no default are treated as
     *     optional  the conventional Eloquent/DTO read.
     *   - Non-nullable, no default → required.
     *
     * PHP quirk: ReflectionProperty::hasDefaultValue() returns false for
     * promoted constructor parameters even when the parameter declares a
     * default. We probe the matching constructor parameter to recover the
     * truth.
     */
    private function isRequired(ReflectionProperty $prop, ReflectionNamedType $type): bool
    {
        if ($this->propertyHasDefault($prop)) {
            return false;
        }

        if ($type->allowsNull()) {
            return $prop->isReadOnly();
        }

        return true;
    }

    private function propertyHasDefault(ReflectionProperty $prop): bool
    {
        if ($prop->hasDefaultValue()) {
            return true;
        }

        if (! $prop->isPromoted()) {
            return false;
        }

        try {
            $ctor = $prop->getDeclaringClass()->getConstructor();
            if ($ctor === null) {
                return false;
            }
            foreach ($ctor->getParameters() as $param) {
                if ($param->getName() === $prop->getName()) {
                    return $param->isDefaultValueAvailable();
                }
            }
        } catch (\ReflectionException) {
            return false;
        }

        return false;
    }

    private static function isResponseWrapper(string $class): bool
    {
        foreach (self::RESPONSE_WRAPPERS as $wrapper) {
            // is_a() with a class-string target is false, not fatal, when the
            // framework in question is not installed.
            if (is_a($class, $wrapper, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A Schema Object slot cannot hold an empty PHP array: it encodes as `[]`,
     * which is not a schema. "No constraint" is the empty *object*.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function anySchema(array $schema): array|\stdClass
    {
        return $schema === [] ? new \stdClass : $schema;
    }

    private function isPhpBuiltin(string $class): bool
    {
        try {
            return (new ReflectionClass($class))->isInternal();
        } catch (\ReflectionException) {
            return false;
        }
    }

    /** @param array<string, mixed> $schema */
    private function applySchemaAttribute(ReflectionClass $ref, array $schema): array
    {
        // Read through AttributeReader: instantiating an attribute whose
        // arguments no longer resolve throws, and a broken annotation must not
        // take down the whole build.
        $meta = AttributeReader::first($ref, SchemaAttribute::class);
        if (! $meta instanceof SchemaAttribute) {
            return $schema;
        }

        if ($meta->title !== '') {
            $schema['title'] = $meta->title;
        }
        if ($meta->description !== '') {
            $schema['description'] = $meta->description;
        }
        if ($meta->example !== null) {
            $schema['example'] = $meta->example;
        }
        if ($meta->deprecated) {
            $schema['deprecated'] = true;
        }
        if ($meta->externalDocs) {
            $schema['externalDocs'] = $meta->externalDocs;
        }

        return $schema;
    }

    private function union(string $type, int $depth): array
    {
        $parts = array_map('trim', explode('|', $type));
        $nullable = in_array('null', $parts, true);
        $parts = array_values(array_filter($parts, fn ($t) => $t !== 'null'));

        // A `mixed`/`void`/`never` member yields an empty schema, which is not a
        // valid oneOf branch  and it also makes the whole union unconstrained.
        $branches = [];
        foreach ($parts as $part) {
            $branch = $this->fromTypeString($part, $depth);
            if ($branch === []) {
                return [];
            }
            $branches[] = $branch;
        }

        if ($branches === []) {
            return $nullable ? ['type' => 'null'] : [];
        }

        if (count($branches) === 1) {
            return $nullable ? self::asNullable($branches[0]) : $branches[0];
        }

        return $nullable
            ? self::asNullable(['oneOf' => $branches])
            : ['oneOf' => $branches];
    }

    /**
     * Encode a schema as nullable per the spec's declared OpenAPI version.
     * OpenAPI 3.1 uses `type: [..., 'null']`; older 3.0 specs use `nullable: true`.
     * For primitive-typed schemas we use the 3.1 form; for $ref or composite schemas
     * (oneOf/allOf/anyOf) we wrap with `oneOf` so the null branch is explicit.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function asNullable(array $schema): array
    {
        if ($schema === []) {
            // `mixed`/`void` carry no constraint at all, and an empty array
            // encodes as `[]`  not a Schema Object. "Anything, including null"
            // is best expressed by saying nothing.
            return [];
        }

        if (isset($schema['$ref'])) {
            return ['oneOf' => [$schema, ['type' => 'null']]];
        }

        if (isset($schema['oneOf']) || isset($schema['anyOf']) || isset($schema['allOf'])) {
            $key = isset($schema['oneOf']) ? 'oneOf' : (isset($schema['anyOf']) ? 'anyOf' : 'allOf');
            $schema[$key][] = ['type' => 'null'];

            return $schema;
        }

        if (isset($schema['type'])) {
            $type = $schema['type'];
            if (is_array($type)) {
                if (! in_array('null', $type, true)) {
                    $type[] = 'null';
                }
                $schema['type'] = $type;

                return $schema;
            }

            $schema['type'] = [$type, 'null'];

            return $schema;
        }

        // No type / $ref to anchor onto  fall back to the union form.
        return ['oneOf' => [$schema, ['type' => 'null']]];
    }

    private function intersection(string $type, int $depth): array
    {
        $parts = array_map('trim', explode('&', $type));

        // Single part after split → treat as plain class
        if (count($parts) === 1) {
            return $this->fromTypeString($parts[0], $depth);
        }

        $branches = [];
        foreach ($parts as $part) {
            $branch = $this->fromTypeString($part, $depth);
            if ($branch !== []) {
                $branches[] = $branch;
            }
        }

        return match (count($branches)) {
            0 => [],
            1 => $branches[0],
            default => ['allOf' => $branches],
        };
    }

    /**
     * A string-keyed map (`array<string, User>`) is a JSON object whose values
     * all share one schema.
     */
    private function mapOf(string $valueType, int $depth): array
    {
        $value = $this->fromTypeString($valueType, $depth + 1);

        return [
            'type' => 'object',
            'additionalProperties' => $value === [] ? true : $value,
        ];
    }

    private function arrayOf(string $itemType, int $depth): array
    {
        $items = $this->fromTypeString($itemType, $depth + 1);

        return [
            'type' => 'array',
            // `mixed`/`void` yield an empty schema; JSON Schema needs an object
            // there, not an empty array (which encodes as `[]`).
            'items' => $items === [] ? new \stdClass : $items,
        ];
    }
}
