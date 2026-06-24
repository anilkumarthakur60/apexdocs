<?php

declare(strict_types=1);

use ApexDocs\Extractor\ArrayShapeReader as Shapes;
use ApexDocs\Extractor\ComponentRegistry;
use ApexDocs\Extractor\NameResolver;
use ApexDocs\Extractor\SchemaBuilder;

/**
 * A class whose payload is assembled by a method — every API resource — has
 * nothing public to reflect, and used to be published as a keyless
 * `{type: object}`. These cover the keys being recovered from the method
 * instead, and the guarantee that an unreadable body still degrades to that
 * same plain object rather than to a wrong schema.
 *
 * The fixtures deliberately do not extend a framework base class: the reader
 * matches `toArray()`/`jsonSerialize()` and Laravel's conditional helpers by
 * name, so the core stays framework-free. The real
 * `Illuminate\Http\Resources\Json\JsonResource` case is covered in
 * tests/Unit/Bridge/Laravel/ResourceSchemaTest.php.
 */

/**
 * @property int $id
 * @property string $title
 * @property bool $published
 * @property float $rating
 * @property ArticleAuthor|null $author
 * @property-read int $views
 */
class ArticleModel {}

/**
 * @property string $name
 * @property string|null $twitter
 */
class ArticleAuthor {}

/** @mixin ArticleModel */
class ArticleResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'published' => (bool) $this->published,
            'rating' => $this->rating,
            'views' => $this->views,
            'author_name' => $this->author->name,
            'slug' => $this->title.'-'.$this->id,
            'kind' => 'article',
            'author' => new AuthorResource($this->author),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'draft_notes' => $this->when($this->isEditor(), 'notes'),
            'links' => [
                'self' => '/articles/1',
                'meta' => ['revision' => 2],
            ],
        ];
    }

    private function isEditor(): bool
    {
        return false;
    }
}

class AuthorResource
{
    /** @return array{name: string, twitter: string|null, followers?: int} */
    public function toArray($request): array
    {
        return ['whatever' => $this->unreadable()];
    }

    private function unreadable(): mixed
    {
        return null;
    }
}

class TagResource
{
    public function toArray($request): array
    {
        if ($this->isSlim()) {
            return ['name' => $this->name];
        }

        return ['name' => $this->name, 'colour' => 'red'];
    }

    private function isSlim(): bool
    {
        return true;
    }
}

class FilteredResource
{
    public function toArray($request): array
    {
        return array_filter([
            'id' => 1,
            'nickname' => 'ada',
        ]);
    }
}

class OpaqueResource
{
    public function toArray($request): array
    {
        return $this->attributes();
    }

    /** @return array<string, mixed> */
    private function attributes(): array
    {
        return [];
    }
}

class MoneyValue implements JsonSerializable
{
    private int $amount = 0;

    private string $currency = 'EUR';

    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency, 'formatted' => (string) $this->amount];
    }
}

/**
 * `$collects` and `$collection` are public on Laravel's ResourceCollection, and
 * a collection that names what it collects redeclares `$collects` — so a
 * resource is reached with public properties in scope, and its keys must still
 * come from `toArray()`.
 */
class ArticleCollectionResource
{
    /** @var class-string */
    public $collects = TagResource::class;

    public $collection;

    public function toArray($request): array
    {
        return [
            'data' => $this->collection,
            'meta' => ['total' => $this->collection->count()],
        ];
    }
}

class TimestampedDto
{
    public function __construct(
        public readonly DateTimeImmutable $occurredAt,
    ) {}
}

class HybridDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
    ) {}
}

/** Shares its short name with Illuminate's collection, and is nothing like it. */
class Collection
{
    public string $label = '';

    public int $size = 0;
}

/**
 * A model in the Eloquent shape, without Eloquent: the reader is duck-typed on
 * the declarations (`$casts`, `casts()`, `$primaryKey`, `$keyType`), so it costs
 * nothing outside Laravel.
 */
class CastingModel
{
    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    protected $casts = [
        'is_published' => 'boolean',
        'view_count' => 'integer',
        'price' => 'decimal:2',
        'meta' => 'array',
        'published_on' => 'date',
        'archived_at' => 'datetime',
        'secret' => 'encrypted',
        'ratio' => 'float',
    ];

    protected function casts(): array
    {
        return ['flags' => 'array', 'legacy' => 'boolean'];
    }
}

