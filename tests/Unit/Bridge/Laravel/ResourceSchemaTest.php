<?php

declare(strict_types=1);

use ApexDocs\Extractor\ComponentRegistry;
use ApexDocs\Extractor\SchemaBuilder;
use ApexDocs\Extractor\ResponseExtractor;
use ApexDocs\Route\Route;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Carbon;

/**
 * The reported bug, against the real framework classes: clicking a resource in
 * the Schemas list showed `object {}` because every key of an API resource
 * lives in `toArray()`, where no reflection of public properties can see it.
 */

/**
 * @property int $id
 * @property string $email
 * @property Carbon|null $created_at
 * @property-read int $comments_count
 */
class DocsUser extends Model
{
    protected $table = 'users';
}

/** @mixin DocsUser */
class DocsUserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'created_at' => $this->created_at,
            'comments' => $this->comments_count,
            'verified' => (bool) $this->resource->email_verified_at,
            'posts' => DocsPostResource::collection($this->whenLoaded('posts')),
            'links' => ['self' => route('docs.user', $this->id)],
        ];
    }
}

class DocsPostResource extends JsonResource
{
    /** @return array{id: int, title: string, published_at: string|null} */
    public function toArray($request): array
    {
        return parent::toArray($request);
    }
}

/** Inherits JsonResource::toArray(), which describes nothing. */
class DocsBareResource extends JsonResource {}

class DocsUserCollection extends ResourceCollection
{
    public $collects = DocsUserResource::class;

    public function toArray($request): array
    {
        return [
            'data' => $this->collection,
            'links' => ['self' => '/api/users'],
        ];
    }
}

function docsSchemas(string ...$classes): array
{
    $registry = new ComponentRegistry;
    $builder = new SchemaBuilder(6, $registry);

    foreach ($classes as $class) {
        $builder->fromClass($class);
    }

    return $registry->all();
}

it('publishes the keys of a JsonResource', function () {
    $schema = docsSchemas(DocsUserResource::class)['DocsUserResource'];

    expect(array_keys($schema['properties']))
        ->toBe(['id', 'email', 'created_at', 'comments', 'verified', 'posts', 'links']);
});

it('types resource keys from the model it mixes in', function () {
    $properties = docsSchemas(DocsUserResource::class)['DocsUserResource']['properties'];

    expect($properties['id'])->toBe(['type' => 'integer'])
        ->and($properties['email'])->toBe(['type' => 'string'])
        ->and($properties['comments'])->toBe(['type' => 'integer'])
        ->and($properties['verified'])->toBe(['type' => 'boolean'])
        // Carbon is a DateTimeInterface: a string in JSON, and never a $ref.
        ->and($properties['created_at'])->toBe(['type' => ['string', 'null'], 'format' => 'date-time']);
});

it('keeps a whenLoaded relation out of required and refs its resource', function () {
    $schemas = docsSchemas(DocsUserResource::class);
    $schema = $schemas['DocsUserResource'];

    expect($schema['properties']['posts'])->toBe([
        'type' => 'array',
        'items' => ['$ref' => '#/components/schemas/DocsPostResource'],
    ])
        ->and($schema['required'])->not->toContain('posts')
        ->and($schema['required'])->toContain('id')
        ->and($schemas['DocsPostResource']['properties'])->toHaveKeys(['id', 'title', 'published_at']);
});

it('reads what a ResourceCollection collects', function () {
    $schema = docsSchemas(DocsUserCollection::class)['DocsUserCollection'];

    expect($schema['properties']['data'])->toBe([
        'type' => 'array',
        'items' => ['$ref' => '#/components/schemas/DocsUserResource'],
    ])
        // $collects is public on the collection and must not become a key.
        ->and(array_keys($schema['properties']))->toBe(['data', 'links']);
});

it('leaves a resource that inherits toArray() as a plain object', function () {
    // The framework's own toArray() delegates to the model; reading it would
    // describe every resource in the application identically.
    expect(docsSchemas(DocsBareResource::class)['DocsBareResource'])->toBe(['type' => 'object']);
});

it('describes an Eloquent model from its @property annotations', function () {
    $schema = docsSchemas(DocsUser::class)['DocsUser'];

    expect(array_keys($schema['properties']))->toBe(['id', 'email', 'created_at', 'comments_count'])
        ->and($schema['properties']['id'])->toBe(['type' => 'integer']);
});

it('fills in the response body a resource attribute points at', function () {
    $registry = new ComponentRegistry;
    $builder = new SchemaBuilder(6, $registry);
    $extractor = new ResponseExtractor($builder);

    $controller = new class
    {
        #[ApexDocs\Attribute\ApiResponse(200, resource: DocsUserResource::class)]
        public function show(): array
        {
            return [];
        }
    };

    $responses = $extractor->extract(
        new Route(['GET'], 'api/users/{id}', $controller::class.'@show'),
        new ReflectionClass($controller),
        new ReflectionMethod($controller, 'show'),
        'GET',
    );

    expect($responses['200']['content']['application/json']['schema'])
        ->toBe(['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/DocsUserResource']]])
        ->and($registry->all()['DocsUserResource']['properties'])->toHaveKey('email');
});