/** @mixin CastingModel */
class CastingResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'is_published' => $this->is_published,
            'view_count' => $this->view_count,
            'price' => $this->price,
            'meta' => $this->meta,
            'published_on' => $this->published_on,
            'archived_at' => $this->archived_at,
            'secret' => $this->secret,
            'ratio' => $this->ratio,
            'flags' => $this->flags,
            'legacy' => $this->legacy,
            'id' => $this->uuid,
        ];
    }
}

/** Build one class and return the component schema it registered. */
function schemaFor(string $class, int $depth = 6): array
{
    $registry = new ComponentRegistry;
    (new SchemaBuilder($depth, $registry))->fromClass($class);

    return $registry->all()[(new ReflectionClass($class))->getShortName()] ?? [];
}

it('recovers the keys a resource builds in toArray', function () {
    $schema = schemaFor(ArticleResource::class);

    expect($schema['type'])->toBe('object')
        ->and(array_keys($schema['properties']))->toBe([
            'id', 'title', 'published', 'rating', 'views', 'author_name',
            'slug', 'kind', 'author', 'tags', 'draft_notes', 'links',
        ]);
});

it('types the keys from the model the resource mixes in', function () {
    $properties = schemaFor(ArticleResource::class)['properties'];

    expect($properties['id'])->toBe(['type' => 'integer'])
        ->and($properties['title'])->toBe(['type' => 'string'])
        ->and($properties['rating'])->toBe(['type' => 'number', 'format' => 'float'])
        ->and($properties['views'])->toBe(['type' => 'integer'])
        ->and($properties['author_name'])->toBe(['type' => 'string']);
});

it('reads casts, concatenation and literals from the expression itself', function () {
    $properties = schemaFor(ArticleResource::class)['properties'];

    expect($properties['published'])->toBe(['type' => 'boolean'])
        ->and($properties['slug'])->toBe(['type' => 'string'])
        ->and($properties['kind'])->toBe(['type' => 'string']);
});

it('references other resources by $ref, singly and as a list', function () {
    $registry = new ComponentRegistry;
    (new SchemaBuilder(6, $registry))->fromClass(ArticleResource::class);
    $properties = $registry->all()['ArticleResource']['properties'];

    expect($properties['author'])->toBe(['$ref' => '#/components/schemas/AuthorResource'])
        ->and($properties['tags'])->toBe([
            'type' => 'array',
            'items' => ['$ref' => '#/components/schemas/TagResource'],
        ])
        ->and($registry->all())->toHaveKeys(['AuthorResource', 'TagResource']);
});

it('nests an inline array literal as an object of its own', function () {
    $links = schemaFor(ArticleResource::class)['properties']['links'];

    expect($links)->toBe([
        'type' => 'object',
        'properties' => [
            'self' => ['type' => 'string'],
            'meta' => [
                'type' => 'object',
                'properties' => ['revision' => ['type' => 'integer']],
                'required' => ['revision'],
            ],
        ],
        'required' => ['self', 'meta'],
    ]);
});

it('leaves conditional keys out of required', function () {
    $schema = schemaFor(ArticleResource::class);

    // whenLoaded()/when() return a MissingValue that removes the key.
    expect($schema['required'])->not->toContain('tags')
        ->and($schema['required'])->not->toContain('draft_notes')
        ->and($schema['required'])->toContain('id');
});

it('prefers the @return array shape over an unreadable body', function () {
    $schema = schemaFor(AuthorResource::class);

    expect($schema['properties'])->toBe([
        'name' => ['type' => 'string'],
        'twitter' => ['type' => ['string', 'null']],
        'followers' => ['type' => 'integer'],
    ])
        // `followers?` is declared optional in the annotation.
        ->and($schema['required'])->toBe(['name', 'twitter']);
});

it('marks a key missing from one return branch optional', function () {
    $schema = schemaFor(TagResource::class);

    expect(array_keys($schema['properties']))->toBe(['name', 'colour'])
        ->and($schema['required'])->toBe(['name']);
});

it('treats every key of an array_filter payload as optional', function () {
    $schema = schemaFor(FilteredResource::class);

    expect(array_keys($schema['properties']))->toBe(['id', 'nickname'])
        ->and($schema)->not->toHaveKey('required');
});

it('publishes a plain object when the body cannot be read', function () {
    expect(schemaFor(OpaqueResource::class))->toBe(['type' => 'object']);
});

it('reads jsonSerialize for a value object', function () {
    $schema = schemaFor(MoneyValue::class);

    expect($schema['properties'])->toBe([
        'amount' => ['type' => 'integer'],
        'currency' => ['type' => 'string'],
        'formatted' => ['type' => 'string'],
    ]);
});

it('describes a class with only @property annotations', function () {
    $schema = schemaFor(ArticleModel::class);

    expect(array_keys($schema['properties']))->toBe(['id', 'title', 'published', 'rating', 'author', 'views'])
        ->and($schema['properties']['published'])->toBe(['type' => 'boolean']);
});

it('resolves what a resource collection collects', function () {
    $properties = schemaFor(ArticleCollectionResource::class)['properties'];

    expect($properties['data'])->toBe([
        'type' => 'array',
        'items' => ['$ref' => '#/components/schemas/TagResource'],
    ])
        ->and($properties['meta']['properties']['total'])->toBe(['type' => 'integer']);
});

it('documents a date as a string, not as a component schema', function () {
    $registry = new ComponentRegistry;
    (new SchemaBuilder(6, $registry))->fromClass(TimestampedDto::class);

    expect($registry->all()['TimestampedDto']['properties']['occurredAt'])
        ->toBe(['type' => 'string', 'format' => 'date-time'])
        ->and($registry->all())->not->toHaveKey('DateTimeImmutable');
});

it('documents a collection written without its generic as an array', function () {
    $json = json_encode((new SchemaBuilder)->fromTypeString(ArrayIterator::class));

    expect($json)->toBe('{"type":"array","items":{}}');
});

it('does not mistake a DTO for a collection because of its name', function () {
    // Matching on the short name alone would turn this into `type: array`.
    $schema = schemaFor(Collection::class);

    expect($schema['properties'])->toHaveKeys(['label', 'size']);
});

it('honours max_depth when nesting recovered shapes', function () {
    $links = schemaFor(ArticleResource::class, 1)['properties']['links'];

    expect($links)->toBe(['type' => 'object']);
});

it('still reflects a plain DTO from its public properties', function () {
    $schema = schemaFor(HybridDto::class);

    expect($schema['properties'])->toBe([
        'id' => ['type' => 'integer'],
        'label' => ['type' => 'string'],
    ])
        ->and($schema['required'])->toBe(['id', 'label']);
});

it('reads a shape straight from the reader, inventing no types', function () {
    $shape = Shapes::forClass(new ReflectionClass(TagResource::class));

    // TagResource mixes in no model, so `$this->name` resolves to nothing —
    // the key is reported without a type rather than with a guessed one.
    expect($shape)->toBe([
        'name' => [],
        'colour' => ['type' => 'string', 'optional' => true],
    ]);
});

it('resolves names against the file that wrote them', function () {
    $resolver = NameResolver::forClass(new ReflectionClass(ArticleResource::class));

    // `Shapes` is this file's alias for the reader.
    expect($resolver->resolve('Shapes'))->toBe(Shapes::class)
        ->and($resolver->resolve('\\App\\Nope'))->toBe('App\Nope')
        ->and($resolver->resolveTypeString('int'))->toBe('int')
        ->and($resolver->resolveTypeString('?TagResource'))->toBe('TagResource|null');
});

it('types resource keys from the casts a model declares', function () {
    $properties = schemaFor(CastingResource::class)['properties'];

    expect($properties['is_published'])->toBe(['type' => 'boolean'])
        ->and($properties['view_count'])->toBe(['type' => 'integer'])
        ->and($properties['ratio'])->toBe(['type' => 'number', 'format' => 'float'])
        // json_encode, because `items` is a distinct stdClass each time.
        ->and(json_encode($properties['meta']))->toBe('{"type":"array","items":{}}')
        ->and($properties['published_on'])->toBe(['type' => 'string', 'format' => 'date'])
        ->and($properties['archived_at'])->toBe(['type' => 'string', 'format' => 'date-time'])
        // Eloquent formats a decimal with number_format and returns a string.
        ->and($properties['price'])->toBe(['type' => 'string'])
        ->and($properties['secret'])->toBe(['type' => 'string']);
});

it('reads the Laravel 11 casts() method as well as the property', function () {
    $properties = schemaFor(CastingResource::class)['properties'];

    expect(json_encode($properties['flags']))->toBe('{"type":"array","items":{}}')
        ->and($properties['legacy'])->toBe(['type' => 'boolean']);
});

it('believes $keyType over the id naming convention', function () {
    $properties = schemaFor(CastingResource::class)['properties'];

    // `'id' => $this->uuid` on a string-keyed model: the convention would have
    // said integer, and it would have been wrong.
    expect($properties['uuid'])->toBe(['type' => 'string'])
        ->and($properties['id'])->toBe(['type' => 'string']);
});
